<?php
require_once __DIR__ . '/../includes/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS AuthTokens (
        token_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(64) NOT NULL,
        validator_hash VARCHAR(255) NOT NULL,
        expires DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
        UNIQUE (selector)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->query($sql);
    echo "AuthTokens table ensured.\n";
} catch (Exception $e) {
    echo "Failed to create AuthTokens table: " . $e->getMessage() . "\n";
}
