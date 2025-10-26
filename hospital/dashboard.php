<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['hospital']);

$userId = currentUserId();

$statusCounts = [];
$statuses = ['pending', 'approved', 'in-transit', 'delivered', 'cancelled'];

$stmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM DeliveryRequests WHERE user_id = ? GROUP BY status");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$recentRequests = [];
$stmt = $conn->prepare('SELECT dr.request_id, dr.destination, dr.status, s.name AS supply_name
    FROM DeliveryRequests dr
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    WHERE dr.user_id = ?
    ORDER BY dr.request_id DESC
    LIMIT 5');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentRequests[] = $row;
}

$pageTitle = 'Hospital Dashboard | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <?php foreach ($statuses as $state): ?>
        <div class="col-md-4 col-lg-2">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-uppercase mb-1"><?php echo ucfirst($state); ?></h2>
                    <p class="display-6 mb-0"><?php echo $statusCounts[$state] ?? 0; ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Recent Requests</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Supply</th>
                        <th>Destination</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentRequests): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4">No requests submitted yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentRequests as $request): ?>
                            <tr>
                                <td>#<?php echo (int) $request['request_id']; ?></td>
                                <td><?php echo htmlspecialchars($request['supply_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($request['destination'] ?? ''); ?></td>
                                <td><span class="badge bg-secondary text-capitalize"><?php echo htmlspecialchars($request['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
