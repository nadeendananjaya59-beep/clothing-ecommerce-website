<?php
/**
 * Forgot Password Page
 * Step 1: User enters email and receives a reset token (displayed on-screen for local/demo use)
 * In production: send token via email using PHPMailer/mail()
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$error      = null;
$info       = null;
$reset_link = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = sanitize($_POST['email'] ?? '');

        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = generate_password_reset_token($conn, $email);

            if ($result['success'] && $result['token']) {
                // Build the reset URL
                $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host       = $_SERVER['HTTP_HOST'];
                $path       = dirname($_SERVER['PHP_SELF']);
                $reset_link = $protocol . '://' . $host . $path . '/reset-password.php?token=' . $result['token'];

                // ============================================================
                // PRODUCTION: Replace this block with email sending code
                // Example using PHP mail():
                //   $subject = "Reset Your Password - E-Commerce Shop";
                //   $body    = "Click to reset: " . $reset_link;
                //   $headers = "From: noreply@yourstore.com";
                //   mail($result['user']['email'], $subject, $body, $headers);
                //   $info = "A password reset link has been sent to your email.";
                //   $reset_link = null; // Don't show link in production!
                // ============================================================

                $info = 'Reset link generated for: <strong>' . htmlspecialchars($email) . '</strong>';
            } else {
                // Anti-enumeration: always show same message
                $info = 'If that email is registered, a reset link has been provided below.';
                $reset_link = null;
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
    <meta name="description" content="Reset your Shop Owner account password.">
    <title>Forgot Password | E-Commerce Shop Owner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">

        <!-- Brand -->
        <div class="auth-brand">
            <div class="brand-icon">🔑</div>
            <h1>Forgot Password?</h1>
            <p>Enter your email to reset your password</p>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <span class="alert-icon"><i class="fas fa-circle-xmark"></i></span>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <?php endif; ?>

        <!-- Info Alert -->
        <?php if ($info): ?>
        <div class="alert alert-info">
            <span class="alert-icon"><i class="fas fa-circle-info"></i></span>
            <div><?= $info ?></div>
        </div>
        <?php endif; ?>

        <!-- Reset Link Display (Development / Demo Only) -->
        <?php if ($reset_link): ?>
        <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25); border-radius: 10px; padding: 1.2rem; margin-bottom: 1.4rem;">
            <p style="font-size:0.8rem; font-weight:700; color:#6ee7b7; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.7rem;">
                <i class="fas fa-link"></i> &nbsp;Password Reset Link <span style="color:#f59e0b; font-size:0.7rem;">(Dev Mode — Send via email in production)</span>
            </p>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <input id="reset-link-input" type="text" readonly
                       value="<?= htmlspecialchars($reset_link) ?>"
                       style="flex:1; background:#0d1117; border:1px solid rgba(16,185,129,0.3); border-radius:8px;
                              color:#a0ffd0; font-size:0.75rem; padding:0.6rem 0.8rem; outline:none; font-family:monospace;">
                <button id="copy-reset-link" class="btn btn-outline" style="white-space:nowrap; padding:0.6rem 1rem; font-size:0.8rem;">
                    <i class="fas fa-copy"></i> Copy Link
                </button>
            </div>
            <p style="font-size:0.75rem; color:#6a6a8a; margin-top:0.6rem;">
                <i class="fas fa-clock"></i> Expires in 1 hour
            </p>
        </div>
        <?php endif; ?>

        <!-- Forgot Password Form (only shown if not yet submitted) -->
        <?php if (!$info): ?>
        <form method="POST" action="forgot-password.php" class="auth-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="email">Registered Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email"
                           class="form-control"
                           placeholder="owner@example.com"
                           autofocus required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>
        <?php else: ?>
        <a href="forgot-password.php" class="btn btn-outline" style="width:100%; text-align:center; display:block;">
            <i class="fas fa-rotate-left"></i> Request Another Link
        </a>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php" class="auth-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>

    </div>
</div>

<script src="assets/js/owner.js"></script>
</body>
</html>
