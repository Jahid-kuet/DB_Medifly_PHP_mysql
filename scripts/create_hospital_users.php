<?php
// Bulk-create hospital user accounts for each Hospitals row.
// Usage (CLI):
//   php scripts/create_hospital_users.php
// Or run in browser (only for admins and local safety).

require_once __DIR__ . '/../includes/config.php';

function slugify($text) {
    $text = preg_replace('~[^\pL0-9]+~u', '_', $text);
    $text = trim($text, '_');
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = strtolower($text);
    $text = preg_replace('~[^-a-z0-9_]+~', '', $text);
    if (empty($text)) {
        return 'hospital';
    }
    return $text;
}

$defaultPassword = 'hospital123';
$created = [];
$skipped = [];

$result = $conn->query('SELECT hospital_id, name FROM Hospitals ORDER BY hospital_id');
if (!$result) {
    echo "No hospitals found or query error.\n";
    exit(1);
}

while ($row = $result->fetch_assoc()) {
    $hospitalId = (int) $row['hospital_id'];
    $name = $row['name'];
    $baseUsername = slugify($name);
    $username = $baseUsername;

    // ensure username uniqueness
    $i = 1;
    while (true) {
        $stmt = $conn->prepare('SELECT user_id FROM Users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->fetch_assoc()) {
            $username = $baseUsername . '_' . $i;
            $i++;
            continue;
        }
        break;
    }

    // create user
    $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $nameField = $name . ' Admin';
    $phone = '';
    $role = 'hospital';

    $stmt = $conn->prepare('INSERT INTO Users (name, username, password, role, phone, hospital_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssi', $nameField, $username, $passwordHash, $role, $phone, $hospitalId);

    try {
        $stmt->execute();
        $created[] = [
            'hospital_id' => $hospitalId,
            'hospital_name' => $name,
            'username' => $username,
            'password' => $defaultPassword
        ];
    } catch (mysqli_sql_exception $e) {
        // duplicate or error - skip
        $skipped[] = [
            'hospital_id' => $hospitalId,
            'hospital_name' => $name,
            'error' => $e->getMessage()
        ];
    }
}

// Output results
if (php_sapi_name() === 'cli') {
    echo "Created users:\n";
    foreach ($created as $c) {
        echo sprintf("Hospital %d - %s => username: %s password: %s\n", $c['hospital_id'], $c['hospital_name'], $c['username'], $c['password']);
    }
    if ($skipped) {
        echo "\nSkipped (already exist or error):\n";
        foreach ($skipped as $s) {
            echo sprintf("Hospital %d - %s => %s\n", $s['hospital_id'], $s['hospital_name'], $s['error']);
        }
    }
    echo "\nNOTE: Please change default passwords after creation.\n";
} else {
    echo "<h2>Created users</h2>\n<ul>\n";
    foreach ($created as $c) {
        echo '<li>' . htmlspecialchars($c['hospital_name']) . ' &rarr; <strong>' . htmlspecialchars($c['username']) . '</strong> (password: ' . htmlspecialchars($c['password']) . ')</li>\n';
    }
    echo "</ul>\n";
    if ($skipped) {
        echo "<h3>Skipped</h3><ul>\n";
        foreach ($skipped as $s) {
            echo '<li>' . htmlspecialchars($s['hospital_name']) . ' &rarr; ' . htmlspecialchars($s['error']) . '</li>\n';
        }
        echo "</ul>\n";
    }
    echo '<p><strong>NOTE:</strong> Default password is <code>' . htmlentities($defaultPassword) . '</code>. Force password reset after deployment.</p>';
}

?>