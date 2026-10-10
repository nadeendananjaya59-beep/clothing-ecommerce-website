<?php
/**
 * Reset Password Page
 * Step 2: User clicks the reset link and enters a new password
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$token     = sanitize($_GET['token'] ?? '');
$error     = null;
$token_valid = false;
$token_data  = null;

if (empty($token)) {
    $error = 'No reset token provided. Please request a new password reset.';
} else {
    $validation = validate_reset_token($conn, $token);
    if ($validation['valid']) {
        $token_valid = true;
        $token_data  = $validation['data'];
    } else {
        $error = $validation['message'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
        $token_valid = false;
    } else {
        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            $result = reset_password($conn, $token, $new_password);
            if ($result['success']) {
                set_flash('success', $result['message']);
                header('Location: login.php');
                exit();
            } else {
                $error       = $result['message'];
                $token_valid = false;
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
    <meta name="description" content="Set a new password for your Shop Owner account.">
    <title>Reset Password | E-Commerce Shop Owner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">

        <!-- Brand -->
        <div class="auth-brand">
            <div class="brand-icon">🔒</div>
            <h1>Set New Password</h1>
            <?php if ($token_valid && $token_data): ?>
            <p>Resetting for: <strong><?= htmlspecialchars($token_data['email']) ?></strong></p>
            <?php else: ?>
            <p>Create a strong new password for your account</p>
            <?php endif; ?>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <span class="alert-icon"><i class="fas fa-circle-xmark"></i></span>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
        <!-- Reset Password Form -->
        <form method="POST" action="reset-password.php?token=<?= urlencode($token) ?>" class="auth-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password-strength-input" name="new_password"
                           class="form-control"
                           placeholder="Min. 8 characters"
                           autofocus required>
                    <button type="button" class="toggle-password" data-target="password-strength-input" aria-label="Toggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <span class="strength-text" id="strength-label"></span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-control"
                           placeholder="Repeat your new password"
                           required>
                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <small id="match-msg"></small>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-key"></i> Reset Password
            </button>
        </form>

        <?php else: ?>
        <!-- Invalid / Expired Token -->
        <div style="text-align:center; padding: 1rem 0;">
            <a href="forgot-password.php" class="btn btn-primary" style="text-decoration:none; display:inline-flex;">
                <i class="fas fa-rotate-left"></i> Request New Reset Link
            </a>
        </div>
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
