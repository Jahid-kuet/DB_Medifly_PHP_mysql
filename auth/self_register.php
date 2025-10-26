<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$errors = [];

// Fetch hospitals for dropdown
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

    if ($name === '') $errors[] = 'Name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($password === '') $errors[] = 'Password is required.';
    if (!in_array($role, ['hospital', 'operator', 'admin'], true)) $errors[] = 'Invalid role.';
    if ($role === 'hospital' && !$hospitalId) $errors[] = 'Select your hospital.';

    // disallow reserved usernames
    $reserved = ['admin', 'hospital', 'operator'];
    if (in_array(strtolower($username), $reserved, true)) {
        $errors[] = 'Username reserved, choose another.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $isApproved = ($role === 'hospital') ? 1 : 0;

        try {
            if ($hospitalId === null) {
                $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id, is_approved) VALUES (?, ?, ?, ?, ?, NULL, ?)');
                $stmt->bind_param('sssssi', $name, $username, $hash, $role, $phone, $isApproved);
            } else {
                $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssii', $name, $username, $hash, $role, $phone, $hospitalId, $isApproved);
            }
            $stmt->execute();

            if ($isApproved) {
                // auto-login hospital users
                $newId = $stmt->insert_id;
                $_SESSION['user_id'] = (int) $newId;
                $_SESSION['role'] = $role;
                $_SESSION['hospital_id'] = $hospitalId ? (int) $hospitalId : null;

                setFlash('Registration successful. You are now logged in.');
                header('Location: ' . BASE_PATH . '/hospital/dashboard.php');
                exit;
            }

            setFlash('Registration submitted and pending admin approval. You will be notified after approval.', 'info');
            header('Location: ' . BASE_PATH . '/auth/login.php');
            exit;
        } catch (mysqli_sql_exception $e) {
            // If DB doesn't have is_approved column yet, fall back to insert without it
            if ($e->getCode() === 1054 || stripos($e->getMessage(), 'is_approved') !== false) {
                try {
                    if ($hospitalId === null) {
                        $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id) VALUES (?, ?, ?, ?, ?, NULL)');
                        $stmt->bind_param('sssss', $name, $username, $hash, $role, $phone);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('ssssis', $name, $username, $hash, $role, $phone, $hospitalId);
                    }
                    $stmt->execute();

                    // If role is hospital, auto-login; otherwise, treat as pending (cannot store is_approved yet)
                    if ($role === 'hospital') {
                        $newId = $stmt->insert_id;
                        $_SESSION['user_id'] = (int) $newId;
                        $_SESSION['role'] = $role;
                        $_SESSION['hospital_id'] = $hospitalId ? (int) $hospitalId : null;
                        setFlash('Registration successful. You are now logged in.');
                        header('Location: ' . BASE_PATH . '/hospital/dashboard.php');
                        exit;
                    }

                    setFlash('Registration submitted and pending admin approval. You will be notified after approval.', 'info');
                    header('Location: ' . BASE_PATH . '/auth/login.php');
                    exit;
                } catch (mysqli_sql_exception $e2) {
                    if ($e2->getCode() === 1062) {
                        $errors[] = 'Username already exists.';
                    } else {
                        $errors[] = 'Registration failed: ' . $e2->getMessage();
                    }
                }
            } else {
                if ($e->getCode() === 1062) {
                    $errors[] = 'Username already exists.';
                } else {
                    $errors[] = 'Registration failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Register | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Register</h1>
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $err): ?>
                            <div><?php echo htmlspecialchars($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="hospital" <?php echo (($_POST['role'] ?? '') === 'hospital') ? 'selected' : ''; ?>>User (Hospital)</option>
                            <option value="operator" <?php echo (($_POST['role'] ?? '') === 'operator') ? 'selected' : ''; ?>>Operator (Requires admin approval)</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin (Requires admin approval)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Hospital (for Hospital role)</label>
                        <select name="hospital_id" class="form-select">
                            <option value="">-- Select Hospital --</option>
                            <?php foreach ($hospitals as $h): ?>
                                <option value="<?php echo (int) $h['hospital_id']; ?>" <?php echo (((int) ($_POST['hospital_id'] ?? 0)) === (int) $h['hospital_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($h['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-primary">Register</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
