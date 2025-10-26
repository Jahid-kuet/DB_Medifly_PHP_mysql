<?php
require_once __DIR__ . '/../includes/config.php';

echo "Running migration: add is_approved column to Users if missing...\n";

$check = $conn->query("SHOW COLUMNS FROM Users LIKE 'is_approved'");
if ($check && $check->num_rows > 0) {
    echo "Column is_approved already exists.\n";
    exit(0);
}

$sql = "ALTER TABLE Users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0";
try {
    $conn->query($sql);
    // Approve existing admin accounts by default
    $conn->query("UPDATE Users SET is_approved = 1 WHERE role = 'admin'");
    echo "Migration completed. 'is_approved' added and admin users approved.\n";
} catch (mysqli_sql_exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

?>
