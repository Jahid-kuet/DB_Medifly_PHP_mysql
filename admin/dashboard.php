<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$counts = [
    'hospitals' => 0,
    'users' => 0,
    'drones' => 0,
    'supplies' => 0,
    'requests' => 0,
];

$queries = [
    'hospitals' => 'SELECT COUNT(*) AS total FROM Hospitals',
    'users' => "SELECT COUNT(*) AS total FROM Users WHERE role <> 'admin'",
    'drones' => 'SELECT COUNT(*) AS total FROM Drones',
    'supplies' => 'SELECT COUNT(*) AS total FROM Supplies',
    'requests' => 'SELECT COUNT(*) AS total FROM DeliveryRequests',
];

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);
    $counts[$key] = (int) ($result->fetch_assoc()['total'] ?? 0);
}

$recentRequests = [];
$stmt = $conn->prepare('SELECT dr.request_id, u.name AS requester, s.name AS supply_name, dr.destination, dr.status, dr.operator_id, dr.drone_id
    FROM DeliveryRequests dr
    LEFT JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    ORDER BY dr.request_id DESC
    LIMIT 5');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentRequests[] = $row;
}

$pageTitle = 'Admin Dashboard | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-md-3">
        <div class="card text-bg-primary shadow-sm">
            <div class="card-body">
                <h2 class="card-title h6 text-uppercase">Hospitals</h2>
                <p class="display-6 mb-0"><?php echo $counts['hospitals']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success shadow-sm">
            <div class="card-body">
                <h2 class="card-title h6 text-uppercase">Users</h2>
                <p class="display-6 mb-0"><?php echo $counts['users']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-warning shadow-sm">
            <div class="card-body">
                <h2 class="card-title h6 text-uppercase">Drones</h2>
                <p class="display-6 mb-0"><?php echo $counts['drones']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info shadow-sm">
            <div class="card-body">
                <h2 class="card-title h6 text-uppercase">Supplies</h2>
                <p class="display-6 mb-0"><?php echo $counts['supplies']; ?></p>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Recent Delivery Requests</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Requester</th>
                        <th>Supply</th>
                        <th>Destination</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentRequests): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">No recent requests.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentRequests as $request): ?>
                            <tr>
                                <td>#<?php echo (int) $request['request_id']; ?></td>
                                <td><?php echo htmlspecialchars($request['requester'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($request['supply_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($request['destination'] ?? ''); ?></td>
                                <td>
                                    <span class="badge bg-secondary text-capitalize"><?php echo htmlspecialchars($request['status']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
