<?php
require_once __DIR__ . '/../conn/conn.php';

function getAllUsers($pdo, $limit = 50, $offset = 0, $search = '', $role = '') {
    try {
        $sql = "SELECT id, email, full_name, phone, role, is_active, last_login, created_at 
                FROM users WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $sql .= " AND (email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        if (!empty($role)) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get Users Error: " . $e->getMessage());
        return [];
    }
}

function countUsers($pdo, $search = '', $role = '') {
    try {
        $sql = "SELECT COUNT(*) as total FROM users WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $sql .= " AND (email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        if (!empty($role)) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Count Users Error: " . $e->getMessage());
        return 0;
    }
}

function getUserById($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT id, email, full_name, phone, role, is_active, last_login, created_at 
                               FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get User By ID Error: " . $e->getMessage());
        return false;
    }
}

function updateUser($pdo, $userId, $data) {
    try {
        $fields = [];
        $params = [];
        
        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = ?";
            $params[] = $data['phone'];
        }
        if (isset($data['role'])) {
            $fields[] = "role = ?";
            $params[] = $data['role'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Update User Error: " . $e->getMessage());
        return false;
    }
}

function deleteUser($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        error_log("Delete User Error: " . $e->getMessage());
        return false;
    }
}

function getRoleCounts($pdo) {
    try {
        $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get Role Counts Error: " . $e->getMessage());
        return [];
    }
}

function getActiveUsersCount($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Get Active Users Error: " . $e->getMessage());
        return 0;
    }
}

function getNewUsersCount($pdo, $days = 7) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Get New Users Error: " . $e->getMessage());
        return 0;
    }
}
?>