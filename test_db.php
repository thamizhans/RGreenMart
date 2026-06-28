<?php
try { 
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', 'root'); 
    echo "Connected successfully to MySQL!"; 
} catch (PDOException $e) { 
    echo "Connection failed: " . $e->getMessage(); 
}
