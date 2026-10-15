<?php
require 'conn/conn.php';

$settings = $conn->query("SELECT * FROM clinic_settings WHERE id = 1")->fetch_assoc();
$services = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
$features = $conn->query("SELECT * FROM features WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
$gallery  = $conn->query("SELECT * FROM gallery_items WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);

// ---- Clinic open/closed status (mirrors computeDayStatus on availability.php) ----
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

$nowDt    = new DateTime('now', new DateTimeZone('Asia/Manila'));
$todayStr = $nowDt->format('Y-m-d');
$todayDow = (int)$nowDt->format('w');
$nowTime  = $nowDt->format('H:i:s');

$stmt = $conn->prepare("SELECT status, start_time, end_time FROM dentist_availability_exceptions WHERE exception_date = ? LIMIT 1");
$stmt->bind_param('s', $todayStr);
$stmt->execute();
$todayEx = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT is_active, start_time, end_time FROM dentist_working_hours WHERE day_of_week = ? LIMIT 1");
$stmt->bind_param('i', $todayDow);
$stmt->execute();
$wh = $stmt->get_result()->fetch_assoc();
$stmt->close();

$whActive     = $wh && (int)$wh['is_active'] === 1;
$defaultStart = $wh['start_time'] ?? '08:00:00';
$defaultEnd   = $wh['end_time']   ?? '17:00:00';

if ($todayEx) {
    $todayStatusKey = $todayEx['status'];
    if ($todayStatusKey === 'unavailable') {
        $todayStartTime = $todayEndTime = null;
    } else {
        $todayStartTime = $todayEx['start_time'] ?: $defaultStart;
        $todayEndTime   = $todayEx['end_time']   ?: $defaultEnd;
    }
} elseif ($whActive) {
    $todayStatusKey = 'available';
    $todayStartTime = $wh['start_time'];
    $todayEndTime   = $wh['end_time'];
} else {
    $todayStatusKey = 'unavailable';
    $todayStartTime = $todayEndTime = null;
}

$isOpenToday = in_array($todayStatusKey, ['available', 'half_day'], true);
$isHalfDay   = ($todayStatusKey === 'half_day');

if (!$isOpenToday)                    $phase = 'closed_today';
elseif ($nowTime < $todayStartTime)   $phase = 'opens_later';
elseif ($nowTime >= $todayEndTime)    $phase = 'closed_after';
else                                  $phase = 'open_now';

$fmtTime = fn($t) => date('g:i A', strtotime($t));

switch ($phase) {
    case 'open_now':
        $clinicStatus = 'open';
        $statusLabel  = $isHalfDay ? 'Open Now · Half Day' : 'Open Now';
        $statusTitle  = 'Open until ' . $fmtTime($todayEndTime);
        break;
    case 'opens_later':
        $clinicStatus = 'soon';
        $statusLabel  = 'Opens at ' . $fmtTime($todayStartTime);
        $statusTitle  = 'Today: ' . $fmtTime($todayStartTime) . ' – ' . $fmtTime($todayEndTime) . ($isHalfDay ? ' (Half Day)' : '');
        break;
    case 'closed_after':
        $clinicStatus = 'closed';
        $statusLabel  = 'Closed for Today';
        $statusTitle  = "Today's hours were " . $fmtTime($todayStartTime) . ' – ' . $fmtTime($todayEndTime);
        break;
    default:
        $clinicStatus = 'closed';
        $statusLabel  = 'Closed Today';
        $statusTitle  = 'Clinic is closed today';
}
// ---- Announcements ----
$announcements = $conn->query("
    SELECT * FROM announcements 
    WHERE is_active = 1 
      AND (start_date IS NULL OR start_date <= CURDATE())
      AND (end_date IS NULL OR end_date >= CURDATE())
    ORDER BY sort_order ASC, created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);


// ---- Patient Feedback / Testimonials ----
$feedbacks = $conn->query("
    SELECT 
        f.id,
        f.rating,
        f.comment,
        f.created_at,
        p.full_name,
        s.title AS service_title,
        d.first_name AS dentist_first,
        d.last_name AS dentist_last
    FROM feedback f
    JOIN patients p ON f.patient_id = p.id
    JOIN patient_services ps ON f.patient_service_id = ps.id
    JOIN services s ON ps.service_id = s.id
    JOIN dentists d ON f.dentist_id = d.id
    WHERE f.rating >= 4
      AND f.comment IS NOT NULL
      AND TRIM(f.comment) <> ''
    ORDER BY f.created_at DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$feedbackStats = $conn->query("
    SELECT 
        COUNT(*) AS total,
        ROUND(AVG(rating), 1) AS avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star
    FROM feedback
")->fetch_assoc();

$avgRating   = $feedbackStats['avg_rating'] ?? 0;
$totalFB     = (int)($feedbackStats['total'] ?? 0);
$fiveStar    = (int)($feedbackStats['five_star'] ?? 0);
$fiveStarPct = $totalFB > 0 ? round(($fiveStar / $totalFB) * 100) : 0;

// Group by layout
$bannerAnnouncements   = array_values(array_filter($announcements, fn($a) => ($a['layout'] ?? 'banner') === 'banner'));
$floatingAnnouncements = array_values(array_filter($announcements, fn($a) => ($a['layout'] ?? 'banner') === 'floating'));
$inlineAnnouncements   = array_values(array_filter($announcements, fn($a) => ($a['layout'] ?? 'banner') === 'inline'));

function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function peso($amount) { return '₱' . number_format((float)$amount, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($settings['clinic_name']) ?> - Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        html { scroll-behavior: smooth; }
        section[id] { scroll-margin-top: 76px; }
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: var(--font-body); }

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
            --text: #0d1b2a;
            --font-display: 'Playfair Display', Georgia, serif;
            --font-body: 'DM Sans', system-ui, sans-serif;
            --r: 14px;
            --r-lg: 22px;
            --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
            --shadow-float: 0 24px 80px rgba(11,22,40,.22);
            --ease: cubic-bezier(.4,0,.2,1);

            --dark-blue: var(--navy);
            --light-teal: var(--teal-light);
            --soft-gray: var(--off);
        }

        h1, h2, h3, h4, .hero-title, .section-title, .logo-name { font-family: var(--font-display); }

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
            padding-left: 8rem;
            padding-right: 8rem;
        }
        @media (max-width: 1280px) { .container-modern { padding-left: 5rem; padding-right: 5rem; } }
        @media (max-width: 1024px) { .container-modern { padding-left: 3rem; padding-right: 3rem; } }
        @media (max-width: 768px)  { .container-modern { padding-left: 1.5rem; padding-right: 1.5rem; } }
        @media (max-width: 640px)  { .container-modern { padding-left: 1rem; padding-right: 1rem; } }

        .logo-name { font-size: 13px; font-weight: 700; color: var(--dark-blue); letter-spacing: 0.2px; line-height: 1.2; }
        .logo-clinic { font-size: 10px; font-weight: 600; color: var(--teal); letter-spacing: 1.5px; text-transform: uppercase; }

        .hero-title { font-size: 40px; font-weight: 900; line-height: 1.08; color: white; }
        .hero-title span { color: var(--teal); }
        .hero-sub { font-size: 16px; font-style: italic; font-weight: 600; color: #d9f2ee; line-height: 1.6; max-width: 460px; }

        .btn-primary { background: var(--teal); color: white; padding: 10px 26px; border-radius: 8px; font-weight: 600; font-size: 12px; transition: all 0.3s ease; display: inline-block; box-shadow: 0 4px 15px rgba(13, 158, 140, 0.25); }
        .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(13, 158, 140, 0.35); }

        .btn-outline { border: 2px solid var(--teal); color: var(--teal); padding: 10px 26px; border-radius: 8px; font-weight: 600; font-size: 12px; transition: all 0.3s ease; display: inline-block; background: transparent; }
        .btn-outline:hover { background: var(--teal); color: white; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(13, 158, 140, 0.2); }

        .btn-outline-white { border: 2px solid rgba(255,255,255,0.5); color: white; padding: 10px 26px; border-radius: 8px; font-weight: 600; font-size: 12px; transition: all 0.3s ease; display: inline-block; background: transparent; }
        .btn-outline-white:hover { background: white; color: var(--dark-blue); border-color: white; }

        .badge-dr { background: rgba(255,255,255,0.12); color: white; padding: 3px 14px; border-radius: 50px; font-size: 10px; font-weight: 700; display: inline-block; letter-spacing: 0.5px; border: 1px solid rgba(255,255,255,0.2); }
        .badge-eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--teal); font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
        .badge-eyebrow::before { content: ''; width: 22px; height: 1px; background: var(--teal); display: inline-block; }

        .service-card {
            background: white; border-radius: 14px; overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); transition: all 0.35s ease; border: 1px solid #eef2f6;
        }
        .service-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(13, 158, 140, 0.1); border-color: var(--teal); }
        .service-photo { 
            height: 130px; 
            background: linear-gradient(135deg, #ccf0eb, #9adfd3); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: rgba(13,158,140,0.45); 
            font-size: 30px;
            overflow: hidden;
        }
        .service-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .service-body { padding: 16px; }
        .service-title { font-size: 14px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; }
        .service-desc { color: #6b7280; font-size: 11.5px; line-height: 1.55; }
        .price-pill { background: var(--light-teal); color: var(--teal); font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 50px; }
        .duration-pill { background: #f1f4f8; color: #6b7280; font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 50px; }

        .info-card { background: white; border-radius: 12px; padding: 18px 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); border: 1px solid #eef2f6; transition: all 0.3s; }
        .info-card:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,0.06); }
        .info-label { font-size: 9px; font-weight: 700; color: var(--teal); text-transform: uppercase; letter-spacing: 1px; }
        .info-value { font-size: 13px; font-weight: 600; color: var(--dark-blue); margin-top: 2px; }

        .info-row { display: flex; align-items: center; gap: 12px; background: var(--soft-gray); border-radius: 10px; padding: 12px 14px; }
        .info-row .info-icon { width: 34px; height: 34px; background: var(--light-teal); color: var(--teal); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }

        .list-item { 
            background: white; 
            border-radius: 12px; 
            padding: 16px; 
            border: 1px solid #eef2f6; 
            display: flex; 
            gap: 12px; 
            align-items: flex-start; 
            transition: all 0.3s; 
        }
        .list-item:hover { border-color: var(--teal); box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .list-icon { 
            width: 34px; 
            height: 34px; 
            background: var(--light-teal); 
            color: var(--teal); 
            border-radius: 9px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 14px; 
            flex-shrink: 0;
            overflow: hidden;
        }
        .list-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .dark-card { background: var(--dark-blue); border-radius: 14px; padding: 20px; }
        .dark-card .info-label { color: var(--teal); }

        .gallery-tile { 
            border-radius: 14px; 
            overflow: hidden; 
            height: 170px; 
            background: linear-gradient(135deg, rgba(13,158,140,0.35), rgba(11,22,40,0.55)); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: rgba(255,255,255,0.5); 
            font-size: 30px; 
            position: relative; 
            transition: transform 0.3s ease;
        }
        .gallery-tile:hover {
            transform: scale(1.03);
        }
        .gallery-tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .gallery-tile .tag { 
            position: absolute; 
            bottom: 10px; 
            left: 10px; 
            background: rgba(11,22,40,0.7); 
            color: white; 
            font-size: 10px; 
            font-weight: 600; 
            padding: 4px 10px; 
            border-radius: 50px; 
            backdrop-filter: blur(4px);
        }

        .section-title { font-size: 26px; font-weight: 800; color: var(--dark-blue); }
        .section-title.on-dark { color: white; }
        .section-subtitle { color: #6b7280; font-size: 13px; }
        .section-subtitle.on-dark { color: #b7c2d0; }

        section { padding-top: 2.5rem; padding-bottom: 2.5rem; }

        .dark-sub-item { 
            background: rgba(255,255,255,0.05); 
            border-radius: 10px; 
            padding: 12px 14px; 
            border: 1px solid rgba(255,255,255,0.06);
        }

        @media (max-width: 768px) {
            section { padding-top: 2rem; padding-bottom: 2rem; }
            .hero-title { font-size: 28px; }
            .section-title { font-size: 20px; }
            .logo-name { font-size: 12px; }
            .logo-clinic { font-size: 9px; }
        }
        @media (max-width: 640px) {
            section { padding-top: 1.5rem; padding-bottom: 1.5rem; }
            .hero-title { font-size: 24px; }
            .section-title { font-size: 18px; }
            .btn-primary, .btn-outline, .btn-outline-white { padding: 8px 18px; font-size: 11px; }
        }





        /* ====== MODAL STYLES ====== */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(11, 22, 40, 0.7);
    backdrop-filter: blur(8px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    animation: fadeIn 0.3s ease;
}

.modal-overlay.active {
    display: flex;
}

.modal-container {
    background: white;
    border-radius: 20px;
    max-width: 520px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 2rem 1.75rem;
    position: relative;
    animation: slideUp 0.3s ease;
    box-shadow: 0 24px 80px rgba(11, 22, 40, 0.35);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: #f1f4f8;
    color: #4a5568;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.modal-close:hover {
    background: #e2e8f0;
    transform: rotate(90deg);
}

.modal-title {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 800;
    color: var(--navy);
    margin-bottom: 0.25rem;
}

.modal-subtitle {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 1.5rem;
}

.modal-form-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy);
    display: block;
    margin-bottom: 0.3rem;
}

.modal-form-label .required {
    color: #e53e3e;
    margin-left: 2px;
}

.modal-form-input {
    width: 100%;
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13px;
    transition: all 0.2s;
    background: #fafbfc;
    color: #1a202c;
}

.modal-form-input:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 4px rgba(13, 158, 140, 0.12);
    background: white;
}

.modal-form-input::placeholder {
    color: #a0aec0;
}

.modal-form-hint {
    font-size: 11px;
    color: #718096;
    margin-top: 0.25rem;
}

.service-checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}

.service-checkbox-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 500;
    color: #4a5568;
    background: #fafbfc;
}

.service-checkbox-item:hover {
    border-color: var(--teal);
    background: var(--teal-light);
}

.service-checkbox-item.selected {
    border-color: var(--teal);
    background: var(--teal-light);
    color: var(--teal-dark);
}

.service-checkbox-item input[type="checkbox"] {
    display: none;
}

.service-checkbox-item .check-icon {
    display: none;
    color: var(--teal);
    font-size: 12px;
}

.service-checkbox-item.selected .check-icon {
    display: inline;
}

.services-selected-count {
    font-size: 12px;
    color: var(--teal);
    font-weight: 600;
    margin-top: 6px;
}

.modal-privacy {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 1rem 0;
}

.modal-privacy input[type="checkbox"] {
    margin-top: 2px;
    width: 16px;
    height: 16px;
    accent-color: var(--teal);
    flex-shrink: 0;
    cursor: pointer;
}

.modal-privacy label {
    font-size: 12px;
    color: #4a5568;
    line-height: 1.5;
    cursor: pointer;
}

.modal-privacy label a {
    color: var(--teal);
    text-decoration: underline;
    font-weight: 500;
}

.modal-privacy label a:hover {
    color: var(--teal-dark);
}

.btn-modal-submit {
    width: 100%;
    padding: 12px;
    background: var(--teal);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-modal-submit:hover {
    background: var(--teal-dark);
    transform: translateY(-1px);
    box-shadow: 0 8px 25px rgba(13, 158, 140, 0.3);
}

.btn-modal-submit:active {
    transform: translateY(0);
}

.track-link {
    text-align: center;
    margin-top: 1rem;
    font-size: 13px;
    color: #64748b;
}

.track-link a {
    color: var(--teal);
    font-weight: 600;
    text-decoration: none;
}

.track-link a:hover {
    text-decoration: underline;
}

.form-group {
    margin-bottom: 1.25rem;
}

/* Scrollbar styling for modal */
.modal-container::-webkit-scrollbar {
    width: 6px;
}

.modal-container::-webkit-scrollbar-track {
    background: #f1f4f8;
    border-radius: 10px;
}

.modal-container::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 10px;
}

.modal-container::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

@media (max-width: 640px) {
    .modal-container {
        padding: 1.5rem 1rem;
        border-radius: 16px;
    }
    .modal-title {
        font-size: 20px;
    }
    .service-checkbox-item {
        font-size: 11px;
        padding: 5px 10px;
    }
}


/* ====== MODAL CHECKBOX STYLES ====== */
.modal-checkbox-group {
    margin: 0.75rem 0;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.modal-checkbox-group.error {
    border-color: #e53e3e;
    background: #fff5f5;
}

.modal-checkbox-group.valid {
    border-color: var(--teal);
    background: var(--teal-light);
}

.modal-checkbox-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    padding: 2px 0;
}

.modal-checkbox-item input[type="checkbox"] {
    display: none;
}

/* Custom Checkbox */
.custom-checkbox {
    width: 20px;
    height: 20px;
    min-width: 20px;
    border: 2px solid #cbd5e0;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    transition: all 0.2s ease;
    margin-top: 1px;
    position: relative;
}

.custom-checkbox i {
    color: white;
    font-size: 11px;
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s ease;
}

.modal-checkbox-item input[type="checkbox"]:checked + .custom-checkbox {
    background: var(--teal);
    border-color: var(--teal);
    box-shadow: 0 2px 8px rgba(13, 158, 140, 0.3);
}

.modal-checkbox-item input[type="checkbox"]:checked + .custom-checkbox i {
    opacity: 1;
    transform: scale(1);
}

.modal-checkbox-item input[type="checkbox"]:focus + .custom-checkbox {
    box-shadow: 0 0 0 3px rgba(13, 158, 140, 0.2);
    border-color: var(--teal);
}

.checkbox-label {
    font-size: 13px;
    color: #2d3748;
    line-height: 1.5;
    flex: 1;
}

.checkbox-label .modal-link {
    color: var(--teal);
    font-weight: 600;
    text-decoration: underline;
    transition: color 0.2s;
}

.checkbox-label .modal-link:hover {
    color: var(--teal-dark);
}

.checkbox-label .required {
    color: #e53e3e;
    font-weight: 700;
    margin-left: 2px;
}

.modal-checkbox-hint {
    font-size: 11px;
    color: #718096;
    margin-top: 6px;
    margin-left: 32px;
}

.modal-checkbox-group.error .modal-checkbox-hint {
    color: #e53e3e;
}

.modal-checkbox-group.valid .modal-checkbox-hint {
    color: var(--teal);
}

/* Service checkbox styles remain the same */
.service-checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}

.service-checkbox-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 500;
    color: #4a5568;
    background: #fafbfc;
}

.service-checkbox-item:hover {
    border-color: var(--teal);
    background: var(--teal-light);
}

.service-checkbox-item.selected {
    border-color: var(--teal);
    background: var(--teal-light);
    color: var(--teal-dark);
}

.service-checkbox-item input[type="checkbox"] {
    display: none;
}

.service-checkbox-item .check-icon {
    display: none;
    color: var(--teal);
    font-size: 12px;
}

.service-checkbox-item.selected .check-icon {
    display: inline;
}

.services-selected-count {
    font-size: 12px;
    color: var(--teal);
    font-weight: 600;
    margin-top: 6px;
}

/* ====== MODAL FOOTER ACTIONS ====== */
.modal-footer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 2px solid #e2e8f0;
    align-items: center;
}

.modal-footer-actions .modal-checkbox-group-inner {
    flex: 1 1 100%;
    margin-bottom: 0.5rem;
}

.modal-footer-buttons {
    display: flex;
    gap: 0.75rem;
    margin-left: auto;
}

/* Checkbox inside Terms/Privacy modals */
.modal-checkbox-group-inner {
    width: 100%;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.modal-checkbox-group-inner.checked {
    border-color: var(--teal);
    background: var(--teal-light);
}

.modal-checkbox-group-inner .modal-checkbox-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    padding: 2px 0;
}

.modal-checkbox-group-inner .modal-checkbox-item input[type="checkbox"] {
    display: none;
}

.modal-checkbox-group-inner .custom-checkbox {
    width: 20px;
    height: 20px;
    min-width: 20px;
    border: 2px solid #cbd5e0;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    transition: all 0.2s ease;
    margin-top: 1px;
}

.modal-checkbox-group-inner .custom-checkbox i {
    color: white;
    font-size: 11px;
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s ease;
}

.modal-checkbox-group-inner .modal-checkbox-item input[type="checkbox"]:checked + .custom-checkbox {
    background: var(--teal);
    border-color: var(--teal);
    box-shadow: 0 2px 8px rgba(13, 158, 140, 0.3);
}

.modal-checkbox-group-inner .modal-checkbox-item input[type="checkbox"]:checked + .custom-checkbox i {
    opacity: 1;
    transform: scale(1);
}

.modal-checkbox-group-inner .checkbox-label {
    font-size: 13px;
    color: #2d3748;
    line-height: 1.5;
    flex: 1;
}

.modal-checkbox-group-inner .checkbox-label .required {
    color: #e53e3e;
    font-weight: 700;
    margin-left: 2px;
}

/* Button styles */
.btn-modal-secondary {
    padding: 10px 24px;
    background: #f1f4f8;
    color: #4a5568;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-modal-secondary:hover {
    background: #e2e8f0;
    border-color: #cbd5e0;
}

.btn-modal-primary {
    padding: 10px 24px;
    background: var(--teal);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-modal-primary:hover {
    background: var(--teal-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(13, 158, 140, 0.3);
}

.btn-modal-primary:active {
    transform: translateY(0);
}

@media (max-width: 640px) {
    .modal-footer-buttons {
        width: 100%;
        flex-direction: column;
    }
    .modal-footer-buttons button {
        width: 100%;
    }
    .modal-checkbox-group-inner {
        padding: 0.5rem 0.75rem;
    }
}



/* ====== OTP INPUT STYLES ====== */
.otp-input {
    width: 50px;
    height: 60px;
    text-align: center;
    font-size: 28px;
    font-weight: 700;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: #fafbfc;
    color: #0b1628;
    transition: all 0.2s;
    font-family: 'Courier New', monospace;
}

.otp-input:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 4px rgba(13, 158, 140, 0.12);
    background: white;
}

.otp-input.error {
    border-color: #e53e3e;
    box-shadow: 0 0 0 4px rgba(229, 62, 62, 0.12);
}

.otp-input.success {
    border-color: var(--teal);
    background: var(--teal-light);
}

.modal-small {
    max-width: 460px;
}

@media (max-width: 640px) {
    .otp-input {
        width: 40px;
        height: 50px;
        font-size: 22px;
    }
}
/* ====== LOGIN/REGISTER MODAL STYLES ====== */
.modal-login {
    max-width: 440px;
    padding: 2rem 2rem 1.75rem;
}

.modal-login .form-group {
    margin-bottom: 1rem;
}

.modal-login .modal-form-input {
    padding: 12px 16px;
    font-size: 14px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    transition: all 0.3s ease;
}

.modal-login .modal-form-input:focus {
    background: white;
    border-color: var(--teal);
    box-shadow: 0 0 0 4px rgba(13, 158, 140, 0.1);
}

.modal-login .modal-form-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 0.4rem;
    display: block;
}

.modal-login .modal-form-label i {
    width: 18px;
}

.modal-login .btn-modal-submit {
    padding: 14px;
    font-size: 15px;
    font-weight: 700;
    margin-top: 0.25rem;
    border-radius: 10px;
    background: var(--teal);
    transition: all 0.3s ease;
}

.modal-login .btn-modal-submit:hover {
    background: var(--teal-dark);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(13, 158, 140, 0.3);
}

.modal-login .btn-modal-submit:active {
    transform: translateY(0);
}

.modal-login .btn-modal-submit i {
    margin-right: 8px;
}

/* Password toggle button */
.password-toggle-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: 16px;
    padding: 4px;
    transition: color 0.2s;
}

.password-toggle-btn:hover {
    color: var(--teal);
}

.password-toggle-btn:focus {
    outline: none;
}

/* Logo */
.modal-logo-wrapper {
    text-align: center;
    margin-bottom: 0.5rem;
}

.modal-logo {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.5rem;
    overflow: hidden;
    border: 4px solid var(--teal-light);
    box-shadow: 0 8px 30px rgba(13, 158, 140, 0.2);
    transition: transform 0.3s ease;
}
/* ====== SWEETALERT FIX ====== */
.swal2-container {
    z-index: 99999 !important;
}

.swal2-popup {
    z-index: 99999 !important;
}
.modal-logo:hover {
    transform: scale(1.05);
}

.modal-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.modal-clinic-name {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 800;
    color: var(--navy);
    line-height: 1.2;
    margin-bottom: 0;
}

.modal-clinic-name span {
    color: var(--teal);
}

.modal-clinic-tagline {
    font-size: 11px;
    color: #64748b;
    font-weight: 500;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Form links */
.modal-login a {
    transition: color 0.2s;
}

.modal-login a:hover {
    color: var(--teal-dark) !important;
}

/* Checkbox styling */
.modal-login input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--teal);
    cursor: pointer;
}

/* Responsive */
@media (max-width: 640px) {
    .modal-login {
        padding: 1.5rem 1.25rem;
        border-radius: 16px;
    }
    
    .modal-login .modal-title {
        font-size: 20px;
    }
    
    .modal-logo {
        width: 70px;
        height: 70px;
    }
    
    .modal-clinic-name {
        font-size: 16px;
    }
    
    .modal-login .modal-form-input {
        font-size: 13px;
        padding: 10px 14px;
    }
    
    .modal-login .btn-modal-submit {
        font-size: 14px;
        padding: 12px;
    }
    
    .modal-login .modal-form-label {
        font-size: 12px;
    }
}


/* ====== BOOKING MODAL: LOCKED +63 PREFIX ====== */
.modal-phone-wrap {
    display: flex;
    align-items: stretch;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: #fafbfc;
    overflow: hidden;
    transition: all 0.2s ease;
}
.modal-phone-wrap:focus-within {
    border-color: var(--teal);
    background: white;
    box-shadow: 0 0 0 4px rgba(13,158,140,.12);
}
.modal-phone-wrap.is-valid   { border-color: var(--teal); }
.modal-phone-wrap.is-invalid { border-color: #e53e3e; }

.modal-phone-prefix {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
    background: #eef2f6;
    color: #64748b;
    font-weight: 700;
    font-size: 13px;
    border-right: 1px solid #e2e8f0;
    user-select: none;
    min-width: 52px;
}

.modal-phone-input {
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    flex: 1;
}
.modal-phone-input:focus {
    outline: none;
    box-shadow: none !important;
}

/* ====== CHAT BOX STYLES ====== */
.chat-toggle {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--teal);
    color: white;
    border: none;
    box-shadow: 0 4px 20px rgba(13, 158, 140, 0.4);
    cursor: pointer;
    z-index: 9998;
    font-size: 24px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 8px 30px rgba(13, 158, 140, 0.5);
}

.chat-toggle .unread-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #e53e3e;
    color: white;
    font-size: 10px;
    font-weight: 700;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
}

.chat-toggle .unread-badge.visible {
    display: flex;
}

.chat-window {
    position: fixed;
    bottom: 100px;
    right: 30px;
    width: 380px;
    max-width: calc(100vw - 60px);
    height: 500px;
    max-height: calc(100vh - 180px);
    background: white;
    border-radius: 20px;
    box-shadow: 0 24px 80px rgba(11, 22, 40, 0.25);
    z-index: 9999;
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.3s ease;
}

.chat-window.open {
    display: flex;
}

.chat-header {
    background: var(--navy);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.chat-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--teal);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    flex-shrink: 0;
}

.chat-header-info h4 {
    color: white;
    font-size: 14px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.chat-header-info .status {
    font-size: 10px;
    color: #7fe0d1;
    display: flex;
    align-items: center;
    gap: 4px;
}

.chat-header-info .status .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.chat-header-info .status .dot.online {
    background: #48bb78;
}

.chat-header-info .status .dot.offline {
    background: #a0aec0;
}

.chat-header-info .status .dot.bot {
    background: var(--teal);
}

.chat-close {
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-close:hover {
    background: rgba(255,255,255,0.2);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    background: #f7f9fc;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.chat-messages .message {
    max-width: 85%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
    word-wrap: break-word;
    animation: fadeIn 0.2s ease;
}

.chat-messages .message.bot {
    background: white;
    color: var(--text);
    align-self: flex-start;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.chat-messages .message.bot .msg-time {
    font-size: 9px;
    color: #94a3b8;
    margin-top: 4px;
    display: block;
}

.chat-messages .message.bot .msg-sender {
    font-size: 9px;
    color: var(--teal);
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
}

.chat-messages .message.user {
    background: var(--teal);
    color: white;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}

.chat-messages .message.user .msg-time {
    font-size: 9px;
    color: rgba(255,255,255,0.7);
    margin-top: 4px;
    display: block;
    text-align: right;
}

.chat-messages .message.staff {
    background: #e8f4f8;
    color: var(--text);
    align-self: flex-start;
    border-bottom-left-radius: 4px;
    border-left: 3px solid var(--teal);
}

.chat-messages .message.staff .msg-sender {
    font-size: 9px;
    color: var(--teal);
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
}

.chat-messages .message.staff .msg-time {
    font-size: 9px;
    color: #94a3b8;
    margin-top: 4px;
    display: block;
}

.chat-messages .typing-indicator {
    display: none;
    align-self: flex-start;
    padding: 10px 16px;
    background: white;
    border-radius: 12px;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.chat-messages .typing-indicator.active {
    display: flex;
    gap: 4px;
    align-items: center;
}

.chat-messages .typing-indicator span {
    width: 8px;
    height: 8px;
    background: #94a3b8;
    border-radius: 50%;
    animation: typingBounce 1.4s infinite both;
}

.chat-messages .typing-indicator span:nth-child(2) {
    animation-delay: 0.2s;
}

.chat-messages .typing-indicator span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typingBounce {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

.chat-footer {
    padding: 12px 16px;
    border-top: 1px solid #e2e8f0;
    background: white;
    flex-shrink: 0;
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.chat-footer textarea {
    flex: 1;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 13px;
    resize: none;
    min-height: 40px;
    max-height: 100px;
    font-family: var(--font-body);
    transition: border-color 0.2s;
    background: #fafbfc;
}

.chat-footer textarea:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 4px rgba(13, 158, 140, 0.1);
}

.chat-footer textarea::placeholder {
    color: #a0aec0;
}

.chat-footer .send-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--teal);
    color: white;
    border: none;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.chat-footer .send-btn:hover {
    background: var(--teal-dark);
    transform: scale(1.05);
}

.chat-footer .send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.chat-footer .send-btn .fa-spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.chat-mode-toggle {
    display: flex;
    gap: 6px;
    padding: 4px 6px;
    background: #f1f4f8;
    border-radius: 8px;
    margin-bottom: 6px;
    flex-shrink: 0;
}

.chat-mode-toggle button {
    padding: 4px 12px;
    border: none;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    background: transparent;
    color: #64748b;
}

.chat-mode-toggle button.active {
    background: var(--teal);
    color: white;
}

.chat-mode-toggle button:hover:not(.active) {
    background: #e2e8f0;
}

.chat-actions {
    display: flex;
    gap: 6px;
}

.chat-actions button {
    background: none;
    border: none;
    color: rgba(255,255,255,0.6);
    cursor: pointer;
    font-size: 14px;
    transition: color 0.2s;
    padding: 4px;
}

.chat-actions button:hover {
    color: white;
}

@media (max-width: 640px) {
    .chat-window {
        bottom: 80px;
        right: 10px;
        width: calc(100vw - 20px);
        height: calc(100vh - 160px);
        border-radius: 16px;
    }
    
    .chat-toggle {
        bottom: 20px;
        right: 20px;
        width: 52px;
        height: 52px;
        font-size: 20px;
    }
    
    .chat-messages {
        padding: 12px 14px;
    }
}

/* Quick replies */
.quick-replies button:hover {
    background: var(--teal) !important;
    color: white !important;
    transform: translateY(-1px);
}

.chat-messages .message.bot .msg-sender {
    font-size: 9px;
    color: var(--teal);
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
}

.chat-messages .message.user .msg-sender {
    font-size: 9px;
    color: #94a3b8;
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
    text-align: right;
}

/* Empty state for chat */
.empty-chat {
    text-align: center;
    color: #94a3b8;
    font-size: 13px;
    padding: 20px;
}

.chat-messages {
    scroll-behavior: smooth;
}

/* Message animations */
.message {
    animation: messageIn 0.3s ease;
}

@keyframes messageIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}


.chat-quick-replies {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px 16px;
    background: white;
    border-top: 1px solid #f1f4f8;
    flex-shrink: 0; /* keeps it from being squeezed/scrolled by the messages flex area */
}

.chat-quick-replies button {
    background: var(--teal-light);
    border: 1px solid var(--teal);
    color: var(--teal-dark);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.chat-quick-replies button:hover {
    background: var(--teal);
    color: white;
}


.scroll-to-bottom-btn {
    position: absolute;
    bottom: 130px; /* sits just above quick-replies/footer */
    right: 20px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--navy);
    color: white;
    border: none;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    box-shadow: 0 4px 14px rgba(11,22,40,0.3);
    z-index: 5;
    transition: transform 0.2s;
}

.scroll-to-bottom-btn.visible {
    display: flex;
}

.scroll-to-bottom-btn:hover {
    transform: translateY(-2px);
}

.scroll-to-bottom-btn .new-msg-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: #e53e3e;
    color: white;
    font-size: 9px;
    font-weight: 700;
    min-width: 16px;
    height: 16px;
    padding: 0 3px;
    border-radius: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
}



/* ====================================================================
   ANNOUNCEMENTS
   ==================================================================== */

/* ---------- Shared tag/badge styling ---------- */
.announcement-tag, .floating-tag, .inline-announcement-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 50px;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}
.tag-info    { background: rgba(13,158,140,0.12);  color: #0a7d6e; }
.tag-success { background: rgba(72,187,120,0.15);  color: #276749; }
.tag-warning { background: rgba(237,137,54,0.15);  color: #9c4221; }
.tag-promo   { background: rgba(201,168,76,0.18);  color: #975a16; }

/* ====================================================================
   1. TOP BANNER BAR
   ==================================================================== */
.announcement-bar {
    display: none;
    background: linear-gradient(90deg, var(--navy) 0%, var(--navy-mid) 55%, var(--teal-dark) 100%);
    color: white;
    padding: 9px 0;
    position: relative;
    z-index: 40;
    overflow: hidden;
}
.announcement-bar.visible { display: block; }

.announcement-bar-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
}

.announcement-icon {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--teal);
    font-size: 13px;
    animation: pulseBell 2.4s infinite;
}

@keyframes pulseBell {
    0%, 100% { transform: rotate(0); }
    10%, 30% { transform: rotate(-12deg); }
    20%, 40% { transform: rotate(12deg); }
    50% { transform: rotate(0); }
}

.announcement-track {
    flex: 1;
    position: relative;
    height: 22px;
    overflow: hidden;
}

.announcement-item {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    opacity: 0;
    transform: translateY(100%);
    transition: opacity 0.5s ease, transform 0.5s ease;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 12.5px;
    color: #e2eaf2;
}
.announcement-item.active {
    opacity: 1;
    transform: translateY(0);
    position: relative;
}
.announcement-item strong { color: #ffffff; font-weight: 700; }

.announcement-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    font-size: 10px;
    flex-shrink: 0;
    background: rgba(255,255,255,0.15);
    color: #7fe0d1;
}

.announcement-cta {
    margin-left: 6px;
    background: rgba(255,255,255,0.12);
    color: white;
    font-size: 10.5px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 50px;
    text-decoration: none;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.announcement-cta:hover { background: var(--teal); }

.announcement-close {
    flex-shrink: 0;
    background: rgba(255,255,255,0.08);
    border: none;
    color: rgba(255,255,255,0.7);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 11px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.announcement-close:hover {
    background: rgba(255,255,255,0.22);
    color: white;
    transform: rotate(90deg);
}

/* ====================================================================
   2. FLOATING CARD (bottom-right popup)
   ==================================================================== */
.floating-announcements {
    position: fixed;
    bottom: 110px;       /* clears the chat bubble */
    right: 30px;
    z-index: 9997;
    width: 340px;
    max-width: calc(100vw - 40px);
    pointer-events: none;
}

.floating-announcement {
    display: none;
    pointer-events: auto;
    background: white;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(11,22,40,0.22), 0 4px 12px rgba(11,22,40,0.08);
    border: 1px solid rgba(13,158,140,0.12);
    animation: floatIn 0.5s cubic-bezier(.2,.9,.3,1.2);
}
.floating-announcement.active { display: block; }

@keyframes floatIn {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.floating-close {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(11,22,40,0.55);
    color: white;
    border: none;
    cursor: pointer;
    font-size: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    backdrop-filter: blur(6px);
    transition: all 0.2s;
}
.floating-close:hover { background: var(--navy); transform: rotate(90deg); }

.floating-media {
    position: relative;
    height: 150px;
    background: linear-gradient(135deg, var(--navy), var(--teal-dark));
    overflow: hidden;
}
.floating-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.5s ease;
}
.floating-announcement:hover .floating-media img { transform: scale(1.05); }

.floating-media-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,0.5);
    font-size: 42px;
}
.floating-media-placeholder.type-promo   { background: linear-gradient(135deg, #c9a84c, #0b1628); }
.floating-media-placeholder.type-info    { background: linear-gradient(135deg, var(--teal), var(--navy)); }
.floating-media-placeholder.type-warning { background: linear-gradient(135deg, #dd6b20, #0b1628); }
.floating-media-placeholder.type-success { background: linear-gradient(135deg, #48bb78, var(--navy)); }

.floating-tag {
    position: absolute;
    top: 12px;
    left: 12px;
    background: rgba(255,255,255,0.95);
    color: var(--navy);
    backdrop-filter: blur(6px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.floating-body {
    padding: 16px 18px 18px;
}
.floating-title {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 800;
    color: var(--navy);
    margin: 0 0 5px;
    line-height: 1.25;
}
.floating-message {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
    margin: 0 0 12px;
}
.floating-cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--teal);
    color: white;
    font-size: 11.5px;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.25s;
    box-shadow: 0 4px 14px rgba(13,158,140,0.28);
}
.floating-cta:hover {
    background: var(--teal-dark);
    transform: translateY(-1px);
    box-shadow: 0 8px 22px rgba(13,158,140,0.38);
}
.floating-cta i { font-size: 10px; transition: transform 0.2s; }
.floating-cta:hover i { transform: translateX(3px); }

.floating-dots {
    display: flex;
    gap: 5px;
    justify-content: center;
    margin-top: 10px;
    pointer-events: auto;
}
.floating-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(11,22,40,0.25);
    border: none;
    padding: 0;
    cursor: pointer;
    transition: all 0.25s;
}
.floating-dot.active {
    width: 18px;
    border-radius: 50px;
    background: var(--teal);
}

/* ====================================================================
   3. INLINE CARD (in-page, hero-style)
   ==================================================================== */
.inline-announcements-wrap {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 24px auto;
}

.inline-announcement {
    display: flex;
    align-items: stretch;
    gap: 0;
    background: white;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(11,22,40,0.06);
    border: 1px solid #eef2f6;
    transition: all 0.3s;
}
.inline-announcement:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 42px rgba(13,158,140,0.12);
    border-color: rgba(13,158,140,0.3);
}

.inline-announcement-img {
    width: 190px;
    flex-shrink: 0;
    overflow: hidden;
    background: linear-gradient(135deg, var(--navy), var(--teal-dark));
}
.inline-announcement-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.5s ease;
}
.inline-announcement:hover .inline-announcement-img img { transform: scale(1.06); }

.inline-announcement-content {
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 6px;
    flex: 1;
}
.inline-announcement-tag { align-self: flex-start; }
.inline-announcement-title {
    font-family: var(--font-display);
    font-size: 19px;
    font-weight: 800;
    color: var(--navy);
    margin: 0;
    line-height: 1.25;
}
.inline-announcement-text {
    font-size: 13px;
    color: #64748b;
    line-height: 1.55;
    margin: 0;
}
.inline-announcement-cta {
    align-self: flex-start;
    margin-top: 6px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--teal);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: gap 0.2s;
}
.inline-announcement-cta:hover { gap: 10px; }
.inline-announcement-cta i { font-size: 10px; }
/* ====== Clinic open/closed badge in topbar ====== */
.clinic-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 50px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    white-space: nowrap;
}
.clinic-status-badge.open {
    background: rgba(72, 187, 120, 0.18);
    color: #7fe0d1;
    border: 1px solid rgba(127, 224, 209, 0.35);
}
.clinic-status-badge.closed {
    background: rgba(229, 62, 62, 0.18);
    color: #feb2b2;
    border: 1px solid rgba(254, 178, 178, 0.35);
}
.clinic-status-badge.soon {
    background: rgba(237, 137, 54, 0.18);
    color: #fbd38d;
    border: 1px solid rgba(251, 211, 141, 0.35);
}
.clinic-status-badge .status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulseStatus 1.6s infinite;
    flex-shrink: 0;
}
@keyframes pulseStatus {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .55; transform: scale(1.4); }
}

@media (max-width: 640px) {
    .clinic-status-badge {
        font-size: 9px;
        padding: 2px 8px;
    }
}
/* ====================================================================
   RESPONSIVE
   ==================================================================== */
@media (max-width: 768px) {
    .announcement-item { font-size: 11.5px; }
    .announcement-cta { display: none; }

    .floating-announcements {
        right: 12px;
        left: 12px;
        bottom: 90px;
        width: auto;
    }
    .floating-media { height: 130px; }

    .inline-announcement { flex-direction: column; }
    .inline-announcement-img { width: 100%; height: 160px; }
    .inline-announcement-content { padding: 16px 18px; }
    .inline-announcement-title { font-size: 16px; }
}

@media (max-width: 480px) {
    .announcement-icon { display: none; }
    .announcement-track { height: 30px; }
    .announcement-item {
        white-space: normal;
        line-height: 1.3;
        font-size: 11px;
        padding-right: 4px;
    }
}


/* ====================================================================
   PATIENT FEEDBACK / TESTIMONIALS
   ==================================================================== */
.feedback-section {
    background: var(--off);
    position: relative;
    overflow: hidden;
}
.feedback-section::before {
    content: '';
    position: absolute;
    top: -100px;
    right: -100px;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(13,158,140,0.08) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

.feedback-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 32px;
    justify-content: center;
    align-items: center;
    margin-bottom: 36px;
    padding: 20px 28px;
    background: white;
    border-radius: 18px;
    box-shadow: 0 4px 24px rgba(11,22,40,0.06);
    border: 1px solid #eef2f6;
    max-width: 780px;
    margin-left: auto;
    margin-right: auto;
}
.feedback-stat {
    text-align: center;
    flex: 1;
    min-width: 110px;
}
.feedback-stat .num {
    font-family: var(--font-display);
    font-size: 34px;
    font-weight: 800;
    color: var(--navy);
    line-height: 1;
}
.feedback-stat .num .small {
    font-size: 16px;
    color: #94a3b8;
    font-weight: 600;
}
.feedback-stat .lbl {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 6px;
}
.feedback-stat .stars-row {
    color: #f59e0b;
    font-size: 13px;
    letter-spacing: 2px;
    margin-top: 4px;
}
.feedback-stat-divider {
    width: 1px;
    height: 50px;
    background: #eef2f6;
    flex-shrink: 0;
}
@media (max-width: 640px) {
    .feedback-stat-divider { display: none; }
    .feedback-stats { gap: 20px; padding: 16px; }
    .feedback-stat .num { font-size: 26px; }
}

.feedback-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}
@media (max-width: 640px) {
    .feedback-grid { grid-template-columns: 1fr; }
}

.feedback-card {
    background: white;
    border-radius: 16px;
    padding: 22px;
    border: 1px solid #eef2f6;
    box-shadow: 0 2px 10px rgba(11,22,40,0.04);
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    flex-direction: column;
}
.feedback-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 40px rgba(13,158,140,0.12);
    border-color: rgba(13,158,140,0.3);
}
.feedback-card::before {
    content: '\201C';
    position: absolute;
    top: -6px;
    right: 18px;
    font-family: Georgia, serif;
    font-size: 68px;
    line-height: 1;
    color: rgba(13,158,140,0.12);
    pointer-events: none;
}

.feedback-card-stars {
    color: #f59e0b;
    font-size: 14px;
    letter-spacing: 2px;
    margin-bottom: 10px;
}
.feedback-card-stars .empty { color: #e2e8f0; }

.feedback-card-comment {
    font-size: 13.5px;
    line-height: 1.65;
    color: #4a5568;
    flex: 1;
    margin-bottom: 16px;
    font-style: italic;
    position: relative;
    z-index: 1;
}

.feedback-card-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-top: 14px;
    border-top: 1px solid #f1f4f8;
}
.feedback-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--teal), var(--teal-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    flex-shrink: 0;
    border: 2px solid white;
    box-shadow: 0 2px 8px rgba(13,158,140,0.25);
}
.feedback-author {
    flex: 1;
    min-width: 0;
}
.feedback-author-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy);
    line-height: 1.2;
}
.feedback-author-meta {
    font-size: 10.5px;
    color: #94a3b8;
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.feedback-author-meta i { font-size: 9px; }
.feedback-author-meta .dot { color: #cbd5e0; }

.feedback-service-tag {
    display: inline-block;
    background: var(--teal-light);
    color: var(--teal-dark);
    font-size: 9.5px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 50px;
    margin-top: 6px;
    letter-spacing: 0.3px;
}
    </style>
</head>
<body>

<!-- ====== TOPBAR ====== -->
<div class="bg-dark-blue text-white text-[11px] relative overflow-hidden">
    <div class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-4 md:gap-6 py-2.5 px-4 pr-28 lg:pl-8">
        <span class="flex items-center gap-1.5">
            <i class="fas fa-phone text-xs sm:text-sm flex-shrink-0"></i>
            <span class="text-[10px] sm:text-[11px] break-all"><?= h($settings['phone']) ?></span>
        </span>
        <span class="flex items-center gap-1.5">
            <i class="fas fa-envelope text-xs sm:text-sm flex-shrink-0"></i>
            <span class="text-[10px] sm:text-[11px] break-all"><?= h($settings['email']) ?></span>
        </span>
        <span class="hidden sm:flex items-center gap-1.5">
            <i class="fas fa-location-dot text-xs sm:text-sm flex-shrink-0"></i>
            <span class="text-[10px] sm:text-[11px] break-all"><?= h($settings['location']) ?></span>
        </span>

<span id="clinicStatusBadge"
      class="clinic-status-badge <?= $clinicStatus ?>"
      title="<?= h($statusTitle) ?>"
      data-date="<?= h($todayStr) ?>"
      data-open="<?= $isOpenToday ? h($todayStartTime) : '' ?>"
      data-close="<?= $isOpenToday ? h($todayEndTime) : '' ?>"
      data-half="<?= $isHalfDay ? '1' : '0' ?>">
    <span class="status-dot"></span>
    <span class="status-text"><?= h($statusLabel) ?></span>
</span>
    </div>
    <div class="absolute right-0 top-0 h-full flex items-center gap-2 sm:gap-4 bg-teal pl-6 sm:pl-10 md:pl-14 pr-3 sm:pr-6 md:pr-8"
         style="clip-path: polygon(20px 0, 100% 0, 100% 100%, 0 100%);">
        <a href="<?= h($settings['facebook_url']) ?>" class="hover:text-dark-blue transition text-sm sm:text-base"><i class="fab fa-facebook"></i></a>
        <a href="<?= h($settings['instagram_url']) ?>" class="hover:text-dark-blue transition text-sm sm:text-base"><i class="fab fa-instagram"></i></a>
        <a href="<?= h($settings['messenger_url']) ?>" class="hover:text-dark-blue transition text-sm sm:text-base"><i class="fab fa-facebook-messenger"></i></a>
    </div>
</div>

<?php if (!empty($bannerAnnouncements)): ?>
<!-- ====== ANNOUNCEMENT BANNER ====== -->
<div id="announcementBar" class="announcement-bar">
    <div class="container-modern">
        <div class="announcement-bar-inner">
            <div class="announcement-icon">
                <i class="fas fa-bullhorn"></i>
            </div>
            <div class="announcement-track" id="announcementTrack">
                <?php foreach ($bannerAnnouncements as $a): ?>
                    <div class="announcement-item">
                        <span class="announcement-badge">
                            <i class="fas <?= h($a['icon'] ?: 'fa-bullhorn') ?>"></i>
                        </span>
                        <strong><?= h($a['title']) ?>:</strong>
                        <span><?= h($a['message']) ?></span>
                        <?php if (!empty($a['cta_text'])): ?>
                            <a href="<?= h($a['cta_link'] ?: '#') ?>" class="announcement-cta"
                               <?= (isset($a['cta_link']) && str_starts_with($a['cta_link'], '#booking')) ? 'onclick="openModal(\'bookingModal\'); return false;"' : '' ?>>
                                <?= h($a['cta_text']) ?> <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="announcement-close" onclick="dismissAnnouncement('bar')" aria-label="Dismiss">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- ====== NAVBAR ====== -->
<nav class="bg-white/90 backdrop-blur-md py-2.5 sticky top-0 z-50 border-b border-gray-100/50">
    <div class="container-modern">
        <div class="flex items-center justify-between">
            <div class="flex   ">
                <!-- Static Logo Image -->
                <img src="assets/img/logo.png" 
                     alt="<?= h($settings['clinic_name']) ?>" 
                     style="height: 50px; width: auto; object-fit: contain;">
            </div>
            <div class="hidden lg:flex items-center gap-5">
                <a href="#home" class="nav-link active" >Home</a>
                <a href="#services" class="nav-link">Services</a>
                <a href="#gallery" class="nav-link">Gallery</a>
                <a href="#about" class="nav-link">About</a>
                <a href="#contact" class="nav-link">Contact</a>
                <a href="#" onclick="openLoginModal();"  class="btn-primary !text-[11px] !px-4 !py-2">Login</a>
                <a href="#" onclick="openRegisterModal();" class="btn-primary !text-[11px] !px-4 !py-2">Sign up</a>
            </div>
            <div class="lg:hidden">
                <button id="menuToggle" class="text-gray-600 text-lg hover:text-teal transition"><i class="fas fa-bars"></i></button>
            </div>
        </div>
        <div id="mobileMenu" class="lg:hidden hidden pt-2.5 mt-2 border-t border-gray-100">
            <a href="#home" class="block py-1.5 text-teal font-semibold text-sm">Home</a>
            <a href="#services" class="block py-1.5 text-gray-600 hover:text-teal text-sm">Services</a>
            <a href="#gallery" class="block py-1.5 text-gray-600 hover:text-teal text-sm">Gallery</a>
            <a href="#about" class="block py-1.5 text-gray-600 hover:text-teal text-sm">About</a>
          <a href="#contact" class="block py-1.5 text-gray-600 hover:text-teal text-sm">Contact</a>
<div class="flex gap-2 mt-2.5 pt-2.5 border-t border-gray-100">
    <a href="#" onclick="openLoginModal(); document.getElementById('mobileMenu').classList.add('hidden'); return false;" class="btn-primary !text-[11px] !px-4 !py-2 flex-1 text-center">Login</a>
    <a href="#" onclick="openRegisterModal(); document.getElementById('mobileMenu').classList.add('hidden'); return false;" class="btn-primary !text-[11px] !px-4 !py-2 flex-1 text-center">Sign up</a>
</div>
</div>
    </div>
</nav>
<!-- ====== HERO SECTION ====== -->
<?php 
$heroBg = !empty($settings['hero_background']) ? $settings['hero_background'] : '';
$hasBg = !empty($heroBg);
?>
<section id="home" 
         class="relative overflow-hidden py-16 md:py-24 <?= !$hasBg ? 'bg-dark-blue' : '' ?>"
         <?php if ($hasBg): ?>
         style="background-image: url('<?= h($heroBg) ?>'); background-size: cover; background-position: center; background-attachment: fixed;"
         <?php endif; ?>>
    
    <!-- Overlay - changes based on whether background image exists -->
    <?php if ($hasBg): ?>
    <div class="absolute inset-0 bg-gradient-to-br from-[#0b1628] via-[#0b1628]/80 to-[#0d9e8c]/35"></div>
    <?php else: ?>
    <div class="absolute inset-0 bg-gradient-to-br from-[#0b1628] via-[#0b1628]/95 to-[#0d9e8c]/25"></div>
    <?php endif; ?>
    
    <!-- Decorative blur element -->
    <div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-teal/10 blur-3xl"></div>
    
    <div class="container-modern relative z-10">
        <div class="max-w-2xl">
            <div class="mb-4">
                <span class="badge-dr">
                    <i class="fas fa-user-md mr-1.5"></i><?= h($settings['hero_badge']) ?>
                </span>
            </div>
            <h1 class="hero-title">
                <?= h($settings['hero_title_line1']) ?><br>
                <span><?= h($settings['hero_title_line2']) ?></span>
            </h1>
            <p class="hero-sub mt-4"><?= h($settings['hero_subtitle']) ?></p>
            <p class="text-gray-300 text-sm mt-3 max-w-lg"><?= h($settings['hero_description']) ?></p>
            
            <div class="flex flex-wrap gap-3 mt-6">
       <a href="#" class="btn-primary" onclick="openModal('bookingModal'); return false;">
    <i class="fas fa-calendar-check mr-2"></i> Book Appointment
</a>
                <a href="#services" class="btn-outline-white">
                    <i class="fas fa-briefcase-medical mr-2"></i> Browse Services
                </a>
            </div>

            <div class="flex items-center gap-10 mt-8 pt-6 border-t border-white/10">
                <div>
                    <span class="text-2xl font-bold text-white"><?= h($settings['stat1_value']) ?></span>
                    <span class="text-xs text-gray-400 block font-medium mt-0.5"><?= h($settings['stat1_label']) ?></span>
                </div>
                <div>
                    <span class="text-2xl font-bold text-white"><?= h($settings['stat2_value']) ?></span>
                    <span class="text-xs text-gray-400 block font-medium mt-0.5"><?= h($settings['stat2_label']) ?></span>
                </div>
                <div>
                    <span class="text-2xl font-bold text-white"><?= h($settings['stat3_value']) ?></span>
                    <span class="text-xs text-gray-400 block font-medium mt-0.5"><?= h($settings['stat3_label']) ?></span>
                </div>
            </div>
        </div>
    </div>
</section>
<br>
<?php if (!empty($inlineAnnouncements)): ?>
<!-- ====== INLINE ANNOUNCEMENTS ====== -->
<section class="container-modern inline-announcements-wrap">
    <?php foreach ($inlineAnnouncements as $a): ?>
    <div class="inline-announcement type-<?= h($a['type']) ?>">
        <?php if (!empty($a['image'])): ?>
            <div class="inline-announcement-img">
                <img src="<?= h($a['image']) ?>" alt="<?= h($a['title']) ?>" loading="lazy">
            </div>
        <?php endif; ?>
        <div class="inline-announcement-content">
            <span class="inline-announcement-tag tag-<?= h($a['type']) ?>">
                <i class="fas <?= h($a['icon'] ?: 'fa-bullhorn') ?>"></i>
                <?= h(ucfirst($a['type'])) ?>
            </span>
            <h3 class="inline-announcement-title"><?= h($a['title']) ?></h3>
            <p class="inline-announcement-text"><?= h($a['message']) ?></p>
            <?php if (!empty($a['cta_text'])): ?>
                <a href="<?= h($a['cta_link'] ?: '#') ?>" class="inline-announcement-cta"
                   <?= (isset($a['cta_link']) && str_starts_with($a['cta_link'], '#booking')) ? 'onclick="openModal(\'bookingModal\'); return false;"' : '' ?>>
                    <?= h($a['cta_text']) ?> <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</section>


<?php endif; ?>
 
<!-- ====== SERVICES SECTION ====== -->
<section id="services" class="bg-white">
    <div class="container-modern">
        <div class="text-center mb-8">
            <div class="flex justify-center"><span class="badge-eyebrow">What We Offer</span></div>
            <h2 class="section-title mt-2">Available <span class="italic text-teal font-normal">dental services</span></h2>
            <p class="section-subtitle mt-1">Explore the treatments offered at the clinic before making your appointment.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php if (empty($services)): ?>
                <p class="text-gray-500 text-sm col-span-full text-center">No services have been added yet.</p>
            <?php endif; ?>
            <?php foreach ($services as $service): ?>
            <div class="service-card">
                <div class="service-photo">
                    <?php if (!empty($service['image']) && file_exists($service['image'])): ?>
                        <img src="<?= h($service['image']) ?>" alt="<?= h($service['title']) ?>" loading="lazy">
                    <?php elseif (!empty($service['image'])): ?>
                        <img src="<?= h($service['image']) ?>" alt="<?= h($service['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <i class="fas <?= h($service['icon'] ?? 'fa-tooth') ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="service-body">
                    <h3 class="service-title"><?= h($service['title']) ?></h3>
                    <p class="service-desc"><?= h($service['description']) ?></p>
                    <div class="mt-3 flex items-center gap-2">
                        <span class="duration-pill"><?= h($service['duration']) ?></span>
                        <span class="price-pill"><?= peso($service['price']) ?></span>
                    </div>
<a href="#" class="btn-outline !text-[11px] !px-4 !py-1.5 mt-3 inline-block" onclick="openModal('bookingModal'); return false;">Book this service →</a>                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<br>

<!-- ====== HOW IT WORKS (static process steps) ====== -->
<section class="bg-white">
    <div class="container-modern">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between mb-8">
            <div>
                <span class="badge-eyebrow">How It Works</span>
                <h2 class="section-title mt-2">Book in four <span class="italic text-teal font-normal">simple steps</span></h2>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-y-7 gap-x-6">
            <div class="relative">
                <div class="flex items-center mb-3.5">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full border border-teal/40 text-teal text-[11px] font-bold shrink-0">01</div>
                    <span class="hidden md:block flex-1 h-px bg-gray-200 ml-3"></span>
                </div>
                <h4 class="service-title">Choose a service</h4>
                <p class="service-desc">Browse available dental treatments and select the one that fits your need before starting your appointment request.</p>
            </div>
            <div class="relative">
                <div class="flex items-center mb-3.5">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full border border-teal/40 text-teal text-[11px] font-bold shrink-0">02</div>
                    <span class="hidden md:block flex-1 h-px bg-gray-200 ml-3"></span>
                </div>
                <h4 class="service-title">Select a schedule</h4>
                <p class="service-desc">Pick your preferred date and available time slot based on the clinic's open hours and current availability.</p>
            </div>
            <div class="relative">
                <div class="flex items-center mb-3.5">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-teal text-white text-[11px] font-bold shrink-0">03</div>
                    <span class="hidden md:block flex-1 h-px bg-gray-200 ml-3"></span>
                </div>
                <h4 class="service-title">Fill in your details</h4>
                <p class="service-desc">Enter your contact information and any notes to help the clinic prepare for your visit efficiently.</p>
            </div>
            <div class="relative">
                <div class="flex items-center mb-3.5">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full border border-teal/40 text-teal text-[11px] font-bold shrink-0">04</div>
                                        <span class="hidden md:block flex-1 h-px bg-gray-200 ml-3"></span>

                </div>
                <h4 class="service-title">Await confirmation</h4>
                <p class="service-desc">The clinic reviews your request and sends a final confirmation with your appointment details.</p>
            </div>
        </div>
    </div>
</section>
<br>

<!-- ====== BUILT FOR BETTER EXPERIENCE ====== -->
<section id="about" class="bg-soft-gray">
    <div class="container-modern">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-start">
            <div>
                <span class="badge-eyebrow">Why Choose Us</span>
                <h2 class="section-title mt-3"><?= h($settings['about_heading']) ?></h2>
                <p class="section-subtitle mt-3 max-w-md"><?= h($settings['about_description']) ?></p>

                <div class="flex flex-col gap-3.5 mt-6">
                    <?php foreach ($features as $feature): ?>
                    <div class="list-item" style="list-style-type: none;">
                        <div class="list-icon">
                            <?php if (!empty($feature['image'])): ?>
                                <img src="<?= h($feature['image']) ?>" alt="<?= h($feature['title']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fas <?= h($feature['icon'] ?? 'fa-check') ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="font-bold text-dark-blue text-sm"><?= h($feature['title']) ?></h4>
                            <p class="text-gray-500 text-[11px] mt-1"><?= h($feature['description']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="info-card">
                    <p class="info-label">Appointment Review</p>
                    <h4 class="font-bold text-dark-blue text-base mt-1">Staff-confirmed scheduling</h4>
                    <p class="text-gray-500 text-[12px] mt-1.5 leading-relaxed">Every request is reviewed by our team before confirmation, ensuring your slot is properly prepared and ready.</p>
                </div>

                <div class="dark-card">
                    <p class="info-label">What to Expect</p>
                    <h4 class="font-bold text-white text-lg mt-1 mb-4">Before your visit</h4>
                    <div class="flex flex-col gap-2.5">
                        <div class="dark-sub-item">
                            <p class="info-label" style="color:#7fe0d1;">Service Guidance</p>
                            <p style="color:#c3ceda; font-size:11.5px; margin-top:2px; line-height:1.5;">Review service details and estimated costs before selecting.</p>
                        </div>
                        <div class="dark-sub-item">
                            <p class="info-label" style="color:#7fe0d1;">Schedule Flexibility</p>
                            <p style="color:#c3ceda; font-size:11.5px; margin-top:2px; line-height:1.5;">Choose a preferred date and time based on available clinic hours.</p>
                        </div>
                        <div class="dark-sub-item">
                            <p class="info-label" style="color:#7fe0d1;">Clinic Support</p>
                            <p style="color:#c3ceda; font-size:11.5px; margin-top:2px; line-height:1.5;">Use our chat assistant if you need help before booking.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<br>

<!-- ====== GALLERY PREVIEW ====== -->
<section id="gallery" class="bg-dark-blue">
    <div class="container-modern">
        <div class="mb-8">
            <span class="badge-eyebrow">Our Clinic</span>
            <h2 class="section-title on-dark mt-2">A space built for <span class="italic text-teal font-normal">comfort</span></h2>
            <p class="section-subtitle on-dark mt-1">A look inside the clinic where patients receive personalized care and treatment.</p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <?php foreach ($gallery as $item): ?>
            <div class="gallery-tile">
                <?php if (!empty($item['image'])): ?>
                    <img src="<?= h($item['image']) ?>" alt="<?= h($item['tag']) ?>" loading="lazy">
                <?php else: ?>
                    <i class="fas <?= h($item['icon'] ?? 'fa-image') ?>"></i>
                <?php endif; ?>
                <span class="tag"><?= h($item['tag']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<br>
<!-- ====== PATIENT FEEDBACK ====== -->
<?php if (!empty($feedbacks)): ?>
<section id="feedback" class="feedback-section">
    <div class="container-modern">

        <div class="text-center mb-8">
            <div class="flex justify-center">
                <span class="badge-eyebrow">Patient Reviews</span>
            </div>
            <h2 class="section-title mt-2">
                What our <span class="italic text-teal font-normal">patients say</span>
            </h2>
            <p class="section-subtitle mt-1">
                Real feedback from patients who trusted us with their smile.
            </p>
        </div>

        <!-- Aggregate stats -->
        <div class="feedback-stats">
            <div class="feedback-stat">
                <div class="num">
                    <?= number_format((float)$avgRating, 1) ?>
                    <span class="small">/5</span>
                </div>
                <div class="stars-row">
                    <?php
                    $full = floor($avgRating);
                    $half = ($avgRating - $full) >= 0.5;
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= $full)                     echo '<i class="fas fa-star"></i>';
                        elseif ($i == $full + 1 && $half)    echo '<i class="fas fa-star-half-alt"></i>';
                        else                                 echo '<i class="far fa-star"></i>';
                    }
                    ?>
                </div>
                <div class="lbl">Average Rating</div>
            </div>

            <div class="feedback-stat-divider"></div>

            <div class="feedback-stat">
                <div class="num"><?= $totalFB ?></div>
                <div class="lbl">Total Reviews</div>
            </div>

            <div class="feedback-stat-divider"></div>

            <div class="feedback-stat">
                <div class="num"><?= $fiveStarPct ?><span class="small">%</span></div>
                <div class="lbl">5-Star Rated</div>
            </div>
        </div>

        <!-- Feedback cards -->
        <div class="feedback-grid">
            <?php foreach ($feedbacks as $fb):
                $fullName = trim($fb['full_name'] ?? '');
                if ($fullName === '') $fullName = 'Patient';

                $parts     = preg_split('/\s+/', $fullName);
                $firstName = $parts[0] ?? 'Patient';
                $lastName  = end($parts) ?: '';

                $safeName = $firstName;
                if (count($parts) > 1 && $lastName !== $firstName) {
                    $safeName .= ' ' . strtoupper(substr($lastName, 0, 1)) . '.';
                }

                $initial = strtoupper(substr($firstName, 0, 1));
            ?>
            <div class="feedback-card">
                <div class="feedback-card-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa<?= $i <= $fb['rating'] ? 's' : 'r' ?> fa-star <?= $i > $fb['rating'] ? 'empty' : '' ?>"></i>
                    <?php endfor; ?>
                </div>

                <p class="feedback-card-comment">
                    <?= h($fb['comment']) ?>
                </p>

                <div class="feedback-card-footer">
                    <div class="feedback-avatar"><?= h($initial) ?></div>
                    <div class="feedback-author">
                        <div class="feedback-author-name"><?= h($safeName) ?></div>
                        <div class="feedback-author-meta">
                            <i class="far fa-calendar-alt"></i>
                            <?= date('M d, Y', strtotime($fb['created_at'])) ?>
                            <span class="dot">·</span>
                            <i class="fas fa-user-md"></i>
                            Dr. <?= h($fb['dentist_first'] . ' ' . $fb['dentist_last']) ?>
                        </div>
                        <span class="feedback-service-tag">
                            <?= h($fb['service_title']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
               </div>
        <!-- close .feedback-grid -->

        <?php if ($totalFB > 2): ?>
        <div class="text-center mt-8">
            <button type="button" 
                    onclick="openReviewsDrawer()" 
                    class="btn-primary !text-[12px] !px-6 !py-2.5">
                <i class="fas fa-star mr-1.5"></i> 
                View All <?= $totalFB ?> Reviews
                <i class="fas fa-arrow-right ml-1.5"></i>
            </button>
        </div>
        <?php endif; ?>

    </div>
</section>
<br>
<?php endif; ?>
<!-- ====== CONTACT / CLINIC INFO ====== -->
<section id="contact" class="bg-white">
    <div class="container-modern">
        <div class="text-center mb-8">
            <div class="flex justify-center"><span class="badge-eyebrow">Contact Us</span></div>
            <h2 class="section-title mt-2">Reach the clinic <span class="italic text-teal font-normal">directly</span></h2>
            <p class="section-subtitle mt-1">Have questions before booking? Find everything you need to get in touch below.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl mx-auto">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-dark-blue text-base mb-4">Clinic Information</h3>
                <div class="flex flex-col gap-3">
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div><p class="info-label">Phone</p><p class="info-value"><?= h($settings['phone']) ?></p></div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div><p class="info-label">Email</p><p class="info-value"><?= h($settings['email']) ?></p></div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-location-dot"></i></div>
                        <div><p class="info-label">Location</p><p class="info-value"><?= h($settings['location']) ?></p></div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div><p class="info-label">Clinic Hours</p><p class="info-value"><?= h($settings['clinic_hours']) ?></p></div>
                    </div>
                </div>
            </div>

            <div class="dark-card flex flex-col justify-center">
                <p class="info-label">Ready to Visit Us?</p>
                <h3 class="font-bold text-white text-xl mt-1.5"><?= h($settings['cta_heading']) ?></h3>
                <p class="text-gray-300 text-[12px] mt-2 leading-relaxed"><?= h($settings['cta_description']) ?></p>
                <div class="flex flex-wrap gap-2.5 mt-5">
                   <a href="#" class="btn-primary" onclick="openModal('bookingModal'); return false;"><i class="fas fa-calendar-plus mr-1.5"></i> Book Appointment</a>
                    <a href="track-appointment.php" class="btn-outline-white"><i class="fas fa-clipboard-list mr-1.5"></i> Track Request</a>
                </div>
            </div>
        </div>
    </div>
</section>
<br>

<!-- ====== FOOTER ====== -->
<footer class="bg-dark-blue py-6 mt-6">
    <div class="container-modern">
        <div class="flex flex-col md:flex-row justify-between items-center gap-3.5">
            <div>
                <div class="text-white font-bold text-xs"><?= h($settings['clinic_name']) ?></div>
                <div class="text-teal text-[9px] font-semibold tracking-wider"><?= h($settings['clinic_tagline']) ?></div>
            </div>
            <div class="flex flex-wrap items-center gap-3.5">
                <a href="#" class="text-gray-400 hover:text-white transition text-[10px]">Home</a>
                <a href="#" class="text-gray-400 hover:text-white transition text-[10px]">Services</a>
                <a href="#" class="text-gray-400 hover:text-white transition text-[10px]">Gallery</a>
                <a href="#" class="text-gray-400 hover:text-white transition text-[10px]">About</a>
                <a href="#" class="text-gray-400 hover:text-white transition text-[10px]">Contact</a>
                <a href="<?= h($settings['instagram_url']) ?>" class="text-gray-400 hover:text-white transition"><i class="fab fa-instagram text-xs"></i></a>
                <a href="<?= h($settings['facebook_url']) ?>" class="text-gray-400 hover:text-white transition"><i class="fab fa-facebook text-xs"></i></a>
            </div>
        </div>
        <div class="text-center text-gray-500 text-[9px] mt-3.5 border-t border-gray-700 pt-3.5">
            &copy; <?= date('Y') ?> <?= h($settings['clinic_name']) ?> Dental Clinic. All rights reserved.
        </div>
    </div>
</footer>


<!-- ====== BOOKING MODAL ====== -->
<div id="bookingModal" class="modal-overlay">
    <div class="modal-container">
        <!-- Close Button -->
        <button class="modal-close" onclick="closeModal('bookingModal')">
            <i class="fas fa-times"></i>
        </button>

        <!-- Modal Header -->
        <h2 class="modal-title">Book an Appointment</h2>
        <p class="modal-subtitle">Fill in the details below and we'll confirm your visit.</p>

        <form id="bookingForm" onsubmit="handleSubmit(event)">
            <!-- Phone Number -->
            <!-- Phone Number -->
<div class="form-group">
    <label class="modal-form-label">
        Phone number <span class="required">*</span>
    </label>

    <div class="modal-phone-wrap" id="modalPhoneWrap">
        <span class="modal-phone-prefix" id="modalPhonePrefix">+63</span>
        <input 
            type="tel" 
            class="modal-form-input modal-phone-input" 
            id="phoneNumber"
            placeholder="9171234567"
            inputmode="numeric"
            autocomplete="tel-national"
            maxlength="10"
            required
        >
    </div>
    <p class="modal-form-hint" id="modalPhoneHint">
        Enter the 10 digits after +63 (starting with 9).
    </p>
</div>

            <!-- Email -->
            <div class="form-group">
                <label class="modal-form-label">
                    Email <span class="required">*</span>
                </label>
                <input 
                    type="email" 
                    class="modal-form-input" 
                    id="emailAddress"
                    placeholder="youremail@example.com" 
                    value="pulmonesmarjorie8@gmail.com"
                    required
                >
                <p class="modal-form-hint">Required for appointment verification and confirmation.</p>
            </div>

            <!-- Services -->
            <div class="form-group">
                <label class="modal-form-label">
                    Select service(s) <span class="required">*</span>
                </label>
                <div class="service-checkbox-group" id="serviceGroup">
                    <?php foreach ($services as $index => $service): ?>
                    <label class="service-checkbox-item" data-service-id="<?= $service['id'] ?>">
                        <input type="checkbox" value="<?= $service['id'] ?>" 
                               onchange="updateServiceSelection(this)">
                        <span class="check-icon"><i class="fas fa-check-circle"></i></span>
                        <?= h($service['title']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="services-selected-count" id="selectedCount">0 services selected</div>
                <p class="modal-form-hint">You may select one or more services for this appointment request.</p>
            </div>

            <!-- Terms & Conditions Checkbox -->
           <div class="modal-checkbox-group" id="termsGroup">
    <label class="modal-checkbox-item">
        <input type="checkbox" id="termsCheckbox" onchange="validateCheckbox('termsCheckbox', 'termsGroup')" disabled>
        <span class="custom-checkbox">
            <i class="fas fa-check"></i>
        </span>
        <span class="checkbox-label">
            I agree to the clinic's 
            <a href="#" onclick="openTermsModal(); return false;" class="modal-link">
                Terms & Conditions
            </a>
            <span class="required">*</span>
        </span>
    </label>
    <p class="modal-checkbox-hint">You must agree to the Terms & Conditions to proceed.</p>
</div>

         
<!-- Privacy Policy Checkbox -->
<div class="modal-checkbox-group" id="privacyGroup">
    <label class="modal-checkbox-item">
        <input type="checkbox" id="privacyCheckbox" onchange="validateCheckbox('privacyCheckbox', 'privacyGroup')" disabled>
        <span class="custom-checkbox">
            <i class="fas fa-check"></i>
        </span>
        <span class="checkbox-label">
            I agree to the clinic's 
            <a href="#" onclick="openPrivacyModal(); return false;" class="modal-link">
                Privacy Policy
            </a>
            and allow the clinic to use my information for appointment verification and scheduling.
            <span class="required">*</span>
        </span>
    </label>
    <p class="modal-checkbox-hint">You must agree to the Privacy Policy to proceed.</p>
</div>

            <!-- Submit Button -->
            <button type="submit" class="btn-modal-submit" id="bookingSubmitBtn">
                <i class="fas fa-paper-plane"></i> Send Verification
            </button>

            <!-- Track Link -->
            <div class="track-link">
                Already submitted? <a href="track-appointment.php">Track your appointment</a>
            </div>
        </form>
    </div>
</div>


<!-- ====== OTP VERIFICATION MODAL ====== -->
<div id="otpModal" class="modal-overlay">
    <div class="modal-container modal-small">
        <button class="modal-close" onclick="closeModal('otpModal')">
            <i class="fas fa-times"></i>
        </button>

        <div style="text-align: center; padding: 0.5rem 0;">
            <div style="font-size: 48px; color: var(--teal); margin-bottom: 0.5rem;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h2 class="modal-title" style="font-size: 22px;">Verify Your Identity</h2>
            <p class="modal-subtitle" style="font-size: 13px; border-bottom: none; padding-bottom: 0;">
                We've sent a verification code to your email.
            </p>
        </div>

        <form id="otpForm" onsubmit="handleOTPSubmit(event)">
            <div class="form-group" style="margin-top: 1rem;">
                <label class="modal-form-label" style="text-align: center; display: block;">
                    Enter 6-digit OTP
                </label>
                <div style="display: flex; gap: 10px; justify-content: center; margin: 0.5rem 0;">
                    <input type="text" maxlength="1" class="otp-input" id="otp1" 
                           oninput="moveToNext(this, 'otp2')" onkeydown="handleBackspace(event, 'otp1')" autofocus>
                    <input type="text" maxlength="1" class="otp-input" id="otp2" 
                           oninput="moveToNext(this, 'otp3')" onkeydown="handleBackspace(event, 'otp1')">
                    <input type="text" maxlength="1" class="otp-input" id="otp3" 
                           oninput="moveToNext(this, 'otp4')" onkeydown="handleBackspace(event, 'otp2')">
                    <input type="text" maxlength="1" class="otp-input" id="otp4" 
                           oninput="moveToNext(this, 'otp5')" onkeydown="handleBackspace(event, 'otp3')">
                    <input type="text" maxlength="1" class="otp-input" id="otp5" 
                           oninput="moveToNext(this, 'otp6')" onkeydown="handleBackspace(event, 'otp4')">
                    <input type="text" maxlength="1" class="otp-input" id="otp6" 
                           oninput="moveToNext(this, null)" onkeydown="handleBackspace(event, 'otp5')">
                </div>
                <div id="otpError" style="text-align: center; color: #e53e3e; font-size: 12px; min-height: 20px; margin-top: 4px;"></div>
                <p style="text-align: center; font-size: 12px; color: #718096; margin-top: 4px;">
                    Code expires in <span id="otpTimer" style="font-weight: 700; color: var(--teal);">5:00</span>
                </p>
            </div>

            <button type="submit" class="btn-modal-submit" id="verifyBtn">
                <i class="fas fa-check-circle"></i> Verify OTP
            </button>

            <div style="text-align: center; margin-top: 1rem;">
                <button type="button" onclick="resendOTPCode()" 
                        id="resendBtn" 
                        style="background: none; border: none; color: var(--teal); font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: underline; display: none;">
                    Resend OTP
                </button>
                <span id="resendTimer" style="font-size: 12px; color: #718096; display: none;"></span>
            </div>
        </form>
    </div>
</div>


<!-- ====== LOGIN MODAL ====== -->
<div id="loginModal" class="modal-overlay">
    <div class="modal-container modal-login">
        <button class="modal-close" onclick="closeModal('loginModal')">
            <i class="fas fa-times"></i>
        </button>

        <div class="modal-logo-wrapper">
            <!-- Logo -->
            <div class="modal-logo">
 <i class="fas fa-tooth "  style="font-size:40px"></i>
            </div>
            <h3 class="modal-clinic-name">
                Dr. Brendalyn Wansi <span>Calacat</span>
            </h3>
            <p class="modal-clinic-tagline">Dental Clinic • Specialized Dentistry With Personalized Care</p>
        </div>

        <form id="loginForm" onsubmit="handleLoginSubmit(event)">
            <div class="form-group">
                <label class="modal-form-label">
                    <i class="fas fa-envelope" style="color: var(--teal); margin-right: 6px;"></i>
                    Email Address <span class="required">*</span>
                </label>
                <input 
                    type="email" 
                    class="modal-form-input" 
                    id="loginEmail"
                    placeholder="youremail@example.com" 
                    required
                >
            </div>

            <div class="form-group">
                <label class="modal-form-label">
                    <i class="fas fa-lock" style="color: var(--teal); margin-right: 6px;"></i>
                    Password <span class="required">*</span>
                </label>
                <div style="position: relative;">
                    <input 
                        type="password" 
                        class="modal-form-input" 
                        id="loginPassword"
                        placeholder="Enter your password" 
                        required
                        style="padding-right: 45px;"
                    >
                    <button 
                        type="button" 
                        onclick="togglePasswordVisibility()"
                        class="password-toggle-btn"
                    >
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin: -0.5rem 0 1.25rem 0;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #4a5568; cursor: pointer;">
                    <input type="checkbox" id="rememberMe" style="accent-color: var(--teal); width: 16px; height: 16px; cursor: pointer;">
                    Remember me
                </label>
                <a href="#" onclick="showForgotPassword()" style="font-size: 12px; color: var(--teal); text-decoration: none; font-weight: 600; transition: color 0.2s;">
                    Forgot password?
                </a>
            </div>

            <button type="submit" class="btn-modal-submit" id="loginSubmitBtn">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>

            <div style="text-align: center; margin-top: 1.25rem; font-size: 13px; color: #64748b;">
                Don't have an account? 
                <a href="#" onclick="openRegisterModal(); return false;" style="color: var(--teal); font-weight: 600; text-decoration: none; transition: color 0.2s;">
                    Create Account
                </a>
            </div>
        </form>
    </div>
</div> 

<!-- ====== REGISTER MODAL (WITH reCAPTCHA) ====== -->
<div id="registerModal" class="modal-overlay">
    <div class="modal-container modal-login" style="max-width: 560px;">
        <button class="modal-close" onclick="closeModal('registerModal')">
            <i class="fas fa-times"></i>
        </button>

        <div class="modal-logo-wrapper">
            <div class="modal-logo">
                <i class="fas fa-tooth" style="font-size:40px"></i>
            </div>
            <h3 class="modal-clinic-name">
                Dr. Brendalyn Wansi <span>Calacat</span>
            </h3>
            <p class="modal-clinic-tagline">Dental Clinic • Specialized Dentistry With Personalized Care</p>
        </div>

        <div style="text-align:center; margin: 4px 0 20px;">
            <h2 style="font-family: var(--font-display); font-size:19px; font-weight:800; color:var(--navy); margin:0;">
                Create Your Account
            </h2>
            <p style="font-size:12.5px; color:#64748b; margin-top:3px;">
                Sign up to book appointments and manage your visits online.
            </p>
        </div>

        <form id="registerForm" onsubmit="handleRegisterSubmit(event)">

            <!-- ===== SECTION: PERSONAL DETAILS ===== -->
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
                <span style="width:22px; height:1px; background:var(--teal); display:inline-block;"></span>
                <span style="font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--teal);">
                    Personal Details
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Full Name -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="modal-form-label">
                        <i class="fas fa-user" style="color: var(--teal); margin-right: 6px;"></i>
                        Full Name <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        class="modal-form-input" 
                        id="registerName"
                        placeholder="Juan Dela Cruz" 
                        required
                    >
                </div>

                <!-- Email + Phone side by side -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="modal-form-label">
                            <i class="fas fa-envelope" style="color: var(--teal); margin-right: 6px;"></i>
                            Email Address <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            class="modal-form-input" 
                            id="registerEmail"
                            placeholder="youremail@example.com" 
                            required
                        >
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="modal-form-label">
                            <i class="fas fa-phone" style="color: var(--teal); margin-right: 6px;"></i>
                            Phone Number <span class="required">*</span>
                        </label>
                        <input 
                            type="tel" 
                            class="modal-form-input" 
                            id="registerPhone"
                            placeholder="9123456789" 
                            required
                        >
                        <p style="font-size: 9px; color: #94a3b8; margin-top: 3px; line-height: 1.3;">
                            e.g. 9123456789 → +639123456789
                        </p>
                    </div>
                </div>
            </div>

            <!-- ===== SECTION: SECURITY ===== -->
            <div style="display:flex; align-items:center; gap:8px; margin:20px 0 14px;">
                <span style="width:22px; height:1px; background:var(--teal); display:inline-block;"></span>
                <span style="font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--teal);">
                    Security
                </span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
                <!-- Password -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="modal-form-label">
                        <i class="fas fa-lock" style="color: var(--teal); margin-right: 6px;"></i>
                        Password <span class="required">*</span>
                    </label>
                    <div style="position: relative;">
                        <input 
                            type="password" 
                            class="modal-form-input" 
                            id="registerPassword"
                            placeholder="Min 8 characters" 
                            required
                            minlength="8"
                            style="padding-right: 45px;"
                        >
                        <button 
                            type="button" 
                            onclick="toggleRegisterPasswordVisibility()"
                            class="password-toggle-btn"
                        >
                            <i class="fas fa-eye" id="registerPasswordToggleIcon"></i>
                        </button>
                    </div>
                    <p style="font-size: 9px; color: #94a3b8; margin-top: 3px;">
                        Must be at least 8 characters
                    </p>
                </div>

                <!-- Confirm Password -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="modal-form-label">
                        <i class="fas fa-check-circle" style="color: var(--teal); margin-right: 6px;"></i>
                        Confirm Password <span class="required">*</span>
                    </label>
                    <input 
                        type="password" 
                        class="modal-form-input" 
                        id="registerConfirmPassword"
                        placeholder="Confirm your password" 
                        required
                    >
                </div>
            </div>

            <!-- ===== reCAPTCHA - CENTERED ===== -->
            <div class="form-group" style="margin-top: 20px; padding-top:16px; border-top:1px solid #eef2f6;">
                <div style="display:flex; justify-content:center;">
                    <div class="g-recaptcha" 
                         data-sitekey="6Ld6fmQtAAAAALAvGiYfogBzwlMtSEcvYhLuLbJh"
                         data-theme="light"
                         data-size="normal"
                         id="registerRecaptcha">
                    </div>
                </div>
                <p class="modal-form-hint" style="text-align:center; margin-top:8px;">
                    <i class="fas fa-shield-alt" style="color:var(--teal); margin-right:4px;"></i>
                    Please complete the "I'm not a robot" verification.
                </p>
            </div>

            <!-- ===== TERMS & CONDITIONS ===== -->
            <div style="margin-top: 14px; display:flex; flex-direction:column; gap:8px;">
                <div class="modal-checkbox-group" id="registerTermsGroup" style="margin:0;">
                    <label class="modal-checkbox-item">
                        <input type="checkbox" id="registerTermsCheckbox" onchange="validateRegisterCheckbox('registerTermsCheckbox', 'registerTermsGroup')">
                        <span class="custom-checkbox">
                            <i class="fas fa-check"></i>
                        </span>
                        <span class="checkbox-label">
                            I agree to the clinic's 
                            <a href="#" onclick="openTermsModal(); return false;" class="modal-link">
                                Terms & Conditions
                            </a>
                            <span class="required">*</span>
                        </span>
                    </label>
                </div>

                <div class="modal-checkbox-group" id="registerPrivacyGroup" style="margin:0;">
                    <label class="modal-checkbox-item">
                        <input type="checkbox" id="registerPrivacyCheckbox" onchange="validateRegisterCheckbox('registerPrivacyCheckbox', 'registerPrivacyGroup')">
                        <span class="custom-checkbox">
                            <i class="fas fa-check"></i>
                        </span>
                        <span class="checkbox-label">
                            I agree to the clinic's 
                            <a href="#" onclick="openPrivacyModal(); return false;" class="modal-link">
                                Privacy Policy
                            </a>
                            and allow the clinic to use my information.
                            <span class="required">*</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- ===== SUBMIT BUTTON ===== -->
            <button type="submit" class="btn-modal-submit" id="registerSubmitBtn" style="margin-top: 18px;">
                <i class="fas fa-user-plus"></i> Create Account
            </button>

            <div style="text-align: center; margin-top: 14px; font-size: 13px; color: #64748b;">
                Already have an account? 
                <a href="#" onclick="openLoginModal(); return false;" style="color: var(--teal); font-weight: 600; text-decoration: none; transition: color 0.2s;">
                    Sign In
                </a>
            </div>
        </form>
    </div>
</div>
 
 

            
        </form>
    </div>
</div>



<!-- ====== TERMS & CONDITIONS MODAL ====== -->
<div id="termsModal" class="modal-overlay">
    <div class="modal-container modal-content-page">
        <button class="modal-close" onclick="closeModal('termsModal')">
            <i class="fas fa-times"></i>
        </button>

        <h2 class="modal-title">Terms & Conditions</h2>
        <p class="modal-subtitle">Please read these terms carefully before using our services</p>

        <div class="content-body">
            <h3>1. Appointment Booking</h3>
            <p>By booking an appointment through our website, you agree to:</p>
            <ul>
                <li>Provide accurate and complete information</li>
                <li>Arrive at least 15 minutes before your scheduled time</li>
                <li>Notify us at least 24 hours in advance for cancellations or rescheduling</li>
                <li>Bring valid identification and any relevant medical records</li>
            </ul>

            <h3>2. Cancellation Policy</h3>
            <div class="highlight-box">
                <p><strong>Late cancellations or no-shows</strong> may be subject to a fee. Please contact us at least 24 hours before your appointment to avoid charges.</p>
            </div>

            <h3>3. Payment Terms</h3>
            <p>Payment is due at the time of service unless otherwise arranged. We accept:</p>
            <ul>
                <li>Cash</li>
                <li>Credit/Debit cards</li>
                <li>Health insurance (with valid coverage)</li>
                <li>GCash and other digital payment methods</li>
            </ul>

            <h3>4. Patient Responsibilities</h3>
            <p>As a patient, you agree to:</p>
            <ul>
                <li>Provide complete and accurate medical history</li>
                <li>Follow post-treatment instructions provided by our staff</li>
                <li>Inform us of any changes in your health status</li>
                <li>Treat our staff and other patients with respect</li>
            </ul>

            <h3>5. Medical Disclaimer</h3>
            <p>All information provided on this website is for general informational purposes only and does not constitute medical advice. Always consult with a qualified healthcare professional for medical concerns.</p>

            <h3>6. Limitation of Liability</h3>
            <p><?= h($settings['clinic_name']) ?> Dental Clinic shall not be liable for any indirect, incidental, special, or consequential damages arising from the use of our services or website.</p>

            <h3>7. Intellectual Property</h3>
            <p>All content on this website, including text, graphics, logos, and images, is the property of <?= h($settings['clinic_name']) ?> Dental Clinic and is protected by copyright laws.</p>

            <h3>8. Governing Law</h3>
            <p>These terms shall be governed by and construed in accordance with the laws of the Philippines. Any disputes shall be resolved in the appropriate courts of the Philippines.</p>

            <h3>9. Changes to Terms</h3>
            <p>We reserve the right to update these terms at any time. Continued use of our services constitutes acceptance of the updated terms.</p>

            <p><em>Last updated: <?= date('F d, Y') ?></em></p>
        </div>

        <div class="modal-footer-actions">
            <!-- Checkbox inside the modal -->
           <div class="modal-checkbox-group-inner" id="termsModalGroup">
    <label class="modal-checkbox-item">
        <input type="checkbox" id="termsModalCheckbox" onchange="syncTermsCheckbox()">
        <span class="custom-checkbox">
            <i class="fas fa-check"></i>
        </span>
        <span class="checkbox-label">
            I have read and agree to the Terms & Conditions 
            <span class="required">*</span>
        </span>
    </label>
</div>
            <div class="modal-footer-buttons">
                <button class="btn-modal-secondary" onclick="closeModal('termsModal')">Close</button>
                <button class="btn-modal-primary" onclick="closeModal('termsModal')">
                    <i class="fas fa-check mr-1"></i> I Agree
                </button>
            </div>
        </div>
    </div>
</div>



<!-- ====== PRIVACY POLICY MODAL ====== -->
<div id="privacyModal" class="modal-overlay">
    <div class="modal-container modal-content-page">
        <button class="modal-close" onclick="closeModal('privacyModal')">
            <i class="fas fa-times"></i>
        </button>

        <h2 class="modal-title">Privacy Policy</h2>
        <p class="modal-subtitle">How we collect, use, and protect your information</p>

        <div class="content-body">
            <h3>1. Information We Collect</h3>
            <p>We collect personal information that you voluntarily provide to us when you:</p>
            <ul>
                <li>Book an appointment through our website</li>
                <li>Contact us via phone, email, or social media</li>
                <li>Fill out forms on our website</li>
                <li>Subscribe to our newsletter or updates</li>
            </ul>
            <p>This information may include your name, phone number, email address, and any other details you choose to provide.</p>

            <h3>2. How We Use Your Information</h3>
            <p>We use the information we collect to:</p>
            <ul>
                <li>Schedule and confirm your appointments</li>
                <li>Send appointment reminders and follow-ups</li>
                <li>Improve our services and patient experience</li>
                <li>Respond to your inquiries and requests</li>
                <li>Comply with legal obligations</li>
            </ul>

            <h3>3. Data Protection</h3>
            <div class="highlight-box">
                <p><strong>Your privacy is important to us.</strong> We implement appropriate technical and organizational measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction.</p>
            </div>

            <h3>4. Information Sharing</h3>
            <p>We do not sell, trade, or rent your personal information to third parties. We may share your information with:</p>
            <ul>
                <li>Our dental staff and service providers who need access to perform their duties</li>
                <li>Legal authorities when required by law</li>
            </ul>

            <h3>5. Your Rights</h3>
            <p>You have the right to:</p>
            <ul>
                <li>Access the personal information we hold about you</li>
                <li>Request corrections to inaccurate information</li>
                <li>Request deletion of your information</li>
                <li>Withdraw consent at any time</li>
            </ul>

            <h3>6. Data Retention</h3>
            <p>We retain your personal information only for as long as necessary to fulfill the purposes outlined in this policy, unless a longer retention period is required or permitted by law.</p>

            <h3>7. Cookies</h3>
            <p>Our website uses cookies to enhance your experience. You can control cookie settings through your browser preferences.</p>

            <h3>8. Contact Us</h3>
            <p>If you have any questions about this Privacy Policy, please contact us at:</p>
            <p><strong>Email:</strong> <?= h($settings['email']) ?><br>
            <strong>Phone:</strong> <?= h($settings['phone']) ?></p>

            <p><em>Last updated: <?= date('F d, Y') ?></em></p>
        </div>

        <div class="modal-footer-actions">
            <!-- Checkbox inside the modal -->
           <div class="modal-checkbox-group-inner" id="privacyModalGroup">
    <label class="modal-checkbox-item">
        <input type="checkbox" id="privacyModalCheckbox" onchange="syncPrivacyCheckbox()">
        <span class="custom-checkbox">
            <i class="fas fa-check"></i>
        </span>
        <span class="checkbox-label">
            I have read and agree to the Privacy Policy 
            <span class="required">*</span>
        </span>
    </label>
</div>
            <div class="modal-footer-buttons">
                <button class="btn-modal-secondary" onclick="closeModal('privacyModal')">Close</button>
                <button class="btn-modal-primary" onclick="closeModal('privacyModal')">
                    <i class="fas fa-check mr-1"></i> I Understand
                </button>
            </div>
        </div>
    </div>
</div>


<?php if (!empty($floatingAnnouncements)): ?>
<!-- ====== FLOATING ANNOUNCEMENTS ====== -->
<div id="floatingAnnouncements" class="floating-announcements">
    <?php foreach ($floatingAnnouncements as $index => $a): ?>
    <div class="floating-announcement <?= $index === 0 ? 'active' : '' ?>" 
         data-announcement-id="<?= (int)$a['id'] ?>"
         data-index="<?= $index ?>">
        <button class="floating-close" onclick="dismissFloatingAnnouncement(<?= (int)$a['id'] ?>)">
            <i class="fas fa-times"></i>
        </button>
        <div class="floating-media">
            <?php if (!empty($a['image'])): ?>
                <img src="<?= h($a['image']) ?>" alt="<?= h($a['title']) ?>" loading="lazy">
            <?php else: ?>
                <div class="floating-media-placeholder type-<?= h($a['type']) ?>">
                    <i class="fas <?= h($a['icon'] ?: 'fa-bullhorn') ?>"></i>
                </div>
            <?php endif; ?>
            <span class="floating-tag tag-<?= h($a['type']) ?>">
                <i class="fas <?= h($a['icon'] ?: 'fa-bullhorn') ?>"></i>
                <?= h(ucfirst($a['type'])) ?>
            </span>
        </div>
        <div class="floating-body">
            <h4 class="floating-title"><?= h($a['title']) ?></h4>
            <p class="floating-message"><?= h($a['message']) ?></p>
            <?php if (!empty($a['cta_text'])): ?>
                <a href="<?= h($a['cta_link'] ?: '#') ?>" class="floating-cta"
                   <?= (isset($a['cta_link']) && str_starts_with($a['cta_link'], '#booking')) ? 'onclick="openModal(\'bookingModal\'); return false;"' : '' ?>>
                    <?= h($a['cta_text']) ?> <i class="fas fa-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (count($floatingAnnouncements) > 1): ?>
    <div class="floating-dots">
        <?php foreach ($floatingAnnouncements as $i => $a): ?>
            <button class="floating-dot <?= $i === 0 ? 'active' : '' ?>" 
                    onclick="showFloatingAnnouncement(<?= $i ?>)"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ====== CHAT BOX ====== -->
<button class="chat-toggle" id="chatToggle" onclick="toggleChat()">
    <i class="fas fa-comment-dots"></i>
    <span class="unread-badge" id="unreadBadge">0</span>
</button>

<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <div class="chat-header-left">
            <div class="chat-avatar">
                <i class="fas fa-tooth"></i>
            </div>
            <div class="chat-header-info">
                <h4>Dental Support</h4>
                <span class="status">
                    <span class="dot" id="chatStatusDot"></span>
                    <span id="chatStatusText">Online</span>
                </span>
            </div>
        </div>
        <div class="chat-actions">
            <button onclick="clearChat()" title="Clear chat">
                <i class="fas fa-eraser"></i>
            </button>
            <button class="chat-close" onclick="toggleChat()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    
    <div class="chat-mode-toggle">
        <button class="active" data-mode="hybrid" onclick="setChatMode('hybrid')">
            <i class="fas fa-robot"></i> Hybrid
        </button>
        <button data-mode="bot" onclick="setChatMode('bot')">
            <i class="fas fa-robot"></i> Bot
        </button>
        <button data-mode="human" onclick="setChatMode('human')">
            <i class="fas fa-user"></i> Staff
        </button>
    </div>
    
    <div class="chat-messages" id="chatMessages">
        <div class="message bot">
            <span class="msg-sender"><i class="fas fa-robot"></i> Assistant</span>
            Hello! 👋 Welcome to our dental clinic. How can I help you today?
            <span class="msg-time">Just now</span>
        </div>
    </div>

    <button class="scroll-to-bottom-btn" id="scrollToBottomBtn" onclick="scrollToBottom()">
    <i class="fas fa-arrow-down"></i>
    <span class="new-msg-badge" id="newMsgBadge" style="display:none;">0</span>
</button>
    <div class="chat-quick-replies" id="chatQuickReplies"></div>
    <div class="chat-footer">
        <textarea id="chatInput" placeholder="Type your message..." rows="1" onkeydown="handleChatKey(event)"></textarea>
        <button class="send-btn" id="sendBtn" onclick="sendChatMessage()">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ====================================================================
// STATE
// ====================================================================
let bookingData = null;
let otpTimerInterval = null;
let otpResendTimer = null;
let otpSeconds = 300;



// ====================================================================
// BOOKING MODAL: LOCKED +63 PREFIX
// ====================================================================
const MODAL_PHONE_PREFIX = '+63';

function getBookingFullPhone() {
    const digits = document.getElementById('phoneNumber').value.replace(/\D/g, '');
    if (!digits) return '';
    return MODAL_PHONE_PREFIX + digits;
}

function validateBookingPhoneLocal() {
    const digits = document.getElementById('phoneNumber').value.replace(/\D/g, '');

    if (!digits) {
        return { valid: false, message: 'Please enter the 10 digits after +63.', normalized: '' };
    }
    if (digits.length !== 10) {
        return { valid: false, message: `Enter exactly 10 digits after +63 (you typed ${digits.length}).`, normalized: '' };
    }
    if (!digits.startsWith('9')) {
        return { valid: false, message: 'The number after +63 must start with 9.', normalized: '' };
    }
    return { valid: true, message: '✓ Valid number', normalized: MODAL_PHONE_PREFIX + digits };
}

function updateModalPhoneFeedback() {
    const wrap = document.getElementById('modalPhoneWrap');
    const hint = document.getElementById('modalPhoneHint');
    const input = document.getElementById('phoneNumber');
    if (!wrap || !hint || !input) return;

    wrap.classList.remove('is-valid', 'is-invalid');

    if (!input.value) {
        hint.textContent = 'Enter the 10 digits after +63 (starting with 9).';
        hint.style.color = '#718096';
        return;
    }
    const r = validateBookingPhoneLocal();
    hint.textContent = r.message;
    hint.style.color = r.valid ? 'var(--teal)' : '#e53e3e';
    wrap.classList.add(r.valid ? 'is-valid' : 'is-invalid');
}
// ====================================================================
// MOBILE MENU
// ====================================================================
document.getElementById('menuToggle').addEventListener('click', function() {
    document.getElementById('mobileMenu').classList.toggle('hidden');
});

// ====================================================================
// MODAL OPEN / CLOSE
// ====================================================================
function openModal(modalId) {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    // Reset the phone field when opening the booking modal
    if (modalId === 'bookingModal') {
        const inp = document.getElementById('phoneNumber');
        if (inp) {
            inp.value = '';
            updateModalPhoneFeedback();
        }
    }
}

function openTermsModal() {
    document.getElementById('termsModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function openPrivacyModal() {
    document.getElementById('privacyModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        if (!document.querySelector('.modal-overlay.active')) {
            document.body.style.overflow = '';
        }
    }
}

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        // OTP modal gets a confirmation instead of closing outright
        if (e.target.id === 'otpModal') return;
        e.target.classList.remove('active');
        if (!document.querySelector('.modal-overlay.active')) {
            document.body.style.overflow = '';
        }
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay') && e.target.id === 'otpModal') {
        Swal.fire({
            title: 'Cancel Verification?',
            text: 'Your appointment will not be confirmed if you cancel.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e53e3e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, cancel',
            cancelButtonText: 'Continue verifying'
        }).then((result) => {
            if (result.isConfirmed) {
                closeModal('otpModal');
                if (otpTimerInterval) clearInterval(otpTimerInterval);
                if (otpResendTimer) clearInterval(otpResendTimer);
                bookingData = null;
            }
        });
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;

    const openOtp = document.getElementById('otpModal').classList.contains('active');
    if (openOtp) {
        Swal.fire({
            title: 'Cancel Verification?',
            text: 'Your appointment will not be confirmed if you cancel.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e53e3e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, cancel',
            cancelButtonText: 'Continue verifying'
        }).then((result) => {
            if (result.isConfirmed) {
                closeModal('otpModal');
                if (otpTimerInterval) clearInterval(otpTimerInterval);
                if (otpResendTimer) clearInterval(otpResendTimer);
                bookingData = null;
            }
        });
        return;
    }

    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    document.body.style.overflow = '';
});

// ====================================================================
// TERMS / PRIVACY CHECKBOX SYNC (booking + register + standalone modals)
// ====================================================================
function syncTermsCheckbox() {
    const checked = document.getElementById('termsModalCheckbox').checked;

    const bookingCb = document.getElementById('termsCheckbox');
    if (bookingCb) {
        bookingCb.checked = checked;
        const g = document.getElementById('termsGroup');
        g.classList.remove('error', 'valid');
        if (checked) g.classList.add('valid');
    }

    const registerCb = document.getElementById('registerTermsCheckbox');
    if (registerCb) {
        registerCb.checked = checked;
        const g = document.getElementById('registerTermsGroup');
        g.classList.remove('error', 'valid');
        if (checked) g.classList.add('valid');
    }
}

function syncPrivacyCheckbox() {
    const checked = document.getElementById('privacyModalCheckbox').checked;

    const bookingCb = document.getElementById('privacyCheckbox');
    if (bookingCb) {
        bookingCb.checked = checked;
        const g = document.getElementById('privacyGroup');
        g.classList.remove('error', 'valid');
        if (checked) g.classList.add('valid');
    }

    const registerCb = document.getElementById('registerPrivacyCheckbox');
    if (registerCb) {
        registerCb.checked = checked;
        const g = document.getElementById('registerPrivacyGroup');
        g.classList.remove('error', 'valid');
        if (checked) g.classList.add('valid');
    }
}

function validateCheckbox(checkboxId, groupId) {
    const checkbox = document.getElementById(checkboxId);
    const group = document.getElementById(groupId);
    group.classList.remove('error', 'valid');
    if (checkbox.checked) {
        group.classList.add('valid');
        return true;
    }
    return false;
}

function validateRegisterCheckbox(checkboxId, groupId) {
    return validateCheckbox(checkboxId, groupId);
}

// ====================================================================
// SERVICE SELECTION (booking modal)
// ====================================================================
function updateServiceSelection(checkbox) {
    checkbox.closest('.service-checkbox-item').classList.toggle('selected', checkbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('#serviceGroup input[type="checkbox"]:checked').length;
    document.getElementById('selectedCount').textContent = count + ' service' + (count !== 1 ? 's' : '') + ' selected';
}

// ====================================================================
// BOOKING FORM SUBMIT -> SEND OTP
// ====================================================================
function handleSubmit(e) {
    e.preventDefault();

    const phoneCheck = validateBookingPhoneLocal();
    const email = document.getElementById('emailAddress').value.trim();
    const selectedServices = document.querySelectorAll('#serviceGroup input[type="checkbox"]:checked');
    const termsChecked = document.getElementById('termsCheckbox').checked;
    const privacyChecked = document.getElementById('privacyCheckbox').checked;

    document.querySelectorAll('.modal-checkbox-group').forEach(g => g.classList.remove('error'));

    // Phone (locked +63 prefix + 10 digits)
    if (!phoneCheck.valid) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Contact Number',
            text: phoneCheck.message.replace('✓ ', ''),
            confirmButtonColor: '#0d9e8c'
        });
        document.getElementById('phoneNumber').focus();
        return;
    }

    // Email
    if (!email) {
        Swal.fire({ icon: 'warning', title: 'Email Required', text: 'Please enter your email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('emailAddress').focus();
        return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('emailAddress').focus();
        return;
    }

    // Services
    if (selectedServices.length === 0) {
        Swal.fire({ icon: 'warning', title: 'No Service Selected', text: 'Please select at least one service.', confirmButtonColor: '#0d9e8c' });
        return;
    }

    // Terms
    if (!termsChecked) {
        document.getElementById('termsGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Terms & Conditions Required', text: 'Please agree to the Terms & Conditions.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('termsCheckbox').focus();
        return;
    }
    if (!privacyChecked) {
        document.getElementById('privacyGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Privacy Policy Required', text: 'Please agree to the Privacy Policy.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('privacyCheckbox').focus();
        return;
    }

    const formattedPhone = phoneCheck.normalized;
    const serviceIds = Array.from(selectedServices).map(cb => cb.value);

    bookingData = {
        phone: formattedPhone,
        email: email,
        services: serviceIds,
        termsAccepted: termsChecked,
        privacyAccepted: privacyChecked
    };

    const submitBtn = document.getElementById('bookingSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    submitBtn.disabled = true;

    sendOTP(formattedPhone, email, {
        services: serviceIds,
        termsAccepted: termsChecked,
        privacyAccepted: privacyChecked
    }).then(result => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (result.success) {
            closeModal('bookingModal');
            setTimeout(() => openOTPModal(formattedPhone, email), 300);
        } else {
            Swal.fire({ icon: 'error', title: 'Failed to Send OTP', text: result.message || 'Please try again.', confirmButtonColor: '#e53e3e' });
        }
    }).catch(error => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred: ' + error.message, confirmButtonColor: '#e53e3e' });
    });
}

// ====================================================================
// OTP INPUT HANDLING
// ====================================================================
function moveToNext(current, nextId) {
    if (current.value.length === 1) {
        if (nextId) document.getElementById(nextId).focus();
        document.getElementById('otpError').textContent = '';
    }
}

function handleBackspace(event, prevId) {
    if (event.key === 'Backspace' && event.target.value === '' && prevId) {
        document.getElementById(prevId).focus();
    }
}

function getOTPCode() {
    let code = '';
    for (let i = 1; i <= 6; i++) code += document.getElementById('otp' + i).value;
    return code;
}

function clearOTPInputs() {
    for (let i = 1; i <= 6; i++) {
        const el = document.getElementById('otp' + i);
        el.value = '';
        el.classList.remove('success', 'error');
    }
    document.getElementById('otp1').focus();
    document.getElementById('otpError').textContent = '';
}

function startOTPTimer() {
    otpSeconds = 300;
    updateTimerDisplay();
    if (otpTimerInterval) clearInterval(otpTimerInterval);

    otpTimerInterval = setInterval(function() {
        otpSeconds--;
        updateTimerDisplay();
        if (otpSeconds <= 0) {
            clearInterval(otpTimerInterval);
            document.getElementById('otpError').textContent = '⚠️ OTP has expired. Please request a new one.';
            document.getElementById('otpError').style.color = '#e53e3e';
            document.getElementById('verifyBtn').disabled = true;
            document.getElementById('verifyBtn').style.opacity = '0.5';
            document.getElementById('resendBtn').style.display = 'inline';
        }
    }, 1000);
}

function updateTimerDisplay() {
    const minutes = Math.floor(otpSeconds / 60);
    const seconds = otpSeconds % 60;
    document.getElementById('otpTimer').textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

function startResendTimer() {
    let seconds = 60;
    const resendBtn = document.getElementById('resendBtn');
    const resendTimer = document.getElementById('resendTimer');

    resendBtn.style.display = 'none';
    resendTimer.style.display = 'inline';
    resendTimer.textContent = 'Wait ' + seconds + 's';

    if (otpResendTimer) clearInterval(otpResendTimer);
    otpResendTimer = setInterval(function() {
        seconds--;
        resendTimer.textContent = 'Wait ' + seconds + 's';
        if (seconds <= 0) {
            clearInterval(otpResendTimer);
            resendBtn.style.display = 'inline';
            resendTimer.style.display = 'none';
        }
    }, 1000);
}

// ====================================================================
// API CALLS
// ====================================================================

// extra = { services, termsAccepted, privacyAccepted } on the initial send,
// or {} on a plain resend. Everything in extra gets merged into the request
// body that api/send_otp.php reads into $_SESSION['pending_booking'].
async function sendOTP(phone, email, extra = {}) {
    try {
        const payload = { phone, email, ...extra };
        const response = await fetch('api/send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const responseText = await response.text();
        try {
            return JSON.parse(responseText);
        } catch (parseError) {
            console.error('Failed to parse JSON:', parseError, responseText);
            return { success: false, message: 'Server returned invalid response. Please try again.' };
        }
    } catch (error) {
        console.error('Send OTP error:', error);
        return { success: false, message: 'Failed to send OTP: ' + error.message };
    }
}

async function verifyOTP(phone, email, otp) {
    try {
        const response = await fetch('api/verify_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, email, otp })
        });
        return await response.json();
    } catch (error) {
        return { success: false, message: 'Failed to verify OTP' };
    }
}

// ====================================================================
// OTP MODAL FLOW
// ====================================================================
function openOTPModal(phone, email) {
    clearOTPInputs();
    document.getElementById('verifyBtn').disabled = false;
    document.getElementById('verifyBtn').style.opacity = '1';
    document.getElementById('resendBtn').style.display = 'none';
    document.getElementById('resendTimer').style.display = 'none';
    startOTPTimer();
    openModal('otpModal');
}

async function handleOTPSubmit(e) {
    e.preventDefault();

    const otp = getOTPCode();
    const errorDiv = document.getElementById('otpError');

    if (otp.length < 6) {
        errorDiv.textContent = '⚠️ Please enter the complete 6-digit code.';
        errorDiv.style.color = '#e53e3e';
        return;
    }

    errorDiv.textContent = 'Verifying...';
    errorDiv.style.color = '#0d9e8c';

    const verifyResult = await verifyOTP(bookingData.phone, bookingData.email, otp);

    if (verifyResult.success) {
        errorDiv.textContent = '✅ OTP verified successfully!';
        errorDiv.style.color = '#0d9e8c';
        for (let i = 1; i <= 6; i++) document.getElementById('otp' + i).classList.add('success');
        document.getElementById('verifyBtn').disabled = true;
        document.getElementById('verifyBtn').style.opacity = '0.5';
        if (otpTimerInterval) clearInterval(otpTimerInterval);

        // Nothing is saved to the DB here. The server already moved the
        // verified booking (including services) into $_SESSION['booking_data'].
        // schedule-appointment.php reads that session and is where the
        // actual appointment gets created once the patient finishes.
        closeModal('otpModal');
        bookingData = null;
        window.location.href = verifyResult.redirect || 'schedule-appointment.php';
    } else {
        errorDiv.textContent = '❌ ' + verifyResult.message;
        errorDiv.style.color = '#e53e3e';
        for (let i = 1; i <= 6; i++) {
            document.getElementById('otp' + i).classList.add('error');
            setTimeout(() => document.getElementById('otp' + i).classList.remove('error'), 3000);
        }
    }
}

async function resendOTPCode() {
    const errorDiv = document.getElementById('otpError');
    errorDiv.textContent = 'Sending new OTP...';
    errorDiv.style.color = '#0d9e8c';

    const result = await sendOTP(bookingData.phone, bookingData.email, {
        services: bookingData.services,
        termsAccepted: bookingData.termsAccepted,
        privacyAccepted: bookingData.privacyAccepted
    });

    if (result.success) {
        errorDiv.textContent = '✅ New OTP sent successfully!';
        errorDiv.style.color = '#0d9e8c';
        clearOTPInputs();
        document.getElementById('verifyBtn').disabled = false;
        document.getElementById('verifyBtn').style.opacity = '1';
        startOTPTimer();
        startResendTimer();
    } else {
        errorDiv.textContent = '❌ ' + result.message;
        errorDiv.style.color = '#e53e3e';
    }
}

// ====================================================================
// LOGIN / REGISTER
// ====================================================================
function openLoginModal() {
    closeModal('registerModal');
    openModal('loginModal');
    document.getElementById('loginForm').reset();
}

function openRegisterModal() {
    closeModal('loginModal');
    openModal('registerModal');
    document.getElementById('registerForm').reset();
}

function togglePasswordVisibility() {
    const input = document.getElementById('loginPassword');
    const icon = document.getElementById('passwordToggleIcon');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

function toggleRegisterPasswordVisibility() {
    const input = document.getElementById('registerPassword');
    const icon = document.getElementById('registerPasswordToggleIcon');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}
function showForgotPassword() {
    Swal.fire({
        title: 'Reset Password',
        html: `
            <p style="font-size: 14px; color: #64748b; margin-bottom: 12px;">
                Enter your email address and we'll send you a link to reset your password.
            </p>
            <input type="email" id="resetEmail" class="modal-form-input"
                   placeholder="youremail@example.com" style="width: 100%;">
        `,
        confirmButtonColor: '#0d9e8c',
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Send Reset Link',
        showCancelButton: true,
        cancelButtonColor: '#64748b',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: async () => {
            const emailInput = document.getElementById('resetEmail');
            const email = emailInput.value.trim();

            // ✅ Empty check
            if (!email) {
                Swal.showValidationMessage('Please enter your email address');
                emailInput.focus();
                return false;
            }

            // ✅ Format check
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                Swal.showValidationMessage('Please enter a valid email address (e.g. name@example.com)');
                emailInput.focus();
                return false;
            }

            // ✅ Call API
            try {
                const res = await fetch('api/forgot_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email })
                });

                if (!res.ok) {
                    throw new Error('Server responded with status ' + res.status);
                }

                const data = await res.json();
                console.log('Forgot password response:', data);

                // If server says it failed, throw so Swal keeps the modal open
                if (!data.success) {
                    throw new Error(data.message || 'Could not send reset email.');
                }

                return data;
            } catch (err) {
                Swal.showValidationMessage(err.message || 'Something went wrong. Please try again.');
                return false;
            }
        }
    }).then((result) => {
        // Only reached when preConfirm returns a truthy value
        if (!result.isConfirmed) return;

        Swal.fire({
            icon: 'success',
            title: 'Check Your Email! 📧',
            html: `
                <p style="font-size: 14px; color: #475569;">
                    ${result.value.message || "We've sent a password reset link to your email."}
                </p>
                <p style="font-size: 12px; color: #94a3b8; margin-top: 8px;">
                    Don't see it? Check your spam folder.<br>The link expires in 30 minutes.
                </p>
            `,
            confirmButtonColor: '#0d9e8c',
            confirmButtonText: 'Got it'
        });
    });
}   

async function handleLoginSubmit(e) {
    e.preventDefault();

    const email = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value;
    const rememberMe = document.getElementById('rememberMe').checked;

    if (!email || !password) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Please enter your email and password.', confirmButtonColor: '#0d9e8c' });
        return;
    }

    const submitBtn = document.getElementById('loginSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
    submitBtn.disabled = true;

    try {
        const response = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, rememberMe })
        });
        const data = await response.json();

        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            closeModal('loginModal');
            Swal.fire({
                icon: 'success',
                title: 'Welcome back, ' + data.user.full_name + '!',
                text: 'You have been successfully logged in.',
                confirmButtonColor: '#0d9e8c',
                timer: 3000,
                timerProgressBar: true
            }).then(() => {
                window.location.href = data.redirect || window.location.href;
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Login Failed', text: data.message || 'Invalid email or password.', confirmButtonColor: '#e53e3e' });
        }
    } catch (error) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Something went wrong', text: 'Please try again later.', confirmButtonColor: '#e53e3e' });
    }
}
async function handleRegisterSubmit(e) {
    e.preventDefault();

    const fullName = document.getElementById('registerName').value.trim();
    const email = document.getElementById('registerEmail').value.trim();
    const phone = document.getElementById('registerPhone').value.trim();
    const password = document.getElementById('registerPassword').value;
    const confirmPassword = document.getElementById('registerConfirmPassword').value;
    const termsChecked = document.getElementById('registerTermsCheckbox').checked;
    const privacyChecked = document.getElementById('registerPrivacyCheckbox').checked;

    document.querySelectorAll('#registerForm .modal-checkbox-group').forEach(g => g.classList.remove('error'));

    if (!fullName) {
        Swal.fire({ icon: 'warning', title: 'Full Name Required', text: 'Please enter your full name.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerName').focus();
        return;
    }
    if (!email) {
        Swal.fire({ icon: 'warning', title: 'Email Required', text: 'Please enter your email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerEmail').focus();
        return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerEmail').focus();
        return;
    }
    if (!password) {
        Swal.fire({ icon: 'warning', title: 'Password Required', text: 'Please enter a password.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPassword').focus();
        return;
    }
    if (password.length < 8) {
        Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must be at least 8 characters.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPassword').focus();
        return;
    }
    if (password !== confirmPassword) {
        Swal.fire({ icon: 'warning', title: 'Password Mismatch', text: 'Passwords do not match.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerConfirmPassword').focus();
        return;
    }
    if (!termsChecked) {
        document.getElementById('registerTermsGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Terms & Conditions Required', text: 'Please agree to the Terms & Conditions.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerTermsCheckbox').focus();
        return;
    }
    if (!privacyChecked) {
        document.getElementById('registerPrivacyGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Privacy Policy Required', text: 'Please agree to the Privacy Policy.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPrivacyCheckbox').focus();
        return;
    }

    const submitBtn = document.getElementById('registerSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';
    submitBtn.disabled = true;

    try {
        const response = await fetch('api/register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                fullName, 
                email, 
                phone, 
                password, 
                termsAccepted: termsChecked, 
                privacyAccepted: privacyChecked 
            })
        });
        const data = await response.json();

        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            closeModal('registerModal');
            
            // 🔥 FIX: Auto-login after registration
            // First, try to login the user automatically
            try {
                const loginResponse = await fetch('api/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password, rememberMe: true })
                });
                const loginData = await loginResponse.json();
                
                if (loginData.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Account Created & Logged In! 🎉',
                        html: `
                            <p style="font-size: 14px; color: #475569;">Welcome, ${fullName}!</p>
                            <p style="font-size: 13px; color: var(--teal); margin-top: 4px;">
                                <i class="fas fa-check-circle"></i> You are now logged in
                            </p>
                            <div style="background: #f8fafc; padding: 10px; border-radius: 8px; margin-top: 10px;">
                                <p style="font-size: 12px; color: #64748b; margin: 0;">
                                    <strong>Email:</strong> ${email}
                                </p>
                            </div>
                        `,
                        confirmButtonColor: '#0d9e8c',
                        confirmButtonText: 'Continue to Dashboard',
                        timer: 5000,
                        timerProgressBar: true,
                        showCancelButton: true,
                        cancelButtonText: 'Stay Here'
                    }).then((result) => {
                        if (result.isConfirmed && loginData.redirect) {
                            window.location.href = loginData.redirect;
                        } else if (result.isConfirmed) {
                            window.location.href = 'index.php';
                        }
                    });
                } else {
                    // If auto-login fails, still show success but let user login manually
                    Swal.fire({
                        icon: 'success',
                        title: 'Account Created Successfully!',
                        text: 'Please login with your credentials.',
                        confirmButtonColor: '#0d9e8c',
                        confirmButtonText: 'Login Now'
                    }).then(() => {
                        openLoginModal();
                    });
                }
            } catch (loginError) {
                console.error('Auto-login failed:', loginError);
                Swal.fire({
                    icon: 'success',
                    title: 'Account Created Successfully!',
                    text: 'Please login with your credentials.',
                    confirmButtonColor: '#0d9e8c',
                    confirmButtonText: 'Login Now'
                }).then(() => {
                    openLoginModal();
                });
            }
        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'Registration Failed', 
                text: data.message || 'Please try again.', 
                confirmButtonColor: '#e53e3e' 
            });
        }
    } catch (error) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ 
            icon: 'error', 
            title: 'Something went wrong', 
            text: 'Please try again later.', 
            confirmButtonColor: '#e53e3e' 
        });
    }
}

async function logoutUser() {
    Swal.fire({
        title: 'Logout?',
        text: 'Are you sure you want to logout?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#e53e3e',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, logout',
        cancelButtonText: 'Cancel'
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        try {
            const response = await fetch('api/logout.php');
            const data = await response.json();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Logged Out', text: 'You have been logged out successfully.', confirmButtonColor: '#0d9e8c', timer: 2000 })
                    .then(() => location.reload());
            }
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Logout Failed', text: 'Please try again.', confirmButtonColor: '#e53e3e' });
        }
    });
}

// ====================================================================
// INIT
// ====================================================================
// SCROLL HELPERS
// ====================================================================
function isNearBottom(container, threshold = 60) {
    return container.scrollHeight - container.scrollTop - container.clientHeight < threshold;
}

function scrollToBottom(instant = false) {
    const container = document.getElementById('chatMessages');
    container.scrollTop = container.scrollHeight;
    const btn = document.getElementById('scrollToBottomBtn');
    btn.classList.remove('visible');
    document.getElementById('newMsgBadge').style.display = 'none';
    document.getElementById('newMsgBadge').textContent = '0';
}

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('chatMessages');
    container.addEventListener('scroll', function() {
        if (isNearBottom(container)) {
            document.getElementById('scrollToBottomBtn').classList.remove('visible');
            document.getElementById('newMsgBadge').style.display = 'none';
            document.getElementById('newMsgBadge').textContent = '0';
        }
    });
});
</script>

<script>
    // ====================================================================
// RETAIN FORM DATA USING GLOBAL VARIABLES
// ====================================================================

let retainedLoginData = { email: '', password: '', rememberMe: false };
let retainedRegisterData = { 
    name: '', email: '', phone: '', password: '', confirmPassword: '', 
    terms: false, privacy: false 
};

function saveLoginData() {
    retainedLoginData.email = document.getElementById('loginEmail').value;
    retainedLoginData.password = document.getElementById('loginPassword').value;
    retainedLoginData.rememberMe = document.getElementById('rememberMe').checked;
}

function saveRegisterData() {
    retainedRegisterData.name = document.getElementById('registerName').value;
    retainedRegisterData.email = document.getElementById('registerEmail').value;
    retainedRegisterData.phone = document.getElementById('registerPhone').value;
    retainedRegisterData.password = document.getElementById('registerPassword').value;
    retainedRegisterData.confirmPassword = document.getElementById('registerConfirmPassword').value;
    retainedRegisterData.terms = document.getElementById('registerTermsCheckbox').checked;
    retainedRegisterData.privacy = document.getElementById('registerPrivacyCheckbox').checked;
}

function restoreLoginData() {
    document.getElementById('loginEmail').value = retainedLoginData.email;
    document.getElementById('loginPassword').value = retainedLoginData.password;
    document.getElementById('rememberMe').checked = retainedLoginData.rememberMe;
}

function restoreRegisterData() {
    document.getElementById('registerName').value = retainedRegisterData.name;
    document.getElementById('registerEmail').value = retainedRegisterData.email;
    document.getElementById('registerPhone').value = retainedRegisterData.phone;
    document.getElementById('registerPassword').value = retainedRegisterData.password;
    document.getElementById('registerConfirmPassword').value = retainedRegisterData.confirmPassword;
    document.getElementById('registerTermsCheckbox').checked = retainedRegisterData.terms;
    document.getElementById('registerPrivacyCheckbox').checked = retainedRegisterData.privacy;
}

function clearRetainedData(type) {
    if (type === 'login') {
        retainedLoginData = { email: '', password: '', rememberMe: false };
    } else if (type === 'register') {
        retainedRegisterData = { 
            name: '', email: '', phone: '', password: '', confirmPassword: '', 
            terms: false, privacy: false 
        };
    }
}

// Auto-save on input
document.addEventListener('DOMContentLoaded', function() {
    ['loginEmail', 'loginPassword', 'rememberMe'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', saveLoginData);
    });
    
    ['registerName', 'registerEmail', 'registerPhone', 'registerPassword', 'registerConfirmPassword', 'registerTermsCheckbox', 'registerPrivacyCheckbox'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', saveRegisterData);
    });
});

// Modified open functions
function openLoginModal() {
    closeModal('registerModal');
    openModal('loginModal');
    restoreLoginData();
}

function openRegisterModal() {
    closeModal('loginModal');
    openModal('registerModal');
    restoreRegisterData();
}

// Modified close functions
function closeModal(modalId) {
    if (modalId === 'loginModal') saveLoginData();
    else if (modalId === 'registerModal') saveRegisterData();

    // Reset reCAPTCHA when closing register/booking modals
    if ((modalId === 'registerModal' || modalId === 'bookingModal') && typeof grecaptcha !== 'undefined') {
        try { grecaptcha.reset(); } catch (e) { /* ignore */ }
    }

    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        if (!document.querySelector('.modal-overlay.active')) {
            document.body.style.overflow = '';
        }
    }
}
</script>

<script>
    // ====================================================================
// CHAT BOX - GUEST VERSION (No Account Required)
// ====================================================================

let chatMode = 'hybrid';
let isProcessing = false;
let chatMessageCount = 0;
let guestId = localStorage.getItem('dental_guest_id') || null;
let lastSeenMsgId = 0;
let staffPollInterval = null;

function toggleChat() {
    const win = document.getElementById('chatWindow');
    win.classList.toggle('open');
    if (win.classList.contains('open')) {
        document.getElementById('chatInput').focus();
        const badge = document.getElementById('unreadBadge');
        badge.textContent = '0';
        badge.classList.remove('visible');
        scrollToBottom(true);
        trackChatEvent('opened');

        if (guestId) {
            fetch(`api/chat.php?action=mark_read&guest_id=${encodeURIComponent(guestId)}`)
                .catch(err => console.error('mark_read failed:', err));
        }
    }
}

function setChatMode(mode) {
    chatMode = mode;
    document.querySelectorAll('.chat-mode-toggle button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });
    
    const modeNames = {
        'hybrid': 'Hybrid Mode (Smart Assistant)',
        'bot': 'Bot Mode (AI Only)',
        'human': 'Staff Mode (Messages forwarded to staff)'
    };
    
    const modeDescriptions = {
        'hybrid': 'I\'ll use my knowledge to help with dental inquiries.',
        'bot': 'I\'ll respond as an AI assistant.',
        'human': 'Messages will be forwarded to our staff for personal assistance.'
    };
    
    addChatMessage('bot', `🔄 Switched to **${modeNames[mode]}**\n\n${modeDescriptions[mode]}`);
    trackChatEvent('mode_change', mode);
}

function handleChatKey(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendChatMessage();
    }
}

async function sendChatMessage() {
    if (isProcessing) return;
    
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    // Clear input
    input.value = '';
    input.style.height = 'auto';
    
    // Add user message
    addChatMessage('user', message);
    trackChatEvent('message_sent', message);
    
    // Show typing indicator
    showTypingIndicator(true);
    isProcessing = true;
    document.getElementById('sendBtn').disabled = true;
    
    try {
        const response = await fetch('api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                message: message, 
                mode: chatMode,
                guest_id: guestId
            })
        });
        
        const data = await response.json();
        
        // Store guest ID if returned
        if (data.guest_id) {
            guestId = data.guest_id;
            localStorage.setItem('dental_guest_id', guestId);
        }
        
        showTypingIndicator(false);
        
        if (data.success) {
            addChatMessage('bot', data.response);
            
            // Play sound for new message
            if (chatMode !== 'human') {
                playNotificationSound();
            }
        } else {
            addChatMessage('bot', '❌ Sorry, I encountered an error. Please try again or contact the clinic directly.');
        }
    } catch (error) {
        console.error('Chat error:', error);
        showTypingIndicator(false);
        addChatMessage('bot', '❌ Oops! Something went wrong. Please try again later or call us at ' + getClinicPhoneFromSettings());
    }
    
    isProcessing = false;
    document.getElementById('sendBtn').disabled = false;
}

function addChatMessage(sender, message, isHistoryReplay = false) {
    const container = document.getElementById('chatMessages');
    const emptyMsg = container.querySelector('.empty-chat');
    if (emptyMsg) emptyMsg.remove();

    const wasNearBottom = isNearBottom(container);

    const div = document.createElement('div');
    div.className = `message ${sender}`;

    const time = new Date();
    const timeStr = time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    let formattedMessage = message
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>')
        .replace(/• /g, '• ');

    let senderLabel = '';
    if (sender === 'bot') {
        senderLabel = '<span class="msg-sender"><i class="fas fa-robot"></i> Assistant</span>';
    } else if (sender === 'staff') {
        senderLabel = '<span class="msg-sender"><i class="fas fa-user-md"></i> Staff</span>';
    } else if (sender === 'user') {
        senderLabel = '<span class="msg-sender"><i class="fas fa-user"></i> You</span>';
    }

    if (sender === 'bot' && !message.match(/[😊👋🏥🦷💰📅📞📍📋]/)) {
        let emoji = '💬';
        if (message.toLowerCase().includes('service')) emoji = '🦷';
        else if (message.toLowerCase().includes('price') || message.toLowerCase().includes('cost')) emoji = '💰';
        else if (message.toLowerCase().includes('hour') || message.toLowerCase().includes('open')) emoji = '🏥';
        else if (message.toLowerCase().includes('book') || message.toLowerCase().includes('appointment')) emoji = '📅';
        else if (message.toLowerCase().includes('contact') || message.toLowerCase().includes('phone')) emoji = '📞';
        else if (message.toLowerCase().includes('location') || message.toLowerCase().includes('address')) emoji = '📍';

        const lines = formattedMessage.split('<br>');
        if (lines.length > 0 && !lines[0].startsWith('•')) {
            lines[0] = emoji + ' ' + lines[0];
            formattedMessage = lines.join('<br>');
        }
    }

    div.innerHTML = `${senderLabel}${formattedMessage}<span class="msg-time">${timeStr}</span>`;
    container.appendChild(div);

    if (isHistoryReplay) {
        // Don't touch scroll or badge state per-message while rebuilding history —
        // the caller handles scrolling and badge count once, after the whole loop.
        chatMessageCount++;
        return;
    }

    if (sender === 'user' || wasNearBottom) {
        container.scrollTop = container.scrollHeight;
    } else {
        const btn = document.getElementById('scrollToBottomBtn');
        const badge = document.getElementById('newMsgBadge');
        btn.classList.add('visible');
        const count = (parseInt(badge.textContent) || 0) + 1;
        badge.textContent = count;
        badge.style.display = 'flex';
    }

    chatMessageCount++;

    if (!document.getElementById('chatWindow').classList.contains('open')) {
        const badge = document.getElementById('unreadBadge');
        const currentCount = parseInt(badge.textContent) || 0;
        badge.textContent = currentCount + 1;
        badge.classList.add('visible');
    }
}

function showTypingIndicator(show) {
    let indicator = document.querySelector('.typing-indicator');
    
    if (!indicator) {
        const container = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.className = 'typing-indicator';
        div.innerHTML = `
            <span></span>
            <span></span>
            <span></span>
        `;
        container.appendChild(div);
        indicator = div;
    }
    
    indicator.classList.toggle('active', show);
    if (show) {
        document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
    }
}

async function clearChat() {
    const container = document.getElementById('chatMessages');
    container.innerHTML = '';

    // Reset local tracking state
    lastSeenMsgId = 0;
    chatMessageCount = 0;

    // Delete the guest's history server-side too, so a refresh won't restore it
    if (guestId) {
        try {
            await fetch(`api/chat.php?action=clear&guest_id=${encodeURIComponent(guestId)}`);
        } catch (err) {
            console.error('Failed to clear chat history on server:', err);
        }
    }

    // Show welcome message + quick replies again
    addChatMessage('bot', 'Hello! 👋 Welcome to our dental clinic.\n\nI\'m here to help you with:\n• 📋 Services & treatments\n• 💰 Price inquiries\n• 🏥 Operating hours\n• 📍 Location & directions\n• 📞 Contact information\n• 📅 Booking appointments\n\nWhat would you like to know? 😊');
    addQuickReplies();

    document.getElementById('unreadBadge').textContent = '0';
    document.getElementById('unreadBadge').classList.remove('visible');
    trackChatEvent('cleared');
}

function playNotificationSound() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        gainNode.gain.value = 0.08;
        
        oscillator.start();
        setTimeout(() => oscillator.stop(), 150);
    } catch (e) {
        // Silent fail
    }
}

function getClinicPhoneFromSettings() {
    const phoneElement = document.querySelector('.info-row .info-value');
    if (phoneElement) {
        return phoneElement.textContent.trim() || '(02) 1234 5678';
    }
    return '(02) 1234 5678';
}

// Track chat events for analytics (optional)
function trackChatEvent(event, data = null) {
    console.log('Chat Event:', event, data);
}

// ====================================================================
// STAFF-REPLY POLLING (new)
// ====================================================================
function pollStaffReplies() {
    if (!guestId) return;
    fetch(`api/chat.php?action=poll&guest_id=${encodeURIComponent(guestId)}&after_id=${lastSeenMsgId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.messages || !data.messages.length) return;
            data.messages.forEach(m => {
                addChatMessage('staff', m.message);
                lastSeenMsgId = Math.max(lastSeenMsgId, parseInt(m.id));
            });
            if (!document.getElementById('chatWindow').classList.contains('open')) {
                playNotificationSound();
            }
        })
        .catch(() => { /* stay silent on transient network errors */ });
}

// Quick reply suggestions
function addQuickReplies() {
    const container = document.getElementById('chatQuickReplies');
    const suggestions = [
        'What services do you offer?',
        'How much is teeth cleaning?',
        'What are your hours?',
        'Where are you located?',
        'How do I book?'
    ];

    container.innerHTML = suggestions.map(text =>
        `<button onclick="quickReply('${text}')">${text}</button>`
    ).join('');
}

function quickReply(text) {
    document.getElementById('chatInput').value = text;
    sendChatMessage();
}
// Replace your existing DOMContentLoaded listener (the one with the auto-resize
// textarea + welcome message + quick replies) with this version:

document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('chatInput');
    if (input) {
        input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
    }

if (guestId) {
        fetch(`api/chat.php?action=history&guest_id=${encodeURIComponent(guestId)}`)
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('chatMessages');
                if (data.success && data.messages && data.messages.length) {
                    container.innerHTML = '';
                    let unreadCount = 0;
                    data.messages.forEach(m => {
                        const sender = m.sender === 'guest' ? 'user' : (m.sender === 'admin' ? 'staff' : m.sender);
                        addChatMessage(sender, m.message, true);
                        lastSeenMsgId = Math.max(lastSeenMsgId, parseInt(m.id));
                        if (sender !== 'user' && parseInt(m.is_read) === 0) unreadCount++;
                    });

                    container.scrollTop = container.scrollHeight;

                    if (!document.getElementById('chatWindow').classList.contains('open') && unreadCount > 0) {
                        const badge = document.getElementById('unreadBadge');
                        badge.textContent = unreadCount;
                        badge.classList.add('visible');
                    } else {
                        document.getElementById('unreadBadge').classList.remove('visible');
                    }
                } else {
                    showWelcome();
                }
            })
            .catch(() => showWelcome());
    } else {
        showWelcome();
    }

    // Start polling for staff replies (every 5s)
    staffPollInterval = setInterval(pollStaffReplies, 5000);
});

function showWelcome() {
    setTimeout(() => {
        addChatMessage('bot', 'Hello! 👋 Welcome to our dental clinic.\n\nI\'m here to help you with:\n• 📋 Services & treatments\n• 💰 Price inquiries\n• 🏥 Operating hours\n• 📍 Location & directions\n• 📞 Contact information\n• 📅 Booking appointments\n\nWhat would you like to know? 😊');
        setTimeout(addQuickReplies, 500);
    }, 300);
}











// ====================================================================
// REGISTRATION WITH reCAPTCHA + OTP
// ====================================================================

let registrationData = null;
 
async function handleRegisterSubmit(e) {
    e.preventDefault();

    const fullName = document.getElementById('registerName').value.trim();
    const email = document.getElementById('registerEmail').value.trim();
    const phone = document.getElementById('registerPhone').value.trim();
    const password = document.getElementById('registerPassword').value;
    const confirmPassword = document.getElementById('registerConfirmPassword').value;
    const termsChecked = document.getElementById('registerTermsCheckbox').checked;
    const privacyChecked = document.getElementById('registerPrivacyCheckbox').checked;

    // Clear previous errors
    document.querySelectorAll('#registerForm .modal-checkbox-group').forEach(g => g.classList.remove('error'));

    // ===== VALIDATION =====
    if (!fullName) {
        Swal.fire({ icon: 'warning', title: 'Full Name Required', text: 'Please enter your full name.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerName').focus();
        return;
    }
    if (!email) {
        Swal.fire({ icon: 'warning', title: 'Email Required', text: 'Please enter your email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerEmail').focus();
        return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerEmail').focus();
        return;
    }
    if (!phone) {
        Swal.fire({ icon: 'warning', title: 'Phone Required', text: 'Please enter your phone number.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPhone').focus();
        return;
    }
    if (!password) {
        Swal.fire({ icon: 'warning', title: 'Password Required', text: 'Please enter a password.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPassword').focus();
        return;
    }
    if (password.length < 8) {
        Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must be at least 8 characters.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPassword').focus();
        return;
    }
    if (password !== confirmPassword) {
        Swal.fire({ icon: 'warning', title: 'Password Mismatch', text: 'Passwords do not match.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerConfirmPassword').focus();
        return;
    }
    if (!termsChecked) {
        document.getElementById('registerTermsGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Terms & Conditions Required', text: 'Please agree to the Terms & Conditions.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerTermsCheckbox').focus();
        return;
    }
    if (!privacyChecked) {
        document.getElementById('registerPrivacyGroup').classList.add('error');
        Swal.fire({ icon: 'warning', title: 'Privacy Policy Required', text: 'Please agree to the Privacy Policy.', confirmButtonColor: '#0d9e8c' });
        document.getElementById('registerPrivacyCheckbox').focus();
        return;
    }

    // ===== reCAPTCHA VERIFICATION =====
    const recaptchaResponse = grecaptcha.getResponse();
    if (!recaptchaResponse) {
        Swal.fire({
            icon: 'warning',
            title: 'reCAPTCHA Required',
            text: 'Please complete the "I\'m not a robot" verification.',
            confirmButtonColor: '#0d9e8c'
        });
        return;
    }

    // ===== STORE REGISTRATION DATA =====
    registrationData = {
        fullName: fullName,
        email: email,
        phone: phone,
        password: password,
        confirmPassword: confirmPassword,
        termsAccepted: termsChecked,
        privacyAccepted: privacyChecked,
        recaptchaToken: recaptchaResponse
    };

    const submitBtn = document.getElementById('registerSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending verification...';
    submitBtn.disabled = true;

    try {
      const response = await fetch('api/register_send_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(registrationData)
});

const responseText = await response.text();
let data;
try {
    data = JSON.parse(responseText);
} catch (parseErr) {
    console.error('RAW SERVER RESPONSE:', responseText);
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
    Swal.fire({
        icon: 'error',
        title: 'Server Error',
        text: 'Server returned invalid data. Check browser console (F12) for details.',
        confirmButtonColor: '#e53e3e'
    });
    grecaptcha.reset();
    return;
}

        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            // Close register modal and open OTP modal
            closeModal('registerModal');
            setTimeout(() => openRegistrationOTPModal(email, fullName), 300);
        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'Verification Failed', 
                text: data.message || 'Please try again.', 
                confirmButtonColor: '#e53e3e' 
            });
            grecaptcha.reset();
        }
    } catch (error) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ 
            icon: 'error', 
            title: 'Something went wrong', 
            text: 'Please try again later.', 
            confirmButtonColor: '#e53e3e' 
        });
        grecaptcha.reset();
    }
}

// ====================================================================
// REGISTRATION OTP MODAL
// ====================================================================

function openRegistrationOTPModal(email, fullName) {
    // Reuse the OTP modal
    document.querySelector('#otpModal .modal-title').textContent = 'Verify Your Email';
    document.querySelector('#otpModal .modal-subtitle').textContent = 
        `We've sent a verification code to ${email}`;
    
    clearOTPInputs();
    document.getElementById('verifyBtn').disabled = false;
    document.getElementById('verifyBtn').style.opacity = '1';
    document.getElementById('resendBtn').style.display = 'none';
    document.getElementById('resendTimer').style.display = 'none';
    
    // Change verify button text
    document.getElementById('verifyBtn').innerHTML = '<i class="fas fa-check-circle"></i> Verify & Create Account';
    
    // Store that this is for registration
    window._otpPurpose = 'registration';
    
    startOTPTimer();
    openModal('otpModal');
}

// ====================================================================
// OVERRIDE OTP VERIFY FOR REGISTRATION
// ====================================================================

// Replace your existing handleOTPSubmit with this updated version
async function handleOTPSubmit(e) {
    e.preventDefault();

    const otp = getOTPCode();
    const errorDiv = document.getElementById('otpError');

    if (otp.length < 6) {
        errorDiv.textContent = '⚠️ Please enter the complete 6-digit code.';
        errorDiv.style.color = '#e53e3e';
        return;
    }

    const purpose = window._otpPurpose || 'booking';
    
    if (purpose === 'registration') {
        // ===== REGISTRATION OTP VERIFICATION =====
        errorDiv.textContent = 'Verifying...';
        errorDiv.style.color = '#0d9e8c';
        
        try {
            const response = await fetch('api/register_verify_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    email: registrationData.email, 
                    otp: otp 
                })
            });
            
            const data = await response.json();

            if (data.success) {
                errorDiv.textContent = '✅ Account created successfully!';
                errorDiv.style.color = '#0d9e8c';
                for (let i = 1; i <= 6; i++) document.getElementById('otp' + i).classList.add('success');
                document.getElementById('verifyBtn').disabled = true;
                document.getElementById('verifyBtn').style.opacity = '0.5';
                if (otpTimerInterval) clearInterval(otpTimerInterval);
                
                closeModal('otpModal');
                
                Swal.fire({
                    icon: 'success',
                    title: '🎉 Welcome to Dental Clinic!',
                    html: `
                        <p style="font-size: 14px; color: #475569;">Your account has been created successfully!</p>
                        <p style="font-size: 13px; color: var(--teal); margin-top: 4px;">
                            <i class="fas fa-check-circle"></i> Welcome, ${data.user.full_name}!
                        </p>
                    `,
                    confirmButtonColor: '#0d9e8c',
                    timer: 3000,
                    timerProgressBar: true
                }).then(() => {
                    window.location.href = data.redirect || 'index.php';
                });
                
            } else {
                errorDiv.textContent = '❌ ' + data.message;
                errorDiv.style.color = '#e53e3e';
                for (let i = 1; i <= 6; i++) {
                    document.getElementById('otp' + i).classList.add('error');
                    setTimeout(() => document.getElementById('otp' + i).classList.remove('error'), 3000);
                }
            }
        } catch (error) {
            errorDiv.textContent = '❌ Verification failed. Please try again.';
            errorDiv.style.color = '#e53e3e';
        }
        
    } else {
        // ===== BOOKING OTP VERIFICATION (YOUR EXISTING CODE) =====
        errorDiv.textContent = 'Verifying...';
        errorDiv.style.color = '#0d9e8c';

        try {
            const verifyResult = await verifyOTP(bookingData.phone, bookingData.email, otp);

            if (verifyResult.success) {
                errorDiv.textContent = '✅ OTP verified successfully!';
                errorDiv.style.color = '#0d9e8c';
                for (let i = 1; i <= 6; i++) document.getElementById('otp' + i).classList.add('success');
                document.getElementById('verifyBtn').disabled = true;
                document.getElementById('verifyBtn').style.opacity = '0.5';
                if (otpTimerInterval) clearInterval(otpTimerInterval);

                closeModal('otpModal');
                bookingData = null;
                window.location.href = verifyResult.redirect || 'schedule-appointment.php';
            } else {
                errorDiv.textContent = '❌ ' + verifyResult.message;
                errorDiv.style.color = '#e53e3e';
                for (let i = 1; i <= 6; i++) {
                    document.getElementById('otp' + i).classList.add('error');
                    setTimeout(() => document.getElementById('otp' + i).classList.remove('error'), 3000);
                }
            }
        } catch (error) {
            errorDiv.textContent = '❌ Verification failed. Please try again.';
            errorDiv.style.color = '#e53e3e';
        }
    }
}

// ====================================================================
// OVERRIDE RESEND OTP FOR REGISTRATION
// ====================================================================

async function resendOTPCode() {
    const errorDiv = document.getElementById('otpError');
    errorDiv.textContent = 'Sending new code...';
    errorDiv.style.color = '#0d9e8c';
    
    const purpose = window._otpPurpose || 'booking';
    
    let url = '';
    let body = {};
    
    if (purpose === 'registration') {
        url = 'api/register_resend_otp.php';
        body = {};
    } else {
        url = 'api/resend_otp.php';
        body = { phone: bookingData.phone, email: bookingData.email };
    }
    
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const result = await response.json();

        if (result.success) {
            errorDiv.textContent = '✅ New code sent successfully!';
            errorDiv.style.color = '#0d9e8c';
            clearOTPInputs();
            document.getElementById('verifyBtn').disabled = false;
            document.getElementById('verifyBtn').style.opacity = '1';
            startOTPTimer();
            startResendTimer();
        } else {
            errorDiv.textContent = '❌ ' + result.message;
            errorDiv.style.color = '#e53e3e';
        }
    } catch (error) {
        errorDiv.textContent = '❌ Failed to resend code. Please try again.';
        errorDiv.style.color = '#e53e3e';
    }
}

// ====================================================================
// RESET reCAPTCHA ON MODAL CLOSE
// ====================================================================

function resetRecaptcha() {
    if (typeof grecaptcha !== 'undefined') {
        grecaptcha.reset();
    }
}

// Modified closeModal to reset reCAPTCHA
const originalCloseModal = closeModal;
closeModal = function(modalId) {
    if (modalId === 'registerModal' || modalId === 'bookingModal') {
        resetRecaptcha();
    }
    originalCloseModal(modalId);
};

 




</script>


<script>
// ---------- 1. BANNER BAR ----------
(function initBannerAnnouncement() {
    const bar = document.getElementById('announcementBar');
    if (!bar) return;

    // No storage check at all — always show on page load
    bar.classList.add('visible');

    const items = Array.from(bar.querySelectorAll('.announcement-item'));
    if (items.length === 0) return;

    let idx = 0;
    items[0].classList.add('active');

    if (items.length > 1) {
        let timer = setInterval(() => {
            items[idx].classList.remove('active');
            idx = (idx + 1) % items.length;
            items[idx].classList.add('active');
        }, 5000);

        bar.addEventListener('mouseenter', () => clearInterval(timer));
        bar.addEventListener('mouseleave', () => {
            timer = setInterval(() => {
                items[idx].classList.remove('active');
                idx = (idx + 1) % items.length;
                items[idx].classList.add('active');
            }, 5000);
        });
    }
})();

function dismissAnnouncement(which) {
    if (which === 'bar') {
        const bar = document.getElementById('announcementBar');
        if (!bar) return;
        bar.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
        bar.style.opacity = '0';
        bar.style.maxHeight = '0';
        bar.style.overflow = 'hidden';
        setTimeout(() => bar.remove(), 320);
        // No storage — page refresh brings it back
    }
}

// ---------- 2. FLOATING CARD ----------
let floatingIndex = 0;
let floatingTimer = null;

function getFloatingCards() {
    return Array.from(document.querySelectorAll('.floating-announcement'));
}

function showFloatingAnnouncement(i) {
    const cards = getFloatingCards();
    if (!cards.length) return;
    cards.forEach((c, n) => c.classList.toggle('active', n === i));
    document.querySelectorAll('.floating-dot').forEach((d, n) =>
        d.classList.toggle('active', n === i)
    );
    floatingIndex = i;
    resetFloatingTimer();
}

function resetFloatingTimer() {
    if (floatingTimer) clearInterval(floatingTimer);
    const cards = getFloatingCards();
    if (cards.length <= 1) return;
    floatingTimer = setInterval(() => {
        floatingIndex = (floatingIndex + 1) % cards.length;
        showFloatingAnnouncement(floatingIndex);
    }, 7000);
}

function dismissFloatingAnnouncement(id) {
    const el = document.querySelector(`.floating-announcement[data-announcement-id="${id}"]`);
    if (el) {
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        setTimeout(() => {
            el.remove();
            const remaining = getFloatingCards();
            if (remaining.length) {
                showFloatingAnnouncement(0);
            } else {
                const wrap = document.getElementById('floatingAnnouncements');
                if (wrap) wrap.remove();
            }
        }, 300);
    }
    // No storage — page refresh brings it back
}

// Show first floating card on load
(function initFloatingAnnouncements() {
    const cards = getFloatingCards();
    if (!cards.length) return;
    showFloatingAnnouncement(0);
})();




// ====================================================================
// BOOKING MODAL PHONE: MASK + LIVE FEEDBACK
// ====================================================================
(function initBookingPhone() {
    const input = document.getElementById('phoneNumber');
    if (!input) return;

    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '').slice(0, 10);
        updateModalPhoneFeedback();
    });
    input.addEventListener('blur', updateModalPhoneFeedback);

    updateModalPhoneFeedback();
})();




(function liveClinicStatus() {
    const badge = document.getElementById('clinicStatusBadge');
    if (!badge) return;

    const openT  = badge.dataset.open;
    const closeT = badge.dataset.close;
    const half   = badge.dataset.half === '1';
    const pageDate = badge.dataset.date;
    const textEl = badge.querySelector('.status-text');

    const dateFmt = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' });
    const timeFmt = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    });

    const to12h = t => {
        let [h, m] = t.split(':').map(Number);
        const ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${h}:${String(m).padStart(2, '0')} ${ap}`;
    };

    function setState(cls, label, title) {
        badge.classList.remove('open', 'closed', 'soon');
        badge.classList.add(cls);
        textEl.textContent = label;
        badge.title = title;
    }

    function tick() {
        if (dateFmt.format(new Date()) !== pageDate) { location.reload(); return; }
        if (!openT || !closeT) return;

        const now   = timeFmt.format(new Date());
        const hours = `${to12h(openT)} – ${to12h(closeT)}`;

        if (now < openT)        setState('soon', 'Opens at ' + to12h(openT), 'Today: ' + hours + (half ? ' (Half Day)' : ''));
        else if (now >= closeT) setState('closed', 'Closed for Today', "Today's hours were " + hours);
        else                    setState('open', half ? 'Open Now · Half Day' : 'Open Now', 'Open until ' + to12h(closeT));
    }

    tick();
    setInterval(tick, 30000);
})();
</script>














<!-- ====================================================================
     REVIEWS DRAWER (right side panel)
     ==================================================================== -->
<div id="reviewsDrawer" class="reviews-drawer">
    <!-- Overlay (click to close) -->
    <div class="reviews-drawer-overlay" onclick="closeReviewsDrawer()"></div>

    <!-- Drawer panel -->
    <aside class="reviews-drawer-panel">
        
        <!-- Header -->
        <header class="reviews-drawer-header">
            <div class="reviews-drawer-header-content">
                <div class="reviews-drawer-header-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div>
                    <h3 class="reviews-drawer-title">Patient Reviews</h3>
                    <p class="reviews-drawer-subtitle">
                        <?= number_format((float)$avgRating, 1) ?>/5 · <?= $totalFB ?> total
                    </p>
                </div>
            </div>
            <button type="button" 
                    class="reviews-drawer-close" 
                    onclick="closeReviewsDrawer()"
                    aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </header>

        <!-- Filter tabs -->
        <div class="reviews-drawer-tabs">
            <button type="button" class="reviews-tab active" data-filter="all" onclick="filterReviews('all', this)">
                All
            </button>
            <button type="button" class="reviews-tab" data-filter="5" onclick="filterReviews('5', this)">
                <i class="fas fa-star"></i> 5 Star
            </button>
            <button type="button" class="reviews-tab" data-filter="4" onclick="filterReviews('4', this)">
                <i class="fas fa-star"></i> 4 Star
            </button>
        </div>

        <!-- Body (scrollable list) -->
        <div class="reviews-drawer-body" id="reviewsDrawerBody">
            <div class="reviews-drawer-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading reviews...</p>
            </div>
        </div>

        <!-- Footer -->
        <footer class="reviews-drawer-footer">
            <div class="reviews-drawer-progress" id="reviewsDrawerProgress">
                Showing <strong>0</strong> reviews
            </div>
        </footer>
    </aside>
</div>

<style>
/* ====================================================================
   REVIEWS DRAWER
   ==================================================================== */
.reviews-drawer {
    position: fixed;
    inset: 0;
    z-index: 99998;
    pointer-events: none;
    visibility: hidden;
    transition: visibility 0s linear 0.35s;
}
.reviews-drawer.open {
    pointer-events: auto;
    visibility: visible;
    transition: visibility 0s linear 0s;
}

.reviews-drawer-overlay {
    position: absolute;
    inset: 0;
    background: rgba(11, 22, 40, 0.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 0;
    transition: opacity 0.35s ease;
}
.reviews-drawer.open .reviews-drawer-overlay {
    opacity: 1;
}

.reviews-drawer-panel {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 50%;
    max-width: 640px;
    min-width: 380px;
    background: #f8fafc;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.35s cubic-bezier(.4, 0, .2, 1);
    box-shadow: -24px 0 80px rgba(11, 22, 40, 0.25);
    overflow: hidden;
}
.reviews-drawer.open .reviews-drawer-panel {
    transform: translateX(0);
}

/* ----- Header ----- */
.reviews-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px;
    background: linear-gradient(135deg, var(--navy), var(--navy-mid));
    color: white;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}
.reviews-drawer-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(13,158,140,0.18) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.reviews-drawer-header-content {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}
.reviews-drawer-header-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: rgba(13, 158, 140, 0.18);
    color: var(--teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    border: 1px solid rgba(13, 158, 140, 0.3);
    flex-shrink: 0;
}
.reviews-drawer-title {
    font-family: var(--font-display);
    font-size: 20px;
    font-weight: 800;
    margin: 0;
    line-height: 1.2;
    color: white;
}
.reviews-drawer-subtitle {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    margin: 2px 0 0;
}
.reviews-drawer-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: white;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.25s ease;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.reviews-drawer-close:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: rotate(90deg);
}

/* ----- Filter tabs ----- */
.reviews-drawer-tabs {
    display: flex;
    gap: 8px;
    padding: 14px 24px;
    background: white;
    border-bottom: 1px solid #eef2f6;
    flex-shrink: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.reviews-drawer-tabs::-webkit-scrollbar { display: none; }

.reviews-tab {
    padding: 7px 16px;
    border-radius: 50px;
    border: 1.5px solid #e2e8f0;
    background: #fafbfc;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: inherit;
}
.reviews-tab i { font-size: 11px; color: #f59e0b; }
.reviews-tab:hover {
    border-color: var(--teal);
    background: var(--teal-light);
    color: var(--teal-dark);
}
.reviews-tab.active {
    background: var(--teal);
    border-color: var(--teal);
    color: white;
    box-shadow: 0 4px 12px rgba(13,158,140,0.28);
}
.reviews-tab.active i { color: white; }

/* ----- Body ----- */
.reviews-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    scroll-behavior: smooth;
}
.reviews-drawer-body::-webkit-scrollbar { width: 6px; }
.reviews-drawer-body::-webkit-scrollbar-track { background: transparent; }
.reviews-drawer-body::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 10px;
}
.reviews-drawer-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* Reuse feedback-card styles inside the drawer, but slightly tweaked */
.reviews-drawer-body .feedback-card {
    background: white;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #eef2f6;
    box-shadow: 0 2px 8px rgba(11,22,40,0.04);
    transition: all 0.25s ease;
    margin-bottom: 0;
}
.reviews-drawer-body .feedback-card:hover {
    box-shadow: 0 10px 28px rgba(13,158,140,0.1);
    border-color: rgba(13,158,140,0.35);
    transform: translateY(-2px);
}
.reviews-drawer-body .feedback-card::before {
    font-size: 60px;
    top: -8px;
    right: 16px;
}

.reviews-drawer-loading,
.reviews-drawer-empty {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
    font-size: 13px;
}
.reviews-drawer-loading i {
    font-size: 26px;
    color: var(--teal);
    margin-bottom: 10px;
    display: block;
}
.reviews-drawer-empty i {
    font-size: 42px;
    color: #d0d7e2;
    margin-bottom: 14px;
    display: block;
}

/* ----- Footer ----- */
.reviews-drawer-footer {
    padding: 14px 24px;
    background: white;
    border-top: 1px solid #eef2f6;
    flex-shrink: 0;
}
.reviews-drawer-progress {
    font-size: 11.5px;
    color: #94a3b8;
    text-align: center;
}
.reviews-drawer-progress strong { color: var(--navy); font-weight: 700; }

/* ----- Responsive: full width on mobile ----- */
@media (max-width: 768px) {
    .reviews-drawer-panel {
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }
    .reviews-drawer-header { padding: 18px 18px; }
    .reviews-drawer-title { font-size: 17px; }
    .reviews-drawer-tabs { padding: 12px 18px; }
    .reviews-drawer-body { padding: 16px 18px; }
    .reviews-drawer-footer { padding: 12px 18px; }
}
</style>

<script>
// ====================================================================
// REVIEWS DRAWER
// ====================================================================
const reviewsState = {
    offset: 0,
    limit: 8,
    filter: 'all',
    loading: false,
    done: false,
    total: 0
};

function openReviewsDrawer() {
    const drawer = document.getElementById('reviewsDrawer');
    drawer.classList.add('open');
    document.body.style.overflow = 'hidden';

    // Reset & load fresh
    reviewsState.offset = 0;
    reviewsState.filter = 'all';
    reviewsState.done = false;
    reviewsState.loading = false;

    // Reset tabs
    document.querySelectorAll('.reviews-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.filter === 'all');
    });

    const body = document.getElementById('reviewsDrawerBody');
    body.innerHTML = `
        <div class="reviews-drawer-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading reviews...</p>
        </div>`;

    loadReviews();
}

function closeReviewsDrawer() {
    const drawer = document.getElementById('reviewsDrawer');
    drawer.classList.remove('open');
    document.body.style.overflow = '';
}

// Close on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.getElementById('reviewsDrawer')?.classList.contains('open')) {
        closeReviewsDrawer();
    }
});

async function loadReviews() {
    if (reviewsState.loading || reviewsState.done) return;
    reviewsState.loading = true;

    const body = document.getElementById('reviewsDrawerBody');

    // Remove loading/empty placeholder on first load
    if (reviewsState.offset === 0) {
        body.querySelector('.reviews-drawer-loading')?.remove();
        body.querySelector('.reviews-drawer-empty')?.remove();
    }

    try {
        const params = new URLSearchParams({
            offset: reviewsState.offset,
            limit:  reviewsState.limit,
            filter: reviewsState.filter
        });
        const res  = await fetch(`api/load_reviews.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Failed to load');

        // First page — clear anything already there
        if (reviewsState.offset === 0) {
            body.innerHTML = '';
        }

        if (data.reviews.length === 0 && reviewsState.offset === 0) {
            body.innerHTML = `
                <div class="reviews-drawer-empty">
                    <i class="fas fa-comment-slash"></i>
                    <p>No reviews match this filter yet.</p>
                </div>`;
            reviewsState.done = true;
        } else {
            body.insertAdjacentHTML('beforeend', data.html);
            reviewsState.offset += data.reviews.length;
            if (reviewsState.offset >= data.total || data.reviews.length < reviewsState.limit) {
                reviewsState.done = true;
            }
        }

        reviewsState.total = data.total;
        updateDrawerProgress();

    } catch (e) {
        console.error('Reviews load error:', e);
        body.innerHTML = `
            <div class="reviews-drawer-empty">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Couldn't load reviews. Please try again.</p>
            </div>`;
        reviewsState.done = true;
    }

    reviewsState.loading = false;
}

function updateDrawerProgress() {
    const el = document.getElementById('reviewsDrawerProgress');
    const shown = Math.min(reviewsState.offset, reviewsState.total);
    if (reviewsState.total === 0) {
        el.innerHTML = 'No reviews yet';
    } else if (shown >= reviewsState.total) {
        el.innerHTML = `Showing all <strong>${reviewsState.total}</strong> review${reviewsState.total === 1 ? '' : 's'}`;
    } else {
        el.innerHTML = `Showing <strong>${shown}</strong> of <strong>${reviewsState.total}</strong> reviews · scroll for more`;
    }
}

// Infinite scroll
document.getElementById('reviewsDrawerBody')?.addEventListener('scroll', function () {
    const nearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 200;
    if (nearBottom && !reviewsState.loading && !reviewsState.done) {
        loadReviews();
    }
});

// Filter tabs
function filterReviews(filter, btn) {
    reviewsState.filter = filter;
    reviewsState.offset = 0;
    reviewsState.done = false;
    reviewsState.loading = false;

    document.querySelectorAll('.reviews-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    const body = document.getElementById('reviewsDrawerBody');
    body.innerHTML = `
        <div class="reviews-drawer-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Filtering...</p>
        </div>`;
    body.scrollTop = 0;

    loadReviews();
}
</script>
</body>
</html>