<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'vendor/autoload.php';
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) exit("Invalid Product");

// Fetch image size settings
$stmt = $conn->prepare("SELECT * FROM image_settings");
$stmt->execute();
$imageSizes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sizes = [];
foreach ($imageSizes as $size) {  $sizes[$size['type']] = $size; }

$productWidth  = $sizes['product']['width'] ?? 500;
$productHeight = $sizes['product']['height'] ?? 450;
$thumbWidth  = $sizes['thumbnail']['width'] ?? 70;
$thumbHeight = $sizes['thumbnail']['height'] ?? 70;

$stmt = $conn->prepare("SELECT * FROM items WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT gst_rate FROM settings LIMIT 1");
$stmt->execute();
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$gstRate = isset($settings['gst_rate']) ? floatval($settings['gst_rate']) : 18;
if (!$product) exit("Product Not Found");

$defaultImage = "/images/default.jpg";

$imgStmt = $conn->prepare("
    SELECT compressed_path, image_path 
    FROM item_images 
    WHERE item_id = ?
      AND image_type = 'gallery'
    ORDER BY is_primary DESC, sort_order ASC 
    LIMIT 1
");
$imgStmt->execute([$id]);
$img = $imgStmt->fetch(PDO::FETCH_ASSOC);

if ($img && (!empty($img['compressed_path']) || !empty($img['image_path']))) {
    $candidate = !empty($img['compressed_path']) ? $img['compressed_path'] : $img['image_path'];
    $variants_paths = ['/' . ltrim($candidate, '/'), '/admin/' . ltrim($candidate, '/')];
    $found = false;
    foreach ($variants_paths as $v) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $v)) {
            $displayImgPath = $v;
            $found = true;
            break;
        }
    }
    if (!$found) $displayImgPath = $defaultImage;
} else {
    $image = basename($product['image'] ?? '');
    $originalImgPath = "./admin/Uploads/$image";
    $compressedImgPath = "./admin/Uploads/compressed/$image";
    $displayImgPath = file_exists($compressedImgPath) ? $compressedImgPath : (file_exists($originalImgPath) ? $originalImgPath : $defaultImage);
}

$displayImgPath = htmlspecialchars($displayImgPath, ENT_QUOTES, 'UTF-8');

// --- Fetch all images for gallery ---
$images = [];
$allStmt = $conn->prepare("
    SELECT id, image_path, compressed_path, is_primary 
    FROM item_images 
    WHERE item_id = ?
      AND image_type = 'gallery'
    ORDER BY is_primary DESC, sort_order ASC
");
$allStmt->execute([$id]);
$rows = $allStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter images: If you have specific 'carousel' sizes, filter here.
// Otherwise, just process all unique images once.
foreach ($rows as $r) {
    $candidate = !empty($r['compressed_path']) ? $r['compressed_path'] : $r['image_path'];
    if (empty($candidate)) continue;
    
    $v_check = ['/' . ltrim($candidate, '/'), '/admin/' . ltrim($candidate, '/')];
    $src = null;
    foreach ($v_check as $vc) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $vc)) { 
            $src = $vc; 
            break; 
        }
    }
    
    if ($src) {
        $images[] = [
            'id' => $r['id'], 
            'src' => $src, 
            'is_primary' => (bool)$r['is_primary']
        ];
    }
}

// Ensure at least the main product image exists if the gallery is empty
if (empty($images)) {
    // Try to fetch thumbnail as fallback
    $thumbStmt = $conn->prepare("
        SELECT image_path, compressed_path 
        FROM item_images 
        WHERE item_id = ?
          AND image_type = 'thumbnail'
        LIMIT 1
    ");
    $thumbStmt->execute([$id]);
    $thumb = $thumbStmt->fetch(PDO::FETCH_ASSOC);

    if ($thumb) {
        $candidate = !empty($thumb['compressed_path']) 
            ? $thumb['compressed_path'] 
            : $thumb['image_path'];

        $images[] = [
            'id' => 0,
            'src' => '/' . ltrim($candidate, '/'),
            'is_primary' => true
        ];
    } else {
        $images[] = ['id' => 0, 'src' => $defaultImage, 'is_primary' => true];
    }
}

$mainImageSrc = $displayImgPath;
$initialIndex = 0;
if (!empty($images)) {
    foreach ($images as $idx => $imgEntry) {
        if ($imgEntry['is_primary']) { $mainImageSrc = $imgEntry['src']; $initialIndex = $idx; break; }
    }
}
$mainImageSrc = htmlspecialchars($mainImageSrc, ENT_QUOTES, 'UTF-8');

// ─── THUMBNAIL IMAGE for OG / social share preview ───────────────────────────
// Prefer the dedicated thumbnail image type; fall back to gallery main image.
$ogImageAbsolute = '';
$thumbShareStmt = $conn->prepare("
    SELECT COALESCE(compressed_path, image_path) AS img_path
    FROM item_images
    WHERE item_id = ?
      AND image_type = 'thumbnail'
    ORDER BY is_primary DESC, sort_order ASC
    LIMIT 1
");
$thumbShareStmt->execute([$id]);
$thumbShareRow = $thumbShareStmt->fetchColumn();

if ($thumbShareRow) {
    $thumbCandidates = [
        '/admin/' . ltrim($thumbShareRow, '/'),
        '/'       . ltrim($thumbShareRow, '/'),
    ];
    foreach ($thumbCandidates as $tc) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $tc)) {
            $ogImageAbsolute = 'https://rgreenmart.com' . $tc;
            break;
        }
    }
}

// Fall back to the gallery main image if no thumbnail found
if (empty($ogImageAbsolute)) {
    $ogImageAbsolute = 'https://rgreenmart.com' . $mainImageSrc;
}

$ogImageAbsolute  = htmlspecialchars($ogImageAbsolute,  ENT_QUOTES, 'UTF-8');
$ogTitle          = htmlspecialchars($product['name'],   ENT_QUOTES, 'UTF-8');
$ogDescription    = htmlspecialchars(
    !empty($product['description'])
        ? mb_strimwidth(strip_tags($product['description']), 0, 160, '…')
        : 'Buy ' . $product['name'] . ' at the best price on RGreenMart – premium quality guaranteed.',
    ENT_QUOTES, 'UTF-8'
);
$ogUrl            = 'https://rgreenmart.com/product.php?id=' . $id;

// ----------------------- VARIANTS -----------------------
$varStmt = $conn->prepare("SELECT id, weight_value, weight_unit, price, old_price, discount, stock FROM item_variants WHERE item_id = ? AND status = 1 ORDER BY weight_value ASC");
$varStmt->execute([$id]);
$variants = $varStmt->fetchAll(PDO::FETCH_ASSOC);
$defaultVariant = $variants[0] ?? null;
// -------- TOTAL STOCK CALCULATION (FIX) --------
$totalStockStmt = $conn->prepare("
    SELECT SUM(stock)
    FROM item_variants
    WHERE item_id = ? AND status = 1
");
$totalStockStmt->execute([$id]);
$totalStock = (int)$totalStockStmt->fetchColumn();
$stockQty = $totalStock ?? 0;

// ----------------------- PRICE CALCULATION -----------------------
if ($defaultVariant) {
    $rawOldPrice = floatval($defaultVariant['old_price'] ?? 0);
    $rawPrice    = floatval($defaultVariant['price']     ?? 0);
    $rawDiscount = floatval($defaultVariant['discount']  ?? 0);

    // Derive old_price when missing but discount% is set in DB
    if ($rawOldPrice <= 0 && $rawPrice > 0 && $rawDiscount > 0 && $rawDiscount < 100) {
        $rawOldPrice = $rawPrice / (1 - $rawDiscount / 100);
    }

    $grossPrice            = round($rawOldPrice);
    $simpleDiscountedPrice = round($rawPrice);

    if ($grossPrice > 0 && $simpleDiscountedPrice > 0 && $grossPrice > $simpleDiscountedPrice) {
        $discountRate = floor((($grossPrice - $simpleDiscountedPrice) / $grossPrice) * 100);
    } else {
        $discountRate = 0;
    }
} else {
    $grossPrice = 0; $discountRate = 0; $simpleDiscountedPrice = 0; $stockQty = 0; $variantId = 0;
}

$catStmt = $conn->prepare("SELECT name FROM categories WHERE id=?");
$catStmt->execute([$product['category_id']]);
$categoryName = $catStmt->fetchColumn() ?: "Unknown";

$brandStmt = $conn->prepare("SELECT name FROM brands WHERE id=?");
$brandStmt->execute([$product['brand_id']]);
$brandName = $brandStmt->fetchColumn() ?: "No Brand";

// ─── RATING CALCULATION ───────────────────────────────────────────────────
// total_rating_points = init_user_count * init_rating_star + sum of user stars
// total_reviews       = init_user_count + number of real user reviews
// avg = total_rating_points / total_reviews
$totalReviews = (int)($product['total_reviews']      ?? 0);
$totalPoints  = (float)($product['total_rating_points'] ?? 0);
if ($totalReviews > 0) {
    $finalRating = $totalPoints / $totalReviews;
} else {
    $finalRating = 0;
}
$finalRating = round(max(0, min(5, $finalRating)), 1);

// ─── HANDLE REVIEW SUBMISSION ─────────────────────────────────────────────
$reviewMsg      = '';
$reviewMsgType  = '';
$isLoggedIn     = isset($_SESSION['user_id']);

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $stars      = max(1, min(5, intval($_POST['stars'] ?? 0)));
    $reviewText = trim($_POST['review_text'] ?? '');
    $userId     = (int)$_SESSION['user_id'];

    // Check if user already reviewed
    $existsStmt = $conn->prepare("SELECT id FROM item_reviews WHERE item_id=? AND user_id=? LIMIT 1");
    $existsStmt->execute([$id, $userId]);
    if ($existsStmt->fetch()) {
        $reviewMsg     = 'You have already reviewed this product.';
        $reviewMsgType = 'error';
    } else {
        $conn->beginTransaction();
        try {
            $conn->prepare("INSERT INTO item_reviews (item_id, user_id, stars, review_text) VALUES (?,?,?,?)")
                 ->execute([$id, $userId, $stars, $reviewText ?: null]);
            $conn->prepare("UPDATE items SET total_rating_points = total_rating_points + ?, total_reviews = total_reviews + 1 WHERE id=?")
                 ->execute([$stars, $id]);
            $conn->commit();
            $reviewMsg     = 'Thank you! Your review has been submitted.';
            $reviewMsgType = 'success';
            // Recalculate rating after submission
            $product['total_reviews']       = $totalReviews + 1;
            $product['total_rating_points'] = $totalPoints  + $stars;
            $totalReviews = $product['total_reviews'];
            $totalPoints  = $product['total_rating_points'];
            if ($totalReviews > 0) {
                $finalRating = $totalPoints / $totalReviews;
            }
            $finalRating = round(max(0, min(5, $finalRating)), 1);
        } catch (Exception $e) {
            $conn->rollBack();
            $reviewMsg     = 'Failed to submit review. Please try again.';
            $reviewMsgType = 'error';
        }
    }
}

// Fetch existing reviews — fetch both name and email so we can
// display name-before-@ as the display name
$reviewsStmt = $conn->prepare("
    SELECT r.stars, r.review_text, r.created_at, u.name AS user_name, u.email AS user_email
    FROM item_reviews r
    JOIN users u ON u.id = r.user_id
    WHERE r.item_id = ?
    ORDER BY r.created_at DESC
    LIMIT 20
");
$reviewsStmt->execute([$id]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Has current user already reviewed?
$userAlreadyReviewed = false;
if ($isLoggedIn) {
    $chkStmt = $conn->prepare("SELECT id FROM item_reviews WHERE item_id=? AND user_id=? LIMIT 1");
    $chkStmt->execute([$id, $_SESSION['user_id']]);
    $userAlreadyReviewed = (bool)$chkStmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- ═══════════════════════════════════════════════════════════
         Open Graph — controls preview on WhatsApp, Facebook,
         Telegram, iMessage, LinkedIn, Discord, etc.
    ════════════════════════════════════════════════════════════ -->
    <meta property="og:type"        content="product">
    <meta property="og:site_name"   content="RGreenMart">
    <meta property="og:url"         content="<?= $ogUrl ?>">
    <meta property="og:title"       content="<?= $ogTitle ?>">
    <meta property="og:description" content="<?= $ogDescription ?>">
    <meta property="og:image"       content="<?= $ogImageAbsolute ?>">
    <meta property="og:image:alt"   content="<?= $ogTitle ?>">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">

    <!-- ═══════════════════════════════════════════════════════════
         Twitter / X Card — controls preview on Twitter / X
         (also read by some other scrapers as a fallback)
    ════════════════════════════════════════════════════════════ -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:site"        content="@rgreenmart">
    <meta name="twitter:title"       content="<?= $ogTitle ?>">
    <meta name="twitter:description" content="<?= $ogDescription ?>">
    <meta name="twitter:image"       content="<?= $ogImageAbsolute ?>">
    <meta name="twitter:image:alt"   content="<?= $ogTitle ?>">

    <!-- Canonical URL (helps crawlers deduplicate) -->
    <meta name="description" content="<?= $ogDescription ?>">
    <link rel="canonical" href="<?= $ogUrl ?>">

    <title><?= htmlspecialchars($product['name']); ?> - RGreenMart</title>
    <link rel="icon" type="image/png" href="./images/LOGO.jpg">
    <link rel="stylesheet" href="./Styles.css">
    <script src="cart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --primary-pink: #000000; --dark-blue: #212b36; --success-green: #000000; }
        body { background-color: #fcfcfc; font-family: 'Inter', sans-serif; color: #333; }
        .product-container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .image-viewer { position: sticky; top: 20px; }
        .main-img-holder { width: 100%; overflow: hidden; }
        .main-img-holder img { width: 100%; height: auto; object-fit: cover; }
        
        .thumb-nav { display: flex; gap: 10px; margin-top: 15px; overflow-x: auto; padding-bottom: 5px; }
        .thumb-item { width: 80px; height: 80px; border: 1px solid #eee; cursor: pointer; border-radius: 4px; overflow: hidden; opacity: 0.6; transition: 0.3s; flex-shrink: 0; }
        .thumb-item.active { border-color: #000; opacity: 1; }
        .thumb-item img { width: 100%; height: 100%; object-fit: cover; }

        /* Content Section */
        .product-title { font-size: 2rem; font-weight: 600; color: #1a1a1a; margin-bottom: 10px; }
        .price-row { font-size: 1.5rem; font-weight: 700; color: #000; margin: 15px 0; }
        .old-price { font-size: 1.1rem; color: #999; text-decoration: line-through; margin-left: 10px; font-weight: 400; }
        
        .info-label { font-size: 0.9rem; font-weight: 600; color: #555; margin-bottom: 8px; display: block; }
        
        .variant-btn { border: 1px solid #ddd; padding: 8px 16px; border-radius: 4px; cursor: pointer; transition: 0.2s; background: #fff; }
        .variant-btn.active { border-color: var(--primary-pink); color: var(--primary-pink); background: #fff5f8; }

        .qty-picker { display: flex; align-items: center; border: 1px solid #ddd; width: fit-content; border-radius: 4px; }
        .qty-picker button { border: none; background: none; padding: 10px 15px; font-size: 1.2rem; }
        .qty-picker input { border: none; width: 50px; text-align: center; font-weight: 600; outline: none; }

        .btn-action { padding: 15px; border-radius: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; border: none; width: 100%; margin-top: 10px; transition: 0.3s; }
        .btn-cart { background-color: var(--primary-pink); color: #white; }
        .btn-buy { background-color: var(--dark-blue); color: #fff; }
        .btn-action:hover { opacity: 0.9; transform: translateY(-1px); }

        .product-desc { color: #666; line-height: 1.6; margin: 25px 0; font-size: 1rem; }
        
        .meta-list { margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; }
        .meta-item { display: flex; margin-bottom: 8px; font-size: 0.9rem; }
        .meta-label { font-weight: 700; width: 120px; color: #333; }

        
        @media (min-width: 768px) {
            .product-main { width:500px; height:500px; background:#fff; border-radius:12px; padding:10px; border:1px solid #e5e7eb; }
            .product-main .carousel, .product-main .carousel-inner, .product-main .carousel-item { height:480px; }
            .product-main .product-image { width:100%; height:100%; object-fit:contain; display:block; margin:0 auto; }
            .thumbs { min-width:120px; max-height:520px; overflow-y:auto; padding-right:6px; }
            .thumbs .thumb-box { width:90px; height:90px; margin-bottom:8px; }
            .thumbs::-webkit-scrollbar { width:8px; }
            .thumbs::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius:4px; }
        }

        @media (max-width: 767px) {
            .image-area { flex-direction: column; }
            .thumbs { width:100%; display:flex; flex-direction:row; gap:0.5rem; overflow-x:auto; padding:0.5rem 0; }
            .thumbs .thumb-box { width:70px; height:70px; flex:0 0 auto; }
            .product-main { width:100%; height:auto; max-height:350px; }
        }

        @media (min-width: 768px) {
        .product-main { 
            width: <?= $productWidth ?>px; 
            height: <?= $productHeight ?>px; 
            background: #fff; 
            border-radius: 12px; 
            padding: 0; /* Changed from 10px to 0 to remove inner margin */
            border: 1px solid #e5e7eb; 
            overflow: hidden; /* Ensures image doesn't bleed over rounded corners */
        }
        
        .product-main .carousel, 
        .product-main .carousel-inner, 
        .product-main .carousel-item { 
            height: 100%; 
            width: 100%;
        }

        .product-main .product-image-responsive { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; /* Changed from contain to cover to touch all edges */
            display: block;
        }
    }

    
  .thumbs-container {
    display: flex;
    gap: 8px;           /* Space between each small image */
    margin-top: 12px;    /* Space between main image and thumbnails */
    padding: 5px 0;
    justify-content: center; /* Align thumbnails to the center */
    overflow-x: auto;   
    scrollbar-width: none; /* Allow scrolling if there are many images */
  }

  .thumb-box { 
    cursor: pointer; 
    border: 2px solid #e5e7eb;
    width: 40px; 
    height: 40px; 
    overflow: hidden;
    transition: 0.2s ease;
    flex-shrink: 0;
    background: #fff;
  }
  .thumbs-container::-webkit-scrollbar { display: none; }
  .thumb-box img { width: 100%; height: 100%; object-fit: cover; }
  .thumb-box.active, .thumb-box.selected {
    border-color: var(--primary-pink); /* Pink border when selected */
    transform: translateY(-2px);       /* Slight "pop" effect */
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
  }
  .scrollbar-hide::-webkit-scrollbar { display: none; }
  .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

  /* Ensure the main container doesn't overflow */
  .product-container {
      width: 100%;
      padding: 10px;
      overflow-x: hidden;
  }

  .carousel-item img {
      width: 100%;
      height: auto;
      aspect-ratio: 1 / 1;
      object-fit: contain; 
      background: #fff;
  }
/* Override fixed PHP widths on mobile */
@media (max-width: 767px) {
    #productCarousel, 
    .carousel-inner, 
    .carousel-item img, 
    .main-img-holder {
        width: 100% !important;
        height: auto !important; /* Let the aspect ratio be natural */
        max-height: 400px;
    }

    .product-title {
        font-size: 1.5rem; /* Smaller text for mobile */
        margin-top: 15px;
    }
    .variant-box { flex: 0 0 auto; min-width: 80px; max-width: calc(50% - 8px); text-align: center; padding: 10px 12px !important; box-sizing: border-box; }
    
}

.thumbs-container {
    -webkit-overflow-scrolling: touch;
    justify-content: flex-start; /* Better for horizontal scrolling */
    padding-left: 10px;
}

.gradient-btn {
  margin-top: auto;
  height: 65px;
  padding: 14px;
  width: 100%;
  border: none;
  background: linear-gradient(135deg, #000000, #000000);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.3s ease;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}

.gradient-btn:hover {
    transform: translateY(-2px);
    background: linear-gradient(135deg, #000000, #000000);
}

/* ── Mobile: proper gap between Add to Cart and Buy Now ── */
@media (max-width: 768px) {
    .gradient-btn + .gradient-btn,
    .gradient-btn.mt-3 {
        margin-top: 14px !important;
    }
}

</style>
</head>

<body>
<?php require_once $_SERVER["DOCUMENT_ROOT"] . "/includes/header.php"; ?>
<main class="zara-product-container">
    <!-- LEFT COLUMN -->
    <div class="zara-product-left">
        <h4>MATERIALS, CARE AND ORIGIN</h4>
        <h3>MATERIALS</h3>
        <p>
            We work with monitoring programmes to ensure compliance with safety,
            health and quality standards for our products.
        </p>
        <p style="margin-top:2rem;">
            <?= nl2br(htmlspecialchars($product['description'] ?? '')); ?>
        </p>
    </div>

    <!-- CENTER COLUMN: IMAGES -->
    <div class="zara-product-center">
        <?php foreach($images as $imgEntry): ?>
            <img src="<?= htmlspecialchars($imgEntry['src'], ENT_QUOTES, 'UTF-8') ?>" alt="Product image">
        <?php endforeach; ?>
    </div>

    <!-- RIGHT COLUMN: DETAILS -->
    <div class="zara-product-right">
        <h1 class="zara-product-title"><?= htmlspecialchars($product['name']); ?></h1>
        <p class="zara-product-price">
            <span id="sellPrice">₹<?= number_format($simpleDiscountedPrice); ?></span>
            <?php if($discountRate > 0): ?>
                <span id="oldPrice" style="text-decoration:line-through; font-size:0.8em; color:#999; margin-left: 10px;">₹<?= number_format($grossPrice); ?></span>
            <?php endif; ?>
        </p>
        
        <input id="qtyInput" type="hidden" value="1" min="1">
        
        <!-- Variants -->
        <?php if (!empty($variants)): ?>
            <div class="zara-product-variants">
                <?php foreach ($variants as $index => $v): ?>
                    <button class="zara-variant-btn <?= $index === 0 ? 'active' : '' ?>"
                        data-id="<?= $v['id'] ?>"
                        data-price="<?= floatval($v['price'] ?? 0) ?>"
                        data-old-price="<?= floatval($v['old_price'] ?? 0) ?>"
                        data-discount="<?= floatval($v['discount'] ?? 0) ?>"
                        data-stock="<?= $v['stock'] ?>"
                        data-weight-value="<?= htmlspecialchars($v['weight_value']) ?>"
                        data-weight-unit="<?= htmlspecialchars($v['weight_unit']) ?>"
                        onclick="updateVariant(this)">
                        <?= (int)$v['weight_value'] . htmlspecialchars($v['weight_unit']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="zara-product-actions">
            <button class="zara-add-btn" style="width:100%" onclick="sendToCart()">ADD TO BASKET</button>
        </div>
        
        <div style="margin-top: 2rem;">
             <span id="stockText" style="font-size: 0.75rem; font-weight:700; color: <?= $stockQty > 0 ? '#000000' : '#dc2626' ?>">
                 <?= $stockQty > 0 ? 'AVAILABLE' : 'OUT OF STOCK' ?>
             </span>
        </div>
    </div>
</main>

    <div class="mt-1">
        <?php 
            $fields = array_filter([
                'Nutritional Info' => $product['nutrition'] ?? null,
                'Origin'           => $product['origin'] ?? null, 
                'Grade'            => $product['grade'] ?? null, 
                'Packaging Type'   => $product['packaging_type'] ?? null,
                'Product Form'     => $product['product_form'] ?? null,
                'Purity'           => $product['purity'] ?? null,
                'Flavor'           => $product['flavor'] ?? null, 
                'Shelf Life'       => $product['shelf_life'] ?? null, 
                'Storage'          => $product['storage_instructions'] ?? null, 
                'Expiry'           => $product['expiry_info'] ?? null
                ]);
                ?>

<?php if(!empty($fields)): ?>
    <div class="p-8 bg-gray-50 border-t border-gray-100">
        <h2 class="text-lg font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-list-ul text-black text-sm"></i> Product Details
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
            <?php foreach($fields as $label => $val): ?>
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-tighter"><?= $label ?></span>
                    <span class="text-sm font-semibold text-gray-700"> <?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); ?> </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════ -->
    <!--  REVIEWS SECTION                                              -->
    <!-- ══════════════════════════════════════════════════════════════ -->
    <div id="review-section" class="p-6 md:p-8 border-t border-gray-100 bg-white">

        <!-- Section Heading -->
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="#FFCC00" style="filter:drop-shadow(0 0 3px rgba(255,200,0,0.6));">
                <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
            </svg>
            Customer Reviews
        </h2>

        <!-- Rating summary -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-6 mb-8">
            <div class="text-center">
                <div class="text-5xl font-black text-gray-800"><?= number_format($finalRating, 1) ?></div>
                <div class="flex justify-center gap-0.5 my-1">
                    <?php
                    $f = floor($finalRating); $h = ($finalRating - $f) >= 0.3; $e = 5 - $f - ($h ? 1 : 0);
                    for ($s=0;$s<$f;$s++) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="#FFCC00" style="filter:drop-shadow(0 0 2px rgba(255,200,0,0.5));"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
                    if ($h) echo '<svg width="20" height="20" viewBox="0 0 24 24"><defs><linearGradient id="hg2"><stop offset="50%" stop-color="#FFCC00"/><stop offset="50%" stop-color="#d1d5db"/></linearGradient></defs><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" fill="url(#hg2)"/></svg>';
                    for ($s=0;$s<$e;$s++) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="#e5e7eb"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
                    ?>
                </div>
                <div class="text-xs text-gray-400"><?= $totalReviews ?> <?= $totalReviews === 1 ? 'review' : 'reviews' ?></div>
            </div>

            <!-- Star distribution bars -->
            <!-- <div class="flex-1 space-y-1 w-full max-w-xs">
                <?php
                // Count per star from DB
                $starCountStmt = $conn->prepare("SELECT stars, COUNT(*) as cnt FROM item_reviews WHERE item_id=? GROUP BY stars");
                $starCountStmt->execute([$id]);
                $starCounts = array_column($starCountStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'stars');
                for ($st = 5; $st >= 1; $st--):
                    $cnt = (int)($starCounts[$st] ?? 0);
                    $pct = $totalReviews > 0 ? round($cnt / $totalReviews * 100) : 0;
                ?>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-10 text-right text-gray-500"><?= $st ?> star</span>
                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                        <div class="bg-amber-400 h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="w-8 text-gray-400"><?= $pct ?>%</span>
                </div>
                <?php endfor; ?>
            </div> -->
        </div>

        <?php if (!empty($reviewMsg)): ?>
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium <?= $reviewMsgType === 'success' ? 'bg-gray-100 text-black border border-black' : 'bg-red-50 text-red-700 border border-red-200' ?>">
            <?= htmlspecialchars($reviewMsg) ?>
        </div>
        <?php endif; ?>

        <!-- Write a Review form -->
        <?php if ($isLoggedIn && !$userAlreadyReviewed): ?>
        <div class="mb-8 p-5 bg-gray-50 border border-gray-200 rounded-xl">
            <h3 class="text-base font-bold text-gray-800 mb-4">✏️ Write a Review</h3>
            <form method="POST">
                <!-- Star selector -->
                <div class="flex items-center gap-1 mb-4" id="starSelector">
                    <?php for ($st = 1; $st <= 5; $st++): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="stars" value="<?= $st ?>" class="hidden" <?= $st === 5 ? 'checked' : '' ?>>
                        <svg class="star-pick w-8 h-8 transition-colors" data-val="<?= $st ?>"
                             viewBox="0 0 24 24" fill="#e5e7eb">
                            <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                        </svg>
                    </label>
                    <?php endfor; ?>
                    <span id="starLabel" class="ml-2 text-sm font-semibold text-amber-600">5 stars</span>
                </div>
                <textarea name="review_text" rows="3" placeholder="Share your experience (optional)…"
                          class="w-full border border-gray-200 rounded-lg p-3 text-sm outline-none focus:ring-2 focus:ring-indigo-300 resize-none mb-3"></textarea>
                <button type="submit" name="submit_review"
                        class="px-6 py-2 rounded-lg text-white text-sm font-bold"
                        style="background:var(--lux-black);">
                    Submit Review
                </button>
            </form>
        </div>
        <?php elseif (!$isLoggedIn): ?>
        <div class="mb-8 p-4 bg-indigo-50 border border-indigo-200 rounded-xl text-sm text-indigo-700">
            <a href="/login.php" class="font-bold underline">Login</a> to write a review for this product.
        </div>
        <?php endif; ?>

        <!-- Existing reviews list -->
        <?php if (!empty($reviews)): ?>
        <h3 class="text-base font-bold text-gray-800 mb-4">Customer Reviews</h3>
        <div class="space-y-4">
            <?php foreach ($reviews as $rv):
                // Display name: use the part of the email before @ symbol
                $displayName = htmlspecialchars(
                    strstr($rv['user_email'], '@', true) ?: ($rv['user_name'] ?? 'User')
                );
            ?>
            <div class="border border-gray-100 rounded-xl p-4 bg-white shadow-sm">
                <div class="flex items-center gap-2 mb-1">
                    <div class="flex gap-0.5">
                        <?php for ($s=1;$s<=5;$s++): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="<?= $s <= $rv['stars'] ? '#FFCC00' : '#e5e7eb' ?>">
                            <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                        </svg>
                        <?php endfor; ?>
                    </div>
                    <span class="text-xs font-bold text-gray-700"><?= $displayName ?></span>
                    <span class="text-xs text-gray-400 ml-auto"><?= date('d M Y', strtotime($rv['created_at'])) ?></span>
                </div>
                <?php if (!empty($rv['review_text'])): ?>
                <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($rv['review_text']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /review-section -->
    </div>
</div>
</div>
</div>

<?php require_once $_SERVER["DOCUMENT_ROOT"] ."/includes/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // 1. Initial Product Data from PHP
    const PRODUCT_DATA = {
        id: <?= (int)$id; ?>,
        name: "<?= addslashes($product['name']); ?>",
        oldamt: <?= (float)$grossPrice; ?>,
        discountRate: <?= (float)$discountRate; ?>,
        price: <?= (float)$simpleDiscountedPrice; ?>,
        image: "<?= $mainImageSrc; ?>",
        variant_id: <?= (int)($variants[0]['id'] ?? 0); ?>,
        variant_price: <?= (float)$simpleDiscountedPrice; ?>,
        variant_old_price: <?= (float)$grossPrice; ?>,
        variant_discount: <?= (float)$discountRate; ?>,
        variant_weight: "<?= htmlspecialchars($variants[0]['weight_value'] ?? '') ?>",
        variant_unit: "<?= htmlspecialchars($variants[0]['weight_unit'] ?? '') ?>"
    };

    function adjust(val) {
        let qtyInput = document.getElementById("qtyInput") || document.getElementById("qty");
        let displayNum = document.getElementById("qtyNumber");
        
        let currentQty = parseInt(qtyInput.value) || 1;
        let newQty = Math.max(1, currentQty + val);
        
        qtyInput.value = newQty;
        if (displayNum) displayNum.innerText = newQty;
        
        updateDisplayPrice(); 
    } 
    /**
     * Updates all price-related elements on the page
     */
function updateDisplayPrice() {
    let qtyInput = document.getElementById("qtyInput") || document.getElementById("qty");
    const qty          = parseInt(qtyInput.value) || 1;
    const sellPrice    = PRODUCT_DATA.price;
    const oldPrice     = PRODUCT_DATA.oldamt;
    const discountRate = PRODUCT_DATA.discountRate;
    // saveAmount is always per-unit (static, never multiplied by qty)
    const saveAmount   = oldPrice > sellPrice ? (oldPrice - sellPrice) : 0;

    const sellEl        = document.getElementById('sellPrice');
    const displayTotalEl= document.getElementById('displayTotal');
    const oldPriceEl    = document.getElementById('oldPrice');
    const saveEl        = document.getElementById('saveAmount');
    const discBadgeEl   = document.getElementById('discBadge');
    const discBlockEl   = document.getElementById('discountBlock');

    // Selling price: always show per-unit price (static)
    if (sellEl)
        sellEl.innerText = '₹' + Math.round(sellPrice).toLocaleString();

    // Total amount: the ONLY value that changes with qty
    if (displayTotalEl)
        displayTotalEl.innerText = Math.round(sellPrice * qty).toLocaleString();

    // Old price: always per-unit (static)
    if (oldPriceEl) {
        if (discountRate > 0 && oldPrice > sellPrice) {
            oldPriceEl.innerText = '₹' + Math.round(oldPrice).toLocaleString();
            oldPriceEl.style.display = 'inline';
        } else {
            oldPriceEl.style.display = 'none';
        }
    }

    // Save amount: always per-unit (static)
    if (saveEl) {
        if (saveAmount > 0) {
            saveEl.innerText = 'SAVE ₹' + Math.round(saveAmount).toLocaleString();
            saveEl.style.display = 'inline';
        } else {
            saveEl.style.display = 'none';
        }
    }

    // Discount % badge: static (does not depend on qty)
    if (discBadgeEl) {
        if (discountRate > 0) {
            discBadgeEl.textContent = Math.floor(discountRate) + '% OFF';
            discBadgeEl.style.display = 'inline';
        } else {
            discBadgeEl.style.display = 'none';
        }
    }
    // Show/hide the entire discount block
    if (discBlockEl) {
        discBlockEl.style.display = discountRate > 0 ? 'flex' : 'none';
    }
}

    function updateVariant(el) {
        // Remove active class from all variant boxes
        document.querySelectorAll('.zara-variant-btn').forEach(b => b.classList.remove('active', 'bg-black', 'text-white', 'border-black', 'bg-gray-100'));
        el.classList.add('active');

        const selPrice    = parseFloat(el.dataset.price    || 0);
        var   selOldPrice = parseFloat(el.dataset.oldPrice || 0);
        const selDiscount = parseFloat(el.dataset.discount || 0);

        // Derive old_price when missing but discount% is stored in DB
        if (selOldPrice <= 0 && selDiscount > 0 && selDiscount < 100 && selPrice > 0) {
            selOldPrice = selPrice / (1 - selDiscount / 100);
        }

        // Compute display discount% from actual prices (floor, non-negative)
        let computedDiscount = 0;
        if (selOldPrice > selPrice && selOldPrice > 0) {
            computedDiscount = Math.floor(((selOldPrice - selPrice) / selOldPrice) * 100);
        }

        // Update PRODUCT_DATA
        PRODUCT_DATA.variant_id    = parseInt(el.dataset.id);
        PRODUCT_DATA.variant_price = selPrice;
        PRODUCT_DATA.price         = selPrice;
        PRODUCT_DATA.oldamt        = selOldPrice > 0 ? selOldPrice : selPrice;
        PRODUCT_DATA.variant_old_price = selOldPrice;
        PRODUCT_DATA.variant_discount  = selDiscount;
        PRODUCT_DATA.discountRate      = computedDiscount;
        PRODUCT_DATA.variant_weight    = el.dataset.weightValue;
        PRODUCT_DATA.variant_unit      = el.dataset.weightUnit;

        // Update stock UI
        const stock = parseInt(el.dataset.stock || 0);
        const stockText = document.getElementById('stockText');
        if (stockText) {
            stockText.innerText = stock > 0 ? '● Available' : '● Out of stock';
            stockText.style.color = stock > 0 ? '#000000' : '#dc2626';
        }

        updateDisplayPrice();
    }

    function sendToCart() {
    const qtyInput = document.getElementById("qtyInput") || document.getElementById("qty");
    const quantity = parseInt(qtyInput.value) || 1;
    
    // Safety check: Don't add if out of stock
    const stockText = document.getElementById('stockText');
        if (stockText && stockText.innerText.includes('Out of stock')) {
            alert('This item is currently out of stock.');
            return;
        }

        if (typeof addToCart === "function") {
            addToCart({ ...PRODUCT_DATA, quantity: quantity });
            openCartPanel();
        }
    }


    // Listener for manual typing in quantity field
    if(document.getElementById("qtyInput")) {
        document.getElementById("qtyInput").addEventListener('input', updateDisplayPrice);
    }  
    
    function changeQty(delta) {
        const input = document.getElementById("qtyInput");
        const displayNum = document.getElementById("qtyNumber");
        
        if (!input) return;

        let currentQty = parseInt(input.value) || 1;
        let newQty = Math.max(1, currentQty + delta);
        
        input.value = newQty;
        
        if (displayNum) {
            displayNum.innerText = newQty;
            // Pop animation
            displayNum.style.transition = "transform 0.1s ease";
            displayNum.style.transform = "scale(1.2)";
            setTimeout(() => displayNum.style.transform = "scale(1)", 100);
        }
        
        // Refresh total price display
        updateDisplayPrice(); 
    }

    // Initialize logic when the page is ready
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Manual typing listener
        const qtyInput = document.getElementById("qtyInput");
        if(qtyInput) {
            qtyInput.addEventListener('input', updateDisplayPrice);
        }

        // 2. Carousel & Thumbnail Sync (Combined into one clean block)
        const carouselEl = document.getElementById('productCarousel');
        if (carouselEl) {
            const bsCarousel = new bootstrap.Carousel(carouselEl, { interval: false });
            const thumbs = document.querySelectorAll('#thumbsColumn .thumb-box');

            thumbs.forEach((t, i) => {
                t.addEventListener('click', () => {
                    // Move Carousel
                    bsCarousel.to(i);
                    
                    // Update Thumbnail Borders
                    thumbs.forEach(x => {
                        x.classList.remove('selected', 'border-black');
                        x.classList.add('border-gray-200');
                    });
                    t.classList.add('selected', 'border-black');
                    t.classList.remove('border-gray-200');
                });
            });
        }
    });

    function buyNow() {
        const qtyInput = document.getElementById("qtyInput");
        const quantity = parseInt(qtyInput?.value || 1);

        if (!PRODUCT_DATA.variant_id || !PRODUCT_DATA.id) {
            alert("Please select a valid product variant.");
            return;
        }

        // Check stock
        const stockText = document.getElementById('stockText');
        if (stockText && stockText.innerText.includes('Out of stock')) {
            alert('This item is currently out of stock.');
            return;
        }

        // Replace cart with only this item so checkout sees the correct price
        // Buy Now flow: set just this product in cart, then go straight to checkout.
        const buyNowCart = [{
            ...PRODUCT_DATA,
            quantity: quantity,
            qty:      quantity
        }];
        localStorage.setItem('cart', JSON.stringify(buyNowCart));
        updateCartCount();

        // Redirect straight to checkout
        window.location.href = '/add_delivery_address.php';
    }

</script>
<script>
var productName = "<?= htmlspecialchars($product['name']); ?>";
var productLink = "https://rgreenmart.com/product.php?id=<?= $id ?>";
var productImage = "https://rgreenmart.com<?= $mainImageSrc ?>";

// ── WhatsApp ──────────────────────────────────────────────
var waMessage = "*" + productName + "*\n" + productLink;
document.getElementById("whatsappShare").href =
    "https://wa.me/?text=" + encodeURIComponent(waMessage);

// ── Facebook ──────────────────────────────────────────────
document.getElementById("facebookShare").href =
    "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(productLink);

// ── Share panel toggle ────────────────────────────────────
function toggleSharePanel() {
    var panel = document.getElementById("sharePanel");
    panel.style.display = (panel.style.display === "none" || panel.style.display === "") ? "block" : "none";
}

// Close when clicking outside the share wrapper
document.addEventListener("click", function(e) {
    var wrapper = document.getElementById("shareWrapper");
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById("sharePanel").style.display = "none";
    }
});

// ── Instagram: copy product link to clipboard ─────────────
function copyForInstagram() {
    var lbl = document.getElementById("igBtnLabel");
    if (navigator.clipboard) {
        navigator.clipboard.writeText(productLink).then(function() {
            lbl.textContent = "Link copied!";
            setTimeout(function() { lbl.textContent = "Copy link for Instagram"; }, 2500);
        });
    } else {
        var ta = document.createElement("textarea");
        ta.value = productLink;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
        lbl.textContent = "Link copied!";
        setTimeout(function() { lbl.textContent = "Copy link for Instagram"; }, 2500);
    }
}
</script>


<!-- ░░░ VARIANT MODAL BACKDROP ░░░ -->
<div id="vm-backdrop" onclick="closeVariantModal()" style="
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.50);z-index:9100;
  backdrop-filter:blur(3px);
"></div>

<!-- ░░░ VARIANT MODAL ░░░ -->
<div id="vm-modal" style="
  display:none;position:fixed;bottom:0;left:50%;
  transform:translateX(-50%) translateY(100%);
  width:100%;max-width:480px;
  background:#fff;z-index:9101;
  border-radius:20px 20px 0 0;
  padding:0 0 24px;
  box-shadow:0 -12px 48px rgba(0,0,0,.16);
  transition:transform .34s cubic-bezier(.4,0,.2,1);
  font-family:var(--lux-font-sans);
">
  <div style="text-align:center;padding:14px 0 2px;">
    <span style="display:inline-block;width:40px;height:4px;background:#e5e7eb;border-radius:99px;"></span>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px 12px;">
    <span style="font-size:16px;font-weight:700;color:#000000;">Select Variant</span>
    <button onclick="closeVariantModal()" style="
      background:#f3f4f6;border:none;width:32px;height:32px;
      border-radius:50%;cursor:pointer;font-size:15px;color:#555;
      display:flex;align-items:center;justify-content:center;line-height:1;
    ">&#10005;</button>
  </div>

  <!-- product preview strip -->
  <div style="
    display:flex;align-items:center;gap:12px;
    padding:10px 20px;background:#eaeaea;
    border-top:1px solid #eaeaea;border-bottom:1px solid #eaeaea;margin-bottom:16px;
  ">
    <img id="vm-img" src="" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid #eaeaea;flex-shrink:0;">
    <div>
      <div id="vm-name" style="font-size:13px;font-weight:700;color:#000000;margin-bottom:3px;line-height:1.3;"></div>
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <span id="vm-old-price" style="font-size:12px;color:#9ca3af;text-decoration:line-through;display:none;"></span>
        <span id="vm-price"     style="font-size:15px;font-weight:700;color:#000000;"></span>
        <span id="vm-disc"      style="display:none;font-size:11px;font-weight:700;color:#fff;background:#000000;border-radius:4px;padding:1px 6px;"></span>
      </div>
    </div>
  </div>

  <div style="padding:0 20px;">
    <p style="font-size:11px;font-weight:700;color:#9ca3af;letter-spacing:.6px;text-transform:uppercase;margin:0 0 10px;">Choose Weight / Size</p>

    <!-- variant chips — each chip shows weight AND price -->
    <div id="vm-chips" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;"></div>

    <!-- qty row -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
      <span style="font-size:13px;font-weight:600;color:#475569;">Quantity:</span>
      <div style="display:flex;align-items:center;border:2px solid #000000;border-radius:8px;overflow:hidden;">
        <button onclick="vmQtyChange(-1)" class="vmqbtn">&#8722;</button>
        <span id="vm-qty-val" style="width:36px;text-align:center;font-size:14px;font-weight:700;color:#1f2937;">1</span>
        <button onclick="vmQtyChange(1)"  class="vmqbtn">+</button>
      </div>
    </div>

    <button id="vm-add-btn" onclick="vmConfirm()" disabled style="
      width:100%;padding:14px;border:none;border-radius:10px;
      background:#d1d5db;color:#fff;font-size:15px;font-weight:700;
      cursor:not-allowed;transition:all .2s;letter-spacing:.3px;
      font-family:var(--lux-font-sans);
    ">ADD TO BASKET</button>
  </div>
</div>


<!-- ░░░ CART PANEL OVERLAY ░░░ -->
<div id="cp-overlay" onclick="closeCartPanel()" style="
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.36);z-index:8100;
  backdrop-filter:blur(2px);
"></div>

<!-- ░░░ CART PANEL ░░░ -->
<div id="cart-panel" style="
  position:fixed;top:0;right:0;height:100vh;
  width:420px;max-width:100vw;
  background:#fff;z-index:8101;
  transform:translateX(105%);
  transition:transform .36s cubic-bezier(.4,0,.2,1);
  display:flex;flex-direction:column;
  box-shadow:-6px 0 40px rgba(0,0,0,.13);
  font-family:var(--lux-font-sans);
">
  <!-- header -->
  <div style="
    padding:16px 18px;
    display:flex;align-items:center;justify-content:space-between;
    background: linear-gradient(135deg, #000000 0%, #000000 100%);
    color:#fff;flex-shrink:0;
  ">
    <div style="display:flex;align-items:center;gap:9px;">
      <i class="fas fa-shopping-cart" style="font-size:17px;"></i>
      <span style="font-size:16px;font-weight:700;">Your Cart</span>
      <span id="cp-badge" style="
        background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.35);
        border-radius:20px;padding:0 10px;font-size:13px;font-weight:700;line-height:22px;
      ">0</span>
    </div>
    <button onclick="closeCartPanel()" style="
      background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);
      color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;
      font-size:16px;display:flex;align-items:center;justify-content:center;
      transition:background .2s;
    " onmouseover="this.style.background='rgba(255,255,255,.28)'"
       onmouseout="this.style.background='rgba(255,255,255,.15)'">&#10005;</button>
  </div>

  <!-- scrollable items list -->
  <div id="cp-items" style="flex:1;overflow-y:auto;padding:12px 12px 4px;background:#f9fafb;"></div>

  <!-- order summary footer -->
  <div style="border-top:2px solid #e5e7eb;background:#fff;flex-shrink:0;padding:14px 18px 18px;">
    <div style="font-size:15px;font-weight:700;color:#000000;text-align:center;margin-bottom:11px;">Order Summary</div>

    <div class="cprow"><span>Total Items</span><strong id="cp-ti">0</strong></div>
    <div class="cprow"><span>Total Quantity</span><strong id="cp-tq">0</strong></div>
    <div class="cprow" style="border-bottom:none;padding-bottom:12px;">
      <span style="font-size:14px;font-weight:700;color:#1f2937;">Total Amount</span>
      <strong id="cp-gt" style="font-size:17px;color:#000000;font-weight:800;">&#8377;0.00</strong>
    </div>

    <!-- Checkout — PHP session check -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <button onclick="window.location.href='add_delivery_address.php'" class="cp-checkout-btn">
      PROCEED TO CHECKOUT &#8594;
    </button>
    <?php else: ?>
    <button onclick="window.location.href='login.php'" class="cp-checkout-btn">
      LOGIN TO CHECKOUT &#8594;
    </button>
    <?php endif; ?>

    <button onclick="window.location.href='viewcart.php'" class="cp-viewcart-btn">
      VIEW FULL CART
    </button>
  </div>
</div>


<!-- ░░░ CSS ░░░ -->
<style>
/* font: matches project body (Arial, sans-serif) */

/* variant modal chip */
.vm-chip {
  padding:8px 14px;border:2px solid #e5e7eb;border-radius:8px;
  cursor:pointer;background:#fff;transition:all .14s;
  font-family:var(--lux-font-sans);text-align:left;
}
.vm-chip:hover { border-color:#000000;color:#000000; }
.vm-chip.active {
  border-color:#000000;background:#eaeaea;color:#000000;
  box-shadow:0 0 0 3px rgba(5,150,105,.14);
}
.vm-chip-weight { font-size:13px;font-weight:700;color:inherit;display:block;line-height:1.2; }
.vm-chip-price  { font-size:12px;color:#000000;display:block;margin-top:2px;font-weight:600; }
.vm-chip.active .vm-chip-price { color:#000000; }

/* variant modal qty buttons */
.vmqbtn {
  background:#eaeaea;border:none;width:32px;height:32px;cursor:pointer;
  font-size:16px;color:#000000;font-weight:700;
  display:flex;align-items:center;justify-content:center;transition:background .13s;
}
.vmqbtn:hover { background:#eaeaea; }

/* cart panel summary rows */
.cprow {
  display:flex;justify-content:space-between;align-items:center;
  padding:7px 0;border-bottom:1px solid #f3f4f6;
  font-size:14px;color:#6b7280;font-family:var(--lux-font-sans);
}

/* checkout button — matches project .checkout-btn */
.cp-checkout-btn {
  width:100%;padding:13px;border:none;border-radius:4px;cursor:pointer;
  background:var(--lux-black);
  color:#fff;font-size:15px;font-weight:600;letter-spacing:.2px;
  transition:all .25s;font-family:var(--lux-font-sans);
}
.cp-checkout-btn:hover {
  background:linear-gradient(135deg,#000000,#000000);
  transform:translateY(-2px);
}
.cp-checkout-btn:active { transform:scale(0.97); }

.cp-viewcart-btn {
  width:100%;padding:10px;margin-top:7px;
  border:2px solid #000000;border-radius:4px;
  background:#fff;color:#000000;font-size:14px;font-weight:600;
  cursor:pointer;transition:background .18s;font-family:var(--lux-font-sans);
}
.cp-viewcart-btn:hover { background:#eaeaea; }

/* cart item card */
.cp-card {
  background:#fff;border-radius:10px;padding:11px 12px;
  margin-bottom:9px;display:flex;gap:11px;align-items:flex-start;
  border:1px solid #e5e7eb;
  box-shadow:0 1px 6px rgba(0,0,0,.04);transition:box-shadow .18s;
}
.cp-card:hover { box-shadow:0 3px 14px rgba(0,0,0,.08); }
.cp-card img {
  width:68px;height:68px;border-radius:7px;object-fit:cover;
  border:1px solid #eaeaea;flex-shrink:0;
}
.cp-card-body { flex:1;min-width:0; }
.cp-card-name {
  font-size:13px;font-weight:700;color:#1f2937;margin-bottom:2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.cp-variant-tag { font-size:11px;color:#6b7280;margin-bottom:4px; }
.cp-prices { display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:8px; }
.cp-fp  { font-size:14px;font-weight:700;color:#000000; }
.cp-op  { font-size:12px;color:#d1d5db;text-decoration:line-through; }
.cp-dc  { font-size:10px;font-weight:700;color:#000000;background:#eaeaea;border-radius:4px;padding:1px 5px; }
.cp-bot { display:flex;align-items:center;justify-content:space-between; }
.cp-qwrap {
  display:flex;align-items:center;
  border:1.5px solid #000000;border-radius:7px;overflow:hidden;
}
.cpqb {
  background:#eaeaea;border:none;width:27px;height:27px;cursor:pointer;
  font-size:13px;color:#000000;font-weight:700;
  display:flex;align-items:center;justify-content:center;transition:background .13s;
}
.cpqb:hover { background:#eaeaea; }
.cpqi {
  width:30px;text-align:center;font-size:13px;font-weight:700;
  color:#1f2937;border:none;border-left:1.5px solid #000000;
  border-right:1.5px solid #000000;height:27px;background:#fff;outline:none;
  font-family:var(--lux-font-sans);
}
.cp-line-total { font-size:13px;font-weight:700;color:#374151; }
.cp-rm {
  background:none;border:none;color:#dc2626;font-size:12px;
  cursor:pointer;padding:2px 5px;border-radius:4px;transition:background .13s;
  margin-top:4px;font-family:var(--lux-font-sans);
}
.cp-rm:hover { background:#fee2e2; }

/* empty state */
.cp-empty {
  text-align:center;padding:60px 20px;
  font-family:var(--lux-font-sans);
}
.cp-empty i   { font-size:50px;color:#eaeaea;display:block;margin-bottom:12px; }
.cp-empty p   { font-size:15px;font-weight:700;color:#6b7280;margin-bottom:4px; }
.cp-empty small { font-size:12px;color:#9ca3af; }

/* scrollbar */
#cp-items::-webkit-scrollbar { width:4px; }
#cp-items::-webkit-scrollbar-track { background:#f9fafb; }
#cp-items::-webkit-scrollbar-thumb { background:#eaeaea;border-radius:4px; }

@media (max-width:480px) {
  #cart-panel { width:100vw !important; }
  #vm-modal   { max-width:100vw; }
}
</style>


<!-- ░░░ JAVASCRIPT ░░░ -->
<script>
/* ─── VARIANT MODAL ─────────────────────────────── */
var _vmProd = null, _vmVars = [], _vmSel = null, _vmQty = 1;

/**
 * Called by "Add to Cart" on index.php product cards.
 * Fetches variants; if >1 shows modal, otherwise adds directly.
 */
function saveToCart(product) {
  fetch('/api/get_variants.php?item_id=' + encodeURIComponent(product.id))
    .then(function(r){ return r.json(); })
    .then(function(vars){
      if (vars && vars.length > 1) {
        openVarModal(product, vars);
      } else {
        if (vars && vars.length === 1) {
          var v = vars[0];
          product.variant_id        = v.id;
          product.variant_weight    = v.weight_value;
          product.variant_unit      = v.weight_unit;
          product.variant_price     = Number(v.price     || 0);
          product.variant_old_price = Number(v.old_price || 0);
          product.variant_discount  = Number(v.discount  || 0);
        }
        product.quantity = 1;
        addToCart(product);
        openCartPanel();
      }
    })
    .catch(function(){
      product.quantity = 1;
      addToCart(product);
      openCartPanel();
    });
}

function openVarModal(product, vars) {
  _vmProd = product; _vmVars = vars; _vmSel = null; _vmQty = 1;
  document.getElementById('vm-qty-val').textContent = '1';
  document.getElementById('vm-img').src = product.image || '/images/default.jpg';
  document.getElementById('vm-name').textContent = product.name;

  /* cheapest variant shown in preview */
  var cheap = vars.slice().sort(function(a,b){ return Number(a.price)-Number(b.price); })[0];
  _vmRefPrice(cheap);

  /* build chips — weight label + price label */
  var el = document.getElementById('vm-chips');
  el.innerHTML = '';
  vars.forEach(function(v){
    var wLabel = [v.weight_value, v.weight_unit].filter(Boolean).join(' ') || 'Option';
    var pLabel = '&#8377;' + Number(v.price).toFixed(0);
    var chip = document.createElement('button');
    chip.className = 'vm-chip';
    chip.innerHTML = '<span class="vm-chip-weight">' + wLabel + '</span>'
                   + '<span class="vm-chip-price">'  + pLabel + '</span>';
    chip.onclick = (function(cv, cel){ return function(){ _vmSelect(cel, cv); }; })(v, chip);
    el.appendChild(chip);
  });

  _vmBtnState(false);
  document.getElementById('vm-backdrop').style.display = 'block';
  var modal = document.getElementById('vm-modal');
  modal.style.display = 'block';
  requestAnimationFrame(function(){
    modal.style.transform = 'translateX(-50%) translateY(0)';
  });
}

function _vmSelect(chip, v) {
  document.querySelectorAll('.vm-chip').forEach(function(c){ c.classList.remove('active'); });
  chip.classList.add('active');
  _vmSel = v;
  _vmRefPrice(v);
  _vmBtnState(true);
}

function _vmRefPrice(v) {
  var p  = Number(v.price    || 0);
  var op = Number(v.old_price || 0);
  var dc = Number(v.discount  || 0);
  document.getElementById('vm-price').innerHTML = '&#8377;' + p.toFixed(0);
  var opEl = document.getElementById('vm-old-price');
  opEl.style.display = (op > p) ? 'inline' : 'none';
  if (op > p) opEl.innerHTML = '&#8377;' + op.toFixed(0);
  var dEl = document.getElementById('vm-disc');
  dEl.style.display = (dc > 0) ? 'inline' : 'none';
  if (dc > 0) dEl.textContent = Math.floor(dc) + '% OFF';
}

function _vmBtnState(on) {
  var btn = document.getElementById('vm-add-btn');
  btn.disabled    = !on;
  btn.style.background = on ? 'var(--lux-black)' : '#d1d5db';
  btn.style.cursor     = on ? 'pointer' : 'not-allowed';
}

function vmQtyChange(d) {
  _vmQty = Math.max(1, _vmQty + d);
  document.getElementById('vm-qty-val').textContent = _vmQty;
}

function vmConfirm() {
  if (!_vmSel) return;
  var item = Object.assign({}, _vmProd, {
    variant_id       : _vmSel.id,
    variant_weight   : _vmSel.weight_value,
    variant_unit     : _vmSel.weight_unit,
    variant_price    : Number(_vmSel.price     || 0),
    variant_old_price: Number(_vmSel.old_price || 0),
    variant_discount : Number(_vmSel.discount  || 0),
    quantity: _vmQty, qty: _vmQty
  });
  addToCart(item);
  closeVariantModal();
  openCartPanel();
}

function closeVariantModal() {
  var m = document.getElementById('vm-modal');
  m.style.transform = 'translateX(-50%) translateY(100%)';
  setTimeout(function(){ m.style.display = 'none'; }, 350);
  document.getElementById('vm-backdrop').style.display = 'none';
}

/* ─── CART PANEL ─────────────────────────────────── */
function openCartPanel() {
  renderCP();
  document.getElementById('cart-panel').style.transform = 'translateX(0)';
  document.getElementById('cp-overlay').style.display   = 'block';
  document.body.style.overflow = 'hidden';
}
function closeCartPanel() {
  document.getElementById('cart-panel').style.transform = 'translateX(105%)';
  document.getElementById('cp-overlay').style.display   = 'none';
  document.body.style.overflow = '';
}

function renderCP() {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var box  = document.getElementById('cp-items');
  box.innerHTML = '';

  var tqAll = cart.reduce(function(s,i){ return s + Number(i.quantity != null ? i.quantity : (i.qty||0)); }, 0);
  document.getElementById('cp-badge').textContent = tqAll;

  if (!cart.length) {
    box.innerHTML = '<div class="cp-empty">'
      + '<i class="fas fa-shopping-basket"></i>'
      + '<p>Your cart is empty</p>'
      + '<small>Add products to get started!</small>'
      + '</div>';
    document.getElementById('cp-ti').textContent  = 0;
    document.getElementById('cp-tq').textContent  = 0;
    document.getElementById('cp-gt').innerHTML    = '&#8377;0.00';
    return;
  }

  var grand = 0, tq = 0;
  cart.forEach(function(item, idx) {
    // Use the stored selling price directly — do NOT recalculate from old_price × discount
    // because floating-point discount math can differ from the actual price in the DB.
    var up = Number(item.variant_price != null ? item.variant_price : (item.price || 0));
    var qty = Number(item.quantity != null ? item.quantity : (item.qty||0));
    var lt  = up * qty;
    grand += lt; tq += qty;

    var hv  = item.variant_weight || item.variant_unit;
    var hop = item.variant_old_price && Number(item.variant_old_price) > up;
    var hdc = item.variant_discount  && Number(item.variant_discount)  > 0;

    /* trash icon inline SVG */
    var trash = '<svg width="11" height="11" fill="none" stroke="#dc2626" stroke-width="2.4" viewBox="0 0 24 24">'
      + '<polyline points="3 6 5 6 21 6"/>'
      + '<path d="M19 6l-1 14H6L5 6"/>'
      + '<path d="M10 11v6M14 11v6"/>'
      + '<path d="M9 6V4h6v2"/></svg>';

    var card = document.createElement('div');
    card.className = 'cp-card';
    card.innerHTML =
      '<img src="' + (item.image||'/images/default.jpg') + '" alt="' + item.name + '" onerror="this.src=\'/images/default.jpg\'">'
      + '<div class="cp-card-body">'
        + '<div class="cp-card-name">' + item.name + '</div>'
        + (hv ? '<div class="cp-variant-tag">' + (item.variant_weight||'') + (item.variant_unit ? ' '+item.variant_unit : '') + '</div>' : '')
        + '<div class="cp-prices">'
          + '<span class="cp-fp">&#8377;' + up.toFixed(0) + '</span>'
          + (hop ? '<span class="cp-op">&#8377;' + Number(item.variant_old_price).toFixed(0) + '</span>' : '')
          + (hdc ? '<span class="cp-dc">' + Math.floor(item.variant_discount) + '% OFF</span>' : '')
        + '</div>'
        + '<div class="cp-bot">'
          + '<div class="cp-qwrap">'
            + '<button class="cpqb" onclick="cpQ(' + idx + ',-1)">' + (qty<=1 ? trash : '&#8722;') + '</button>'
            + '<input class="cpqi" type="number" value="' + qty + '" min="1" onchange="cpSQ(' + idx + ',this.value)">'
            + '<button class="cpqb" onclick="cpQ(' + idx + ',1)">+</button>'
          + '</div>'
          + '<span class="cp-line-total">&#8377;' + lt.toFixed(2) + '</span>'
        + '</div>'
        + '<button class="cp-rm" onclick="cpRm(' + idx + ')">&#10005; Remove</button>'
      + '</div>';
    box.appendChild(card);
  });

  document.getElementById('cp-ti').textContent = cart.length;
  document.getElementById('cp-tq').textContent = tq;
  document.getElementById('cp-gt').innerHTML   = '&#8377;' + grand.toFixed(2);
  document.getElementById('cp-badge').textContent = tq;
}

function cpQ(idx, d) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var nq   = Number(cart[idx].quantity != null ? cart[idx].quantity : (cart[idx].qty||0)) + d;
  if (nq <= 0) cart.splice(idx, 1);
  else cart[idx].quantity = cart[idx].qty = nq;
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
}
function cpSQ(idx, val) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  cart[idx].quantity = cart[idx].qty = Math.max(1, Number(val));
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
}
function cpRm(idx) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var nm   = cart[idx].name;
  cart.splice(idx, 1);
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
  if (typeof showToastMessage === 'function') showToastMessage(nm + ' removed');
}

document.addEventListener('DOMContentLoaded', updateCartCount);

// ── Star Selector for review form ──────────────────────────────────────────
(function() {
    const stars = document.querySelectorAll('.star-pick');
    const label = document.getElementById('starLabel');
    if (!stars.length) return;

    const labels = ['', '1 star', '2 stars', '3 stars', '4 stars', '5 stars'];

    function paintStars(val) {
        stars.forEach(s => {
            s.setAttribute('fill', parseInt(s.dataset.val) <= val ? '#FFCC00' : '#e5e7eb');
        });
        if (label) label.textContent = labels[val] || '';
    }

    // Init at 5
    paintStars(5);

    stars.forEach(star => {
        star.addEventListener('mouseover', () => paintStars(parseInt(star.dataset.val)));
        star.addEventListener('click', () => {
            const val = parseInt(star.dataset.val);
            // Check the corresponding radio
            const radio = star.closest('label').querySelector('input[type=radio]');
            if (radio) radio.checked = true;
            paintStars(val);
        });
    });

    const selector = document.getElementById('starSelector');
    if (selector) {
        selector.addEventListener('mouseleave', () => {
            const checked = selector.querySelector('input[type=radio]:checked');
            paintStars(checked ? parseInt(checked.value) : 5);
        });
    }
})();
</script>

</body>
</html>
