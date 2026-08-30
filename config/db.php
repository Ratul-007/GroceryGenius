<?php
// GroceryGenius — Database Connection
// config/db.php

$host = 'sql106.infinityfree.com';
$db   = 'if0_42753114_grocerygenius';
$user = 'if0_42753114';
$pass = 'Grocery007'; // <-- replace with your actual InfinityFree MySQL password

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode([
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]));
}
?>