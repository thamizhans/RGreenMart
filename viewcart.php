<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RGreenMart</title>
    <link rel="stylesheet" type="text/css" href="./Styles.css">
    <script src="./cart.js"></script>
    <style>
        body { background: var(--lux-white); color: var(--lux-black); }
        .cartsplit {
            display: flex;
            flex-wrap: wrap;
            padding: 4rem 2rem;
            max-width: 1200px;
            margin: 0 auto;
            gap: 4rem;
        }
        .itemscontainer {
            flex: 2;
            min-width: 300px;
        }
        .checkoutcontainer {
            flex: 1;
            min-width: 300px;
            background: #fafafa;
            padding: 3rem;
            border: 1px solid var(--lux-gray);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        .col3 {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: var(--lux-gray);
            border-bottom: 2px solid var(--lux-black);
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        
        /* Overriding cart.js styles for luxury look */
        .cart-item-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            align-items: center;
            padding: 2rem 0;
            border-bottom: 1px solid #eaeaea;
        }
        .cart-img {
            width: 100px;
            height: 120px;
            object-fit: cover;
            filter: grayscale(10%) contrast(1.1);
        }
        .cart-item-info { gap: 2rem; }
        .cart-item-info h4 { font-family: var(--lux-font-heading); font-size: 1.25rem !important; margin: 0 0 0.5rem 0; font-weight: 500 !important; }
        .price-meta { margin-top: 0.5rem; font-family: var(--lux-font-sans); }
        .final-price { font-weight: 400; font-size: 1.1rem; }
        .delete-btn { background: none; border: none; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; color: var(--lux-gray); text-decoration: underline; margin-top: 1rem; padding: 0; }
        .qty-box { display: flex; align-items: center; border: 1px solid var(--lux-gray); width: fit-content; }
        .qty-box input { width: 40px; text-align: center; border: none; font-family: var(--lux-font-sans); background: transparent; }
        .qty-btn { background: none; border: none; padding: 0.5rem 1rem; cursor: pointer; }
        .hrline { display: none; }
        .cart-item-amount { font-family: var(--lux-font-sans); font-size: 1.25rem; font-weight: 400; }
        
        .summary-title { font-family: var(--lux-font-heading); font-size: 1.5rem; margin-bottom: 2rem; font-weight: 400; }
        .checkoutcontainer p { display: flex; justify-content: space-between; font-family: var(--lux-font-sans); margin-bottom: 1rem; border-bottom: 1px solid #eaeaea; padding-bottom: 1rem; }
        .finalTotal { font-size: 1.5rem; font-weight: 600; }
        .checkout-btn { width: 100%; background: var(--lux-black) !important; color: var(--lux-white) !important; text-transform: uppercase; letter-spacing: 0.1em; padding: 1.5rem !important; border: none !important; font-family: var(--lux-font-sans); margin-top: 2rem; cursor: pointer; transition: background 0.3s ease !important; }
        .checkout-btn:hover { background: #333 !important; }

        @media (max-width: 768px) {
            .cart-item-row { grid-template-columns: 1fr; gap: 2rem; }
            .col3 { display: none; }
            .cartsplit { padding: 2rem 1rem; gap: 2rem; }
            .checkoutcontainer { padding: 2rem; position: static; }
        }
    </style>
</head>
<body>

    <?php require_once $_SERVER["DOCUMENT_ROOT"] . "/includes/header.php"; ?>
<div class="zara-cart-container">
    <div class="zara-cart-header">
        <h3>SHOPPING BAG</h3>
        <p><span id="totalItems">0</span> ITEMS</p>
    </div>
    
    <div id="cartItemsContainer" class="zara-cart-items"></div>
    
    <div class="zara-cart-footer">
        <div class="zara-cart-total">TOTAL: <span id="grandTotal">0</span> INR</div>
        <button onclick="window.location.href='add_delivery_address.php'" class="zara-checkout-btn">CONTINUE</button>
    </div>
</div>
 <?php require_once $_SERVER["DOCUMENT_ROOT"] ."/includes/footer.php"; ?>
    
    <div id="toast-container"></div>
    <script>
loadCart();
/** Update Quantity (+ or -) */
function updateQty(index, change) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];

    cart[index].qty = Math.max(1, Number(cart[index].qty) + change);
    localStorage.setItem("cart", JSON.stringify(cart));

    loadCart();
    updateCartCount();
}

/** Manually change qty using input */
function setQty(index, value) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];

    cart[index].qty = Math.max(1, Number(value));
    localStorage.setItem("cart", JSON.stringify(cart));

    loadCart();
    updateCartCount();
}

/** Update cart badge count */
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem("cart")) || [];
    const totalQty = cart.reduce((sum, item) => sum + Number(item.qty), 0);

    const countElement = document.getElementById("cartCount");
    if (countElement) countElement.textContent = totalQty;
}

document.addEventListener("DOMContentLoaded", () => {
    loadCart();
    updateCartCount();
});
</script>
</body>
</html>
