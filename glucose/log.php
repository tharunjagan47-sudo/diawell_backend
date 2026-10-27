<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in first.", 401);
}

$rawGlucose = $data["reading_value"] ?? $data["glucose_value"] ?? $data["readingValue"] ?? null;
$glucose = parseNumericValue($rawGlucose);
$reading_type = trim($data["reading_type"] ?? $data["readingType"] ?? "Fasting");
$reading_date = trim($data["reading_date"] ?? $data["readingDate"] ?? date("Y-m-d"));
$reading_time = trim($data["reading_time"] ?? $data["readingTime"] ?? date("H:i:s"));
$notes = trim($data["notes"] ?? "");

// Validate glucose value
if ($glucose === null || $glucose < 40 || $glucose > 600) {
    respond(false, "Please enter a valid blood sugar reading between 40 and 600 mg/dL.", 400);
}

// Normalize reading type
$allowedTypes = ["Fasting", "After meal", "Before meal", "Bedtime", "Random"];
$matchedType = "Fasting";
foreach ($allowedTypes as $type) {
    if (strcasecmp($reading_type, $type) === 0) {
        $matchedType = $type;
        break;
    }
}

// Normalize date format if user passes e.g. "15 Oct 2026"
$parsedTime = strtotime($reading_date);
if ($parsedTime !== false) {
    $formattedDate = date("Y-m-d", $parsedTime);
} else {
    $formattedDate = date("Y-m-d");
}

// Normalize time format if user passes e.g. "7:30 AM"
$parsedHour = strtotime($reading_time);
if ($parsedHour !== false) {
    $formattedTime = date("H:i:s", $parsedHour);
} else {
    $formattedTime = date("H:i:s");
}

try {
    // 1. Verify user exists
    $check = $pdo->prepare("SELECT id, target_glucose_mg_dl FROM users WHERE id = :id LIMIT 1");
    $check->execute([":id" => $userId]);
    $user = $check->fetch();

    if (!$user) {
        respond(false, "User not found with ID: " . $userId, 404);
    }

    // 2. Insert glucose reading
    $stmt = $pdo->prepare(
        "INSERT INTO glucose_readings (user_id, glucose_value, reading_type, reading_date, reading_time, notes)
         VALUES (:user_id, :glucose_value, :reading_type, :reading_date, :reading_time, :notes)"
    );

    $stmt->execute([
        ":user_id" => $userId,
        ":glucose_value" => (float) $glucose,
        ":reading_type" => $matchedType,
        ":reading_date" => $formattedDate,
        ":reading_time" => $formattedTime,
        ":notes" => $notes
    ]);

    $readingId = (int) $pdo->lastInsertId();

    // 3. Update current glucose in users table to this latest reading
    $updateUser = $pdo->prepare("UPDATE users SET current_glucose_mg_dl = :glucose WHERE id = :id");
    $updateUser->execute([
        ":glucose" => (float) $glucose,
        ":id" => $userId
    ]);

    // 4. Optional notification if reading is high or low
    $target = (float) ($user["target_glucose_mg_dl"] ?? 120.0);
    if ($glucose > ($target + 40)) {
        $notif = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, body, notification_type) 
             VALUES (:user_id, :title, :body, 'glucose')"
        );
        $notif->execute([
            ":user_id" => $userId,
            ":title" => "Elevated Glucose Reading Recorded",
            ":body" => "Your recorded reading of {$glucose} mg/dL is above your target of {$target} mg/dL. Consider drinking water and reviewing your recent meals."
        ]);
    }

    respond(true, "Glucose reading saved successfully.", 201, [
        "reading_id" => $readingId,
        "user_id" => $userId,
        "reading_value" => (float) $glucose,
        "reading_type" => $matchedType,
        "reading_date" => $formattedDate,
        "reading_time" => date("g:i A", strtotime($formattedTime)),
        "notes" => $notes
    ]);

} catch (PDOException $e) {
    error_log("Glucose log error: " . $e->getMessage());
    respond(false, "Database error saving glucose reading. Please try again.", 500);
}

