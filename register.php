<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ── Track referral link click (?ref=CODE) ────────────────────────
require_once 'generate_referral_code.php';
captureReferralClick();
// ─────────────────────────────────────────────────────────────────

$errors  = [];
$success = "";

/* ================================================================
   REGISTER — direct registration (no OTP)
================================================================ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {

    $email           = trim($_POST["email"]            ?? '');
    $mobile          = preg_replace('/\D/', '', $_POST["mobile"] ?? '');
    $password        = trim($_POST["password"]         ?? '');
    $confirmPassword = trim($_POST["confirm_password"] ?? '');

    // Validate email
    if (!$email)
        $errors['email'] = "Email is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email'] = "Please enter a valid email address.";

    // Validate password
    if (strlen($password) < 6)
        $errors['password'] = "Password must be at least 6 characters.";
    elseif ($password !== $confirmPassword)
        $errors['confirm_password'] = "Passwords do not match.";

    // Check duplicates
    if (!isset($errors['email'])) {
        $stmtE = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmtE->execute([$email]);
        if ($stmtE->rowCount() > 0)
            $errors['email'] = "This email is already registered.";
    }

    if ($mobile !== '') {
        if (strlen($mobile) !== 10) {
            $errors['mobile'] = "Mobile number must be 10 digits.";
        } else {
            $stmtM = $conn->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
            $stmtM->execute([$mobile]);
            if ($stmtM->rowCount() > 0)
                $errors['mobile'] = "This mobile number is already registered.";
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $conn->prepare(
            "INSERT INTO users (email, mobile, password_hash) VALUES (?, ?, ?)"
        )->execute([$email, $mobile ?: null, $hash]);

        $userId = $conn->lastInsertId();

        // ── Referral ──────────────────────────────────────────────
        if (!empty($_SESSION['referred_by'])) {
            $conn->prepare("UPDATE users SET referred_by=? WHERE id=?")
                 ->execute([$_SESSION['referred_by'], $userId]);
            saveReferralDiscountSnapshot($conn, $userId);
            unset($_SESSION['referred_by']);
        }
        assignReferralCode($conn, $userId);
        // ─────────────────────────────────────────────────────────

        // Auto-login
        $stmtU = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmtU->execute([$userId]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_name']   = $user['name'];
        $_SESSION['user_email']  = $user['email'];
        $_SESSION['user_mobile'] = $user['mobile'];

        header("Location: index.php");
        exit();
    }
}

// Preserve form values on validation error
$oldEmail  = htmlspecialchars($_POST['email']  ?? '');
$oldMobile = htmlspecialchars($_POST['mobile'] ?? '');
$hasErrors = !empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | RGreenMart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="luxury-editorial.css">
    <style>
        /* Brutalist Overrides */
        body { font-family: var(--lux-font-sans); background: var(--lux-white) !important; color: var(--lux-black) !important; }
        .rounded-xl, .rounded-lg, .rounded, .rounded-md, .rounded-sm, .rounded-full { border-radius: 0 !important; }
        .shadow-lg, .shadow-md, .shadow { box-shadow: none !important; }
        .bg-white { background-color: var(--lux-white) !important; }
        .bg-gray-100 { background-color: transparent !important; }
        input[type="text"], input[type="password"], input[type="tel"], input[type="email"] { border: 1px solid var(--lux-gray) !important; background: transparent !important; outline: none !important; padding: 1rem !important; font-family: var(--lux-font-sans); width: 100%; transition: border-color 0.2s; margin-top: 0.5rem; }
        input:focus { border-color: var(--lux-black) !important; }
        .text-black { color: var(--lux-black) !important; font-family: var(--lux-font-heading); text-transform: uppercase; font-size: 2rem; }
        .bg-black text-white { background-color: var(--lux-black) !important; color: var(--lux-white) !important; text-transform: uppercase; letter-spacing: 0.1em; padding: 1rem !important; border: none !important; font-family: var(--lux-font-sans); margin-top: 1rem; cursor: pointer; transition: background 0.3s ease !important; border-radius: 0 !important;}
        .bg-black text-white:hover { background-color: #333 !important; }
        .text-gray-700 { color: var(--lux-black) !important; }
    </style>
</head>
<body>
<?php include "includes/header.php"; ?>
<div class="zara-auth-container">
    
    <!-- LEFT: REGISTER FORM -->
    <div class="zara-auth-left">
        <h2>REGISTER</h2>

        <!-- General / numeric-keyed errors -->
        <?php
        $generalErrors = array_filter($errors, fn($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        if ($generalErrors): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:10px; margin-bottom:20px; font-size:0.85rem;" id="alertBox">
                <?php foreach ($generalErrors as $e) echo "<p>" . htmlspecialchars($e) . "</p>"; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="registerForm" novalidate>

            <div class="zara-input-group">
                <label>EMAIL *</label>
                <input type="email" name="email" id="emailInput" required value="<?= $oldEmail ?>">
                <?php if (isset($errors['email'])): ?>
                    <p style="color:red; font-size:0.75rem; margin-top:5px;"><?= htmlspecialchars($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="zara-input-group">
                <label>MOBILE (OPTIONAL)</label>
                <input type="text" name="mobile" id="mobileInput" value="<?= $oldMobile ?>" maxlength="10">
                <?php if (isset($errors['mobile'])): ?>
                    <p style="color:red; font-size:0.75rem; margin-top:5px;"><?= htmlspecialchars($errors['mobile']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Password section (revealed after email confirmed) -->
            <div id="passwordSection" class="<?= $hasErrors ? '' : 'hidden' ?>">

                <p style="font-size:0.75rem; margin-bottom:1rem;">NOW SET A PASSWORD TO COMPLETE YOUR REGISTRATION.</p>

                <div class="zara-input-group">
                    <label>PASSWORD *</label>
                    <div style="position:relative;">
                        <input type="password" id="password" name="password">
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
                    <?php if (isset($errors['password'])): ?>
                        <p style="color:red; font-size:0.75rem; margin-top:5px;"><?= htmlspecialchars($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="zara-input-group">
                    <label>CONFIRM PASSWORD *</label>
                    <div style="position:relative;">
                        <input type="password" id="confirm_password" name="confirm_password">
                        <button type="button" onclick="togglePassword('confirm_password', this)" style="position:absolute; right:0; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer;">
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
                    <?php if (isset($errors['confirm_password'])): ?>
                        <p style="color:red; font-size:0.75rem; margin-top:5px;"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                    <?php endif; ?>
                </div>

                <button name="register" type="submit" class="zara-auth-btn">COMPLETE REGISTRATION</button>

            </div><!-- /passwordSection -->

            <!-- "Next" button — shown before email is confirmed -->
            <div id="nextBtnWrap" class="<?= $hasErrors ? 'hidden' : '' ?>">
                <button type="button" id="nextBtn" class="zara-auth-btn">NEXT</button>
            </div>

        </form>
    </div>

    <!-- RIGHT: LOG IN -->
    <div class="zara-auth-right">
        <h2>LOG IN</h2>
        <p>
            IF YOU ALREADY HAVE AN ACCOUNT, PLEASE LOG IN HERE.
            <br><br>
            BY LOGGING IN, YOU CAN ACCESS YOUR ORDERS AND ACCOUNT DETAILS FASTER.
        </p>
        <button onclick="window.location.href='login.php'" class="zara-auth-btn outline">LOG IN</button>
    </div>

</div>' : '' ?>">
            <button type="button" id="nextBtn"
                class="w-full bg-black hover:bg-gray-800
                    text-white p-3 rounded-lg font-semibold transition duration-300">
                Next →
            </button>
        </div>

    </form>

    <p style="text-align:center; font-size:0.85rem; margin-top:2rem;">
        Already have an account? 
        <a href="login.php" style="color:black; font-weight:700; text-decoration:underline;">Login</a>
    </p>

</div>

<!-- ═══════════════════════════════════════════════════════
     EMAIL CONFIRMATION POPUP
═══════════════════════════════════════════════════════ -->
<div id="emailPopup"
     class="fixed inset-0 flex items-center justify-center z-50 hidden"
     style="background:rgba(0,0,0,0.45);">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-7 text-center" id="popupCard">

        <!-- Mail icon -->
        <div class="flex items-center justify-center w-14 h-14 rounded-full bg-black text-white mx-auto mb-4">
            <svg class="w-7 h-7 text-black" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
        </div>

        <h3 class="text-lg font-bold text-gray-800 mb-2">Check your email</h3>
        <p class="text-gray-500 text-sm mb-3">Make sure this address is correct before continuing:</p>
        <p id="popupEmail"
           class="text-black font-semibold text-base bg-gray-100 rounded-lg px-4 py-2 mb-2 break-all"></p>
        <p class="text-gray-400 text-xs mb-6">Press <strong>Confirm</strong> to proceed, or <strong>Edit</strong> to change it.</p>

        <div class="flex gap-3">
            <button id="popupEdit"
                class="flex-1 border border-gray-300 text-gray-600 hover:bg-gray-50
                       p-2.5 rounded-lg text-sm font-medium transition">Edit
            </button>
            <button id="popupConfirm"
                class="flex-1 bg-black hover:bg-gray-800
                       text-white p-2.5 rounded-lg text-sm font-semibold transition">Confirm
            </button>
        </div>
    </div>
</div>

<?php include "includes/footer.php"; ?>

<style>
@keyframes popIn {
    0%   { transform: scale(.85); opacity: 0; }
    100% { transform: scale(1);   opacity: 1; }
}
#popupCard { animation: popIn .18s ease-out; }
</style>

<script>
/* ── Eye toggle ─────────────────────────────────────────── */
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

/* ── Auto-hide server-side error alert ──────────────────── */
setTimeout(() => {
    const alertBox = document.getElementById('alertBox');
    if (alertBox) alertBox.style.display = 'none';
}, 5000);

/* ── Elements ───────────────────────────────────────────── */
const emailInput      = document.getElementById('emailInput');
const nextBtnWrap     = document.getElementById('nextBtnWrap');
const nextBtn         = document.getElementById('nextBtn');
const passwordSection = document.getElementById('passwordSection');
const popup           = document.getElementById('emailPopup');
const popupEmailEl    = document.getElementById('popupEmail');
const popupConfirm    = document.getElementById('popupConfirm');
const popupEdit       = document.getElementById('popupEdit');

/* ── "Next" clicked: client-side email check then popup ── */
nextBtn.addEventListener('click', () => {
    const email = emailInput.value.trim();
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!email) { showFieldError(emailInput, 'Email is required.'); return; }
    if (!emailRe.test(email)) { showFieldError(emailInput, 'Please enter a valid email address.'); return; }

    clearFieldError(emailInput);
    popupEmailEl.textContent = email;
    popup.classList.remove('hidden');
});

/* ── Edit: close popup, refocus email ───────────────────── */
popupEdit.addEventListener('click', () => {
    popup.classList.add('hidden');
    emailInput.focus();
});

/* ── Confirm: hide popup, reveal password fields ────────── */
popupConfirm.addEventListener('click', () => {
    popup.classList.add('hidden');
    nextBtnWrap.classList.add('hidden');
    passwordSection.classList.remove('hidden');
    document.getElementById('password').focus();
});

/* ── Click outside popup to dismiss ────────────────────── */
popup.addEventListener('click', (e) => {
    if (e.target === popup) popup.classList.add('hidden');
});

/* ── Inline field-error helpers ─────────────────────────── */
function showFieldError(input, msg) {
    clearFieldError(input);
    input.classList.add('border-red-500');
    const p = document.createElement('p');
    p.className = 'text-red-500 text-xs mt-1 js-field-err';
    p.textContent = msg;
    input.insertAdjacentElement('afterend', p);
}
function clearFieldError(input) {
    input.classList.remove('border-red-500');
    const next = input.nextElementSibling;
    if (next && next.classList.contains('js-field-err')) next.remove();
}
</script>
</body>
</html>
