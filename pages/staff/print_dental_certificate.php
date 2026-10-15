<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: appointments.php');
    exit;
}

// ---- Fetch appointment ----
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    die('Appointment not found.');
}

// ---- Dental Chart (for treated teeth) ----
$patientKey = $appointment['phone'] ?: ($appointment['email'] ?: ('appt-' . $id));
$stmt = $conn->prepare("SELECT tooth_number, condition_key, notes FROM dental_chart WHERE patient_key = ?");
$stmt->bind_param('s', $patientKey);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chartMap = [];
foreach ($chartRows as $r) {
    $chartMap[(int)$r['tooth_number']] = $r;
}

// Treated teeth from dental chart
$treatedTeeth = [];
foreach ($chartMap as $num => $row) {
    if (($row['condition_key'] ?? 'healthy') !== 'healthy') {
        $treatedTeeth[] = $num;
    }
}
sort($treatedTeeth);

// Build address
$address = implode(', ', array_filter([
    $appointment['street_address'],
    $appointment['barangay'],
    $appointment['city'],
    $appointment['province'],
    $appointment['region'],
]));

// Certificate issue date — always today
$certDate = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dental Certificate — <?= h($appointment['full_name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Playfair+Display:wght@500;600;700;800&display=swap');

    * { box-sizing: border-box; }

    body {
        font-family: 'DM Sans', system-ui, sans-serif;
        background: #e8ecef;
        margin: 0;
        padding: 30px 15px;
        color: #111;
    }

    .toolbar {
        max-width: 8.5in;
        margin: 0 auto 20px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-primary { background: #0d9e8c; color: #fff; }
    .btn-primary:hover { background: #0a7d6e; }
    .btn-outline { background: #fff; color: #333; border: 1.5px solid #ccc; }
    .btn-outline:hover { background: #f5f5f5; }

    /* ===== Certificate paper ===== */
    .certificate {
        max-width: 8.5in;
        min-height: 11in;
        margin: 0 auto;
        background: #fff;
        padding: 0.55in 0.6in;
        box-shadow: 0 6px 24px rgba(0,0,0,0.15);
        font-size: 11.5px;
        line-height: 1.55;
        position: relative;
    }

    /* ================= HEADER ================= */
    .cert-header {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 18px;
        padding: 4px 0 14px;
    }

    .cert-logo-icon {
        width: 78px;
        height: 78px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #111;
    }
    .cert-logo-icon i {
        font-size: 74px;
        line-height: 1;
    }

    .cert-divider {
        width: 2px;
        align-self: stretch;
        background: #111;
        margin: 2px 4px;
    }

    .cert-clinic-text {
        line-height: 1.1;
        text-align: center;
    }
    .cert-clinic-text .doctor-name {
        font-family: 'DM Sans', sans-serif;
        font-weight: 800;
        font-size: 15px;
        color: #111;
        letter-spacing: 0.2px;
        margin: 0 0 2px;
    }
    .cert-clinic-text .clinic-name {
        font-family: 'DM Sans', sans-serif;
        font-weight: 900;
        font-size: 27px;
        color: #111;
        letter-spacing: -0.5px;
        margin: 0 0 4px;
        line-height: 1;
    }
    .cert-clinic-text .clinic-tagline {
        font-family: 'DM Sans', sans-serif;
        font-weight: 500;
        font-size: 10.5px;
        color: #333;
        letter-spacing: 0.2px;
        margin: 0;
    }

    .cert-header-divider {
        border-top: 1.5px solid #111;
        margin: 0 0 0;
    }

    /* Title */
    .cert-title {
        text-align: center;
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 800;
        letter-spacing: 3px;
        text-transform: uppercase;
        margin: 18px 0 4px;
    }
    .cert-title-line {
        border-bottom: 1px solid #333;
        margin: 4px auto 14px;
        max-width: 300px;
    }

    /* ================= DATE (FIXED ALIGNMENT) ================= */
    .cert-date {
        display: flex;
        justify-content: flex-end;
        font-size: 11px;
        margin: 4px 0 16px;
    }
    .cert-date-inner {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 200px;
    }
    .cert-date-inner .date-value {
        width: 100%;
        border-bottom: 1px solid #333;
        text-align: center;
        padding: 2px 6px 3px;
        font-weight: 600;
        min-height: 18px;
    }
    .cert-date-inner .date-label {
        font-size: 9.5px;
        color: #555;
        margin-top: 2px;
        text-align: center;
        letter-spacing: 0.5px;
    }

    /* Salutation */
    .cert-salutation {
        font-weight: 700;
        margin-bottom: 6px;
        font-size: 11.5px;
    }

    /* ================= BODY (full-width fill lines) ================= */
    .cert-body {
        font-size: 11.5px;
        margin-bottom: 10px;
    }

    .fill-row {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        width: 100%;
        margin: 4px 0;
    }
    .fill-row .fill-text {
        flex-shrink: 0;
        font-weight: 500;
        padding-bottom: 1px;
    }
    .fill-row .fill-line {
        flex: 1;
        min-width: 0;
        border-bottom: 1px solid #333;
        padding: 0 8px 1px;
        font-weight: 600;
        text-align: center;
        min-height: 16px;
        line-height: 1.3;
    }
    .fill-row .fill-line.left { text-align: left; padding-left: 10px; }
    .fill-row .fill-line.fixed-sm { flex: 0 0 80px; }
    .fill-row .fill-line.fixed-md { flex: 0 0 140px; }
    .fill-row .fill-line.blank { min-height: 16px; }

    /* Service checkboxes */
    .services-section {
        margin-top: 8px;
        font-size: 11.5px;
    }
    .services-list {
        margin: 4px 0 6px 14px;
        line-height: 1.7;
    }
    .services-list .service-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .checkbox {
        display: inline-block;
        width: 12px;
        height: 12px;
        border: 1px solid #333;
        position: relative;
        flex-shrink: 0;
        margin-right: 2px;
    }
    .other-line {
        display: inline-block;
        border-bottom: 1px solid #333;
        min-width: 220px;
        padding: 0 6px 1px;
        font-weight: 600;
    }

    /* Teeth chart row */
    .teeth-block {
        margin-top: 10px;
        font-size: 11.5px;
    }
    .teeth-row {
        font-family: 'DM Sans', monospace;
        font-size: 11px;
        letter-spacing: 0.5px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        padding: 1px 0;
    }
    .teeth-row .quadrant {
        display: flex;
        gap: 10px;
    }
    .teeth-row .quadrant span {
        display: inline-block;
        min-width: 16px;
        text-align: center;
        color: #555;
    }
    .teeth-row .quadrant span.marked {
        color: #b91c1c;
        font-weight: 800;
        text-decoration: underline;
    }
    .teeth-letter {
        font-weight: 800;
        font-size: 12px;
        color: #111;
        min-width: 18px;
        text-align: center;
    }

    /* Remarks */
    .remarks-block {
        margin-top: 14px;
    }
    .remarks-label {
        font-weight: 600;
        margin-bottom: 2px;
    }
    .remarks-value {
        border-bottom: 1px solid #333;
        min-height: 20px;
        padding: 1px 4px;
        font-size: 11.5px;
        margin-bottom: 12px;
    }

    /* Rest / Fit-to-work */
    .rest-fit-block {
        margin-top: 10px;
        padding-left: 14px;
        font-size: 11.5px;
        line-height: 1.9;
    }
    .rest-fit-block .checkbox { margin-right: 6px; }
    .rest-fit-block .fill-inline {
        display: inline-block;
        border-bottom: 1px solid #333;
        min-width: 70px;
        padding: 0 6px 1px;
        font-weight: 600;
        text-align: center;
    }

    /* Fine print */
    .fine-print {
        font-size: 9.5px;
        color: #444;
        margin-top: 18px;
        font-style: italic;
        line-height: 1.5;
    }

    /* Footer signature */
    .cert-footer {
        margin-top: 30px;
        text-align: right;
        font-size: 11px;
    }
    .cert-footer .signature-name {
        font-weight: 800;
        text-transform: uppercase;
        font-size: 11.5px;
        letter-spacing: 0.3px;
    }
    .cert-footer .signature-line {
        border-bottom: 1px solid #333;
        width: 240px;
        margin: 0 0 4px auto;
        height: 26px;
    }
    .cert-footer .license-line {
        font-size: 10px;
        color: #555;
        margin-top: 2px;
    }

    /* ===== Print rules ===== */
    @media print {
        @page {
            size: letter;
            margin: 0.4in;
        }
        body {
            background: #fff;
            padding: 0;
            margin: 0;
        }
        .toolbar { display: none !important; }
        .certificate {
            box-shadow: none;
            margin: 0;
            padding: 0.2in 0.3in;
            max-width: 100%;
            min-height: auto;
        }
    }

    @media (max-width: 640px) {
        .certificate { padding: 20px 18px; font-size: 10.5px; }
        .cert-logo-icon { width: 56px; height: 56px; }
        .cert-logo-icon i { font-size: 52px; }
        .cert-clinic-text .doctor-name { font-size: 12px; }
        .cert-clinic-text .clinic-name { font-size: 20px; }
        .cert-clinic-text .clinic-tagline { font-size: 9px; }
        .teeth-row { font-size: 9.5px; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="patient-record-history.php?id=<?= $id ?>" class="btn btn-outline">
        <i>←</i> Back
    </a>
    <button class="btn btn-primary" onclick="window.print()">
        🖨️ Print Certificate
    </button>
</div>

<div class="certificate">
 
    <!-- ===================== HEADER ===================== -->
    <div class="cert-header">
        
        <div class="cert-logo-icon">
            <i class="fas fa-tooth"></i>
        </div>
        <div class="cert-divider"></div>
        <div class="cert-clinic-text">
            <div class="doctor-name">Dr. Brendalyn Wansi Calacat</div>
            <div class="clinic-name">Dental Clinic</div>
            <div class="clinic-tagline">Specialized Dentistry With Personalized Care</div>
        </div>
    </div>
    <div class="cert-header-divider"></div>
 
    <!-- ===================== TITLE ===================== -->
    <div class="cert-title">Dental Certificate</div>
    <div class="cert-title-line"></div>

    <!-- ===================== DATE (always today) ===================== -->
    <div class="cert-date">
        <div class="cert-date-inner">
            <div class="date-value"><?= h($certDate) ?></div>
            <div class="date-label">Date</div>
        </div>
    </div>

    <!-- ===================== SALUTATION ===================== -->
    <div class="cert-salutation">TO WHOM IT MAY CONCERN,</div>

    <!-- ===================== BODY ===================== -->
    <div class="cert-body">

        <!-- Intro -->
        <div class="fill-row">
            <span class="fill-text">This is to certify that I have attended to the dental treatment of</span>
        </div>

        <!-- Name + Age -->
        <div class="fill-row">
            <span class="fill-line"><?= h($appointment['full_name'] ?: '') ?></span>
            <span class="fill-text" style="padding: 0 2px;">,</span>
            <span class="fill-line fixed-sm"><?= h($appointment['age'] ?: '') ?></span>
            <span class="fill-text" style="padding-bottom: 1px;">age,</span>
        </div>

        <!-- Address -->
        <div class="fill-row">
            <span class="fill-text" style="flex: 0 0 auto;">residing at</span>
            <span class="fill-line left"><?= h($address ?: '') ?></span>
        </div>

        <!-- Found suffering from (BLANK) -->
        <div class="fill-row">
            <span class="fill-text" style="flex: 0 0 auto;">was found suffering from</span>
            <span class="fill-line left blank">&nbsp;</span>
        </div>

        <!-- Last intro -->
        <div class="fill-row">
            <span class="fill-text">had undergone the following service/s:</span>
        </div>

    </div>

    <!-- ===================== SERVICES (ALL BLANK) ===================== -->
    <div class="services-section">
        <div class="services-list">
            <div class="service-item">
                <span class="checkbox"></span>
                Consultation
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Oral Prophylaxis
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Orthodontic Treatment:
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Permanent Restoration (FILLINGS)
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Prosthodontic Treatment:
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Extraction
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Endodontic Treatment (Root Canal Therapy)
            </div>
            <div class="service-item">
                <span class="checkbox"></span>
                Other/s: <span class="other-line" style="min-width: 260px;"></span>
            </div>
        </div>
        On the following teeth
    </div>

    <!-- ===================== TEETH CHART ===================== -->
    <div class="teeth-block">
        <!-- Upper row -->
        <div class="teeth-row" style="margin-top: 6px;">
            <span class="teeth-letter">R</span>
            <div class="quadrant">
                <?php foreach ([18,17,16,15,14,13,12,11] as $num): ?>
                    <span class="<?= in_array($num, $treatedTeeth) ? 'marked' : '' ?>"><?= $num ?></span>
                <?php endforeach; ?>
            </div>
            <div class="quadrant">
                <?php foreach ([21,22,23,24,25,26,27,28] as $num): ?>
                    <span class="<?= in_array($num, $treatedTeeth) ? 'marked' : '' ?>"><?= $num ?></span>
                <?php endforeach; ?>
            </div>
            <span class="teeth-letter">L</span>
        </div>
        <!-- Lower row -->
        <div class="teeth-row">
            <span class="teeth-letter">R</span>
            <div class="quadrant">
                <?php foreach ([48,47,46,45,44,43,42,41] as $num): ?>
                    <span class="<?= in_array($num, $treatedTeeth) ? 'marked' : '' ?>"><?= $num ?></span>
                <?php endforeach; ?>
            </div>
            <div class="quadrant">
                <?php foreach ([31,32,33,34,35,36,37,38] as $num): ?>
                    <span class="<?= in_array($num, $treatedTeeth) ? 'marked' : '' ?>"><?= $num ?></span>
                <?php endforeach; ?>
            </div>
            <span class="teeth-letter">L</span>
        </div>
    </div>

    <!-- ===================== REMARKS ===================== -->
    <div class="remarks-block">
        <div class="remarks-label">Remarks:</div>
        <div class="remarks-value">
            <?= h($appointment['internal_notes'] ?? '') ?>
        </div>
    </div>

    <!-- ===================== REST / FIT ===================== -->
    <div class="rest-fit-block">
        <div>
            <span class="checkbox"></span> Rest for <span class="fill-inline">____</span> days
        </div>
        <div>
            <span class="checkbox"></span> Fit to work · Date:
            <span class="fill-inline">____</span> /
            <span class="fill-inline">____</span> /
            <span class="fill-inline">____</span>
        </div>
    </div>

    <!-- ===================== FINE PRINT ===================== -->
    <div class="fine-print">
        *This document is issued upon request of the above mentioned individual for whatever purpose it may serve. However,
        this could not be used in any Medico-Legal case and/or any court of law in the Philippines.
    </div>

    <!-- ===================== FOOTER ===================== -->
    <div class="cert-footer">
        <div class="signature-line"></div>
        <div class="signature-name">Brendalyn Wansi Calacat, DMD</div>
        <div class="license-line">License No. _______________</div>
        <div class="license-line">PTR No. _______________</div>
    </div>

</div>

<script>
    window.addEventListener('load', function() {
        setTimeout(function() {
            // Uncomment to auto-open the print dialog:
            // window.print();
        }, 300);
    });
</script>

</body>
</html>