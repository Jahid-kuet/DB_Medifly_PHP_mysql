<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_payment'])) {
        $paymentId = (int) $_POST['payment_id'];
        $amount = (float) $_POST['amount'];
        $method = trim($_POST['payment_method']);
        $transactionId = trim($_POST['transaction_id'] ?? '');

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare('SELECT request_id FROM Payments WHERE payment_id = ? AND status = "pending" FOR UPDATE');
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $paymentRow = $stmt->get_result()->fetch_assoc();

            if (!$paymentRow) {
                throw new RuntimeException('Pending payment not found.');
            }

            $requestId = (int) $paymentRow['request_id'];

            $stmt = $conn->prepare('UPDATE Payments SET amount = ?, payment_method = ?, transaction_id = ?, status = "completed", payment_date = NOW() WHERE payment_id = ?');
            $stmt->bind_param('dssi', $amount, $method, $transactionId, $paymentId);
            $stmt->execute();

            // If admin provided an admin_note, append it to Payments.notes for auditability
            $adminNote = trim($_POST['admin_note'] ?? '');
            if ($adminNote !== '') {
                $appended = ' | Admin: ' . $adminNote;
                $stmt = $conn->prepare("UPDATE Payments SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE payment_id = ?");
                $stmt->bind_param('si', $appended, $paymentId);
                $stmt->execute();
            }

            $stmt = $conn->prepare('UPDATE DeliveryRequests SET payment_status = "paid", payment_amount = ?, payment_method = ? WHERE request_id = ?');
            $stmt->bind_param('dsi', $amount, $method, $requestId);
            $stmt->execute();

            $timestamp = date('Y-m-d H:i:s');
            $logNote = sprintf('Admin verified payment of ৳%.2f via %s (Txn: %s)', $amount, $method, $transactionId !== '' ? $transactionId : 'N/A');
            if ($adminNote !== '') {
                $logNote .= ' — Admin note: ' . $adminNote;
            }
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $logNote);
            $stmt->execute();

            $conn->commit();
            setFlash('Payment verified and marked as completed.');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('Verification failed: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/admin/payments.php');
        exit;
    }

    if (isset($_POST['reject_payment'])) {
        $paymentId = (int) $_POST['payment_id'];
        $reason = trim($_POST['reason'] ?? '');

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare('SELECT request_id FROM Payments WHERE payment_id = ? AND status = "pending" FOR UPDATE');
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $paymentRow = $stmt->get_result()->fetch_assoc();

            if (!$paymentRow) {
                throw new RuntimeException('Pending payment not found.');
            }

            $requestId = (int) $paymentRow['request_id'];

            $stmt = $conn->prepare('UPDATE Payments SET status = "failed", notes = CONCAT(IFNULL(notes, ""), ?) WHERE payment_id = ?');
            $noteText = $reason !== '' ? ' | Rejected: ' . $reason : ' | Rejected by admin';
            $stmt->bind_param('si', $noteText, $paymentId);
            $stmt->execute();

            $stmt = $conn->prepare('UPDATE DeliveryRequests SET payment_status = "unpaid", payment_method = NULL WHERE request_id = ?');
            $stmt->bind_param('i', $requestId);
            $stmt->execute();

            $timestamp = date('Y-m-d H:i:s');
            $logNote = 'Admin rejected payment submission' . ($reason !== '' ? (': ' . $reason) : '');
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $logNote);
            $stmt->execute();

            $conn->commit();
            setFlash('Payment submission rejected.', 'info');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('Unable to reject payment: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/admin/payments.php');
        exit;
    }

    if (isset($_POST['confirm_payment'])) {
        $requestId = (int) $_POST['request_id'];
        $amount = (float) $_POST['amount'];
        $method = trim($_POST['payment_method']);
        $transactionId = trim($_POST['transaction_id'] ?? '');

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare('INSERT INTO Payments (request_id, amount, payment_method, transaction_id, status) VALUES (?, ?, ?, ?, "completed")');
            $stmt->bind_param('idss', $requestId, $amount, $method, $transactionId);
            $stmt->execute();

            $stmt = $conn->prepare('UPDATE DeliveryRequests SET payment_status = "paid", payment_amount = ?, payment_method = ? WHERE request_id = ?');
            $stmt->bind_param('dsi', $amount, $method, $requestId);
            $stmt->execute();

            $timestamp = date('Y-m-d H:i:s');
            $logNote = sprintf('Admin recorded payment of ৳%.2f via %s', $amount, $method);
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $logNote);
            $stmt->execute();

            $conn->commit();
            setFlash('Payment confirmed successfully. Request can now be assigned to operator.');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('Payment confirmation failed: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/admin/payments.php');
        exit;
    }
}

// Fetch all payments with request details
$query = "
    SELECT 
        p.*,
        dr.request_id,
        dr.destination,
        dr.status as request_status,
        dr.payment_status,
        dr.payment_amount,
        u.name as hospital_name,
        h.name as hospital_facility,
        s.name as supply_name
    FROM Payments p
    INNER JOIN DeliveryRequests dr ON p.request_id = dr.request_id
    INNER JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    INNER JOIN Supplies s ON dr.supply_id = s.supply_id
    ORDER BY p.payment_date DESC
";
$payments = $conn->query($query);

// Payments submitted by hospital awaiting verification
$pendingVerificationQuery = "
    SELECT 
        p.*,
        dr.destination,
        dr.payment_amount,
        u.name as hospital_name,
        h.name as hospital_facility,
        s.name as supply_name
    FROM Payments p
    INNER JOIN DeliveryRequests dr ON p.request_id = dr.request_id
    INNER JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    INNER JOIN Supplies s ON dr.supply_id = s.supply_id
    WHERE p.status = 'pending'
    ORDER BY p.payment_date DESC
";
$pendingVerifications = $conn->query($pendingVerificationQuery);

// Fetch pending payments (approved requests without payment)
$pendingQuery = "
    SELECT 
        dr.*,
        u.name as hospital_name,
        h.name as hospital_facility,
        s.name as supply_name
    FROM DeliveryRequests dr
    INNER JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    INNER JOIN Supplies s ON dr.supply_id = s.supply_id
    WHERE dr.status = 'approved' AND dr.payment_status = 'unpaid'
    ORDER BY dr.created_at DESC
";
$pendingPayments = $conn->query($pendingQuery);

$pageTitle = 'Payment Management | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col">
        <h1 class="text-gradient"><i class="bi bi-credit-card"></i> Payment Management</h1>
        <p class="text-muted">Track and manage all payment transactions</p>
    </div>
</div>

<!-- Pending Payments -->
<?php if ($pendingPayments->num_rows > 0): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-exclamation-triangle"></i> Pending Payments (<?php echo $pendingPayments->num_rows; ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Hospital</th>
                        <th>Supply</th>
                        <th>Destination</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($req = $pendingPayments->fetch_assoc()): ?>
                    <tr>
                        <td><span class="badge bg-primary">#<?php echo $req['request_id']; ?></span></td>
                        <td>
                            <strong><?php echo htmlspecialchars($req['hospital_facility']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($req['hospital_name']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($req['supply_name']); ?></td>
                        <td><?php echo htmlspecialchars($req['destination']); ?></td>
                        <td>
                            <?php if ($req['payment_amount'] > 0): ?>
                                <strong>৳<?php echo number_format($req['payment_amount'], 2); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $req['request_id']; ?>">
                                <i class="bi bi-cash"></i> Confirm Payment
                            </button>
                        </td>
                    </tr>

                    <!-- Payment Modal -->
                    <div class="modal fade" id="paymentModal<?php echo $req['request_id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Confirm Payment for Request #<?php echo $req['request_id']; ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Amount (৳)</label>
                                            <input type="number" step="0.01" class="form-control" name="amount" 
                                                   value="<?php echo $req['payment_amount'] > 0 ? $req['payment_amount'] : ''; ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select class="form-select" name="payment_method" required>
                                                <option value="">Select method</option>
                                                <option value="Cash">Cash</option>
                                                <option value="bKash">bKash</option>
                                                <option value="Nagad">Nagad</option>
                                                <option value="Rocket">Rocket</option>
                                                <option value="Bank Transfer">Bank Transfer</option>
                                                <option value="Card">Credit/Debit Card</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Transaction ID (Optional)</label>
                                            <input type="text" class="form-control" name="transaction_id" placeholder="e.g., TRX123456789">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="confirm_payment" class="btn btn-success">
                                            <i class="bi bi-check-circle"></i> Confirm Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Pending Verification -->
<?php if ($pendingVerifications->num_rows > 0): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-info text-white">
        <i class="bi bi-hourglass-split"></i> Payments Awaiting Verification (<?php echo $pendingVerifications->num_rows; ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Request</th>
                        <th>Hospital</th>
                        <th>Supply</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction ID</th>
                        <th>Notes</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($pending = $pendingVerifications->fetch_assoc()): ?>
                    <tr>
                        <td><span class="badge bg-secondary">#<?php echo $pending['payment_id']; ?></span></td>
                        <td><span class="badge bg-primary">#<?php echo $pending['request_id']; ?></span></td>
                        <td>
                            <strong><?php echo htmlspecialchars($pending['hospital_facility']); ?></strong><br>
                            <small class="text-muted">Submitted by <?php echo htmlspecialchars($pending['hospital_name']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($pending['supply_name']); ?></td>
                        <td><strong>৳<?php echo number_format($pending['amount'] ?? $pending['payment_amount'], 2); ?></strong></td>
                        <td><?php echo htmlspecialchars($pending['payment_method'] ?? 'N/A'); ?></td>
                        <td><?php echo $pending['transaction_id'] ? htmlspecialchars($pending['transaction_id']) : '-'; ?></td>
                        <td style="max-width:220px;">
                            <?php if (!empty($pending['notes'])): ?>
                                <small class="text-muted" title="<?php echo htmlspecialchars($pending['notes']); ?>"><?php echo nl2br(htmlspecialchars(mb_strimwidth($pending['notes'], 0, 80, '...'))); ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M d, Y h:i A', strtotime($pending['payment_date'])); ?></td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#verifyModal<?php echo $pending['payment_id']; ?>">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $pending['payment_id']; ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </td>
                    </tr>

                    <!-- Verify Modal -->
                    <div class="modal fade" id="verifyModal<?php echo $pending['payment_id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Verify Payment #<?php echo $pending['payment_id']; ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="payment_id" value="<?php echo $pending['payment_id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Confirmed Amount (৳)</label>
                                            <input type="number" step="0.01" class="form-control" name="amount" value="<?php echo $pending['amount'] ?? $pending['payment_amount']; ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <input type="text" class="form-control" name="payment_method" value="<?php echo htmlspecialchars($pending['payment_method'] ?? ''); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Transaction ID</label>
                                            <input type="text" class="form-control" name="transaction_id" value="<?php echo htmlspecialchars($pending['transaction_id'] ?? ''); ?>" placeholder="Optional">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Hospital Notes</label>
                                            <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($pending['notes'] ?? ''); ?></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Admin Note (optional)</label>
                                            <textarea class="form-control" name="admin_note" rows="2" placeholder="Add a note for hospital (will be recorded)"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="verify_payment" class="btn btn-success">
                                            <i class="bi bi-check-circle"></i> Verify & Complete
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?php echo $pending['payment_id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Reject Payment #<?php echo $pending['payment_id']; ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="payment_id" value="<?php echo $pending['payment_id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Reason (optional)</label>
                                            <textarea class="form-control" name="reason" rows="3" placeholder="Duplicate payment, incorrect amount, etc."></textarea>
                                        </div>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i> Rejecting will mark the request as unpaid and notify the hospital to resubmit payment details.
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="reject_payment" class="btn btn-danger">
                                            <i class="bi bi-x-circle"></i> Reject Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Completed Payments -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-check-circle"></i> Payment History
    </div>
    <div class="card-body">
        <?php if ($payments->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Request</th>
                        <th>Hospital</th>
                        <th>Supply</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction ID</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($payment = $payments->fetch_assoc()): ?>
                    <tr>
                        <td><span class="badge bg-secondary">#<?php echo $payment['payment_id']; ?></span></td>
                        <td><span class="badge bg-primary">#<?php echo $payment['request_id']; ?></span></td>
                        <td>
                            <strong><?php echo htmlspecialchars($payment['hospital_facility']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($payment['hospital_name']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($payment['supply_name']); ?></td>
                        <td><strong>৳<?php echo number_format($payment['amount'], 2); ?></strong></td>
                        <td><span class="badge bg-info"><?php echo htmlspecialchars($payment['payment_method']); ?></span></td>
                        <td><?php echo $payment['transaction_id'] ? htmlspecialchars($payment['transaction_id']) : '-'; ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?></td>
                        <td>
                            <?php
                            $statusClass = [
                                'pending' => 'warning',
                                'completed' => 'success',
                                'failed' => 'danger',
                                'refunded' => 'secondary'
                            ];
                            $class = $statusClass[$payment['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $class; ?>"><?php echo ucfirst($payment['status']); ?></span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No payment records found.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Some modals are rendered inside tables/cards which can cause backdrop/focus issues.
// Move modal elements to document.body so Bootstrap's backdrop and focus management works reliably.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (modal) {
        if (!modal.dataset.movedToBody) {
            document.body.appendChild(modal);
            modal.dataset.movedToBody = 'true';
        }
    });
});
</script>
