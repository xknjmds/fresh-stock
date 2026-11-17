<?php
/**
 * db_connect.php
 * ---------------------------------------------------------------
 * Creates the global $conn (mysqli) connection.
 *
 * Automatically picks LOCAL (XAMPP) credentials when running on
 * localhost, otherwise uses the LIVE (InfinityFree) credentials.
 * Update the LIVE block with the values from:
 *   InfinityFree Control Panel -> MySQL Databases
 * ---------------------------------------------------------------
 */

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = in_array(explode(':', $host)[0], ['localhost', '127.0.0.1', '::1'], true);

if ($isLocal) {
    // ---------- LOCAL (XAMPP) ----------
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'grocery_stock');
} else {
    // ---------- LIVE (InfinityFree) ----------
    define('DB_HOST', 'sqlXXX.infinityfree.com'); // MySQL Hostname
    define('DB_USER', 'if0_XXXXXXXX');            // MySQL Username
    define('DB_PASS', 'your_password_here');      // MySQL Password (vPanel password)
    define('DB_NAME', 'if0_XXXXXXXX_grocery');    // MySQL Database Name
}

// Throw exceptions on mysqli errors so we can catch them cleanly.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    // Do not leak credentials/details on the live server.
    $detail = $isLocal ? htmlspecialchars($e->getMessage()) : 'Please check config/db_connect.php.';
    exit('<div style="font-family:sans-serif;padding:40px;text-align:center">'
        . '<h2>Database connection failed</h2><p>' . $detail . '</p></div>');
}
