<?php $token = $_GET['token'] ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — Wansi Calacat Dental Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    :root { --teal: #0d9e8c; --teal-dark: #0a7d6e; --navy: #0b1628; }
    * { font-family: 'DM Sans', system-ui, sans-serif; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #0b1628 0%, #0d9e8c 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; margin: 0; }
    .card { background: #fff; border-radius: 20px; padding: 2.5rem 2rem; max-width: 460px; width: 100%; box-shadow: 0 24px 80px rgba(11,22,40,0.35); }
    .form-input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #f8fafc; transition: all 0.25s; }
    .form-input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,0.12); background: #fff; }
    .pw-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 16px; padding: 4px; }
    .pw-toggle:hover { color: var(--teal); }
    .btn { width: 100%; padding: 14px; background: var(--teal); color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.25s; }
    .btn:hover { background: var(--teal-dark); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(13,158,140,0.35); }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .strength-bar { height: 4px; border-radius: 4px; background: #e2e8f0; overflow: hidden; margin-top: 8px; }
    .strength-fill { height: 100%; width: 0%; transition: all 0.3s; }
</style>
</head>
<body>
<div class="card">
    <div style="text-align:center; margin-bottom:1.5rem;">
        <div style="width:80px;height:80px;border-radius:50%;background:#e6f7f5;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;color:var(--teal);font-size:32px;">
            <i class="fas fa-lock"></i>
        </div>
        <h2 style="font-size:22px;font-weight:800;color:var(--navy);margin:0;">Set New Password</h2>
        <p style="font-size:13px;color:#64748b;margin-top:6px;">Choose a strong password for your account</p>
    </div>

    <form id="resetForm">
        <div style="margin-bottom:1rem;">
            <label style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px;">
                <i class="fas fa-lock" style="color:var(--teal);margin-right:6px;"></i>New Password
            </label>
            <div style="position:relative;">
                <input type="password" id="newPassword" class="form-input" placeholder="Min 8 characters" style="padding-right:45px;" required minlength="8">
                <button type="button" class="pw-toggle" onclick="togglePw('newPassword', this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <p id="strengthText" style="font-size:11px;color:#94a3b8;margin:6px 0 0;">Use 8+ characters with letters, numbers, and symbols.</p>
        </div>

        <div style="margin-bottom:1.5rem;">
            <label style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px;">
                <i class="fas fa-check-circle" style="color:var(--teal);margin-right:6px;"></i>Confirm Password
            </label>
            <div style="position:relative;">
                <input type="password" id="confirmPassword" class="form-input" placeholder="Confirm your password" style="padding-right:45px;" required>
                <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword', this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>

        <button type="submit" class="btn" id="submitBtn">
            <i class="fas fa-save"></i> Reset Password
        </button>
    </form>

    <div style="text-align:center;margin-top:1.25rem;font-size:13px;color:#64748b;">
        <a href="index.php" style="color:var(--teal);font-weight:600;text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;

function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

document.getElementById('newPassword').addEventListener('input', function() {
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
        text.style.color = '#94a3b8';
        return;
    }
    fill.style.width = (score / 4 * 100) + '%';
    fill.style.background = colors[Math.max(0, score - 1)];
    text.textContent = labels[Math.max(0, score - 1)] + ' password';
    text.style.color = colors[Math.max(0, score - 1)];
});

document.getElementById('resetForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const password = document.getElementById('newPassword').value;
    const confirm = document.getElementById('confirmPassword').value;
    const btn = document.getElementById('submitBtn');

    if (password.length < 8) {
        Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must be at least 8 characters.', confirmButtonColor: '#0d9e8c' });
        return;
    }
    if (password !== confirm) {
        Swal.fire({ icon: 'warning', title: 'Password Mismatch', text: 'Passwords do not match.', confirmButtonColor: '#0d9e8c' });
        return;
    }

    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    btn.disabled = true;

    try {
        const res = await fetch('api/reset_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: TOKEN, password, confirm_password: confirm })
        });
        const data = await res.json();

        btn.innerHTML = original;
        btn.disabled = false;

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Password Reset! 🎉',
                text: data.message,
                confirmButtonColor: '#0d9e8c',
                confirmButtonText: 'Go to Login'
            });
            window.location.href = 'index.php';
        } else {
            Swal.fire({ icon: 'error', title: 'Reset Failed', text: data.message, confirmButtonColor: '#e53e3e' });
        }
    } catch (err) {
        btn.innerHTML = original;
        btn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.', confirmButtonColor: '#e53e3e' });
    }
});

if (!TOKEN) {
    Swal.fire({
        icon: 'error',
        title: 'Invalid Link',
        text: 'This password reset link is missing its token. Please request a new one.',
        confirmButtonColor: '#e53e3e'
    }).then(() => { window.location.href = 'index.php'; });
}
</script>
</body>
</html>