<?php
require  '../../conn/conn.php';
 
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

 

// Get user data
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Check if user is active
$is_active = $user['is_active'] ?? 0;
$user_name = $user['full_name'] ?? 'Patient';
$user_email = $user['email'] ?? '';
$user_role = $user['role'] ?? 'patient';
$user_phone = $user['phone'] ?? 'Not provided';
$user_created = $user['created_at'] ?? date('Y-m-d H:i:s');

// Initialize variables
$appointments = [];
$services = [];
$service_lookup = [];

// Only fetch data if user is active
if ($is_active == 1) {
    // Get user's appointments
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE email = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get services for lookup
    $services_result = $conn->query("SELECT id, title, price, duration, icon FROM services WHERE is_active = 1");
    if ($services_result) {
        $services = $services_result->fetch_all(MYSQLI_ASSOC);
        foreach ($services as $s) {
            $service_lookup[$s['id']] = $s;
        }
    }
}

function resolve_services($raw, $lookup) {
    $ids = json_decode($raw, true);
    if (!is_array($ids)) {
        $ids = [$raw];
    }
    $titles = [];
    foreach ($ids as $val) {
        if (isset($lookup[$val])) {
            $titles[] = $lookup[$val]['title'];
        }
    }
    return $titles;
}

function maskTrackingNumber($id) {
    return '#' . str_pad($id, 6, '0', STR_PAD_LEFT);
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

function getStatusBadge($status) {
    $badges = [
        'confirmed' => 'bg-[#d4edda] text-[#155724]',
        'pending' => 'bg-[#fef3d7] text-[#b45309]',
        'cancelled' => 'bg-[#fee9e7] text-[#b91c1c]',
        'completed' => 'bg-[#cce5ff] text-[#004085]'
    ];
    $icons = [
        'confirmed' => 'fa-check-circle',
        'pending' => 'fa-clock',
        'cancelled' => 'fa-times-circle',
        'completed' => 'fa-check-double'
    ];
    $class = $badges[$status] ?? 'bg-gray-100 text-gray-600';
    $icon = $icons[$status] ?? 'fa-circle';
    return '<span class="status-badge ' . $class . '"><i class="fas ' . $icon . ' mr-1"></i> ' . ucfirst($status) . '</span>';
}

include 'psidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard · Wansi Calacat Dental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        
        * { font-family: 'DM Sans', system-ui, sans-serif; }
        h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }

        :root {
            --navy: #0b1628;
            --navy-mid: #1a2a44;
            --teal: #0d9e8c;
            --teal-dark: #0a7d6e;
            --teal-light: #e6f7f5;
            --teal-soft: #ccf0eb;
            --gold: #c9a84c;
            --white: #ffffff;
            --off: #f7f9fc;
            --slate: #64748b;
            --line: #e1e8f0;
            --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
            --shadow-float: 0 24px 80px rgba(11,22,40,.22);
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
        .border-teal { border-color: var(--teal); }
        .bg-dark-blue { background-color: var(--navy); }
        .text-dark-blue { color: var(--navy); }
        .bg-light-teal { background-color: var(--teal-light); }
        .bg-soft-gray { background-color: var(--off); }
        .bg-gold { background-color: var(--gold); }
        .text-gold { color: var(--gold); }

        .main-content {
            margin-left: 270px;
            min-height: 100vh;
            padding: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 1023px) {
            .main-content {
                margin-left: 0;
            }
        }

        .container-modern {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding: 1.5rem 2rem;
        }
        @media (max-width: 640px) {
            .container-modern { padding: 1rem; }
        }

        /* Cards */
        .dashboard-card {
            background: white;
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            box-shadow: 0 12px 40px rgba(11,22,40,.12);
            transform: translateY(-2px);
        }

        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            box-shadow: 0 12px 40px rgba(11,22,40,.12);
            transform: translateY(-2px);
            border-color: var(--teal);
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Avatar */
        .avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            background: var(--teal-light);
            color: var(--teal);
        }
        .avatar-lg {
            width: 70px;
            height: 70px;
            font-size: 30px;
        }

        /* Waiting Approval Card */
        .waiting-card {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 20px;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .waiting-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(245,158,11,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .waiting-card .icon {
            font-size: 64px;
            color: #f59e0b;
            margin-bottom: 1rem;
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
        }
        .waiting-card h2 {
            font-size: 28px;
            font-weight: 800;
            color: #92400e;
        }
        .waiting-card p {
            color: #78350f;
            font-size: 15px;
            max-width: 500px;
            margin: 0.5rem auto 0;
            line-height: 1.6;
        }
        .waiting-card .status-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #f59e0b;
            margin-right: 8px;
            animation: blink 1.5s ease-in-out infinite;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.2; }
        }

        .btn-primary {
            background: var(--teal);
            color: white;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(13,158,140,0.25);
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary:hover {
            background: #0a7d6e;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(13,158,140,0.35);
        }

        .btn-outline {
            background: transparent;
            color: var(--teal);
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            border: 2px solid var(--teal);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-outline:hover {
            background: var(--teal);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(13,158,140,0.2);
        }

        .btn-outline-white {
            border: 2px solid rgba(255,255,255,0.5);
            color: white;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: transparent;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-outline-white:hover {
            background: white;
            color: var(--navy);
            border-color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 8px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(220,53,69,0.3);
        }

        .service-tag {
            display: inline-block;
            background: var(--teal-light);
            color: var(--teal-dark);
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .footer {
            text-align: center;
            padding: 2rem 0 1.5rem;
            border-top: 1px solid #eef2f6;
            margin-top: 2.5rem;
        }
        .footer p {
            font-size: 12px;
            color: #94a3b8;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6b7280;
            font-size: 13px;
        }
        .info-value {
            font-weight: 500;
            color: #1f2937;
            font-size: 14px;
        }

        /* Dashboard Hero */
        .dash-hero {
            background: linear-gradient(135deg, var(--navy), var(--navy-mid));
            border-radius: 20px;
            padding: 2rem 2.5rem;
            position: relative;
            overflow: hidden;
        }
        .dash-hero::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(13,158,140,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        .dash-hero::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -5%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(201,168,76,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        @media print {
            .no-print { display: none !important; }
        }

        @media (max-width: 768px) {
            .waiting-card h2 { font-size: 22px; }
            .waiting-card .icon { font-size: 48px; }
            .waiting-card { padding: 2rem 1.5rem; }
            .dash-hero { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<!-- Include Sidebar -->
 
<!-- ====== MAIN CONTENT ====== -->
<div class="main-content">
    <div class="container-modern">
        
        <?php if ($is_active == 0): ?>
            <!-- ====== WAITING FOR APPROVAL CARD ====== -->
            <div class="waiting-card">
                <div class="icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h2>⏳ Your Account is Pending Approval</h2>
                <p>
                    <span class="status-dot"></span>
                    Our admin team is currently reviewing your account. 
                    You'll receive an email notification once your account is approved.
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <div class="bg-white/50 rounded-lg px-4 py-2 text-sm text-amber-800">
                        <i class="fas fa-check-circle text-amber-600 mr-1"></i>
                        Account Created: <?= date('M d, Y', strtotime($user_created)) ?>
                    </div>
                    <div class="bg-white/50 rounded-lg px-4 py-2 text-sm text-amber-800">
                        <i class="fas fa-user-clock text-amber-600 mr-1"></i>
                        Status: Pending Review
                    </div>
                </div>
                <div class="mt-6">
                    <a href="../../index.php" class="btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Home
                    </a>
                </div>
                <div class="mt-4 text-sm text-amber-700">
                    <i class="fas fa-info-circle mr-1"></i>
                    If you have any questions, please contact our clinic directly.
                </div>
            </div>
            
            <!-- ====== INACTIVE USER INFO CARD ====== -->
            <div class="dashboard-card mt-6">
                <h3 class="font-semibold text-dark-blue mb-4 flex items-center gap-2">
                    <i class="fas fa-user-circle text-teal"></i>
                    Account Details
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user mr-2 text-gray-400"></i>Full Name</span>
                        <span class="info-value"><?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-envelope mr-2 text-gray-400"></i>Email Address</span>
                        <span class="info-value"><?= htmlspecialchars($user_email) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone mr-2 text-gray-400"></i>Phone Number</span>
                        <span class="info-value"><?= htmlspecialchars($user_phone) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-tag mr-2 text-gray-400"></i>Role</span>
                        <span class="info-value capitalize"><?= htmlspecialchars($user_role) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar mr-2 text-gray-400"></i>Registered On</span>
                        <span class="info-value"><?= date('F d, Y', strtotime($user_created)) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-clock mr-2 text-gray-400"></i>Status</span>
                        <span class="info-value">
                            <span class="status-badge bg-[#fef3d7] text-[#b45309]">
                                <i class="fas fa-hourglass-half mr-1"></i> Pending Approval
                            </span>
                        </span>
                    </div>
                </div>
                <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
                    <i class="fas fa-shield-alt mr-2"></i>
                    Your account is currently being reviewed by our admin team. This usually takes 1-2 business days.
                </div>
            </div>
            
        <?php else: ?>
            <!-- ====== ACTIVE USER DASHBOARD ====== -->
            
            <!-- Dashboard Hero -->
            <div class="dash-hero mb-6">
                <div class="relative z-10 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-4">
                            <div class="avatar avatar-lg bg-white/20 text-white border-2 border-white/30">
                                <?= getInitials($user_name) ?>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-white font-['Playfair_Display']">
                                    Welcome back, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?>! 👋
                                </h1>
                                <p class="text-sm text-white/70 mt-1">
                                    <i class="far fa-calendar-alt mr-1 text-teal"></i>
                                    <?php if (!empty($user['last_login']) && $user['last_login'] !== '0000-00-00 00:00:00'): ?>
                                        Last login: <?= date('F d, Y g:i A', strtotime($user['last_login'])) ?>
                                    <?php else: ?>
                                        Welcome to your first session!
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="book-appointment.php" class="btn-primary">
                            <i class="fas fa-calendar-plus"></i> Book Appointment
                        </a>
                        <a href="track-appointment.php" class="btn-outline-white">
                            <i class="fas fa-search"></i> Track
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Stats -->
            <?php 
            $total = count($appointments);
            $confirmed = count(array_filter($appointments, function($a) { return $a['status'] === 'confirmed'; }));
            $pending = count(array_filter($appointments, function($a) { return $a['status'] === 'pending'; }));
            $cancelled = count(array_filter($appointments, function($a) { return $a['status'] === 'cancelled'; }));
            ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card"  >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                            <p class="text-2xl font-bold text-dark-blue mt-1"><?= $total ?></p>
                        </div>
                        <div class="stat-icon bg-teal-light text-teal">
                            <i class="fas fa-calendar"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card"  >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Confirmed</p>
                            <p class="text-2xl font-bold text-dark-blue mt-1"><?= $confirmed ?></p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card"  >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Pending</p>
                            <p class="text-2xl font-bold text-dark-blue mt-1"><?= $pending ?></p>
                        </div>
                        <div class="stat-icon bg-amber-50 text-amber-600">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card"  >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Cancelled</p>
                            <p class="text-2xl font-bold text-dark-blue mt-1"><?= $cancelled ?></p>
                        </div>
                        <div class="stat-icon bg-red-50 text-red-500">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Appointments -->
            <div class="dashboard-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-dark-blue flex items-center gap-2 font-['Playfair_Display']">
                        <i class="fas fa-list-ul text-teal"></i>
                        Recent Appointments
                    </h3>
                    <a href="my-appointments.php" class="text-sm text-teal hover:text-teal-dark font-medium">View All →</a>
                </div>
                
                <?php if (empty($appointments)): ?>
                    <div class="text-center py-10">
                        <div class="w-20 h-20 bg-teal-light rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-plus text-3xl text-teal"></i>
                        </div>
                        <p class="text-gray-500 font-medium">No appointments yet.</p>
                        <p class="text-xs text-gray-400 mt-1">Book your first dental appointment today!</p>
                        <a href="book-appointment.php" class="btn-primary mt-4 inline-flex">
                            <i class="fas fa-plus-circle"></i> Book Your First Appointment
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-3 px-2 font-semibold text-gray-500 text-xs uppercase tracking-wider">#ID</th>
                                    <th class="text-left py-3 px-2 font-semibold text-gray-500 text-xs uppercase tracking-wider">Services</th>
                                    <th class="text-left py-3 px-2 font-semibold text-gray-500 text-xs uppercase tracking-wider">Date</th>
                                    <th class="text-left py-3 px-2 font-semibold text-gray-500 text-xs uppercase tracking-wider">Status</th>
                                    <th class="text-left py-3 px-2 font-semibold text-gray-500 text-xs uppercase tracking-wider">Tracking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $appt): 
                                    $service_titles = resolve_services($appt['services'], $service_lookup);
                                    $status = $appt['status'];
                                ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="py-3 px-2 font-medium text-dark-blue">#<?= $appt['id'] ?></td>
                                    <td class="py-3 px-2">
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach (array_slice($service_titles, 0, 2) as $title): ?>
                                                <span class="service-tag"><?= htmlspecialchars($title) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($service_titles) > 2): ?>
                                                <span class="service-tag">+<?= count($service_titles) - 2 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-2 text-gray-600">
                                        <?= date('M d, Y', strtotime($appt['created_at'])) ?>
                                    </td>
                                    <td class="py-3 px-2">
                                        <?= getStatusBadge($status) ?>
                                    </td>
                                    <td class="py-3 px-2">
                                        <span class="text-xs font-mono bg-gray-100 px-3 py-1 rounded-full text-gray-600">
                                            <?= maskTrackingNumber($appt['id']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                <div class="dashboard-card text-center hover:border-teal/30 cursor-pointer" onclick="window.location.href='book-appointment.php'">
                    <div class="w-14 h-14 bg-teal-light rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-calendar-plus text-teal text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-dark-blue font-['Playfair_Display']">Book Appointment</h4>
                    <p class="text-xs text-gray-500 mt-1">Schedule your next dental visit</p>
                    <span class="btn-primary text-sm mt-3 inline-flex">Book Now</span>
                </div>
                <div class="dashboard-card text-center hover:border-teal/30 cursor-pointer" onclick="window.location.href='track-appointment.php'">
                    <div class="w-14 h-14 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-search text-amber-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-dark-blue font-['Playfair_Display']">Track Appointment</h4>
                    <p class="text-xs text-gray-500 mt-1">Check your appointment status</p>
                    <span class="btn-outline text-sm mt-3 inline-flex">Track Now</span>
                </div>
                <div class="dashboard-card text-center hover:border-teal/30 cursor-pointer" onclick="window.location.href='profile.php'">
                    <div class="w-14 h-14 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-user-edit text-blue-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-dark-blue font-['Playfair_Display']">Profile Settings</h4>
                    <p class="text-xs text-gray-500 mt-1">Update your personal information</p>
                    <span class="btn-outline text-sm mt-3 inline-flex">Edit Profile</span>
                </div>
            </div>
            
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="footer no-print">
            <p>
                <i class="fas fa-tooth text-teal/50 mr-1"></i>
                Wansi Calacat Dental Clinic · Patient Dashboard
            </p>
        </div>
        
    </div>
</div>

<script>
// ====== OPEN BOOKING ======
function openBooking() {
    window.location.href = 'book-appointment.php';
}


</script>

</body>
</html>