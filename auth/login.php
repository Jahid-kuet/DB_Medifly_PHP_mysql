<?php
require_once __DIR__ . '/../includes/auth.php';

if (currentUserId()) {
    redirectToDashboard();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $approvalColumnAvailable = true;
        try {
            $stmt = $conn->prepare('SELECT user_id, password, role, hospital_id, is_approved FROM Users WHERE username = ?');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        } catch (mysqli_sql_exception $e) {
            // If the DB doesn't have is_approved yet (migration not run), fall back to select without it.
            if ($e->getCode() === 1054 || stripos($e->getMessage(), 'is_approved') !== false) {
                $approvalColumnAvailable = false;
                $stmt = $conn->prepare('SELECT user_id, password, role, hospital_id FROM Users WHERE username = ?');
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
            } else {
                throw $e; // rethrow unexpected DB errors
            }
        }

    if ($user && password_verify($password, $user['password'])) {
            // check approval (if column exists). If not present, treat existing accounts as approved.
            $isApproved = 1;
            if ($approvalColumnAvailable) {
                $isApproved = isset($user['is_approved']) ? (int) $user['is_approved'] : 0;
            }

            if ($isApproved === 0) {
                $error = 'Your account is pending admin approval. You will be notified once approved.';
            } else {
                // Regenerate session id to prevent fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['hospital_id'] = $user['hospital_id'] ? (int) $user['hospital_id'] : null;

                // Remember me
                if (!empty($_POST['remember'])) {
                    try {
                        // create selector and validator
                        $selector = bin2hex(random_bytes(8));
                        $validator = bin2hex(random_bytes(32));
                        $validatorHash = hash('sha256', $validator);
                        $expires = date('Y-m-d H:i:s', time() + REMEMBER_COOKIE_LIFETIME);

                        $stmt = $conn->prepare('INSERT INTO AuthTokens (user_id, selector, validator_hash, expires) VALUES (?, ?, ?, ?)');
                        $stmt->bind_param('isss', $user['user_id'], $selector, $validatorHash, $expires);
                        $stmt->execute();

                        // set cookie
                        setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, time() + REMEMBER_COOKIE_LIFETIME, SESSION_COOKIE_PATH, SESSION_COOKIE_DOMAIN ?? '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
                    } catch (Exception $e) {
                        // ignore remember failures
                    }
                }

                redirectToDashboard();
            }
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

$pageTitle = 'Login | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3 text-center">Login</h1>
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                    <button type="submit" class="btn btn-primary w-100">Sign In</button>
                </form>
                <div class="mt-3 text-center">
                    <a href="<?php echo BASE_PATH; ?>/auth/self_register.php" class="btn btn-link">Register a new account</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
