<?php
$urls = [
    'http://127.0.0.1:8000/',
    'http://127.0.0.1:8000/product.php?id=174',
    'http://127.0.0.1:8000/login.php',
    'http://127.0.0.1:8000/register.php',
    'http://127.0.0.1:8000/viewcart.php'
];

foreach ($urls as $url) {
    echo "Testing $url...\n";
    $html = @file_get_contents($url);
    
    if ($html === false) {
        echo "[ERROR] Could not fetch $url\n";
        continue;
    }
    
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning:') !== false || strpos($html, 'Parse error') !== false || strpos($html, 'Uncaught Error') !== false) {
        echo "[BUG FOUND] PHP error found on $url\n";
        // print a small context around the error
        preg_match('/(?:Fatal error|Warning:|Parse error|Uncaught Error).*$/m', $html, $matches);
        if(!empty($matches)) {
            echo "    -> " . strip_tags($matches[0]) . "\n";
        }
    } else {
        echo "[OK] No obvious PHP errors on $url\n";
    }
}
