<?php
/**
 * Owner Logout
 * Destroys the session and redirects to login
 */

require_once __DIR__ . '/includes/session.php';

start_session();

// Only log out if actually logged in
if (is_owner_logged_in()) {
    destroy_owner_session();
    set_flash('success', 'You have been signed out successfully.');
}

header('Location: login.php');
exit();
?>
