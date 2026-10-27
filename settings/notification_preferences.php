<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];
$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

// ==========================================
// GET: Retrieve user notification preferences
// ==========================================
if ($method === "GET") {
    try {
        $stmt = $pdo->prepare(
            "SELECT medication_reminders, meal_logging_reminders, glucose_checkins, weekly_report_alerts, updated_at
             FROM notification_preferences
             WHERE user_id = :id
             LIMIT 1"
        );
        $stmt->execute([":id" => $userId]);
        $pref = $stmt->fetch();

        if (!$pref) {
            respond(true, "Default notification preferences returned.", 200, [
                "user_id" => $userId,
                "medication_reminders" => true,
                "meal_logging_reminders" => true,
                "glucose_checkins" => true,
                "weekly_report_alerts" => true,
                "is_customized" => false
            ]);
        }

        respond(true, "Notification preferences retrieved successfully.", 200, [
            "user_id" => $userId,
            "medication_reminders" => (bool) $pref["medication_reminders"],
            "meal_logging_reminders" => (bool) $pref["meal_logging_reminders"],
            "glucose_checkins" => (bool) $pref["glucose_checkins"],
            "weekly_report_alerts" => (bool) $pref["weekly_report_alerts"],
            "updated_at" => $pref["updated_at"],
            "is_customized" => true
        ]);

    } catch (PDOException $e) {
        error_log("Notification preferences fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving notification preferences.", 500);
    }
}

// ==========================================
// POST: Save/Update notification preferences
// ==========================================
if ($method === "POST") {
    $medReminders = isset($data["medication_reminders"]) ? ((bool)$data["medication_reminders"] ? 1 : 0) : 1;
    $mealReminders = isset($data["meal_logging_reminders"]) ? ((bool)$data["meal_logging_reminders"] ? 1 : 0) : 1;
    $glucoseCheckins = isset($data["glucose_checkins"]) ? ((bool)$data["glucose_checkins"] ? 1 : 0) : 1;
    $weeklyAlerts = isset($data["weekly_report_alerts"]) ? ((bool)$data["weekly_report_alerts"] ? 1 : 0) : 1;

    try {
        $sql = "INSERT INTO notification_preferences (user_id, medication_reminders, meal_logging_reminders, glucose_checkins, weekly_report_alerts)
                VALUES (:user_id, :med, :meal, :gluc, :week)
                ON DUPLICATE KEY UPDATE
                    medication_reminders = VALUES(medication_reminders),
                    meal_logging_reminders = VALUES(meal_logging_reminders),
                    glucose_checkins = VALUES(glucose_checkins),
                    weekly_report_alerts = VALUES(weekly_report_alerts),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":user_id" => $userId,
            ":med" => $medReminders,
            ":meal" => $mealReminders,
            ":gluc" => $glucoseCheckins,
            ":week" => $weeklyAlerts
        ]);

        respond(true, "Notification preferences updated successfully.", 200, [
            "user_id" => $userId,
            "medication_reminders" => (bool) $medReminders,
            "meal_logging_reminders" => (bool) $mealReminders,
            "glucose_checkins" => (bool) $glucoseCheckins,
            "weekly_report_alerts" => (bool) $weeklyAlerts
        ]);

    } catch (PDOException $e) {
        error_log("Notification preferences save error: " . $e->getMessage());
        respond(false, "Database error saving notification preferences.", 500);
    }
}

respond(false, "Method not allowed. Use GET or POST.", 405);

