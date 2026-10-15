<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
session_start();

try {
    // Include database connection
    require_once __DIR__ . '/../conn/conn.php';
    
    // Check if $conn is set (use MySQLi instead of PDO for consistency)
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('Database connection not established');
    }
    
    // Include login functions
    $functions_path = __DIR__ . '/../functions/login_functions.php';
    if (!file_exists($functions_path)) {
        throw new Exception('Login functions file not found: ' . $functions_path);
    }
    require_once $functions_path;
    
    $response = ['success' => false, 'message' => ''];
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $fullName = trim($input['fullName'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    $termsAccepted = $input['termsAccepted'] ?? false;
    $privacyAccepted = $input['privacyAccepted'] ?? false;
    
    // Validation
    if (empty($fullName)) {
        throw new Exception('Full name is required');
    }
    
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    if (empty($password)) {
        throw new Exception('Password is required');
    }
    
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }
    
    if (!$termsAccepted) {
        throw new Exception('You must agree to Terms & Conditions');
    }
    
    if (!$privacyAccepted) {
        throw new Exception('You must agree to Privacy Policy');
    }
    
    // Check if email already exists (using MySQLi)
    $existingUser = getUserByEmail($conn, $email);
    if ($existingUser) {
        throw new Exception('Email already exists. Please use a different email or login.');
    }
    
    // Create user (using MySQLi)
    $result = createUser($conn, $email, $password, $fullName, $phone);
    
    if ($result) {
        // Get the newly created user
        $user = getUserByEmail($conn, $email);
        
        if ($user) {
            // Auto-login: Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            $response['success'] = true;
            $response['message'] = 'Account created and logged in successfully!';
            $response['user'] = [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];
        } else {
            $response['success'] = true;
            $response['message'] = 'Account created successfully! Please login.';
        }
    } else {
        throw new Exception('Failed to create account. Please try again.');
    }
    
} catch (Exception $e) {
    error_log("Registration Error: " . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

// Clear any output buffers and send JSON
ob_clean();
echo json_encode($response);
exit;
?>