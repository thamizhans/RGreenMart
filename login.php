<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$error = "";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $identifier = trim($_POST["identifier"]);
    $password   = trim($_POST["password"]);

    $sql  = "SELECT * FROM users WHERE email = :id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['id' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user["password_hash"])) {

        $_SESSION["user_id"]     = $user["id"];
        $_SESSION["user_name"]   = $user["name"];
        $_SESSION["user_email"]  = $user["email"];
        $_SESSION["user_mobile"] = $user["mobile"];

        if (!empty($_SESSION["redirect_after_login"])) {
            $redirect = $_SESSION["redirect_after_login"];
            unset($_SESSION["redirect_after_login"]);
            header("Location: $redirect");
            exit();
        }

        header("Location: index.php");
        exit();
    } else {
        $error = "Invalid login credentials!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | RGreenMart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/cart.js"></script>
    <link rel="stylesheet" href="luxury-editorial.css">
    <style>
        /* Brutalist Overrides */
        body { font-family: var(--lux-font-sans); background: var(--lux-white) !important; color: var(--lux-black) !important; }
        .rounded-xl, .rounded-lg, .rounded, .rounded-md, .rounded-sm, .rounded-full { border-radius: 0 !important; }
        .shadow-lg, .shadow-md, .shadow { box-shadow: none !important; }
        .bg-white { background-color: var(--lux-white) !important; }
        .bg-gray-100 { background-color: transparent !important; }
        input[type="text"], input[type="password"] { border: 1px solid var(--lux-gray) !important; background: transparent !important; outline: none !important; padding: 1rem !important; font-family: var(--lux-font-sans); width: 100%; transition: border-color 0.2s; margin-top: 0.5rem; }
        input:focus { border-color: var(--lux-black) !important; }
        .text-black { color: var(--lux-black) !important; font-family: var(--lux-font-heading); text-transform: uppercase; font-size: 2rem; }
        .bg-black text-white { background-color: var(--lux-black) !important; color: var(--lux-white) !important; text-transform: uppercase; letter-spacing: 0.1em; padding: 1rem !important; border: none !important; font-family: var(--lux-font-sans); margin-top: 1rem; cursor: pointer; transition: background 0.3s ease !important; border-radius: 0 !important;}
        .bg-black text-white:hover { background-color: #333 !important; }
        .text-gray-700 { color: var(--lux-gray) !important; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; font-weight: 500;}
        .max-w-md { max-width: 500px !important; border: 1px solid var(--lux-gray); padding: 4rem !important; }
        @media (max-width: 768px) { .max-w-md { border: none; padding: 2rem !important; } }
    </style>
</head>
<body>
<?php include "includes/header.php"; ?>

<div class="zara-auth-container">
    
    <!-- LEFT: LOGIN FORM -->
    <div class="zara-auth-left">
        <h2>LOG IN</h2>
        
        <?php if ($error): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:10px; margin-bottom:20px; font-size:0.85rem;" id="alertBox">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="zara-input-group">
                <label>E-MAIL</label>
                <input type="text" name="identifier" required value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
            </div>

            <div class="zara-input-group">
                <label>PASSWORD</label>
                <div style="position:relative;">
                    <input type="password" id="password" name="password" required>
                    <button type="button" onclick="togglePassword('password', this)" style="position:absolute; right:0; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer;">
                        <!-- Eye Open -->
                        <svg class="w-5 h-5 eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px; color:#666;">
                            <path stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <!-- Eye Closed -->
                        <svg class="w-5 h-5 eye-closed hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px; color:#666; display:none;">
                            <path stroke-width="2" d="M3 3l18 18M10.477 10.477A3 3 0 0113.52 13.52M7.05 7.05A7.5 7.5 0 004.22 10.5C5.29 13.574 8.418 16 12 16a7.48 7.48 0 003.95-1.05M9.9 4.24A9.1 9.1 0 0112 4c3.582 0 6.71 2.426 7.78 5.5a9.13 9.13 0 01-1.947 3.053"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div style="margin-top: 1rem; margin-bottom: 2rem; text-align: left;">
                <a href="forgot_password.php" style="font-size: 0.75rem; color: #000; text-decoration: underline;">HAVE YOU FORGOTTEN YOUR PASSWORD?</a>
            </div>

            <button type="submit" class="zara-auth-btn">LOG IN</button>
        </form>
    </div>

    <!-- RIGHT: REGISTER -->
    <div class="zara-auth-right">
        <h2>REGISTER</h2>
        <p>
            IF YOU STILL DON'T HAVE A RGREENMART ACCOUNT, USE THIS OPTION TO ACCESS THE REGISTRATION FORM. 
            <br><br>
            BY GIVING US YOUR DETAILS, PURCHASING IN RGREENMART WILL BE FASTER AND AN ENJOYABLE EXPERIENCE.
        </p>
        <button onclick="window.location.href='register.php'" class="zara-auth-btn outline">CREATE ACCOUNT</button>
    </div>

</div>

<?php include "includes/footer.php"; ?>

<script>
function togglePassword(fieldId, btn) {
    const input      = document.getElementById(fieldId);
    const openIcon   = btn.querySelector('.eye-open');
    const closedIcon = btn.querySelector('.eye-closed');
    if (input.type === "password") {
        input.type = "text";
        openIcon.classList.add("hidden");
        closedIcon.classList.remove("hidden");
    } else {
        input.type = "password";
        openIcon.classList.remove("hidden");
        closedIcon.classList.add("hidden");
    }
}

setTimeout(() => {
    const alertBox = document.getElementById('alertBox');
    if (alertBox) alertBox.style.display = 'none';
}, 5000);
</script>
</body>
</html>