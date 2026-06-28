<?php
// Connect to DB
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=rgreenmart', 'root', 'root');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1x1 transparent GIF base64
    $gif = base64_decode("R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7");

    // Helper function to create dummy image
    function ensureImageExists($path) {
        global $gif;
        if (empty($path)) return;
        $fullPath = __DIR__ . '/' . $path;
        if (!file_exists($fullPath)) {
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($fullPath, $gif);
            echo "Created missing image: $path\n";
        }
    }

    // Fix items
    $stmt = $pdo->query("SELECT image FROM items");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        ensureImageExists($row['image']);
    }

    // Fix item_images
    $stmt = $pdo->query("SHOW TABLES LIKE 'item_images'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT image_path, compressed_path FROM item_images");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            ensureImageExists($row['image_path']);
            ensureImageExists($row['compressed_path']);
        }
    }

    echo "All missing images have been generated!\n";

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
