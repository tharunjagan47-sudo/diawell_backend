<?php
/**
 * Centralized Database Configuration for DiaWell Backend.
 * Supports environment variables for cloud/container hosting with local XAMPP fallbacks.
 */
$diawellDbConfig = [
    "host" => getenv("DB_HOST") ?: ($_ENV["DB_HOST"] ?? "localhost"),
    "port" => (int) (getenv("DB_PORT") ?: ($_ENV["DB_PORT"] ?? 3306)),
    "db_name" => getenv("DB_NAME") ?: ($_ENV["DB_NAME"] ?? "diawell"),
    "username" => getenv("DB_USER") ?: ($_ENV["DB_USER"] ?? "root"),
    "password" => getenv("DB_PASS") !== false ? getenv("DB_PASS") : ($_ENV["DB_PASS"] ?? ""),
    "charset" => getenv("DB_CHARSET") ?: ($_ENV["DB_CHARSET"] ?? "utf8mb4"),
];
