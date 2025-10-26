<?php
// Database configuration and connection bootstrap
$host = 'localhost';
$username = 'root';
$password = '';
$dbName = 'mediflydb_php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $username, $password, $dbName);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'MediFly Delivery');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/DB_PHP');
}

if (!defined('GOOGLE_MAPS_API_KEY')) {
    // Get your API key from: https://console.cloud.google.com/google/maps-apis
    // IMPORTANT: Restrict this key in Google Cloud Console to allowed HTTP referrers (e.g. http://localhost/*)
    // and keep it private. Only set an unrestricted key for local testing and rotate it before production.
    define('GOOGLE_MAPS_API_KEY', 'AIzaSyAsqGFOcy1gJOZYEC-q41XTXPFqO9ejxSA');
}

// Session & cookie configuration
if (!defined('SESSION_COOKIE_LIFETIME')) {
    // 0 = until browser close. Set to 0 for default session behavior.
    define('SESSION_COOKIE_LIFETIME', 0);
}

if (!defined('SESSION_COOKIE_PATH')) {
    define('SESSION_COOKIE_PATH', '/');
}

if (!defined('SESSION_COOKIE_DOMAIN')) {
    // null will let PHP use the current host
    define('SESSION_COOKIE_DOMAIN', null);
}

if (!defined('SESSION_COOKIE_SECURE')) {
    // set true when serving over HTTPS in production
    define('SESSION_COOKIE_SECURE', false);
}

if (!defined('SESSION_COOKIE_HTTPONLY')) {
    define('SESSION_COOKIE_HTTPONLY', true);
}

if (!defined('SESSION_COOKIE_SAMESITE')) {
    // Lax is a sensible default
    define('SESSION_COOKIE_SAMESITE', 'Lax');
}

// Remember-me cookie settings (30 days)
if (!defined('REMEMBER_COOKIE_NAME')) {
    define('REMEMBER_COOKIE_NAME', 'mf_remember');
}

if (!defined('REMEMBER_COOKIE_LIFETIME')) {
    define('REMEMBER_COOKIE_LIFETIME', 60 * 60 * 24 * 30);
}

// Khulna City Center Coordinates
if (!defined('DEFAULT_LAT')) {
    define('DEFAULT_LAT', 22.8456);
}

if (!defined('DEFAULT_LNG')) {
    define('DEFAULT_LNG', 89.5403);
}
