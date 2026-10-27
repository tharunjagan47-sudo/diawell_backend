<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$foodId = (int) ($_GET["food_id"] ?? $_GET["id"] ?? 0);
$category = trim($_GET["category"] ?? $_GET["meal_category"] ?? "");

try {
    if ($foodId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $foodId]);
        $food = $stmt->fetch();
    } elseif ($category !== "") {
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE category LIKE :cat LIMIT 1");
        $stmt->execute([":cat" => "%" . $category . "%"]);
        $food = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT * FROM food_items ORDER BY id ASC LIMIT 1");
        $food = $stmt->fetch();
    }

    if (!$food) {
        respond(false, "Food item not found.", 404);
    }

    $carbs = (float) $food["carbs_g"];
    $protein = (float) $food["protein_g"];
    $fat = (float) $food["fat_g"];
    $fiber = (float) $food["fiber_g"];
    $calories = (int) $food["calories"];
    $gi = $food["glycemic_index"] ?? "Low";

    // Dynamic Diabetic Impact Note
    if ($gi === "Low") {
        $glycemicColor = "#2E7D32";
        $glycemicBg = "#E8F5E9";
        $glycemicImpact = "Low Glycemic Index: Causes a slow and gradual rise in blood sugar, promoting long-term glycemic stability.";
    } elseif ($gi === "Medium") {
        $glycemicColor = "#E65100";
        $glycemicBg = "#FFF3E0";
        $glycemicImpact = "Moderate Glycemic Index: Balanced portion recommended; consume with extra dietary fiber to reduce glucose spike.";
    } else {
        $glycemicColor = "#C62828";
        $glycemicBg = "#FFEBEE";
        $glycemicImpact = "Higher Glycemic Load: Monitor post-prandial blood sugar 2 hours after intake.";
    }

    respond(true, "Food details retrieved successfully.", 200, [
        "id" => (int) $food["id"],
        "title" => $food["name"],
        "category" => $food["category"],
        "diet_type" => $food["diet_type"],
        "portion" => $food["portion"],
        "calories" => $calories,
        "calories_label" => "{$calories} kcal",
        "macros" => [
            "carbs_g" => $carbs,
            "protein_g" => $protein,
            "fat_g" => $fat,
            "fiber_g" => $fiber,
            "summary" => "Carbs {$carbs}g • Protein {$protein}g • Fat {$fat}g • Fiber {$fiber}g"
        ],
        "glycemic_index" => $gi,
        "glycemic_details" => [
            "badge" => "{$gi} Glycemic Impact",
            "color" => $glycemicColor,
            "bg_color" => $glycemicBg,
            "description" => $glycemicImpact
        ],
        "icon" => $food["icon"] ?? "🥣",
        "ingredients" => $food["ingredients"] ? array_map('trim', explode(',', $food["ingredients"])) : [],
        "health_benefits" => $food["health_benefits"] ?? "Rich in essential macronutrients tailored for diabetes management."
    ]);

} catch (PDOException $e) {
    error_log("Food details error: " . $e->getMessage());
    respond(false, "Database error retrieving food details.", 500);
}

