<?php
/**
 * Session Helper Functions
 * Handles all session-related operations for Shop Owner authentication
 */

// Start session if not already started
function start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('OWNER_SESSION');
        session_set_cookie_params([
            'lifetime' => 3600,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

// Check if owner is logged in
function is_owner_logged_in() {
    start_session();
    return isset($_SESSION['owner_id']) && isset($_SESSION['owner_role']) && $_SESSION['owner_role'] === 'Shop Owner';
}

// Require owner to be logged in (redirect if not)
function require_owner_login() {
    if (!is_owner_logged_in()) {
        header('Location: login.php');
        exit();
    }
}

// Prevent logged-in owner from accessing auth pages
function redirect_if_logged_in() {
    if (is_owner_logged_in()) {
        header('Location: dashboard.php');
        exit();
    }
}

// Set owner session after login
function set_owner_session($user) {
    start_session();
    session_regenerate_id(true);
    $_SESSION['owner_id']       = $user['user_id'];
    $_SESSION['owner_name']     = $user['full_name'];
    $_SESSION['owner_email']    = $user['email'];
    $_SESSION['owner_role']     = $user['role_name'];
    $_SESSION['owner_shop_id']  = $user['shop_id'] ?? null;
    $_SESSION['owner_shop_name']= $user['shop_name'] ?? null;
    $_SESSION['login_time']     = time();
}

// Destroy owner session
function destroy_owner_session() {
    start_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// Sanitize input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Flash message setter
function set_flash($type, $message) {
    start_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Flash message getter (clears after reading)
function get_flash() {
    start_session();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Generate CSRF token
function generate_csrf_token() {
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verify_csrf_token($token) {
    start_session();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Render CSRF hidden field
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">';
}
?>
