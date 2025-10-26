<?php
// Quick checker for AuthTokens table. Prints count and recent rows or a helpful error if the table is missing.
require_once __DIR__ . '/../includes/config.php';

try {
    // Check if $conn exists
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection ($conn) is not available.');
    }

    // Get count
    $count = 0;
    $res = $conn->query("SELECT COUNT(*) as cnt FROM AuthTokens");
    if ($res) {
        $row = $res->fetch_assoc();
        $count = (int)$row['cnt'];
    }

    echo "AuthTokens table row count: $count\n";

    if ($count > 0) {
        $q = $conn->query("SELECT token_id, selector, expires, user_id, created_at FROM AuthTokens ORDER BY expires DESC LIMIT 20");
        if ($q) {
            echo "Recent tokens (up to 20):\n";
            while ($r = $q->fetch_assoc()) {
                echo sprintf("%s | selector=%s | user_id=%s | expires=%s | created_at=%s\n",
                    $r['token_id'], $r['selector'], $r['user_id'], $r['expires'], $r['created_at']);
            }
        }
    } else {
        echo "No rows found in AuthTokens.\n";
    }
} catch (mysqli_sql_exception $e) {
    // Likely table missing or permission issue
    echo "SQL Error: " . $e->getMessage() . "\n";
    echo "If the table is missing, run scripts/add_auth_tokens_table.php or create the table manually.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>