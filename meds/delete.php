<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

$medId = (int) ($_GET["medication_id"] ?? $_GET["id"] ?? $data["medication_id"] ?? $data["id"] ?? 0);

if ($medId <= 0) {
    respond(false, "Valid medication_id is required.", 400);
}

try {
    $stmt = $pdo->prepare("DELETE FROM medications WHERE id = :id AND user_id = :user_id");
    $stmt->execute([":id" => $medId, ":user_id" => $userId]);

    if ($stmt->rowCount() === 0) {
        respond(false, "Medication not found or unauthorized.", 404);
    }

    respond(true, "Medication deleted successfully.", 200, [
        "deleted_medication_id" => $medId
    ]);

} catch (PDOException $e) {
    error_log("Medication delete error: " . $e->getMessage());
    respond(false, "Database error deleting medication.", 500);
}

