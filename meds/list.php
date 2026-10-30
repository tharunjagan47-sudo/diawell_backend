<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId();
if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

$rawDate = trim($_GET["date"] ?? date("Y-m-d"));
$parsed = strtotime($rawDate);
$selectedDate = ($parsed !== false) ? date("Y-m-d", $parsed) : date("Y-m-d");

try {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, name, dose, reminder_time, reminder_date, timing, frequency, status, notes,
                therapeutic_class, manufacturer, primary_ingredient, packaging, dosage_form, created_at
         FROM medications
         WHERE user_id = :user_id AND (reminder_date = :date OR frequency = 'Daily')
         ORDER BY reminder_time ASC"
    );
    $stmt->execute([":user_id" => $userId, ":date" => $selectedDate]);
    $meds = $stmt->fetchAll();

    $formattedMeds = [];
    $takenCount = 0;

    foreach ($meds as $row) {
        $status = ucfirst(strtolower($row["status"] ?? "Upcoming"));
        if ($status === "Taken") {
            $takenCount++;
            $statusColor = "#2E7D32";
            $statusBg = "#E8F5E9";
        } elseif ($status === "Missed") {
            $statusColor = "#C62828";
            $statusBg = "#FFEBEE";
        } else {
            $status = "Upcoming";
            $statusColor = "#B81D85";
            $statusBg = "#FDEEF7";
        }

        $formattedTime = date("g:i A", strtotime($row["reminder_time"]));

        $formattedMeds[] = [
            "id" => (int) $row["id"],
            "name" => $row["name"],
            "dose" => $row["dose"],
            "reminder_time" => $formattedTime,
            "raw_time" => $row["reminder_time"],
            "reminder_date" => $row["reminder_date"],
            "timing" => $row["timing"] ?? "After Meal",
            "frequency" => $row["frequency"] ?? "Daily",
            "status" => $status,
            "status_color" => $statusColor,
            "status_bg" => $statusBg,
            "notes" => $row["notes"] ?? "",
            "therapeutic_class" => $row["therapeutic_class"] ?? "",
            "category" => $row["therapeutic_class"] ?? "",
            "manufacturer" => $row["manufacturer"] ?? "",
            "primary_ingredient" => $row["primary_ingredient"] ?? "",
            "packaging" => $row["packaging"] ?? "",
            "dosage_form" => $row["dosage_form"] ?? "Tablet"
        ];
    }

    $totalCount = count($formattedMeds);
    $adherencePercent = $totalCount > 0 ? round(($takenCount / $totalCount) * 100) : 0;

    respond(true, "Medications retrieved successfully.", 200, [
        "user_id" => $userId,
        "date" => $selectedDate,
        "date_label" => date("D, j M Y", strtotime($selectedDate)),
        "stats" => [
            "total" => $totalCount,
            "taken" => $takenCount,
            "upcoming" => $totalCount - $takenCount,
            "adherence_percent" => $adherencePercent
        ],
        "medications" => $formattedMeds
    ]);

} catch (PDOException $e) {
    error_log("Medications list error: " . $e->getMessage());
    respond(false, "Database error retrieving medications.", 500);
}

