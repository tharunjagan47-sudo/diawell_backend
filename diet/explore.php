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
            "glycemic_index" => $item["glycemic_index"],
            "icon" => $item["icon"],
            "health_benefits" => $item["health_benefits"]
        ];
    }

    respond(true, "Foods retrieved successfully.", 200, [
        "search_query" => $searchQuery,
        "category" => $category,
        "user_preference" => $userDietPref,
        "count" => count($results),
        "foods" => $results
    ]);

} catch (PDOException $e) {
    error_log("Food explore error: " . $e->getMessage());
    respond(false, "Database error exploring foods.", 500);
}
