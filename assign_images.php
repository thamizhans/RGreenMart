<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=rgreenmart', 'root', 'root');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $artifactDir = 'C:/Users/tamil/.gemini/antigravity/brain/b9f247c8-fa9c-476e-8224-adcbbfe672cc/';
    
    // Mapping of product names to artifact image paths
    $mapping = [
        'Badam' => 'badam_product_1782605748264.png',
        'Cashew' => 'cashew_product_1782605758482.png',
        'kismish' => 'kismish_product_1782605767719.png',
        'Dates' => 'dates_product_1782605776728.png',
        'Honey' => 'honey_product_1782605792078.png',
        'Black Dates' => 'black_dates_product_1782605800207.png',
        'Pista' => 'pista_product_1782605808429.png',
        'Walnuts' => 'walnuts_product_1782605817961.png',
        'Atthipalam' => 'atthipalam_product_1782605833864.png',
        'Appricot' => 'appricot_product_1782605844783.png',
        'Healthy Combo Pack' => 'combo_pack_product_1782605853689.png'
    ];

    // Helper to copy image to path
    function copyImage($sourceName, $targetPath) {
        global $artifactDir;
        if (empty($targetPath)) return;
        $sourcePath = $artifactDir . $sourceName;
        $fullTarget = __DIR__ . '/' . $targetPath;
        if (file_exists($sourcePath)) {
            $dir = dirname($fullTarget);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            copy($sourcePath, $fullTarget);
            echo "Copied $sourceName to $targetPath\n";
        }
    }

    $stmt = $pdo->query("SELECT id, name, image FROM items");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim($row['name']);
        if (isset($mapping[$name])) {
            $sourceFile = $mapping[$name];
            
            // Assign to items.image
            if (!empty($row['image'])) {
                copyImage($sourceFile, $row['image']);
            }
            
            // Also assign to any item_images for this item
            $imgStmt = $pdo->prepare("SELECT image_path, compressed_path FROM item_images WHERE item_id = ?");
            $imgStmt->execute([$row['id']]);
            while ($imgRow = $imgStmt->fetch(PDO::FETCH_ASSOC)) {
                copyImage($sourceFile, $imgRow['image_path']);
                copyImage($sourceFile, $imgRow['compressed_path']);
            }
        }
    }

    echo "All specific product images have been assigned and copied!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
