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

$name = trim($data["name"] ?? $data["medication_name"] ?? $data["medicationName"] ?? "");
$dose = trim($data["dose"] ?? $data["medication_dose"] ?? $data["medicationDose"] ?? "500 mg • 1 Tablet");
$rawTime = trim($data["reminder_time"] ?? $data["selectedTime"] ?? "08:00:00");
$rawDate = trim($data["reminder_date"] ?? $data["reminderDateText"] ?? date("Y-m-d"));
$timing = trim($data["timing"] ?? $data["selectedTiming"] ?? "After Meal");
$frequency = trim($data["frequency"] ?? "Daily");
$notes = trim($data["notes"] ?? "");
$therapeuticClass = trim($data["therapeutic_class"] ?? $data["category"] ?? "");
$manufacturer = trim($data["manufacturer"] ?? "");
$primaryIngredient = trim($data["primary_ingredient"] ?? "");
$packaging = trim($data["packaging"] ?? "");
$dosageForm = trim($data["dosage_form"] ?? "Tablet");

if ($name === "") {
    respond(false, "Medication name is required.", 400);
}

// Normalize date
$parsedDate = strtotime($rawDate);
$reminderDate = ($parsedDate !== false) ? date("Y-m-d", $parsedDate) : date("Y-m-d");

// Normalize time
$parsedTime = strtotime($rawTime);
$reminderTime = ($parsedTime !== false) ? date("H:i:s", $parsedTime) : "08:00:00";

try {
    // 1. Verify user exists
    $check = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $check->execute([":id" => $userId]);
    if (!$check->fetch()) {
        respond(false, "User not found with ID: " . $userId, 404);
    }

    // 2. Insert medication reminder with enriched dataset attributes
    $stmt = $pdo->prepare(
        "INSERT INTO medications (user_id, name, dose, reminder_time, reminder_date, timing, frequency, status, notes, therapeutic_class, manufacturer, primary_ingredient, packaging, dosage_form)
         VALUES (:user_id, :name, :dose, :reminder_time, :reminder_date, :timing, :frequency, 'Upcoming', :notes, :therapeutic_class, :manufacturer, :primary_ingredient, :packaging, :dosage_form)"
    );

    $stmt->execute([
        ":user_id" => $userId,
        ":name" => $name,
        ":dose" => $dose,
        ":reminder_time" => $reminderTime,
        ":reminder_date" => $reminderDate,
        ":timing" => $timing,
        ":frequency" => $frequency,
        ":notes" => $notes,
        ":therapeutic_class" => $therapeuticClass,
        ":manufacturer" => $manufacturer,
        ":primary_ingredient" => $primaryIngredient,
        ":packaging" => $packaging,
        ":dosage_form" => $dosageForm
    ]);

    $medId = (int) $pdo->lastInsertId();

    // 3. Create reminder notification
    $timeLabel = date("g:i A", strtotime($reminderTime));
    $notif = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, body, notification_type) 
         VALUES (:user_id, :title, :body, 'medication')"
    );
    $notif->execute([
        ":user_id" => $userId,
        ":title" => "Medication Reminder Set",
        ":body" => "Reminder active for {$name} ({$dose}) scheduled at {$timeLabel} ({$timing})."
    ]);

    respond(true, "Medication reminder set successfully.", 201, [
        "id" => $medId,
        "user_id" => $userId,
        "name" => $name,
        "dose" => $dose,
        "reminder_time" => $timeLabel,
        "raw_time" => $reminderTime,
        "reminder_date" => $reminderDate,
        "timing" => $timing,
        "frequency" => $frequency,
        "status" => "Upcoming",
        "notes" => $notes,
        "therapeutic_class" => $therapeuticClass,
        "manufacturer" => $manufacturer,
        "primary_ingredient" => $primaryIngredient,
        "packaging" => $packaging,
        "dosage_form" => $dosageForm
    ]);

} catch (PDOException $e) {
    error_log("Medication add error: " . $e->getMessage());
    respond(false, "Database error setting medication reminder.", 500);
}

