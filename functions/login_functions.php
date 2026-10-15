<?php
require_once __DIR__ . '/../conn/conn.php';

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hashedPassword) {
    return password_verify($password, $hashedPassword);
}

function createUser($conn, $email, $password, $fullName, $phone = null, $role = 'patient') {
    try {
        if (!$conn || $conn->connect_error) {
            throw new Exception('Database connection failed');
        }
        
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if (!$checkStmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            return ['success' => false, 'message' => 'Email already registered. Please use a different email or login.'];
        }
        $checkStmt->close();
        
        $hashedPassword = hashPassword($password);
        $isActive = 1;
        
        $stmt = $conn->prepare("
            INSERT INTO users (email, password, full_name, phone, role, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sssssi", $email, $hashedPassword, $fullName, $phone, $role, $isActive);
        
        if ($stmt->execute()) {
            $userId = $conn->insert_id;
            $stmt->close();
            return ['success' => true, 'message' => 'User created successfully', 'user_id' => $userId];
        }
        
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create user'];
        
    } catch (Exception $e) {
        error_log("Create User Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

function createUserPDO($pdo, $email, $password, $fullName, $phone = null, $role = 'patient') {
    try {
        if (!$pdo) {
            throw new Exception('PDO connection not available');
        }
        
        // Check if email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        
        if ($checkStmt->rowCount() > 0) {
            return ['success' => false, 'message' => 'Email already registered. Please use a different email or login.'];
        }
        
        $hashedPassword = hashPassword($password);
        $isActive = 1;
        
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, full_name, phone, role, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$email, $hashedPassword, $fullName, $phone, $role, $isActive]);
        $userId = $pdo->lastInsertId();
        
        return ['success' => true, 'message' => 'User created successfully', 'user_id' => $userId];
        
    } catch (PDOException $e) {
        error_log("Create User PDO Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

function loginUser($conn, $email, $password) {
    try {
        // Check connection
        if (!$conn) {
            error_log('loginUser: Connection is null');
            return ['success' => false, 'message' => 'Database connection error'];
        }
        
        if ($conn->connect_error) {
            error_log('loginUser: Connection failed - ' . $conn->connect_error);
            return ['success' => false, 'message' => 'Database connection error: ' . $conn->connect_error];
        }
        
        error_log('loginUser: Looking for user: ' . $email);
        
        $stmt = $conn->prepare("
            SELECT id, email, password, full_name, phone, role, is_active 
            FROM users 
            WHERE email = ?
        ");
        
        if (!$stmt) {
            error_log('loginUser: Prepare failed - ' . $conn->error);
            return ['success' => false, 'message' => 'Database error: ' . $conn->error];
        }
        
        $stmt->bind_param("s", $email);
        
        if (!$stmt->execute()) {
            error_log('loginUser: Execute failed - ' . $stmt->error);
            return ['success' => false, 'message' => 'Database error: ' . $stmt->error];
        }
        
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        error_log('loginUser: User found: ' . ($user ? 'Yes' : 'No'));
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }
        
        // Check if account is active
        $isActive = intval($user['is_active'] ?? 0);
        error_log('loginUser: Account active: ' . $isActive);
        
        if ($isActive !== 1) {
            return ['success' => false, 'message' => 'Your account is pending approval. Please wait for admin confirmation.'];
        }
        
        // Verify password
        error_log('loginUser: Verifying password');
        $passwordValid = password_verify($password, $user['password']);
        error_log('loginUser: Password valid: ' . ($passwordValid ? 'Yes' : 'No'));
        
        if (!$passwordValid) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }
        
        // Update last login
        $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
        
        // Remove password from user data
        unset($user['password']);
        
        // Get redirect URL based on role
        $redirectUrl = getRedirectUrlByRole($user['role']);
        
        return [
            'success' => true, 
            'message' => 'Login successful', 
            'user' => $user,
            'redirect' => $redirectUrl
        ];
        
    } catch (Exception $e) {
        error_log("Login Error: " . $e->getMessage());
        error_log("Login Error Trace: " . $e->getTraceAsString());
        return ['success' => false, 'message' => 'Login failed: ' . $e->getMessage()];
    }
}

function getRedirectUrlByRole($role) {
    $roleRedirects = [
        'patient' => 'pages/patient/patient-dashboard.php',
        'staff' => 'pages/staff/patient_services.php',
        'admin' => 'pages/admin/dashboard.php',
    ];
    
    return isset($roleRedirects[$role]) ? $roleRedirects[$role] : 'index.php';
}

function getUserByEmail($conn, $email) {
    try {
        if (!$conn || $conn->connect_error) {
            return false;
        }
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Get User Error: " . $e->getMessage());
        return false;
    }
}

function getUserByEmailPDO($pdo, $email) {
    try {
        if (!$pdo) {
            return false;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get User PDO Error: " . $e->getMessage());
        return false;
    }
}

function updateUserLastLogin($conn, $userId) {
    try {
        if (!$conn || $conn->connect_error) {
            return false;
        }
        
        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Update Last Login Error: " . $e->getMessage());
        return false;
    }
}

function redirectUserByRole($user) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    
    // ============================================================
    // CRITICAL: Set staff session for chat
    // ============================================================
    if (in_array($user['role'], ['staff', 'admin'])) {
        $_SESSION['staff_logged_in'] = true;
        $_SESSION['staff_id'] = $user['id'];
        $_SESSION['staff_name'] = $user['full_name'];
        $_SESSION['staff_role'] = $user['role'];
        $_SESSION['is_staff'] = true;
    } else {
        $_SESSION['staff_logged_in'] = false;
        $_SESSION['is_staff'] = false;
    }
    
    $redirectUrl = getRedirectUrlByRole($user['role']);
    header("Location: " . $redirectUrl);
    exit();
}

function hasPageAccess($userRole, $currentPage) {
    if (strpos($currentPage, 'pages/admin/') !== false) {
        return $userRole === 'admin';
    }
    
    if (strpos($currentPage, 'pages/staff/') !== false) {
        return in_array($userRole, ['staff', 'admin']);
    }
    
    if (strpos($currentPage, 'pages/patient/') !== false) {
        return true;
    }
    
    return true;
}

function checkPageAccess($conn, $userId) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: ../index.php');
        exit();
    }
    
    $currentPage = $_SERVER['REQUEST_URI'];
    $userRole = $_SESSION['user_role'] ?? 'patient';
    
    if (!hasPageAccess($userRole, $currentPage)) {
        header('Location: ../unauthorized.php');
        exit();
    }
}
?>