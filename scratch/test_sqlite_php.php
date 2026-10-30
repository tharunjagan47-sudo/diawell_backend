<?php
require_once __DIR__ . '/../config/helpers.php';

$dbPath = __DIR__ . '/../data/meds.db';

if (!file_exists($dbPath)) {
    die("Database not found at $dbPath");
}

try {
    $sqlite = new PDO("sqlite:" . $dbPath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $term = "glycomet*";
    $stmt = $sqlite->prepare("
        SELECT m.product_id, m.brand_name, m.primary_ingredient, m.primary_strength, 
               m.dosage_form, m.therapeutic_class, m.manufacturer, m.packaging_raw, m.price_inr
        FROM medicines_fts f
        JOIN medicines m ON m.product_id = f.rowid
        WHERE medicines_fts MATCH :term
        ORDER BY rank
        LIMIT 5
    ");
    $stmt->execute([":term" => $term]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($results) . " rows:\n";
    print_r($results);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

