<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Helper to query Ollama
function queryOllama(string $endpoint, array $payload, int $timeout = 60): ?array {
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

$rawInput = file_get_contents("php://input");
if (empty($rawInput)) {
    $rawInput = @file_get_contents("php://stdin");
}
$data = json_decode($rawInput, true) ?: [];

// Fast Pre-Warm Endpoint Support
if (!empty($data["warmup"]) || !empty($_GET["warmup"]) || !empty($_POST["warmup"])) {
    $warmPayload = [
        "model" => "qwen2.5vl:3b",
        "prompt" => "",
        "keep_alive" => "30m"
    ];
    queryOllama("/api/generate", $warmPayload, 30);
    http_response_code(200);
    echo json_encode([
        "status" => "warm",
        "success" => true,
        "model" => "qwen2.5vl:3b"
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

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

// Vision model: qwen2.5vl:3b (single-pass, zero model swapping)
$modelName = "qwen2.5vl:3b";

$tightPrompt = "Identify all foods and portion sizes in this photo (Indian or international dishes). Compute realistic nutrition totals specifically for what you see. Return ONLY valid JSON with keys: dish_name (string), calories (int), protein_g (int), carbs_g (int), fat_g (int), fiber_g (int), health_score (int 1-10 for diabetes), health_reason (short 1-line string), items (array of {name: string, calories: int, carbs_g: int, protein_g: int, fat_g: int, fiber_g: int}).";

$payload = [
    "model" => $modelName,
    "prompt" => $tightPrompt,
    "images" => [$imageBase64],
    "format" => "json",
    "stream" => false,
    "keep_alive" => "30m",
    "options" => [
        "temperature" => 0.1,
        "num_predict" => 260,
        "num_ctx" => 1024
    ]
];

$ollamaResponse = queryOllama("/api/generate", $payload, 90);
$rawText = trim($ollamaResponse["response"] ?? "");

if (empty($rawText)) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Vision model ($modelName) failed to generate response."
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $rawText);
$cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
$cleanJson = trim($cleanJson);

$parsed = json_decode($cleanJson, true);
if (!is_array($parsed) || empty($parsed["dish_name"])) {
    // Repair dangling JSON if num_predict capped output before array/object closed
    if (preg_match('/\{[\s\S]*"dish_name"\s*:/i', $cleanJson)) {
        $repaired = preg_replace('/,\s*\{[^}]*$/', '', $cleanJson);
        $repaired = rtrim($repaired, " ,\r\n\t");
        if (substr($repaired, -1) !== ']' && strpos($repaired, '"items"') !== false) {
            $repaired .= ']';
        }
        if (substr($repaired, -1) !== '}') {
            $repaired .= '}';
        }
        $parsed = json_decode($repaired, true);
    }
}

// Regex fallback for individual keys if JSON is still incomplete
$dishName = "";
if (is_array($parsed) && !empty($parsed["dish_name"])) {
    $dishName = trim($parsed["dish_name"]);
} elseif (preg_match('/"dish_name"\s*:\s*"([^"]+)"/i', $cleanJson, $m)) {
    $dishName = trim($m[1]);
}

if (empty($dishName)) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Unable to parse food identification from model response.",
        "raw_response" => $rawText
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$calories = isset($parsed["calories"]) ? intval($parsed["calories"]) : 0;
if ($calories === 0 && preg_match('/"calories"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $calories = intval($m[1]);
}
if ($calories <= 0) {
    $calories = 320;
}

$protein = isset($parsed["protein_g"]) ? intval($parsed["protein_g"]) : (isset($parsed["protein"]) ? intval($parsed["protein"]) : 0);
if ($protein === 0 && preg_match('/"(?:protein_g|protein)"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $protein = intval($m[1]);
}

$carbs = isset($parsed["carbs_g"]) ? intval($parsed["carbs_g"]) : (isset($parsed["carbs"]) ? intval($parsed["carbs"]) : (isset($parsed["carbohydrates"]) ? intval($parsed["carbohydrates"]) : 0));
if ($carbs === 0 && preg_match('/"(?:carbs_g|carbs|carbohydrates)"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $carbs = intval($m[1]);
}

$fat = isset($parsed["fat_g"]) ? intval($parsed["fat_g"]) : (isset($parsed["fat"]) ? intval($parsed["fat"]) : (isset($parsed["fats"]) ? intval($parsed["fats"]) : 0));
if ($fat === 0 && preg_match('/"(?:fat_g|fat|fats)"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $fat = intval($m[1]);
}

$fiber = isset($parsed["fiber_g"]) ? intval($parsed["fiber_g"]) : (isset($parsed["fiber"]) ? intval($parsed["fiber"]) : 0);
if ($fiber === 0 && preg_match('/"(?:fiber_g|fiber)"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $fiber = intval($m[1]);
}

// Fallback estimation if model omitted specific macro numbers
if ($protein === 0 && $carbs === 0 && $fat === 0) {
    $carbs = intval(round(($calories * 0.50) / 4));
    $protein = intval(round(($calories * 0.20) / 4));
    $fat = intval(round(($calories * 0.30) / 9));
    $fiber = max(3, intval(round($carbs * 0.12)));
}

$healthScore = isset($parsed["health_score"]) ? intval($parsed["health_score"]) : 7;
if (preg_match('/"health_score"\s*:\s*(\d+)/i', $cleanJson, $m)) {
    $healthScore = intval($m[1]);
}
if ($healthScore < 1) $healthScore = 1;
if ($healthScore > 10) $healthScore = 10;

$healthReason = isset($parsed["health_reason"]) ? trim($parsed["health_reason"]) : "";
if ($healthReason === "" && preg_match('/"health_reason"\s*:\s*"([^"]+)"/i', $cleanJson, $m)) {
    $healthReason = trim($m[1]);
}
if ($healthReason === "") {
    $healthReason = "$dishName prepared with moderate glycemic impact.";
}

$subItems = [];
$totalItemCount = !empty($parsed["items"]) && is_array($parsed["items"]) ? count($parsed["items"]) : 1;
if (!empty($parsed["items"]) && is_array($parsed["items"])) {
    foreach ($parsed["items"] as $it) {
        if (!is_array($it)) continue;
        $itName = trim($it["name"] ?? "");
        if ($itName === "") continue;
        $itCal = intval($it["calories"] ?? 0);
        $ratio = ($calories > 0 && $itCal > 0) ? ($itCal / $calories) : (1.0 / max($totalItemCount, 1));

        $itCarbs = (isset($it["carbs_g"]) && floatval($it["carbs_g"]) > 0) ? floatval($it["carbs_g"]) : ((isset($it["carbs"]) && floatval($it["carbs"]) > 0) ? floatval($it["carbs"]) : round($carbs * $ratio, 1));
        $itProt = (isset($it["protein_g"]) && floatval($it["protein_g"]) > 0) ? floatval($it["protein_g"]) : ((isset($it["protein"]) && floatval($it["protein"]) > 0) ? floatval($it["protein"]) : round($protein * $ratio, 1));
        $itFat = (isset($it["fat_g"]) && floatval($it["fat_g"]) > 0) ? floatval($it["fat_g"]) : ((isset($it["fat"]) && floatval($it["fat"]) > 0) ? floatval($it["fat"]) : round($fat * $ratio, 1));
        $itFib = (isset($it["fiber_g"]) && floatval($it["fiber_g"]) > 0) ? floatval($it["fiber_g"]) : ((isset($it["fiber"]) && floatval($it["fiber"]) > 0) ? floatval($it["fiber"]) : round($fiber * $ratio, 1));

        $subItems[] = [
            "name" => $itName,
            "portion" => trim($it["portion"] ?? "1 serving"),
            "calories" => $itCal > 0 ? $itCal : intval(round($calories * $ratio)),
            "carbs_g" => floatval($itCarbs),
            "protein_g" => floatval($itProt),
            "fat_g" => floatval($itFat),
            "fiber_g" => floatval($itFib)
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
    "visual_description" => "$dishName ($calories kcal)",
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
    "vision_model" => $modelName,
    "text_model" => $modelName,
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
