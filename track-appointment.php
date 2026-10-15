<?php
require_once 'conn/conn.php';
 
// Set timezone
date_default_timezone_set('Asia/Manila');

// Handle appointment tracking
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE tracking_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();
    
    if (!$appointment) {
        $error = "Invalid tracking token. Please check your email for the correct link.";
    }
} elseif (isset($_POST['track'])) {
    $tracking_number = trim($_POST['tracking_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    $query = "SELECT * FROM appointments WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($tracking_number)) {
        // Remove the # prefix if present and convert to integer
        $tracking_id = ltrim($tracking_number, '#');
        $tracking_id = (int)$tracking_id;
        
        // Search by ID (as integer) OR by tracking_token (full match)
        $query .= " AND (id = ? OR tracking_token = ?)";
        $params[] = $tracking_id;
        $params[] = $tracking_number; // Try exact match on token too
        $types .= "is";
    } else {
        if (!empty($email)) {
            $query .= " AND email = ?";
            $params[] = $email;
            $types .= "s";
        }
        if (!empty($phone)) {
            $query .= " AND phone = ?";
            $params[] = $phone;
            $types .= "s";
        }
    }
    
    $query .= " ORDER BY created_at DESC LIMIT 1";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointment = $result->fetch_assoc();
        $stmt->close();
        
        if (!$appointment) {
            $error = "No appointment found with the provided information. Please check and try again.";
        }
    } else {
        $error = "Please enter at least one search field.";
    }
}

// Get services for lookup
$services_result = $conn->query("SELECT id, title, price, duration, icon FROM services WHERE is_active = 1");
$services = $services_result->fetch_all(MYSQLI_ASSOC);
$service_lookup = [];
foreach ($services as $s) {
    $service_lookup[$s['id']] = $s;
}

function resolve_services($raw, $lookup) {
    $ids = json_decode($raw, true);
    if (!is_array($ids)) {
        $ids = [$raw];
    }
    $titles = [];
    $total = 0.0;
    foreach ($ids as $val) {
        if (isset($lookup[$val])) {
            $titles[] = $lookup[$val]['title'];
            $total += (float)$lookup[$val]['price'];
        }
    }
    return ['titles' => $titles, 'total' => $total];
}

function maskTrackingNumber($id) {
    return '#' . str_pad($id, 6, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Appointment · <?= htmlspecialchars($settings['clinic_name'] ?? 'WhiteSmile Dental') ?></title>
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
        
        .bg-teal { background-color: var(--teal); }
        .text-teal { color: var(--teal); }
        .border-teal { border-color: var(--teal); }
        .bg-dark-blue { background-color: var(--navy); }
        .text-dark-blue { color: var(--navy); }
        .bg-light-teal { background-color: var(--teal-light); }
        .bg-soft-gray { background-color: var(--off); }
        
        .container-modern {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 2rem;
            padding-right: 2rem;
        }
        @media (max-width: 640px) {
            .container-modern { padding-left: 1rem; padding-right: 1rem; }
        }
        
        /* Hero Section */
        .tracking-hero {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
            position: relative;
            overflow: hidden;
            padding: 4rem 0 5rem;
        }
        .tracking-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(13,158,140,0.12) 0%, transparent 70%);
            border-radius: 50%;
        }
        .tracking-hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(13,158,140,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .tracking-hero .hero-content {
            position: relative;
            z-index: 1;
        }
        .tracking-hero .hero-icon {
            width: 80px;
            height: 80px;
            background: rgba(13,158,140,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: var(--teal);
            margin: 0 auto 1.25rem;
            border: 2px solid rgba(13,158,140,0.2);
        }
        .tracking-hero h1 {
            font-size: 2.75rem;
            font-weight: 800;
            color: white;
            text-align: center;
            line-height: 1.1;
        }
        .tracking-hero h1 span {
            color: var(--teal);
        }
        .tracking-hero p {
            color: rgba(255,255,255,0.7);
            text-align: center;
            max-width: 500px;
            margin: 0.75rem auto 0;
            font-size: 1.05rem;
            line-height: 1.6;
        }
        @media (max-width: 640px) {
            .tracking-hero { padding: 2.5rem 0 3rem; }
            .tracking-hero h1 { font-size: 1.75rem; }
            .tracking-hero .hero-icon { width: 60px; height: 60px; font-size: 28px; }
        }
        
        /* Top Bar */
        .topbar {
            background: var(--navy);
            color: white;
            font-size: 11px;
            padding: 0.5rem 0;
        }
        .topbar a { color: white; transition: color 0.2s; }
        .topbar a:hover { color: var(--teal); }
        
         .tracking-card {
        background: white;
        border-radius: 20px;
        padding: 2rem;
        box-shadow: var(--shadow-card);
        border: 1px solid #f0f3f7;
        transition: all 0.3s ease;
        position: relative;
        z-index: 10;
        margin-bottom: 1.5rem;
    }
    .tracking-card:hover {
        box-shadow: 0 12px 40px rgba(11,22,40,.12);
        transform: translateY(-2px);
    }
    
    /* Make sure the card stays on top of hero elements */
    .container-modern .tracking-card {
        position: relative;
        z-index: 20;
    }
        
        .status-badge {
            display: inline-block;
            padding: 6px 20px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
        }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-pending { background: #fef3d7; color: #b45309; }
        .status-cancelled { background: #fee9e7; color: #b91c1c; }
        
        /* Timeline */
        .timeline-step {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 14px 0;
            position: relative;
        }
        .timeline-step:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 17px;
            top: 48px;
            bottom: -6px;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-step .step-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            z-index: 1;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .timeline-step.active .step-icon {
            background: var(--teal);
            color: white;
            box-shadow: 0 4px 14px rgba(13,158,140,0.35);
        }
        .timeline-step.completed .step-icon {
            background: #d4edda;
            color: #155724;
        }
        .timeline-step.pending .step-icon {
            background: #f1f4f8;
            color: #94a3b8;
        }
        .timeline-step.cancelled .step-icon {
            background: #fee9e7;
            color: #b91c1c;
        }
        .timeline-step .step-content { flex: 1; }
        .timeline-step .step-title {
            font-weight: 600;
            color: #0b1628;
            font-size: 14px;
        }
        .timeline-step .step-desc {
            font-size: 12.5px;
            color: #64748b;
        }
        .timeline-step.active .step-title { color: var(--teal); }
        
        /* Input Groups */
        .input-group {
            position: relative;
        }
        .input-group .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 1;
        }
        .input-group input {
            padding-left: 44px !important;
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        .input-group input:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
            background: white;
        }
        
        /* Search Tabs */
        .search-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            background: #f1f4f8;
            padding: 4px;
            border-radius: 14px;
        }
        .search-tab {
            flex: 1;
            padding: 10px 16px;
            text-align: center;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .search-tab.active {
            background: white;
            color: var(--teal);
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .search-tab:hover:not(.active) {
            color: #0b1628;
        }
        .search-section { display: none; }
        .search-section.active { display: block; }
        
        .tracking-number-badge {
            display: inline-block;
            background: var(--teal-light);
            color: var(--teal-dark);
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .btn-primary {
            background: var(--teal);
            color: white;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(13,158,140,0.25);
            border: none;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
        }
        .btn-primary:hover {
            background: #0a7d6e;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(13,158,140,0.35);
        }
        .btn-primary:active { transform: translateY(0); }
        
        .btn-outline {
            background: transparent;
            color: var(--teal);
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            border: 2px solid var(--teal);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-outline:hover {
            background: var(--teal);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(13,158,140,0.2);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1px solid #eef2f6;
        }
        @media (max-width: 480px) {
            .info-grid { grid-template-columns: 1fr; }
        }
        .info-item .label {
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-item .value {
            font-size: 14px;
            font-weight: 600;
            color: #0b1628;
            margin-top: 2px;
        }
        
        .service-tag {
            display: inline-block;
            background: var(--teal-light);
            color: var(--teal-dark);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #0b1628;
            margin-bottom: 6px;
        }
        .form-hint {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }
        .form-hint i { margin-right: 4px; }
        
        @media print {
            .no-print { display: none !important; }
            .tracking-card { box-shadow: none !important; border: 1px solid #ddd; }
            .tracking-hero { padding: 1.5rem 0 !important; }
            .tracking-hero .hero-icon { display: none; }
        }
        
        /* Error Message */
        .error-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            color: #991b1b;
            font-size: 14px;
        }
        .error-box i { font-size: 18px; }
        
        /* Footer */
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
        .footer a {
            color: var(--teal);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .footer a:hover { color: var(--teal-dark); }

        .status-approved { background: #e0edff; color: #1d4ed8; }

        .status-completed { 
    background: #d1fae5; 
    color: #065f46; 
}
    </style>
</head>
<body>

<!-- ====== TOP BAR ====== -->
<div class="topbar no-print">
    <div class="container-modern flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-6">
            <span><i class="fas fa-phone mr-1.5 text-teal"></i> <?= htmlspecialchars($settings['phone'] ?? '+63 912 345 6789') ?></span>
            <span class="hidden sm:inline"><i class="fas fa-envelope mr-1.5 text-teal"></i> <?= htmlspecialchars($settings['email'] ?? 'info@whitesmile.com') ?></span>
        </div>
        <div class="flex items-center gap-4">
            <a href="index.php" class="hover:text-teal transition"><i class="fas fa-home"></i></a>
            <a href="#" class="hover:text-teal transition"><i class="fab fa-facebook"></i></a>
            <a href="#" class="hover:text-teal transition"><i class="fab fa-instagram"></i></a>
        </div>
    </div>
</div>

<!-- ====== HERO SECTION ====== -->
<section class="tracking-hero no-print">
    <div class="container-modern hero-content">
        <div class="hero-icon">
            <i class="fas fa-calendar-check"></i>
        </div>
        <h1>Track Your <span>Appointment</span></h1>
        <p>Stay updated on the status of your dental appointment</p>
    </div>
</section>

<!-- ====== MAIN CONTENT ====== -->
<div class="container-modern" style="margin-top: -2rem; padding-bottom: 2rem;">
    <div class="max-w-3xl mx-auto">
        <?php if (isset($appointment)): ?>
            <!-- ====== APPOINTMENT DETAILS ====== -->
            <?php
            $service_info = resolve_services($appointment['services'], $service_lookup);
            $status = $appointment['status'];
            $is_verified = $appointment['is_verified'];
            ?>
            <div class="tracking-card">
                <!-- Header -->
                <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
                    <div>
                        <div class="flex items-center gap-3">
                            <h3 class="text-xl font-bold text-dark-blue">Appointment</h3>
                            <span class="tracking-number-badge"><?= maskTrackingNumber($appointment['id']) ?></span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">
                            <i class="far fa-calendar-alt mr-1"></i> 
                            <?= date('F d, Y', strtotime($appointment['created_at'])) ?>
                            <span class="mx-2">·</span>
                            <i class="far fa-clock mr-1"></i>
                            <?= date('g:i A', strtotime($appointment['created_at'])) ?>
                        </p>
                    </div>
   <?php if ($status === 'confirmed'): ?>
    <span class="status-badge status-confirmed"><i class="fas fa-check-circle mr-1.5"></i> Confirmed</span>
<?php elseif ($status === 'approved'): ?>
    <span class="status-badge status-approved"><i class="fas fa-thumbs-up mr-1.5"></i> Approved</span>
<?php elseif ($status === 'pending'): ?>
    <span class="status-badge status-pending"><i class="fas fa-clock mr-1.5"></i> Pending</span>
<?php elseif ($status === 'completed'): ?>
    <span class="status-badge status-completed"><i class="fas fa-check-double mr-1.5"></i> Completed</span>
<?php else: ?>
    <span class="status-badge status-cancelled"><i class="fas fa-times-circle mr-1.5"></i> Cancelled</span>
<?php endif; ?>
                    </div>

              <?php if (!empty($appointment['is_rescheduled'])): ?>
                <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-3">
                    <i class="fas fa-calendar-alt text-amber-600 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-amber-800">This appointment was rescheduled</p>
                        <p class="text-sm text-amber-700">
                            Originally set for
                            <?= $appointment['previous_date'] ? date('F d, Y', strtotime($appointment['previous_date'])) : '—' ?>
                            <?= $appointment['previous_time'] ? 'at ' . date('g:i A', strtotime($appointment['previous_time'])) : '' ?>,
                            now moved to
                            <strong><?= $appointment['appointment_date'] ? date('F d, Y', strtotime($appointment['appointment_date'])) : '—' ?>
                            <?= $appointment['appointment_time'] ? 'at ' . date('g:i A', strtotime($appointment['appointment_time'])) : '' ?></strong>.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact & Services -->
                <div class="info-grid mb-6">
                    <div class="info-item">
                        <p class="label"><i class="fas fa-phone mr-1"></i> Phone</p>
                        <p class="value"><?= htmlspecialchars($appointment['phone']) ?></p>
                    </div>
                    <div class="info-item">
                        <p class="label"><i class="fas fa-envelope mr-1"></i> Email</p>
                        <p class="value"><?= htmlspecialchars($appointment['email']) ?></p>
                    </div>
                    <div class="info-item col-span-full">
                        <p class="label"><i class="fas fa-tooth mr-1"></i> Services</p>
                        <div class="flex flex-wrap gap-1.5 mt-1">
                            <?php foreach ($service_info['titles'] as $title): ?>
                                <span class="service-tag"><?= htmlspecialchars($title) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($service_info['total'] > 0): ?>
                            <p class="text-sm font-medium text-teal mt-1.5">
                                Total: ₱<?= number_format($service_info['total'], 2) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Timeline -->
<div class="mt-6">
    <h4 class="font-semibold text-dark-blue mb-2 flex items-center gap-2">
        <i class="fas fa-list-ul text-teal"></i> Status Timeline
    </h4>
    <div class="relative">
    <?php
    $steps = [
        ['icon' => 'fa-calendar-plus', 'title' => 'Appointment Created', 'desc' => 'Your appointment was scheduled'],
        ['icon' => 'fa-clock',         'title' => 'Under Review',        'desc' => 'Clinic is reviewing your appointment'],
        ['icon' => 'fa-thumbs-up',     'title' => 'Approved',            'desc' => 'Your appointment has been approved'],
        ['icon' => 'fa-check-circle',  'title' => 'Confirmed',           'desc' => 'Your appointment has been confirmed'],
         ['icon' => 'fa-check-double',  'title' => 'Completed',           'desc' => 'Your appointment has been completed']
    ];

    $current_step = 0;
    if ($status === 'pending') $current_step = 1;
    elseif ($status === 'approved') $current_step = 2;
    elseif ($status === 'confirmed') $current_step = 3;
    elseif ($status === 'completed') $current_step = 4;
    elseif ($status === 'cancelled') $current_step = 5;

    foreach ($steps as $index => $step):
        $step_status = '';
        if ($status === 'cancelled') {
            $step_status = 'cancelled';
        } elseif ($status === 'completed') {
            // When status is completed, all steps up to and including "Completed" are marked as completed
            if ($index <= $current_step) {
                $step_status = 'completed';
            } else {
                $step_status = 'pending';
            }
            // The last step (Completed) gets a special "Completed" label instead of "Current"
        } elseif ($index <= $current_step) {
            $step_status = $index === $current_step ? 'active' : 'completed';
        } else {
            $step_status = 'pending';
        }
    ?>
        <div class="timeline-step <?= $step_status ?>">
            <div class="step-icon">
                <i class="fas <?= $step['icon'] ?>"></i>
            </div>
            <div class="step-content">
                <p class="step-title"><?= $step['title'] ?></p>
                <p class="step-desc"><?= $step['desc'] ?></p>
            </div>
            <?php if ($status === 'completed' && $index === $current_step): ?>
                <span class="text-xs font-semibold text-green-600 bg-green-50 px-2.5 py-1 rounded-full flex-shrink-0">✓ Done</span>
            <?php elseif ($index === $current_step && $status !== 'cancelled' && $status !== 'completed'): ?>
                <span class="text-xs font-semibold text-teal bg-teal-light px-2.5 py-1 rounded-full flex-shrink-0">Current</span>
            <?php endif; ?>
            <?php if ($status === 'cancelled' && $index === 0): ?>
                <span class="text-xs font-semibold text-red-500 bg-red-50 px-2.5 py-1 rounded-full flex-shrink-0">Cancelled</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

              <?php if ($status === 'completed'): ?>
    <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-start gap-3">
        <i class="fas fa-check-circle text-green-600 text-lg mt-0.5"></i>
        <div>
            <p class="text-sm font-medium text-green-800">Appointment Completed</p>
            <p class="text-sm text-green-700">Your dental appointment has been successfully completed. Thank you for choosing us!</p>
        </div>
    </div>
<?php elseif ($status === 'approved'): ?>
    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
        <i class="fas fa-thumbs-up text-blue-600 text-lg mt-0.5"></i>
        <div>
            <p class="text-sm font-medium text-blue-800">Approved — Awaiting Final Confirmation</p>
            <p class="text-sm text-blue-700">Your appointment has been approved and is pending final confirmation from the clinic.</p>
        </div>
    </div>
<?php elseif ($status === 'confirmed' && !$is_verified): ?>
    <div class="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-3">
        <i class="fas fa-info-circle text-amber-600 text-lg mt-0.5"></i>
        <div>
            <p class="text-sm font-medium text-amber-800">Verification Pending</p>
            <p class="text-sm text-amber-700">Please verify your email to complete the process.</p>
        </div>
    </div>
<?php endif; ?>

                <!-- Actions -->
                <div class="mt-6 pt-4 border-t border-gray-200 flex flex-wrap justify-between items-center gap-3">
                    <a href="track-appointment.php" class="btn-outline no-print text-sm">
                        <i class="fas fa-arrow-left"></i> Check Another
                    </a>
                    <button onclick="window.print()" class="btn-outline no-print text-sm">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

        <?php else: ?>
            <!-- ====== SEARCH FORM ====== -->
            <div class="tracking-card">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-teal-light rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-search text-teal text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-dark-blue">Find Your Appointment</h3>
                    <p class="text-sm text-gray-500 mt-1">Enter your details to check your appointment status</p>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="error-box mb-6">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= $error ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Search Tabs -->
                <div class="search-tabs no-print" id="searchTabs">
                    <button class="search-tab active" data-tab="tracking-number">
                        <i class="fas fa-hashtag mr-1.5"></i> Tracking #
                    </button>
                    <button class="search-tab" data-tab="email-phone">
                        <i class="fas fa-user mr-1.5"></i> Email & Phone
                    </button>
                </div>
                
                <form method="POST" action="track-appointment.php" class="space-y-4" id="trackForm">
                    
                    <!-- Tracking Number -->
                    <div class="search-section active" id="section-tracking-number">
                        <div class="form-group">
                            <label for="tracking_number">
                                <i class="fas fa-hashtag text-teal mr-1"></i> Tracking Number
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-qrcode"></i></span>
                                <input type="text" name="tracking_number" id="tracking_number"
                                       placeholder="Enter tracking number from email"
                                       value="<?= htmlspecialchars($_POST['tracking_number'] ?? '') ?>">
                            </div>
                            <p class="form-hint">
                                <i class="fas fa-info-circle"></i> 
                                Enter the tracking number sent in your confirmation email.
                            </p>
                        </div>
                        
                        <div class="text-center py-2">
                            <span class="text-xs text-gray-400 bg-gray-100 px-4 py-1.5 rounded-full">— OR —</span>
                        </div>
                    </div>
                    
                    <!-- Email & Phone -->
                    <div class="search-section" id="section-email-phone">
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope text-teal mr-1"></i> Email Address</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="email"
                                       placeholder="your@email.com"
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone"><i class="fas fa-phone text-teal mr-1"></i> Phone Number</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" name="phone" id="phone"
                                       placeholder="09xx xxx xxxx"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                            <p class="form-hint">
                                <i class="fas fa-info-circle"></i> 
                                Use the email and phone number you provided when booking.
                            </p>
                        </div>
                    </div>
                    
                    <button type="submit" name="track" class="btn-primary">
                        <i class="fas fa-search"></i> Find Appointment
                    </button>
                </form>
                
                <!-- Security Notice -->
                <div class="mt-6 pt-4 border-t border-gray-200 text-center">
                    <p class="text-xs text-gray-400">
                        <i class="fas fa-shield-alt mr-1 text-teal"></i> 
                        Your information is secure and will only be used to track your appointment.
                    </p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Back to Home -->
        <div class="text-center mt-6 no-print">
            <a href="index.php" class="text-sm text-gray-500 hover:text-teal transition inline-flex items-center gap-1.5">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
        
        <!-- Footer -->
        <div class="footer no-print">
            <p>
                <i class="fas fa-tooth text-teal/50 mr-1"></i>
                <?= htmlspecialchars($settings['clinic_name'] ?? 'WhiteSmile Dental Clinic') ?> · 
                <a href="index.php">Home</a> · 
                <a href="track-appointment.php">Track Appointment</a>
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search tabs
    const tabs = document.querySelectorAll('.search-tab');
    const sections = {
        'tracking-number': document.getElementById('section-tracking-number'),
        'email-phone': document.getElementById('section-email-phone')
    };
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            const tabId = this.dataset.tab;
            Object.keys(sections).forEach(key => {
                sections[key].classList.toggle('active', key === tabId);
            });
            
            if (tabId === 'tracking-number') {
                document.getElementById('email').value = '';
                document.getElementById('phone').value = '';
            } else {
                document.getElementById('tracking_number').value = '';
            }
        });
    });

    // Form validation
    document.getElementById('trackForm').addEventListener('submit', function(e) {
        const trackingNumber = document.getElementById('tracking_number').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        
        if (!trackingNumber && !email && !phone) {
            e.preventDefault();
            Swal.fire({
                title: 'Missing Information',
                text: 'Please enter either a tracking number OR your email and phone number.',
                icon: 'warning',
                confirmButtonColor: '#0d9e8c',
                confirmButtonText: 'OK'
            });
            return false;
        }
        return true;
    });
});
</script>

</body>
</html>