<?php

require '../../conn/conn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// ========== FUNCTIONS ==========
function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

function getRoleBadge($role) {
    $badges = [
        'admin' => 'bg-purple-100 text-purple-700',
        'staff' => 'bg-blue-100 text-blue-700',
        'patient' => 'bg-green-100 text-green-700'
    ];
    return $badges[$role] ?? 'bg-gray-100 text-gray-700';
}

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

// ========== DATABASE OPERATIONS ==========

// Paginated + searchable user list (replaces getAllUsers for table rendering)
function getUsersPaginated($conn, $search = '', $page = 1, $limit = 10) {
    $page = max(1, (int)$page);
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM users WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM users WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($search)) {
        $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $count_sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $like = "%$search%";
        $params = [$like, $like, $like];
        $types = "sss";
    }

    // Count first, so we know how many pages exist
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total / $limit));

    // Clamp page to a valid range instead of silently returning nothing
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'rows' => $rows,
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page
    ];
}

function getUsersCount($conn) {
    $result = $conn->query("SELECT COUNT(*) FROM users");
    return $result->fetch_row()[0] ?? 0;
}

function getUserById($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        return false;
    }
}

function getUserByEmail($conn, $email) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        return false;
    }
}

function createUser($conn, $data) {
    try {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users (email, password, full_name, phone, role, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssi",
            $data['email'],
            $hashedPassword,
            $data['full_name'],
            $data['phone'] ?? null,
            $data['role'] ?? 'patient',
            $data['is_active'] ?? 1
        );
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

function updateUser($conn, $id, $data) {
    try {
        $fields = [];
        $params = [];
        $types = "";
        
        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
            $types .= "s";
        }
        if (isset($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
            $types .= "s";
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = ?";
            $params[] = $data['phone'];
            $types .= "s";
        }
        if (isset($data['role'])) {
            $fields[] = "role = ?";
            $params[] = $data['role'];
            $types .= "s";
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
            $types .= "i";
        }
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            $types .= "s";
        }
        
        if (empty($fields)) return false;
        
        $params[] = $id;
        $types .= "i";
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        return $stmt->execute();
        
    } catch (Exception $e) {
        return false;
    }
}

function deleteUser($conn, $id) {
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

function toggleUserStatus($conn, $id) {
    try {
        $user = getUserById($conn, $id);
        if (!$user) return false;
        $newStatus = $user['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $id);
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

function getRoleCount($conn, $role) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_row()[0] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getActiveUsersCount($conn) {
    try {
        $result = $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
        return $result->fetch_row()[0] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}

// ========== ROW / PAGINATION RENDERERS ==========
// Single source of truth for row markup, shared by the initial PHP render
// and the AJAX live_search response, so they can never drift apart.
function renderUserRow($user) {
    ob_start();
    ?>
    <div class="user-table-row user-item" 
         data-id="<?= $user['id'] ?>"
         data-name="<?= h($user['full_name']) ?>" 
         data-email="<?= h($user['email']) ?>"
         data-phone="<?= h($user['phone']) ?>"
         data-role="<?= $user['role'] ?>"
         data-status="<?= $user['is_active'] ?>">

      <span class="font-medium flex items-center gap-3">
        <div class="avatar bg-teal-light text-teal">
          <?= getInitials($user['full_name']) ?>
        </div>
        <?= h($user['full_name']) ?>
      </span>

      <span class="text-gray-600 text-sm"><?= h($user['email']) ?></span>

      <span class="text-gray-600 text-sm"><?= h($user['phone']) ?: '—' ?></span>

      <span>
        <span class="role-badge <?= getRoleBadge($user['role']) ?>">
          <?= ucfirst($user['role']) ?>
        </span>
      </span>

      <span>
        <span class="status-badge <?= $user['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
          <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
      </span>

      <span class="text-right flex items-center justify-end gap-1">
        <button class="action-btn edit" onclick="editUser(<?= $user['id'] ?>)" title="Edit">
          <i class="fas fa-edit"></i>
        </button>
        <button class="action-btn toggle" onclick="toggleStatus(<?= $user['id'] ?>)" title="Toggle Status">
          <i class="fas fa-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
        </button>
        <button class="action-btn delete" onclick="deleteUser(<?= $user['id'] ?>)" title="Delete">
          <i class="fas fa-trash-alt"></i>
        </button>
      </span>
    </div>
    <?php
    return ob_get_clean();
}

function renderUserPagination($page, $total_pages, $total) {
    if ($total_pages <= 1) return '';
    ob_start();
    ?>
    <div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">
        <span class="text-xs text-gray-400"><?= $total ?> total records · Page <?= $page ?> of <?= $total_pages ?></span>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
                <button class="pagination-link" onclick="liveSearch(<?= $page - 1 ?>)"><i class="fas fa-chevron-left"></i></button>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <button class="pagination-link <?= $i === $page ? 'active' : '' ?>" onclick="liveSearch(<?= $i ?>)"><?= $i ?></button>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <button class="pagination-link" onclick="liveSearch(<?= $page + 1 ?>)"><i class="fas fa-chevron-right"></i></button>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    switch ($_POST['action']) {

        case 'live_search':
            $search = trim($_POST['search'] ?? '');
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 10;

            $result = getUsersPaginated($conn, $search, $page, $limit);

            $html = '';
            if (empty($result['rows'])) {
                $html = '<div class="text-center py-8 text-gray-400"><i class="fas fa-users text-3xl mb-2 block"></i><p>No users found</p></div>';
            } else {
                foreach ($result['rows'] as $user) {
                    $html .= renderUserRow($user);
                }
            }

            $response['success'] = true;
            $response['html'] = $html;
            $response['pagination'] = renderUserPagination($result['page'], $result['total_pages'], $result['total']);
            $response['total'] = $result['total'];
            break;

        case 'get_user':
            $id = $_POST['id'] ?? 0;
            $user = getUserById($conn, $id);
            if ($user) {
                unset($user['password']);
                $response['success'] = true;
                $response['user'] = $user;
            } else {
                $response['message'] = 'User not found';
            }
            break;
            
        case 'create':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $fullName = $_POST['full_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $role = $_POST['role'] ?? 'patient';
            
            if (empty($email) || empty($password) || empty($fullName)) {
                $response['message'] = 'Email, password and full name are required';
            } elseif (strlen($password) < 8) {
                $response['message'] = 'Password must be at least 8 characters';
            } elseif (getUserByEmail($conn, $email)) {
                $response['message'] = 'Email already exists';
            } else {
                $data = ['email' => $email, 'password' => $password, 'full_name' => $fullName, 'phone' => $phone, 'role' => $role, 'is_active' => 1];
                if (createUser($conn, $data)) {
                    $response['success'] = true;
                    $response['message'] = 'User created successfully';
                } else {
                    $response['message'] = 'Failed to create user';
                }
            }
            break;
            
        case 'update':
            $id = $_POST['id'] ?? 0;
            $data = [];
            if (isset($_POST['full_name'])) $data['full_name'] = $_POST['full_name'];
            if (isset($_POST['email'])) $data['email'] = $_POST['email'];
            if (isset($_POST['phone'])) $data['phone'] = $_POST['phone'];
            if (isset($_POST['role'])) $data['role'] = $_POST['role'];
            if (isset($_POST['is_active'])) $data['is_active'] = $_POST['is_active'];
            if (isset($_POST['password']) && !empty($_POST['password'])) {
                $data['password'] = $_POST['password'];
            }
            
            if (empty($id)) {
                $response['message'] = 'User ID is required';
            } elseif (empty($data)) {
                $response['message'] = 'No data to update';
            } elseif (updateUser($conn, $id, $data)) {
                $response['success'] = true;
                $response['message'] = 'User updated successfully';
            } else {
                $response['message'] = 'Failed to update user';
            }
            break;
            
        case 'delete':
            $id = $_POST['id'] ?? 0;
            if (empty($id)) {
                $response['message'] = 'User ID is required';
            } elseif ($id == $_SESSION['user_id']) {
                $response['message'] = 'You cannot delete your own account';
            } elseif (deleteUser($conn, $id)) {
                $response['success'] = true;
                $response['message'] = 'User deleted successfully';
            } else {
                $response['message'] = 'Failed to delete user';
            }
            break;
            
        case 'toggle_status':
            $id = $_POST['id'] ?? 0;
            if (empty($id)) {
                $response['message'] = 'User ID is required';
            } elseif (toggleUserStatus($conn, $id)) {
                $response['success'] = true;
                $response['message'] = 'User status updated successfully';
            } else {
                $response['message'] = 'Failed to update user status';
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// ========== GET USERS FOR INITIAL DISPLAY (paginated) ==========
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$userResult = getUsersPaginated($conn, '', $page, $limit);
$users = $userResult['rows'];

$totalUsers = getUsersCount($conn);
$activeUsers = getActiveUsersCount($conn);
$totalAdmins = getRoleCount($conn, 'admin');
$totalStaff = getRoleCount($conn, 'staff');
$totalPatients = getRoleCount($conn, 'patient');


include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Users · Dental Clinic Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
    * { font-family: 'DM Sans', system-ui, sans-serif; }
    h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }
    :root {
      --navy: #0b1628;
      --teal: #0d9e8c;
      --teal-dark: #0a7d6e;
      --teal-light: #e6f7f5;
      --off: #f7f9fc;
      --slate: #64748b;
      --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
    }
    body {
    background-color: #f8fafc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
}
    .bg-teal { background-color: var(--teal); }
    .text-teal { color: var(--teal); }
    .bg-dark-blue { background-color: var(--navy); }
    .text-dark-blue { color: var(--navy); }
    .bg-light-teal { background-color: var(--teal-light); }
    .bg-soft-gray { background-color: var(--off); }

    .container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
    @media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

    .btn-primary {
      background: var(--teal);
      color: white;
      padding: 10px 22px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 13px;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 14px rgba(13,158,140,0.25);
      border: none;
      cursor: pointer;
      text-decoration: none;
    }
    .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 10px 28px rgba(13,158,140,0.35); }
    .btn-outline {
      border: 1.5px solid var(--teal);
      color: var(--teal);
      padding: 8px 18px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 12px;
      transition: all 0.2s;
      background: transparent;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-outline:hover { background: var(--teal); color: white; }

    .stat-card {
      background: white;
      border-radius: 18px;
      padding: 1.5rem 1.25rem;
      box-shadow: var(--shadow-card);
      border: 1px solid #eef2f6;
      transition: all 0.2s;
    }
    .stat-card:hover { border-color: var(--teal); box-shadow: 0 12px 32px rgba(11,22,40,.08); }

    .dashboard-card {
      background: white;
      border-radius: 18px;
      padding: 1.5rem 1.25rem;
      box-shadow: var(--shadow-card);
      border: 1px solid #f0f3f7;
    }
    .dashboard-card .card-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--navy);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }

    .user-table-row {
      display: grid;
      grid-template-columns: 1.8fr 1.8fr 1.2fr 1fr 1fr 0.8fr;
      padding: 12px 0;
      border-bottom: 1px solid #f1f4f8;
      font-size: 13px;
      align-items: center;
    }
    .user-table-row.head {
      font-weight: 600;
      color: var(--slate);
      font-size: 11px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
      border-bottom: 2px solid #e9edf2;
      padding-bottom: 10px;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 50px;
      font-size: 11px;
      font-weight: 600;
    }
    .role-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 50px;
      font-size: 11px;
      font-weight: 600;
    }

    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
      flex-shrink: 0;
    }

    .pagination-link {
      padding: 6px 14px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      font-size: 13px;
      color: var(--slate);
      transition: all 0.2s;
      text-decoration: none;
      display: inline-block;
      cursor: pointer;
      background: white;
    }
    .pagination-link:hover { background: #f1f4f8; border-color: var(--teal); }
    .pagination-link.active { background: var(--teal); color: white; border-color: var(--teal); }

    @media (min-width: 1024px) {
      .main-content { margin-left: 260px; }
    }
    @media (max-width: 1023px) { .main-content { margin-left: 0; } }

    @media (max-width: 640px) {
      .user-table-row {
        grid-template-columns: 1fr 1fr;
        gap: 4px 8px;
        padding: 12px 0;
        font-size: 12px;
      }
      .user-table-row.head { display: none; }
      .stat-card { padding: 1rem; }
      .stat-card .text-3xl { font-size: 1.5rem; }
      .dashboard-card { padding: 1rem; }
    }

    .search-input {
      padding: 8px 16px 8px 40px;
      border-radius: 10px;
      border: 1.5px solid #e2e8f0;
      font-size: 13px;
      background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 12px center;
      background-size: 16px;
      transition: all 0.2s;
      width: 100%;
      max-width: 280px;
    }
    .search-input:focus {
      outline: none;
      border-color: var(--teal);
      box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
    }

    .action-btn {
      padding: 4px 8px;
      border-radius: 6px;
      transition: all 0.2s;
      cursor: pointer;
      border: none;
      background: transparent;
      font-size: 14px;
    }
    .action-btn:hover { background: #f1f4f8; }
    .action-btn.edit:hover { color: var(--teal); }
    .action-btn.delete:hover { color: #e53e3e; }
    .action-btn.toggle:hover { color: var(--teal); }

    /* Loading indicator for search */
    .search-loading { position: relative; }
    .search-loading .search-loader {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      display: none;
    }
    .search-loading.loading .search-loader { display: block; }
    .search-loader i { color: var(--teal); font-size: 13px; }

    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
  </style>
</head>
<body>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

  <main class="py-6 px-4">
    <div class="container-modern">

      <!-- HEADER -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold text-dark-blue">User Management</h1>
          <p class="text-sm text-gray-500">Manage all users registered in the system</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button class="btn-primary" onclick="openAddUserModal()">
            <i class="fas fa-user-plus"></i> Add User
          </button>
          <button class="btn-outline" onclick="exportUsers()">
            <i class="fas fa-file-export"></i> Export
          </button>
        </div>
      </div>

      <!-- STATS CARDS -->
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Users</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= $totalUsers ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Active</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= $activeUsers ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Admins</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= $totalAdmins ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Staff</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= $totalStaff ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Patients</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= $totalPatients ?></p>
        </div>
      </div>

      <!-- USER TABLE -->
      <div class="dashboard-card">
        <div class="card-title">
          <span><i class="fas fa-users mr-2 text-teal"></i> All Users</span>
          <div class="flex items-center gap-3">
            <div class="relative search-loading" id="searchContainer">
              <input type="text" class="search-input" id="searchInput" placeholder="Search users..." autocomplete="off">
              <div class="search-loader"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
          </div>
        </div>

        <div class="mt-4 overflow-x-auto" id="userTableContainer">
          <div class="user-table-row head">
            <span>User</span>
            <span>Email</span>
            <span>Phone</span>
            <span>Role</span>
            <span>Status</span>
            <span class="text-right">Actions</span>
          </div>

          <div id="userRows">
            <?php if (empty($users)): ?>
              <div class="text-center py-8 text-gray-400">
                <i class="fas fa-users text-3xl mb-2 block"></i>
                <p>No users found</p>
              </div>
            <?php else: ?>
              <?php foreach ($users as $user): ?>
                <?= renderUserRow($user) ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div id="userPagination">
          <?= renderUserPagination($userResult['page'], $userResult['total_pages'], $userResult['total']) ?>
        </div>
      </div>

      <div class="mt-8 text-center text-[11px] text-gray-400 border-t border-gray-200 pt-5">
        <i class="fas fa-tooth mr-1 text-teal/50"></i> Wansi Calacat Dental Clinic · User Management
      </div>
    </div>
  </main>
</div>

<!-- ===== ADD/EDIT USER MODAL ===== -->
<div id="userModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl" style="animation: slideUp 0.3s ease;">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-xl font-bold text-dark-blue" id="modalTitle">Add New User</h3>
      <button onclick="closeUserModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    
    <form id="userForm">
      <input type="hidden" id="userId" value="">
      <input type="hidden" id="formAction" value="create">
      
      <div class="mb-4">
        <label class="block text-sm font-semibold text-dark-blue mb-1">Full Name *</label>
        <input type="text" id="fullName" class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-teal focus:outline-none" required>
      </div>
      
      <div class="mb-4">
        <label class="block text-sm font-semibold text-dark-blue mb-1">Email *</label>
        <input type="email" id="email" class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-teal focus:outline-none" required>
      </div>
      
      <div class="mb-4">
        <label class="block text-sm font-semibold text-dark-blue mb-1">Phone</label>
        <input type="tel" id="phone" class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-teal focus:outline-none" placeholder="9123456789">
      </div>
      
      <div class="mb-4" id="passwordField">
        <label class="block text-sm font-semibold text-dark-blue mb-1">Password *</label>
        <input type="password" id="password" class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-teal focus:outline-none" placeholder="Min 8 characters" minlength="8">
        <p class="text-xs text-gray-400 mt-1">Leave blank to keep current password when editing</p>
      </div>
      
      <div class="mb-4">
        <label class="block text-sm font-semibold text-dark-blue mb-1">Role</label>
        <select id="role" class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-teal focus:outline-none">
          <option value="patient">Patient</option>
          <option value="staff">Staff</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      
      <div class="mb-4">
        <label class="flex items-center gap-3 cursor-pointer">
          <input type="checkbox" id="isActive" checked class="w-4 h-4 accent-teal">
          <span class="text-sm font-medium text-dark-blue">Active</span>
        </label>
      </div>
      
      <button type="submit" class="btn-primary w-full justify-center text-base py-3">
        <i class="fas fa-save"></i> <span id="submitBtnText">Create User</span>
      </button>
    </form>
  </div>
</div>

<script>
// ========== LIVE SEARCH / PAGINATION ==========
let searchTimeout = null;
let currentPage = <?= (int)$userResult['page'] ?>;

function liveSearch(page = 1) {
    const search = document.getElementById('searchInput').value.trim();
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');

    const formData = new FormData();
    formData.append('action', 'live_search');
    formData.append('search', search);
    formData.append('page', page);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (searchContainer) searchContainer.classList.remove('loading');
            if (data.success) {
                document.getElementById('userRows').innerHTML = data.html;
                document.getElementById('userPagination').innerHTML = data.pagination;
                currentPage = page;
            }
        })
        .catch(() => {
            if (searchContainer) searchContainer.classList.remove('loading');
        });
}

document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');
    searchTimeout = setTimeout(() => liveSearch(1), 400);
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimeout);
        liveSearch(1);
    }
});

// ========== MODAL FUNCTIONS ==========
function openAddUserModal() {
    document.getElementById('modalTitle').textContent = 'Add New User';
    document.getElementById('submitBtnText').textContent = 'Create User';
    document.getElementById('formAction').value = 'create';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('password').required = true;
    document.getElementById('passwordField').style.display = 'block';
    document.getElementById('isActive').checked = true;
    document.getElementById('userModal').classList.remove('hidden');
}

function closeUserModal() {
    document.getElementById('userModal').classList.add('hidden');
}

async function editUser(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_user');
        formData.append('id', id);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            const user = data.user;
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('submitBtnText').textContent = 'Update User';
            document.getElementById('formAction').value = 'update';
            document.getElementById('userId').value = user.id;
            document.getElementById('fullName').value = user.full_name;
            document.getElementById('email').value = user.email;
            document.getElementById('phone').value = user.phone || '';
            document.getElementById('role').value = user.role;
            document.getElementById('isActive').checked = user.is_active == 1;
            document.getElementById('password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('passwordField').style.display = 'block';
            document.getElementById('userModal').classList.remove('hidden');
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load user data' });
    }
}

// ========== FORM SUBMIT ==========
document.getElementById('userForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const action = document.getElementById('formAction').value;
    const id = document.getElementById('userId').value;
    const fullName = document.getElementById('fullName').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const password = document.getElementById('password').value;
    const role = document.getElementById('role').value;
    const isActive = document.getElementById('isActive').checked ? 1 : 0;
    
    if (!fullName || !email) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Name and email are required.' });
        return;
    }
    
    if (action === 'create' && password.length < 8) {
        Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must be at least 8 characters.' });
        return;
    }
    
    const formData = new FormData();
    formData.append('action', action);
    if (id) formData.append('id', id);
    formData.append('full_name', fullName);
    formData.append('email', email);
    formData.append('phone', phone);
    formData.append('role', role);
    formData.append('is_active', isActive);
    if (password) formData.append('password', password);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false });
            closeUserModal();
            setTimeout(() => liveSearch(currentPage), 500);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.' });
    }
});

// ========== DELETE USER ==========
function deleteUser(id) {
    Swal.fire({
        title: 'Delete User?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e53e3e',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message, timer: 2000, showConfirmButton: false });
                    setTimeout(() => liveSearch(currentPage), 500);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to delete user.' });
            }
        }
    });
}

// ========== TOGGLE STATUS ==========
function toggleStatus(id) {
    Swal.fire({
        title: 'Toggle Status?',
        text: 'Change this user\'s active status.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, toggle',
        cancelButtonText: 'Cancel'
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: data.message, timer: 2000, showConfirmButton: false });
                    setTimeout(() => liveSearch(currentPage), 500);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to update status.' });
            }
        }
    });
}

// ========== EXPORT USERS ==========
// Note: this only exports rows currently in the DOM (the current page).
// For a full-dataset export, a separate server-side endpoint querying
// all users (like appointments_export.php) would be needed.
function exportUsers() {
    const users = document.querySelectorAll('.user-item');
    let csv = 'Name,Email,Phone,Role,Status\n';
    
    users.forEach(item => {
        const name = item.dataset.name;
        const email = item.dataset.email;
        const phone = item.dataset.phone || '';
        const role = item.dataset.role;
        const status = item.dataset.status == '1' ? 'Active' : 'Inactive';
        csv += `"${name}","${email}","${phone}","${role}","${status}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'users_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// ========== CLOSE MODAL ON CLICK OUTSIDE ==========
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeUserModal();
});

// ========== CLOSE MODAL ON ESC ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUserModal();
});

// ========== PHONE NUMBER FORMATTING ==========
document.getElementById('phone').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9+]/g, '');
});
</script>

</body>
</html>