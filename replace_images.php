<?php
$sourceImage = __DIR__ . '/images/Celebration.jpg';

function replacePlaceholders($dir) {
    global $sourceImage;
    if (!is_dir($dir)) return;
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            replacePlaceholders($path);
        } else {
            // If the file is small (e.g. the 1x1 GIF placeholder), replace it
            if (filesize($path) < 100) {
                copy($sourceImage, $path);
                echo "Replaced placeholder with visible image: $path\n";
            }
        }
    }
}

replacePlaceholders(__DIR__ . '/Uploads');
replacePlaceholders(__DIR__ . '/images');
echo "All placeholders have been replaced with a visible image!\n";
