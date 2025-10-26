<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$editingUser = null;

// Ensure the is_approved column exists. If missing (older DB), try to add it
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM `Users` LIKE 'is_approved'");
    if ($colCheck && $colCheck->num_rows === 0) {
        // add the column and approve existing admins
        $conn->query("ALTER TABLE `Users` ADD COLUMN `is_approved` TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("UPDATE `Users` SET `is_approved` = 1 WHERE `role` = 'admin'");
        setFlash('Database updated: added is_approved column and approved existing admin accounts.', 'info');
    }
} catch (mysqli_sql_exception $e) {
    // If we cannot alter the table (permissions or other), surface a friendly message but allow the page to continue.
    // Avoid throwing — admin actions that reference the column will still be guarded by checks later.
    error_log('Could not auto-migrate is_approved column: ' . $e->getMessage());
    // Optionally notify the admin in UI
    setFlash('Warning: Database schema missing is_approved column. Run the migration script scripts/add_is_approved_column.php or create the column manually.', 'warning');
}

// Fetch hospitals for dropdowns
$hospitals = [];
$result = $conn->query('SELECT hospital_id, name FROM Hospitals ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $hospitals[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $hospitalId = $_POST['hospital_id'] !== '' ? (int) $_POST['hospital_id'] : null;
    $password = $_POST['password'] ?? '';

    if ($name === '' || $username === '') {
        setFlash('Name and username are required.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/users.php?edit=' . $userId);
        exit;
    }

    if (!in_array($role, ['admin', 'hospital', 'operator'], true)) {
        setFlash('Invalid role selection.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/users.php?edit=' . $userId);
        exit;
    }

    if ($role !== 'hospital') {
        $hospitalId = null;
    } elseif (!$hospitalId) {
        setFlash('Hospital selection is required for hospital role.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/users.php?edit=' . $userId);
        exit;
    }

    try {
        if ($password !== '') {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            if ($hospitalId === null) {
                $stmt = $conn->prepare('UPDATE Users SET name = ?, username = ?, password = ?, role = ?, phone = ?, hospital_id = NULL WHERE user_id = ?');
                $stmt->bind_param('sssssi', $name, $username, $hashedPassword, $role, $phone, $userId);
            } else {
                $stmt = $conn->prepare('UPDATE Users SET name = ?, username = ?, password = ?, role = ?, phone = ?, hospital_id = ? WHERE user_id = ?');
                $stmt->bind_param('sssssii', $name, $username, $hashedPassword, $role, $phone, $hospitalId, $userId);
            }
        } else {
            if ($hospitalId === null) {
                $stmt = $conn->prepare('UPDATE Users SET name = ?, username = ?, role = ?, phone = ?, hospital_id = NULL WHERE user_id = ?');
                $stmt->bind_param('ssssi', $name, $username, $role, $phone, $userId);
            } else {
                $stmt = $conn->prepare('UPDATE Users SET name = ?, username = ?, role = ?, phone = ?, hospital_id = ? WHERE user_id = ?');
                $stmt->bind_param('ssssii', $name, $username, $role, $phone, $hospitalId, $userId);
            }
        }
        $stmt->execute();
        setFlash('User updated successfully.');
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            setFlash('Username already exists. Choose another.', 'danger');
        } else {
            setFlash('Failed to update user: ' . $e->getMessage(), 'danger');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

// Handle approve/disapprove actions
if (isset($_GET['approve'])) {
    $uid = (int) $_GET['approve'];
    $stmt = $conn->prepare('UPDATE Users SET is_approved = 1 WHERE user_id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    setFlash('User approved.');
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if (isset($_GET['disapprove'])) {
    $uid = (int) $_GET['disapprove'];
    $stmt = $conn->prepare('UPDATE Users SET is_approved = 0 WHERE user_id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    setFlash('User marked as not approved.');
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if (isset($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    if ($deleteId === currentUserId()) {
        setFlash('You cannot delete your own account.', 'danger');
    } else {
        $stmt = $conn->prepare('DELETE FROM Users WHERE user_id = ?');
        $stmt->bind_param('i', $deleteId);
        $stmt->execute();
        setFlash('User deleted.', 'info');
    }
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if (isset($_GET['edit'])) {
    $userId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM Users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $editingUser = $result->fetch_assoc();
    if (!$editingUser) {
        setFlash('User not found.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/users.php');
        exit;
    }
}

$users = [];
$stmt = $conn->prepare('SELECT u.*, h.name AS hospital_name FROM Users u LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id ORDER BY u.role, u.name');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$pageTitle = 'Manage Users | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0"><?php echo $editingUser ? 'Edit User' : 'Add User'; ?></h2>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_PATH; ?>/auth/register.php">New User</a>
                </div>
                <?php if ($editingUser): ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="user_id" value="<?php echo (int) $editingUser['user_id']; ?>">
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editingUser['name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($editingUser['username'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="admin" <?php echo ($editingUser['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="hospital" <?php echo ($editingUser['role'] === 'hospital') ? 'selected' : ''; ?>>Hospital</option>
                                <option value="operator" <?php echo ($editingUser['role'] === 'operator') ? 'selected' : ''; ?>>Operator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($editingUser['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="hospital_id" class="form-label">Hospital (for Hospital role)</label>
                            <select class="form-select" id="hospital_id" name="hospital_id">
                                <option value="">-- Not Applicable --</option>
                                <?php foreach ($hospitals as $hospital): ?>
                                    <option value="<?php echo (int) $hospital['hospital_id']; ?>" <?php echo ((int) ($editingUser['hospital_id'] ?? 0) === (int) $hospital['hospital_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($hospital['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
                            <input type="password" class="form-control" id="password" name="password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update User</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">Select a user from the list to edit.
                        <br>Need a new account? Use the <strong>New User</strong> button.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">User Directory</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Hospital</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                        <tbody>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">No users yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td class="text-capitalize"><?php echo htmlspecialchars($user['role']); ?></td>
                                        <td><?php echo htmlspecialchars($user['hospital_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                                        <td><?php echo ((int) ($user['is_approved'] ?? 0)) ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-secondary">Pending</span>'; ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_PATH; ?>/admin/users.php?edit=<?php echo (int) $user['user_id']; ?>">Edit</a>
                                            <?php if ((int) $user['user_id'] !== currentUserId()): ?>
                                                <a class="btn btn-sm btn-outline-danger" href="<?php echo BASE_PATH; ?>/admin/users.php?delete=<?php echo (int) $user['user_id']; ?>" onclick="return confirm('Delete this user?');">Delete</a>
                                            <?php endif; ?>
                                            <?php if (!(int) ($user['is_approved'] ?? 0)): ?>
                                                <a class="btn btn-sm btn-outline-success ms-1" href="<?php echo BASE_PATH; ?>/admin/users.php?approve=<?php echo (int) $user['user_id']; ?>">Approve</a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-outline-secondary ms-1" href="<?php echo BASE_PATH; ?>/admin/users.php?disapprove=<?php echo (int) $user['user_id']; ?>">Disapprove</a>
                                            <?php endif; ?>
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
