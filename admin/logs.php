<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$requests = [];
$stmt = $conn->prepare('SELECT request_id, destination FROM DeliveryRequests ORDER BY request_id DESC');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$requestId || $notes === '') {
        setFlash('Request and notes are required to create a log entry.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/logs.php');
        exit;
    }

    $timestamp = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $requestId, $timestamp, $notes);
    $stmt->execute();
    setFlash('Log entry created.');
    header('Location: ' . BASE_PATH . '/admin/logs.php');
    exit;
}

if (isset($_GET['delete'])) {
    $logId = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM DeliveryLogs WHERE log_id = ?');
    $stmt->bind_param('i', $logId);
    $stmt->execute();
    setFlash('Log entry deleted.', 'info');
    header('Location: ' . BASE_PATH . '/admin/logs.php');
    exit;
}

$logs = [];
$stmt = $conn->prepare('SELECT l.log_id, l.timestamp, l.notes, l.request_id, r.destination
    FROM DeliveryLogs l
    LEFT JOIN DeliveryRequests r ON l.request_id = r.request_id
    ORDER BY l.timestamp DESC');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

$pageTitle = 'Delivery Logs | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Add Log Entry</h2>
                <form method="post" novalidate>
                    <div class="mb-3">
                        <label for="request_id" class="form-label">Delivery Request</label>
                        <select class="form-select" id="request_id" name="request_id" required>
                            <option value="">Select request</option>
                            <?php foreach ($requests as $request): ?>
                                <option value="<?php echo (int) $request['request_id']; ?>">
                                    #<?php echo (int) $request['request_id']; ?> - <?php echo htmlspecialchars($request['destination'] ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Add Log</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Log History</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Request</th>
                                <th>Timestamp</th>
                                <th>Notes</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">No log entries recorded.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>#<?php echo (int) $log['log_id']; ?></td>
                                        <td>#<?php echo (int) $log['request_id']; ?></td>
                                        <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($log['notes'])); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-danger" href="/admin/logs.php?delete=<?php echo (int) $log['log_id']; ?>" onclick="return confirm('Delete this log entry?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
