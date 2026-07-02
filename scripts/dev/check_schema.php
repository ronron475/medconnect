<?php
require_once __DIR__ . '/config/db.php';

// Get table columns
$result = $pdo->query("DESCRIBE users");
echo "<pre>";
print_r($result->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
?>
