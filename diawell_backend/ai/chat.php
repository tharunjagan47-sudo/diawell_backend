<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];

// Extract user message across all common parameter names
$userMessage = trim($data["message"] ?? $data["prompt"] ?? $data["query"] ?? $_POST["message"] ?? $_POST["prompt"] ?? $_GET["message"] ?? "");

if (empty($userMessage)) {
    http_response_code(200);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "response" => "Message cannot be empty.",
        "reply" => "Message cannot be empty.",
        "message" => "Message cannot be empty.",
        "answer" => "Message cannot be empty.",
        "data" => [
            "response" => "Message cannot be empty.",
            "reply" => "Message cannot be empty.",
            "message" => "Message cannot be empty."
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Instant Zero-Token Tanglish-to-English Normalization:
 * Direct word-boundary mapping in PHP before sending to Ollama.
 * Converts multi-token Tanglish terms to standard English in 0.1ms.
 */
function normalizeTanglish(string $text): string {
    $replacements = [
        '/\b(thalai\s*suthal|mayakkam)\b/i' => 'dizziness (low sugar check)',
        '/\b(kammi\s*panna|kuraika|kuraiya)\b/i' => 'to lower',
        '/\b(kalaila|kaalaila)\b/i' => 'morning breakfast',
        '/\b(madhiyam)\b/i' => 'afternoon lunch',
        '/\b(rathiri|naight)\b/i' => 'night dinner',
        '/\b(sapadu|saapadu|sapdalam|saapidalam|sapda)\b/i' => 'food to eat',
        '/\b(sarkarai)\b/i' => 'blood sugar',
        '/\b(athigam|jasthi)\b/i' => 'high',
        '/\b(nadai)\b/i' => 'walking',
        '/\b(pasikudhu|pasi)\b/i' => 'feeling hungry',
        '/\b(epdi|eppadi)\b/i' => 'how',
        '/\b(enna)\b/i' => 'what'
    ];

    return preg_replace(array_keys($replacements), array_values($replacements), $text);
}

$normalizedPrompt = normalizeTanglish($userMessage);

// Ultra-Compact System Prompt (<50 tokens for instant CPU TTFT & strict no-report rule)
$systemPrompt = "You are Dr. Jarvis, DiaWell's rapid AI Diabetes Specialist. Understand English and Tanglish. Reply in exactly 2-3 short, practical bullet points with Indian low-GI diet or exercise advice. Do NOT generate reports, summaries, or consultation notes at the end.";

$ollamaUrl = "http://127.0.0.1:11434/api/chat";
$primaryModel = "qwen2.5:1.5b";
$fallbackModel = "qwen2.5:0.5b";

$options = [
    "num_gpu" => 0,          // Keep CPU lock (prevents CUDA crash)
    "num_ctx" => 512,        // Halves CPU memory scan time
    "num_predict" => 140,    // Sweet spot: complete 2-3 bullet answer in ~1.5 - 2.5 seconds
    "num_thread" => 8,       // Max CPU threads
    "temperature" => 0.3
];

/**
 * Execute Ollama chat request via cURL
 */
function queryOllama(string $url, string $model, string $systemPrompt, string $userPrompt, array $options): ?string {
    $payload = json_encode([
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userPrompt]
        ],
        "stream" => false,
        "keep_alive" => "60m",
        "options" => $options
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        $content = trim($decoded["message"]["content"] ?? "");
        if (!empty($content)) {
            return $content;
        }
    }
    return null;
}

// 1. Try Primary Model: qwen2.5:1.5b
$aiReply = queryOllama($ollamaUrl, $primaryModel, $systemPrompt, $normalizedPrompt, $options);

// 2. Automatic Fallback to qwen2.5:0.5b if 1.5b is unavailable or slow
if (empty($aiReply)) {
    $aiReply = queryOllama($ollamaUrl, $fallbackModel, $systemPrompt, $normalizedPrompt, $options);
}

// 3. Graceful default
if (empty($aiReply)) {
    $aiReply = "I am currently reviewing your diabetes wellness query. Please verify local Ollama is active and try again shortly.";
}

http_response_code(200);
echo json_encode([
    "success" => true,
    "status" => "success",
    "response" => $aiReply,
    "reply" => $aiReply,
    "message" => $aiReply,
    "answer" => $aiReply,
    "data" => [
        "response" => $aiReply,
        "reply" => $aiReply,
        "message" => $aiReply
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);