<?php
// api/logout.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Remember the session name & cookie params BEFORE destroying
$sessionName = session_name();
$params      = session_get_cookie_params();

// 1. Clear variables
$_SESSION = [];

// 2. Expire the session cookie in the browser
if (ini_get('session.use_cookies')) {
    setcookie(
        $sessionName,
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy the session file on the server
session_destroy();

// 4. Clear any custom cookies
setcookie('user_email',  '', time() - 3600, '/');
setcookie('remember_me', '', time() - 3600, '/');

echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully'
]);
exit;