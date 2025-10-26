<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .session-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background-color: #e9ecef;
        }
        .actions {
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .btn:hover {
            background-color: #0056b3;
        }
        .btn-success {
            background-color: #28a745;
        }
        .btn-success:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>
    <h1>🔍 Session Debug Information</h1>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="status success">
            <strong>✅ Logged In</strong><br>
            User ID: <?php echo $_SESSION['user_id']; ?><br>
            Role: <strong><?php echo strtoupper($_SESSION['role'] ?? 'NONE'); ?></strong><br>
            Username: <?php echo $_SESSION['username'] ?? 'N/A'; ?>
        </div>
    <?php else: ?>
        <div class="status error">
            <strong>❌ Not Logged In</strong><br>
            You need to login first to access role-based pages.
        </div>
    <?php endif; ?>

    <div class="session-info">
        <h2>Current Session Data:</h2>
        <table>
            <thead>
                <tr>
                    <th>Session Key</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($_SESSION)): ?>
                    <tr>
                        <td colspan="2" style="text-align: center; color: #999;">
                            No session data found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($_SESSION as $key => $value): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                            <td><?php echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="actions">
        <h3>Quick Actions:</h3>
        <a href="/DB_PHP/auth/login.php" class="btn">🔐 Go to Login</a>
        <a href="/DB_PHP/auth/logout.php" class="btn" style="background-color: #dc3545;">🚪 Logout</a>
        <a href="/DB_PHP/home.php" class="btn btn-success">🏠 Go Home</a>
    </div>

    <div class="session-info" style="margin-top: 20px;">
        <h3>Test Logins:</h3>
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Dashboard</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Admin</strong></td>
                    <td>admin</td>
                    <td>admin123</td>
                    <td><a href="/DB_PHP/admin/dashboard.php">/admin/dashboard.php</a></td>
                </tr>
                <tr>
                    <td><strong>Hospital</strong></td>
                    <td>hospital</td>
                    <td>hospital123</td>
                    <td><a href="/DB_PHP/hospital/dashboard.php">/hospital/dashboard.php</a></td>
                </tr>
                <tr>
                    <td><strong>Operator</strong></td>
                    <td>operator</td>
                    <td>operator123</td>
                    <td><a href="/DB_PHP/operator/dashboard.php">/operator/dashboard.php</a></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="session-info" style="margin-top: 20px;">
            <h3>Your Access:</h3>
            <p>Based on your role (<strong><?php echo strtoupper($_SESSION['role'] ?? 'NONE'); ?></strong>), you can access:</p>
            <ul>
                <?php 
                $role = $_SESSION['role'] ?? '';
                switch($role) {
                    case 'admin':
                        echo '<li>✅ <a href="/DB_PHP/admin/dashboard.php">Admin Dashboard</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/admin/requests.php">Manage Requests</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/admin/payments.php">Manage Payments</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/admin/users.php">Manage Users</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/admin/drones.php">Manage Drones</a></li>';
                        break;
                    case 'hospital':
                        echo '<li>✅ <a href="/DB_PHP/hospital/dashboard.php">Hospital Dashboard</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/hospital/requests.php">My Requests</a></li>';
                        break;
                    case 'operator':
                        echo '<li>✅ <a href="/DB_PHP/operator/dashboard.php">Operator Dashboard</a></li>';
                        echo '<li>✅ <a href="/DB_PHP/operator/requests.php">My Deliveries</a></li>';
                        break;
                    default:
                        echo '<li>❌ No role assigned</li>';
                }
                ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="session-info" style="margin-top: 20px; background-color: #fff3cd;">
        <h3>⚠️ Authorization Error?</h3>
        <p><strong>"You are not authorized to access that page"</strong> means:</p>
        <ol>
            <li>You are not logged in, OR</li>
            <li>Your current role doesn't have permission for that page</li>
        </ol>
        <p><strong>Solution:</strong></p>
        <ul>
            <li>For <strong>operator/dashboard.php</strong> → Login as: <code>operator / operator123</code></li>
            <li>For <strong>hospital/dashboard.php</strong> → Login as: <code>hospital / hospital123</code></li>
            <li>For <strong>admin/dashboard.php</strong> → Login as: <code>admin / admin123</code></li>
        </ul>
    </div>
</body>
</html>
