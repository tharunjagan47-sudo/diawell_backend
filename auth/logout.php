<?php
require_once __DIR__ . '/../config/helpers.php';

// Clear all session variables
$_SESSION = [];

// Destroy session cookie if set
if (ini_get("session.use_cookies") && !headers_sent()) {
    $params = session_get_cookie_params();
    @setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
if (session_id() !== "") {
    @session_destroy();
}

respond(true, "Logged out successfully.");
