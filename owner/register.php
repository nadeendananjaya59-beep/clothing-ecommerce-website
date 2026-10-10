<?php
/**
 * Owner Registration Page
 * Allows new shop owners to create their account and shop
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$error   = null;
$success = null;
$form    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        // Sanitize inputs
        $form['full_name']     = sanitize($_POST['full_name']     ?? '');
        $form['email']         = sanitize($_POST['email']         ?? '');
        $form['phone']         = sanitize($_POST['phone']         ?? '');
        $form['shop_name']     = sanitize($_POST['shop_name']     ?? '');
        $form['shop_address']  = sanitize($_POST['shop_address']  ?? '');
        $form['shop_phone']    = sanitize($_POST['shop_phone']    ?? '');
        $password              = $_POST['password']               ?? '';
        $confirm_password      = $_POST['confirm_password']       ?? '';

        // Validate
        $errors = [];
        if (empty($form['full_name']))    $errors[] = 'Full name is required.';
        if (empty($form['email']))        $errors[] = 'Email address is required.';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        if (empty($form['shop_name']))    $errors[] = 'Shop name is required.';
        if (strlen($password) < 8)        $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        } else {
            $result = register_owner(
                $conn,
                $form['full_name'],
                $form['email'],
                $password,
                $form['phone'],
                $form['shop_name'],
                $form['shop_address'],
                $form['shop_phone']
            );

            if ($result['success']) {
                set_flash('success', $result['message']);
                header('Location: login.php');
                exit();
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Register your shop and create your Shop Owner account.">
    <title>Shop Owner Registration | E-Commerce</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card wide">

        <!-- Brand -->
        <div class="auth-brand">
            <div class="brand-icon">🏪</div>
            <h1>Create Your Shop</h1>
            <p>Register as a Shop Owner and start selling today</p>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <span class="alert-icon"><i class="fas fa-circle-xmark"></i></span>
            <div><?= $error ?></div>
        </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form method="POST" action="register.php" class="auth-form" autocomplete="off">
            <?= csrf_field() ?>

            <!-- Account Info Section -->
            <div class="section-label"><i class="fas fa-user-shield"></i> Account Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="full_name" name="full_name"
                               class="form-control"
                               placeholder="John Doe"
                               value="<?= htmlspecialchars($form['full_name'] ?? '') ?>"
                               required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone input-icon"></i>
                        <input type="tel" id="phone" name="phone"
                               class="form-control"
                               placeholder="+94 771 234 567"
                               value="<?= htmlspecialchars($form['phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email"
                           class="form-control"
                           placeholder="owner@example.com"
                           value="<?= htmlspecialchars($form['email'] ?? '') ?>"
                           required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password-strength-input" name="password"
                               class="form-control"
                               placeholder="Min. 8 characters"
                               required>
                        <button type="button" class="toggle-password" data-target="password-strength-input" aria-label="Toggle password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                        <span class="strength-text" id="strength-label"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-control"
                               placeholder="Repeat password"
                               required>
                        <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <small id="match-msg"></small>
                </div>
            </div>

            <!-- Shop Info Section -->
            <div class="section-label" style="margin-top:0.5rem"><i class="fas fa-store"></i> Shop Details</div>

            <div class="form-group">
                <label class="form-label" for="shop_name">Shop Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-store input-icon"></i>
                    <input type="text" id="shop_name" name="shop_name"
                           class="form-control"
                           placeholder="My Clothing Store"
                           value="<?= htmlspecialchars($form['shop_name'] ?? '') ?>"
                           required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="shop_phone">Shop Phone</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone-volume input-icon"></i>
                        <input type="tel" id="shop_phone" name="shop_phone"
                               class="form-control"
                               placeholder="+94 11 234 5678"
                               value="<?= htmlspecialchars($form['shop_phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="shop_address">Shop Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt input-icon"></i>
                        <input type="text" id="shop_address" name="shop_address"
                               class="form-control"
                               placeholder="123 Main St, Colombo"
                               value="<?= htmlspecialchars($form['shop_address'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:0.5rem">
                <i class="fas fa-store-alt"></i> Create Shop Account
            </button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php" class="auth-link">Sign In</a>
        </div>

    </div>
</div>

<script src="assets/js/owner.js"></script>
</body>
</html>
