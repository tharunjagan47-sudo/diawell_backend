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
// DELETE: Delete a glucose reading
// ==========================================
if ($method === "DELETE") {
    $readingId = (int) ($_GET["reading_id"] ?? $data["reading_id"] ?? 0);

    if ($readingId <= 0) {
        respond(false, "Valid reading_id is required to delete.", 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM glucose_readings WHERE id = :id AND user_id = :user_id");
        $stmt->execute([":id" => $readingId, ":user_id" => $userId]);

        if ($stmt->rowCount() === 0) {
            respond(false, "Reading not found or does not belong to user.", 404);
        }

        respond(true, "Glucose reading deleted successfully.", 200, [
            "deleted_reading_id" => $readingId
        ]);

    } catch (PDOException $e) {
        error_log("Glucose delete error: " . $e->getMessage());
        respond(false, "Database error deleting reading.", 500);
    }
}

// ==========================================
// GET: Detailed look at reading with trend summary
// ==========================================
if ($method === "GET") {
    $readingId = (int) ($_GET["reading_id"] ?? 0);

    try {
        if ($readingId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id, user_id, glucose_value, reading_type, reading_date, reading_time, notes, created_at
                 FROM glucose_readings
                 WHERE id = :id AND user_id = :user_id
                 LIMIT 1"
            );
            $stmt->execute([":id" => $readingId, ":user_id" => $userId]);
            $reading = $stmt->fetch();
        } else {
            // Get latest reading
            $stmt = $pdo->prepare(
                "SELECT id, user_id, glucose_value, reading_type, reading_date, reading_time, notes, created_at
                 FROM glucose_readings
                 WHERE user_id = :user_id
                 ORDER BY reading_date DESC, reading_time DESC
                 LIMIT 1"
            );
            $stmt->execute([":user_id" => $userId]);
            $reading = $stmt->fetch();
        }

        if (!$reading) {
            respond(false, "No glucose readings found.", 404);
        }

        $currentVal = (float) $reading["glucose_value"];
        $type = $reading["reading_type"];

        // Get recent readings of the same type (or overall) for trend graph
        $recentStmt = $pdo->prepare(
            "SELECT id, glucose_value, reading_date, reading_time
             FROM glucose_readings
             WHERE user_id = :user_id AND reading_type = :type AND id <= :id
             ORDER BY reading_date DESC, reading_time DESC
             LIMIT 3"
        );
        $recentStmt->execute([
            ":user_id" => $userId,
            ":type" => $type,
            ":id" => $reading["id"]
        ]);
        $recent = $recentStmt->fetchAll();

        // Calculate comparison summary
        $trendSummary = "Single reading logged for this category.";
        if (count($recent) >= 2) {
            $prevVal = (float) $recent[1]["glucose_value"];
            $diff = abs(round($currentVal - $prevVal, 1));
            if ($currentVal < $prevVal) {
                $trendSummary = "Your recent readings were " . implode(", ", array_map(function($r){ return round((float)$r["glucose_value"]); }, array_reverse($recent))) . " mg/dL. This {$currentVal} mg/dL is {$diff} mg/dL below the previous entry of {$prevVal} mg/dL.";
            } elseif ($currentVal > $prevVal) {
                $trendSummary = "Your recent readings were " . implode(", ", array_map(function($r){ return round((float)$r["glucose_value"]); }, array_reverse($recent))) . " mg/dL. This {$currentVal} mg/dL is {$diff} mg/dL higher than the previous entry of {$prevVal} mg/dL.";
            } else {
                $trendSummary = "This {$currentVal} mg/dL is stable and matches your previous reading.";
            }
        }

        $formattedDate = date("j M Y", strtotime($reading["reading_date"]));
        $formattedTime = date("g:i A", strtotime($reading["reading_time"]));

        respond(true, "Reading details retrieved successfully.", 200, [
            "id" => (int) $reading["id"],
            "user_id" => (int) $reading["user_id"],
            "reading_type" => $reading["reading_type"],
            "reading_value" => (string) round($currentVal),
            "reading_value_num" => $currentVal,
            "unit" => "mg/dL",
            "date_time_label" => "{$formattedDate} at {$formattedTime}",
            "reading_date" => $reading["reading_date"],
            "reading_time" => $reading["reading_time"],
            "notes" => $reading["notes"] ?? "No notes recorded.",
            "recent_readings" => array_map(function($r){
                return [
                    "id" => (int) $r["id"],
                    "value" => (float) $r["glucose_value"],
                    "date" => $r["reading_date"],
                    "time" => date("g:i A", strtotime($r["reading_time"]))
                ];
            }, array_reverse($recent)),
            "ai_trend_summary" => $trendSummary
        ]);

    } catch (PDOException $e) {
        error_log("Glucose details error: " . $e->getMessage());
        respond(false, "Database error retrieving reading details.", 500);
    }
}

respond(false, "Method not allowed. Use GET or DELETE.", 405);

