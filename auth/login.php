
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        exit("Please enter email and password.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        exit("Invalid email address.");
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password_hash
             FROM users
             WHERE email = :email
             LIMIT 1"
        );

        $stmt->execute([
            ":email" => $email
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify(
            $password,
            $user["password_hash"]
        )) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["full_name"];

            header("Location: ../dashboard.php");
            exit;
        } else {
            exit("Invalid email or password.");
        }

    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500);
        exit("Login failed. Please try again.");
    }
}
?>
