<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// Create a detailed log file for debugging
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../debug_login.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] " . $message;
    if ($data !== null) {
        $log_entry .= " | Data: " . print_r($data, true);
    }
    $log_entry .= "\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

debug_log('=== LOGIN REQUEST RECEIVED ===');

try {
    // Get absolute paths
    $base_dir = dirname(__DIR__);
    $conn_path = $base_dir . '/conn/conn.php';
    $functions_path = $base_dir . '/functions/login_functions.php';
    
    debug_log('File paths:', ['conn' => $conn_path, 'functions' => $functions_path]);
    
    // Include connection file
    require_once $conn_path;
    
    if (!isset($conn)) {
        throw new Exception('$conn variable not set after including conn.php');
    }
    
    if ($conn->connect_error) {
        throw new Exception('MySQLi connection error: ' . $conn->connect_error);
    }
    
    debug_log('MySQLi connection established successfully');
    
    // Include login functions
    require_once $functions_path;
    
    if (!function_exists('loginUser')) {
        throw new Exception('loginUser function not found');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }
    
    // Get JSON input
    $raw_input = file_get_contents('php://input');
    debug_log('Raw input received: ' . $raw_input);
    
    $input = json_decode($raw_input, true);
    debug_log('Decoded input: ' . print_r($input, true));
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $rememberMe = $input['rememberMe'] ?? false;
    
    debug_log('Login attempt for email: ' . $email);
    
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    
    if (empty($password)) {
        throw new Exception('Password is required');
    }
    
    // Login user using your function
    $result = loginUser($conn, $email, $password);
    
    if ($result['success']) {
        $user = $result['user'];
        
        // ============================================================
        // START SESSION AND SET ALL VARIABLES
        // ============================================================
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Clear any existing session data
        // session_destroy();
        // session_start();
        
        // Set standard session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        
        // ============================================================
        // CRITICAL FIX: Set staff session for chat
        // ============================================================
        if (in_array($user['role'], ['staff', 'admin'])) {
            $_SESSION['staff_logged_in'] = true;
            $_SESSION['staff_id'] = $user['id'];
            $_SESSION['staff_name'] = $user['full_name'];
            $_SESSION['staff_role'] = $user['role'];
            $_SESSION['is_staff'] = true;
            
            debug_log('✅ Staff session set for: ' . $user['email'] . ' (Role: ' . $user['role'] . ')');
            debug_log('Session data: ' . print_r($_SESSION, true));
        } else {
            // For patients, clear staff session
            $_SESSION['staff_logged_in'] = false;
            $_SESSION['is_staff'] = false;
            debug_log('Patient login, staff session cleared');
        }
        
        $response = [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'redirect' => $result['redirect']
        ];
        
        debug_log('✅ Login successful for user: ' . $email);
        echo json_encode($response);
        exit;
        
    } else {
        debug_log('❌ Login failed: ' . $result['message']);
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
        exit;
    }
    
} catch (Exception $e) {
    debug_log('❌ ERROR: ' . $e->getMessage());
    debug_log('ERROR TRACE: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>