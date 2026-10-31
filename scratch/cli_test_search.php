<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['query'] = 'metformin';
$t0 = microtime(true);
require 'c:/xampp/htdocs/DiaWell_backend/meds/search.php';
$t1 = microtime(true);
echo "\nTotal time: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

