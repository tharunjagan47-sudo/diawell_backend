<?php
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$rawQuery = trim($_GET["query"] ?? $_GET["q"] ?? "");
$dbPath = __DIR__ . '/../data/meds.db';

// Fallback curated list in case SQLite database file is missing
$fallbackList = [
    [
        "name" => "Glycomet 500 SR Tablet",
        "dose" => "500mg • Tablet",
        "primary_ingredient" => "Metformin",
        "primary_strength" => "500mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "USV Ltd",
        "packaging" => "strip of 20 tablet sr",
        "price_inr" => "₹47.71",
        "timing" => "With Meal",
        "info" => "Active: Metformin (500mg) • Class: Antidiabetic • Mfd: USV Ltd",
        "icon" => "💊"
    ],
    [
        "name" => "Amaryl 2mg Tablet",
        "dose" => "2mg • Tablet",
        "primary_ingredient" => "Glimepiride",
        "primary_strength" => "2mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Sanofi India Ltd",
        "packaging" => "strip of 30 tablets",
        "price_inr" => "₹212.65",
        "timing" => "Before Breakfast",
        "info" => "Active: Glimepiride (2mg) • Class: Antidiabetic • Mfd: Sanofi India Ltd",
        "icon" => "💊"
    ],
    [
        "name" => "Januvia 100mg Tablet",
        "dose" => "100mg • Tablet",
        "primary_ingredient" => "Sitagliptin",
        "primary_strength" => "100mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "MSD Pharmaceuticals Pvt Ltd",
        "packaging" => "strip of 7 tablets",
        "price_inr" => "₹270.00",
        "timing" => "With Food",
        "info" => "Active: Sitagliptin (100mg) • Class: Antidiabetic • Mfd: MSD Pharmaceuticals Pvt Ltd",
        "icon" => "💊"
    ],
    [
        "name" => "Jardiance Met 12.5mg/500mg Tablet",
        "dose" => "12.5mg • Tablet",
        "primary_ingredient" => "Empagliflozin",
        "primary_strength" => "12.5mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Boehringer Ingelheim",
        "packaging" => "strip of 10 tablets",
        "price_inr" => "₹400.00",
        "timing" => "With Meal",
        "info" => "Active: Empagliflozin (12.5mg) • Class: Antidiabetic • Mfd: Boehringer Ingelheim",
        "icon" => "💊"
    ],
    [
        "name" => "Galvus Met 50mg/500mg Tablet",
        "dose" => "500mg • Tablet",
        "primary_ingredient" => "Metformin",
        "primary_strength" => "500mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Novartis India Ltd",
        "packaging" => "strip of 15 tablets",
        "price_inr" => "₹328.50",
        "timing" => "With Meal",
        "info" => "Active: Metformin (500mg) • Class: Antidiabetic • Mfd: Novartis India Ltd",
        "icon" => "💊"
    ],
    [
        "name" => "Gluconorm SR 500mg Tablet",
        "dose" => "500mg • Tablet",
        "primary_ingredient" => "Metformin",
        "primary_strength" => "500mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Lupin Ltd",
        "packaging" => "strip of 15 tablets",
        "price_inr" => "₹35.05",
        "timing" => "With Meal",
        "info" => "Active: Metformin (500mg) • Class: Antidiabetic • Mfd: Lupin Ltd",
        "icon" => "💊"
    ]
];

if (!file_exists($dbPath)) {
    if ($rawQuery === "") {
        $filtered = $fallbackList;
    } else {
        $filtered = array_values(array_filter($fallbackList, function($item) use ($rawQuery) {
            return stripos($item["name"], $rawQuery) !== false ||
                   stripos($item["primary_ingredient"], $rawQuery) !== false ||
                   stripos($item["category"], $rawQuery) !== false;
        }));
    }

    respond(true, "Medication suggestions retrieved successfully.", 200, [
        "search_query" => $rawQuery,
        "count" => count($filtered),
        "source" => "catalog",
        "medications" => $filtered
    ]);
}

try {
    $sqlite = new PDO("sqlite:" . $dbPath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $results = [];

    if ($rawQuery === "") {
        // Return top curated antidiabetic & wellness medicines from dataset
        $curatedIds = [94173, 122, 113868, 113878, 94183, 94210, 94182, 94174, 214769, 58235, 66];
        $idList = implode(',', $curatedIds);
        $stmt = $sqlite->query("
            SELECT product_id, brand_name, primary_ingredient, primary_strength,
                   dosage_form, therapeutic_class, manufacturer, packaging_raw, price_inr
            FROM medicines
            WHERE product_id IN ($idList)
            ORDER BY CASE product_id
                WHEN 94173 THEN 1
                WHEN 122 THEN 2
                WHEN 113868 THEN 3
                WHEN 113878 THEN 4
                WHEN 94183 THEN 5
                WHEN 94210 THEN 6
                WHEN 94182 THEN 7
                WHEN 94174 THEN 8
                WHEN 214769 THEN 9
                WHEN 58235 THEN 10
                WHEN 66 THEN 11
                ELSE 12 END
            LIMIT 25
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Clean and tokenize query
        $clean = trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $rawQuery));
        $words = array_values(array_filter(explode(' ', $clean)));

        if (!empty($words)) {
            // Build prefix tokens for FTS5
            $ftsParts = [];
            foreach ($words as $w) {
                $cleanWord = trim($w);
                if ($cleanWord !== "") {
                    $ftsParts[] = '"' . $cleanWord . '"*';
                }
            }

            if (!empty($ftsParts)) {
                // 1. Strict AND match across fields with BM25 ranking (brand_name weighted 10x, ingredient 6x, active ingredients 4x)
                $ftsQueryAnd = implode(' ', $ftsParts);
                $stmt = $sqlite->prepare("
                    SELECT product_id, brand_name, primary_ingredient, primary_strength, 
                           dosage_form, therapeutic_class, manufacturer, packaging_raw, price_inr
                    FROM medicines_fast
                    WHERE medicines_fast MATCH :term
                    ORDER BY bm25(medicines_fast, 10.0, 6.0, 2.0, 1.0, 2.0, 1.0, 4.0)
                    LIMIT 30
                ");
                $stmt->execute([":term" => $ftsQueryAnd]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 2. If multi-word search returned 0 rows, fallback to OR match
                if (empty($results) && count($ftsParts) > 1) {
                    $ftsQueryOr = implode(' OR ', $ftsParts);
                    $stmt = $sqlite->prepare("
                        SELECT product_id, brand_name, primary_ingredient, primary_strength, 
                               dosage_form, therapeutic_class, manufacturer, packaging_raw, price_inr
                        FROM medicines_fast
                        WHERE medicines_fast MATCH :term
                        ORDER BY bm25(medicines_fast, 10.0, 6.0, 2.0, 1.0, 2.0, 1.0, 4.0)
                        LIMIT 30
                    ");
                    $stmt->execute([":term" => $ftsQueryOr]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }

    $formatted = [];
    foreach ($results as $row) {
        $dosageForm = strtolower($row["dosage_form"] ?? "tablet");
        $primaryStrength = trim($row["primary_strength"] ?? "");
        $primaryIngredient = trim($row["primary_ingredient"] ?? "");
        $therapeuticClass = trim($row["therapeutic_class"] ?? "other");
        $packaging = trim($row["packaging_raw"] ?? "");
        $price = (float) ($row["price_inr"] ?? 0.0);

        // Determine icon based on dosage form
        if (strpos($dosageForm, "inject") !== false || strpos($dosageForm, "penfill") !== false || strpos($dosageForm, "cartridge") !== false) {
            $icon = "💉";
        } elseif (strpos($dosageForm, "inhaler") !== false || strpos($dosageForm, "respule") !== false) {
            $icon = "💨";
        } elseif (strpos($dosageForm, "syrup") !== false || strpos($dosageForm, "suspension") !== false || strpos($dosageForm, "solution") !== false || strpos($dosageForm, "drop") !== false) {
            $icon = "💧";
        } elseif (strpos($dosageForm, "cream") !== false || strpos($dosageForm, "gel") !== false || strpos($dosageForm, "ointment") !== false) {
            $icon = "🧴";
        } else {
            $icon = "💊";
        }

        // Formatted dose label
        $doseLabel = $primaryStrength !== "" ? ($primaryStrength . " • " . ucfirst($dosageForm)) : ucfirst($dosageForm);

        // Determine recommended schedule timing based on category & ingredient
        $lowerClass = strtolower($therapeuticClass);
        $lowerIng = strtolower($primaryIngredient);

        if (strpos($lowerIng, "metformin") !== false) {
            $timing = "With Meal";
        } elseif (strpos($lowerIng, "glimepiride") !== false || strpos($lowerIng, "glipizide") !== false || strpos($lowerIng, "gliclazide") !== false) {
            $timing = "Before Breakfast";
        } elseif (strpos($lowerIng, "insulin") !== false) {
            $timing = "Before Meal";
        } elseif (strpos($lowerClass, "antidiabetic") !== false) {
            $timing = "With Food";
        } elseif (strpos($lowerClass, "statin") !== false || strpos($lowerIng, "atorvastatin") !== false || strpos($lowerIng, "rosuvastatin") !== false) {
            $timing = "At Bedtime";
        } elseif (strpos($lowerClass, "antacid") !== false) {
            $timing = "Before Meal";
        } elseif (strpos($lowerClass, "vitamin") !== false || strpos($lowerClass, "supplement") !== false) {
            $timing = "After Lunch";
        } else {
            $timing = "After Meal";
        }

        // Format therapeutic class nicely
        $categoryDisplay = ($lowerClass === "other" || $lowerClass === "") 
            ? "General Medicine" 
            : ucwords(str_replace(['_', '-'], ' ', $therapeuticClass));

        // Format info description
        $infoParts = [];
        if ($primaryIngredient !== "") {
            $infoParts[] = "Active: {$primaryIngredient}" . ($primaryStrength !== "" ? " ({$primaryStrength})" : "");
        }
        if ($categoryDisplay !== "") {
            $infoParts[] = "Class: {$categoryDisplay}";
        }
        if (!empty($row["manufacturer"])) {
            $infoParts[] = "Mfd: {$row["manufacturer"]}";
        }
        $infoText = implode(" • ", $infoParts);

        $formatted[] = [
            "product_id" => (int) $row["product_id"],
            "name" => $row["brand_name"],
            "dose" => $doseLabel,
            "primary_ingredient" => $primaryIngredient,
            "primary_strength" => $primaryStrength,
            "dosage_form" => ucfirst($dosageForm),
            "category" => $categoryDisplay,
            "manufacturer" => $row["manufacturer"] ?? "",
            "packaging" => $packaging,
            "price_inr" => $price > 0 ? ("₹" . number_format($price, 2)) : "",
            "timing" => $timing,
            "info" => $infoText,
            "icon" => $icon
        ];
    }

    respond(true, "Medication suggestions retrieved successfully.", 200, [
        "search_query" => $rawQuery,
        "count" => count($formatted),
        "source" => "dataset",
        "medications" => $formatted
    ]);

} catch (Exception $e) {
    error_log("Search medications error: " . $e->getMessage());
    respond(false, "Error searching medications database.", 500);
}
