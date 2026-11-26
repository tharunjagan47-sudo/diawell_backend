<?php
require_once __DIR__ . '/db_config.php';

$host = $diawellDbConfig["host"];
$port = $diawellDbConfig["port"];
$dbname = $diawellDbConfig["db_name"];
$username = $diawellDbConfig["username"];
$password = $diawellDbConfig["password"];
$charset = $diawellDbConfig["charset"];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
    }
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Database connection failed. Please ensure MySQL is running."
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
