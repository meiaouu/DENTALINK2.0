<?php
require 'conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$settings = $conn->query("SELECT * FROM clinic_settings WHERE id = 1")->fetch_assoc();
$services = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);

function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function peso($amount) { return '₱' . number_format((float)$amount, 2); }

// Must arrive here with a verified booking already in session (set by api/verify_otp.php)
if (!isset($_SESSION['booking_data']) || empty($_SESSION['booking_data'])) {
    header('Location: index.php');
    exit;
}

$bookingData = $_SESSION['booking_data'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedule Your Visit - <?= h($settings['clinic_name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --navy: #0b1628;
    --teal: #0d9e8c;
    --teal-dark: #0a7d6e;
    --teal-light: #e6f7f5;
    --teal-soft: rgba(13,158,140,.08);
    --slate: #64748b;
    --line: #e2e8f0;
    --shadow-card: 0 2px 8px rgba(11,22,40,.05), 0 10px 28px rgba(11,22,40,.06);
    --shadow-card-hover: 0 4px 14px rgba(11,22,40,.07), 0 16px 36px rgba(11,22,40,.09);
    --shadow-teal: 0 8px 22px rgba(13,158,140,.28);
    --ease: cubic-bezier(.4,0,.2,1);
}
* { font-family: 'DM Sans', system-ui, sans-serif; box-sizing: border-box; }
h1, h2, h3 { font-family: 'Playfair Display', Georgia, serif; }
body {
    background:
        radial-gradient(720px 420px at 12% -8%, rgba(13,158,140,.06), transparent 60%),
        radial-gradient(600px 400px at 100% 0%, rgba(11,22,40,.035), transparent 55%),
        #f7f9fc;
    min-height: 100vh;
    color: var(--navy);
    -webkit-font-smoothing: antialiased;
}

::selection { background: var(--teal); color: white; }

a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, [tabindex]:focus-visible {
    outline: 2px solid var(--teal);
    outline-offset: 2px;
    border-radius: 6px;
}

@media (prefers-reduced-motion: reduce) {
    * { animation-duration: .001ms !important; transition-duration: .001ms !important; }
}

.wrap { max-width: 1180px; margin: 0 auto; padding: 2.25rem 1.5rem 3rem; }

.page-title { font-size: 30px; font-weight: 800; text-align: center; letter-spacing: -.01em; }
.page-title span { color: var(--teal); font-style: italic; font-weight: 700; }

/* ===== STEP INDICATOR ===== */
.step-track { display: flex; justify-content: center; align-items: flex-start; gap: 0; margin: 1.75rem auto 2.25rem; max-width: 720px; }
.step-node { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
.step-node .dot {
    width: 30px; height: 30px; border-radius: 50%; background: white; border: 2px solid var(--line);
    color: #94a3b8; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center;
    transition: all .3s var(--ease); z-index: 1;
}
.step-node.active .dot { background: var(--teal); border-color: var(--teal); color: white; box-shadow: 0 0 0 5px var(--teal-soft), 0 4px 14px rgba(13,158,140,.3); }
.step-node.done .dot { background: var(--teal); border-color: var(--teal); color: white; }
.step-node.done .dot::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 11px; }
.step-node .lbl { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #94a3b8; margin-top: 7px; transition: color .3s var(--ease); text-align: center; }
.step-node.active .lbl, .step-node.done .lbl { color: var(--teal); }
.step-node:not(:last-child)::after {
    content: ''; position: absolute; top: 15px; left: calc(50% + 20px); right: calc(-50% + 20px);
    height: 2px; background: var(--line); z-index: 0; transition: background .4s var(--ease);
}
.step-node.done:not(:last-child)::after { background: var(--teal); }

/* ===== CARDS ===== */
.panel { background: white; border-radius: 16px; box-shadow: var(--shadow-card); border: 1px solid #eef2f6; padding: 1.25rem; transition: box-shadow .25s var(--ease), border-color .25s var(--ease); }
.panel-title { font-size: 12px; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .6px; display: flex; align-items: center; gap: 7px; margin-bottom: .75rem; }
.panel-title i { color: var(--teal); }

.step-view { display: none; }
.step-view.active { display: block; animation: fadeStep .35s var(--ease); }
@keyframes fadeStep { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* --- STEP 1 layout --- */
.s1-grid { display: grid; grid-template-columns: 230px 1fr 230px; gap: 1rem; align-items: start; }
@media (max-width: 980px) { .s1-grid { grid-template-columns: 1fr; } }

.service-toggle { display: flex; align-items: center; justify-content: space-between; background: var(--teal-light); border: 1px solid #bfe9e2; border-radius: 10px; padding: 10px 12px; cursor: pointer; font-size: 13px; font-weight: 700; color: var(--teal-dark); transition: background .2s var(--ease), border-color .2s var(--ease); }
.service-toggle:hover { background: #dcf4f0; border-color: var(--teal); }
.service-toggle .count-badge { background: var(--teal); color: white; border-radius: 999px; width: 20px; height: 20px; font-size: 11px; display: flex; align-items: center; justify-content: center; margin-right: 6px; }
.service-toggle i { transition: transform .25s var(--ease); }
.service-panel { margin-top: 8px; display: none; flex-direction: column; gap: 6px; }
.service-panel.open { display: flex; }
.service-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; padding: 7px 9px; border-radius: 8px; border: 1px solid var(--line); cursor: pointer; transition: border-color .2s var(--ease), background .2s var(--ease), transform .15s var(--ease); }
.service-row:hover { border-color: var(--teal); background: #fafffe; transform: translateX(1px); }
.service-row input { accent-color: var(--teal); width: 15px; height: 15px; }

.cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.cal-month { font-weight: 700; font-size: 15px; min-width: 130px; text-align: center; }
.cal-nav { border: 1px solid var(--line); background: white; border-radius: 8px; width: 30px; height: 30px; cursor: pointer; transition: all .2s var(--ease); color: var(--slate); }
.cal-nav:hover { border-color: var(--teal); color: var(--teal); background: var(--teal-light); }
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
.calendar-grid .day-name { font-size: 10px; font-weight: 700; color: #94a3b8; text-align: center; padding: 4px 0; text-transform: uppercase; }
.day-cell { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 9px; font-size: 12.5px; font-weight: 600; cursor: pointer; background: #f8fafc; border: 2px solid transparent; transition: all .18s var(--ease); }
.day-cell:hover:not(.disabled) { background: var(--teal-light); border-color: var(--teal); transform: translateY(-1px); }
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
    transition: all .18s var(--ease);
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

.time-fmt-toggle { display: flex; background: #f1f4f8; border-radius: 8px; padding: 2px; }
.time-fmt-toggle button { border: none; background: transparent; font-size: 10px; font-weight: 700; padding: 4px 9px; border-radius: 6px; cursor: pointer; color: #94a3b8; transition: all .2s var(--ease); }
.time-fmt-toggle button.active { background: white; color: var(--teal); box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.time-slots-list { display: flex; flex-direction: column; gap: 6px; max-height: 360px; overflow-y: auto; margin-top: 8px; padding-right: 2px; }
.time-slots-list::-webkit-scrollbar { width: 5px; }
.time-slots-list::-webkit-scrollbar-thumb { background: var(--line); border-radius: 999px; }
.time-slots-list::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
.time-slot-btn { display: block; width: 100%; padding: 8px 10px; border: 2px solid var(--line); border-radius: 9px; background: white; color: var(--navy); font-family: inherit; font-size: 12px; font-weight: 600; cursor: pointer; text-align: center; transition: all .18s var(--ease); }
.time-slot-btn:hover:not(.disabled):not(.booked) { border-color: var(--teal); background: var(--teal-light); }
.time-slot-btn.selected { border-color: var(--teal); background: var(--teal); color: white; box-shadow: 0 4px 12px rgba(13,158,140,.3); }
.time-slot-btn.booked { background: #fee9e7; border-color: #fca5a5; color: #dc2626; cursor: not-allowed; }
.empty-hint { font-size: 12px; color: #94a3b8; text-align: center; padding: 24px 8px; background: #f8fafc; border-radius: 10px; border: 1px dashed var(--line); }

.status-bar { margin-top: 1rem; background: white; border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; box-shadow: var(--shadow-card); }
.status-bar .main { font-weight: 700; font-size: 13px; }
.status-bar .hint { font-size: 11.5px; color: #94a3b8; }

.page-footer { text-align: center; margin-top: 1.75rem; font-size: 11.5px; color: #94a3b8; display: flex; justify-content: center; gap: 18px; flex-wrap: wrap; }
.page-footer span:not(:last-child) { position: relative; padding-right: 18px; }
.page-footer span:not(:last-child)::after { content: ''; position: absolute; right: -9px; top: 50%; transform: translateY(-50%); width: 3px; height: 3px; border-radius: 50%; background: var(--line); }
.page-footer i { color: var(--teal); margin-right: 4px; }

/* --- STEP 2 form --- */
.form-narrow { max-width: 640px; margin: 0 auto; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 640px) { .form-grid-2 { grid-template-columns: 1fr; } }
.f-label { font-size: 12px; font-weight: 700; color: var(--navy); margin-bottom: 5px; display: block; }
.f-label .opt { color: #94a3b8; font-weight: 500; font-style: italic; }
.f-input, .f-select, textarea.f-input {
    width: 100%; padding: 11px 13px; border: 2px solid var(--line); border-radius: 10px;
    font-size: 13.5px; background: #fafbfc; color: var(--navy); transition: all .2s var(--ease);
}
.f-input:hover, .f-select:hover { border-color: #cbd5e1; }
.f-input:focus, .f-select:focus, textarea:focus { outline: none; border-color: var(--teal); background: white; box-shadow: 0 0 0 4px var(--teal-soft); }
.f-input:disabled, .f-select:disabled { background: #f1f4f8; color: #94a3b8; cursor: not-allowed; }
.f-group { margin-bottom: 1.1rem; }
.f-hint { font-size: 11px; color: #94a3b8; margin-top: 5px; }
.section-label { font-size: 14px; font-weight: 800; color: var(--navy); margin: 1.5rem 0 .9rem; padding-top: 1rem; border-top: 1px solid var(--line); display: flex; align-items: center; gap: 8px; }

/* --- Phone field: locked +63 prefix --- */
.phone-wrap { display: flex; align-items: stretch; border: 2px solid var(--line); border-radius: 10px; background: #fafbfc; overflow: hidden; transition: all .2s var(--ease); }
.phone-wrap:focus-within { border-color: var(--teal); background: white; box-shadow: 0 0 0 4px var(--teal-soft); }
.phone-wrap.is-valid   { border-color: #0d9e8c; }
.phone-wrap.is-invalid { border-color: #e53e3e; }

.phone-prefix {
    display: flex; align-items: center; justify-content: center;
    padding: 0 12px; background: #eef2f6; color: var(--slate);
    font-weight: 700; font-size: 13.5px; border-right: 1px solid var(--line);
    user-select: none; min-width: 52px;
}
.phone-input {
    border: none !important; background: transparent !important; box-shadow: none !important;
    border-radius: 0 !important; flex: 1;
}

/* --- STEP 3 medical & dental history --- */
.mh-question { margin-bottom: 1.35rem; }
.mh-question .q-text { font-size: 13px; font-weight: 700; color: var(--navy); margin-bottom: 8px; }
.radio-group { display: flex; gap: 22px; }
.radio-option { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--navy); cursor: pointer; }
.radio-option input { accent-color: var(--teal); width: 16px; height: 16px; }
.mh-followup { margin-top: 10px; display: none; }
.mh-followup.show { display: block; }
.checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; margin-top: 8px; }
@media (max-width: 640px) { .checkbox-grid { grid-template-columns: 1fr; } }
.checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; padding: 7px 9px; border-radius: 8px; border: 1px solid var(--line); cursor: pointer; transition: border-color .2s var(--ease), background .2s var(--ease); }
.checkbox-row:hover { border-color: var(--teal); background: #fafffe; }
.checkbox-row input { accent-color: var(--teal); width: 15px; height: 15px; }

/* --- Buttons / Review / Confirmed --- */
.btn-primary { background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; padding: 12px 30px; border-radius: 10px; font-weight: 700; font-size: 13.5px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .22s var(--ease); box-shadow: 0 4px 14px rgba(13,158,140,.22); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: var(--shadow-teal); }
.btn-primary:active { transform: translateY(0); }
.btn-primary:disabled { opacity: .4; cursor: not-allowed; transform: none; box-shadow: none; }
.btn-ghost { padding: 12px 22px; background: white; border: 2px solid var(--line); border-radius: 10px; font-weight: 700; font-size: 13px; color: #4a5568; cursor: pointer; transition: all .2s var(--ease); }
.btn-ghost:hover { border-color: #cbd5e0; background: #f8fafc; transform: translateY(-1px); }
.actions-row { display: flex; justify-content: space-between; align-items: center; margin-top: 1.75rem; max-width: 640px; margin-left: auto; margin-right: auto; }

.review-card { max-width: 640px; margin: 0 auto; }
.review-block { background: #f8fafc; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-bottom: .8rem; transition: box-shadow .2s var(--ease); }
.review-block:hover { box-shadow: 0 2px 10px rgba(11,22,40,.04); }
.review-block .rb-title { font-size: 10.5px; font-weight: 800; color: var(--teal); text-transform: uppercase; letter-spacing: .5px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.review-block .rb-edit { color: var(--teal); font-weight: 700; cursor: pointer; text-decoration: underline; font-size: 11px; transition: color .2s var(--ease); }
.review-block .rb-edit:hover { color: var(--teal-dark); }
.review-row { display: flex; justify-content: space-between; font-size: 12.5px; padding: 4px 0; gap: 12px; }
.review-row .l { color: var(--slate); white-space: nowrap; }
.review-row .v { font-weight: 600; text-align: right; }
.service-chip { display: inline-block; background: var(--teal-light); color: var(--teal-dark); padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; margin: 2px 3px 2px 0; }

.confirmed-wrap { max-width: 480px; margin: 2rem auto 0; text-align: center; }
.confirmed-icon { width: 76px; height: 76px; border-radius: 50%; background: var(--teal-light); color: var(--teal); display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 1rem; box-shadow: 0 0 0 8px rgba(13,158,140,.06); animation: popIn .4s var(--ease); }
@keyframes popIn { from { opacity: 0; transform: scale(.7); } to { opacity: 1; transform: scale(1); } }
.tracking-box { background: var(--teal-light); border-radius: 12px; padding: 14px; margin: 1.25rem 0; border: 1px dashed #bfe9e2; }
.tracking-id { font-size: 22px; font-weight: 800; color: var(--teal-dark); letter-spacing: 1.5px; }
</style>
</head>
<body>
<div class="wrap">

    <h1 class="page-title">Schedule Your <span>Visit</span></h1>

    <div class="step-track">
        <div class="step-node active" data-step="1"><div class="dot">1</div><span class="lbl">Schedule</span></div>
        <div class="step-node" data-step="2"><div class="dot">2</div><span class="lbl">Your Info</span></div>
        <div class="step-node" data-step="3"><div class="dot">3</div><span class="lbl">Medical History</span></div>
        <div class="step-node" data-step="4"><div class="dot">4</div><span class="lbl">Review</span></div>
        <div class="step-node" data-step="5"><div class="dot">5</div><span class="lbl">Confirmed</span></div>
    </div>

    <!-- ============ STEP 1: SCHEDULE ============ -->
    <div class="step-view active" id="stepView1">
        <div class="s1-grid">
            <div class="panel">
                <div class="panel-title"><i class="fas fa-tooth"></i> Dental Service</div>
                <div class="service-toggle" onclick="toggleServicePanel()">
                    <span><span class="count-badge" id="serviceCountBadge">0</span><span id="serviceCountLabel">services selected</span></span>
                    <i class="fas fa-chevron-down" id="serviceChevron"></i>
                </div>
                <div class="service-panel" id="servicePanel">
                    <?php foreach ($services as $service): ?>
                    <label class="service-row">
                        <input type="checkbox" value="<?= (int)$service['id'] ?>" data-title="<?= h($service['title']) ?>" data-price="<?= (float)$service['price'] ?>" onchange="onServiceToggle()">
                        <?= h($service['title']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel">
                <div class="cal-head">
                    <button class="cal-nav" id="prevMonthBtn" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                    <span class="cal-month" id="calendarMonth">July 2026</span>
                    <button class="cal-nav" id="nextMonthBtn" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
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

            <div class="panel">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div class="panel-title" style="margin-bottom:0;"><i class="fas fa-clock"></i> Time Slots</div>
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
                <div class="hint" id="statusHint">Tap an available date on the calendar above</div>
            </div>
            <button class="btn-primary" id="toStep2Btn" disabled onclick="goToStep(2)">
                Continue <i class="fas fa-arrow-right"></i>
            </button>
        </div>

        <div class="page-footer">
            <span><i class="fas fa-shield-alt"></i>Secure Booking</span>
            <span><?= h($settings['clinic_name']) ?></span>
            <span><?= h($settings['clinic_tagline'] ?? 'Specialized Dentistry With Personalized Care') ?></span>
        </div>
    </div>

    <!-- ============ STEP 2: YOUR INFO ============ -->
    <div class="step-view" id="stepView2">
        <div class="panel form-narrow">
            <div class="section-label" style="margin-top:0; border-top:none; padding-top:0;">
                <i class="fas fa-user" style="color:var(--teal); font-size:12px;"></i> Personal Information
            </div>

            <div class="form-grid-2">
                <div class="f-group">
                    <label class="f-label">First Name <span style="color:#e53e3e;">*</span></label>
                    <input type="text" class="f-input" id="firstName" placeholder="Juan" required>
                </div>
                <div class="f-group">
                    <label class="f-label">Middle Name <span class="opt">(optional)</span></label>
                    <input type="text" class="f-input" id="middleName" placeholder="Santos">
                </div>
            </div>

            <div class="f-group">
                <label class="f-label">Last Name <span style="color:#e53e3e;">*</span></label>
                <input type="text" class="f-input" id="lastName" placeholder="Dela Cruz" required>
            </div>

            <div class="form-grid-2">
                <div class="f-group">
                    <label class="f-label">Age <span style="color:#e53e3e;">*</span></label>
                    <input type="number" class="f-input" id="age" min="0" max="99" placeholder="e.g. 28" required>
                </div>
                <div class="f-group">
                    <label class="f-label">Occupation <span class="opt">(optional)</span></label>
                    <input type="text" class="f-input" id="occupation" placeholder="Enter occupation">
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
                    <p class="f-hint" id="phoneHint">Enter the 10 digits after +63 (starting with 9).</p>
                </div>
                <div class="f-group">
                    <label class="f-label">Email  </label>
                    <input type="email" class="f-input" id="contactEmail" placeholder="youremail@example.com">
                </div>
            </div>

            <div class="section-label"><i class="fas fa-location-dot" style="color:var(--teal); font-size:12px;"></i> Address Information</div>

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
                <input type="text" class="f-input" id="streetAddress" placeholder="House no., street, purok, subdivision">
                <p class="f-hint">Select your area, then add your detailed address.</p>
            </div>

            <div class="section-label" style="margin-top:1rem;"><i class="fas fa-note-sticky" style="color:var(--teal); font-size:12px;"></i> Additional Notes</div>
            <div class="f-group">
                <label class="f-label">Notes / Concern <span class="opt">(optional)</span></label>
                <textarea class="f-input" id="patientNotes" rows="3" placeholder="Additional request or concern..."></textarea>
            </div>

            <p class="f-hint" style="text-align:center;">The clinic will review your request first before it becomes a confirmed appointment.</p>
        </div>

        <div class="actions-row">
            <button class="btn-ghost" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
            <button class="btn-primary" onclick="validateStep2()">Continue <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>

    <!-- ============ STEP 3: MEDICAL & DENTAL HISTORY ============ -->
    <div class="step-view" id="stepView3">
        <div class="panel form-narrow">
            <div class="panel-title" style="margin-bottom:1.1rem;"><i class="fas fa-notes-medical"></i> Medical & Dental History</div>

            <div class="mh-question">
                <div class="q-text">1. Are you presently under a physician's care?</div>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="underCare" value="yes" onchange="toggleFollowup('underCare','underCareFollowup')"> Yes</label>
                    <label class="radio-option"><input type="radio" name="underCare" value="no" onchange="toggleFollowup('underCare','underCareFollowup')"> No</label>
                </div>
                <div class="mh-followup" id="underCareFollowup">
                    <input type="text" class="f-input" id="underCareDoctor" placeholder="If yes, who / Dr.?">
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">2. Are you pregnant?</div>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="pregnant" value="yes"> Yes</label>
                    <label class="radio-option"><input type="radio" name="pregnant" value="no"> No</label>
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">3. Are you taking any medicine at present?</div>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="takingMedicine" value="yes" onchange="toggleFollowup('takingMedicine','takingMedicineFollowup')"> Yes</label>
                    <label class="radio-option"><input type="radio" name="takingMedicine" value="no" onchange="toggleFollowup('takingMedicine','takingMedicineFollowup')"> No</label>
                </div>
                <div class="mh-followup" id="takingMedicineFollowup">
                    <input type="text" class="f-input" id="medicineDetails" placeholder="If yes, what?">
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">4. Have you ever had any of the following?</div>
                <div class="checkbox-grid">
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="High Blood Pressure"> High Blood Pressure</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Low Blood Pressure"> Low Blood Pressure</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Asthma"> Asthma</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Heart Disease"> Heart Disease</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Diabetes"> Diabetes</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Tuberculosis"> Tuberculosis</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Thyroid Problem"> Thyroid Problem</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Bleeding Problems"> Bleeding Problems</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="AIDS or HIV Infection"> AIDS or HIV Infection</label>
                    <label class="checkbox-row"><input type="checkbox" class="condition-checkbox" value="Hepatitis"> Hepatitis</label>
                    <label class="checkbox-row" style="grid-column: span 2;"><input type="checkbox" id="otherConditionCheck" onchange="toggleFollowup('otherConditionCheck','otherConditionFollowup', true)"> Others</label>
                </div>
                <div class="mh-followup" id="otherConditionFollowup">
                    <input type="text" class="f-input" id="otherConditionDetails" placeholder="Please specify">
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">5. Have you ever had an allergic reaction to any of the following?</div>
                <div class="form-grid-2">
                    <div>
                        <label class="f-label">a. Local Anesthesia</label>
                        <div class="radio-group">
                            <label class="radio-option"><input type="radio" name="allergyLocalAnesthesia" value="yes"> Yes</label>
                            <label class="radio-option"><input type="radio" name="allergyLocalAnesthesia" value="no"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="f-label">b. Antibiotics</label>
                        <div class="radio-group">
                            <label class="radio-option"><input type="radio" name="allergyAntibiotics" value="yes"> Yes</label>
                            <label class="radio-option"><input type="radio" name="allergyAntibiotics" value="no"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="f-label">c. Pain Killer</label>
                        <div class="radio-group">
                            <label class="radio-option"><input type="radio" name="allergyPainKiller" value="yes"> Yes</label>
                            <label class="radio-option"><input type="radio" name="allergyPainKiller" value="no"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="f-label">d. Others</label>
                        <div class="radio-group">
                            <label class="radio-option"><input type="radio" name="allergyOther" value="yes" onchange="toggleFollowup('allergyOther','allergyOtherFollowup')"> Yes</label>
                            <label class="radio-option"><input type="radio" name="allergyOther" value="no" onchange="toggleFollowup('allergyOther','allergyOtherFollowup')"> No</label>
                        </div>
                    </div>
                </div>
                <div class="mh-followup" id="allergyOtherFollowup">
                    <input type="text" class="f-input" id="allergyOtherDetails" placeholder="Please specify">
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">6. Have you ever been hospitalized?</div>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="hospitalized" value="yes" onchange="toggleFollowup('hospitalized','hospitalizedFollowup')"> Yes</label>
                    <label class="radio-option"><input type="radio" name="hospitalized" value="no" onchange="toggleFollowup('hospitalized','hospitalizedFollowup')"> No</label>
                </div>
                <div class="mh-followup" id="hospitalizedFollowup">
                    <div class="form-grid-2">
                        <input type="text" class="f-input" id="hospitalizedWhen" placeholder="If yes, when?">
                        <input type="text" class="f-input" id="hospitalizedWhy" placeholder="Why?">
                    </div>
                </div>
            </div>

            <div class="mh-question">
                <div class="q-text">7. Have you worn any type of denture?</div>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="wornDenture" value="yes"> Yes</label>
                    <label class="radio-option"><input type="radio" name="wornDenture" value="no"> No</label>
                </div>
            </div>

            <div class="mh-question" style="margin-bottom:0;">
                <div class="q-text">8. When was your last dental visit?</div>
                <div class="form-grid-2">
                    <input type="text" class="f-input" id="lastDentalVisit" placeholder="e.g. 6 months ago">
                    <input type="text" class="f-input" id="lastDentalVisitWhy" placeholder="Why?">
                </div>
            </div>

            <p class="f-hint" style="text-align:center; margin-top:1.25rem;">This information helps the clinic provide safe and appropriate care for your visit.</p>
        </div>

        <div class="actions-row">
            <button class="btn-ghost" onclick="goToStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
            <button class="btn-primary" onclick="validateStep3Medical()">Continue <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>

    <!-- ============ STEP 4: REVIEW ============ -->
    <div class="step-view" id="stepView4">
        <div class="review-card">
            <div class="review-block">
                <div class="rb-title">Schedule <span class="rb-edit" onclick="goToStep(1)">Edit</span></div>
                <div class="review-row"><span class="l">Date</span><span class="v" id="rvDate">—</span></div>
                <div class="review-row"><span class="l">Time</span><span class="v" id="rvTime">—</span></div>
                <div style="margin-top:6px;" id="rvServices"></div>
            </div>

            <div class="review-block">
                <div class="rb-title">Your Info <span class="rb-edit" onclick="goToStep(2)">Edit</span></div>
                <div class="review-row"><span class="l">Full Name</span><span class="v" id="rvName">—</span></div>
                <div class="review-row"><span class="l">Age</span><span class="v" id="rvAge">—</span></div>
                <div class="review-row"><span class="l">Occupation</span><span class="v" id="rvOccupation">—</span></div>
                <div class="review-row"><span class="l">Contact Number</span><span class="v" id="rvPhone">—</span></div>
                <div class="review-row"><span class="l">Email</span><span class="v" id="rvEmail">—</span></div>
            </div>

            <div class="review-block">
                <div class="rb-title">Address <span class="rb-edit" onclick="goToStep(2)">Edit</span></div>
                <div class="review-row"><span class="l">Region</span><span class="v" id="rvRegion">—</span></div>
                <div class="review-row"><span class="l">Province</span><span class="v" id="rvProvince">—</span></div>
                <div class="review-row"><span class="l">City / Municipality</span><span class="v" id="rvCity">—</span></div>
                <div class="review-row"><span class="l">Barangay</span><span class="v" id="rvBarangay">—</span></div>
                <div class="review-row"><span class="l">Street</span><span class="v" id="rvStreet">—</span></div>
            </div>

            <div class="review-block">
                <div class="rb-title">Medical & Dental History <span class="rb-edit" onclick="goToStep(3)">Edit</span></div>
                <div class="review-row"><span class="l">Under Physician's Care</span><span class="v" id="rvUnderCare">—</span></div>
                <div class="review-row"><span class="l">Pregnant</span><span class="v" id="rvPregnant">—</span></div>
                <div class="review-row"><span class="l">Taking Medicine</span><span class="v" id="rvTakingMedicine">—</span></div>
                <div class="review-row"><span class="l">Medical Conditions</span><span class="v" id="rvConditions">—</span></div>
                <div class="review-row"><span class="l">Allergic Reactions</span><span class="v" id="rvAllergies">—</span></div>
                <div class="review-row"><span class="l">Hospitalized</span><span class="v" id="rvHospitalized">—</span></div>
                <div class="review-row"><span class="l">Worn Denture</span><span class="v" id="rvDenture">—</span></div>
                <div class="review-row"><span class="l">Last Dental Visit</span><span class="v" id="rvLastDentalVisit">—</span></div>
            </div>

            <div class="review-block" id="rvNotesBlock" style="display:none;">
                <div class="rb-title">Notes <span class="rb-edit" onclick="goToStep(2)">Edit</span></div>
                <div class="review-row"><span class="v" id="rvNotes" style="text-align:left;">—</span></div>
            </div>

            <p class="f-hint" style="text-align:center;">By confirming, you agree this request will be reviewed by the clinic before final confirmation.</p>
        </div>

        <div class="actions-row">
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
            <h2 style="font-size:22px; font-weight:800;">Request Submitted!</h2>
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
                <a class="btn-ghost" href="index.php" style="text-align:center; text-decoration:none;">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </div>
    </div>

</div>

<script>
// ====================== INITIAL DATA FROM PHP ======================
const bookingData = <?= json_encode($bookingData) ?>;
const allServices = <?= json_encode($services) ?>;

// ====================== SERVER TIME (FIX FOR TIMEZONE BUG) ======================
// We use the server's time so that "today" and "now" are based on the clinic's timezone,
// not the user's local device timezone.
const SERVER_NOW = new Date("<?= date('Y-m-d\TH:i:s') ?>");

// ====================== STATE ======================
let currentStep = 1;
let currentMonth = SERVER_NOW.getMonth();
let currentYear = SERVER_NOW.getFullYear();
const minMonthIndex = SERVER_NOW.getFullYear() * 12 + SERVER_NOW.getMonth();
const maxMonthIndex = minMonthIndex + 2;
let selectedDate = null;
let selectedTime = null;
let timeFormat = '12h';
let selectedServiceIds = (bookingData.services || []).map(id => parseInt(id));
let monthStatuses = {};
let renderedSlots = [];
let renderedBooked = [];

// ====================== HELPERS ======================
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

// ====================== SERVICE SELECTOR ======================
function toggleServicePanel() {
    document.getElementById('servicePanel').classList.toggle('open');
    document.getElementById('serviceChevron').classList.toggle('fa-chevron-up');
    document.getElementById('serviceChevron').classList.toggle('fa-chevron-down');
}

function initServiceCheckboxes() {
    document.querySelectorAll('#servicePanel input[type="checkbox"]').forEach(cb => {
        cb.checked = selectedServiceIds.includes(parseInt(cb.value));
    });
    updateServiceCountLabel();
}

function onServiceToggle() {
    selectedServiceIds = Array.from(document.querySelectorAll('#servicePanel input[type="checkbox"]:checked'))
        .map(cb => parseInt(cb.value));
    updateServiceCountLabel();
    updateContinueState();
}

function updateServiceCountLabel() {
    document.getElementById('serviceCountBadge').textContent = selectedServiceIds.length;
    document.getElementById('serviceCountLabel').textContent =
        selectedServiceIds.length === 1 ? 'service selected' : 'services selected';
}

function getSelectedServiceObjects() {
    return allServices.filter(s => selectedServiceIds.includes(parseInt(s.id)));
}

// ====================== CALENDAR ======================
function generateCalendar(dateToSelect = null) {
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calendarMonth').textContent = monthNames[currentMonth] + ' ' + currentYear;

    const viewingIndex = currentYear * 12 + currentMonth;
    const prevBtn = document.getElementById('prevMonthBtn');
    const nextBtn = document.getElementById('nextMonthBtn');

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

    fetch(`api/fetch_monthly_availability.php?year=${currentYear}&month=${currentMonth + 1}`)
        .then(r => r.json())
        .then(data => {
            monthStatuses = (data && data.days) ? data.days : {};
            renderCalendarGrid();
            
            // FIX: After the calendar is rendered and monthStatuses is populated,
            // check if we need to select a specific date.
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
    
    // USE SERVER TIME
    const today = SERVER_NOW;
    const todayStr = today.getFullYear() + '-' +
        String(today.getMonth() + 1).padStart(2, '0') + '-' +
        String(today.getDate()).padStart(2, '0');

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
            statusIcon = `<span class="day-icon" aria-label="Past"><i class="fas fa-ban"></i></span>`;
        } else if (info.status === 'available') {
            statusIcon = `<span class="day-icon" aria-label="Available"><i class="fas fa-check"></i></span>`;
        } else if (info.status === 'half_day') {
            statusIcon = `<span class="day-icon" aria-label="Half day"><i class="fas fa-adjust"></i></span>`;
        } else {
            statusIcon = `<span class="day-icon" aria-label="Unavailable"><i class="fas fa-ban"></i></span>`;
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

    document.getElementById('timeSlotsContainer').innerHTML =
        '<div class="empty-hint">Select a date and service to view available slots</div>';

    generateCalendar();
    updateStatusBar();
    updateContinueState();
}

function selectDate(dateStr) {
    selectedDate = dateStr;
    
    // Re-render the grid to update the selected highlight
    renderCalendarGrid();
    
    // Generate time slots (now safe because monthStatuses is guaranteed to be populated)
    generateTimeSlots(dateStr);
    updateStatusBar();
    updateContinueState();
}

// ====================== TIME SLOTS ======================
function setTimeFormat(fmt) {
    timeFormat = fmt;
    document.getElementById('fmt12').classList.toggle('active', fmt === '12h');
    document.getElementById('fmt24').classList.toggle('active', fmt === '24h');

    if (renderedSlots.length) {
        renderTimeSlots(renderedSlots, renderedBooked);
    }
    updateStatusBar();
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
    let endH = hour + 1;
    let endM = min;
    if (endH >= 24) endH -= 24;

    if (timeFormat === '24h') {
        return String(hour).padStart(2,'0') + ':' + String(min).padStart(2,'0') +
               ' - ' +
               String(endH).padStart(2,'0') + ':' + String(endM).padStart(2,'0');
    }

    return formatTime(hour, min) + ' - ' + formatTime(endH, endM);
}

function generateTimeSlots(dateStr) {
    // Always wipe the previous date's slots first
    renderedSlots = [];
    renderedBooked = [];
    selectedTime = null;

    const container = document.getElementById('timeSlotsContainer');
    const info = monthStatuses[dateStr] || { status: 'unavailable', start: null, end: null };

    if (info.status === 'unavailable' || info.status === 'past') {
        container.innerHTML = '<div class="empty-hint">This date is unavailable.</div>';
        return;
    }

    if (!info.start || !info.end) {
        container.innerHTML = '<div class="empty-hint">No working hours set for this day.</div>';
        return;
    }

    const [startH, startM] = info.start.split(':').map(Number);
    const [endH,   endM]   = info.end.split(':').map(Number);

    const now = SERVER_NOW;
    const todayStr = now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0');
    const isToday = (dateStr === todayStr);
    const nowMinutes = now.getHours() * 60 + now.getMinutes();

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
        container.innerHTML = isToday
            ? '<div class="empty-hint">No more slots today. Please choose another date.</div>'
            : '<div class="empty-hint">No slots available for this day.</div>';
        return;
    }

    // Show the slots right away, then mark booked ones when the server answers
    renderTimeSlots(slots, []);

    fetch('api/fetch_reserved_time_slots.php?date=' + encodeURIComponent(dateStr), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (selectedDate !== dateStr) return; // user already clicked another date
            renderTimeSlots(slots, data.booked_slots || []);
        })
        .catch(err => console.error('Failed to load booked slots', err));
}

 

function renderTimeSlots(slots, bookedSlots) {
    renderedSlots = slots;
    renderedBooked = bookedSlots;

    let html = '<div class="time-slots-list">';
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
        hint.textContent = 'Tap an available date on the calendar above';
        return;
    }
    const dateDisplay = new Date(selectedDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    if (!selectedTime) {
        main.textContent = dateDisplay;
        hint.textContent = 'Now pick a time slot on the right';
    } else {
        const [h, m] = selectedTime.split(':').map(Number);
        main.textContent = dateDisplay + ' — ' + formatTimeRange(h, m);
        hint.textContent = 'Looks good — continue when ready';
    }
}

function updateContinueState() {
    document.getElementById('toStep2Btn').disabled = !(selectedDate && selectedTime && selectedServiceIds.length > 0);
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
        Swal.fire({ icon: 'warning', title: 'Invalid Age', text: 'Please enter a valid age between 1 and 99.', confirmButtonColor: '#0d9e8c' });
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

// ====================== STEP 3: MEDICAL & DENTAL HISTORY ======================
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

function getMedicalHistoryData() {
    return {
        under_physician_care: radioVal('underCare'),
        physician_name: fieldVal('underCareDoctor'),
        pregnant: radioVal('pregnant'),
        taking_medicine: radioVal('takingMedicine'),
        medicine_details: fieldVal('medicineDetails'),
        conditions: Array.from(document.querySelectorAll('.condition-checkbox:checked')).map(cb => cb.value),
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
}

function validateStep3Medical() {
    const requiredRadios = ['underCare', 'pregnant', 'takingMedicine', 'hospitalized', 'wornDenture'];
    for (const name of requiredRadios) {
        if (!radioVal(name)) {
            Swal.fire({ icon: 'warning', title: 'Please Complete the Form', text: 'Please answer all Yes/No questions before continuing.', confirmButtonColor: '#0d9e8c' });
            return;
        }
    }
    goToStep(4);
}

// ====================== REVIEW ======================
function selectedText(selectId) {
    const el = document.getElementById(selectId);
    if (!el || el.selectedIndex < 0 || !el.value) return '—';
    return el.options[el.selectedIndex].text;
}

function populateReview() {
    document.getElementById('rvDate').textContent = selectedDate
        ? new Date(selectedDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
        : '—';
    document.getElementById('rvTime').textContent = selectedTime
        ? (() => { const [h,m] = selectedTime.split(':').map(Number); return formatTimeRange(h,m); })()
        : '—';

    const svcHtml = getSelectedServiceObjects().map(s => `<span class="service-chip">${s.title}</span>`).join('');
    document.getElementById('rvServices').innerHTML = svcHtml || '<span class="f-hint">No services selected</span>';

    document.getElementById('rvName').textContent = getFullName() || '—';
    document.getElementById('rvAge').textContent = document.getElementById('age').value.trim() || '—';
    document.getElementById('rvOccupation').textContent = document.getElementById('occupation').value.trim() || '—';
    document.getElementById('rvPhone').textContent = getFullPhone() || '—';
    document.getElementById('rvEmail').textContent = document.getElementById('contactEmail').value.trim() || '—';

    document.getElementById('rvRegion').textContent = selectedText('regionSelect');
    document.getElementById('rvProvince').textContent = selectedText('provinceSelect');
    document.getElementById('rvCity').textContent = selectedText('citySelect');
    document.getElementById('rvBarangay').textContent = selectedText('barangaySelect');
    document.getElementById('rvStreet').textContent = document.getElementById('streetAddress').value.trim() || '—';

    const notes = document.getElementById('patientNotes').value.trim();
    document.getElementById('rvNotesBlock').style.display = notes ? 'block' : 'none';
    document.getElementById('rvNotes').textContent = notes;

    const mh = getMedicalHistoryData();
    document.getElementById('rvUnderCare').textContent = mh.under_physician_care
        ? (mh.under_physician_care === 'yes' ? `Yes${mh.physician_name ? ' — ' + mh.physician_name : ''}` : 'No')
        : '—';
    document.getElementById('rvPregnant').textContent = mh.pregnant
        ? (mh.pregnant === 'yes' ? 'Yes' : 'No')
        : '—';
    document.getElementById('rvTakingMedicine').textContent = mh.taking_medicine
        ? (mh.taking_medicine === 'yes' ? `Yes${mh.medicine_details ? ' — ' + mh.medicine_details : ''}` : 'No')
        : '—';
    document.getElementById('rvConditions').textContent = mh.conditions.length
        ? mh.conditions.join(', ') + (mh.other_condition_details ? `, ${mh.other_condition_details}` : '')
        : 'None reported';

    const allergies = [];
    if (mh.allergic_local_anesthesia === 'yes') allergies.push('Local Anesthesia');
    if (mh.allergic_antibiotics === 'yes') allergies.push('Antibiotics');
    if (mh.allergic_pain_killer === 'yes') allergies.push('Pain Killer');
    if (mh.allergic_other === 'yes') allergies.push(mh.allergic_other_details || 'Other');
    document.getElementById('rvAllergies').textContent = allergies.length ? allergies.join(', ') : 'None reported';

    document.getElementById('rvHospitalized').textContent = mh.hospitalized
        ? (mh.hospitalized === 'yes'
            ? `Yes${mh.hospitalized_when ? ' — ' + mh.hospitalized_when : ''}${mh.hospitalized_why ? ' (' + mh.hospitalized_why + ')' : ''}`
            : 'No')
        : '—';
    document.getElementById('rvDenture').textContent = mh.worn_denture
        ? (mh.worn_denture === 'yes' ? 'Yes' : 'No')
        : '—';
    document.getElementById('rvLastDentalVisit').textContent = mh.last_dental_visit
        ? mh.last_dental_visit + (mh.last_dental_visit_reason ? ' — ' + mh.last_dental_visit_reason : '')
        : '—';
}

// ====================== PSGC ADDRESS CASCADE ======================
const PSGC_BASE = 'https://psgc.gitlab.io/api';

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

// ====================== FINAL SUBMIT ======================
function confirmAppointment() {
    const submitBtn = document.getElementById('confirmBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    submitBtn.disabled = true;

    const phoneCheck = validatePhoneLocal();

    const payload = {
        phone: phoneCheck.valid ? phoneCheck.normalized : getFullPhone(),
        email: document.getElementById('contactEmail').value.trim() || bookingData.email || '',
        services: selectedServiceIds,
        date: selectedDate,
        time: selectedTime,
        full_name: getFullName(),
        age: document.getElementById('age').value.trim(),
        occupation: document.getElementById('occupation').value.trim(),
        region: selectedText('regionSelect'),
        province: selectedText('provinceSelect'),
        city: selectedText('citySelect'),
        barangay: selectedText('barangaySelect'),
        street_address: document.getElementById('streetAddress').value.trim(),
        notes: document.getElementById('patientNotes').value.trim(),
        termsAccepted: bookingData.termsAccepted ? 1 : 0,
        privacyAccepted: bookingData.privacyAccepted ? 1 : 0
    };

    fetch('api/save_appointment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            fetch('api/clear_booking_session.php');

            fetch('api/save_medical_history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign(
                    { appointment_id: data.appointment_id },
                    getMedicalHistoryData()
                ))
            }).catch(err => console.error('Failed to save medical history', err));

            document.getElementById('confirmedTrackingId').textContent = '#' + String(data.appointment_id).padStart(6, '0');
            document.getElementById('trackLink').href = 'track-appointment.php?token=' + encodeURIComponent(data.tracking_token || '');
            goToStep(5);
        } else {
            Swal.fire({ icon: 'error', title: 'Booking Failed', text: data.message || 'Please try again.', confirmButtonColor: '#e53e3e' });
        }
    })
    .catch(err => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save appointment. Please try again.', confirmButtonColor: '#e53e3e' });
        console.error(err);
    });
}

// ====================== INIT ======================
document.addEventListener('DOMContentLoaded', function() {
    initServiceCheckboxes();
    generateCalendar();
    loadRegions();

    document.getElementById('contactEmail').value = bookingData.email || '';

    // Pre-fill name fields if bookingData already has a full_name
    if (bookingData.full_name) {
        const parts = bookingData.full_name.trim().split(/\s+/);
        if (parts.length === 1) {
            document.getElementById('firstName').value = parts[0];
        } else if (parts.length === 2) {
            document.getElementById('firstName').value = parts[0];
            document.getElementById('lastName').value  = parts[1];
        } else {
            document.getElementById('firstName').value  = parts[0];
            document.getElementById('lastName').value   = parts[parts.length - 1];
            document.getElementById('middleName').value = parts.slice(1, -1).join(' ');
        }
    }

    // Live Title Case capitalization for name fields
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

    // ====================== PHONE PREFILL FROM bookingData ======================
    const phoneInput = document.getElementById('contactNumber');
    if (bookingData.phone) {
        const p = String(bookingData.phone).trim();
        if (p.startsWith('+63')) {
            phoneInput.value = p.slice(3).replace(/\D/g, '').slice(0, 10);
        } else if (p.startsWith('09')) {
            // Convert 09XXXXXXXXX to 9XXXXXXXXX (drop the leading 0)
            phoneInput.value = p.slice(1).replace(/\D/g, '').slice(0, 10);
        } else if (p.startsWith('9') && p.length >= 10) {
            phoneInput.value = p.replace(/\D/g, '').slice(0, 10);
        } else {
            phoneInput.value = p.replace(/\D/g, '').slice(0, 10);
        }
    }

    // Enforce digits-only, max 10 digits
    phoneInput.addEventListener('input', () => {
        phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 10);
        updatePhoneFeedback();
    });
    phoneInput.addEventListener('blur', updatePhoneFeedback);
    updatePhoneFeedback();
});
</script>
</body>
</html>