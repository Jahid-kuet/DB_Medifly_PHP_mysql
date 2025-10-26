<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['operator']);

$userId = currentUserId();

$statusCounts = [];
$statuses = ['approved', 'in-transit', 'delivered'];
$stmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM DeliveryRequests WHERE operator_id = ? GROUP BY status');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$upcoming = [];
$stmt = $conn->prepare('SELECT dr.request_id, dr.destination, dr.status, s.name AS supply_name, d.model AS drone_model
    FROM DeliveryRequests dr
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    LEFT JOIN Drones d ON dr.drone_id = d.drone_id
    WHERE dr.operator_id = ? AND dr.status IN ("approved", "in-transit")
    ORDER BY dr.request_id DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $upcoming[] = $row;
}

$pageTitle = 'Operator Dashboard | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <?php foreach ($statuses as $state): ?>
        <div class="col-md-4">
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
        <h2 class="h5 mb-0">Active Assignments</h2>
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
                        <th>Drone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$upcoming): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">No active assignments at the moment.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($upcoming as $assignment): ?>
                            <tr>
                                <td>#<?php echo (int) $assignment['request_id']; ?></td>
                                <td><?php echo htmlspecialchars($assignment['supply_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($assignment['destination'] ?? ''); ?></td>
                                <td><span class="badge bg-secondary text-capitalize"><?php echo htmlspecialchars($assignment['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($assignment['drone_model'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
