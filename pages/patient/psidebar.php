<!-- sidebar-patient.php - Patient Sidebar Component -->
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

// Get user data from session
$user_name  = $_SESSION['user_name']  ?? 'Patient';
$user_email = $_SESSION['user_email'] ?? 'patient@clinic.com';
$user_role  = $_SESSION['user_role']  ?? 'patient';
?>
<aside id="sidebar" class="sidebar">
  <div class="sidebar-brand flex items-center gap-3">
    <div class="brand-icon">
      <i class="fas fa-tooth"></i>
    </div>
    <div>
      <div class="logo-name">Wansi Calacat</div>
      <div class="logo-clinic">Patient Portal</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>

    <!-- Dashboard -->
  <a href="patient-dashboard.php" class="<?= isActive('patient-dashboard.php', $current_page) ?>" <?= isActive('patient-dashboard.php', $current_page) ? 'aria-current="page"' : '' ?>>
  <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
  <span>Dashboard</span>
  <span class="nav-badge hidden" data-badge="pending_appointments">0</span>
</a>

   <a href="book-appointment.php" class="<?= isActive('book-appointment.php', $current_page) ?>" <?= isActive('book-appointment.php', $current_page) ? 'aria-current="page"' : '' ?>>
  <span class="nav-icon"><i class="fas fa-calendar-plus"></i></span>
  <span>Book Appointment</span>
</a>

    <div class="nav-label">My Health</div>

    <!-- My Treatments -->
    <a href="my-treatments.php" class="<?= isActive('my-treatments.php', $current_page) ?>" <?= isActive('my-treatments.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-notes-medical"></i></span>
      <span>My Treatments</span>
      <span class="nav-badge hidden" data-badge="active_treatments">0</span>
    </a>

    <div class="nav-label">Records</div>

    <!-- Medical History -->
    <a href="patient-medical-history.php" class="<?= isActive('patient-medical-history.php', $current_page) ?>" <?= isActive('patient-medical-history.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-heartbeat"></i></span>
      <span>Medical History</span>
    </a>

    <!-- Billing History -->
    <a href="billing-history.php" class="<?= isActive('billing-history.php', $current_page) ?>" <?= isActive('billing-history.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-receipt"></i></span>
      <span>Billing History</span>
      <span class="nav-badge hidden" data-badge="unpaid_bills">0</span>
    </a>

    <div class="nav-label">Communication</div>

    <!-- Messages -->
    <a href="chat.php" class="<?= isActive('chat.php', $current_page) ?>" <?= isActive('chat.php', $current_page) ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fas fa-comment-dots"></i></span>
      <span>Messages</span>
      <span class="nav-badge hidden" data-badge="messages">0</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar-icon">
        <i class="fas fa-user"></i>
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
  }

  .sidebar.open {
    transform: translateX(0);
  }

  /* Brand area */
  .sidebar-brand {
    padding: 1.8rem 1.8rem 1.2rem 1.8rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    align-items: center;
    gap: 14px;
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
  }

  .sidebar-brand:hover .brand-icon {
    transform: scale(1.02) rotate(-2deg);
  }

  .logo-name {
    color: white;
    font-size: 20px;
    font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
    letter-spacing: -0.2px;
    line-height: 1.2;
    font-weight: 700;
  }

  .logo-clinic {
    color: var(--sb-teal);
    font-size: 10px;
    letter-spacing: 2px;
    font-weight: 600;
    text-transform: uppercase;
    opacity: 0.8;
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

  /* Keep label flexible; don't let the badge or any other span grow */
  .sidebar-nav a span:not(.nav-icon):not(.nav-badge) {
    flex: 1;
  }

  /* ===== Notification badges — red circular pill ===== */
  .sidebar-nav a .nav-badge {
    margin-left: auto;
     background: var(--sb-teal);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 50px;
    min-width: 18px;
    text-align: center;
    line-height: 1.4;
    box-shadow: 0 2px 6px rgba(229, 62, 62, 0.4);
    transition: transform 0.15s ease;
    flex-shrink: 0;
  }

  .sidebar-nav a .nav-badge.hidden {
    display: none;
  }

  .sidebar-nav a .nav-badge.pulse {
    animation: pulse-badge 1.6s ease-in-out infinite;
  }

  @keyframes pulse-badge {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.12); }
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
    padding: 6px 8px;
    border-radius: 8px;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 5;
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
    z-index: 100;
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
      padding: 1.4rem 1.4rem 1rem;
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
  }
</style>

<script>
  (function() {
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggle');
    const userBtn   = document.getElementById('userOptionsBtn');
    const userMenu  = document.getElementById('userOptionsMenu');

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

    function toggleUserMenu(e) {
      e.stopPropagation();
      const isOpen = userMenu.classList.toggle('show');
      userBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
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
      userBtn.addEventListener('click', toggleUserMenu);

      document.addEventListener('click', function(e) {
        const userCard = userBtn.closest('.user-card');
        if (userCard && !userCard.contains(e.target)) {
          closeUserMenu();
        }
      });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
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
      link.addEventListener('click', function() {
        if (window.innerWidth < 1024) {
          closeSidebar();
        }
      });
    });

    // Handle resize
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

    // Init: desktop open
    if (window.innerWidth >= 1024) {
      sidebar.classList.add('open');
      if (overlay) overlay.classList.remove('show');
    }

    /* ============================================================
       NOTIFICATION BADGES
       Polls a single endpoint every 30s, updates all sidebar
       badges at once. Pauses when the tab is hidden. Exposes
       window.refreshBadges() for instant refresh.
       ============================================================ */
    (function () {
        const API_URL = '../../api/patient-notifications.php';
        let pollTimer = null;
        let lastCounts = {};

        function applyBadges(counts) {
            const map = {
                messages:              counts.messages              || 0,
                upcoming_appointments: counts.upcoming_appointments || 0,
                pending_appointments:  counts.pending_appointments  || 0,
                unpaid_bills:          counts.unpaid_bills          || 0,
                active_treatments:     counts.active_treatments     || 0,
            };

            document.querySelectorAll('.nav-badge[data-badge]').forEach(el => {
                const key = el.dataset.badge;
                const val = map[key] ?? 0;

                if (val > 0) {
                    el.textContent = val > 99 ? '99+' : val;
                    el.classList.remove('hidden');

                    if (lastCounts[key] !== undefined && val > lastCounts[key]) {
                        el.classList.add('pulse');
                        setTimeout(() => el.classList.remove('pulse'), 3000);
                    }
                } else {
                    el.classList.add('hidden');
                }
            });

            lastCounts = { ...lastCounts, ...map };
        }

        function fetchCounts() {
            if (document.hidden) return;

            fetch(API_URL, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success && data.counts) {
                        applyBadges(data.counts);
                    }
                })
                .catch(err => console.warn('Patient notification poll failed:', err));
        }

        function startPolling() {
            fetchCounts();
            pollTimer = setInterval(fetchCounts, 30000);
        }

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopPolling();
            } else {
                fetchCounts();
                if (!pollTimer) pollTimer = setInterval(fetchCounts, 30000);
            }
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startPolling);
        } else {
            startPolling();
        }

        window.refreshBadges = fetchCounts;
    })();
  })();

  // Logout functionality
  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function(e) {
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
                  didOpen: () => {
                      Swal.showLoading();
                  }
              });

              fetch('../../api/logout.php', {
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/json',
                  },
              })
              .then(response => response.json())
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
  }
</script>