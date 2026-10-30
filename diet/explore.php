<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId();
$searchQuery = trim($_GET["query"] ?? $_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "All");
$dietType = trim($_GET["diet_type"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$limit = min(100, max(1, (int) ($_GET["limit"] ?? 30)));
$sortBy = trim($_GET["sort_by"] ?? "name");
$sortOrder = trim($_GET["sort_order"] ?? "asc");

$pythonServiceUrl = getenv("AI_SERVICE_URL") ?: "http://127.0.0.1:8001";
$csvPath = __DIR__ . '/../data/Indian_Food_Nutrition_Processed.csv';

try {
    // Check user's preferred diet if logged in
    $userDietPref = "Non-Vegetarian";
    if ($userId) {
        $pStmt = $pdo->prepare("SELECT preferred_diet FROM food_preferences WHERE user_id = :id LIMIT 1");
        $pStmt->execute([":id" => $userId]);
        $pref = $pStmt->fetch();
        if ($pref && !empty($pref["preferred_diet"])) {
            $userDietPref = $pref["preferred_diet"];
        }
    }

    // 1. Try querying the Python AI service dataset first
    $queryParams = [
        "page" => $page,
        "limit" => $limit,
        "sort_by" => $sortBy,
        "sort_order" => $sortOrder
    ];
    if ($searchQuery !== "") {
        $queryParams["query"] = $searchQuery;
    }
    if ($category !== "" && strcasecmp($category, "All") !== 0) {
        $queryParams["category"] = $category;
    }
    if ($dietType !== "") {
        $queryParams["diet_type"] = $dietType;
    }

    $ch = curl_init($pythonServiceUrl . "/search?" . http_build_query($queryParams));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody !== false && $httpCode === 200) {
        $parsed = json_decode($responseBody, true);
        if ($parsed && isset($parsed["success"]) && $parsed["success"] && isset($parsed["data"])) {
            $data = $parsed["data"];
            $items = $data["items"] ?? [];

            $formattedFoods = [];
            foreach ($items as $item) {
                $isPreferred = (strcasecmp($item["diet_type"] ?? "Vegetarian", $userDietPref) === 0);
                $carbs = (float) ($item["carbs_g"] ?? 0.0);
                $fiber = (float) ($item["fibre_g"] ?? 0.0);
                $sugar = (float) ($item["free_sugar_g"] ?? 0.0);

                // Estimate glycemic index from dataset metrics (net carbs & sugar)
                $netCarbs = max(0.0, $carbs - $fiber);
                $gi = "Low";
                if ($netCarbs > 30.0 || $sugar > 6.0) {
                    $gi = "High";
                } elseif ($netCarbs > 15.0 || $sugar > 2.0) {
                    $gi = "Medium";
                }

                $formattedFoods[] = [
                    "id" => (int) $item["id"],
                    "title" => $item["title"],
                    "category" => $item["category"] ?? "Lunch/Dinner",
                    "diet_type" => $item["diet_type"] ?? "Vegetarian",
                    "is_user_preference" => $isPreferred,
                    "preference_tag" => $isPreferred ? "{$item["diet_type"]} • your preference" : ($item["diet_type"] ?? "Vegetarian"),
                    "portion" => $item["portion"] ?? "100 g portion basis",
                    "calories" => ($item["calories"] !== null ? round($item["calories"]) . " kcal" : "Unknown"),
                    "calories_num" => (int) ($item["calories"] ?? 0),
                    "carbs_g" => $carbs,
                    "protein_g" => (float) ($item["protein_g"] ?? 0.0),
                    "fat_g" => (float) ($item["fat_g"] ?? 0.0),
                    "fiber_g" => $fiber,
                    "free_sugar_g" => $sugar,
                    "sodium_mg" => (float) ($item["sodium_mg"] ?? 0.0),
                    "glycemic_index" => $gi,
                    "icon" => $item["icon"] ?? "🥗",
                    "health_benefits" => "Dataset verified Indian cuisine: {$carbs}g carbs, {$fiber}g fibre, {$sugar}g sugar."
                ];
            }

            respond(true, "Foods retrieved successfully from nutrition dataset.", 200, [
                "source" => "python_dataset",
                "search_query" => $searchQuery,
                "category" => $category,
                "user_preference" => $userDietPref,
                "count" => count($formattedFoods),
                "total_items" => $data["total"] ?? count($formattedFoods),
                "page" => $data["page"] ?? $page,
                "limit" => $data["limit"] ?? $limit,
                "total_pages" => $data["total_pages"] ?? 1,
                "foods" => $formattedFoods
            ]);
        }
    }

    // 2. Resilient CSV Fallback for Explore Food
    if (file_exists($csvPath)) {
        $handle = fopen($csvPath, "r");
        if ($handle !== false) {
            $headers = fgetcsv($handle);
            $colMap = [];
            foreach ($headers as $idx => $h) {
                $clean = trim($h);
                if (stripos($clean, "Dish") !== false) $colMap["dish"] = $idx;
                elseif (stripos($clean, "Calorie") !== false) $colMap["calories"] = $idx;
                elseif (stripos($clean, "Carb") !== false) $colMap["carbs"] = $idx;
                elseif (stripos($clean, "Protein") !== false) $colMap["protein"] = $idx;
                elseif (stripos($clean, "Fat") !== false && stripos($clean, "Free") === false) $colMap["fat"] = $idx;
                elseif (stripos($clean, "Free Sugar") !== false || stripos($clean, "Sugar") !== false) $colMap["sugar"] = $idx;
                elseif (stripos($clean, "Fibre") !== false || stripos($clean, "Fiber") !== false) $colMap["fibre"] = $idx;
                elseif (stripos($clean, "Sodium") !== false) $colMap["sodium"] = $idx;
            }

            $nonVegWords = ["chicken", "mutton", "fish", "egg", "prawn", "keema", "meat", "pork", "beef", "shrimp", "seafood"];
            $matchedDishes = [];
            $itemId = 1001;

            while (($row = fgetcsv($handle)) !== false) {
                $name = trim($row[$colMap["dish"] ?? 0] ?? "");
                if ($name === "") continue;

                $nameLower = strtolower($name);
                $isNonVeg = false;
                foreach ($nonVegWords as $w) {
                    if (strpos($nameLower, $w) !== false) {
                        $isNonVeg = true;
                        break;
                    }
                }
                $dishDietType = $isNonVeg ? "Non-Vegetarian" : "Vegetarian";

                // Filter by search query
                if ($searchQuery !== "" && stripos($name, $searchQuery) === false) {
                    $itemId++;
                    continue;
                }

                // Filter by diet type
                if ($dietType !== "") {
                    if (stripos($dietType, "non") === false && stripos($dietType, "veg") !== false && $dishDietType === "Non-Vegetarian") {
                        $itemId++;
                        continue;
                    }
                }

                $cal = isset($colMap["calories"]) && is_numeric($row[$colMap["calories"]]) ? round((float)$row[$colMap["calories"]]) : 150;
                $carbs = isset($colMap["carbs"]) && is_numeric($row[$colMap["carbs"]]) ? round((float)$row[$colMap["carbs"]], 1) : 15.0;
                $protein = isset($colMap["protein"]) && is_numeric($row[$colMap["protein"]]) ? round((float)$row[$colMap["protein"]], 1) : 5.0;
                $fat = isset($colMap["fat"]) && is_numeric($row[$colMap["fat"]]) ? round((float)$row[$colMap["fat"]], 1) : 4.0;
                $fiber = isset($colMap["fibre"]) && is_numeric($row[$colMap["fibre"]]) ? round((float)$row[$colMap["fibre"]], 1) : 2.0;
                $sugar = isset($colMap["sugar"]) && is_numeric($row[$colMap["sugar"]]) ? round((float)$row[$colMap["sugar"]], 1) : 0.0;
                $sodium = isset($colMap["sodium"]) && is_numeric($row[$colMap["sodium"]]) ? round((float)$row[$colMap["sodium"]], 1) : 120.0;

                $netCarbs = max(0.0, $carbs - $fiber);
                $gi = ($netCarbs > 30.0 || $sugar > 6.0) ? "High" : (($netCarbs > 15.0 || $sugar > 2.0) ? "Medium" : "Low");
                $isPreferred = (strcasecmp($dishDietType, $userDietPref) === 0);

                $matchedDishes[] = [
                    "id" => $itemId,
                    "title" => $name,
                    "category" => "Lunch/Dinner",
                    "diet_type" => $dishDietType,
                    "is_user_preference" => $isPreferred,
                    "preference_tag" => $isPreferred ? "{$dishDietType} • your preference" : $dishDietType,
                    "portion" => "100 g portion basis",
                    "calories" => "{$cal} kcal",
                    "calories_num" => (int) $cal,
                    "carbs_g" => $carbs,
                    "protein_g" => $protein,
                    "fat_g" => $fat,
                    "fiber_g" => $fiber,
                    "free_sugar_g" => $sugar,
                    "sodium_mg" => $sodium,
                    "glycemic_index" => $gi,
                    "icon" => $isNonVeg ? "🍗" : "🥗",
                    "health_benefits" => "Dataset verified Indian cuisine: {$carbs}g carbs, {$fiber}g fibre."
                ];
                $itemId++;
            }
            fclose($handle);

            $total = count($matchedDishes);
            $start = ($page - 1) * $limit;
            $slice = array_slice($matchedDishes, $start, $limit);

            respond(true, "Foods retrieved successfully from nutrition dataset (CSV direct).", 200, [
                "source" => "csv_dataset",
                "search_query" => $searchQuery,
                "category" => $category,
                "user_preference" => $userDietPref,
                "count" => count($slice),
                "total_items" => $total,
                "page" => $page,
                "limit" => $limit,
                "total_pages" => ceil($total / $limit) ?: 1,
                "foods" => $slice
            ]);
        }
    }

    // 3. Fallback to MySQL food_items table if CSV is unavailable
    $sql = "SELECT id, name, category, diet_type, `portion`, calories, carbs_g, protein_g, fat_g, fiber_g, glycemic_index, icon, health_benefits
            FROM food_items
            WHERE 1=1";
    $params = [];

    if ($searchQuery !== "") {
        $sql .= " AND (name LIKE :q OR ingredients LIKE :q OR health_benefits LIKE :q)";
        $params[":q"] = "%" . $searchQuery . "%";
    }

    if ($category !== "" && strcasecmp($category, "All") !== 0) {
        $sql .= " AND category = :cat";
        $params[":cat"] = $category;
    }

    if ($dietType !== "") {
        $sql .= " AND diet_type = :diet";
        $params[":diet"] = $dietType;
    }

    $sql .= " ORDER BY CASE WHEN diet_type = :user_pref THEN 0 ELSE 1 END, name ASC";
    $params[":user_pref"] = $userDietPref;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $results = [];
    foreach ($items as $item) {
        $isPreferred = (strcasecmp($item["diet_type"], $userDietPref) === 0);
        $results[] = [
            "id" => (int) $item["id"],
            "title" => $item["name"],
            "category" => $item["category"],
            "diet_type" => $item["diet_type"],
            "is_user_preference" => $isPreferred,
            "preference_tag" => $isPreferred ? "{$item["diet_type"]} • your preference" : $item["diet_type"],
            "portion" => $item["portion"],
            "calories" => $item["calories"] . " kcal",
            "calories_num" => (int) $item["calories"],
            "carbs_g" => (float) $item["carbs_g"],
            "protein_g" => (float) $item["protein_g"],
            "fat_g" => (float) $item["fat_g"],
            "fiber_g" => (float) $item["fiber_g"],
            "free_sugar_g" => 0.0,
            "sodium_mg" => 150.0,
            "glycemic_index" => $item["glycemic_index"],
            "icon" => $item["icon"],
            "health_benefits" => $item["health_benefits"]
        ];
    }

    respond(true, "Foods retrieved successfully (database fallback).", 200, [
        "source" => "database_fallback",
        "search_query" => $searchQuery,
        "category" => $category,
        "user_preference" => $userDietPref,
        "count" => count($results),
        "total_items" => count($results),
        "page" => 1,
        "limit" => count($results),
        "total_pages" => 1,
        "foods" => $results
    ]);

} catch (PDOException $e) {
    error_log("Food explore error: " . $e->getMessage());
    respond(false, "Database error exploring foods.", 500);
}
