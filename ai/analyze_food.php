<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
if (empty($rawInput)) {
    $rawInput = @file_get_contents("php://stdin");
}
$data = json_decode($rawInput, true) ?: [];

// Extract base64 image from payload or multipart files
$imageBase64 = trim($data["image_base64"] ?? $data["image"] ?? $_POST["image_base64"] ?? $_POST["image"] ?? "");

if (empty($imageBase64) && !empty($_FILES["image"]["tmp_name"]) && file_exists($_FILES["image"]["tmp_name"])) {
    $imgBytes = file_get_contents($_FILES["image"]["tmp_name"]);
    if (!empty($imgBytes)) {
        $imageBase64 = base64_encode($imgBytes);
    }
}

$mealType = trim($data["meal_type"] ?? $_POST["meal_type"] ?? "Lunch");

// Clean base64 prefix if present (e.g., "data:image/jpeg;base64,...")
if (preg_match('/^data:image\/\w+;base64,/i', $imageBase64)) {
    $imageBase64 = preg_replace('/^data:image\/\w+;base64,/i', '', $imageBase64);
}
$imageBase64 = str_replace([' ', "\r", "\n"], ['+', '', ''], $imageBase64);

if (empty($imageBase64)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Image data is required (image_base64)."
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper to query Ollama
function queryOllama(string $endpoint, array $payload, int $timeout = 90): ?array {
    $ch = curl_init("http://127.0.0.1:11434" . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }
    return json_decode($response, true);
}

// 1. Check installed models on Ollama
$installedModels = [];
$tagsCh = curl_init("http://127.0.0.1:11434/api/tags");
curl_setopt_array($tagsCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_CONNECTTIMEOUT => 2
]);
$tagsResp = curl_exec($tagsCh);
curl_close($tagsCh);

if ($tagsResp !== false) {
    $tagsJson = json_decode($tagsResp, true);
    if (!empty($tagsJson["models"]) && is_array($tagsJson["models"])) {
        foreach ($tagsJson["models"] as $m) {
            $installedModels[] = strtolower($m["name"] ?? "");
        }
    }
}

// Model selection: qwen2.5vl:3b as primary vision model
$visionModel = "qwen2.5vl:3b";
$hasQwenVl = false;
foreach ($installedModels as $m) {
    if (strpos($m, "qwen2.5vl") !== false || strpos($m, "qwen2.5-vl") !== false) {
        $visionModel = $m;
        $hasQwenVl = true;
        break;
    }
}
if (!$hasQwenVl) {
    // If qwen2.5vl is still downloading, fallback to installed vision models
    foreach ($installedModels as $m) {
        if (strpos($m, "moondream") !== false || strpos($m, "llava") !== false) {
            $visionModel = $m;
            break;
        }
    }
}

// Model for Step 2 text nutrition calculation: qwen2.5:3b
$textModel = "qwen2.5:3b";
$hasQwenText = false;
foreach ($installedModels as $m) {
    if (strpos($m, "qwen2.5:3b") !== false) {
        $textModel = $m;
        $hasQwenText = true;
        break;
    }
}
if (!$hasQwenText) {
    $textModel = $visionModel;
}

// -----------------------------------------------------------------------------
// STEP 1: Pure Visual Food Identification (No JSON distraction)
// -----------------------------------------------------------------------------
$step1Prompt = "Look very carefully at this food photo. Identify every specific food item visible on the plate or bowl (recognize Indian dishes like idli, dosa, pongal, poori, chapati, biryani, sambar, chutney, rice, curry, vada, parotta as well as fruits, snacks, and international dishes) and estimate the visible portion count/size. Reply in 1-2 plain sentences naming only the foods you actually see.";

$step1Payload = [
    "model" => $visionModel,
    "prompt" => $step1Prompt,
    "images" => [$imageBase64],
    "temperature" => 0.1,
    "stream" => false
];

$step1Response = queryOllama("/api/generate", $step1Payload, 90);
$step1Description = trim($step1Response["response"] ?? "");

if (empty($step1Description)) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Vision model ($visionModel) failed to identify foods in the image."
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// STEP 2: Dish-Specific Nutrition Calculation (Zero Constant Numbers)
// -----------------------------------------------------------------------------
$step2Prompt = "Calculate realistic nutrition values specifically for this meal: \"" . addslashes($step1Description) . "\".\n" .
"Estimate accurate total calories, protein_g, carbs_g, fat_g, fiber_g, a diabetes-friendly health_score (1 to 10), a 1-line health_reason, and an items array listing each visible food item with its individual estimated calories. Ensure the sum of item calories approximately equals total calories.\n" .
"Return ONLY valid JSON with keys: dish_name (string), calories (integer), protein_g (integer), carbs_g (integer), fat_g (integer), fiber_g (integer), health_score (integer 1-10), health_reason (string), items (array of {name: string, calories: integer}).";

$step2Payload = [
    "model" => $textModel,
    "prompt" => $step2Prompt,
    "format" => "json",
    "temperature" => 0.2,
    "stream" => false
];

$step2Response = queryOllama("/api/generate", $step2Payload, 60);
$step2RawText = trim($step2Response["response"] ?? "");

$cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $step2RawText);
$cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
$cleanJson = trim($cleanJson);

$parsedNutrition = json_decode($cleanJson, true);
if (!is_array($parsedNutrition) || empty($parsedNutrition["dish_name"])) {
    if (preg_match('/\{[\s\S]*\}/', $step2RawText, $matches)) {
        $parsedNutrition = json_decode($matches[0], true);
    }
}

if (!is_array($parsedNutrition) || empty($parsedNutrition["dish_name"])) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Nutrition calculation failed to generate structured JSON.",
        "visual_description" => $step1Description
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Extract exact values from AI - ZERO CONSTANT / HARDCODED NUMBERS
$dishName = trim($parsedNutrition["dish_name"]);
$calories = intval($parsedNutrition["calories"] ?? 0);
$protein = intval($parsedNutrition["protein_g"] ?? 0);
$carbs = intval($parsedNutrition["carbs_g"] ?? 0);
$fat = intval($parsedNutrition["fat_g"] ?? 0);
$fiber = intval($parsedNutrition["fiber_g"] ?? 0);
$healthScore = intval($parsedNutrition["health_score"] ?? 7);
if ($healthScore < 1) $healthScore = 1;
if ($healthScore > 10) $healthScore = 10;
$healthReason = trim($parsedNutrition["health_reason"] ?? "$dishName nutrition calculated for glycemic control.");

$subItems = [];
if (!empty($parsedNutrition["items"]) && is_array($parsedNutrition["items"])) {
    foreach ($parsedNutrition["items"] as $it) {
        if (!is_array($it)) continue;
        $itName = trim($it["name"] ?? "");
        if ($itName === "") continue;
        $subItems[] = [
            "name" => $itName,
            "portion" => trim($it["portion"] ?? "1 serving"),
            "calories" => intval($it["calories"] ?? 0),
            "carbs_g" => floatval($it["carbs_g"] ?? 0),
            "protein_g" => floatval($it["protein_g"] ?? 0),
            "fat_g" => floatval($it["fat_g"] ?? 0),
            "fiber_g" => floatval($it["fiber_g"] ?? 0)
        ];
    }
}

if (empty($subItems)) {
    $subItems[] = [
        "name" => $dishName,
        "portion" => "1 serving",
        "calories" => $calories,
        "carbs_g" => floatval($carbs),
        "protein_g" => floatval($protein),
        "fat_g" => floatval($fat),
        "fiber_g" => floatval($fiber)
    ];
}

$outputData = [
    "dish_name" => $dishName,
    "visual_description" => $step1Description,
    "meal_type" => $mealType,
    "calories" => $calories,
    "protein_g" => $protein,
    "carbs_g" => $carbs,
    "fat_g" => $fat,
    "fiber_g" => $fiber,
    "health_score" => $healthScore,
    "health_reason" => $healthReason,
    "items" => $subItems
];

http_response_code(200);
echo json_encode([
    "success" => true,
    "status" => "success",
    "vision_model" => $visionModel,
    "text_model" => $textModel,
    "data" => $outputData,
    "dish_name" => $dishName,
    "calories" => $calories,
    "protein_g" => $protein,
    "carbs_g" => $carbs,
    "fat_g" => $fat,
    "fiber_g" => $fiber,
    "health_score" => $healthScore,
    "health_reason" => $healthReason,
    "items" => $subItems
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
