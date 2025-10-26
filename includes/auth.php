<?php
require_once __DIR__ . '/config.php';

// Configure session cookie params before starting session
if (session_status() === PHP_SESSION_NONE) {
    $secure = defined('SESSION_COOKIE_SECURE') ? SESSION_COOKIE_SECURE : false;
    $httponly = defined('SESSION_COOKIE_HTTPONLY') ? SESSION_COOKIE_HTTPONLY : true;
    $samesite = defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Lax';

    // PHP < 7.3 compatibility: pass samesite via header if not supported
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => SESSION_COOKIE_LIFETIME ?? $params['lifetime'],
        'path' => SESSION_COOKIE_PATH ?? $params['path'],
        'domain' => SESSION_COOKIE_DOMAIN ?? $params['domain'],
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite,
    ]);

    session_start();
}

// Auto-login via remember-me cookie if session not present
if (empty($_SESSION['user_id']) && isset($_COOKIE[REMEMBER_COOKIE_NAME])) {
    // Make sure the AuthTokens table exists before attempting any DB work
    try {
        $tblCheck = $conn->query("SHOW TABLES LIKE 'AuthTokens'");
    } catch (Exception $e) {
        $tblCheck = false;
    }

    if ($tblCheck && $tblCheck->num_rows > 0) {
        [$selector, $validator] = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME]) + [null, null];
        if ($selector && $validator) {
            try {
                $stmt = $conn->prepare('SELECT at.user_id, at.validator_hash, at.expires, u.role, u.hospital_id FROM AuthTokens at JOIN Users u ON at.user_id = u.user_id WHERE at.selector = ?');
                $stmt->bind_param('s', $selector);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    if (strtotime($row['expires']) >= time() && hash_equals($row['validator_hash'], hash('sha256', $validator))) {
                        // restore session
                        $_SESSION['user_id'] = (int) $row['user_id'];
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['hospital_id'] = $row['hospital_id'] ? (int) $row['hospital_id'] : null;
                        // refresh token expiry (optional)
                        $newExpires = date('Y-m-d H:i:s', time() + REMEMBER_COOKIE_LIFETIME);
                        $stmt = $conn->prepare('UPDATE AuthTokens SET expires = ? WHERE selector = ?');
                        $stmt->bind_param('ss', $newExpires, $selector);
                        $stmt->execute();
                        // refresh cookie
                        setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, time() + REMEMBER_COOKIE_LIFETIME, SESSION_COOKIE_PATH, SESSION_COOKIE_DOMAIN ?? '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
                    } else {
                        // invalid or expired - clear cookie and DB row if present
                        setcookie(REMEMBER_COOKIE_NAME, '', time() - 3600, SESSION_COOKIE_PATH, SESSION_COOKIE_DOMAIN ?? '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
                        $stmt = $conn->prepare('DELETE FROM AuthTokens WHERE selector = ?');
                        $stmt->bind_param('s', $selector);
                        $stmt->execute();
                    }
                }
            } catch (Exception $e) {
                // ignore remember-me failures silently
            }
        }
    }
}


function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_PATH . '/auth/login.php');
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();

    if (!in_array($_SESSION['role'], $roles, true)) {
        setFlash('You are not authorized to access that page.', 'danger');
        header('Location: ' . BASE_PATH . '/home.php');
        exit;
    }
}

function currentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function currentHospitalId(): ?int
{
    return $_SESSION['hospital_id'] ?? null;
}

function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

function isRole(string $role): bool
{
    return currentRole() === $role;
}

function redirectToDashboard(): void
{
    if (!currentUserId()) {
        header('Location: ' . BASE_PATH . '/auth/login.php');
        exit;
    }

    switch (currentRole()) {
        case 'admin':
            header('Location: ' . BASE_PATH . '/admin/dashboard.php');
            break;
        case 'hospital':
            header('Location: ' . BASE_PATH . '/hospital/dashboard.php');
            break;
        case 'operator':
            header('Location: ' . BASE_PATH . '/operator/dashboard.php');
            break;
        default:
            header('Location: ' . BASE_PATH . '/auth/logout.php');
            break;
    }
    exit;
}

function setFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }

    return null;
}
