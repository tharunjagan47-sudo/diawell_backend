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

// 1. Detect installed Ollama vision model dynamically (defaults to moondream)
$visionModel = "moondream";
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
        $candidates = ["moondream", "qwen2.5vl:3b", "qwen2.5vl", "llava", "llama3.2-vision", "vision"];
        foreach ($candidates as $cand) {
            foreach ($tagsJson["models"] as $m) {
                $mName = strtolower($m["name"] ?? "");
                if (strpos($mName, $cand) !== false) {
                    $visionModel = $m["name"];
                    break 2;
                }
            }
        }
    }
}

// 2. Strict JSON prompt for nutrition info breakdown
$prompt = "Look carefully at this food image. Identify the exact dish(es) visible in the photo (Indian or international). Return ONLY a valid JSON object with NO markdown, NO extra text:\n" .
"{\n" .
"  \"dish_name\": \"Exact name of the food\",\n" .
"  \"calories\": 350,\n" .
"  \"protein_g\": 12,\n" .
"  \"carbs_g\": 45,\n" .
"  \"fat_g\": 10,\n" .
"  \"fiber_g\": 5,\n" .
"  \"health_score\": 7,\n" .
"  \"health_reason\": \"Short 1-line diabetes-friendly note\",\n" .
"  \"items\": [\n" .
"    {\"name\": \"Main item\", \"calories\": 250},\n" .
"    {\"name\": \"Side item\", \"calories\": 100}\n" .
"  ]\n" .
"}";

$ollamaPayload = [
    "model" => $visionModel,
    "prompt" => $prompt,
    "images" => [$imageBase64],
    "stream" => false,
    "format" => "json"
];

$ch = curl_init("http://127.0.0.1:11434/api/generate");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($ollamaPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 5
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Ollama vision inference failed or timed out: " . ($curlError ?: "HTTP $httpCode"),
        "model" => $visionModel
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$decodedOllama = json_decode($response, true);
$rawText = trim($decodedOllama["response"] ?? "");

// Strip code fence wrappers if present
$cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $rawText);
$cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
$cleanJson = trim($cleanJson);

$parsedNutrition = json_decode($cleanJson, true);

if (!is_array($parsedNutrition) || empty($parsedNutrition["dish_name"])) {
    // Attempt regex extraction of JSON object if surrounded by prose
    if (preg_match('/\{[\s\S]*\}/', $rawText, $matches)) {
        $parsedNutrition = json_decode($matches[0], true);
    }
}

if (!is_array($parsedNutrition) || empty($parsedNutrition["dish_name"])) {
    http_response_code(200);
    echo json_encode([
        "success" => false,
        "status" => "uncertain",
        "message" => "Could not confidently identify the dish from image.",
        "raw_response" => $rawText,
        "model" => $visionModel
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize fields & ensure defaults
$dishName = trim($parsedNutrition["dish_name"] ?? "Healthy Meal");
$calories = intval($parsedNutrition["calories"] ?? 300);
if ($calories <= 0) $calories = 250;
$protein = floatval($parsedNutrition["protein_g"] ?? $parsedNutrition["protein"] ?? 10);
$carbs = floatval($parsedNutrition["carbs_g"] ?? $parsedNutrition["carbs"] ?? 35);
$fat = floatval($parsedNutrition["fat_g"] ?? $parsedNutrition["fat"] ?? 6);
$fiber = floatval($parsedNutrition["fiber_g"] ?? $parsedNutrition["fiber"] ?? 5);
$healthScore = floatval($parsedNutrition["health_score"] ?? 8);
if ($healthScore <= 0 || $healthScore > 10) $healthScore = 8.0;
$healthReason = trim($parsedNutrition["health_reason"] ?? "Balanced meal portion with moderate glycemic load.");

$subItems = [];
if (!empty($parsedNutrition["items"]) && is_array($parsedNutrition["items"])) {
    foreach ($parsedNutrition["items"] as $it) {
        if (!is_array($it)) continue;
        $itName = trim($it["name"] ?? "");
        if ($itName === "") continue;
        $itCal = intval($it["calories"] ?? 0);
        $subItems[] = [
            "name" => $itName,
            "portion" => trim($it["portion"] ?? "1 serving"),
            "calories" => $itCal > 0 ? $itCal : intval($calories / max(1, count($parsedNutrition["items"]))),
            "carbs_g" => floatval($it["carbs_g"] ?? ($carbs / max(1, count($parsedNutrition["items"])))),
            "protein_g" => floatval($it["protein_g"] ?? ($protein / max(1, count($parsedNutrition["items"])))),
            "fat_g" => floatval($it["fat_g"] ?? ($fat / max(1, count($parsedNutrition["items"])))),
            "fiber_g" => floatval($it["fiber_g"] ?? ($fiber / max(1, count($parsedNutrition["items"]))))
        ];
    }
}

if (empty($subItems)) {
    $subItems[] = [
        "name" => $dishName,
        "portion" => "1 serving",
        "calories" => $calories,
        "carbs_g" => $carbs,
        "protein_g" => $protein,
        "fat_g" => $fat,
        "fiber_g" => $fiber
    ];
}

$outputData = [
    "dish_name" => $dishName,
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
    "model" => $visionModel,
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
