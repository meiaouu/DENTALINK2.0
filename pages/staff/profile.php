<?php 
require '../../conn/conn.php';
// ---- Auth guard ----
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit;
}



$userId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';

// ---- Fetch fresh user data ----
$stmt = $conn->prepare("SELECT id, email, full_name, phone, role, is_active, last_login, created_at, updated_at 
                        FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Session points to a user that no longer exists
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// ---- Handle profile update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    // Check email not taken by another user
    if (empty($errors) && $email !== $user['email']) {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $check->bind_param('si', $email, $userId);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $errors[] = 'That email is already in use by another account.';
        }
        $check->close();
    }

    if (empty($errors)) {
        $upd = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?");
        $upd->bind_param('sssi', $full_name, $phone, $email, $userId);
        if ($upd->execute()) {
            // Keep session in sync
            $_SESSION['user_name']  = $full_name;
            $_SESSION['user_email'] = $email;

            $user['full_name'] = $full_name;
            $user['phone']     = $phone;
            $user['email']     = $email;
            $success = 'Profile updated successfully.';
        } else {
            $errors[] = 'Could not update profile. Please try again.';
        }
        $upd->close();
    }
}

// ---- Handle password change ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Fetch current hash
    $pstmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $pstmt->bind_param('i', $userId);
    $pstmt->execute();
    $row = $pstmt->get_result()->fetch_assoc();
    $pstmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $ustmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $ustmt->bind_param('si', $hash, $userId);
        if ($ustmt->execute()) {
            $success = 'Password changed successfully.';
        } else {
            $errors[] = 'Could not update password. Please try again.';
        }
        $ustmt->close();
    }
}

// ---- Format dates ----
function fmtDate($dt) {
    if (!$dt) return '—';
    return date('M d, Y g:i A', strtotime($dt));
}

// ---- Initials for avatar ----
$initials = '';
foreach (explode(' ', trim($user['full_name'])) as $part) {
    if ($part !== '') $initials .= strtoupper($part[0]);
    if (strlen($initials) >= 2) break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Wansi Calacat Dental Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    :root {
        --navy: #0b1628;
        --teal: #0d9e8c;
        --teal-dark: #0a7d6e;
        --teal-light: #e6f7f5;
        --line: #e1e8f0;
        --text: #0d1b2a;
        --muted: #64748b;
    }
    * { font-family: 'DM Sans', system-ui, -apple-system, sans-serif; box-sizing: border-box; }
    body {
  margin: 0;
  background: #f8fafc;
  background-image: linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
  background-size: 32px 32px;
  background-position: -1px -1px;
  color: #111827;
}
h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }

    /* Push content right of fixed sidebar on desktop */
    .page-wrap { margin-left: 0; transition: margin 0.3s ease; padding: 2rem 1.5rem; }
    @media (min-width: 1024px) { .page-wrap { margin-left: 270px; } }

    .page-header { margin-bottom: 2rem; }
    .page-title { font-size: 26px; font-weight: 800; color: var(--navy); margin: 0; }
    .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }

    .grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr;  margin: 0 auto; }
    @media (min-width: 900px) { .grid { grid-template-columns: 320px 1fr; } }

    .card {
        background: #fff; border-radius: 18px; border: 1px solid var(--line);
        padding: 1.75rem; box-shadow: 0 2px 10px rgba(11,22,40,0.04);
    }
    .card h3 { font-size: 15px; font-weight: 700; color: var(--navy); margin: 0 0 1.25rem; display: flex; align-items: center; gap: 8px; }
    .card h3 i { color: var(--teal); }

    /* Profile summary card */
    .profile-summary { text-align: center; padding: 2rem 1.5rem; }
    .avatar-lg {
        width: 96px; height: 96px; border-radius: 50%;
        background: linear-gradient(145deg, var(--teal), var(--teal-dark));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 34px; font-weight: 800; margin: 0 auto 1rem;
        box-shadow: 0 10px 30px rgba(13,158,140,0.3);
        letter-spacing: 1px;
    }
    .profile-name { font-size: 18px; font-weight: 800; color: var(--navy); margin: 0; }
    .profile-role {
        display: inline-block; margin-top: 6px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
        background: var(--teal-light); color: var(--teal-dark);
        padding: 4px 12px; border-radius: 50px;
    }
    .profile-email { font-size: 12px; color: var(--muted); margin-top: 10px; word-break: break-all; }

    .meta-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 0; font-size: 12px;
        border-bottom: 1px solid var(--line);
    }
    .meta-row:last-child { border-bottom: none; }
    .meta-row .label { color: var(--muted); font-weight: 500; }
    .meta-row .value { color: var(--text); font-weight: 600; text-align: right; }

    /* Forms */
    .form-row { display: grid; gap: 1rem; grid-template-columns: 1fr; margin-bottom: 1rem; }
    @media (min-width: 640px) { .form-row.two { grid-template-columns: 1fr 1fr; } }

    .form-group label {
        display: block; font-size: 12.5px; font-weight: 600;
        color: var(--navy); margin-bottom: 6px;
    }
    .form-group label i { color: var(--teal); margin-right: 6px; }
    .form-input {
        width: 100%; padding: 11px 14px;
        border: 2px solid var(--line); border-radius: 10px;
        font-size: 13.5px; background: #fafbfc; color: var(--text);
        transition: all 0.2s; font-family: inherit;
    }
    .form-input:focus {
        outline: none; border-color: var(--teal);
        box-shadow: 0 0 0 4px rgba(13,158,140,0.12);
        background: #fff;
    }
    .form-input:disabled { background: #f1f4f8; color: #94a3b8; cursor: not-allowed; }
    .form-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }

    .pw-wrap { position: relative; }
    .pw-toggle {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        background: none; border: none; color: #94a3b8; cursor: pointer;
        font-size: 15px; padding: 4px;
    }
    .pw-toggle:hover { color: var(--teal); }

    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 22px; border-radius: 10px;
        font-size: 13px; font-weight: 700; cursor: pointer;
        border: none; transition: all 0.25s;
    }
    .btn-primary { background: var(--teal); color: #fff; box-shadow: 0 4px 14px rgba(13,158,140,0.25); }
    .btn-primary:hover { background: var(--teal-dark); transform: translateY(-1px); box-shadow: 0 8px 22px rgba(13,158,140,0.35); }
    .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .btn-ghost { background: #f1f4f8; color: #475569; }
    .btn-ghost:hover { background: #e2e8f0; }

    .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--line); }

    /* Password strength bar */
    .strength-bar { height: 4px; border-radius: 4px; background: #e2e8f0; overflow: hidden; margin-top: 8px; }
    .strength-fill { height: 100%; width: 0%; transition: all 0.3s; }

    /* Divider between sections */
    .section-divider { display: flex; align-items: center; gap: 10px; margin: 0 0 1.25rem; }
    .section-divider::before, .section-divider::after {
        content: ''; flex: 1; height: 1px; background: var(--line);
    }
    .section-divider span {
        font-size: 10px; font-weight: 700; color: var(--muted);
        letter-spacing: 1.5px; text-transform: uppercase;
    }

    
</style>
</head>
<body>

<?php include  'ssidebar.php'; ?>

<div class="page-wrap">

    <div class="page-header">
        <h1 class="page-title">My Profile</h1>
        <p class="page-sub">Manage your personal information and account security.</p>
    </div>

    <div class="grid">

        <!-- ============ LEFT: SUMMARY CARD ============ -->
        <div>
            <div class="card profile-summary">
                <div class="avatar-lg"><?= htmlspecialchars($initials ?: 'U') ?></div>
                <p class="profile-name"><?= htmlspecialchars($user['full_name']) ?></p>
                <span class="profile-role"><?= htmlspecialchars($user['role'] ?? 'user') ?></span>
                <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
            </div>

            <div class="card" style="margin-top: 1.5rem;">
                <h3><i class="fas fa-info-circle"></i> Account Info</h3>
                <div class="meta-row">
                    <span class="label">Status</span>
                    <span class="value">
                        <?php if ((int)$user['is_active'] === 1): ?>
                            <span style="color:#0d9e8c;"><i class="fas fa-circle" style="font-size:7px;"></i> Active</span>
                        <?php else: ?>
                            <span style="color:#e53e3e;"><i class="fas fa-circle" style="font-size:7px;"></i> Inactive</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="label">Last Login</span>
                    <span class="value"><?= htmlspecialchars(fmtDate($user['last_login'])) ?></span>
                </div>
                <div class="meta-row">
                    <span class="label">Member Since</span>
                    <span class="value"><?= htmlspecialchars(fmtDate($user['created_at'])) ?></span>
                </div>
                <div class="meta-row">
                    <span class="label">Last Updated</span>
                    <span class="value"><?= htmlspecialchars(fmtDate($user['updated_at'])) ?></span>
                </div>
            </div>
        </div>

        <!-- ============ RIGHT: FORMS ============ -->
        <div>

            <!-- ----- Personal Info ----- -->
            <div class="card">
                <h3><i class="fas fa-user"></i> Personal Information</h3>

                <form id="profileForm" method="POST" action="profile.php">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label><i class="fas fa-user"></i>Full Name</label>
                        <input type="text" name="full_name" class="form-input"
                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>

                    <div class="form-row two">
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i>Email Address</label>
                            <input type="email" name="email" class="form-input"
                                   value="<?= htmlspecialchars($user['email']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i>Phone Number</label>
                            <input type="tel" name="phone" class="form-input"
                                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                   placeholder="e.g. +63 912 345 6789">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label><i class="fas fa-shield-alt"></i>Role</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($user['role']) ?>" disabled>
                        <p class="form-hint">Role is managed by an administrator and cannot be changed here.</p>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-ghost">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ----- Change Password ----- -->
            <div class="card" style="margin-top: 1.5rem;">
                <h3><i class="fas fa-lock"></i> Change Password</h3>

                <form id="passwordForm" method="POST" action="profile.php">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label><i class="fas fa-key"></i>Current Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="current_password" id="currentPw"
                                   class="form-input" placeholder="Enter current password" required
                                   style="padding-right:44px;">
                            <button type="button" class="pw-toggle" onclick="togglePw('currentPw', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-row two">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i>New Password</label>
                            <div class="pw-wrap">
                                <input type="password" name="new_password" id="newPw"
                                       class="form-input" placeholder="Min 8 characters"
                                       required minlength="8" style="padding-right:44px;">
                                <button type="button" class="pw-toggle" onclick="togglePw('newPw', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                            <p class="form-hint" id="strengthText">Use 8+ characters with letters, numbers, and symbols.</p>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i>Confirm New Password</label>
                            <div class="pw-wrap">
                                <input type="password" name="confirm_password" id="confirmPw"
                                       class="form-input" placeholder="Re-enter new password"
                                       required style="padding-right:44px;">
                                <button type="button" class="pw-toggle" onclick="togglePw('confirmPw', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-ghost">
                            <i class="fas fa-undo"></i> Clear
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-shield-alt"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

// Password strength meter
const newPw = document.getElementById('newPw');
if (newPw) {
    newPw.addEventListener('input', function() {
        const val = this.value;
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const fill = document.getElementById('strengthFill');
        const text = document.getElementById('strengthText');
        const colors = ['#e53e3e', '#ed8936', '#ecc94b', '#48bb78'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];

        if (!val) {
            fill.style.width = '0%';
            text.textContent = 'Use 8+ characters with letters, numbers, and symbols.';
            text.style.color = '#64748b';
            return;
        }
        fill.style.width = (score / 4 * 100) + '%';
        fill.style.background = colors[Math.max(0, score - 1)];
        text.textContent = labels[Math.max(0, score - 1)] + ' password';
        text.style.color = colors[Math.max(0, score - 1)];
    });
}

// Password match check
const passwordForm = document.getElementById('passwordForm');
if (passwordForm) {
    passwordForm.addEventListener('submit', function(e) {
        const current = document.getElementById('currentPw').value;
        const next = document.getElementById('newPw').value;
        const confirm = document.getElementById('confirmPw').value;

        if (next.length < 8) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'New password must be at least 8 characters.', confirmButtonColor: '#0d9e8c' });
            return;
        }
        if (next !== confirm) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Password Mismatch', text: 'New passwords do not match.', confirmButtonColor: '#0d9e8c' });
            return;
        }
        if (next === current) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Same Password', text: 'New password must be different from the current one.', confirmButtonColor: '#0d9e8c' });
            return;
        }
    });
}

// Server feedback (PHP $success / $errors)
<?php if (!empty($success)): ?>
Swal.fire({
    icon: 'success',
    title: 'Done!',
    text: <?= json_encode($success) ?>,
    confirmButtonColor: '#0d9e8c',
    timer: 2500,
    timerProgressBar: true
});
<?php endif; ?>

<?php if (!empty($errors)): ?>
Swal.fire({
    icon: 'error',
    title: 'Oops...',
    html: <?= json_encode(implode('<br>', array_map('htmlspecialchars', $errors))) ?>,
    confirmButtonColor: '#e53e3e'
});
<?php endif; ?>
</script>
</body>
</html>