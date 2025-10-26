<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$statuses = ['pending', 'approved', 'in-transit', 'delivered', 'cancelled'];

// Fetch supplies, operators, drones for selectors
$supplies = [];
$result = $conn->query('SELECT supply_id, name FROM Supplies ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $supplies[] = $row;
}

$operatorUsers = [];
$stmt = $conn->prepare("SELECT user_id, name FROM Users WHERE role = 'operator' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $operatorUsers[] = $row;
}
$operatorNames = [];
foreach ($operatorUsers as $operator) {
    $operatorNames[(int) $operator['user_id']] = $operator['name'];
}

$drones = [];
$result = $conn->query('SELECT drone_id, model, status FROM Drones ORDER BY model');
while ($row = $result->fetch_assoc()) {
    $drones[] = $row;
}
$droneInfo = [];
foreach ($drones as $drone) {
    $droneInfo[(int) $drone['drone_id']] = $drone;
}

$operatorDroneMap = [];
$result = $conn->query('SELECT user_id, drone_id FROM Operators');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $userKey = (int) $row['user_id'];
        $operatorDroneMap[$userKey][] = (int) $row['drone_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!$requestId) {
        setFlash('Invalid request reference.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/requests.php');
        exit;
    }

    if ($action === 'confirm_payment_delivery') {
        $paymentId = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare('SELECT status, payment_status, drone_id FROM DeliveryRequests WHERE request_id = ? FOR UPDATE');
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $requestRow = $stmt->get_result()->fetch_assoc();

            if (!$requestRow) {
                throw new RuntimeException('Delivery request not found.');
            }

            if (($requestRow['payment_status'] ?? 'unpaid') !== 'pending') {
                throw new RuntimeException('No pending payment to confirm for this request.');
            }

            $pendingPaymentId = $paymentId;

            if (!$pendingPaymentId) {
                $stmt = $conn->prepare('SELECT payment_id FROM Payments WHERE request_id = ? AND status = "pending" ORDER BY payment_date DESC LIMIT 1');
                $stmt->bind_param('i', $requestId);
                $stmt->execute();
                $paymentRow = $stmt->get_result()->fetch_assoc();
                $pendingPaymentId = $paymentRow ? (int) $paymentRow['payment_id'] : 0;
            } else {
                $stmt = $conn->prepare('SELECT payment_id FROM Payments WHERE payment_id = ? AND request_id = ? AND status = "pending"');
                $stmt->bind_param('ii', $pendingPaymentId, $requestId);
                $stmt->execute();
                $paymentRow = $stmt->get_result()->fetch_assoc();
                if (!$paymentRow) {
                    throw new RuntimeException('Pending payment record not found.');
                }
            }

            if (!$pendingPaymentId) {
                throw new RuntimeException('Pending payment record not found.');
            }

            $stmt = $conn->prepare('UPDATE Payments SET status = "completed", payment_date = NOW() WHERE payment_id = ? AND status = "pending"');
            $stmt->bind_param('i', $pendingPaymentId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Payment could not be marked as completed.');
            }

            $stmt = $conn->prepare('UPDATE DeliveryRequests SET payment_status = "paid", status = "delivered" WHERE request_id = ?');
            $stmt->bind_param('i', $requestId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Delivery request was not updated.');
            }

            if (!empty($requestRow['drone_id'])) {
                $droneId = (int) $requestRow['drone_id'];
                $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
                $stmt->bind_param('i', $droneId);
                $stmt->execute();
            }

            $timestamp = date('Y-m-d H:i:s');
            $note = sprintf(
                'Admin confirmed payment (was %s) and marked delivery as delivered (was %s).',
                $requestRow['payment_status'],
                $requestRow['status']
            );
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $note);
            $stmt->execute();

            $conn->commit();
            setFlash('Payment confirmed and delivery marked as completed.');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('Unable to confirm payment: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/admin/requests.php');
        exit;
    }

    if ($action === 'delete') {
        $stmt = $conn->prepare('SELECT drone_id FROM DeliveryRequests WHERE request_id = ?');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $previousDroneId = $row ? (int) ($row['drone_id'] ?? 0) : 0;

        $stmt = $conn->prepare('DELETE FROM DeliveryRequests WHERE request_id = ?');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();

        if ($previousDroneId) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM DeliveryRequests WHERE drone_id = ?');
            $stmt->bind_param('i', $previousDroneId);
            $stmt->execute();
            $count = $stmt->get_result()->fetch_assoc();
            if (($count['total'] ?? 0) == 0) {
                $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
                $stmt->bind_param('i', $previousDroneId);
                $stmt->execute();
            }
        }

        setFlash('Delivery request deleted.', 'info');
        header('Location: ' . BASE_PATH . '/admin/requests.php');
        exit;
    }

    if ($action === 'update') {
        $supplyId = (int) ($_POST['supply_id'] ?? 0);
        $destination = trim($_POST['destination'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $operatorId = $_POST['operator_id'] !== '' ? (int) $_POST['operator_id'] : null;
        $droneId = $_POST['drone_id'] !== '' ? (int) $_POST['drone_id'] : null;
        $adminNote = trim($_POST['admin_note'] ?? '');
        $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);

        if (!$supplyId || $destination === '' || !in_array($status, $statuses, true)) {
            setFlash('Supply, destination, and status are required.', 'danger');
            header('Location: ' . BASE_PATH . '/admin/requests.php');
            exit;
        }

        if (($operatorId && !$droneId) || ($droneId && !$operatorId)) {
            setFlash('Assign both an operator and a drone together.', 'danger');
            header('Location: ' . BASE_PATH . '/admin/requests.php');
            exit;
        }

        $requiresAssignmentStatuses = ['approved', 'in-transit', 'delivered'];
        if (in_array($status, $requiresAssignmentStatuses, true) && (!$operatorId || !$droneId)) {
            setFlash('Approved deliveries must have both an operator and a drone assigned.', 'danger');
            header('Location: ' . BASE_PATH . '/admin/requests.php');
            exit;
        }

        $stmt = $conn->prepare('SELECT status, operator_id, drone_id FROM DeliveryRequests WHERE request_id = ?');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        if (!$current) {
            setFlash('Request not found.', 'danger');
            header('Location: ' . BASE_PATH . '/admin/requests.php');
            exit;
        }

        $oldStatus = $current['status'];
        $oldOperatorId = $current['operator_id'] ? (int) $current['operator_id'] : null;
        $oldDroneId = $current['drone_id'] ? (int) $current['drone_id'] : null;

        if ($operatorId === null && $oldOperatorId !== null) {
            $operatorChanged = true;
        } else {
            $operatorChanged = $operatorId !== $oldOperatorId;
        }

        if ($droneId === null && $oldDroneId !== null) {
            $droneChanged = true;
        } else {
            $droneChanged = $droneId !== $oldDroneId;
        }

        if ($operatorId && $droneId) {
            $stmt = $conn->prepare('SELECT operator_id FROM Operators WHERE user_id = ? AND drone_id = ? LIMIT 1');
            $stmt->bind_param('ii', $operatorId, $droneId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();

            // Auto-register new operator-drone assignment if not already present
            if (!$exists) {
                $stmt = $conn->prepare('INSERT INTO Operators (user_id, drone_id) VALUES (?, ?)');
                $stmt->bind_param('ii', $operatorId, $droneId);
                $stmt->execute();
            }
        }

        if ($operatorId === null && $droneId === null) {
            $stmt = $conn->prepare('UPDATE DeliveryRequests SET supply_id = ?, destination = ?, status = ?, payment_amount = ?, operator_id = NULL, drone_id = NULL WHERE request_id = ?');
            $stmt->bind_param('issdi', $supplyId, $destination, $status, $paymentAmount, $requestId);
        } elseif ($operatorId === null) {
            $stmt = $conn->prepare('UPDATE DeliveryRequests SET supply_id = ?, destination = ?, status = ?, payment_amount = ?, operator_id = NULL, drone_id = ? WHERE request_id = ?');
            $stmt->bind_param('issdii', $supplyId, $destination, $status, $paymentAmount, $droneId, $requestId);
        } elseif ($droneId === null) {
            $stmt = $conn->prepare('UPDATE DeliveryRequests SET supply_id = ?, destination = ?, status = ?, payment_amount = ?, operator_id = ?, drone_id = NULL WHERE request_id = ?');
            $stmt->bind_param('issdii', $supplyId, $destination, $status, $paymentAmount, $operatorId, $requestId);
        } else {
            $stmt = $conn->prepare('UPDATE DeliveryRequests SET supply_id = ?, destination = ?, status = ?, payment_amount = ?, operator_id = ?, drone_id = ? WHERE request_id = ?');
            $stmt->bind_param('issdiii', $supplyId, $destination, $status, $paymentAmount, $operatorId, $droneId, $requestId);
        }
        $stmt->execute();

        if ($droneId && $droneChanged) {
            $stmt = $conn->prepare("UPDATE Drones SET status = 'assigned' WHERE drone_id = ?");
            $stmt->bind_param('i', $droneId);
            $stmt->execute();
        }

        if ($oldDroneId && ($droneChanged || (!$droneId && $oldDroneId))) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM DeliveryRequests WHERE drone_id = ?');
            $stmt->bind_param('i', $oldDroneId);
            $stmt->execute();
            $count = $stmt->get_result()->fetch_assoc();
            if (($count['total'] ?? 0) == 0) {
                $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
                $stmt->bind_param('i', $oldDroneId);
                $stmt->execute();
            }
        }

        if (in_array($status, ['delivered', 'cancelled'], true) && $droneId) {
            $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
            $stmt->bind_param('i', $droneId);
            $stmt->execute();
        }

        $logMessages = [];
        if ($oldStatus !== $status) {
            $logMessages[] = "Status changed from {$oldStatus} to {$status}";
        }
        if ($operatorChanged) {
            if ($operatorId) {
                $operatorLabel = $operatorNames[$operatorId] ?? ('User #' . $operatorId);
                $logMessages[] = 'Operator assigned to ' . $operatorLabel;
            } else {
                $operatorLabel = $oldOperatorId ? ($operatorNames[$oldOperatorId] ?? ('User #' . $oldOperatorId)) : 'unknown operator';
                $logMessages[] = 'Operator unassigned (previously ' . $operatorLabel . ')';
            }
        }
        if ($droneChanged) {
            if ($droneId) {
                $droneLabel = $droneInfo[$droneId]['model'] ?? ('Drone #' . $droneId);
                $logMessages[] = 'Drone assigned to ' . $droneLabel;
            } else {
                $droneLabel = $oldDroneId ? ($droneInfo[$oldDroneId]['model'] ?? ('Drone #' . $oldDroneId)) : 'unknown drone';
                $logMessages[] = 'Drone unassigned (previously ' . $droneLabel . ')';
            }
        }
        if ($adminNote !== '') {
            $logMessages[] = "Admin note: {$adminNote}";
        }

        if ($logMessages) {
            $timestamp = date('Y-m-d H:i:s');
            $note = implode('; ', $logMessages);
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $note);
            $stmt->execute();
        }

        if ($operatorChanged) {
            $assignmentUrl = BASE_PATH . '/operator/requests.php';
            if ($operatorId) {
                $droneLabel = $droneId ? ($droneInfo[$droneId]['model'] ?? ('Drone #' . $droneId)) : 'assigned drone';
                $message = sprintf('New assignment: Request #%d (%s) with %s.', $requestId, $destination, $droneLabel);
                $stmt = $conn->prepare('INSERT INTO Notifications (user_id, message, url) VALUES (?, ?, ?)');
                $stmt->bind_param('iss', $operatorId, $message, $assignmentUrl);
                $stmt->execute();
            }

            if ($oldOperatorId && $oldOperatorId !== $operatorId) {
                $message = sprintf('You were unassigned from Request #%d.', $requestId);
                $stmt = $conn->prepare('INSERT INTO Notifications (user_id, message, url) VALUES (?, ?, ?)');
                $stmt->bind_param('iss', $oldOperatorId, $message, $assignmentUrl);
                $stmt->execute();
            }
        }

        setFlash('Request updated successfully.');
        header('Location: ' . BASE_PATH . '/admin/requests.php');
        exit;
    }
}

$requests = [];
$stmt = $conn->prepare("SELECT dr.*, u.name AS requester_name, h.name AS hospital_name, s.name AS supply_name, op.name AS operator_name, d.model AS drone_model
    FROM DeliveryRequests dr
    LEFT JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    LEFT JOIN Users op ON dr.operator_id = op.user_id
    LEFT JOIN Drones d ON dr.drone_id = d.drone_id
    ORDER BY 
        CASE dr.status 
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'in-transit' THEN 3
            WHEN 'delivered' THEN 4
            WHEN 'cancelled' THEN 5
        END,
        dr.request_id DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$paymentPendingMap = [];
$result = $conn->query('SELECT payment_id, request_id FROM Payments WHERE status = "pending"');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $paymentPendingMap[(int) $row['request_id']] = (int) $row['payment_id'];
    }
}

$paymentPendingCount = 0;
foreach ($requests as $request) {
    if (($request['payment_status'] ?? 'unpaid') === 'pending') {
        $paymentPendingCount++;
    }
}

$pageTitle = 'Manage Delivery Requests | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<?php if ($paymentPendingCount > 0): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center" role="alert">
    <div>
        <i class="bi bi-bell"></i> <?php echo $paymentPendingCount; ?> payment <?php echo $paymentPendingCount === 1 ? 'submission is' : 'submissions are'; ?> awaiting verification.
        <a href="<?php echo BASE_PATH; ?>/admin/payments.php" class="alert-link">Review payments</a>
    </div>
    <span class="badge bg-warning text-dark">Action required</span>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Delivery Requests</h2>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Requester</th>
                        <th>Hospital</th>
                        <th>Supply</th>
                        <th>Destination</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Operator</th>
                        <th>Drone</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="11" class="text-center py-4">No delivery requests available.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <?php
                            $statusColors = [
                                'pending' => 'warning',
                                'approved' => 'primary',
                                'payment-pending' => 'info',
                                'in-transit' => 'info',
                                'delivered' => 'success',
                                'cancelled' => 'danger'
                            ];
                            $statusColor = $statusColors[$request['status']] ?? 'secondary';

                            $paymentColors = [
                                'unpaid' => 'danger',
                                'pending' => 'warning',
                                'paid' => 'success',
                                'refunded' => 'secondary'
                            ];
                            $paymentColor = $paymentColors[$request['payment_status'] ?? 'unpaid'] ?? 'secondary';
                            $rowClasses = [];
                            $hasPendingPayment = ($request['payment_status'] ?? 'unpaid') === 'pending';
                            if ($hasPendingPayment) {
                                $rowClasses[] = 'table-warning';
                            }
                            ?>
                            <tr<?php echo $rowClasses ? ' class="' . implode(' ', $rowClasses) . '"' : ''; ?>>
                                <td>#<?php echo (int) $request['request_id']; ?></td>
                                <td><?php echo htmlspecialchars($request['requester_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($request['hospital_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($request['supply_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($request['destination'] ?? ''); ?></td>
                                <td><strong>৳<?php echo number_format($request['payment_amount'] ?? 0, 2); ?></strong></td>
                                <td><span class="badge bg-<?php echo $paymentColor; ?>"><?php echo ucfirst($request['payment_status'] ?? 'unpaid'); ?></span></td>
                                <td><span class="badge bg-<?php echo $statusColor; ?> text-capitalize"><?php echo htmlspecialchars($request['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($request['operator_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($request['drone_model'] ?? '-'); ?></td>
                                <td class="text-end">
                                    <?php if ($hasPendingPayment): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="confirm_payment_delivery">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                            <?php if (isset($paymentPendingMap[(int) $request['request_id']])): ?>
                                                <input type="hidden" name="payment_id" value="<?php echo $paymentPendingMap[(int) $request['request_id']]; ?>">
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Confirm payment and mark this delivery as completed?');">
                                                <i class="bi bi-check-circle"></i> Confirm Payment
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?php echo (int) $request['request_id']; ?>" aria-expanded="false" aria-controls="edit-<?php echo (int) $request['request_id']; ?>">
                                        Manage
                                    </button>
                                    <form method="post" class="d-inline ms-1" onsubmit="return confirm('Delete this request?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <tr class="collapse" id="edit-<?php echo (int) $request['request_id']; ?>">
                                <td colspan="11">
                                    <div class="border rounded p-3 bg-light">
                                        <form method="post" class="row g-3">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                            <div class="col-md-3">
                                                <label class="form-label">Supply</label>
                                                <select class="form-select" name="supply_id" required>
                                                    <option value="">Select</option>
                                                    <?php foreach ($supplies as $supply): ?>
                                                        <option value="<?php echo (int) $supply['supply_id']; ?>" <?php echo ((int) $request['supply_id'] === (int) $supply['supply_id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($supply['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="status" required>
                                                    <?php foreach ($statuses as $state): ?>
                                                        <option value="<?php echo $state; ?>" <?php echo ($request['status'] === $state) ? 'selected' : ''; ?>><?php echo ucfirst($state); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Operator</label>
                                                <select class="form-select" id="operator-<?php echo (int) $request['request_id']; ?>" name="operator_id" data-operator-select data-request-id="<?php echo (int) $request['request_id']; ?>">
                                                    <option value="">-- None --</option>
                                                    <?php foreach ($operatorUsers as $operator): ?>
                                                        <?php
                                                        $optionOperatorId = (int) $operator['user_id'];
                                                        $preferredDroneId = $operatorDroneMap[$optionOperatorId][0] ?? '';
                                                        ?>
                                                        <option value="<?php echo $optionOperatorId; ?>" data-assigned-drone="<?php echo $preferredDroneId; ?>" <?php echo ((int) ($request['operator_id'] ?? 0) === $optionOperatorId) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($operator['name']); ?><?php echo $preferredDroneId ? ' • Prefers ' . htmlspecialchars($droneInfo[$preferredDroneId]['model'] ?? ('Drone #' . $preferredDroneId)) : ''; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Drone</label>
                                                <select class="form-select" id="drone-<?php echo (int) $request['request_id']; ?>" name="drone_id" data-drone-select="<?php echo (int) $request['request_id']; ?>">
                                                    <option value="">-- None --</option>
                                                    <?php foreach ($drones as $drone): ?>
                                                        <option value="<?php echo (int) $drone['drone_id']; ?>" <?php echo ((int) ($request['drone_id'] ?? 0) === (int) $drone['drone_id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($drone['model'] . ' (' . $drone['status'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Destination</label>
                                                <input type="text" class="form-control" name="destination" value="<?php echo htmlspecialchars($request['destination'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Payment Amount (৳)</label>
                                                <input type="number" step="0.01" class="form-control" name="payment_amount" value="<?php echo $request['payment_amount'] ?? 0; ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Admin Note</label>
                                                <input type="text" class="form-control" name="admin_note" placeholder="Optional note">
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-operator-select]').forEach((select) => {
        const requestId = select.dataset.requestId;
        if (!requestId) {
            return;
        }
        const droneSelect = document.querySelector(`[data-drone-select="${requestId}"]`);
        if (!droneSelect) {
            return;
        }

        const applyPreferredDrone = (force = false) => {
            const option = select.options[select.selectedIndex];
            if (!option) {
                return;
            }
            const preferredDrone = option.dataset.assignedDrone;
            if (!preferredDrone) {
                return;
            }
            if (force || !droneSelect.value) {
                droneSelect.value = preferredDrone;
            }
        };

        select.addEventListener('change', () => applyPreferredDrone(true));
        applyPreferredDrone(false);
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
