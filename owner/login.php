<?php
/**
 * Owner Login Page
 * Authenticates existing shop owners
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$flash = get_flash();
$error = null;
$email_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = sanitize($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        $email_val = $email;

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = login_owner($conn, $email, $password);
            if ($result['success']) {
                header('Location: dashboard.php');
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
    <meta name="description" content="Sign in to your Shop Owner dashboard.">
    <title>Shop Owner Login | E-Commerce</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">

        <!-- Brand -->
        <div class="auth-brand">
            <div class="brand-icon">🏪</div>
            <h1>Welcome Back</h1>
            <p>Sign in to manage your shop</p>
        </div>

        <!-- Flash Message -->
        <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
            <span class="alert-icon">
                <?= $flash['type'] === 'success' ? '<i class="fas fa-circle-check"></i>' : '<i class="fas fa-circle-xmark"></i>' ?>
            </span>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <span class="alert-icon"><i class="fas fa-triangle-exclamation"></i></span>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php" class="auth-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email"
                           class="form-control"
                           placeholder="owner@example.com"
                           value="<?= htmlspecialchars($email_val) ?>"
                           autofocus required>
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.45rem;">
                    <label class="form-label" for="login_password" style="margin:0">Password</label>
                    <a href="forgot-password.php" class="auth-link" style="font-size:0.8rem">Forgot password?</a>
                </div>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="login_password" name="password"
                           class="form-control"
                           placeholder="Enter your password"
                           required>
                    <button type="button" class="toggle-password" data-target="login_password" aria-label="Toggle password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="remember_me" id="remember_me">
                    <label for="remember_me">Remember me for 30 days</label>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-right-to-bracket"></i> Sign In to Dashboard
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php" class="auth-link">Register Your Shop</a>
        </div>

    </div>
</div>

<script src="assets/js/owner.js"></script>
</body>
</html>
