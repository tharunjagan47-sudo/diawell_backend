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
// DELETE: Clear chat history
// ==========================================
if ($method === "DELETE") {
    try {
        $stmt = $pdo->prepare("DELETE FROM ai_chat_messages WHERE user_id = :user_id");
        $stmt->execute([":user_id" => $userId]);

        respond(true, "AI chat history cleared successfully.", 200, [
            "user_id" => $userId,
            "cleared" => true
        ]);
    } catch (PDOException $e) {
        error_log("AI chat clear error: " . $e->getMessage());
        respond(false, "Database error clearing chat history.", 500);
    }
}

// ==========================================
// GET: Retrieve conversation history
// ==========================================
if ($method === "GET") {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, sender, message, created_at 
             FROM ai_chat_messages 
             WHERE user_id = :user_id 
             ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([":user_id" => $userId]);
        $rows = $stmt->fetchAll();

        // If empty, return initial greeting matching AiChatbotScreen.kt
        if (empty($rows)) {
            $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([":id" => $userId]);
            $u = $userStmt->fetch();
            $name = $u ? $u["full_name"] : "there";

            $initialGreeting = "Hi {$name}! I'm JARVIS, your AI Health & Glucose Assistant! ⚡🩺 I can suggest balanced meal plans, answer food questions, and track your daily routine. What's on your mind?";

            $ins = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, sender, message) VALUES (:uid, 'ai', :msg)");
            $ins->execute([":uid" => $userId, ":msg" => $initialGreeting]);

            $rows = [[
                "id" => (int) $pdo->lastInsertId(),
                "sender" => "ai",
                "message" => $initialGreeting,
                "created_at" => date("Y-m-d H:i:s")
            ]];
        }

        $formatted = array_map(function($r) {
            return [
                "id" => (int) $r["id"],
                "sender" => $r["sender"],
                "text" => $r["message"],
                "created_at" => $r["created_at"]
            ];
        }, $rows);

        respond(true, "Chat messages retrieved successfully.", 200, [
            "user_id" => $userId,
            "count" => count($formatted),
            "messages" => $formatted
        ]);

    } catch (PDOException $e) {
        error_log("AI chat fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving chat history.", 500);
    }
}

// ==========================================
// POST: Send message & get JARVIS response
// ==========================================
if ($method === "POST") {
    $userMessage = trim($data["message"] ?? $data["text"] ?? "");

    if ($userMessage === "") {
        respond(false, "Message text cannot be empty.", 400);
    }

    try {
        // 1. Fetch user health context
        $uStmt = $pdo->prepare(
            "SELECT u.full_name, u.diabetes_type, u.current_glucose_mg_dl, u.target_glucose_mg_dl,
                    hp.health_focus, hp.dietary_preferences, hp.allergies,
                    fp.preferred_diet
             FROM users u
             LEFT JOIN health_preferences hp ON hp.user_id = u.id
             LEFT JOIN food_preferences fp ON fp.user_id = u.id
             WHERE u.id = :id
             LIMIT 1"
        );
        $uStmt->execute([":id" => $userId]);
        $context = $uStmt->fetch();

        $userName = $context["full_name"] ?? "Patient";
        $diabetesType = $context["diabetes_type"] ?? "Type 2";
        $currentGlucose = $context["current_glucose_mg_dl"] ?? 128.0;
        $targetGlucose = $context["target_glucose_mg_dl"] ?? 120.0;
        $prefDiet = $context["preferred_diet"] ?? "Non-Vegetarian";

        // 2. Save user message to database
        $saveUser = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, sender, message) VALUES (:uid, 'user', :msg)");
        $saveUser->execute([":uid" => $userId, ":msg" => $userMessage]);
        $userMsgId = (int) $pdo->lastInsertId();

        // 3. Generate intelligent, contextual diabetic health response
        $msgLower = strtolower($userMessage);
        $aiReply = "";

        if (strpos($msgLower, "breakfast") !== false) {
            $aiReply = "For breakfast, try unsweetened rolled oats with plain curd or Greek yogurt, blueberries, and a few crushed almonds. This provides high soluble fiber, healthy fats and protein to prevent morning glycemic spikes.";
        } elseif (strpos($msgLower, "lunch") !== false) {
            $aiReply = "A balanced diabetic lunch: Fill half your plate with leafy greens/salad, one quarter with lean protein (like grilled chicken, tofu, or paneer), and one quarter with complex carbs like brown basmati rice or a multigrain roti.";
        } elseif (strpos($msgLower, "dinner") !== false) {
            $aiReply = "For dinner, keep carbohydrates lighter. Steamed vegetables with baked fish, or a warm bowl of yellow moong dal with a small portion of quinoa or methi paratha promotes restful digestion and stable overnight glucose levels.";
        } elseif (strpos($msgLower, "snack") !== false) {
            $aiReply = "Great low-glycemic snacks include roasted chana (sprouted gram chaat), a handful of walnuts, cucumber slices with hummus, or half an apple with peanut butter.";
        } elseif (strpos($msgLower, "fruit") !== false || strpos($msgLower, "mango") !== false || strpos($msgLower, "banana") !== false) {
            $aiReply = "Whole fruits with edible skin (berries, apples, pears, guavas) have lower glycemic loads. Enjoy fruits like mangoes or bananas in modest portions alongside protein (e.g. nuts) to slow sugar uptake.";
        } elseif (strpos($msgLower, "glucose") !== false || strpos($msgLower, "sugar") !== false || strpos($msgLower, "reading") !== false) {
            $aiReply = "Your recent recorded reading is {$currentGlucose} mg/dL compared to your fasting target of {$targetGlucose} mg/dL. Consistent 30-minute post-meal walks and adequate hydration significantly aid in natural insulin sensitivity.";
        } elseif (strpos($msgLower, "workout") !== false || strpos($msgLower, "exercise") !== false || strpos($msgLower, "walk") !== false) {
            $aiReply = "A 25–30 minute brisk walk or light cycling session stimulates muscular glucose uptake independently of insulin. It's especially effective when done 30 minutes after your main meal.";
        } else {
            $aiReply = "That's a thoughtful question! In managing {$diabetesType} diabetes, maintaining balanced macro portions (fiber, protein, healthy fats) and spacing carbohydrates evenly throughout the day helps you feel energized without unpredictable blood sugar swings.";
        }

        // 4. Save AI response to database
        $saveAi = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, sender, message) VALUES (:uid, 'ai', :msg)");
        $saveAi->execute([":uid" => $userId, ":msg" => $aiReply]);
        $aiMsgId = (int) $pdo->lastInsertId();

        respond(true, "AI response generated successfully.", 200, [
            "user_id" => $userId,
            "user_message" => [
                "id" => $userMsgId,
                "sender" => "user",
                "text" => $userMessage
            ],
            "ai_response" => [
                "id" => $aiMsgId,
                "sender" => "ai",
                "text" => $aiReply
            ]
        ]);

    } catch (PDOException $e) {
        error_log("AI chat reply error: " . $e->getMessage());
        respond(false, "Database error processing AI response.", 500);
    }
}

respond(false, "Method not allowed. Use GET, POST, or DELETE.", 405);

