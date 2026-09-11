<?php
// GroceryGenius — Database Connection (TEMPLATE)
// config/db.example.php
//
// This is a safe template to commit to the public repo.
// Copy this file to "db.php" (same folder) and fill in your real
// credentials there. "db.php" is gitignored and will never be pushed.

$host = 'localhost';          // e.g. 'localhost' for XAMPP, or 'sqlXXX.infinityfree.com' for InfinityFree
$db   = 'grocerygenius_db';   // your database name
$user = 'root';               // your MySQL username
$pass = '';                   // your MySQL password

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