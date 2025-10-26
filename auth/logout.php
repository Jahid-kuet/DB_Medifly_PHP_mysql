<?php
require_once __DIR__ . '/../includes/auth.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
session_start();

// Also clear remember-me cookie and remove any token in DB
if (isset($_COOKIE[REMEMBER_COOKIE_NAME])) {
    [$selector] = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME]) + [null];
    if ($selector) {
        try {
            $stmt = $conn->prepare('DELETE FROM AuthTokens WHERE selector = ?');
            $stmt->bind_param('s', $selector);
            $stmt->execute();
        } catch (Exception $e) {
            // ignore
        }
    }
    setcookie(REMEMBER_COOKIE_NAME, '', time() - 3600, SESSION_COOKIE_PATH, SESSION_COOKIE_DOMAIN ?? '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
}

setFlash('You have been logged out.', 'info');
header('Location: ' . BASE_PATH . '/auth/login.php');
exit;
