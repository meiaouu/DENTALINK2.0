<?php
// book-appointment.php - Patient Booking Page with Editable Pre-filled Info & Medical History
require_once '../../conn/conn.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set clinic timezone for all server-side time calculations
date_default_timezone_set('Asia/Manila');

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
$user_phone = $user['phone'] ?? '';
$user_address = $user['address'] ?? '';
$user_age = $user['age'] ?? '';
$user_gender = $user['gender'] ?? '';
$user_occupation = $user['occupation'] ?? '';

// Get settings
$settings = $conn->query("SELECT * FROM clinic_settings WHERE id = 1")->fetch_assoc();

// Get services
$services = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);

// Get patient's existing medical history
$medical_history = null;

$stmt = $conn->prepare("
    SELECT mdh.* FROM medical_dental_history mdh
    JOIN appointments a ON mdh.appointment_id = a.id
    WHERE a.email = ?
    ORDER BY a.created_at DESC LIMIT 1
");
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $medical_history = $result->fetch_assoc();
}
$stmt->close();

if (!$medical_history && !empty($user_phone)) {
    $stmt = $conn->prepare("
        SELECT mdh.* FROM medical_dental_history mdh
        JOIN appointments a ON mdh.appointment_id = a.id
        WHERE a.phone = ?
        ORDER BY a.created_at DESC LIMIT 1
    ");
    $stmt->bind_param("s", $user_phone);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $medical_history = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$medical_history) {
    $check_col = $conn->query("SHOW COLUMNS FROM medical_dental_history LIKE 'patient_id'");
    if ($check_col->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM medical_dental_history WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $medical_history = $result->fetch_assoc();
        }
        $stmt->close();
    }
}

include 'psidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment · Wansi Calacat Dental</title>
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
            --teal-soft: rgba(13,158,140,.08);
            --slate: #64748b;
            --line: #e2e8f0;
            --shadow-card: 0 2px 8px rgba(11,22,40,.05), 0 10px 28px rgba(11,22,40,.06);
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
        .bg-soft-gray { background-color: #f7f9fc; }

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
            opacity: 0.5;
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

        .btn-ghost {
            padding: 10px 22px;
            background: white;
            border: 2px solid var(--line);
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            color: #4a5568;
            cursor: pointer;
            transition: all .2s ease;
        }
        .btn-ghost:hover {
            border-color: #cbd5e0;
            background: #f8fafc;
            transform: translateY(-1px);
        }

        .f-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 5px;
            display: block;
        }
        .f-label .edit-hint {
            font-weight: 400;
            color: #94a3b8;
            font-size: 10px;
            font-style: italic;
        }

        .f-input, .f-select, .f-textarea {
            width: 100%;
            padding: 11px 13px;
            border: 2px solid var(--line);
            border-radius: 10px;
            font-size: 13.5px;
            background: #fafbfc;
            color: var(--navy);
            transition: all .2s ease;
            font-family: 'DM Sans', system-ui, sans-serif;
        }
        .f-input:focus, .f-select:focus, .f-textarea:focus {
            outline: none;
            border-color: var(--teal);
            background: white;
            box-shadow: 0 0 0 4px var(--teal-soft);
        }
        .f-input:disabled, .f-select:disabled {
            background: #f1f4f8;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .f-textarea {
            resize: vertical;
            min-height: 60px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .form-grid-2 { grid-template-columns: 1fr; }
        }

        .service-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--teal-light);
            border: 1px solid #bfe9e2;
            border-radius: 10px;
            padding: 10px 12px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: var(--teal-dark);
            transition: background .2s ease, border-color .2s ease;
        }
        .service-toggle:hover {
            background: #dcf4f0;
            border-color: var(--teal);
        }
        .service-toggle .count-badge {
            background: var(--teal);
            color: white;
            border-radius: 999px;
            width: 20px;
            height: 20px;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 6px;
        }
        .service-panel {
            margin-top: 8px;
            display: none;
            flex-direction: column;
            gap: 6px;
        }
        .service-panel.open {
            display: flex;
        }
        .service-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            padding: 7px 9px;
            border-radius: 8px;
            border: 1px solid var(--line);
            cursor: pointer;
            transition: border-color .2s ease, background .2s ease;
        }
        .service-row:hover {
            border-color: var(--teal);
            background: #fafffe;
        }
        .service-row input {
            accent-color: var(--teal);
            width: 15px;
            height: 15px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        .day-name {
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-align: center;
            padding: 4px 0;
            text-transform: uppercase;
        }
        .day-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            background: #f8fafc;
            border: 2px solid transparent;
            transition: all .18s ease;
            position: relative;
            padding: 2px;
        }
        .day-cell .day-num {
            font-weight: 700;
            line-height: 1;
            font-size: 13px;
        }
        .day-cell .day-icon {
            font-size: 9px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .day-cell.other-month { visibility: hidden; }

        .day-cell.available {
            background: #e6f7f5;
            color: #0a7d6e;
            border-color: #b8e5df;
        }
        .day-cell.available .day-icon { color: #0d9e8c; }
        .day-cell.available:hover:not(.disabled) {
            background: #0d9e8c;
            color: white;
            border-color: #0d9e8c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13,158,140,.35);
        }
        .day-cell.available:hover:not(.disabled) .day-icon { color: white; }

        .day-cell.half-day {
            background: #fef3d7;
            color: #b45309;
            border-color: #fcd9a0;
        }
        .day-cell.half-day .day-icon { color: #d97706; }
        .day-cell.half-day:hover:not(.disabled) {
            background: #d97706;
            color: white;
            border-color: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(217,119,6,.35);
        }
        .day-cell.half-day:hover:not(.disabled) .day-icon { color: white; }

        .day-cell.disabled {
            background: #f1f4f8;
            color: #cbd5e1;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }
        .day-cell.disabled .day-icon { color: #cbd5e1; }
        .day-cell.disabled .day-num {
            text-decoration: line-through;
            text-decoration-color: #cbd5e1;
            text-decoration-thickness: 1.5px;
        }
        .day-cell.disabled:hover { transform: none; box-shadow: none; }

        .day-cell.selected {
            background: #0d9e8c !important;
            color: white !important;
            border-color: #0d9e8c !important;
            box-shadow: 0 4px 14px rgba(13,158,140,.45);
            transform: translateY(-2px);
        }
        .day-cell.selected .day-icon { color: white !important; }
        .day-cell.selected .day-num { text-decoration: none !important; }

        .day-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--line);
            font-size: 10.5px;
            color: var(--slate);
        }
        .day-legend .leg-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .day-legend .leg-swatch {
            width: 16px;
            height: 16px;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 8px;
            border: 1.5px solid transparent;
        }
        .day-legend .leg-available  { background: #e6f7f5; border-color: #b8e5df; color: #0d9e8c; }
        .day-legend .leg-half       { background: #fef3d7; border-color: #fcd9a0; color: #d97706; }
        .day-legend .leg-unavail    { background: #f1f4f8; border-color: #e2e8f0; color: #cbd5e1; }

        .slots-window {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .slots-window.half {
            background: #fef3d7;
            color: #b45309;
            border: 1px solid #fcd9a0;
        }
        .slots-window.half i { color: #d97706; }

        .time-slots-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 8px;
        }
        .time-slot-btn {
            padding: 8px 10px;
            border: 2px solid var(--line);
            border-radius: 9px;
            background: white;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all .18s ease;
        }
        .time-slot-btn:hover:not(.disabled):not(.booked) {
            border-color: var(--teal);
            background: var(--teal-light);
        }
        .time-slot-btn.selected {
            border-color: var(--teal);
            background: var(--teal);
            color: white;
            box-shadow: 0 4px 12px rgba(13,158,140,.3);
        }
        .time-slot-btn.booked {
            background: #fee9e7;
            border-color: #fca5a5;
            color: #dc2626;
            cursor: not-allowed;
        }
        .empty-hint {
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
            padding: 24px 8px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px dashed var(--line);
        }

        .cal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .cal-month {
            font-weight: 700;
            font-size: 15px;
            min-width: 130px;
            text-align: center;
        }
        .cal-nav {
            border: 1px solid var(--line);
            background: white;
            border-radius: 8px;
            width: 30px;
            height: 30px;
            cursor: pointer;
            transition: all .2s ease;
            color: var(--slate);
        }
        .cal-nav:hover {
            border-color: var(--teal);
            color: var(--teal);
            background: var(--teal-light);
        }

        .time-fmt-toggle {
            display: flex;
            background: #f1f4f8;
            border-radius: 8px;
            padding: 2px;
        }
        .time-fmt-toggle button {
            border: none;
            background: transparent;
            font-size: 10px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 6px;
            cursor: pointer;
            color: #94a3b8;
            transition: all .2s ease;
        }
        .time-fmt-toggle button.active {
            background: white;
            color: var(--teal);
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }

        .status-bar {
            margin-top: 1rem;
            background: white;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            box-shadow: var(--shadow-card);
        }
        .status-bar .main {
            font-weight: 700;
            font-size: 13px;
        }
        .status-bar .hint {
            font-size: 11.5px;
            color: #94a3b8;
        }

        .service-chip {
            display: inline-block;
            background: var(--teal-light);
            color: var(--teal-dark);
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            margin: 2px 3px 2px 0;
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
        .page-header .relative {
            position: relative;
            z-index: 1;
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

        .review-block {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: .8rem;
        }
        .review-block .rb-title {
            font-size: 10.5px;
            font-weight: 800;
            color: var(--teal);
            text-transform: uppercase;
            letter-spacing: .5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .review-row {
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            padding: 4px 0;
            gap: 12px;
        }
        .review-row .l {
            color: var(--slate);
            white-space: nowrap;
        }
        .review-row .v {
            font-weight: 600;
            text-align: right;
        }

        .confirmed-wrap {
            max-width: 480px;
            margin: 2rem auto 0;
            text-align: center;
        }
        .confirmed-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: var(--teal-light);
            color: var(--teal);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            margin: 0 auto 1rem;
            box-shadow: 0 0 0 8px rgba(13,158,140,.06);
            animation: popIn .4s ease;
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(.7); }
            to { opacity: 1; transform: scale(1); }
        }
        .tracking-box {
            background: var(--teal-light);
            border-radius: 12px;
            padding: 14px;
            margin: 1.25rem 0;
            border: 1px dashed #bfe9e2;
        }
        .tracking-id {
            font-size: 22px;
            font-weight: 800;
            color: var(--teal-dark);
            letter-spacing: 1.5px;
        }

        .step-track {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 0;
            margin: 1.75rem auto 2.25rem;
            max-width: 720px;
        }
        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }
        .step-node .dot {
            width: 30px; height: 30px; border-radius: 50%; background: white; border: 2px solid var(--line);
            color: #94a3b8; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center;
            transition: all .3s ease; z-index: 1;
        }
        .step-node.active .dot { background: var(--teal); border-color: var(--teal); color: white; box-shadow: 0 0 0 5px var(--teal-soft), 0 4px 14px rgba(13,158,140,.3); }
        .step-node.done .dot { background: var(--teal); border-color: var(--teal); color: white; }
        .step-node.done .dot::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 11px; }
        .step-node .lbl { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #94a3b8; margin-top: 7px; transition: color .3s ease; text-align: center; }
        .step-node.active .lbl, .step-node.done .lbl { color: var(--teal); }
        .step-node:not(:last-child)::after {
            content: ''; position: absolute; top: 15px; left: calc(50% + 20px); right: calc(-50% + 20px);
            height: 2px; background: var(--line); z-index: 0; transition: background .4s ease;
        }
        .step-node.done:not(:last-child)::after { background: var(--teal); }

        .step-view { display: none; }
        .step-view.active { display: block; animation: fadeStep .35s ease; }
        @keyframes fadeStep { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.75rem;
            max-width: 640px;
            margin-left: auto;
            margin-right: auto;
        }

        /* --- Phone field: locked +63 prefix --- */
        .phone-wrap {
            display: flex;
            align-items: stretch;
            border: 2px solid #86d9c4;
            border-radius: 10px;
            background: #f0fdf6;
            overflow: hidden;
            transition: all .2s ease;
        }
        .phone-wrap:focus-within {
            border-color: var(--teal);
            background: white;
            box-shadow: 0 0 0 4px var(--teal-soft);
        }
        .phone-wrap.is-valid   { border-color: #0d9e8c; }
        .phone-wrap.is-invalid { border-color: #e53e3e; }
        .phone-prefix {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
            background: #eef2f6;
            color: var(--slate);
            font-weight: 700;
            font-size: 13.5px;
            border-right: 1px solid var(--line);
            user-select: none;
            min-width: 52px;
        }
        .phone-input {
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            flex: 1;
        }

        .pre-filled {
            background: #f0fdf6;
            border-color: #86d9c4;
        }
        .pre-filled:focus {
            border-color: var(--teal);
            background: white;
        }
        .pre-filled-hint {
            font-size: 10px;
            color: var(--teal-dark);
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .pre-filled-hint i {
            font-size: 10px;
        }

        .mh-question {
            margin-bottom: 1.25rem;
            padding: 12px 16px;
            background: #fafbfc;
            border-radius: 10px;
            border: 1px solid var(--line);
        }
        .mh-question .q-text {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 8px;
        }
        .radio-group {
            display: flex;
            gap: 22px;
            flex-wrap: wrap;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--navy);
            cursor: pointer;
        }
        .radio-option input {
            accent-color: var(--teal);
            width: 16px;
            height: 16px;
        }
        .mh-followup {
            margin-top: 10px;
            display: none;
        }
        .mh-followup.show {
            display: block;
        }
        .checkbox-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 14px;
            margin-top: 8px;
        }
        @media (max-width: 640px) {
            .checkbox-grid { grid-template-columns: 1fr; }
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            padding: 7px 9px;
            border-radius: 8px;
            border: 1px solid var(--line);
            cursor: pointer;
            transition: border-color .2s ease, background .2s ease;
        }
        .checkbox-row:hover {
            border-color: var(--teal);
            background: #fafffe;
        }
        .checkbox-row input {
            accent-color: var(--teal);
            width: 15px;
            height: 15px;
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="container-modern">

        <div class="page-header">
            <div class="relative flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white font-['Playfair_Display']">
                        <i class="fas fa-calendar-plus text-teal mr-2"></i>
                        Book Appointment
                    </h1>
                    <p class="text-sm text-white/70 mt-1">
                        Schedule your next dental visit with ease
                    </p>
                </div>
                <div class="flex items-center gap-3 text-white/80 text-sm">
                    <i class="fas fa-user-circle text-teal"></i>
                    <span><?= htmlspecialchars($user_name) ?></span>
                </div>
            </div>
        </div>

        <div class="step-track">
            <div class="step-node active" data-step="1"><div class="dot">1</div><span class="lbl">Schedule</span></div>
            <div class="step-node" data-step="2"><div class="dot">2</div><span class="lbl">Your Info</span></div>
            <div class="step-node" data-step="3"><div class="dot">3</div><span class="lbl">Medical History</span></div>
            <div class="step-node" data-step="4"><div class="dot">4</div><span class="lbl">Review</span></div>
            <div class="step-node" data-step="5"><div class="dot">5</div><span class="lbl">Confirm</span></div>
        </div>

        <!-- ============ STEP 1: SCHEDULE ============ -->
        <div class="step-view active" id="stepView1">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="dashboard-card">
                    <h3 class="text-sm font-bold text-dark-blue mb-3 flex items-center gap-2">
                        <i class="fas fa-tooth text-teal"></i> Select Service
                    </h3>
                    <div class="service-toggle" onclick="toggleServicePanel()">
                        <span><span class="count-badge" id="serviceCountBadge">0</span><span id="serviceCountLabel">services selected</span></span>
                        <i class="fas fa-chevron-down" id="serviceChevron"></i>
                    </div>
                    <div class="service-panel" id="servicePanel">
                        <?php foreach ($services as $service): ?>
                        <label class="service-row">
                            <input type="checkbox" value="<?= (int)$service['id'] ?>" data-title="<?= htmlspecialchars($service['title']) ?>" data-price="<?= (float)$service['price'] ?>" onchange="onServiceToggle()">
                            <?= htmlspecialchars($service['title']) ?>
                            <span class="text-xs text-gray-400 ml-auto">₱<?= number_format($service['price'] ?? 0, 2) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="cal-head">
                        <button class="cal-nav" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                        <span class="cal-month" id="calendarMonth">July 2026</span>
                        <button class="cal-nav" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                    <div class="day-legend">
                        <span class="leg-item">
                            <span class="leg-swatch leg-available"><i class="fas fa-check"></i></span>
                            Available
                        </span>
                        <span class="leg-item">
                            <span class="leg-swatch leg-half"><i class="fas fa-adjust"></i></span>
                            Half Day
                        </span>
                        <span class="leg-item">
                            <span class="leg-swatch leg-unavail"><i class="fas fa-ban"></i></span>
                            Unavailable
                        </span>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h3 class="text-sm font-bold text-dark-blue flex items-center gap-2">
                            <i class="fas fa-clock text-teal"></i> Time Slots
                        </h3>
                        <div class="time-fmt-toggle">
                            <button id="fmt12" class="active" onclick="setTimeFormat('12h')">12h</button>
                            <button id="fmt24" onclick="setTimeFormat('24h')">24h</button>
                        </div>
                    </div>
                    <div id="timeSlotsContainer">
                        <div class="empty-hint">Select a date and service to view available slots</div>
                    </div>
                </div>
            </div>

            <div class="status-bar">
                <div>
                    <div class="main" id="statusMain">No date selected</div>
                    <div class="hint" id="statusHint">Select a service, tap an available date, then pick a time</div>
                </div>
                <button class="btn-primary" id="toStep2Btn" disabled onclick="goToStep(2)">
                    Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- ============ STEP 2: YOUR INFO ============ -->
        <div class="step-view" id="stepView2">
            <div class="dashboard-card max-w-3xl mx-auto">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fas fa-user-edit text-teal"></i>
                    <h3 class="font-semibold text-dark-blue font-['Playfair_Display']">Your Information</h3>
                    <span class="text-xs text-gray-400 ml-auto">
                        <i class="fas fa-pencil-alt text-teal mr-1"></i> Edit any field if needed
                    </span>
                </div>

                <div class="form-grid-2">
                    <div class="f-group">
                        <label class="f-label">First Name <span style="color:#e53e3e;">*</span></label>
                        <input type="text" class="f-input pre-filled" id="firstName" placeholder="Juan">
                    </div>
                    <div class="f-group">
                        <label class="f-label">Middle Name <span class="edit-hint">(optional)</span></label>
                        <input type="text" class="f-input pre-filled" id="middleName" placeholder="Santos">
                    </div>
                </div>

                <div class="f-group">
                    <label class="f-label">Last Name <span style="color:#e53e3e;">*</span></label>
                    <input type="text" class="f-input pre-filled" id="lastName" placeholder="Dela Cruz">
                    <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile</div>
                </div>

                <div class="form-grid-2">
                    <div class="f-group">
                        <label class="f-label">Age</label>
                        <input type="number" class="f-input pre-filled" id="age" value="<?= htmlspecialchars($user_age) ?>" min="0" max="120">
                        <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile</div>
                    </div>
                    <div class="f-group">
                        <label class="f-label">Gender</label>
                        <select class="f-select pre-filled" id="gender">
                            <option value="">Select gender</option>
                            <option value="Male" <?= $user_gender === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $user_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Other" <?= $user_gender === 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                        <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile</div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="f-group">
                        <label class="f-label">Contact Number <span style="color:#e53e3e;">*</span></label>
                        <div class="phone-wrap" id="phoneWrap">
                            <span class="phone-prefix" id="phonePrefix">+63</span>
                            <input type="tel" class="f-input phone-input" id="contactNumber"
                                   placeholder="9171234567" inputmode="numeric" autocomplete="tel-national"
                                   maxlength="10">
                        </div>
                        <p class="f-hint" id="phoneHint" style="font-size:11px; color:#94a3b8; margin-top:5px;">Enter the 10 digits after +63 (starting with 9).</p>
                    </div>
                    <div class="f-group">
                        <label class="f-label">Email Address</label>
                        <input type="email" class="f-input pre-filled" id="contactEmail" value="<?= htmlspecialchars($user_email) ?>">
                        <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile</div>
                    </div>
                </div>

                <div class="f-group">
                    <label class="f-label">Occupation</label>
                    <input type="text" class="f-input pre-filled" id="occupation" value="<?= htmlspecialchars($user_occupation) ?>">
                    <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile</div>
                </div>

                <div class="form-grid-2">
                    <div class="f-group">
                        <label class="f-label">Region</label>
                        <select class="f-select" id="regionSelect" onchange="loadProvinces()">
                            <option value="">Select region</option>
                        </select>
                    </div>
                    <div class="f-group">
                        <label class="f-label">Province</label>
                        <select class="f-select" id="provinceSelect" onchange="loadCities()" disabled>
                            <option value="">Select province</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="f-group">
                        <label class="f-label">City / Municipality</label>
                        <select class="f-select" id="citySelect" onchange="loadBarangays()" disabled>
                            <option value="">Select city / municipality</option>
                        </select>
                    </div>
                    <div class="f-group">
                        <label class="f-label">Barangay</label>
                        <select class="f-select" id="barangaySelect" disabled>
                            <option value="">Select barangay</option>
                        </select>
                    </div>
                </div>

                <div class="f-group">
                    <label class="f-label">Street / House No. / Purok</label>
                    <input type="text" class="f-input pre-filled" id="streetAddress" value="<?= htmlspecialchars($user_address) ?>" placeholder="House no., street, purok, subdivision">
                    <div class="pre-filled-hint"><i class="fas fa-check-circle text-teal"></i> Pre-filled from your profile. Select your area above, then adjust the details.</div>
                </div>

                <div class="f-group">
                    <label class="f-label">Notes / Concern <span class="edit-hint">(optional)</span></label>
                    <textarea class="f-textarea" id="patientNotes" rows="3" placeholder="Any additional notes or concerns about your appointment..."></textarea>
                </div>

                <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    Your information is pre-filled from your account. You can edit any field above if needed.
                    <a href="profile.php" class="text-teal hover:underline ml-1">Update your profile</a>
                </div>
            </div>

            <div class="actions-row max-w-3xl mx-auto">
                <button class="btn-ghost" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                <button class="btn-primary" onclick="validateStep2()">Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- ============ STEP 3: MEDICAL & DENTAL HISTORY ============ -->
        <div class="step-view" id="stepView3">
            <div class="dashboard-card max-w-3xl mx-auto">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fas fa-notes-medical text-teal"></i>
                    <h3 class="font-semibold text-dark-blue font-['Playfair_Display']">Medical & Dental History</h3>
                    <span class="text-xs text-gray-400 ml-auto">
                        <i class="fas fa-pencil-alt text-teal mr-1"></i> Update if needed
                    </span>
                </div>

                <?php
                function mh_val($field, $default = '') {
                    global $medical_history;
                    return isset($medical_history[$field]) ? htmlspecialchars($medical_history[$field]) : $default;
                }
                function mh_checked($field, $value) {
                    global $medical_history;
                    if (!isset($medical_history[$field])) return '';
                    return $medical_history[$field] == $value ? 'checked' : '';
                }
                function mh_selected($field, $value) {
                    global $medical_history;
                    if (!isset($medical_history[$field])) return '';
                    return $medical_history[$field] == $value ? 'selected' : '';
                }
                ?>

                <div class="mh-question">
                    <div class="q-text">1. Are you presently under a physician's care?</div>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="underCare" value="yes" <?= mh_checked('under_physician_care', 'yes') ?> onchange="toggleFollowup('underCare','underCareFollowup')"> Yes</label>
                        <label class="radio-option"><input type="radio" name="underCare" value="no" <?= mh_checked('under_physician_care', 'no') ?> onchange="toggleFollowup('underCare','underCareFollowup')"> No</label>
                    </div>
                    <div class="mh-followup <?= (isset($medical_history['under_physician_care']) && $medical_history['under_physician_care'] === 'yes') ? 'show' : '' ?>" id="underCareFollowup">
                        <input type="text" class="f-input" id="underCareDoctor" placeholder="If yes, who / Dr.?" value="<?= mh_val('physician_name') ?>">
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">2. Are you pregnant?</div>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="pregnant" value="yes" <?= mh_checked('pregnant', 'yes') ?>> Yes</label>
                        <label class="radio-option"><input type="radio" name="pregnant" value="no" <?= mh_checked('pregnant', 'no') ?>> No</label>
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">3. Are you taking any medicine at present?</div>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="takingMedicine" value="yes" <?= mh_checked('taking_medicine', 'yes') ?> onchange="toggleFollowup('takingMedicine','takingMedicineFollowup')"> Yes</label>
                        <label class="radio-option"><input type="radio" name="takingMedicine" value="no" <?= mh_checked('taking_medicine', 'no') ?> onchange="toggleFollowup('takingMedicine','takingMedicineFollowup')"> No</label>
                    </div>
                    <div class="mh-followup <?= (isset($medical_history['taking_medicine']) && $medical_history['taking_medicine'] === 'yes') ? 'show' : '' ?>" id="takingMedicineFollowup">
                        <input type="text" class="f-input" id="medicineDetails" placeholder="If yes, what?" value="<?= mh_val('medicine_details') ?>">
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">4. Have you ever had any of the following?</div>
                    <div class="checkbox-grid">
                        <?php
                        $conditions = ['High Blood Pressure', 'Low Blood Pressure', 'Asthma', 'Heart Disease', 'Diabetes', 'Tuberculosis', 'Thyroid Problem', 'Bleeding Problems', 'AIDS or HIV Infection', 'Hepatitis'];
                        $saved_conditions = isset($medical_history['conditions']) ? explode(',', $medical_history['conditions']) : [];
                        if (!is_array($saved_conditions)) $saved_conditions = [];
                        foreach ($conditions as $condition):
                        ?>
                        <label class="checkbox-row">
                            <input type="checkbox" class="condition-checkbox" value="<?= $condition ?>" <?= in_array($condition, $saved_conditions) ? 'checked' : '' ?>>
                            <?= $condition ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="checkbox-row" style="grid-column: span 2;">
                            <input type="checkbox" id="otherConditionCheck" <?= !empty(mh_val('other_condition_details')) ? 'checked' : '' ?> onchange="toggleFollowup('otherConditionCheck','otherConditionFollowup', true)">
                            Others
                        </label>
                    </div>
                    <div class="mh-followup <?= !empty(mh_val('other_condition_details')) ? 'show' : '' ?>" id="otherConditionFollowup">
                        <input type="text" class="f-input" id="otherConditionDetails" placeholder="Please specify" value="<?= mh_val('other_condition_details') ?>">
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">5. Have you ever had an allergic reaction to any of the following?</div>
                    <div class="form-grid-2">
                        <div>
                            <label class="f-label" style="font-size:11px;">a. Local Anesthesia</label>
                            <div class="radio-group">
                                <label class="radio-option"><input type="radio" name="allergyLocalAnesthesia" value="yes" <?= mh_checked('allergic_local_anesthesia', 'yes') ?>> Yes</label>
                                <label class="radio-option"><input type="radio" name="allergyLocalAnesthesia" value="no" <?= mh_checked('allergic_local_anesthesia', 'no') ?>> No</label>
                            </div>
                        </div>
                        <div>
                            <label class="f-label" style="font-size:11px;">b. Antibiotics</label>
                            <div class="radio-group">
                                <label class="radio-option"><input type="radio" name="allergyAntibiotics" value="yes" <?= mh_checked('allergic_antibiotics', 'yes') ?>> Yes</label>
                                <label class="radio-option"><input type="radio" name="allergyAntibiotics" value="no" <?= mh_checked('allergic_antibiotics', 'no') ?>> No</label>
                            </div>
                        </div>
                        <div>
                            <label class="f-label" style="font-size:11px;">c. Pain Killer</label>
                            <div class="radio-group">
                                <label class="radio-option"><input type="radio" name="allergyPainKiller" value="yes" <?= mh_checked('allergic_pain_killer', 'yes') ?>> Yes</label>
                                <label class="radio-option"><input type="radio" name="allergyPainKiller" value="no" <?= mh_checked('allergic_pain_killer', 'no') ?>> No</label>
                            </div>
                        </div>
                        <div>
                            <label class="f-label" style="font-size:11px;">d. Others</label>
                            <div class="radio-group">
                                <label class="radio-option"><input type="radio" name="allergyOther" value="yes" <?= mh_checked('allergic_other', 'yes') ?> onchange="toggleFollowup('allergyOther','allergyOtherFollowup')"> Yes</label>
                                <label class="radio-option"><input type="radio" name="allergyOther" value="no" <?= mh_checked('allergic_other', 'no') ?> onchange="toggleFollowup('allergyOther','allergyOtherFollowup')"> No</label>
                            </div>
                        </div>
                    </div>
                    <div class="mh-followup <?= (isset($medical_history['allergic_other']) && $medical_history['allergic_other'] === 'yes') ? 'show' : '' ?>" id="allergyOtherFollowup">
                        <input type="text" class="f-input" id="allergyOtherDetails" placeholder="Please specify" value="<?= mh_val('allergic_other_details') ?>">
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">6. Have you ever been hospitalized?</div>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="hospitalized" value="yes" <?= mh_checked('hospitalized', 'yes') ?> onchange="toggleFollowup('hospitalized','hospitalizedFollowup')"> Yes</label>
                        <label class="radio-option"><input type="radio" name="hospitalized" value="no" <?= mh_checked('hospitalized', 'no') ?> onchange="toggleFollowup('hospitalized','hospitalizedFollowup')"> No</label>
                    </div>
                    <div class="mh-followup <?= (isset($medical_history['hospitalized']) && $medical_history['hospitalized'] === 'yes') ? 'show' : '' ?>" id="hospitalizedFollowup">
                        <div class="form-grid-2">
                            <input type="text" class="f-input" id="hospitalizedWhen" placeholder="If yes, when?" value="<?= mh_val('hospitalized_when') ?>">
                            <input type="text" class="f-input" id="hospitalizedWhy" placeholder="Why?" value="<?= mh_val('hospitalized_why') ?>">
                        </div>
                    </div>
                </div>

                <div class="mh-question">
                    <div class="q-text">7. Have you worn any type of denture?</div>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="wornDenture" value="yes" <?= mh_checked('worn_denture', 'yes') ?>> Yes</label>
                        <label class="radio-option"><input type="radio" name="wornDenture" value="no" <?= mh_checked('worn_denture', 'no') ?>> No</label>
                    </div>
                </div>

                <div class="mh-question" style="margin-bottom:0;">
                    <div class="q-text">8. When was your last dental visit?</div>
                    <div class="form-grid-2">
                        <input type="text" class="f-input" id="lastDentalVisit" placeholder="e.g. 6 months ago" value="<?= mh_val('last_dental_visit') ?>">
                        <input type="text" class="f-input" id="lastDentalVisitWhy" placeholder="Why?" value="<?= mh_val('last_dental_visit_reason') ?>">
                    </div>
                </div>

                <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    This information helps the clinic provide safe and appropriate care for your visit.
                </div>
            </div>

            <div class="actions-row max-w-3xl mx-auto">
                <button class="btn-ghost" onclick="goToStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                <button class="btn-primary" onclick="validateStep3()">Review Appointment <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- ============ STEP 4: REVIEW ============ -->
        <div class="step-view" id="stepView4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-5xl mx-auto">
                <div>
                    <h3 class="font-semibold text-dark-blue mb-4 flex items-center gap-2 font-['Playfair_Display']">
                        <i class="fas fa-clipboard-check text-teal"></i> Appointment Details
                    </h3>

                    <div class="review-block">
                        <div class="rb-title">Schedule <span class="rb-edit text-teal font-bold cursor-pointer text-xs" onclick="goToStep(1)">Edit</span></div>
                        <div class="review-row"><span class="l">Date</span><span class="v" id="rvDate">—</span></div>
                        <div class="review-row"><span class="l">Time</span><span class="v" id="rvTime">—</span></div>
                        <div style="margin-top:6px;" id="rvServices"></div>
                    </div>

                    <div class="review-block">
                        <div class="rb-title">Your Information <span class="rb-edit text-teal font-bold cursor-pointer text-xs" onclick="goToStep(2)">Edit</span></div>
                        <div class="review-row"><span class="l">Full Name</span><span class="v" id="rvName">—</span></div>
                        <div class="review-row"><span class="l">Age</span><span class="v" id="rvAge">—</span></div>
                        <div class="review-row"><span class="l">Gender</span><span class="v" id="rvGender">—</span></div>
                        <div class="review-row"><span class="l">Occupation</span><span class="v" id="rvOccupation">—</span></div>
                        <div class="review-row"><span class="l">Contact Number</span><span class="v" id="rvPhone">—</span></div>
                        <div class="review-row"><span class="l">Email</span><span class="v" id="rvEmail">—</span></div>
                        <div class="review-row"><span class="l">Region</span><span class="v" id="rvRegion">—</span></div>
                        <div class="review-row"><span class="l">Province</span><span class="v" id="rvProvince">—</span></div>
                        <div class="review-row"><span class="l">City / Municipality</span><span class="v" id="rvCity">—</span></div>
                        <div class="review-row"><span class="l">Barangay</span><span class="v" id="rvBarangay">—</span></div>
                        <div class="review-row"><span class="l">Street</span><span class="v" id="rvStreet">—</span></div>
                    </div>

                    <div class="review-block" id="rvNotesBlock" style="display:none;">
                        <div class="rb-title">Additional Notes <span class="rb-edit text-teal font-bold cursor-pointer text-xs" onclick="goToStep(2)">Edit</span></div>
                        <div class="review-row"><span class="v" id="rvNotes" style="text-align:left;">—</span></div>
                    </div>
                </div>

                <div>
                    <div class="review-block">
                        <div class="rb-title">Medical History <span class="rb-edit text-teal font-bold cursor-pointer text-xs" onclick="goToStep(3)">Edit</span></div>
                        <div class="review-row"><span class="l">Under Physician's Care</span><span class="v" id="rvUnderCare">—</span></div>
                        <div class="review-row"><span class="l">Pregnant</span><span class="v" id="rvPregnant">—</span></div>
                        <div class="review-row"><span class="l">Taking Medicine</span><span class="v" id="rvTakingMedicine">—</span></div>
                        <div class="review-row"><span class="l">Conditions</span><span class="v" id="rvConditions">—</span></div>
                        <div class="review-row"><span class="l">Allergies</span><span class="v" id="rvAllergies">—</span></div>
                        <div class="review-row"><span class="l">Hospitalized</span><span class="v" id="rvHospitalized">—</span></div>
                        <div class="review-row"><span class="l">Worn Denture</span><span class="v" id="rvDenture">—</span></div>
                        <div class="review-row"><span class="l">Last Dental Visit</span><span class="v" id="rvLastDental">—</span></div>
                    </div>

                    <div class="dashboard-card">
                        <h3 class="font-semibold text-dark-blue mb-4 flex items-center gap-2 font-['Playfair_Display']">
                            <i class="fas fa-check-circle text-teal"></i> Ready to Submit?
                        </h3>

                        <div class="space-y-3 text-sm">
                            <div class="p-3 bg-teal-light rounded-lg">
                                <p class="text-xs text-teal-dark font-semibold">📋 Summary</p>
                                <ul class="mt-2 space-y-1 text-gray-700">
                                    <li class="flex justify-between"><span class="text-gray-500">Service(s):</span> <span id="rvSummaryServices" class="font-medium">—</span></li>
                                    <li class="flex justify-between"><span class="text-gray-500">Date:</span> <span id="rvSummaryDate" class="font-medium">—</span></li>
                                    <li class="flex justify-between"><span class="text-gray-500">Time:</span> <span id="rvSummaryTime" class="font-medium">—</span></li>
                                    <li class="flex justify-between"><span class="text-gray-500">Patient:</span> <span id="rvSummaryName" class="font-medium">—</span></li>
                                </ul>
                            </div>

                            <div class="p-3 bg-amber-50 rounded-lg text-xs text-amber-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                The clinic will review your request before confirming the appointment.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="actions-row max-w-5xl mx-auto">
                <button class="btn-ghost" onclick="goToStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
                <button class="btn-primary" id="confirmBtn" onclick="confirmAppointment()">
                    <i class="fas fa-check-circle"></i> Confirm Appointment
                </button>
            </div>
        </div>

        <!-- ============ STEP 5: CONFIRMED ============ -->
        <div class="step-view" id="stepView5">
            <div class="confirmed-wrap">
                <div class="confirmed-icon"><i class="fas fa-check"></i></div>
                <h2 style="font-size:22px; font-weight:800;">Appointment Request Submitted!</h2>
                <p style="font-size:13.5px; color:var(--slate); margin-top:6px;">
                    Your appointment request has been sent to the clinic for review. You'll receive a confirmation once it's approved.
                </p>
                <div class="tracking-box">
                    <p style="font-size:11px; color:var(--slate); margin-bottom:4px; text-transform:uppercase; letter-spacing:.5px; font-weight:700;">Tracking ID</p>
                    <div class="tracking-id" id="confirmedTrackingId">#000000</div>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:1.25rem;">
                    <a class="btn-primary" id="trackLink" href="#" target="_blank" style="justify-content:center; text-decoration:none;">
                        <i class="fas fa-clipboard-list"></i> Track Your Appointment
                    </a>
                    <a class="btn-outline" href="patient-dashboard.php" style="justify-content:center; text-decoration:none;">
                        <i class="fas fa-home"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>
                <i class="fas fa-tooth text-teal/50 mr-1"></i>
                Wansi Calacat Dental Clinic · Patient Portal
            </p>
        </div>

    </div>
</div>

<script>
// ====================== SERVER TIME (FIX FOR TIMEZONE BUG) ======================
// Use PHP's raw time values so "today" and "now" are based on the clinic's timezone.
const SERVER_YEAR        = <?= (int)date('Y') ?>;
const SERVER_MONTH       = <?= (int)date('n') ?> - 1; // JS months are 0-indexed
const SERVER_DAY         = <?= (int)date('j') ?>;
const SERVER_HOUR        = <?= (int)date('G') ?>;
const SERVER_MINUTE      = <?= (int)date('i') ?>;
const SERVER_TODAY_STR   = "<?= date('Y-m-d') ?>";
const SERVER_NOW_MINUTES = SERVER_HOUR * 60 + SERVER_MINUTE;

// ====================== STATE ======================
let currentStep = 1;
let currentMonth = SERVER_MONTH;
let currentYear  = SERVER_YEAR;

// Bounds: current month → current month + 2
const minMonthIndex = SERVER_YEAR * 12 + SERVER_MONTH;
const maxMonthIndex = minMonthIndex + 2;

let selectedDate = null;
let selectedTime = null;
let timeFormat = '12h';
let selectedServiceIds = [];
let monthStatuses = {};

// Cache for slot re-rendering (used by setTimeFormat)
let renderedSlots = [];
let renderedBooked = [];
let renderedHeader = '';

// ====================== STEP NAVIGATION ======================
function goToStep(step) {
    document.querySelectorAll('.step-view').forEach(v => v.classList.remove('active'));
    document.getElementById('stepView' + step).classList.add('active');

    document.querySelectorAll('.step-node').forEach(node => {
        const n = parseInt(node.dataset.step);
        node.classList.remove('active', 'done');
        if (n < step) node.classList.add('done');
        if (n === step) node.classList.add('active');
    });

    currentStep = step;
    if (step === 4) populateReview();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ====================== NAME HELPERS ======================
function titleCase(str) {
    const lowerWords = ['de', 'del', 'dela', 'la', 'las', 'los', 'van', 'von', 'da', 'dos'];
    return (str || '')
        .toLowerCase()
        .split(' ')
        .map((word, i) => {
            if (i > 0 && lowerWords.includes(word)) return word;
            return word.charAt(0).toUpperCase() + word.slice(1);
        })
        .join(' ');
}

function getFullName() {
    const first  = titleCase(document.getElementById('firstName').value.trim());
    const middle = titleCase(document.getElementById('middleName').value.trim());
    const last   = titleCase(document.getElementById('lastName').value.trim());
    return [first, middle, last].filter(Boolean).join(' ');
}

// ====================== PSGC ADDRESS CASCADE ======================
const PSGC_BASE = 'https://psgc.gitlab.io/api';

function selectedText(selectId) {
    const el = document.getElementById(selectId);
    if (!el || el.selectedIndex < 0 || !el.value) return '';
    return el.options[el.selectedIndex].text;
}

async function loadRegions() {
    try {
        const res = await fetch(PSGC_BASE + '/regions/');
        const data = await res.json();
        const sel = document.getElementById('regionSelect');
        data.sort((a,b) => a.name.localeCompare(b.name)).forEach(r => {
            const opt = document.createElement('option');
            opt.value = r.code; opt.textContent = r.name;
            sel.appendChild(opt);
        });
    } catch (e) {
        console.error('Failed to load regions', e);
    }
}

async function loadProvinces() {
    const regionCode = document.getElementById('regionSelect').value;
    const provinceSel = document.getElementById('provinceSelect');
    const citySel = document.getElementById('citySelect');
    const brgySel = document.getElementById('barangaySelect');

    provinceSel.innerHTML = '<option value="">Select province</option>';
    citySel.innerHTML = '<option value="">Select city / municipality</option>';
    brgySel.innerHTML = '<option value="">Select barangay</option>';
    citySel.disabled = true; brgySel.disabled = true;

    if (!regionCode) { provinceSel.disabled = true; return; }

    try {
        const res = await fetch(`${PSGC_BASE}/regions/${regionCode}/provinces/`);
        const data = await res.json();
        provinceSel.disabled = false;
        data.sort((a,b) => a.name.localeCompare(b.name)).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.code; opt.textContent = p.name;
            provinceSel.appendChild(opt);
        });
        if (data.length === 0) {
            provinceSel.innerHTML = '<option value="">N/A</option>';
            provinceSel.disabled = true;
            const cityRes = await fetch(`${PSGC_BASE}/regions/${regionCode}/cities-municipalities/`);
            const cityData = await cityRes.json();
            citySel.disabled = false;
            cityData.sort((a,b) => a.name.localeCompare(b.name)).forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.code; opt.textContent = c.name;
                citySel.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Failed to load provinces', e);
    }
}

async function loadCities() {
    const provinceCode = document.getElementById('provinceSelect').value;
    const citySel = document.getElementById('citySelect');
    const brgySel = document.getElementById('barangaySelect');

    citySel.innerHTML = '<option value="">Select city / municipality</option>';
    brgySel.innerHTML = '<option value="">Select barangay</option>';
    brgySel.disabled = true;

    if (!provinceCode) { citySel.disabled = true; return; }

    try {
        const res = await fetch(`${PSGC_BASE}/provinces/${provinceCode}/cities-municipalities/`);
        const data = await res.json();
        citySel.disabled = false;
        data.sort((a,b) => a.name.localeCompare(b.name)).forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.code; opt.textContent = c.name;
            citySel.appendChild(opt);
        });
    } catch (e) {
        console.error('Failed to load cities', e);
    }
}

async function loadBarangays() {
    const cityCode = document.getElementById('citySelect').value;
    const brgySel = document.getElementById('barangaySelect');
    brgySel.innerHTML = '<option value="">Select barangay</option>';

    if (!cityCode) { brgySel.disabled = true; return; }

    try {
        const res = await fetch(`${PSGC_BASE}/cities-municipalities/${cityCode}/barangays/`);
        const data = await res.json();
        brgySel.disabled = false;
        data.sort((a,b) => a.name.localeCompare(b.name)).forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.code; opt.textContent = b.name;
            brgySel.appendChild(opt);
        });
    } catch (e) {
        console.error('Failed to load barangays', e);
    }
}

// ====================== TOGGLE FOLLOWUP ======================
function toggleFollowup(triggerIdOrName, followupId, isCheckbox = false) {
    let show;
    if (isCheckbox) {
        show = document.getElementById(triggerIdOrName).checked;
    } else {
        const checked = document.querySelector(`input[name="${triggerIdOrName}"]:checked`);
        show = !!checked && checked.value === 'yes';
    }
    document.getElementById(followupId).classList.toggle('show', show);
}

function radioVal(name) {
    const el = document.querySelector(`input[name="${name}"]:checked`);
    return el ? el.value : '';
}

function fieldVal(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : '';
}

// ====================== PHONE: LOCKED +63 PREFIX ======================
const PHONE_PREFIX = '+63';

function getFullPhone() {
    const digits = document.getElementById('contactNumber').value.replace(/\D/g, '');
    if (!digits) return '';
    return PHONE_PREFIX + digits;
}

function validatePhoneLocal() {
    const digits = document.getElementById('contactNumber').value.replace(/\D/g, '');

    if (!digits) {
        return { valid: false, message: 'Please enter the 10 digits after +63.', normalized: '' };
    }
    if (digits.length !== 10) {
        return { valid: false, message: `Enter exactly 10 digits after +63 (you typed ${digits.length}).`, normalized: '' };
    }
    if (!digits.startsWith('9')) {
        return { valid: false, message: 'The number after +63 must start with 9.', normalized: '' };
    }
    return { valid: true, message: '✓ Valid number', normalized: PHONE_PREFIX + digits };
}

function updatePhoneFeedback() {
    const wrap = document.getElementById('phoneWrap');
    const hint = document.getElementById('phoneHint');
    const input = document.getElementById('contactNumber');
    if (!wrap || !hint || !input) return;

    wrap.classList.remove('is-valid', 'is-invalid');

    if (!input.value) {
        hint.textContent = 'Enter the 10 digits after +63 (starting with 9).';
        hint.style.color = '#94a3b8';
        return;
    }
    const r = validatePhoneLocal();
    hint.textContent = r.message;
    hint.style.color = r.valid ? '#0d9e8c' : '#e53e3e';
    wrap.classList.add(r.valid ? 'is-valid' : 'is-invalid');
}

// ====================== STEP 2 VALIDATION ======================
function validateStep2() {
    const firstName = document.getElementById('firstName').value.trim();
    const lastName  = document.getElementById('lastName').value.trim();
    const age = document.getElementById('age').value.trim();

    if (!firstName) {
        Swal.fire({ icon: 'warning', title: 'First Name Required', text: 'Please enter your first name.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('firstName').focus();
        return;
    }
    if (!lastName) {
        Swal.fire({ icon: 'warning', title: 'Last Name Required', text: 'Please enter your last name.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('lastName').focus();
        return;
    }
    if (!age) {
        Swal.fire({ icon: 'warning', title: 'Age Required', text: 'Please enter your age.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('age').focus();
        return;
    }
    const ageNum = parseInt(age, 10);
    if (isNaN(ageNum) || ageNum <= 0 || ageNum > 120) {
        Swal.fire({ icon: 'warning', title: 'Invalid Age', text: 'Please enter a valid age between 1 and 120.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('age').focus();
        return;
    }
    const phoneCheck = validatePhoneLocal();
    if (!phoneCheck.valid) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Contact Number',
            text: phoneCheck.message.replace('✓ ', ''),
            confirmButtonColor: '#0d9e8c'
        });
        document.getElementById('contactNumber').focus();
        return;
    }
    goToStep(3);
}

// ====================== STEP 3 VALIDATION ======================
function validateStep3() {
    const requiredRadios = ['underCare', 'pregnant', 'takingMedicine', 'hospitalized', 'wornDenture'];
    let missing = [];
    for (const name of requiredRadios) {
        if (!radioVal(name)) {
            missing.push(name.replace(/([A-Z])/g, ' $1').trim());
        }
    }
    if (missing.length > 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Please Complete Medical History',
            text: 'Please answer all Yes/No questions before continuing.',
            confirmButtonColor: '#0d9e8c'
        });
        return;
    }
    goToStep(4);
}

// ====================== SERVICE SELECTOR ======================
function toggleServicePanel() {
    document.getElementById('servicePanel').classList.toggle('open');
    document.getElementById('serviceChevron').classList.toggle('fa-chevron-up');
    document.getElementById('serviceChevron').classList.toggle('fa-chevron-down');
}

function onServiceToggle() {
    selectedServiceIds = Array.from(document.querySelectorAll('#servicePanel input[type="checkbox"]:checked'))
        .map(cb => parseInt(cb.value));
    document.getElementById('serviceCountBadge').textContent = selectedServiceIds.length;
    document.getElementById('serviceCountLabel').textContent =
        selectedServiceIds.length === 1 ? 'service selected' : 'services selected';
    updateContinueState();
}

function getSelectedServiceObjects() {
    const allServices = <?= json_encode($services) ?>;
    return allServices.filter(s => selectedServiceIds.includes(parseInt(s.id)));
}

// ====================== CALENDAR ======================
function generateCalendar(dateToSelect = null) {
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calendarMonth').textContent = monthNames[currentMonth] + ' ' + currentYear;

    // Enable/disable nav buttons at range edges
    const viewingIndex = currentYear * 12 + currentMonth;
    const prevBtn = document.querySelector('.cal-nav[onclick*="changeMonth(-1)"]');
    const nextBtn = document.querySelector('.cal-nav[onclick*="changeMonth(1)"]');

    if (prevBtn) {
        const atMin = viewingIndex <= minMonthIndex;
        prevBtn.disabled = atMin;
        prevBtn.style.opacity = atMin ? '0.35' : '1';
        prevBtn.style.cursor = atMin ? 'not-allowed' : 'pointer';
    }
    if (nextBtn) {
        const atMax = viewingIndex >= maxMonthIndex;
        nextBtn.disabled = atMax;
        nextBtn.style.opacity = atMax ? '0.35' : '1';
        nextBtn.style.cursor = atMax ? 'not-allowed' : 'pointer';
    }

    fetch(`../../api/fetch_monthly_availability.php?year=${currentYear}&month=${currentMonth + 1}`)
        .then(r => r.json())
        .then(data => {
            monthStatuses = (data && data.days) ? data.days : {};
            renderCalendarGrid();

            // FIX: After monthStatuses is populated, select the requested date.
            if (dateToSelect) {
                selectDate(dateToSelect);
            }
        })
        .catch(err => {
            console.error('Failed to load availability:', err);
            monthStatuses = {};
            renderCalendarGrid();
        });
}

function renderCalendarGrid() {
    const grid = document.getElementById('calendarGrid');
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    const mondayFirst = (firstDay + 6) % 7;
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

    // USE SERVER TIME (string passed from PHP)
    const todayStr = SERVER_TODAY_STR;

    let html = '';
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(d => html += `<div class="day-name">${d}</div>`);
    for (let i = 0; i < mondayFirst; i++) html += `<div class="day-cell other-month"></div>`;

    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = currentYear + '-' +
            String(currentMonth + 1).padStart(2, '0') + '-' +
            String(day).padStart(2, '0');

        const info = monthStatuses[dateStr] || { status: 'unavailable', start: null, end: null };
        const isPast = dateStr < todayStr;
        const isSelected = selectedDate === dateStr;

        const openStatuses = ['available', 'half_day'];
        const clickable = !isPast && openStatuses.includes(info.status);

        let cls = 'day-cell';
        if (info.status === 'available')  cls += ' available';
        if (info.status === 'half_day')   cls += ' half-day';
        if (!clickable)                   cls += ' disabled';
        if (isSelected)                   cls += ' selected';

        const onclick = clickable ? `onclick="selectDate('${dateStr}')"` : '';

        let title = '';
        if (isPast)                          title = ' title="Past date"';
        else if (info.status === 'available') title = ' title="Available"';
        else if (info.status === 'half_day')  title = ` title="Half day ${info.start || ''} – ${info.end || ''}"`;
        else                                 title = ' title="Unavailable"';

        let statusIcon = '';
        if (isPast) {
            statusIcon = `<span class="day-icon"><i class="fas fa-ban"></i></span>`;
        } else if (info.status === 'available') {
            statusIcon = `<span class="day-icon"><i class="fas fa-check"></i></span>`;
        } else if (info.status === 'half_day') {
            statusIcon = `<span class="day-icon"><i class="fas fa-adjust"></i></span>`;
        } else {
            statusIcon = `<span class="day-icon"><i class="fas fa-ban"></i></span>`;
        }

        html += `
            <div class="${cls}" ${onclick}${title}>
                <span class="day-num">${day}</span>
                ${statusIcon}
            </div>
        `;
    }
    grid.innerHTML = html;
}

function changeMonth(delta) {
    const currentIndex = currentYear * 12 + currentMonth;
    const proposedIndex = currentIndex + delta;

    if (proposedIndex < minMonthIndex || proposedIndex > maxMonthIndex) {
        return;
    }

    currentYear = Math.floor(proposedIndex / 12);
    currentMonth = proposedIndex % 12;

    selectedDate = null;
    selectedTime = null;
    renderedSlots = [];
    renderedBooked = [];
    renderedHeader = '';

    document.getElementById('timeSlotsContainer').innerHTML =
        '<div class="empty-hint">Select a date to view available slots</div>';

    generateCalendar();
    updateStatusBar();
    updateContinueState();
}

function selectDate(dateStr) {
    if (selectedDate !== dateStr) {
        selectedTime = null;
    }
    selectedDate = dateStr;
    
    // Re-render the grid to update selected highlight (no re-fetch)
    renderCalendarGrid();
    
    // Generate time slots (monthStatuses is guaranteed populated here)
    generateTimeSlots(dateStr);
    updateStatusBar();
    updateContinueState();
}

// ====================== TIME SLOTS ======================
function setTimeFormat(fmt) {
    timeFormat = fmt;
    document.getElementById('fmt12').classList.toggle('active', fmt === '12h');
    document.getElementById('fmt24').classList.toggle('active', fmt === '24h');

    if (selectedDate && renderedSlots.length) {
        renderTimeSlots(renderedSlots, renderedBooked, renderedHeader);
    } else if (selectedDate) {
        generateTimeSlots(selectedDate);
    }
}

function formatTime(hour, min) {
    if (timeFormat === '24h') {
        return String(hour).padStart(2,'0') + ':' + String(min).padStart(2,'0');
    }
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    const ampm = hour >= 12 ? 'PM' : 'AM';
    return displayHour + ':' + String(min).padStart(2,'0') + ' ' + ampm;
}

function formatTimeRange(hour, min) {
    let endH = hour;
    let endM = min + 30;
    if (endM >= 60) { endM -= 60; endH++; }
    if (endH >= 24) endH -= 24;

    if (timeFormat === '24h') {
        return String(hour).padStart(2,'0') + ':' + String(min).padStart(2,'0') +
               ' - ' +
               String(endH).padStart(2,'0') + ':' + String(endM).padStart(2,'0');
    }
    return formatTime(hour, min) + ' - ' + formatTime(endH, endM);
}

function generateTimeSlots(dateStr) {
    // Wipe previous date's cache
    renderedSlots = [];
    renderedBooked = [];
    renderedHeader = '';
    selectedTime = null;

    const container = document.getElementById('timeSlotsContainer');
    const info = monthStatuses[dateStr];

    if (!info) {
        container.innerHTML = '<div class="empty-hint">Loading availability…</div>';
        return;
    }

    if (info.status === 'unavailable' || info.status === 'past') {
        container.innerHTML = '<div class="empty-hint">This date is unavailable.</div>';
        return;
    }

    let winStart = info.start;
    let winEnd   = info.end;
    if (info.status === 'half_day' && (!winStart || !winEnd)) {
        winStart = '08:00';
        winEnd   = '12:00';
    }

    if (!winStart || !winEnd) {
        container.innerHTML = '<div class="empty-hint">No working hours set for this day.</div>';
        return;
    }

    const [startH, startM] = winStart.split(':').map(Number);
    const [endH,   endM]   = winEnd.split(':').map(Number);

    // USE SERVER TIME (integers passed from PHP)
    const todayStr = SERVER_TODAY_STR;
    const isToday = (dateStr === todayStr);
    const nowMinutes = SERVER_NOW_MINUTES;

    const slots = [];
    let h = startH, m = startM;
    while (h < endH || (h === endH && m < endM)) {
        const slotMinutes = h * 60 + m;
        const isPastSlot = isToday && (slotMinutes <= nowMinutes);

        if (!isPastSlot) {
            slots.push({
                value: String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':00',
                hour: h,
                min: m
            });
        }

        m += 30;
        if (m >= 60) { m = 0; h++; }
    }

    if (slots.length === 0) {
        container.innerHTML = '<div class="empty-hint">No slots available for this day.</div>';
        return;
    }

    // Half-day banner
    let headerHtml = '';
    if (info.status === 'half_day') {
        const [sh, sm] = winStart.split(':').map(Number);
        const [eh, em] = winEnd.split(':').map(Number);
        headerHtml = `<div class="slots-window half">
            <i class="fas fa-adjust"></i>
            Half day &middot; ${formatTime(sh, sm)} – ${formatTime(eh, em)}
        </div>`;
    }

    // Render immediately, then mark booked slots when server responds
    renderTimeSlots(slots, [], headerHtml);

    fetch('../../api/fetch_reserved_time_slots.php?date=' + encodeURIComponent(dateStr), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (selectedDate !== dateStr) return; // user moved on
            renderTimeSlots(slots, data.booked_slots || [], headerHtml);
        })
        .catch(err => console.error('Failed to load booked slots', err));
}

function renderTimeSlots(slots, bookedSlots, headerHtml = '') {
    renderedSlots = slots;
    renderedBooked = bookedSlots;
    renderedHeader = headerHtml;

    let html = headerHtml + '<div class="time-slots-list">';
    slots.forEach(slot => {
        const isBooked = bookedSlots.includes(slot.value);
        const isSelected = selectedTime === slot.value;
        let cls = 'time-slot-btn';
        if (isBooked) cls += ' booked';
        if (isSelected) cls += ' selected';

        html += `<button type="button" class="${cls}" data-time="${slot.value}" ${isBooked ? 'disabled' : ''}>${formatTimeRange(slot.hour, slot.min)}</button>`;
    });
    html += '</div>';

    const container = document.getElementById('timeSlotsContainer');
    container.innerHTML = html;

    container.querySelectorAll('.time-slot-btn:not(.booked)').forEach(btn => {
        btn.addEventListener('click', () => selectTime(btn.dataset.time));
    });
}

function selectTime(timeStr) {
    selectedTime = timeStr;

    document.querySelectorAll('#timeSlotsContainer .time-slot-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.time === timeStr);
    });

    updateStatusBar();
    updateContinueState();
}

// ====================== STATUS BAR ======================
function updateStatusBar() {
    const main = document.getElementById('statusMain');
    const hint = document.getElementById('statusHint');
    if (!selectedDate) {
        main.textContent = 'No date selected';
        hint.textContent = 'Select a service, then tap an available date';
        return;
    }
    const dateDisplay = new Date(selectedDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    if (!selectedTime) {
        main.textContent = dateDisplay;
        hint.textContent = 'Now pick a time slot';
    } else {
        const [h, m] = selectedTime.split(':').map(Number);
        main.textContent = dateDisplay + ' — ' + formatTimeRange(h, m);
        hint.textContent = 'Ready to review your appointment';
    }
}

function updateContinueState() {
    document.getElementById('toStep2Btn').disabled = !(selectedDate && selectedTime && selectedServiceIds.length > 0);
}

// ====================== REVIEW ======================
function populateReview() {
    const fullName = getFullName() || '—';
    const age = document.getElementById('age').value.trim() || '—';
    const gender = document.getElementById('gender').value || '—';
    const occupation = document.getElementById('occupation').value.trim() || '—';
    const phone = getFullPhone() || '—';
    const email = document.getElementById('contactEmail').value.trim() || '—';
    const streetAddress = document.getElementById('streetAddress').value.trim() || '—';
    const notes = document.getElementById('patientNotes').value.trim();

    document.getElementById('rvDate').textContent = selectedDate
        ? new Date(selectedDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
        : '—';
    document.getElementById('rvTime').textContent = selectedTime
        ? (() => { const [h,m] = selectedTime.split(':').map(Number); return formatTimeRange(h,m); })()
        : '—';

    const svcHtml = getSelectedServiceObjects().map(s => `<span class="service-chip">${s.title}</span>`).join('');
    document.getElementById('rvServices').innerHTML = svcHtml || '<span class="text-sm text-gray-400">No services selected</span>';

    document.getElementById('rvName').textContent = fullName;
    document.getElementById('rvAge').textContent = age;
    document.getElementById('rvGender').textContent = gender;
    document.getElementById('rvOccupation').textContent = occupation;
    document.getElementById('rvPhone').textContent = phone;
    document.getElementById('rvEmail').textContent = email;
    document.getElementById('rvRegion').textContent = selectedText('regionSelect') || '—';
    document.getElementById('rvProvince').textContent = selectedText('provinceSelect') || '—';
    document.getElementById('rvCity').textContent = selectedText('citySelect') || '—';
    document.getElementById('rvBarangay').textContent = selectedText('barangaySelect') || '—';
    document.getElementById('rvStreet').textContent = streetAddress;

    document.getElementById('rvNotesBlock').style.display = notes ? 'block' : 'none';
    document.getElementById('rvNotes').textContent = notes || '—';

    document.getElementById('rvUnderCare').textContent = radioVal('underCare') || '—';
    document.getElementById('rvPregnant').textContent = radioVal('pregnant') || '—';
    document.getElementById('rvTakingMedicine').textContent = radioVal('takingMedicine') || '—';

    const conditions = Array.from(document.querySelectorAll('.condition-checkbox:checked')).map(cb => cb.value);
    const otherCondition = document.getElementById('otherConditionDetails').value.trim();
    document.getElementById('rvConditions').textContent = conditions.length
        ? conditions.join(', ') + (otherCondition ? `, ${otherCondition}` : '')
        : 'None reported';

    const allergies = [];
    if (radioVal('allergyLocalAnesthesia') === 'yes') allergies.push('Local Anesthesia');
    if (radioVal('allergyAntibiotics') === 'yes') allergies.push('Antibiotics');
    if (radioVal('allergyPainKiller') === 'yes') allergies.push('Pain Killer');
    if (radioVal('allergyOther') === 'yes') {
        const otherAllergy = document.getElementById('allergyOtherDetails').value.trim();
        allergies.push(otherAllergy || 'Other');
    }
    document.getElementById('rvAllergies').textContent = allergies.length ? allergies.join(', ') : 'None reported';

    const hospitalized = radioVal('hospitalized');
    const hospWhen = document.getElementById('hospitalizedWhen').value.trim();
    const hospWhy = document.getElementById('hospitalizedWhy').value.trim();
    document.getElementById('rvHospitalized').textContent = hospitalized === 'yes'
        ? `Yes${hospWhen ? ' — ' + hospWhen : ''}${hospWhy ? ' (' + hospWhy + ')' : ''}`
        : hospitalized === 'no' ? 'No' : '—';

    document.getElementById('rvDenture').textContent = radioVal('wornDenture') || '—';

    const lastVisit = document.getElementById('lastDentalVisit').value.trim();
    const lastVisitWhy = document.getElementById('lastDentalVisitWhy').value.trim();
    document.getElementById('rvLastDental').textContent = lastVisit
        ? lastVisit + (lastVisitWhy ? ' — ' + lastVisitWhy : '')
        : '—';

    document.getElementById('rvSummaryServices').textContent = getSelectedServiceObjects().map(s => s.title).join(', ') || '—';
    document.getElementById('rvSummaryDate').textContent = document.getElementById('rvDate').textContent;
    document.getElementById('rvSummaryTime').textContent = document.getElementById('rvTime').textContent;
    document.getElementById('rvSummaryName').textContent = fullName;
}

// ====================== CONFIRM APPOINTMENT ======================
function confirmAppointment() {
    const submitBtn = document.getElementById('confirmBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    submitBtn.disabled = true;

    const conditions = Array.from(document.querySelectorAll('.condition-checkbox:checked')).map(cb => cb.value);
    const otherCondition = document.getElementById('otherConditionDetails').value.trim();
    if (otherCondition) conditions.push(otherCondition);

    const payload = {
        phone: validatePhoneLocal().valid ? validatePhoneLocal().normalized : getFullPhone(),
        email: document.getElementById('contactEmail').value.trim(),
        services: selectedServiceIds,
        appointment_date: selectedDate,
        appointment_time: selectedTime,
        full_name: getFullName(),
        age: document.getElementById('age').value.trim(),
        occupation: document.getElementById('occupation').value.trim(),
        region: selectedText('regionSelect'),
        province: selectedText('provinceSelect'),
        city: selectedText('citySelect'),
        barangay: selectedText('barangaySelect'),
        street_address: document.getElementById('streetAddress').value.trim(),
        notes: document.getElementById('patientNotes').value.trim(),
        terms_accepted: 1,
        privacy_accepted: 1,
        is_verified: 1,
        under_physician_care: radioVal('underCare'),
        physician_name: fieldVal('underCareDoctor'),
        pregnant: radioVal('pregnant'),
        taking_medicine: radioVal('takingMedicine'),
        medicine_details: fieldVal('medicineDetails'),
        conditions: conditions.join(', '),
        other_condition_details: fieldVal('otherConditionDetails'),
        allergic_local_anesthesia: radioVal('allergyLocalAnesthesia'),
        allergic_antibiotics: radioVal('allergyAntibiotics'),
        allergic_pain_killer: radioVal('allergyPainKiller'),
        allergic_other: radioVal('allergyOther'),
        allergic_other_details: fieldVal('allergyOtherDetails'),
        hospitalized: radioVal('hospitalized'),
        hospitalized_when: fieldVal('hospitalizedWhen'),
        hospitalized_why: fieldVal('hospitalizedWhy'),
        worn_denture: radioVal('wornDenture'),
        last_dental_visit: fieldVal('lastDentalVisit'),
        last_dental_visit_reason: fieldVal('lastDentalVisitWhy')
    };

    fetch('../../api/save_appointment_patient.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response. Please check the API file.');
            });
        }
        return response.json();
    })
    .then(data => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            document.getElementById('confirmedTrackingId').textContent = '#' + String(data.appointment_id).padStart(6, '0');
            document.getElementById('trackLink').href = '../../track-appointment.php?token=' + encodeURIComponent(data.tracking_token || '');
            goToStep(5);
        } else {
            Swal.fire({ icon: 'error', title: 'Booking Failed', text: data.message || 'Please try again.', confirmButtonColor: '#e53e3e' });
        }
    })
    .catch(err => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        console.error('Error:', err);
        Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Failed to save appointment. Please try again.', confirmButtonColor: '#e53e3e' });
    });
}

// ====================== INIT ======================
document.addEventListener('DOMContentLoaded', function() {
    generateCalendar();
    updateContinueState();
    loadRegions();

    // Split the profile's full name into first / middle / last
    const profileName = <?= json_encode($user_name) ?>;
    if (profileName && profileName !== 'Patient') {
        const parts = profileName.trim().split(/\s+/);
        if (parts.length === 1) {
            document.getElementById('firstName').value = titleCase(parts[0]);
        } else if (parts.length === 2) {
            document.getElementById('firstName').value = titleCase(parts[0]);
            document.getElementById('lastName').value  = titleCase(parts[1]);
        } else {
            document.getElementById('firstName').value  = titleCase(parts[0]);
            document.getElementById('lastName').value   = titleCase(parts[parts.length - 1]);
            document.getElementById('middleName').value = titleCase(parts.slice(1, -1).join(' '));
        }
    }

    // Phone prefill from profile (handles +63..., 09..., 9...)
    const phoneInput = document.getElementById('contactNumber');
    const profilePhone = <?= json_encode($user_phone) ?>;
    if (profilePhone) {
        const p = String(profilePhone).trim();
        if (p.startsWith('+63')) {
            phoneInput.value = p.slice(3).replace(/\D/g, '').slice(0, 10);
        } else if (p.startsWith('09')) {
            phoneInput.value = p.slice(1).replace(/\D/g, '').slice(0, 10);
        } else if (p.startsWith('63') && p.replace(/\D/g, '').length === 12) {
            phoneInput.value = p.replace(/\D/g, '').slice(2, 12);
        } else {
            phoneInput.value = p.replace(/\D/g, '').slice(0, 10);
        }
    }

    // Digits only, max 10
    phoneInput.addEventListener('input', () => {
        phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 10);
        updatePhoneFeedback();
    });
    phoneInput.addEventListener('blur', updatePhoneFeedback);
    updatePhoneFeedback();

    // Live Title Case for name fields
    ['firstName', 'middleName', 'lastName'].forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('input', () => {
            const pos = el.selectionStart;
            el.value = titleCase(el.value);
            el.setSelectionRange(pos, pos);
        });
        el.addEventListener('blur', () => {
            el.value = titleCase(el.value);
        });
    });
});
</script>

</body>
</html>