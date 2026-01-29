<?php
require 'config.php';
$db = getDB();
$rates = get_active_rates($db);
echo json_encode($rates, JSON_PRETTY_PRINT);
