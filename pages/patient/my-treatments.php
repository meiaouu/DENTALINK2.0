<?php
// my-treatments.php - Patient Treatment History Page
require_once '../../conn/conn.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../index.php');
    exit;
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
if (($user['is_active'] ?? 0) != 1) {
    header('Location: patient-dashboard.php');
    exit;
}

$user_name = $user['full_name'] ?? 'Patient';
$user_email = $user['email'] ?? '';

// Get patient ID from patients table using email
$patient_id = null;
$stmt = $conn->prepare("SELECT id FROM patients WHERE email = ?");
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $patient_id = $row['id'];
}
$stmt->close();

// If no patient found, show message
if (!$patient_id) {
    $no_patient = true;
    $treatments = [];
    $upcoming_sessions = [];
    $inprogress_sessions = [];
} else {
    $no_patient = false;

    // Get all patient services (treatments) with status, progress, and feedback
    $treatments_query = "
        SELECT 
            ps.*,
            s.title AS service_title,
            s.price,
            s.duration,
            d.first_name AS dentist_first,
            d.last_name AS dentist_last,
            COUNT(ses.id) AS total_sessions,
            SUM(CASE WHEN ses.status = 'completed' THEN 1 ELSE 0 END) AS completed_sessions_count,
            f.id         AS feedback_id,
            f.rating     AS feedback_rating,
            f.comment    AS feedback_comment,
            f.created_at AS feedback_created_at
        FROM patient_services ps
        JOIN services s ON ps.service_id = s.id
        JOIN dentists d ON ps.dentist_id = d.id
        LEFT JOIN service_sessions ses ON ps.id = ses.patient_service_id
        LEFT JOIN feedback f ON f.patient_service_id = ps.id AND f.patient_id = ps.patient_id
        WHERE ps.patient_id = ?
        GROUP BY ps.id
        ORDER BY ps.created_at DESC
    ";

    $stmt = $conn->prepare($treatments_query);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $treatments_result = $stmt->get_result();
    $treatments = $treatments_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get upcoming sessions (scheduled)
    $upcoming_sessions_query = "
        SELECT 
            ses.*,
            ps.id AS treatment_id,
            s.title AS service_title,
            d.first_name AS dentist_first,
            d.last_name AS dentist_last
        FROM service_sessions ses
        JOIN patient_services ps ON ses.patient_service_id = ps.id
        JOIN services s ON ps.service_id = s.id
        JOIN dentists d ON ps.dentist_id = d.id
        WHERE ps.patient_id = ?
        AND ses.status = 'scheduled'
        AND (ses.date IS NULL OR ses.date >= CURDATE())
        ORDER BY ses.date ASC, ses.created_at ASC
        LIMIT 10
    ";

    $stmt = $conn->prepare($upcoming_sessions_query);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $upcoming_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get in-progress sessions
    $inprogress_sessions_query = "
        SELECT 
            ses.*,
            ps.id AS treatment_id,
            s.title AS service_title,
            d.first_name AS dentist_first,
            d.last_name AS dentist_last
        FROM service_sessions ses
        JOIN patient_services ps ON ses.patient_service_id = ps.id
        JOIN services s ON ps.service_id = s.id
        JOIN dentists d ON ps.dentist_id = d.id
        WHERE ps.patient_id = ?
        AND ses.status = 'in-progress'
        ORDER BY ses.date ASC, ses.created_at ASC
    ";

    $stmt = $conn->prepare($inprogress_sessions_query);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $inprogress_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Include sidebar
include 'psidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Treatments · Wansi Calacat Dental</title>
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

        .main-content {
            margin-left: 270px;
            min-height: 100vh;
            padding: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 1023px) {
            .main-content { margin-left: 0; }
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

        .page-header {
            background: linear-gradient(135deg, var(--navy), var(--navy-mid));
            border-radius: 20px;
            padding: 2rem 2.5rem;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(13,158,140,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        .page-header .relative { position: relative; z-index: 1; }

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

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-inprogress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .session-status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 50px;
            font-size: 10px;
            font-weight: 600;
        }
        .session-scheduled { background: #fff3cd; color: #856404; }
        .session-in-progress { background: #cce5ff; color: #004085; }
        .session-completed { background: #d4edda; color: #155724; }
        .session-cancelled { background: #f8d7da; color: #721c24; }

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
        .btn-primary:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
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
        }

        .footer {
            text-align: center;
            padding: 2rem 0 1.5rem;
            border-top: 1px solid #eef2f6;
            margin-top: 2.5rem;
        }
        .footer p { font-size: 12px; color: #94a3b8; }

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 48px; color: #d0d7e2; margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; font-weight: 600; color: var(--navy); margin-bottom: 8px; }
        .empty-state p { color: var(--slate); font-size: 14px; }

        .session-progress {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 130px;
        }
        .session-progress .progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 600;
        }
        .session-progress .progress-count { color: var(--navy); }
        .session-progress .progress-pct { color: #94a3b8; }
        .progress {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
            position: relative;
        }
        .progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: width 0.35s ease;
            min-width: 3px;
        }
        .progress-bar.low     { background: #e74c3c; }
        .progress-bar.mid     { background: #f5a623; }
        .progress-bar.high    { background: var(--teal); }
        .progress-bar.done    { background: #16a34a; }

        .treatment-card {
            background: white;
            border-radius: 14px;
            padding: 16px;
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
            margin-bottom: 12px;
        }
        .treatment-card:hover {
            border-color: var(--teal);
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }

        .session-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px 14px;
            border: 1px solid #eef2f6;
            transition: all 0.2s ease;
            margin-bottom: 8px;
        }
        .session-card:hover {
            border-color: var(--teal);
            background: #fafffe;
        }

        .service-tag {
            display: inline-block;
            background: var(--teal-light);
            color: var(--teal-dark);
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .feedback-stars {
            display: inline-flex;
            gap: 2px;
            font-size: 14px;
        }
        .feedback-stars .filled { color: #f59e0b; }
        .feedback-stars .empty  { color: #d1d5db; }

        @media (max-width: 1023px) {
            .main-content { margin-left: 0; }
        }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<!-- Include Sidebar -->
<?php include 'psidebar.php'; ?>

<!-- ====== MAIN CONTENT ====== -->
<div class="main-content">
    <div class="container-modern">

        <!-- Page Header -->
        <div class="page-header">
            <div class="relative flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white font-['Playfair_Display']">
                        <i class="fas fa-notes-medical text-teal mr-2"></i>
                        My Treatments
                    </h1>
                    <p class="text-sm text-white/70 mt-1">
                        Track all your dental treatments, sessions, and progress
                    </p>
                </div>
                <div class="flex items-center gap-3 text-white/80 text-sm">
                    <i class="fas fa-user-circle text-teal"></i>
                    <span><?= htmlspecialchars($user_name) ?></span>
                </div>
            </div>
        </div>

        <?php if ($no_patient): ?>
            <div class="dashboard-card">
                <div class="empty-state">
                    <i class="fas fa-user-plus"></i>
                    <h3>No Patient Record Found</h3>
                    <p>We couldn't find a patient record associated with your account.</p>
                    <a href="book-appointment.php" class="btn-primary mt-4 inline-flex">
                        <i class="fas fa-calendar-plus"></i> Book Your First Appointment
                    </a>
                </div>
            </div>

        <?php elseif (empty($treatments)): ?>
            <div class="dashboard-card">
                <div class="empty-state">
                    <i class="fas fa-tooth"></i>
                    <h3>No Treatments Yet</h3>
                    <p>You don't have any treatments scheduled yet. Book your first appointment to get started!</p>
                    <a href="book-appointment.php" class="btn-primary mt-4 inline-flex">
                        <i class="fas fa-calendar-plus"></i> Book Your First Appointment
                    </a>
                </div>
            </div>

        <?php else:
            // Calculate stats
            $total      = count($treatments);
            $pending    = count(array_filter($treatments, fn($t) => $t['status'] === 'pending'));
            $inprogress = count(array_filter($treatments, fn($t) => $t['status'] === 'in-progress'));
            $completed  = count(array_filter($treatments, fn($t) => $t['status'] === 'completed'));
            $cancelled  = count(array_filter($treatments, fn($t) => $t['status'] === 'cancelled'));
        ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                        <p class="text-2xl font-bold text-dark-blue mt-1"><?= $total ?></p>
                    </div>
                    <div class="stat-icon bg-light-teal text-teal">
                        <i class="fas fa-list"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
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
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">In Progress</p>
                        <p class="text-2xl font-bold text-dark-blue mt-1"><?= $inprogress ?></p>
                    </div>
                    <div class="stat-icon bg-blue-50 text-blue-600">
                        <i class="fas fa-spinner"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Completed</p>
                        <p class="text-2xl font-bold text-dark-blue mt-1"><?= $completed ?></p>
                    </div>
                    <div class="stat-icon bg-green-100 text-green-600">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Cancelled</p>
                        <p class="text-2xl font-bold text-dark-blue mt-1"><?= $cancelled ?></p>
                    </div>
                    <div class="stat-icon bg-red-50 text-red-500">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Sessions -->
        <?php if (!empty($upcoming_sessions)): ?>
        <div class="dashboard-card mb-6">
            <h3 class="font-semibold text-dark-blue flex items-center gap-2 font-['Playfair_Display'] mb-4">
                <i class="fas fa-calendar-day text-teal"></i>
                Upcoming Sessions
            </h3>
            <div class="space-y-3">
                <?php foreach ($upcoming_sessions as $session):
                    $date_display = $session['date'] ? date('M d, Y', strtotime($session['date'])) : 'Not scheduled';
                ?>
                <div class="session-card flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-semibold text-dark-blue"><?= htmlspecialchars($session['service_title']) ?></p>
                        <p class="text-xs text-gray-500">
                            <i class="far fa-calendar-alt mr-1"></i> <?= $date_display ?>
                            <span class="mx-1">·</span>
                            <i class="far fa-user mr-1"></i> Dr. <?= htmlspecialchars($session['dentist_first'] . ' ' . $session['dentist_last']) ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="session-status-badge session-scheduled">Scheduled</span>
                        <span class="text-xs text-gray-400">Session <?= $session['session_number'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- In Progress Sessions -->
        <?php if (!empty($inprogress_sessions)): ?>
        <div class="dashboard-card mb-6">
            <h3 class="font-semibold text-dark-blue flex items-center gap-2 font-['Playfair_Display'] mb-4">
                <i class="fas fa-spinner text-blue-600"></i>
                In Progress Sessions
            </h3>
            <div class="space-y-3">
                <?php foreach ($inprogress_sessions as $session):
                    $date_display = $session['date'] ? date('M d, Y', strtotime($session['date'])) : 'Not scheduled';
                ?>
                <div class="session-card flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-semibold text-dark-blue"><?= htmlspecialchars($session['service_title']) ?></p>
                        <p class="text-xs text-gray-500">
                            <i class="far fa-calendar-alt mr-1"></i> <?= $date_display ?>
                            <span class="mx-1">·</span>
                            <i class="far fa-user mr-1"></i> Dr. <?= htmlspecialchars($session['dentist_first'] . ' ' . $session['dentist_last']) ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="session-status-badge session-in-progress">In Progress</span>
                        <span class="text-xs text-gray-400">Session <?= $session['session_number'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Treatments -->
        <div class="dashboard-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-dark-blue flex items-center gap-2 font-['Playfair_Display']">
                    <i class="fas fa-list-ul text-teal"></i>
                    All Treatments
                </h3>
                <span class="text-xs text-gray-400 bg-gray-50 px-3 py-1 rounded-full">
                    <?= $total ?> treatments
                </span>
            </div>

            <?php foreach ($treatments as $treatment):
                $done = (int)($treatment['completed_sessions_count'] ?? 0);
                $tot  = (int)($treatment['total_sessions'] ?? 0);
                $pct  = $tot > 0 ? round(($done / $tot) * 100) : 0;
                $pct  = max(0, min(100, $pct));
                $barClass = $pct >= 100 ? 'done' : ($pct >= 67 ? 'high' : ($pct >= 34 ? 'mid' : 'low'));

                // Determine if treatment is fully complete
                $is_completed = ($treatment['status'] === 'completed') || ($tot > 0 && $done >= $tot);
                $has_feedback = !empty($treatment['feedback_id']);
                $rating       = (int)($treatment['feedback_rating'] ?? 0);

                // status class fix (in-progress → inprogress)
                $status_class = 'status-' . str_replace('-', '', $treatment['status']);
            ?>
            <div class="treatment-card">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h4 class="font-semibold text-dark-blue"><?= htmlspecialchars($treatment['service_title']) ?></h4>
                            <span class="status-badge <?= $status_class ?>">
                                <?= ucfirst(str_replace('-', ' ', $treatment['status'])) ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="far fa-user mr-1"></i> Dr. <?= htmlspecialchars($treatment['dentist_first'] . ' ' . $treatment['dentist_last']) ?>
                            <span class="mx-1">·</span>
                            <i class="far fa-calendar-alt mr-1"></i> Started: <?= date('M d, Y', strtotime($treatment['created_at'])) ?>
                        </p>
                        <?php if (!empty($treatment['notes'])): ?>
                            <p class="text-xs text-gray-400 mt-1"><i class="fas fa-sticky-note mr-1"></i> <?= htmlspecialchars($treatment['notes']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right min-w-[120px]">
                        <span class="text-xs text-gray-500">₱<?= number_format($treatment['price'] ?? 0, 2) ?></span>
                        <div class="session-progress mt-1">
                            <div class="progress-labels">
                                <span class="progress-count"><?= $done ?>/<?= $tot ?> sessions</span>
                                <span class="progress-pct"><?= $pct ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $pct ?>%;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($is_completed): ?>
                <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2">
                    <?php if ($has_feedback): ?>
                        <div class="flex items-center gap-2 flex-wrap">
                            <div class="feedback-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fa<?= $i <= $rating ? 's' : 'r' ?> fa-star <?= $i <= $rating ? 'filled' : 'empty' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="text-xs text-gray-500">
                                Your feedback · <?= date('M d, Y', strtotime($treatment['feedback_created_at'])) ?>
                            </span>
                        </div>
                        <button type="button"
                            class="btn-outline text-xs no-print"
                            onclick='openFeedbackModal(<?= htmlspecialchars(json_encode([
                                "id"      => (int)$treatment["id"],
                                "service" => $treatment["service_title"],
                                "rating"  => $rating,
                                "comment" => $treatment["feedback_comment"] ?? "",
                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)'>
                            <i class="fas fa-pen"></i> Edit Feedback
                        </button>
                    <?php else: ?>
                        <span class="text-xs text-gray-500">
                            <i class="fas fa-check-circle text-green-500 mr-1"></i>
                            Treatment complete — how did we do?
                        </span>
                        <button type="button"
                            class="btn-primary text-xs no-print"
                            onclick='openFeedbackModal(<?= htmlspecialchars(json_encode([
                                "id"      => (int)$treatment["id"],
                                "service" => $treatment["service_title"],
                                "rating"  => 0,
                                "comment" => "",
                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)'>
                            <i class="fas fa-star"></i> Leave Feedback
                        </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <!-- Footer -->
        <div class="footer no-print">
            <p>
                <i class="fas fa-tooth text-teal/50 mr-1"></i>
                Wansi Calacat Dental Clinic · Patient Portal
            </p>
        </div>

    </div>
</div>

<!-- ====== FEEDBACK MODAL ====== -->
<div id="feedbackModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 flex items-center justify-between" style="background: linear-gradient(135deg, #0b1628, #1a2a44);">
            <h3 class="text-white font-semibold font-['Playfair_Display'] flex items-center gap-2">
                <i class="fas fa-star text-teal"></i> Treatment Feedback
            </h3>
            <button type="button" onclick="closeFeedbackModal()" class="text-white/70 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="feedbackForm" class="p-6 space-y-4">
            <input type="hidden" name="patient_service_id" id="fb_treatment_id">
            <input type="hidden" name="rating" id="fb_rating" value="0">

            <p class="text-sm text-gray-600">
                How was your <span id="fb_service_name" class="font-semibold text-dark-blue"></span> treatment?
            </p>

            <!-- Star rating -->
            <div class="flex justify-center gap-2" id="starRating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" data-value="<?= $i ?>"
                        class="star-btn text-3xl text-gray-300 hover:text-amber-400 transition">
                        <i class="fas fa-star"></i>
                    </button>
                <?php endfor; ?>
            </div>
            <p class="text-center text-xs text-gray-400" id="fb_rating_label">Tap a star to rate</p>

            <!-- Comment -->
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Comment (optional)</label>
                <textarea name="comment" id="fb_comment" rows="3" maxlength="500"
                    class="w-full border border-gray-200 rounded-lg p-3 text-sm focus:outline-none focus:border-teal focus:ring-1 focus:ring-teal"
                    placeholder="Tell us about your experience..."></textarea>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeFeedbackModal()"
                    class="flex-1 py-2.5 rounded-lg border border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button type="submit" id="fb_submit"
                    class="flex-1 py-2.5 rounded-lg text-white text-sm font-semibold transition"
                    style="background-color: #0d9e8c;">
                    <i class="fas fa-paper-plane mr-1"></i> Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const ratingLabels = ['', 'Very Poor', 'Poor', 'Okay', 'Good', 'Excellent!'];
let currentRating = 0;

function paintStars(rating) {
    document.querySelectorAll('#starRating .star-btn').forEach(btn => {
        const v = parseInt(btn.dataset.value);
        btn.classList.toggle('text-amber-400', v <= rating);
        btn.classList.toggle('text-gray-300', v > rating);
    });
    document.getElementById('fb_rating_label').textContent =
        rating > 0 ? ratingLabels[rating] : 'Tap a star to rate';
}

function openFeedbackModal(data) {
    document.getElementById('fb_treatment_id').value = data.id;
    document.getElementById('fb_service_name').textContent = data.service;
    document.getElementById('fb_comment').value = data.comment || '';
    currentRating = data.rating || 0;
    document.getElementById('fb_rating').value = currentRating;
    paintStars(currentRating);

    const modal = document.getElementById('feedbackModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeFeedbackModal() {
    const modal = document.getElementById('feedbackModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Star interaction
document.querySelectorAll('#starRating .star-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        currentRating = parseInt(btn.dataset.value);
        document.getElementById('fb_rating').value = currentRating;
        paintStars(currentRating);
    });
    btn.addEventListener('mouseenter', () => paintStars(parseInt(btn.dataset.value)));
});
document.getElementById('starRating').addEventListener('mouseleave', () => paintStars(currentRating));

// Submit
document.getElementById('feedbackForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const rating = parseInt(document.getElementById('fb_rating').value);
    if (rating < 1) {
        Swal.fire({ icon: 'warning', title: 'Please select a rating', timer: 1500, showConfirmButton: false });
        return;
    }

    const btn = document.getElementById('fb_submit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Sending...';

    try {
        const res = await fetch('submit-feedback.php', {
            method: 'POST',
            body: new FormData(document.getElementById('feedbackForm'))
        });
        const data = await res.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Thank you!',
                text: 'Your feedback has been saved.',
                timer: 1600,
                showConfirmButton: false
            });
            location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'Oops', text: data.message || 'Could not save feedback.' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Submit';
    }
});

// Close on backdrop click
document.getElementById('feedbackModal').addEventListener('click', (e) => {
    if (e.target.id === 'feedbackModal') closeFeedbackModal();
});
</script>

</body>
</html>