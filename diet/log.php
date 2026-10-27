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
// DELETE: Remove a logged meal
// ==========================================
if ($method === "DELETE") {
    $logId = (int) ($_GET["log_id"] ?? $data["log_id"] ?? 0);
    if ($logId <= 0) {
        respond(false, "Valid log_id is required to delete meal.", 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM food_logs WHERE id = :id AND user_id = :user_id");
        $stmt->execute([":id" => $logId, ":user_id" => $userId]);

        if ($stmt->rowCount() === 0) {
            respond(false, "Logged meal not found or unauthorized.", 404);
        }

        respond(true, "Logged meal deleted successfully.", 200, [
            "deleted_log_id" => $logId
        ]);
    } catch (PDOException $e) {
        error_log("Food log delete error: " . $e->getMessage());
        respond(false, "Database error deleting food log.", 500);
    }
}

// ==========================================
// GET: Retrieve logged meals for a specific date
// ==========================================
if ($method === "GET") {
    $rawDate = trim($_GET["date"] ?? date("Y-m-d"));
    $parsedTime = strtotime($rawDate);
    $selectedDate = ($parsedTime !== false) ? date("Y-m-d", $parsedTime) : date("Y-m-d");

    try {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, meal_category, food_name, `portion`, calories, carbs_g, protein_g, fat_g, log_date, log_time, icon
             FROM food_logs
             WHERE user_id = :user_id AND log_date = :log_date
             ORDER BY FIELD(meal_category, 'BREAKFAST', 'LUNCH', 'SNACKS', 'DINNER'), log_time ASC"
        );
        $stmt->execute([
            ":user_id" => $userId,
            ":log_date" => $selectedDate
        ]);
        $meals = $stmt->fetchAll();

        // Calculate macro totals
        $totalCalories = 0;
        $totalCarbs = 0;
        $totalProtein = 0;
        $totalFat = 0;

        $formattedMeals = [];
        foreach ($meals as $m) {
            $totalCalories += (int) $m["calories"];
            $totalCarbs += (float) $m["carbs_g"];
            $totalProtein += (float) $m["protein_g"];
            $totalFat += (float) $m["fat_g"];

            $formattedMeals[] = [
                "id" => (int) $m["id"],
                "category" => strtoupper($m["meal_category"]),
                "time" => date("g:i A", strtotime($m["log_time"])),
                "raw_time" => $m["log_time"],
                "title" => $m["food_name"],
                "portion" => $m["portion"],
                "calories" => $m["calories"] . " kcal",
                "calories_num" => (int) $m["calories"],
                "carbs_g" => (float) $m["carbs_g"],
                "protein_g" => (float) $m["protein_g"],
                "fat_g" => (float) $m["fat_g"],
                "icon" => $m["icon"] ?? "🥣"
            ];
        }

        $dateHeader = date("D, j M Y", strtotime($selectedDate));

        respond(true, "Food log retrieved successfully.", 200, [
            "user_id" => $userId,
            "date" => $selectedDate,
            "date_label" => "📅  " . $dateHeader,
            "summary" => [
                "total_calories" => number_format($totalCalories) . " kcal",
                "total_calories_num" => $totalCalories,
                "carbs" => round($totalCarbs, 1) . " g",
                "protein" => round($totalProtein, 1) . " g",
                "fat" => round($totalFat, 1) . " g",
                "macros_label" => "Carbs " . round($totalCarbs) . " g • Protein " . round($totalProtein) . " g • Fat " . round($totalFat) . " g"
            ],
            "meals_count" => count($formattedMeals),
            "meals" => $formattedMeals
        ]);

    } catch (PDOException $e) {
        error_log("Food log fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving food log.", 500);
    }
}

// ==========================================
// POST: Log a new meal
// ==========================================
if ($method === "POST") {
    $category = strtoupper(trim($data["meal_category"] ?? $data["category"] ?? "BREAKFAST"));
    $food_name = trim($data["food_name"] ?? $data["title"] ?? "");
    $portion = trim($data["portion"] ?? "1 serving");
    $calories = (int) parseNumericValue($data["calories"] ?? 0);
    $carbs = parseNumericValue($data["carbs_g"] ?? $data["carbs"] ?? 0);
    $protein = parseNumericValue($data["protein_g"] ?? $data["protein"] ?? 0);
    $fat = parseNumericValue($data["fat_g"] ?? $data["fat"] ?? 0);
    $rawDate = trim($data["log_date"] ?? $data["date"] ?? date("Y-m-d"));
    $rawTime = trim($data["log_time"] ?? $data["time"] ?? date("H:i:s"));
    $icon = trim($data["icon"] ?? "🥣");

    if ($food_name === "") {
        respond(false, "Food name is required.", 400);
    }

    $parsedTime = strtotime($rawDate);
    $logDate = ($parsedTime !== false) ? date("Y-m-d", $parsedTime) : date("Y-m-d");

    $parsedHour = strtotime($rawTime);
    $logTime = ($parsedHour !== false) ? date("H:i:s", $parsedHour) : date("H:i:s");

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO food_logs (user_id, meal_category, food_name, `portion`, calories, carbs_g, protein_g, fat_g, log_date, log_time, icon)
             VALUES (:user_id, :category, :food_name, :portion, :calories, :carbs, :protein, :fat, :log_date, :log_time, :icon)"
        );

        $stmt->execute([
            ":user_id" => $userId,
            ":category" => $category,
            ":food_name" => $food_name,
            ":portion" => $portion,
            ":calories" => $calories,
            ":carbs" => (float) $carbs,
            ":protein" => (float) $protein,
            ":fat" => (float) $fat,
            ":log_date" => $logDate,
            ":log_time" => $logTime,
            ":icon" => $icon
        ]);

        $logId = (int) $pdo->lastInsertId();

        respond(true, "Meal logged successfully.", 201, [
            "log_id" => $logId,
            "user_id" => $userId,
            "category" => $category,
            "food_name" => $food_name,
            "portion" => $portion,
            "calories" => $calories . " kcal",
            "carbs_g" => (float) $carbs,
            "protein_g" => (float) $protein,
            "fat_g" => (float) $fat,
            "log_date" => $logDate,
            "log_time" => date("g:i A", strtotime($logTime)),
            "icon" => $icon
        ]);

    } catch (PDOException $e) {
        error_log("Food log insert error: " . $e->getMessage());
        respond(false, "Database error saving meal log.", 500);
    }
}

respond(false, "Method not allowed. Use GET, POST, or DELETE.", 405);
