<?php
// GroceryGenius — Database Connection
// config/db.php

$host = 'localhost';
$db   = 'grocerygenius_db';
$user = 'root';
$pass = ''; // XAMPP default is empty

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
