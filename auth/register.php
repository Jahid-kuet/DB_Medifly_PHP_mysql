<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$errors = [];
$success = false;

$hospitals = [];
$result = $conn->query('SELECT hospital_id, name FROM Hospitals ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $hospitals[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $hospitalId = $_POST['hospital_id'] !== '' ? (int) $_POST['hospital_id'] : null;

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (!in_array($role, ['admin', 'hospital', 'operator'], true)) {
        $errors[] = 'Role selection is invalid.';
    }

    if ($role !== 'hospital') {
        $hospitalId = null;
    }

    if (!$errors) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Admin-created users are approved by default
            $isApproved = 1;
            if ($hospitalId === null) {
                $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id, is_approved) VALUES (?, ?, ?, ?, ?, NULL, ?)');
                $stmt->bind_param('sssssi', $name, $username, $hashedPassword, $role, $phone, $isApproved);
            } else {
                $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssii', $name, $username, $hashedPassword, $role, $phone, $hospitalId, $isApproved);
            }
            $stmt->execute();
            setFlash('User account created successfully.');
            header('Location: ' . BASE_PATH . '/admin/users.php');
            exit;
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $errors[] = 'Username already exists. Choose another.';
            } else {
                $errors[] = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Register User | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Create New User</h1>
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $message): ?>
                            <div><?php echo htmlspecialchars($message); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Select role</option>
                                <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="hospital" <?php echo (($_POST['role'] ?? '') === 'hospital') ? 'selected' : ''; ?>>Hospital</option>
                                <option value="operator" <?php echo (($_POST['role'] ?? '') === 'operator') ? 'selected' : ''; ?>>Operator</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="hospital_id" class="form-label">Hospital (for Hospital role)</label>
                            <?php $selectedHospitalId = (int) ($_POST['hospital_id'] ?? 0); ?>
                            <select class="form-select" id="hospital_id" name="hospital_id">
                                <option value="">-- Not Applicable --</option>
                                <?php foreach ($hospitals as $hospital): ?>
                                    <option value="<?php echo (int) $hospital['hospital_id']; ?>" <?php echo ($selectedHospitalId === (int) $hospital['hospital_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($hospital['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
