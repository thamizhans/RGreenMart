<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=rgreenmart', 'root', 'root');
$stmt = $pdo->query('SHOW COLUMNS FROM items');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
$stmt2 = $pdo->query('SHOW COLUMNS FROM item_images');
print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));
