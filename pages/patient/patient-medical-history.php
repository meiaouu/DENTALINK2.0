<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Check if user is a patient
if ($_SESSION['user_role'] !== 'patient') {
    header('Location: ../unauthorized.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

$medicalHistory = [];
$error_message = '';

try {
    // Get all appointments for this patient with their medical history
    $query = "
        SELECT 
            a.id as appointment_id,
            a.appointment_date,
            a.appointment_time,
            a.status,
            a.full_name,
            a.phone,
            a.email,
            a.created_at as appointment_created_at,
            m.*
        FROM appointments a
        LEFT JOIN medical_dental_history m ON a.id = m.appointment_id
        WHERE a.email = ? 
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $medicalHistory[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    $error_message = "Error loading medical history: " . $e->getMessage();
}

// Include sidebar
include 'Psidebar.php';

// ---- Tooth naming configuration ----
function getToothName($num) {
    $names = [
        18 => '3rd Molar', 17 => '2nd Molar', 16 => '1st Molar',
        15 => '2nd Premolar', 14 => '1st Premolar',
        13 => 'Canine', 12 => 'Lateral Incisor', 11 => 'Central Incisor',
        21 => 'Central Incisor', 22 => 'Lateral Incisor', 23 => 'Canine',
        24 => '1st Premolar', 25 => '2nd Premolar',
        26 => '1st Molar', 27 => '2nd Molar', 28 => '3rd Molar',
        31 => 'Central Incisor', 32 => 'Lateral Incisor', 33 => 'Canine',
        34 => '1st Premolar', 35 => '2nd Premolar',
        36 => '1st Molar', 37 => '2nd Molar', 38 => '3rd Molar',
        48 => '3rd Molar', 47 => '2nd Molar', 46 => '1st Molar',
        45 => '2nd Premolar', 44 => '1st Premolar',
        43 => 'Canine', 42 => 'Lateral Incisor', 41 => 'Central Incisor',
    ];
    return $names[$num] ?? 'Tooth';
}

// Dental chart configuration
function toothImage($num) {
    $n = (int)$num;
    $prefix = ($n >= 11 && $n <= 28) ? 'sup' : 'inf';
    return "../../assets/img/dentadura-{$prefix}-{$n}.png";
}

$upperArch = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
$lowerArch = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

$dentalConditions = [
    'healthy'    => ['label' => 'Healthy',        'color' => '#cbd5e1'],
    'caries'     => ['label' => 'Caries / Decay', 'color' => '#dc3545'],
    'filled'     => ['label' => 'Filled',         'color' => '#1e40af'],
    'crown'      => ['label' => 'Crown',          'color' => '#b45309'],
    'root_canal' => ['label' => 'Root Canal',     'color' => '#7c3aed'],
    'missing'    => ['label' => 'Missing',        'color' => '#64748b'],
    'extraction' => ['label' => 'For Extraction', 'color' => '#7f1d1d'],
    'impacted'   => ['label' => 'Impacted',       'color' => '#ea580c'],
    'fractured'  => ['label' => 'Fractured',      'color' => '#db2777'],
];

// Function to get dental chart for a specific patient
function getDentalChart($conn, $patientKey) {
    $stmt = $conn->prepare("SELECT tooth_number, condition_key, notes, updated_by, updated_at FROM dental_chart WHERE patient_key = ?");
    $stmt->bind_param('s', $patientKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $chartMap = [];
    foreach ($rows as $r) {
        $chartMap[$r['tooth_number']] = $r;
    }
    return $chartMap;
}

// Helper function to get condition label
function getConditionLabel($cond, $dentalConditions) {
    return $dentalConditions[$cond]['label'] ?? 'Healthy';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medical History & Dental Chart · Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: 'DM Sans', system-ui, sans-serif; }
        h1, h2, h3, h4 { font-family: 'Playfair Display', Georgia, serif; }
        
        :root {
            --navy: #0b1628;
            --teal: #0d9e8c;
            --teal-dark: #0a7d6e;
            --teal-light: #e6f7f5;
            --slate: #64748b;
            --amber: #b45309;
            --amber-bg: #fef3d7;
            --red: #b91c1c;
            --red-bg: #fee9e7;
            --blue: #1e40af;
            --blue-bg: #dbeafe;
            --gray-bg: #eef1f6;
            --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
        }

        .main-content {
            min-height: 100vh;
            background-color: #f7f9fc;
            background-image: linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
            padding: 20px;
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: 260px;
            }
        }
        @media (max-width: 1023px) {
            .main-content {
                margin-left: 0;
            }
        }

        .container-modern {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .dashboard-card {
            background: white;
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            margin-bottom: 1.25rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(11,22,40,.12);
        }

        .card-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--navy);
            font-family: 'DM Sans';
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .card-title i {
            color: var(--teal);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            white-space: nowrap;
        }

        .st-pending   { background: var(--amber-bg); color: var(--amber); }
        .st-approved  { background: var(--teal-light); color: var(--teal-dark); }
        .st-confirmed { background: var(--blue-bg); color: var(--blue); }
        .st-completed { background: #dcfce7; color: #166534; }
        .st-cancelled { background: var(--red-bg); color: var(--red); }
        .st-default   { background: var(--gray-bg); color: var(--slate); }

        .badge-yes {
            background: #d4edda;
            color: #155724;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-no {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .history-card {
            border-left: 4px solid var(--teal);
            transition: all 0.3s;
        }

        .history-card:hover {
            border-left-color: var(--teal-dark);
            background: #fafbfc;
        }

        .history-card .date-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f0f3f7;
            margin-bottom: 14px;
        }

        .history-card .date-header .date {
            font-size: 14px;
            font-weight: 700;
            color: var(--navy);
        }

        .history-card .date-header .time {
            font-size: 13px;
            color: var(--slate);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px 16px;
        }

        .info-item .label {
            display: block;
            font-size: 10.5px;
            color: #94a3b8;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 3px;
        }

        .info-item .value {
            font-size: 13px;
            color: var(--navy);
            font-weight: 600;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .info-item .value.yes {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .info-item .value.no {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        .info-item .value.details {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .empty-icon {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 16px;
            display: block;
        }

        .empty-state h3 {
            color: var(--navy);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--slate);
            font-size: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--teal);
            color: white;
        }

        .btn-primary:hover {
            background: var(--teal-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13,158,140,0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108,117,125,0.3);
        }

        .view-details-btn {
            background: none;
            border: 1px solid var(--teal);
            color: var(--teal);
            padding: 4px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-details-btn:hover {
            background: var(--teal);
            color: white;
        }

        .no-data-message {
            color: #999;
            font-style: italic;
            padding: 8px 0;
        }

        /* ===== DENTAL CHART STYLES ===== */
        .chart-container {
            background: #fafbfc;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #eef1f6;
            margin-top: 10px;
        }

        .chart-topbar {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding-bottom: 14px;
            margin-bottom: 16px;
            border-bottom: 1px solid #eef2f6;
        }
        
        .chart-topbar .stat {
            min-width: 140px;
        }
        
        .chart-topbar .stat span.label {
            font-size: 10.5px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .4px;
            display: block;
            margin-bottom: 3px;
        }
        
        .chart-topbar .stat span.val {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--navy);
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .chart-legend .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--slate);
        }
        
        .chart-legend .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            border: 1px solid rgba(0,0,0,0.06);
        }

        .chart-arch-label {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 4px 0 8px;
            border-bottom: 1px dashed #dce2ea;
            margin-bottom: 8px;
        }

        .chart-tooth-row {
            display: flex;
            justify-content: center;
            gap: 4px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .chart-tooth {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            width: 52px;
            padding: 4px 2px;
            border-radius: 8px;
            background: white;
            border: 1px solid #eef1f6;
            transition: all 0.15s;
        }

        .chart-tooth:hover {
            background: #f7f9fc;
            border-color: var(--teal-light);
        }

        .chart-tooth .tooth-img {
            width: 32px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--tooth-color, #cbd5e1);
            border-radius: 6px;
            background: white;
        }

        .chart-tooth .tooth-img img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .chart-tooth .tooth-num {
            font-size: 10px;
            font-weight: 700;
            color: var(--slate);
            line-height: 1.2;
        }

        .chart-tooth .tooth-name {
            font-size: 7px;
            font-weight: 600;
            color: #94a3b8;
            text-align: center;
            line-height: 1.1;
            max-width: 46px;
        }

        .chart-tooth .tooth-condition {
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 1px 4px;
            border-radius: 4px;
            background: rgba(0,0,0,0.04);
            color: #94a3b8;
            max-width: 44px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chart-tooth.missing .tooth-img img { opacity: 0.2; }
        .chart-tooth.missing .tooth-img { border-color: #cbd5e1; background: #f7f9fc; }

        .chart-divider {
            border-top: 1px dashed #dce2ea;
            margin: 8px 0 10px;
        }

        .chart-notes {
            background: #f7f9fc;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            color: var(--navy);
            line-height: 1.6;
            margin-top: 12px;
        }

        .chart-footer {
            font-size: 10.5px;
            color: #94a3b8;
            text-align: center;
            border-top: 1px solid #eef1f6;
            padding-top: 10px;
            margin-top: 12px;
        }

        @media (max-width: 768px) {
            .container-modern {
                padding: 0 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .history-card .date-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .chart-tooth {
                width: 40px;
            }
            
            .chart-tooth .tooth-img {
                width: 24px;
                height: 38px;
            }
            
            .chart-tooth .tooth-num {
                font-size: 8px;
            }
            
            .chart-tooth .tooth-name {
                font-size: 6px;
                max-width: 34px;
            }
            
            .chart-tooth .tooth-condition {
                font-size: 5px;
                max-width: 34px;
                padding: 0 2px;
            }
            
            .chart-tooth-row {
                gap: 2px;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                background: white !important;
            }
            .dashboard-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }
            .chart-container {
                background: white !important;
                border: 1px solid #e2e8f0 !important;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container-modern">
            <!-- Header -->
            <div class="dashboard-card" style="margin-bottom: 1.5rem;">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 style="font-size: 24px; font-weight: 800; color: var(--navy); display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-notes-medical text-teal-600" style="color: var(--teal);"></i>
                            My Complete Health Record
                        </h1>
                        <p style="color: var(--slate); font-size: 14px; margin-top: 4px;">
                            <i class="far fa-calendar-alt"></i> 
                            <?php echo date('F d, Y'); ?> · 
                            <?php echo count($medicalHistory); ?> appointment record(s)
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div style="background: #f8f9fa; padding: 8px 16px; border-radius: 10px; text-align: right;">
                            <div style="font-weight: 600; color: var(--navy); font-size: 14px;">
                                <?php echo htmlspecialchars($user_name); ?>
                            </div>
                            <div style="font-size: 12px; color: var(--slate);">
                                <?php echo htmlspecialchars($user_email); ?>
                            </div>
                        </div>
                        <span class="status-badge st-confirmed no-print" style="font-size: 10px;">Patient</span>
                    </div>
                </div>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="dashboard-card" style="border-left: 4px solid #dc3545; background: #f8d7da;">
                    <div style="display: flex; align-items: center; gap: 12px; color: #721c24;">
                        <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
                        <div>
                            <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- No Data Found -->
            <?php if (empty($medicalHistory)): ?>
                <div class="dashboard-card">
                    <div class="empty-state">
                        <span class="empty-icon">📋</span>
                        <h3>No Medical History Found</h3>
                        <p style="max-width: 400px; margin: 0 auto 20px;">
                            Your medical history will appear here once you have completed dental appointments.
                        </p>
                        <a href="book-appointment.php" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Book an Appointment
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Medical History Records -->
                <?php foreach ($medicalHistory as $index => $record): ?>
                    <?php 
                    // Get dental chart for this patient using phone/email as patient_key
                    $patientKey = $record['phone'] ?: ($record['email'] ?: ('appt-' . $record['appointment_id']));
                    $chartMap = getDentalChart($conn, $patientKey);
                    
                    // Calculate chart stats
                    $recordedMarks = count($chartMap);
                    $lastExamDate = null;
                    $lastUpdatedBy = null;
                    $latestNote = 'No notes recorded.';
                    $markedCount = 0;
                    
                    foreach ($chartMap as $r) {
                        if (!$lastExamDate || $r['updated_at'] > $lastExamDate) {
                            $lastExamDate = $r['updated_at'];
                            $lastUpdatedBy = $r['updated_by'];
                            if (!empty($r['notes'])) {
                                $latestNote = $r['notes'];
                            }
                        }
                        if ($r['condition_key'] !== 'healthy') {
                            $markedCount++;
                        }
                    }
                    ?>
                    
                    <?php if ($record['appointment_id']): ?>
                        <div class="dashboard-card history-card" style="animation: slideIn 0.4s ease <?php echo $index * 0.08; ?>s both;">
                            <!-- Header -->
                            <div class="date-header">
                                <div class="flex items-center gap-3 flex-wrap">
                                    <span class="date">
                                        <i class="far fa-calendar-check" style="color: var(--teal);"></i>
                                        <?php echo date('F j, Y', strtotime($record['appointment_date'] ?? 'now')); ?>
                                    </span>
                                    <span class="time">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('g:i A', strtotime($record['appointment_time'] ?? '00:00:00')); ?>
                                    </span>
                                    <span class="status-badge <?php echo 'st-' . ($record['status'] ?? 'default'); ?>">
                                        <?php echo ucfirst($record['status'] ?? 'Unknown'); ?>
                                    </span>
                                    <?php if ($record['appointment_created_at']): ?>
                                    <span style="font-size: 11px; color: #94a3b8;">
                                        <i class="far fa-calendar-alt"></i>
                                        Recorded: <?php echo date('M j, Y', strtotime($record['appointment_created_at'])); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span style="font-size: 11px; color: var(--teal); font-weight: 600;">
                                        <i class="fas fa-tooth"></i>
                                        <?php echo $recordedMarks; ?> tooth mark(s)
                                    </span>
                                </div>
                                <button class="view-details-btn" onclick="toggleDetails(<?php echo $index; ?>)">
                                    <i class="fas fa-chevron-down" id="toggleIcon<?php echo $index; ?>"></i>
                                    <span id="toggleText<?php echo $index; ?>">View Details</span>
                                </button>
                            </div>

                            <!-- Quick Summary -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px;">
                                <div>
                                    <span style="font-size: 10.5px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Patient</span>
                                    <div style="font-size: 13px; font-weight: 600; color: var(--navy);">
                                        <?php echo htmlspecialchars($record['full_name'] ?? 'N/A'); ?>
                                    </div>
                                </div>
                                <div>
                                    <span style="font-size: 10.5px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Phone</span>
                                    <div style="font-size: 13px; font-weight: 600; color: var(--navy);">
                                        <?php echo htmlspecialchars($record['phone'] ?? 'N/A'); ?>
                                    </div>
                                </div>
                                <div>
                                    <span style="font-size: 10.5px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Under Care</span>
                                    <div>
                                        <span class="badge-<?php echo ($record['under_physician_care'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                            <?php echo ucfirst($record['under_physician_care'] ?? 'No'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Detailed Information (Hidden by default) -->
                            <div id="details<?php echo $index; ?>" style="display: none; padding-top: 14px; border-top: 1px solid #f0f3f7; margin-top: 10px;">
                                
                                <!-- DENTAL CHART SECTION - IMPROVED DESIGN -->
                                <div style="margin-bottom: 20px;">
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 12px;">
                                        <i class="fas fa-tooth" style="color: var(--teal);"></i> Dental Chart
                                    </h4>
                                    
                                    <?php if (!empty($chartMap)): ?>
                                        <div class="chart-container">
                                            <!-- Chart Topbar -->
                                            <div class="chart-topbar">
                                                <div class="stat">
                                                    <span class="label">Latest Exam Date</span>
                                                    <span class="val"><?php echo $lastExamDate ? date('M j, Y g:i A', strtotime($lastExamDate)) : '—'; ?></span>
                                                </div>
                                                <div class="stat">
                                                    <span class="label">Updated By</span>
                                                    <span class="val"><?php echo htmlspecialchars($lastUpdatedBy ?: '—'); ?></span>
                                                </div>
                                                <div class="stat">
                                                    <span class="label">Recorded Marks</span>
                                                    <span class="val"><?php echo $recordedMarks; ?> mark(s)</span>
                                                </div>
                                                <div class="stat" style="flex:1; min-width:180px;">
                                                    <span class="label">Notes / Findings</span>
                                                    <span class="val"><?php echo htmlspecialchars($latestNote); ?></span>
                                                </div>
                                            </div>

                                            <!-- Legend -->
                                            <div class="chart-legend">
                                                <?php foreach ($dentalConditions as $key => $c): ?>
                                                <span class="legend-item">
                                                    <span class="legend-dot" style="background:<?php echo $c['color']; ?>;"></span>
                                                    <?php echo htmlspecialchars($c['label']); ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Upper Arch -->
                                            <div class="chart-arch-label">Upper Arch (Maxillary)</div>
                                            <div class="chart-tooth-row">
                                                <?php foreach ($upperArch as $num): ?>
                                                    <?php 
                                                    $entry = $chartMap[$num] ?? null;
                                                    $cond = $entry['condition_key'] ?? 'healthy';
                                                    $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                                                    $toothName = getToothName($num);
                                                    $condLabel = getConditionLabel($cond, $dentalConditions);
                                                    $isMissing = $cond === 'missing';
                                                    ?>
                                                    <div class="chart-tooth <?php echo $isMissing ? 'missing' : ''; ?>" style="--tooth-color: <?php echo $color; ?>;" title="<?php echo htmlspecialchars($entry['notes'] ?? ''); ?>">
                                                        <div class="tooth-img" style="border-color: <?php echo $color; ?>;">
                                                            <img src="<?php echo htmlspecialchars(toothImage($num)); ?>" alt="Tooth <?php echo $num; ?>" onerror="this.style.opacity=0.15;">
                                                        </div>
                                                        <span class="tooth-num"><?php echo $num; ?></span>
                                                        <span class="tooth-name"><?php echo htmlspecialchars($toothName); ?></span>
                                                        <?php if ($cond !== 'healthy'): ?>
                                                            <span class="tooth-condition" style="background:<?php echo $color; ?>20; color:<?php echo $color; ?>;"><?php echo htmlspecialchars($condLabel); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="chart-divider"></div>

                                            <!-- Lower Arch -->
                                            <div class="chart-arch-label">Lower Arch (Mandibular)</div>
                                            <div class="chart-tooth-row">
                                                <?php foreach ($lowerArch as $num): ?>
                                                    <?php 
                                                    $entry = $chartMap[$num] ?? null;
                                                    $cond = $entry['condition_key'] ?? 'healthy';
                                                    $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                                                    $toothName = getToothName($num);
                                                    $condLabel = getConditionLabel($cond, $dentalConditions);
                                                    $isMissing = $cond === 'missing';
                                                    ?>
                                                    <div class="chart-tooth <?php echo $isMissing ? 'missing' : ''; ?>" style="--tooth-color: <?php echo $color; ?>;" title="<?php echo htmlspecialchars($entry['notes'] ?? ''); ?>">
                                                        <div class="tooth-img" style="border-color: <?php echo $color; ?>;">
                                                            <img src="<?php echo htmlspecialchars(toothImage($num)); ?>" alt="Tooth <?php echo $num; ?>" onerror="this.style.opacity=0.15;">
                                                        </div>
                                                        <span class="tooth-num"><?php echo $num; ?></span>
                                                        <span class="tooth-name"><?php echo htmlspecialchars($toothName); ?></span>
                                                        <?php if ($cond !== 'healthy'): ?>
                                                            <span class="tooth-condition" style="background:<?php echo $color; ?>20; color:<?php echo $color; ?>;"><?php echo htmlspecialchars($condLabel); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Chart Footer -->
                                            <div class="chart-footer">
                                                <?php echo $recordedMarks; ?> tooth record(s) on file · 
                                                <?php echo $markedCount; ?> marked with conditions
                                                <?php if ($markedCount > 0): ?>
                                                    · <?php echo number_format(($markedCount / max(1, $recordedMarks)) * 100, 0); ?>% affected
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Detailed tooth notes if any -->
                                        <?php 
                                        $detailedNotes = [];
                                        foreach ($chartMap as $num => $entry) {
                                            if (!empty($entry['notes'])) {
                                                $detailedNotes[] = "Tooth #{$num} (" . getToothName($num) . "): " . $entry['notes'];
                                            }
                                        }
                                        if (!empty($detailedNotes)): 
                                        ?>
                                        <div class="chart-notes" style="margin-top: 10px;">
                                            <strong><i class="fas fa-sticky-note" style="color: var(--teal);"></i> Tooth Notes:</strong>
                                            <ul style="margin-top: 6px; padding-left: 20px; list-style: disc;">
                                                <?php foreach ($detailedNotes as $note): ?>
                                                <li><?php echo htmlspecialchars($note); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <p class="no-data-message" style="padding: 20px; text-align: center; background: #f8f9fa; border-radius: 8px;">
                                            <i class="fas fa-info-circle" style="color: var(--teal);"></i> 
                                            No dental chart records found for this patient.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <!-- 1. General Information -->
                                <div style="margin-bottom: 16px;">
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;">
                                        <i class="fas fa-user-md" style="color: var(--teal);"></i> General Information
                                    </h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="label">Under Physician's Care</span>
                                            <span class="value <?php echo ($record['under_physician_care'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['under_physician_care'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($record['physician_name'])): ?>
                                        <div class="info-item">
                                            <span class="label">Physician Name</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['physician_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="info-item">
                                            <span class="label">Pregnant</span>
                                            <span class="value <?php echo ($record['pregnant'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['pregnant'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <div class="info-item">
                                            <span class="label">Taking Medicine</span>
                                            <span class="value <?php echo ($record['taking_medicine'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['taking_medicine'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($record['medicine_details'])): ?>
                                        <div class="info-item" style="grid-column: 1 / -1;">
                                            <span class="label">Medicine Details</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['medicine_details']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 2. Medical Conditions -->
                                <div style="margin-bottom: 16px;">
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;">
                                        <i class="fas fa-heartbeat" style="color: var(--teal);"></i> Medical Conditions
                                    </h4>
                                    <?php if (!empty($record['conditions']) || !empty($record['other_condition_details'])): ?>
                                        <div class="info-grid">
                                            <?php if (!empty($record['conditions'])): ?>
                                            <div class="info-item" style="grid-column: 1 / -1;">
                                                <span class="label">Conditions</span>
                                                <span class="value details"><?php echo htmlspecialchars($record['conditions']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($record['other_condition_details'])): ?>
                                            <div class="info-item" style="grid-column: 1 / -1;">
                                                <span class="label">Other Condition Details</span>
                                                <span class="value details"><?php echo htmlspecialchars($record['other_condition_details']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="no-data-message">No conditions reported</p>
                                    <?php endif; ?>
                                </div>

                                <!-- 3. Allergies -->
                                <div style="margin-bottom: 16px;">
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;">
                                        <i class="fas fa-exclamation-triangle" style="color: var(--teal);"></i> Allergies
                                    </h4>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px;">
                                        <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; font-weight: 500;">Local Anesthesia</span>
                                            <span class="badge-<?php echo ($record['allergic_local_anesthesia'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['allergic_local_anesthesia'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; font-weight: 500;">Antibiotics</span>
                                            <span class="badge-<?php echo ($record['allergic_antibiotics'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['allergic_antibiotics'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; font-weight: 500;">Pain Killers</span>
                                            <span class="badge-<?php echo ($record['allergic_pain_killer'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['allergic_pain_killer'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 12px; font-weight: 500;">Other Allergies</span>
                                            <span class="badge-<?php echo ($record['allergic_other'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['allergic_other'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if (!empty($record['allergic_other_details'])): ?>
                                    <div style="margin-top: 10px;">
                                        <span style="font-size: 10.5px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Other Allergy Details</span>
                                        <div style="background: #fff3cd; padding: 8px 12px; border-radius: 6px; border: 1px solid #ffc107; color: #856404; font-size: 13px; margin-top: 4px;">
                                            <?php echo htmlspecialchars($record['allergic_other_details']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- 4. Hospitalization -->
                                <div style="margin-bottom: 16px;">
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;">
                                        <i class="fas fa-hospital" style="color: var(--teal);"></i> Hospitalization History
                                    </h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="label">Ever Hospitalized</span>
                                            <span class="value <?php echo ($record['hospitalized'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['hospitalized'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($record['hospitalized_when'])): ?>
                                        <div class="info-item">
                                            <span class="label">When</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['hospitalized_when']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($record['hospitalized_why'])): ?>
                                        <div class="info-item" style="grid-column: 1 / -1;">
                                            <span class="label">Reason</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['hospitalized_why']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 5. Dental History -->
                                <div>
                                    <h4 style="font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;">
                                        <i class="fas fa-tooth" style="color: var(--teal);"></i> Dental History
                                    </h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="label">Wears Denture</span>
                                            <span class="value <?php echo ($record['worn_denture'] ?? 'no') === 'yes' ? 'yes' : 'no'; ?>">
                                                <?php echo ucfirst($record['worn_denture'] ?? 'No'); ?>
                                            </span>
                                        </div>
                                        <div class="info-item">
                                            <span class="label">Last Dental Visit</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['last_dental_visit'] ?? 'N/A'); ?></span>
                                        </div>
                                        <?php if (!empty($record['last_dental_visit_reason'])): ?>
                                        <div class="info-item" style="grid-column: 1 / -1;">
                                            <span class="label">Reason for Last Visit</span>
                                            <span class="value details"><?php echo htmlspecialchars($record['last_dental_visit_reason']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="dashboard-card no-print" style="margin-top: 1.5rem;">
                <div style="display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center;">
                    <a href="patient-dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="book-appointment.php" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Book New Appointment
                        </a>
                        <button onclick="window.print()" class="btn btn-secondary" style="background: var(--navy);">
                            <i class="fas fa-print"></i> Print / PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleDetails(index) {
            const details = document.getElementById('details' + index);
            const icon = document.getElementById('toggleIcon' + index);
            const text = document.getElementById('toggleText' + index);
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.className = 'fas fa-chevron-up';
                text.textContent = 'Hide Details';
                // Smooth scroll to show details
                details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                details.style.display = 'none';
                icon.className = 'fas fa-chevron-down';
                text.textContent = 'View Details';
            }
        }

        // Auto-expand first record if there's only one
        document.addEventListener('DOMContentLoaded', function() {
            const records = document.querySelectorAll('.history-card');
            if (records.length === 1) {
                toggleDetails(0);
            }
        });
    </script>
</body>
</html>