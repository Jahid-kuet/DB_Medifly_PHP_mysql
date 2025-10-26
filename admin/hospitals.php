<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$editingHospital = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $location === '') {
        setFlash('Name and location are required.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/hospitals.php');
        exit;
    }

    // Normalize hospital_id and treat as update only when > 0
    $hospitalId = isset($_POST['hospital_id']) ? (int) $_POST['hospital_id'] : 0;
    if ($hospitalId > 0) {
        $stmt = $conn->prepare('UPDATE Hospitals SET name = ?, location = ? WHERE hospital_id = ?');
        $stmt->bind_param('ssi', $name, $location, $hospitalId);
        $stmt->execute();
        setFlash('Hospital updated successfully.');
    } else {
        $stmt = $conn->prepare('INSERT INTO Hospitals (name, location) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $location);
        $stmt->execute();
        setFlash('Hospital created successfully.');
    }

    header('Location: ' . BASE_PATH . '/admin/hospitals.php');
    exit;
}

if (isset($_GET['delete'])) {
    $hospitalId = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM Hospitals WHERE hospital_id = ?');
    $stmt->bind_param('i', $hospitalId);
    $stmt->execute();
    setFlash('Hospital deleted.', 'info');
    header('Location: ' . BASE_PATH . '/admin/hospitals.php');
    exit;
}

if (isset($_GET['edit'])) {
    $hospitalId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM Hospitals WHERE hospital_id = ?');
    $stmt->bind_param('i', $hospitalId);
    $stmt->execute();
    $result = $stmt->get_result();
    $editingHospital = $result->fetch_assoc();
    if (!$editingHospital) {
        setFlash('Hospital not found.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/hospitals.php');
        exit;
    }
}

$hospitals = [];
$result = $conn->query('SELECT * FROM Hospitals ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $hospitals[] = $row;
}

$pageTitle = 'Manage Hospitals | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?php echo $editingHospital ? 'Edit Hospital' : 'Add Hospital'; ?></h2>
                <form method="post" novalidate>
                    <input type="hidden" name="hospital_id" value="<?php echo $editingHospital ? (int) $editingHospital['hospital_id'] : ''; ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Hospital Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editingHospital['name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <textarea class="form-control" id="location" name="location" rows="3" required><?php echo htmlspecialchars($editingHospital['location'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editingHospital ? 'Update Hospital' : 'Create Hospital'; ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Hospital Directory</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Location</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$hospitals): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4">No hospitals registered yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($hospitals as $hospital): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($hospital['name']); ?></td>
                                        <td><?php echo htmlspecialchars($hospital['location']); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_PATH; ?>/admin/hospitals.php?edit=<?php echo (int) $hospital['hospital_id']; ?>">Edit</a>
                                            <a class="btn btn-sm btn-outline-danger" href="<?php echo BASE_PATH; ?>/admin/hospitals.php?delete=<?php echo (int) $hospital['hospital_id']; ?>" onclick="return confirm('Delete this hospital?');">Delete</a>
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
