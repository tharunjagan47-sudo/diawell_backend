<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

$medId = (int) ($data["medication_id"] ?? $data["id"] ?? 0);
$rawStatus = ucfirst(strtolower(trim($data["status"] ?? "Taken")));

$allowed = ["Taken", "Missed", "Upcoming"];
if (!in_array($rawStatus, $allowed, true)) {
    respond(false, "Invalid status. Allowed values: Taken, Missed, Upcoming.", 400);
}

if ($medId <= 0) {
    respond(false, "Valid medication_id is required.", 400);
}

try {
    $stmt = $pdo->prepare(
        "UPDATE medications 
         SET status = :status, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([
        ":status" => $rawStatus,
        ":id" => $medId,
        ":user_id" => $userId
    ]);

    if ($stmt->rowCount() === 0) {
        respond(false, "Medication not found or unauthorized.", 404);
    }

    respond(true, "Medication status updated to {$rawStatus}.", 200, [
        "medication_id" => $medId,
        "status" => $rawStatus,
        "updated_at" => date("Y-m-d H:i:s")
    ]);

} catch (PDOException $e) {
    error_log("Medication status update error: " . $e->getMessage());
    respond(false, "Database error updating medication status.", 500);
}

