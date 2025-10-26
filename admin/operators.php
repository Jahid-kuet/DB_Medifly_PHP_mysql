<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$editingOperator = null;

$operatorUsers = [];
$stmt = $conn->prepare("SELECT user_id, name FROM Users WHERE role = 'operator' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $operatorUsers[] = $row;
}

$drones = [];
$result = $conn->query("SELECT drone_id, model, status FROM Drones ORDER BY model");
while ($row = $result->fetch_assoc()) {
    $drones[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $droneId = (int) ($_POST['drone_id'] ?? 0);

    if (!$userId || !$droneId) {
        setFlash('Operator and drone selection are required.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/operators.php');
        exit;
    }

    $operatorId = isset($_POST['operator_id']) && $_POST['operator_id'] !== '' ? (int) $_POST['operator_id'] : null;
    $previousDroneId = null;

    if ($operatorId) {
        $stmt = $conn->prepare('SELECT drone_id FROM Operators WHERE operator_id = ?');
        $stmt->bind_param('i', $operatorId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $previousDroneId = (int) $row['drone_id'];
        }

        $stmt = $conn->prepare('UPDATE Operators SET user_id = ?, drone_id = ? WHERE operator_id = ?');
        $stmt->bind_param('iii', $userId, $droneId, $operatorId);
        $stmt->execute();
        setFlash('Operator assignment updated.');
    } else {
        $stmt = $conn->prepare('INSERT INTO Operators (user_id, drone_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $userId, $droneId);
        $stmt->execute();
        setFlash('Operator assignment created.');
    }

    $stmt = $conn->prepare("UPDATE Drones SET status = 'assigned' WHERE drone_id = ?");
    $stmt->bind_param('i', $droneId);
    $stmt->execute();

    if ($previousDroneId && $previousDroneId !== $droneId) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM Operators WHERE drone_id = ?');
        $stmt->bind_param('i', $previousDroneId);
        $stmt->execute();
        $countResult = $stmt->get_result()->fetch_assoc();
        if (($countResult['total'] ?? 0) == 0) {
            $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
            $stmt->bind_param('i', $previousDroneId);
            $stmt->execute();
        }
    }

    header('Location: ' . BASE_PATH . '/admin/operators.php');
    exit;
}

if (isset($_GET['delete'])) {
    $operatorId = (int) $_GET['delete'];
    $previousDroneId = null;
    $stmt = $conn->prepare('SELECT drone_id FROM Operators WHERE operator_id = ?');
    $stmt->bind_param('i', $operatorId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $previousDroneId = (int) $row['drone_id'];
    }

    $stmt = $conn->prepare('DELETE FROM Operators WHERE operator_id = ?');
    $stmt->bind_param('i', $operatorId);
    $stmt->execute();
    setFlash('Operator assignment removed.', 'info');

    if ($previousDroneId) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM Operators WHERE drone_id = ?');
        $stmt->bind_param('i', $previousDroneId);
        $stmt->execute();
        $countResult = $stmt->get_result()->fetch_assoc();
        if (($countResult['total'] ?? 0) == 0) {
            $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
            $stmt->bind_param('i', $previousDroneId);
            $stmt->execute();
        }
    }

    header('Location: ' . BASE_PATH . '/admin/operators.php');
    exit;
}

if (isset($_GET['edit'])) {
    $operatorId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM Operators WHERE operator_id = ?');
    $stmt->bind_param('i', $operatorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $editingOperator = $result->fetch_assoc();
    if (!$editingOperator) {
        setFlash('Operator assignment not found.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/operators.php');
        exit;
    }
}

$assignments = [];
$stmt = $conn->prepare('SELECT o.operator_id, u.name AS operator_name, d.model AS drone_model, d.status
    FROM Operators o
    LEFT JOIN Users u ON o.user_id = u.user_id
    LEFT JOIN Drones d ON o.drone_id = d.drone_id
    ORDER BY u.name');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}

$pageTitle = 'Manage Operators | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?php echo $editingOperator ? 'Edit Assignment' : 'Assign Operator'; ?></h2>
                <form method="post" novalidate>
                    <input type="hidden" name="operator_id" value="<?php echo (int) ($editingOperator['operator_id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label for="user_id" class="form-label">Operator</label>
                        <select class="form-select" id="user_id" name="user_id" required>
                            <option value="">Choose operator</option>
                            <?php foreach ($operatorUsers as $operator): ?>
                                <option value="<?php echo (int) $operator['user_id']; ?>" <?php echo ((int) ($editingOperator['user_id'] ?? 0) === (int) $operator['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($operator['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="drone_id" class="form-label">Drone</label>
                        <select class="form-select" id="drone_id" name="drone_id" required>
                            <option value="">Choose drone</option>
                            <?php foreach ($drones as $drone): ?>
                                <option value="<?php echo (int) $drone['drone_id']; ?>" <?php echo ((int) ($editingOperator['drone_id'] ?? 0) === (int) $drone['drone_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($drone['model'] . ' (' . $drone['status'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editingOperator ? 'Update Assignment' : 'Assign Operator'; ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Operator Assignments</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Operator</th>
                                <th>Drone</th>
                                <th>Drone Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$assignments): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">No operator assignments yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['operator_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['drone_model'] ?? 'Unassigned'); ?></td>
                                        <td class="text-capitalize"><?php echo htmlspecialchars($assignment['status'] ?? '-'); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="/admin/operators.php?edit=<?php echo (int) $assignment['operator_id']; ?>">Edit</a>
                                            <a class="btn btn-sm btn-outline-danger" href="/admin/operators.php?delete=<?php echo (int) $assignment['operator_id']; ?>" onclick="return confirm('Remove this assignment?');">Delete</a>
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
