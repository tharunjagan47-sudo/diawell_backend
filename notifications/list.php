<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId();
if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, title, body, notification_type, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $stmt->execute([":user_id" => $userId]);
    $notifs = $stmt->fetchAll();

    // If empty, generate contextual initial sample notifications matching NotificationsScreen.kt
    if (empty($notifs)) {
        $sampleItems = [
            [
                "title" => "Evening medication reminder",
                "body" => "Metformin • scheduled at 8:00 PM. Follow your prescribed schedule.",
                "type" => "medication",
                "is_read" => 0
            ],
            [
                "title" => "A little meal check-in",
                "body" => "Remember to log your dinner and portion when you're ready.",
                "type" => "meal",
                "is_read" => 0
            ],
            [
                "title" => "Time for a glucose check-in",
                "body" => "Log a reading according to the routine agreed with your care team.",
                "type" => "glucose",
                "is_read" => 1
            ],
            [
                "title" => "Your weekly report is ready",
                "body" => "See your food, glucose and medication tracking for this week.",
                "type" => "report",
                "is_read" => 1
            ]
        ];

        $ins = $pdo->prepare("INSERT INTO notifications (user_id, title, body, notification_type, is_read) VALUES (:uid, :t, :b, :type, :r)");
        foreach ($sampleItems as $s) {
            $ins->execute([
                ":uid" => $userId,
                ":t" => $s["title"],
                ":b" => $s["body"],
                ":type" => $s["type"],
                ":r" => $s["is_read"]
            ]);
        }

        $stmt->execute([":user_id" => $userId]);
        $notifs = $stmt->fetchAll();
    }

    $formatted = [];
    $unreadCount = 0;
    foreach ($notifs as $row) {
        $isUnread = ((int) $row["is_read"] === 0);
        if ($isUnread) $unreadCount++;

        $timeAgo = date("g:i A", strtotime($row["created_at"]));
        $timeLabel = $timeAgo . " • " . ($isUnread ? "Unread" : "Read");

        $formatted[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "body" => $row["body"],
            "type" => $row["notification_type"],
            "time" => $timeLabel,
            "created_at" => $row["created_at"],
            "is_unread" => $isUnread,
            "is_read" => (int) $row["is_read"]
        ];
    }

    respond(true, "Notifications retrieved successfully.", 200, [
        "user_id" => $userId,
        "date_header" => "Today • " . date("j F"),
        "total" => count($formatted),
        "unread_count" => $unreadCount,
        "notifications" => $formatted
    ]);

} catch (PDOException $e) {
    error_log("Notifications list error: " . $e->getMessage());
    respond(false, "Database error retrieving notifications.", 500);
}

