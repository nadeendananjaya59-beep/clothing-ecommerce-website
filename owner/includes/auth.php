<?php
/**
 * Owner Authentication Functions
 * All authentication logic for shop owners
 */

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/session.php';

// ─────────────────────────────────────────────
// REGISTRATION
// ─────────────────────────────────────────────
function register_owner($conn, $full_name, $email, $password, $phone, $shop_name, $shop_address, $shop_phone) {
    // Check if email already exists
    $check_sql = "SELECT user_id FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Email address is already registered.'];
    }
    mysqli_stmt_close($stmt);

    // Get 'Shop Owner' role_id
    $role_sql = "SELECT role_id FROM user_roles WHERE role_name = 'Shop Owner' LIMIT 1";
    $role_res = mysqli_query($conn, $role_sql);
    if (!$role_res || mysqli_num_rows($role_res) === 0) {
        return ['success' => false, 'message' => 'Shop Owner role not found. Please run the SQL patch first.'];
    }
    $role_row = mysqli_fetch_assoc($role_res);
    $role_id  = $role_row['role_id'];

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Begin transaction
    mysqli_begin_transaction($conn);
    try {
        // Insert user
        $user_sql = "INSERT INTO users (full_name, email, password, phone, role_id, status, created_at)
                     VALUES (?, ?, ?, ?, ?, 'Active', NOW())";
        $stmt = mysqli_prepare($conn, $user_sql);
        mysqli_stmt_bind_param($stmt, 'ssssi', $full_name, $email, $hashed_password, $phone, $role_id);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create user account.');
        }
        $user_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        // Insert shop
        $shop_sql = "INSERT INTO shops (user_id, shop_name, shop_address, shop_phone, shop_email, status, created_at)
                     VALUES (?, ?, ?, ?, ?, 'Active', NOW())";
        $stmt = mysqli_prepare($conn, $shop_sql);
        mysqli_stmt_bind_param($stmt, 'issss', $user_id, $shop_name, $shop_address, $shop_phone, $email);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create shop.');
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────
function login_owner($conn, $email, $password) {
    $sql = "SELECT u.user_id, u.full_name, u.email, u.password, u.status,
                   ur.role_name, s.shop_id, s.shop_name
            FROM users u
            INNER JOIN user_roles ur ON u.role_id = ur.role_id
            LEFT JOIN shops s ON s.user_id = u.user_id
            WHERE u.email = ? AND ur.role_name = 'Shop Owner'
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // Check account status
    if ($user['status'] !== 'Active') {
        return ['success' => false, 'message' => 'Your account has been ' . strtolower($user['status']) . '. Please contact support.'];
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Update last login
    $upd_sql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
    $upd_stmt = mysqli_prepare($conn, $upd_sql);
    mysqli_stmt_bind_param($upd_stmt, 'i', $user['user_id']);
    mysqli_stmt_execute($upd_stmt);
    mysqli_stmt_close($upd_stmt);

    // Set session
    set_owner_session($user);

    return ['success' => true, 'message' => 'Login successful! Welcome back, ' . $user['full_name'] . '.'];
}

// ─────────────────────────────────────────────
// FORGOT PASSWORD - Generate Reset Token
// ─────────────────────────────────────────────
function generate_password_reset_token($conn, $email) {
    // Check if user exists and is a Shop Owner
    $sql = "SELECT u.user_id, u.full_name FROM users u
            INNER JOIN user_roles ur ON u.role_id = ur.role_id
            WHERE u.email = ? AND ur.role_name = 'Shop Owner' AND u.status = 'Active'
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        // Return success anyway to avoid user enumeration
        return ['success' => true, 'message' => 'If that email exists, a reset link has been sent.', 'token' => null];
    }

    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // Delete old tokens for this user
    $del_sql = "DELETE FROM password_resets WHERE user_id = ?";
    $del_stmt = mysqli_prepare($conn, $del_sql);
    mysqli_stmt_bind_param($del_stmt, 'i', $user['user_id']);
    mysqli_stmt_execute($del_stmt);
    mysqli_stmt_close($del_stmt);

    // Generate secure token
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $ins_sql = "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)";
    $ins_stmt = mysqli_prepare($conn, $ins_sql);
    mysqli_stmt_bind_param($ins_stmt, 'iss', $user['user_id'], $token, $expires_at);
    mysqli_stmt_execute($ins_stmt);
    mysqli_stmt_close($ins_stmt);

    return [
        'success' => true,
        'message' => 'Password reset link has been generated.',
        'token'   => $token,
        'user'    => $user
    ];
}

// ─────────────────────────────────────────────
// RESET PASSWORD - Verify Token and Update
// ─────────────────────────────────────────────
function validate_reset_token($conn, $token) {
    $sql = "SELECT pr.user_id, pr.expires_at, pr.used, u.email, u.full_name
            FROM password_resets pr
            INNER JOIN users u ON u.user_id = pr.user_id
            WHERE pr.token = ? AND pr.used = 0
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        return ['valid' => false, 'message' => 'Invalid or expired reset token.'];
    }

    $reset = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (strtotime($reset['expires_at']) < time()) {
        return ['valid' => false, 'message' => 'This reset link has expired. Please request a new one.'];
    }

    return ['valid' => true, 'data' => $reset];
}

function reset_password($conn, $token, $new_password) {
    $validation = validate_reset_token($conn, $token);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => $validation['message']];
    }

    $user_id        = $validation['data']['user_id'];
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    // Update password
    $upd_sql = "UPDATE users SET password = ? WHERE user_id = ?";
    $upd_stmt = mysqli_prepare($conn, $upd_sql);
    mysqli_stmt_bind_param($upd_stmt, 'si', $hashed_password, $user_id);
    mysqli_stmt_execute($upd_stmt);
    mysqli_stmt_close($upd_stmt);

    // Mark token as used
    $mark_sql = "UPDATE password_resets SET used = 1 WHERE token = ?";
    $mark_stmt = mysqli_prepare($conn, $mark_sql);
    mysqli_stmt_bind_param($mark_stmt, 's', $token);
    mysqli_stmt_execute($mark_stmt);
    mysqli_stmt_close($mark_stmt);

    return ['success' => true, 'message' => 'Password has been reset successfully. You can now log in.'];
}

// ─────────────────────────────────────────────
// GET OWNER PROFILE
// ─────────────────────────────────────────────
function get_owner_profile($conn, $user_id) {
    $sql = "SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at, u.last_login,
                   s.shop_id, s.shop_name, s.shop_description, s.shop_address, s.shop_phone,
                   s.shop_email, s.shop_logo, s.status AS shop_status
            FROM users u
            LEFT JOIN shops s ON s.user_id = u.user_id
            WHERE u.user_id = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $data;
}
?>
