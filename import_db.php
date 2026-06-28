<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', 'root');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS rgreenmart");
    $pdo->exec("USE rgreenmart");
    
    // Import SQL file
    $sql = file_get_contents(__DIR__ . '/DataBase/rgmv3live.sql');
    if ($sql === false) {
        die("Failed to read SQL file.");
    }
    
    // Execute SQL
    $pdo->exec($sql);
    echo "Database created and rgmv3live.sql imported successfully!";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
