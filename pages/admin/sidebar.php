<!-- sidebar.php - Enhanced Sidebar Component with Dynamic Active States -->
<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to determine active state
if (!function_exists('isActive')) {
    function isActive($page, $current_page) {
        return $current_page === $page ? 'active' : '';
    }
}

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// --- Fetch logged-in user data ---
$user_name = 'Guest';
$user_email = 'guest@clinic.com';
$user_role = '';

if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (!empty($_SESSION['user_name'])) {
        $user_name = $_SESSION['user_name'];
        $user_email = $_SESSION['user_email'] ?? 'user@clinic.com';
        $user_role = $_SESSION['user_role'] ?? '';
    } else {
        try {
            require_once __DIR__ . '/../conn/conn.php';
            
            if ($conn) {
                $stmt = $conn->prepare("SELECT full_name, email, role FROM users WHERE id = ?");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                
                if ($user) {
                    $user_name = $user['full_name'];
                    $user_email = $user['email'];
                    $user_role = $user['role'];
                    
                    $_SESSION['user_name'] = $user_name;
                    $_SESSION['user_email'] = $user_email;
                    $_SESSION['user_role'] = $user_role;
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Sidebar user fetch error: " . $e->getMessage());
        }
    }
}
?>
<aside id="sidebar" class="sidebar">
  <div class="sidebar-brand flex items-center gap-3">
    <div class="brand-icon">
      <i class="fas fa-tooth"></i>
    </div>
    <div style="flex:1; min-width:0;">
      <div class="logo-name">Wansi Calacat</div>
      <div class="logo-clinic">Clinic Admin</div>
    </div>

    <!-- Bell notification icon -->
    <div class="sidebar-notif-wrap">
      <button type="button" id="sidebarNotifBtn" class="sidebar-notif-btn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-bell"></i>
        <span class="sidebar-notif-badge hidden" data-badge="pending_total">0</span>
      </button>

      <div class="sidebar-notif-panel" id="sidebarNotifPanel">
        <div class="notif-panel-header">
          <span>Pending Appointments</span>
          <button type="button" class="notif-panel-close" id="sidebarNotifClose" aria-label="Close">&times;</button>
        </div>
        <div class="notif-panel-body" id="sidebarNotifBody">
          <div class="notif-loading"><i class="fas fa-spinner fa-spin"></i>Loading…</div>
        </div>
        <div class="notif-panel-footer">
          <a href="appointments.php?status=pending">View all pending →</a>
        </div>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a href="dashboard.php" class="<?= isActive('dashboard.php', $current_page) ?>" <?= isActive('dashboard.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
      <span>Dashboard</span>
    </a>

    <div class="nav-item has-dropdown">
      <a href="appointments.php" class="<?= isActive('appointments.php', $current_page) ?>" <?= isActive('appointments.php', $current_page) ? 'aria-current="page"' : '' ?> aria-haspopup="true" aria-expanded="false">
        <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
        <span>Appointments</span>
        <span class="nav-badge hidden" data-badge="pending_appointments">0</span>
        <i class="fas fa-chevron-down nav-caret"></i>
      </a>
      <div class="nav-submenu">
        <a href="appointments.php" class="submenu-link">
          <span>Appointments</span>
          <span class="nav-badge submenu-badge hidden" data-badge="pending_appointments">0</span>
        </a>
        <a href="today.php" class="submenu-link">
          <span>Today</span>
          <span class="nav-badge submenu-badge hidden" data-badge="today_appointments">0</span>
        </a>
        <a href="queue.php" class="submenu-link">
          <span>Queue</span>
        </a>
      </div>
    </div>

    <a href="availability.php" class="<?= isActive('availability.php', $current_page) ?>" <?= isActive('availability.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-clock"></i></span>
      <span>Availability</span>
    </a>

    <a href="patients.php" class="<?= isActive('patients.php', $current_page) ?>" <?= isActive('patients.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-users"></i></span>
      <span>Patients</span>
      <span class="nav-badge hidden" data-badge="unverified">0</span>
    </a>

    <div class="nav-item has-dropdown">
      <a href="history.php" class="<?= isActive('history.php', $current_page) ?>" <?= isActive('history.php', $current_page) ? 'aria-current="page"' : '' ?> aria-haspopup="true" aria-expanded="false">
        <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
        <span>History</span>
        <i class="fas fa-chevron-down nav-caret"></i>
      </a>
      <div class="nav-submenu">
        <a href="patient-record-history.php">Appointment archives</a>
        <a href="audit-log.php">Audit</a>
        <a href="backup.php">Back up</a>
      </div>
    </div>

    <div class="nav-label">Content</div>

    <a href="users.php" class="<?= isActive('users.php', $current_page) ?>" <?= isActive('users.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-users"></i></span>
      <span>Users Account</span>
    </a>
    <a href="manage_dentists.php" class="<?= isActive('manage_dentists.php', $current_page) ?>" <?= isActive('manage_dentists.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-user"></i></span>
      <span>Dentists</span>
    </a>

    <div class="nav-label">System</div>
    <a href="homeset.php" class="<?= isActive('homeset.php', $current_page) ?>" <?= isActive('homeset.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-sliders-h"></i></span>
      <span>Home Settings</span>
    </a>
    <a href="chat.php" class="<?= isActive('chat.php', $current_page) ?>">
      <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
      <span>Chat</span>
      <span class="nav-badge hidden" data-badge="chat_total">0</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar-icon">
        <i class="fas fa-user-md"></i>
      </div>
      <div class="user-info">
        <p class="user-name"><?= htmlspecialchars($user_name) ?></p>
        <p class="user-email"><?= htmlspecialchars($user_email) ?></p>
        <?php if ($user_role): ?>
          <span class="role-badge" style="
              font-size: 9px;
              background: rgba(13, 158, 140, 0.2);
              color: var(--sb-teal);
              padding: 2px 10px;
              border-radius: 20px;
              text-transform: uppercase;
              letter-spacing: 0.5px;
              font-weight: 600;
              display: inline-block;
              margin-top: 2px;
          "><?= htmlspecialchars($user_role) ?></span>
        <?php endif; ?>
      </div>
      <div class="user-options-wrap">
        <button type="button" class="user-options" id="userOptionsBtn" aria-haspopup="true" aria-expanded="false" aria-label="User options">
          <i class="fas fa-ellipsis-v"></i>
        </button>
        <div class="user-options-menu" id="userOptionsMenu">
          <a href="profile.php"><i class="fas fa-user-circle"></i> Profile</a>
          <a href="#" id="logoutBtn" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
      </div>
    </div>
  </div>
</aside>

<!-- Overlay for mobile -->
<div id="sidebarOverlay" class="sidebar-overlay"></div>

<!-- Floating toggle button (mobile) -->
<button id="sidebarToggle" class="sidebar-toggle-btn" aria-label="Toggle sidebar">
  <i class="fas fa-bars"></i>
</button>

<!-- jQuery (needed for the AJAX notification polling) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
  /* ===== SIDEBAR STYLES ===== */
  #sidebar, #sidebar *,
  .sidebar-overlay,
  .sidebar-toggle-btn {
    box-sizing: border-box;
  }

  :root {
    --sb-navy: #0b1628;
    --sb-navy-light: #131f35;
    --sb-teal: #0d9e8c;
    --sb-teal-light: #e6f7f5;
    --sb-teal-glow: rgba(13, 158, 140, 0.25);
    --sidebar-width: 270px;
    --sb-radius: 14px;
  }

  /* ===== NOTIFICATION BADGES ===== */
  .sidebar-nav a .nav-badge {
    margin-left: auto;
    background: #e53e3e;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 50px;
    min-width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
    box-shadow: 0 2px 6px rgba(229, 62, 62, 0.4);
    transition: transform 0.15s ease;
  }

  .sidebar-nav a .nav-badge.hidden {
    display: none;
  }

  .sidebar-nav a .nav-badge.pulse {
    animation: badge-pulse 1.6s ease-in-out infinite;
  }

  @keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.12); }
  }

  .chat-tab-badge {
    background: #e53e3e;
    color: white;
    font-size: 10px;
    font-weight: 800;
    border-radius: 50px;
    min-width: 16px;
    height: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
  }
  .chat-tab-badge.hidden { display: none; }

  /* ===== Sidebar notification bell ===== */
  .sidebar-notif-wrap {
    position: relative;
    flex-shrink: 0;
    z-index: 9998;
  }

  .sidebar-notif-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #b7c2d0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    cursor: pointer;
    transition: all .18s ease;
  }
  .sidebar-notif-btn:hover,
  .sidebar-notif-btn[aria-expanded="true"] {
    background: rgba(13, 158, 140, 0.18);
    color: var(--sb-teal);
    border-color: rgba(13, 158, 140, 0.35);
  }

  .sidebar-notif-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    background: #e53e3e;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(229, 62, 62, 0.5);
    border: 2px solid var(--sb-navy);
    line-height: 1;
  }
  .sidebar-notif-badge.hidden { display: none; }
  .sidebar-notif-badge.pulse {
    animation: badge-pulse 1.6s ease-in-out infinite;
  }

  /* Dropdown panel */
  .sidebar-notif-panel {
    position: absolute;
    top: calc(100% + 10px);
    right: -15px;
    width: 250px;
    max-width: calc(100vw - 40px);
    background: #131f35;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55);
    display: none;
    z-index: 9999;
    overflow: hidden;
  }
  .sidebar-notif-panel.show { display: block; }

  .notif-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    font-size: 11.5px;
    font-weight: 700;
    color: #e2e8f0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .notif-panel-close {
    background: none;
    border: none;
    color: #7a8aa0;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 6px;
  }
  .notif-panel-close:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }

  .notif-panel-body {
    max-height: 320px;
    overflow-y: auto;
    padding: 6px;
  }
  .notif-panel-body::-webkit-scrollbar { width: 4px; }
  .notif-panel-body::-webkit-scrollbar-thumb { background: #2d3a54; border-radius: 10px; }

  .notif-item {
    display: flex;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 9px;
    text-decoration: none;
    color: inherit;
    transition: background .15s;
  }
  .notif-item:hover { background: rgba(255, 255, 255, 0.05); }

  .notif-item-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(13, 158, 140, 0.22);
    color: var(--sb-teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    flex-shrink: 0;
  }
  .notif-item-body { flex: 1; min-width: 0; }
  .notif-item-name {
    font-size: 12.5px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .notif-item-meta {
    font-size: 10.5px;
    color: #8a9bb0;
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .notif-item-meta i { color: var(--sb-teal); font-size: 9px; }

  .notif-empty {
    padding: 28px 16px;
    text-align: center;
    font-size: 12.5px;
    color: #6a7e96;
  }
  .notif-empty i {
    display: block;
    font-size: 22px;
    color: #2d3a54;
    margin-bottom: 6px;
  }
  .notif-loading {
    padding: 24px 16px;
    text-align: center;
    font-size: 12px;
    color: #6a7e96;
  }
  .notif-loading i { color: var(--sb-teal); margin-right: 6px; }

  .notif-panel-footer {
    padding: 10px 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    text-align: center;
  }
  .notif-panel-footer a {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--sb-teal);
    text-decoration: none;
  }
  .notif-panel-footer a:hover { text-decoration: underline; }

  /* Sidebar container */
  .sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: var(--sidebar-width);
    background: var(--sb-navy);
    color: #e2e8f0;
    z-index: 60;
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    display: flex;
    flex-direction: column;
    box-shadow: 6px 0 30px rgba(0, 0, 0, 0.4);
    border-right: 1px solid rgba(255, 255, 255, 0.04);
    backdrop-filter: blur(2px);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    overflow: visible;
  }

  .sidebar.open {
    transform: translateX(0);
  }

  /* Brand area */
  .sidebar-brand {
    padding: 1.8rem 1.5rem 1.2rem 1.8rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 100;
    overflow: visible;
  }

  .brand-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(145deg, var(--sb-teal), #0a7d6e);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: white;
    box-shadow: 0 6px 18px rgba(13, 158, 140, 0.35);
    transition: transform 0.2s;
    flex-shrink: 0;
  }

  .sidebar-brand:hover .brand-icon {
    transform: scale(1.02) rotate(-2deg);
  }

  .logo-name {
    color: white;
    font-size: 18px;
    font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
    letter-spacing: -0.2px;
    line-height: 1.2;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .logo-clinic {
    color: var(--sb-teal);
    font-size: 10px;
    letter-spacing: 2px;
    font-weight: 600;
    text-transform: uppercase;
    opacity: 0.8;
    white-space: nowrap;
  }

  /* Navigation */
  .sidebar-nav {
    flex: 1;
    padding: 1.5rem 1.2rem 0.5rem;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #2d3a54 transparent;
  }

  .sidebar-nav::-webkit-scrollbar {
    width: 4px;
  }
  .sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
  }
  .sidebar-nav::-webkit-scrollbar-thumb {
    background: #2d3a54;
    border-radius: 10px;
  }

  .nav-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #5a6e8a;
    padding: 0.6rem 0.8rem 0.4rem;
    margin-top: 0.4rem;
  }

  .sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0.7rem 1rem;
    border-radius: var(--sb-radius);
    font-size: 13.5px;
    font-weight: 500;
    color: #b7c2d0;
    transition: all 0.18s ease;
    margin-bottom: 2px;
    text-decoration: none;
    position: relative;
  }

  .sidebar-nav a .nav-icon {
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    color: #7a8aa0;
    font-size: 15px;
    transition: all 0.2s;
  }

  .sidebar-nav a:hover .nav-icon {
    background: rgba(13, 158, 140, 0.18);
    color: var(--sb-teal);
  }
  .sidebar-nav a span:not(.nav-icon):not(.nav-badge):not(.nav-caret) {
    flex: 1;
  }

  .sidebar-nav a:hover {
    background: rgba(255, 255, 255, 0.05);
    color: white;
    transform: translateX(4px);
  }

  .sidebar-nav a.active {
    background: rgba(13, 158, 140, 0.14);
    color: white;
    box-shadow: inset 3px 0 0 var(--sb-teal);
  }

  .sidebar-nav a.active .nav-icon {
    background: rgba(13, 158, 140, 0.25);
    color: var(--sb-teal);
  }

  .sidebar-nav .nav-item.has-dropdown {
    position: relative;
  }

  .sidebar-nav .nav-item.has-dropdown > a .nav-caret {
    margin-left: auto;
    font-size: 10px;
    color: #5a6e8a;
    transition: transform 0.2s ease, color 0.2s ease;
    flex: none;
  }

  .sidebar-nav .nav-item.has-dropdown.open > a .nav-caret {
    transform: rotate(180deg);
    color: var(--sb-teal);
  }

  /* Submenu */
  .nav-submenu {
    display: flex;
    flex-direction: column;
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 10px;
    padding: 0 4px;
    margin-top: 0;
    transition: max-height 0.25s ease, opacity 0.2s ease, margin-top 0.25s ease, padding 0.25s ease;
  }

  .nav-item.has-dropdown.open .nav-submenu {
    max-height: 240px;
    opacity: 1;
    margin-top: 4px;
    padding: 6px 4px;
  }

  .nav-submenu a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 500;
    color: #b7c2d0;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    margin-bottom: 0;
    transform: none !important;
  }

  .nav-submenu a:hover {
    background: rgba(13, 158, 140, 0.16);
    color: white;
  }

  /* Submenu badges — slightly smaller */
  .sidebar-nav .nav-submenu a .submenu-badge {
    margin-left: 8px;
    padding: 1px 6px;
    min-width: 16px;
    height: 16px;
    font-size: 9px;
  }

  /* Today's badge — teal accent */
  .sidebar-nav .nav-submenu a .submenu-badge[data-badge="today_appointments"] {
    background: var(--sb-teal);
    box-shadow: 0 2px 6px rgba(13, 158, 140, 0.4);
  }

  /* Footer / user card */
  .sidebar-footer {
    padding: 1rem 1.5rem 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    margin-top: auto;
  }

  .user-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.04);
    padding: 0.7rem 1rem 0.7rem 0.8rem;
    border-radius: 16px;
    transition: background 0.2s;
    position: relative;
  }

  .user-card:hover {
    background: rgba(255, 255, 255, 0.07);
  }

  .avatar-icon {
    width: 38px;
    height: 38px;
    background: var(--sb-teal-light);
    color: var(--sb-teal);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    transition: 0.2s;
  }

  .user-card:hover .avatar-icon {
    background: white;
    transform: scale(1.02);
  }

  .user-info {
    flex: 1;
    min-width: 0;
  }

  .user-name {
    font-size: 13px;
    font-weight: 600;
    color: white;
    margin: 0;
    line-height: 1.3;
  }

  .user-email {
    font-size: 11px;
    color: #8a9bb0;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .user-options-wrap {
    position: relative;
    flex-shrink: 0;
  }

  .user-options {
    background: transparent;
    border: none;
    color: #6a7e96;
    font-size: 14px;
    padding: 4px 6px;
    border-radius: 8px;
    cursor: pointer;
    transition: 0.2s;
  }

  .user-options:hover,
  .user-options[aria-expanded="true"] {
    background: rgba(255, 255, 255, 0.08);
    color: white;
  }

  .user-options-menu {
    position: absolute;
    bottom: calc(100% + 8px);
    right: 0;
    min-width: 160px;
    background: var(--sb-navy-light);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45);
    padding: 6px;
    display: none;
    z-index: 80;
  }

  .user-options-menu.show {
    display: block;
  }

  .user-options-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 500;
    color: #cbd5e1;
    text-decoration: none;
    transition: 0.15s;
  }

  .user-options-menu a:hover {
    background: rgba(255, 255, 255, 0.06);
    color: white;
  }

  .user-options-menu a.danger:hover {
    background: rgba(231, 76, 60, 0.15);
    color: #ff8a7a;
  }

  /* Overlay */
  .sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 50;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
    backdrop-filter: blur(2px);
  }

  .sidebar-overlay.show {
    opacity: 1;
    pointer-events: auto;
  }

  /* Toggle button (mobile) */
  .sidebar-toggle-btn {
    position: fixed;
    bottom: 1.8rem;
    right: 1.8rem;
    z-index: 70;
    background: var(--sb-teal);
    color: white;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow: 0 10px 28px rgba(13, 158, 140, 0.45);
    border: none;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
  }

  .sidebar-toggle-btn:hover {
    background: #0a7d6e;
    transform: scale(1.06);
    box-shadow: 0 12px 32px rgba(13, 158, 140, 0.55);
  }

  .sidebar-toggle-btn:active {
    transform: scale(0.94);
  }

  /* Desktop: always visible */
  @media (min-width: 1024px) {
    .sidebar {
      transform: translateX(0);
      box-shadow: 4px 0 30px rgba(0, 0, 0, 0.2);
    }
    .sidebar-overlay {
      display: none;
    }
    .sidebar-toggle-btn {
      display: none;
    }
  }

  /* Mobile adjustments */
  @media (max-width: 1023px) {
    .sidebar {
      width: 280px;
    }
    .sidebar-brand {
      padding: 1.4rem 1.2rem 1rem;
    }
    .sidebar-nav {
      padding: 1rem 0.8rem 0.5rem;
    }
    .sidebar-nav a {
      padding: 0.65rem 0.9rem;
      font-size: 14px;
    }
  }

  @media (max-width: 480px) {
    .sidebar {
      width: 260px;
    }
    .sidebar-notif-panel {
      right: -10px;
      width: 260px;
    }
  }
</style>

<script>
/* ============================================================
   SIDEBAR TOGGLE + USER MENU
   ============================================================ */
(function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggle');
    const userBtn = document.getElementById('userOptionsBtn');
    const userMenu = document.getElementById('userOptionsMenu');

    if (!sidebar) return;

    function openSidebar() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    function closeUserMenu() {
        if (!userMenu || !userBtn) return;
        userMenu.classList.remove('show');
        userBtn.setAttribute('aria-expanded', 'false');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = userMenu.classList.toggle('show');
            userBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function(e) {
            if (!userMenu.contains(e.target) && e.target !== userBtn) {
                closeUserMenu();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
            closeUserMenu();
        }
    });

    document.querySelectorAll('.sidebar-nav a').forEach(function(link) {
        const isDropdownParent = link.parentElement.classList.contains('has-dropdown') && !link.classList.contains('submenu-link');
        if (isDropdownParent) return;

        link.addEventListener('click', function() {
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        });
    });

    document.querySelectorAll('.nav-item.has-dropdown > a').forEach(function(parentLink) {
        parentLink.addEventListener('click', function(e) {
            e.preventDefault();
            const item = this.closest('.nav-item');
            const isOpen = item.classList.toggle('open');
            this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth >= 1024) {
                sidebar.classList.add('open');
                if (overlay) overlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        }, 100);
    });

    if (window.innerWidth >= 1024) {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.remove('show');
    }
})();


/* ============================================================
   LOGOUT
   ============================================================ */
document.getElementById('logoutBtn').addEventListener('click', function(e) {
    e.preventDefault();

    Swal.fire({
        title: 'Are you sure?',
        text: "You will be logged out of your account.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, logout!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Logging out...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            fetch('../../api/logout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Logged Out!',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = '../../index.php';
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message || 'Logout failed. Please try again.'
                    });
                }
            })
            .catch(error => {
                console.error('Logout error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'An error occurred during logout. Please try again.'
                });
            });
        }
    });
});


/* ============================================================
   NOTIFICATION BADGES — jQuery AJAX + seen-tracking
   - Bell: always shows pending_total (never resets on view)
   - Parent Appointments badge: sum of pending_appointments + today_appointments (seen-aware)
   - Submenu Appointments: pending_appointments (seen-aware)
   - Submenu Today: today_appointments (seen-aware)
   - Patients: unverified
   - Chat: chat_total
   ============================================================ */
(function () {
    if (typeof jQuery === 'undefined') {
        console.warn('[Notif] jQuery not loaded — badges will not update.');
        return;
    }

    const API_URL = '../../api/notification_counts.php';
    const CURRENT_PAGE = '<?= htmlspecialchars($current_page, ENT_QUOTES) ?>';

    let pollTimer = null;
    let lastCounts = {};
    let consecutiveFailures = 0;
    let seenPosted = { appointments: false, today: false };

    // When the user is currently looking at appointments.php or today.php,
    // mark it as seen (once per page load) so the badge resets server-side.
    function stampSeenIfOnPage() {
        if (CURRENT_PAGE === 'appointments.php' && !seenPosted.appointments) {
            seenPosted.appointments = true;
            $.post('../../api/mark_seen.php', { what: 'appointments' });
        }
        if (CURRENT_PAGE === 'today.php' && !seenPosted.today) {
            seenPosted.today = true;
            $.post('../../api/mark_seen.php', { what: 'today' });
        }
    }

    function applyBadges(counts) {
        const chatTotal = (counts.patient_chat || 0) + (counts.guest_chat || 0) + (counts.team_chat || 0);

        const map = {
            pending_total:        counts.pending_total        || 0,   // bell — always total
            pending_appointments: counts.pending_appointments || 0,   // parent + submenu — seen-aware
            today_appointments:   counts.today_appointments   || 0,
            unverified:           counts.unverified           || 0,
            chat_total:           chatTotal,
        };

        // Parent Appointments nav badge = sum of its children
        const parentAppointmentsTotal = map.pending_appointments + map.today_appointments;

        // ---- Bell badge — always shows the TOTAL pending count ----
        const $bellBadge = $('.sidebar-notif-badge[data-badge="pending_total"]');
        if ($bellBadge.length) {
            const val = map.pending_total;
            if (val > 0) {
                $bellBadge.text(val > 99 ? '99+' : val).removeClass('hidden');
                if (lastCounts.pending_total !== undefined && val > lastCounts.pending_total) {
                    $bellBadge.addClass('pulse');
                    setTimeout(() => $bellBadge.removeClass('pulse'), 3000);
                }
            } else {
                $bellBadge.addClass('hidden');
            }
        }

        // ---- Parent Appointments nav badge — combined total of submenu ----
        const $parentApptBadge = $('.sidebar-nav .nav-item.has-dropdown > a .nav-badge[data-badge="pending_appointments"]');
        if ($parentApptBadge.length) {
            const val = parentAppointmentsTotal;
            if (val > 0) {
                $parentApptBadge.text(val > 99 ? '99+' : val).removeClass('hidden');
                if (lastCounts.parent_appointments_total !== undefined && val > lastCounts.parent_appointments_total) {
                    $parentApptBadge.addClass('pulse');
                    setTimeout(() => $parentApptBadge.removeClass('pulse'), 3000);
                }
            } else {
                $parentApptBadge.addClass('hidden');
            }
        }

        // ---- All other badges (submenu Appointments, submenu Today, Patients, Chat) ----
        $('.nav-badge[data-badge]').each(function () {
            const $el = $(this);

            // Skip the parent Appointments badge (already handled above)
            if ($el.closest('.nav-item.has-dropdown').length &&
                $el.parent().is('.nav-item.has-dropdown > a')) {
                return;
            }

            const key = $el.data('badge');
            const val = map[key] ?? 0;

            if (val > 0) {
                $el.text(val > 99 ? '99+' : val).removeClass('hidden');

                if (lastCounts[key] !== undefined && val > lastCounts[key]) {
                    $el.addClass('pulse');
                    setTimeout(() => $el.removeClass('pulse'), 3000);
                }
            } else {
                $el.addClass('hidden');
            }
        });

        lastCounts = {
            ...lastCounts,
            ...map,
            parent_appointments_total: parentAppointmentsTotal,
        };
        consecutiveFailures = 0;
    }

    function fetchCounts() {
        if (document.hidden) return;

        $.ajax({
            url: API_URL,
            method: 'GET',
            dataType: 'json',
            cache: false,
            xhrFields: { withCredentials: true },
            timeout: 10000
        })
        .done(function (data) {
            if (data && data.success && data.counts) {
                applyBadges(data.counts);
            } else if (data && data.success === false) {
                console.warn('[Notif] API returned success=false — likely not logged in.');
            }
        })
        .fail(function (xhr, status, err) {
            consecutiveFailures++;
            if (consecutiveFailures <= 3) {
                console.warn('[Notif] Poll failed:', status, err || xhr.status);
            }
        });
    }

    function startPolling() {
        stampSeenIfOnPage();                        // reset badge for THIS page, once
        fetchCounts();                              // immediate
        pollTimer = setInterval(fetchCounts, 5000); // every 5s
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    $(document).on('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            fetchCounts();
            if (!pollTimer) pollTimer = setInterval(fetchCounts, 5000);
        }
    });

    $(function () {
        startPolling();
    });

    window.refreshBadges = fetchCounts;
})();


/* ============================================================
   SIDEBAR BELL — open/close + list of pending appointments
   ============================================================ */
(function () {
    if (typeof jQuery === 'undefined') return;

    const $btn      = $('#sidebarNotifBtn');
    const $panel    = $('#sidebarNotifPanel');
    const $closeBtn = $('#sidebarNotifClose');
    const $bodyEl   = $('#sidebarNotifBody');
    if (!$btn.length || !$panel.length) return;

    let loadedOnce = false;
    let lastLoadTime = 0;

    function openPanel() {
        $panel.addClass('show');
        $btn.attr('aria-expanded', 'true');

        const now = Date.now();
        if (!loadedOnce || (now - lastLoadTime) > 10000) {
            fetchPendingList();
        }
    }

    function closePanel() {
        $panel.removeClass('show');
        $btn.attr('aria-expanded', 'false');
    }

    function fetchPendingList() {
        $bodyEl.html('<div class="notif-loading"><i class="fas fa-spinner fa-spin"></i>Loading…</div>');

        $.ajax({
            url: '../../api/notification_counts.php',
            method: 'GET',
            data: { with_pending: 1 },
            dataType: 'json',
            cache: false,
            xhrFields: { withCredentials: true },
            timeout: 10000
        })
        .done(function (data) {
            loadedOnce = true;
            lastLoadTime = Date.now();

            if (!data || !data.success) {
                $bodyEl.html('<div class="notif-empty"><i class="fas fa-lock"></i>Please sign in again.</div>');
                return;
            }

            const items = data.pending_list || [];
            renderPending(items);
        })
        .fail(function () {
            $bodyEl.html('<div class="notif-empty"><i class="fas fa-exclamation-triangle"></i>Could not load notifications.</div>');
        });
    }

    function renderPending(items) {
        if (!items.length) {
            $bodyEl.html('<div class="notif-empty"><i class="fas fa-bell-slash"></i>No pending appointments.</div>');
            return;
        }

        let html = '';
        items.slice(0, 8).forEach(function (a) {
            const name = a.full_name || 'Unnamed Patient';
            const initials = name.trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();

            let dateStr = '—';
            if (a.appointment_date) {
                const d = new Date(a.appointment_date + 'T00:00:00');
                dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }

            let timeStr = '';
            if (a.appointment_time) {
                try {
                    const t = new Date('1970-01-01T' + a.appointment_time);
                    timeStr = t.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                } catch (e) {
                    timeStr = a.appointment_time;
                }
            }

            const safeName = $('<div>').text(name).html();

            html += `
                <a href="appointment_details.php?id=${a.id}" class="notif-item">
                    <div class="notif-item-avatar">${initials || '?'}</div>
                    <div class="notif-item-body">
                        <div class="notif-item-name">${safeName}</div>
                        <div class="notif-item-meta">
                            <span><i class="far fa-calendar-alt"></i> ${dateStr}</span>
                            ${timeStr ? `<span><i class="far fa-clock"></i> ${timeStr}</span>` : ''}
                        </div>
                    </div>
                </a>`;
        });

        $bodyEl.html(html);
    }

    $btn.on('click', function (e) {
        e.stopPropagation();
        $panel.hasClass('show') ? closePanel() : openPanel();
    });

    $closeBtn.on('click', function (e) {
        e.stopPropagation();
        closePanel();
    });

    $(document).on('click', function (e) {
        if (!$panel.is(e.target) && $panel.has(e.target).length === 0 &&
            !$btn.is(e.target) && $btn.has(e.target).length === 0) {
            closePanel();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });
})();
</script>