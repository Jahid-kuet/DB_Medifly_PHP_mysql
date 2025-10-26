<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$editingDrone = null;
$allowedStatuses = ['available', 'maintenance', 'assigned'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model = trim($_POST['model'] ?? '');
    $capacity = (int) ($_POST['capacity'] ?? 0);
    $status = $_POST['status'] ?? 'available';

    if ($model === '') {
        setFlash('Drone model is required.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/drones.php');
        exit;
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'available';
    }

    // Normalize drone_id and treat as update only when > 0
    $droneId = isset($_POST['drone_id']) ? (int) $_POST['drone_id'] : 0;
    if ($droneId > 0) {
        $stmt = $conn->prepare('UPDATE Drones SET model = ?, capacity = ?, status = ? WHERE drone_id = ?');
        $stmt->bind_param('sisi', $model, $capacity, $status, $droneId);
        $stmt->execute();
        setFlash('Drone updated successfully.');
    } else {
        $stmt = $conn->prepare('INSERT INTO Drones (model, capacity, status) VALUES (?, ?, ?)');
        $stmt->bind_param('sis', $model, $capacity, $status);
        $stmt->execute();
        setFlash('Drone added successfully.');
    }

    header('Location: ' . BASE_PATH . '/admin/drones.php');
    exit;
}

if (isset($_GET['delete'])) {
    $droneId = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM Drones WHERE drone_id = ?');
    $stmt->bind_param('i', $droneId);
    $stmt->execute();
    setFlash('Drone removed.', 'info');
    header('Location: ' . BASE_PATH . '/admin/drones.php');
    exit;
}

if (isset($_GET['edit'])) {
    $droneId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM Drones WHERE drone_id = ?');
    $stmt->bind_param('i', $droneId);
    $stmt->execute();
    $result = $stmt->get_result();
    $editingDrone = $result->fetch_assoc();
    if (!$editingDrone) {
        setFlash('Drone not found.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/drones.php');
        exit;
    }
}

$drones = [];
$result = $conn->query('SELECT * FROM Drones ORDER BY drone_id DESC');
while ($row = $result->fetch_assoc()) {
    $drones[] = $row;
}

$pageTitle = 'Manage Drones | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?php echo $editingDrone ? 'Edit Drone' : 'Add Drone'; ?></h2>
                <form method="post" novalidate>
                    <input type="hidden" name="drone_id" value="<?php echo $editingDrone ? (int) $editingDrone['drone_id'] : ''; ?>">
                    <div class="mb-3">
                        <label for="model" class="form-label">Model</label>
                        <input type="text" class="form-control" id="model" name="model" value="<?php echo htmlspecialchars($editingDrone['model'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="capacity" class="form-label">Capacity</label>
                        <input type="number" class="form-control" id="capacity" name="capacity" value="<?php echo htmlspecialchars((string) ($editingDrone['capacity'] ?? '')); ?>" min="0">
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach ($allowedStatuses as $state): ?>
                                <option value="<?php echo $state; ?>" <?php echo (($editingDrone['status'] ?? 'available') === $state) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($state); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editingDrone ? 'Update Drone' : 'Add Drone'; ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Drone Fleet</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Model</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$drones): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">No drones available.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($drones as $drone): ?>
                                    <tr>
                                        <td>#<?php echo (int) $drone['drone_id']; ?></td>
                                        <td><?php echo htmlspecialchars($drone['model']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $drone['capacity']); ?></td>
                                        <td class="text-capitalize"><?php echo htmlspecialchars($drone['status']); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_PATH; ?>/admin/drones.php?edit=<?php echo (int) $drone['drone_id']; ?>">Edit</a>
                                            <a class="btn btn-sm btn-outline-danger" href="<?php echo BASE_PATH; ?>/admin/drones.php?delete=<?php echo (int) $drone['drone_id']; ?>" onclick="return confirm('Delete this drone?');">Delete</a>
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
