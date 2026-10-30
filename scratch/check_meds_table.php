<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("DESCRIBE medications");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Columns of medications table:\n";
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']})\n";
}

