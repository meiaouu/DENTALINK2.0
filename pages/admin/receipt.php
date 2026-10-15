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
function yn($v) {
    if ($v === 'yes') return '<span class="pill pill-yes">Yes</span>';
    if ($v === 'no') return '<span class="pill pill-no">No</span>';
    return '<span class="pill pill-unknown">—</span>';
}

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
        55 => '2nd Molar', 54 => '1st Molar', 53 => 'Canine',
        52 => 'Lateral Incisor', 51 => 'Central Incisor',
        61 => 'Central Incisor', 62 => 'Lateral Incisor', 63 => 'Canine',
        64 => '1st Molar', 65 => '2nd Molar',
        71 => 'Central Incisor', 72 => 'Lateral Incisor', 73 => 'Canine',
        74 => '1st Molar', 75 => '2nd Molar',
        85 => '2nd Molar', 84 => '1st Molar', 83 => 'Canine',
        82 => 'Lateral Incisor', 81 => 'Central Incisor',
    ];
    return $names[$num] ?? 'Tooth';
}

$PRIMARY_TO_ADULT_IMAGE = [
    55 => 17, 54 => 16, 53 => 13, 52 => 12, 51 => 11,
    61 => 21, 62 => 22, 63 => 23, 64 => 26, 65 => 27,
    71 => 31, 72 => 32, 73 => 33, 74 => 36, 75 => 37,
    81 => 41, 82 => 42, 83 => 43, 84 => 46, 85 => 47,
];

function toothImage($num) {
    global $PRIMARY_TO_ADULT_IMAGE;
    $n = (int)$num;
    if (isset($PRIMARY_TO_ADULT_IMAGE[$n])) {
        $n = $PRIMARY_TO_ADULT_IMAGE[$n];
    }
    $prefix = ($n >= 11 && $n <= 28) ? 'sup' : 'inf';
    return "../../assets/img/dentadura-{$prefix}-{$n}.png";
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    header('Location: appointments.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM patient_record_archive WHERE receipt_code = ? LIMIT 1");
$stmt->bind_param('s', $code);
$stmt->execute();
$archive = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$archive) {
    header('Location: appointments.php');
    exit;
}

$snapshot = json_decode($archive['snapshot_json'], true) ?: [];
$appointment       = $snapshot['appointment'] ?? [];
$dentist           = $snapshot['dentist'] ?? null;
$services          = $snapshot['services'] ?? [];
$totalPrice        = $snapshot['total_price'] ?? 0;
$history           = $snapshot['medical_history'] ?? null;
$chartRows         = $snapshot['dental_chart'] ?? [];
$otherAppointments = $snapshot['other_appointments'] ?? [];

$isNew     = isset($_GET['new']) && $_GET['new'] == '1';
$isExisting= isset($_GET['existing']) && $_GET['existing'] == '1';
$isUpdated = isset($_GET['updated']) && $_GET['updated'] == '1';

$chartMap = [];
foreach ($chartRows as $r) $chartMap[$r['tooth_number']] = $r;

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
    'other'      => ['label' => 'Other',          'color' => '#0891b2'],
];
$upperArch = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
$lowerArch = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

$primaryUpperArch = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
$primaryLowerArch = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

$patientAge = is_numeric($appointment['age'] ?? null) ? (int)$appointment['age'] : null;
$chartView = ($patientAge !== null && $patientAge < 13) ? 'kids' : 'adult';
$chartArchUpper = $chartView === 'kids' ? $primaryUpperArch : $upperArch;
$chartArchLower = $chartView === 'kids' ? $primaryLowerArch : $lowerArch;
$chartLabelSuffix = $chartView === 'kids' ? ' — Primary Teeth' : '';

$address = implode(', ', array_filter([
    $appointment['street_address'] ?? '',
    $appointment['barangay'] ?? '',
    $appointment['city'] ?? '',
    $appointment['province'] ?? '',
    $appointment['region'] ?? '',
]));

$archiveTimestamp = $archive['created_at'] ?? $archive['generated_at'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Record Receipt · <?= h($archive['receipt_code']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
* { font-family: 'DM Sans', system-ui, sans-serif; }
h1, h2, h3 { font-family: 'Playfair Display', Georgia, serif; }
:root {
    --navy: #0b1628; --teal: #0d9e8c; --teal-dark: #0a7d6e; --teal-light: #e6f7f5;
    --slate: #64748b; --red: #b91c1c; --red-bg: #fee9e7;
}
body { background: #f4f6f9; }
.sheet {
    max-width: 1020px; margin: 32px auto; background: white; border-radius: 18px;
    box-shadow: 0 12px 40px rgba(11,22,40,.1); border: 1px solid #eef1f6; overflow: hidden;
}
.sheet-header {
    background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%);
    padding: 28px 32px; color: white; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;
}
.sheet-header h1 { font-size: 22px; font-weight: 700; }
.sheet-header .clinic-sub { font-size: 12.5px; color: rgba(255,255,255,.6); margin-top: 3px; }
.receipt-meta { text-align: right; font-size: 12px; color: rgba(255,255,255,.75); }
.receipt-meta .code { font-size: 14px; font-weight: 700; color: white; }

.sheet-body { padding: 28px 32px; }
.section { margin-bottom: 26px; }
.section:last-child { margin-bottom: 0; }
.section-title {
    font-size: 12.5px; font-weight: 800; color: var(--navy); text-transform: uppercase;
    letter-spacing: .4px; display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
    padding-bottom: 8px; border-bottom: 1px solid #eef1f6;
}
.section-title i { color: var(--teal); }
.chart-type-badge {
    margin-left: auto;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    color: var(--teal-dark);
    background: var(--teal-light);
    padding: 3px 11px;
    border-radius: 50px;
}

.grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; }
.grid2 .label { font-size: 10.5px; color: #94a3b8; text-transform: uppercase; letter-spacing: .3px; font-weight: 700; display: block; margin-bottom: 2px; }
.grid2 .value { font-size: 13.5px; color: var(--navy); font-weight: 600; }

.pill { display:inline-block; padding:2px 10px; border-radius:50px; font-size:11px; font-weight:700; }
.pill-yes { background: var(--red-bg); color: var(--red); }
.pill-no  { background: #dcfce7; color:#166534; }
.pill-unknown { background: #eef1f6; color: var(--slate); }

.service-line { display:flex; justify-content:space-between; padding:7px 0; font-size:13px; border-bottom:1px solid #f1f5f9; }
.service-total { display:flex; justify-content:space-between; padding:10px 0 0; font-weight:800; color:var(--navy); border-top:2px solid #e2e8f0; margin-top:6px; font-size:14px; }

.notes-box { background:#f7f9fc; border-radius:10px; padding:10px 13px; font-size:13px; color:var(--navy); line-height:1.6; margin-top: 8px; }

.dental-chart-container {
    background: #fafbfc;
    border-radius: 12px;
    padding: 16px 20px;
    border: 1px solid #eef1f6;
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
    width: 48px;
    padding: 4px 2px;
    border-radius: 8px;
    background: white;
    border: 1px solid #eef1f6;
    transition: all 0.15s;
}
.chart-tooth .tooth-img {
    width: 30px;
    height: 44px;
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
    max-width: 44px;
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
    text-align: center;
}
.chart-tooth.missing .tooth-img img { opacity: 0.2; }
.chart-tooth.missing .tooth-img { border-color: #cbd5e1; background: #f7f9fc; }

.chart-divider {
    border-top: 1px dashed #dce2ea;
    margin: 8px 0 10px;
}

.history-table { width:100%; border-collapse: collapse; font-size: 12.5px; }
.history-table th { text-align:left; padding: 7px 9px; background:#f7f9fc; color:#94a3b8; font-size: 10.5px; text-transform:uppercase; font-weight:700; }
.history-table td { padding: 8px 9px; border-bottom: 1px solid #f1f5f9; color: var(--navy); }

.empty-note { color:#94a3b8; font-size: 13px; }

.actions { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 32px; background: #fafbfc; border-top: 1px solid #eef1f6; }
.btn { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 7px; text-decoration: none; }
.btn-print { background: var(--teal); color: white; }
.btn-print:hover { background: var(--teal-dark); }
.btn-back { background: white; color: var(--slate); border: 1px solid #e2e8f0; }
.btn-back:hover { background: #f1f4f8; }

@media print {
    body { background: white; }
    .actions { display: none; }
    .sheet { box-shadow: none; margin: 0; border-radius: 0; max-width: 100%; }
    .dental-chart-container { background: white; border: 1px solid #e2e8f0; }
}
</style>
</head>
<body>

<div class="sheet">
  <div class="sheet-header">
    <div>
        <h1><i class="fas fa-tooth" style="color:#0d9e8c;"></i> Wansi Calacat Dental Clinic</h1>
        <div class="clinic-sub">Full Patient Record &amp; Visit Receipt</div>
    </div>
    <div class="receipt-meta">
        <div class="code"><?= h($archive['receipt_code']) ?></div>
        <div>Generated <?= h($archiveTimestamp ? date('F j, Y g:i A', strtotime($archiveTimestamp)) : '—') ?></div>
        <div>By <?= h($archive['generated_by'] ?: 'Staff') ?></div>
        <?php if ($isNew && !$isUpdated): ?>
        <div style="color: #0d9e8c; font-weight: 600; margin-top: 4px; background: #e6f7f5; padding: 2px 12px; border-radius: 20px; font-size: 11px;">
            <i class="fas fa-check-circle"></i> New Record Created
        </div>
        <?php endif; ?>
        <?php if ($isExisting): ?>
        <div style="color: #64748b; font-weight: 600; margin-top: 4px; background: #f1f5f9; padding: 2px 12px; border-radius: 20px; font-size: 11px;">
            <i class="fas fa-clock"></i> Existing Record (View Only)
        </div>
        <?php endif; ?>
        <?php if ($isUpdated): ?>
        <div style="color: #b45309; font-weight: 600; margin-top: 4px; background: #fef3d7; padding: 2px 12px; border-radius: 20px; font-size: 11px;">
            <i class="fas fa-sync"></i> Record Updated
        </div>
        <?php endif; ?>
    </div>
</div>

    <div class="sheet-body">

        <!-- Personal Info -->
        <div class="section">
            <div class="section-title"><i class="fas fa-id-card"></i> Personal Information</div>
            <div class="grid2">
                <div><span class="label">Full Name</span><div class="value"><?= h($appointment['full_name'] ?? '—') ?></div></div>
                <div><span class="label">Phone</span><div class="value"><?= h($appointment['phone'] ?? '—') ?></div></div>
                <div><span class="label">Email</span><div class="value"><?= h($appointment['email'] ?? '—') ?></div></div>
                <div><span class="label">Age</span><div class="value"><?= h($appointment['age'] ?? '—') ?></div></div>
                <div><span class="label">Occupation</span><div class="value"><?= h($appointment['occupation'] ?? '—') ?></div></div>
                <div><span class="label">Address</span><div class="value"><?= h($address ?: '—') ?></div></div>
            </div>
        </div>

        <!-- Appointment Info -->
        <div class="section">
            <div class="section-title"><i class="fas fa-calendar-check"></i> Appointment Details</div>
            <div class="grid2" style="margin-bottom: 14px;">
                <div><span class="label">Date</span><div class="value"><?= h(!empty($appointment['appointment_date']) ? date('M j, Y', strtotime($appointment['appointment_date'])) : '—') ?></div></div>
                <div><span class="label">Time</span><div class="value"><?= h(!empty($appointment['appointment_time']) ? date('g:i A', strtotime($appointment['appointment_time'])) : '—') ?></div></div>
                <div><span class="label">Status</span><div class="value"><?= h(ucfirst($appointment['status'] ?? '—')) ?></div></div>
                <div><span class="label">Dentist</span><div class="value"><?= $dentist ? h('Dr. ' . $dentist['first_name'] . ' ' . $dentist['last_name']) : 'Unassigned' ?></div></div>
            </div>

            <?php if ($services): ?>
                <?php foreach ($services as $s): ?>
                    <div class="service-line"><span><?= h($s['title']) ?></span><span>₱<?= number_format($s['price'], 2) ?></span></div>
                <?php endforeach; ?>
                <div class="service-total"><span>Total</span><span>₱<?= number_format($totalPrice, 2) ?></span></div>
            <?php else: ?>
                <p class="empty-note">No services recorded for this appointment.</p>
            <?php endif; ?>

            <?php if (!empty($appointment['notes'])): ?>
                <div class="notes-box"><strong>Patient notes:</strong><br><?= nl2br(h($appointment['notes'])) ?></div>
            <?php endif; ?>
        </div>

        <!-- Medical & Dental History -->
        <div class="section">
            <div class="section-title"><i class="fas fa-file-medical"></i> Medical &amp; Dental History</div>
            <?php if ($history): ?>
                <div class="grid2" style="margin-bottom: 14px;">
                    <div><span class="label">Under physician's care</span><div class="value"><?= yn($history['under_physician_care'] ?? null) ?></div></div>
                    <div><span class="label">Physician name</span><div class="value"><?= h($history['physician_name'] ?? '—') ?></div></div>
                    <div><span class="label">Pregnant</span><div class="value"><?= yn($history['pregnant'] ?? null) ?></div></div>
                    <div><span class="label">Taking medicine</span><div class="value"><?= yn($history['taking_medicine'] ?? null) ?></div></div>
                </div>

                <div class="grid2" style="margin-bottom: 14px;">
                    <div><span class="label">Allergic — Local Anesthesia</span><div class="value"><?= yn($history['allergic_local_anesthesia'] ?? null) ?></div></div>
                    <div><span class="label">Allergic — Antibiotics</span><div class="value"><?= yn($history['allergic_antibiotics'] ?? null) ?></div></div>
                    <div><span class="label">Allergic — Pain Killers</span><div class="value"><?= yn($history['allergic_pain_killer'] ?? null) ?></div></div>
                    <div><span class="label">Allergic — Other</span><div class="value"><?= yn($history['allergic_other'] ?? null) ?></div></div>
                </div>

                <div class="grid2">
                    <div><span class="label">Hospitalized</span><div class="value"><?= yn($history['hospitalized'] ?? null) ?></div></div>
                    <div><span class="label">When</span><div class="value"><?= h($history['hospitalized_when'] ?? '—') ?></div></div>
                    <div><span class="label">Worn Denture</span><div class="value"><?= yn($history['worn_denture'] ?? null) ?></div></div>
                    <div><span class="label">Last Dental Visit</span><div class="value"><?= h($history['last_dental_visit'] ?? '—') ?></div></div>
                </div>

                <?php
                    $extraNotes = [
                        'Medicine details'          => $history['medicine_details'] ?? '',
                        'Medical conditions'        => $history['conditions'] ?? '',
                        'Other condition details'   => $history['other_condition_details'] ?? '',
                        'Other allergy details'     => $history['allergic_other_details'] ?? '',
                        'Reason for hospitalization'=> $history['hospitalized_why'] ?? '',
                        'Reason for last visit'     => $history['last_dental_visit_reason'] ?? '',
                    ];
                ?>
                <?php foreach ($extraNotes as $label => $val): ?>
                    <?php if (!empty($val)): ?>
                        <div class="notes-box"><strong><?= h($label) ?>:</strong><br><?= nl2br(h($val)) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty-note">No medical/dental history was on file at the time this record was generated.</p>
            <?php endif; ?>
        </div>

        <!-- Dental Chart -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-tooth"></i> Dental Chart
                <span class="chart-type-badge">
                    <i class="fas <?= $chartView === 'kids' ? 'fa-child' : 'fa-user' ?>"></i>
                    <?= $chartView === 'kids' ? 'Primary (Baby) Teeth' : 'Permanent (Adult) Teeth' ?>
                </span>
            </div>

            <?php if ($chartMap): ?>
                <div class="dental-chart-container">
                    <div class="chart-legend">
                        <?php foreach ($dentalConditions as $key => $c): ?>
                            <span class="legend-item">
                                <span class="legend-dot" style="background:<?= $c['color'] ?>;"></span>
                                <?= h($c['label']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="chart-arch-label">Upper Arch (Maxillary)<?= h($chartLabelSuffix) ?></div>
                    <div class="chart-tooth-row">
                        <?php foreach ($chartArchUpper as $num): ?>
                            <?php 
                            $entry = $chartMap[$num] ?? null;
                            $cond = $entry['condition_key'] ?? 'healthy';
                            $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                            $toothName = getToothName($num);
                            $condLabel = $dentalConditions[$cond]['label'];
                            $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                            $isMissing = $cond === 'missing';
                            ?>
                            <div class="chart-tooth <?= $isMissing ? 'missing' : '' ?>" style="--tooth-color: <?= $color ?>;">
                                <div class="tooth-img" style="border-color: <?= $color ?>;">
                                    <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                                </div>
                                <span class="tooth-num"><?= $num ?></span>
                                <span class="tooth-name"><?= h($toothName) ?></span>
                                <?php if ($cond !== 'healthy'): ?>
                                    <span class="tooth-condition" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="chart-divider"></div>

                    <div class="chart-arch-label">Lower Arch (Mandibular)<?= h($chartLabelSuffix) ?></div>
                    <div class="chart-tooth-row">
                        <?php foreach ($chartArchLower as $num): ?>
                            <?php 
                            $entry = $chartMap[$num] ?? null;
                            $cond = $entry['condition_key'] ?? 'healthy';
                            $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                            $toothName = getToothName($num);
                            $condLabel = $dentalConditions[$cond]['label'];
                            $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                            $isMissing = $cond === 'missing';
                            ?>
                            <div class="chart-tooth <?= $isMissing ? 'missing' : '' ?>" style="--tooth-color: <?= $color ?>;">
                                <div class="tooth-img" style="border-color: <?= $color ?>;">
                                    <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                                </div>
                                <span class="tooth-num"><?= $num ?></span>
                                <span class="tooth-name"><?= h($toothName) ?></span>
                                <?php if ($cond !== 'healthy'): ?>
                                    <span class="tooth-condition" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p style="font-size:10.5px; color:#94a3b8; margin-top:12px; text-align:center; border-top:1px solid #eef1f6; padding-top:10px;">
                        <?= count($chartMap) ?> tooth record(s) on file · 
                        <?php
                            $markedCount = 0;
                            foreach ($chartMap as $r) {
                                if ($r['condition_key'] !== 'healthy') $markedCount++;
                            }
                            echo $markedCount . ' marked with conditions';
                        ?>
                    </p>
                </div>
            <?php else: ?>
                <p class="empty-note">No dental chart entries were recorded at the time this record was generated.</p>
            <?php endif; ?>
        </div>

        <!-- Other Appointments -->
        <?php if ($otherAppointments): ?>
        <div class="section">
            <div class="section-title"><i class="fas fa-history"></i> Other Appointments on File</div>
            <table class="history-table">
                <thead><tr><th>Date</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($otherAppointments as $oa): ?>
                    <tr>
                        <td><?= h(!empty($oa['appointment_date']) ? date('M j, Y', strtotime($oa['appointment_date'])) : '—') ?></td>
                        <td><?= h(!empty($oa['appointment_time']) ? date('g:i A', strtotime($oa['appointment_time'])) : '—') ?></td>
                        <td><?= h(ucfirst($oa['status'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <div class="actions">
        <a href="patient-record.php?id=<?= (int)$archive['appointment_id'] ?>" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back to Record</a>
        <button type="button" class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
</div>

</body>
</html>