<?php
require '../../conn/conn.php';
require_once '../../functions/appointment_patient_search.php';
try { $recordAccess = aps_staff_authorized($conn); }
catch (Throwable $e) { error_log('Patient record authorization failed: ' . $e->getMessage()); $recordAccess = false; }
if (!$recordAccess) { http_response_code(403); exit('Authorized staff access required.'); }
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function visitScheduledDate($value) {
    $date = (string)($value ?? '');
    if ($date === '') return 'Not scheduled';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        && (int)$m[1] >= 1900) {
        return date('M j, Y', strtotime($date));
    }
    return 'Invalid stored date — check original booking';
}
function visitScheduledTime($value) {
    $time = (string)($value ?? '');
    if ($time === '') return 'Not scheduled';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
        return 'Invalid stored time — check booking';
    }
    return date('g:i A', strtotime($time));
}
function yn($v) {
    if ($v === 'yes') return '<span class="pill pill-yes">Yes</span>';
    if ($v === 'no') return '<span class="pill pill-no">No</span>';
    return '<span class="pill pill-unknown">—</span>';
}

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

function getToothType($num) {
    if (in_array($num, [11,12,21,22,31,32,41,42, 51,52,61,62,71,72,81,82])) return 'Incisor';
    if (in_array($num, [13,23,33,43, 53,63,73,83])) return 'Canine';
    if (in_array($num, [14,15,24,25,34,35,44,45])) return 'Premolar';
    if (in_array($num, [16,17,18,26,27,28,36,37,38,46,47,48, 54,64,74,84,55,65,75,85])) return 'Molar';
    return 'Tooth';
}

$PRIMARY_TOOTH_NUMBERS = [55,54,53,52,51,61,62,63,64,65,71,72,73,74,75,81,82,83,84,85];

function isPrimaryToothNum($num) {
    global $PRIMARY_TOOTH_NUMBERS;
    return in_array((int)$num, $PRIMARY_TOOTH_NUMBERS);
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
$upperArch = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
$lowerArch = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

$primaryUpperArch = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
$primaryLowerArch = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: appointments.php');
    exit;
}

// ---- Appointment ----
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: appointments.php');
    exit;
}

// This URL identifies an APPOINTMENT, not a patient.
$linkedPatient = null;
if (!empty($appointment['patient_id'])) {
    $patientStmt = $conn->prepare('SELECT * FROM patients WHERE id = ?');
    $linkedId = (int)$appointment['patient_id'];
    $patientStmt->bind_param('i', $linkedId);
    $patientStmt->execute();
    $linkedPatient = $patientStmt->get_result()->fetch_assoc() ?: null;
    $patientStmt->close();
}
// Reuse existing dental_chart patient_key format to preserve historical entries.
$patientKey = $appointment['phone'] ?: ($appointment['email'] ?: ('appt-' . $id));


$patientAge = is_numeric($appointment['age'] ?? null) ? (int)$appointment['age'] : null;
$defaultChartView = ($patientAge !== null && $patientAge < 13) ? 'kids' : 'adult';

// Per-visit procedure storage. Never silently create/alter database tables from a page.
$hasProcedureTable = false;
try {
    $pcheck = $conn->query("SHOW TABLES LIKE 'appointment_procedures'");
    $hasProcedureTable = $pcheck && $pcheck->num_rows > 0;
} catch (Throwable $e) { error_log('Procedure table check: ' . $e->getMessage()); }
// Determine whether soft-removal columns are available BEFORE handling POST updates.
// Never mutate records or infer installed migrations from only one flag.
$hasBookedVisitFlags = false;
if ($hasProcedureTable) {
    try {
        $flagNames = [];
        $resFlags = $conn->query("SHOW COLUMNS FROM appointment_procedures WHERE Field IN ('booked_auto', 'is_removed')");
        if ($resFlags) while ($flag = $resFlags->fetch_assoc()) $flagNames[] = $flag['Field'];
        $hasBookedVisitFlags = in_array('booked_auto', $flagNames, true) && in_array('is_removed', $flagNames, true);
    } catch (Throwable $e) {
        error_log('Could not check visit procedure flags: ' . $e->getMessage());
    }
}
if (empty($_SESSION['staff_patient_record_csrf'])) {
    $_SESSION['staff_patient_record_csrf'] = bin2hex(random_bytes(32));
}
$recordCsrf = $_SESSION['staff_patient_record_csrf'];
// ========================= AJAX HANDLERS =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Staff patient record is read-only for clinical chart and treatment edits.
    // Editing procedures and tooth conditions requires a separate dentist-authorized endpoint.
    if (in_array((string)$_POST['action'], ['save_tooth', 'reset_tooth', 'update_medical'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Clinical chart changes require an authorized dentist.']);
        exit;
    }

    // Protect all write operations, including the existing page edit actions.
    if (!hash_equals($recordCsrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Session expired. Refresh this page.']);
        exit;
    }
    // The URL appointment ID is authoritative. Do not accept an unrelated record ID.
    if (isset($_POST['appointment_id']) && (int)$_POST['appointment_id'] !== $id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Appointment mismatch.']);
        exit;
    }
    // Unified appointment Edit Mode. Scheduled date/time are NEVER writeable here.
    // Dentist assignment and individual visit charges are saved atomically.
    if ((string)($_POST['action'] ?? '') === 'save_visit_edits') {
        try {
            $newDentistId = filter_var($_POST['dentist_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$newDentistId || $newDentistId < 1) {
                throw new RuntimeException('Select an active dentist before saving.');
            }
            $payload = json_decode((string)($_POST['charges'] ?? '[]'), true);
            if (!is_array($payload) || count($payload) > 100) {
                throw new RuntimeException('Invalid list of appointment charges.');
            }
            $clean = [];
            foreach ($payload as $entry) {
                if (!is_array($entry)) throw new RuntimeException('Invalid charge entry.');
                $pid = filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT);
                $amount = trim((string)($entry['charge'] ?? ''));
                if (!$pid || $pid < 1 || isset($clean[$pid]) ||
                    !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $amount)) {
                    throw new RuntimeException('Enter a valid amount (0–99,999,999.99) for each service.');
                }
                $clean[$pid] = (float)$amount;
            }
            if ($clean && (!$hasProcedureTable || !$hasBookedVisitFlags)) {
                throw new RuntimeException('Install the procedure/visit-charges migration first.');
            }
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT status, dentist_id FROM appointments WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $id);
            $lock->execute();
            $current = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$current || !in_array($current['status'], ['confirmed', 'in_progress'], true)) {
                throw new RuntimeException('This appointment is not available for editing. Refresh the page.');
            }
            if ($clean && $current['status'] !== 'in_progress') {
                throw new RuntimeException('Visit charges can only be edited while treatment is in progress.');
            }
            $dentistCheck = $conn->prepare('SELECT id FROM dentists WHERE id = ? AND is_active = 1 LIMIT 1');
            $dentistCheck->bind_param('i', $newDentistId);
            $dentistCheck->execute();
            $activeDentist = $dentistCheck->get_result()->fetch_assoc();
            $dentistCheck->close();
            if (!$activeDentist) throw new RuntimeException('The selected dentist is not active.');
            $billCheck = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
            $billCheck->bind_param('i', $id);
            $billCheck->execute();
            $hasBill = (bool)$billCheck->get_result()->fetch_assoc();
            $billCheck->close();
            if ($hasBill) {
                throw new RuntimeException('An invoice already exists. Changes must be reconciled in Billing.');
            }
            $chargeUpdates = 0;
            if ($clean) {
                $fetch = $conn->prepare('SELECT id, charge FROM appointment_procedures WHERE id = ? AND appointment_id = ? AND is_removed = 0 FOR UPDATE');
                $update = $conn->prepare('UPDATE appointment_procedures SET charge = ? WHERE id = ? AND appointment_id = ? AND is_removed = 0');
                foreach ($clean as $pid => $amount) {
                    $fetch->bind_param('ii', $pid, $id);
                    $fetch->execute();
                    $old = $fetch->get_result()->fetch_assoc();
                    if (!$old) throw new RuntimeException('A service has changed or been removed. Refresh the page.');
                    if (abs((float)$old['charge'] - $amount) > 0.00001) {
                        $update->bind_param('dii', $amount, $pid, $id);
                        $update->execute();
                        $chargeUpdates++;
                        error_log('DentaLink charge correction: appt=' . $id . ', procedure=' . $pid . ', old=' . $old['charge'] . ', new=' . number_format($amount, 2, '.', '') . ', actor=' . (int)($_SESSION['user_id'] ?? 0));
                    }
                }
                $fetch->close();
                $update->close();
            }
            if ((int)($current['dentist_id'] ?? 0) !== $newDentistId) {
                $assign = $conn->prepare('UPDATE appointments SET dentist_id = ?, updated_at = NOW() WHERE id = ?');
                $assign->bind_param('ii', $newDentistId, $id);
                $assign->execute();
                $assign->close();
                error_log('DentaLink dentist assignment updated: appt=' . $id . ', previous=' . (int)($current['dentist_id'] ?? 0) . ', new=' . $newDentistId . ', actor=' . (int)($_SESSION['user_id'] ?? 0));
            } elseif ($chargeUpdates > 0) {
                // Keep the appointment's change timestamp in sync with visit-charge corrections.
                $touch = $conn->prepare('UPDATE appointments SET updated_at = NOW() WHERE id = ?');
                $touch->bind_param('i', $id);
                $touch->execute();
                $touch->close();
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Dentist and visit charges saved. Original scheduled date, time and clinic prices were unchanged.']);
            exit;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('DentaLink visit edit failed: ' . $e->getMessage());
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not save appointment changes.']);
            exit;
        }
    }
    if (in_array((string)($_POST['action'] ?? ''), ['add_procedure', 'update_procedure_charge', 'remove_procedure', 'complete_record_treatment'], true)) {
        try {
            if (!$hasProcedureTable) throw new RuntimeException('Install the treatment-procedure SQL migration first.');
            if (!$hasBookedVisitFlags) throw new RuntimeException('Install the booked-service charge migration before changing visit services.');
            if ($_POST['action'] === 'add_procedure') {
                if ($appointment['status'] !== 'in_progress') throw new RuntimeException('Only active treatments accept new procedures.');
                $serviceId = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
                $title = trim((string)($_POST['procedure_name'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $amountRaw = trim((string)($_POST['charge'] ?? ''));
                if ($serviceId && $serviceId > 0) {
                    $st = $conn->prepare('SELECT id, title, description, price FROM services WHERE id = ? AND is_active = 1');
                    $st->bind_param('i', $serviceId); $st->execute();
                    $service = $st->get_result()->fetch_assoc(); $st->close();
                    if (!$service) throw new RuntimeException('Selected service no longer exists.');
                    $title = $service['title'];
                    $description = $service['description'] ?? '';
                    // No adjustment supplied: use the official catalog price by default.
                    if ($amountRaw === '') $amountRaw = number_format((float)$service['price'], 2, '.', '');
                } else { $serviceId = null; }
                if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $amountRaw)) {
                    throw new RuntimeException('Enter a valid charge with up to two decimal places.');
                }
                $charge = (float)$amountRaw;
                if ($title === '' || strlen($title) > 150 || strlen($description) > 2000)
                    throw new RuntimeException('Enter a procedure name and valid description.');
                // An already active catalog service should be edited, not added twice.
                if ($serviceId !== null) {
                    $dup = $conn->prepare('SELECT id FROM appointment_procedures WHERE appointment_id = ? AND service_id = ? AND is_removed = 0 LIMIT 1');
                    $dup->bind_param('ii', $id, $serviceId);
                    $dup->execute();
                    $existingCatalogRow = $dup->get_result()->fetch_assoc();
                    $dup->close();
                    if ($existingCatalogRow) throw new RuntimeException('This service is already on the visit. Use the pencil icon to change its charge.');
                }
                // Do not add charges after a bill has been issued.
                $billCheck = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
                $billCheck->bind_param('i', $id); $billCheck->execute();
                $hasBill = $billCheck->get_result()->fetch_assoc(); $billCheck->close();
                if ($hasBill) throw new RuntimeException('Invoice already exists; review charges in Billing.');
                $dentistId = !empty($appointment['dentist_id']) ? (int)$appointment['dentist_id'] : null;
                // Staff record financial/admin procedure information, NOT clinical tooth conditions.
                $st = $conn->prepare('INSERT INTO appointment_procedures (appointment_id, service_id, procedure_name, description, dentist_id, charge, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $actorId = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
                $st->bind_param('iissidi', $id, $serviceId, $title, $description, $dentistId, $charge, $actorId);
                $st->execute(); $st->close();
                echo json_encode(['success' => true, 'message' => 'Procedure charge saved for this visit only.']);
                exit;
            }
            if ($_POST['action'] === 'update_procedure_charge') {
                if ($appointment['status'] !== 'in_progress') throw new RuntimeException('Charges can only be changed during active treatment.');
                $procedureId = filter_var($_POST['procedure_id'] ?? null, FILTER_VALIDATE_INT);
                $amountRaw = trim((string)($_POST['charge'] ?? ''));
                if (!$procedureId || !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $amountRaw))
                    throw new RuntimeException('Enter a valid treatment charge (up to two decimal places).');
                $charge = (float)$amountRaw;
                $conn->begin_transaction();
                $check = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
                $check->bind_param('i', $id); $check->execute();
                $existingBill = $check->get_result()->fetch_assoc(); $check->close();
                if ($existingBill) throw new RuntimeException('This appointment already has a bill. Adjust charges using an authorized billing correction workflow.');
                $st = $conn->prepare('UPDATE appointment_procedures SET charge = ? WHERE id = ? AND appointment_id = ?' . ($hasBookedVisitFlags ? ' AND is_removed = 0' : ''));
                $st->bind_param('dii', $charge, $procedureId, $id); $st->execute();
                $changed = $st->affected_rows; $st->close();
                if ($changed !== 1) {
                    $checkProc = $conn->prepare('SELECT id FROM appointment_procedures WHERE id = ? AND appointment_id = ? AND is_removed = 0');
                    $checkProc->bind_param('ii', $procedureId, $id);
                    $checkProc->execute();
                    $existsProc = $checkProc->get_result()->fetch_assoc();
                    $checkProc->close();
                    if (!$existsProc) throw new RuntimeException('Procedure not found or removed. Refresh the page.');
                }
                $conn->commit();
                error_log('Visit charge adjusted: appointment=' . $id . ', procedure=' . $procedureId . ', actor=' . (int)($_SESSION['user_id'] ?? 0));
                echo json_encode(['success' => true, 'message' => 'This appointment charge was updated. Catalog price unchanged.']);
                exit;
            }
            if ($_POST['action'] === 'remove_procedure') {
                if ($appointment['status'] !== 'in_progress') throw new RuntimeException('Only an active treatment can have a service removed.');
                $procedureId = filter_var($_POST['procedure_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$procedureId || $procedureId < 1) throw new RuntimeException('Invalid service selection.');
                $conn->begin_transaction();
                $bill = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
                $bill->bind_param('i', $id); $bill->execute();
                $existingBill = $bill->get_result()->fetch_assoc(); $bill->close();
                if ($existingBill) throw new RuntimeException('An invoice already exists. Review changes in Billing.');
                $item = $conn->prepare('SELECT id, tooth_numbers FROM appointment_procedures WHERE id = ? AND appointment_id = ? FOR UPDATE');
                $item->bind_param('ii', $procedureId, $id); $item->execute();
                $existingItem = $item->get_result()->fetch_assoc(); $item->close();
                if (!$existingItem) throw new RuntimeException('This service was already removed. Refresh the page.');
                if (trim((string)($existingItem['tooth_numbers'] ?? '')) !== '')
                    throw new RuntimeException('This service has dentist-recorded tooth information. Request a clinical correction instead.');
                $del = $conn->prepare('UPDATE appointment_procedures SET is_removed = 1 WHERE id = ? AND appointment_id = ? AND is_removed = 0');
                $del->bind_param('ii', $procedureId, $id); $del->execute();
                $deleted = $del->affected_rows; $del->close();
                if ($deleted !== 1) throw new RuntimeException('Unable to remove service. Please refresh.');
                $conn->commit();
                error_log('Visit service removed: appointment=' . $id . ', procedure=' . $procedureId . ', actor=' . (int)($_SESSION['user_id'] ?? 0));
                echo json_encode(['success' => true, 'message' => 'Service removed from this visit only.']);
                exit;
            }
            if ($_POST['action'] === 'complete_record_treatment') {
                $conn->begin_transaction();
                $lock = $conn->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
                $lock->bind_param('i', $id); $lock->execute();
                $current = $lock->get_result()->fetch_assoc(); $lock->close();
                if (!$current || $current['status'] !== 'in_progress')
                    throw new RuntimeException('This appointment is no longer in progress. Refresh this page.');
                if (empty($current['dentist_id'])) throw new RuntimeException('Assign a dentist before completing treatment.');
                $st = $conn->prepare('SELECT id, service_id, procedure_name, charge, tooth_numbers, dentist_id FROM appointment_procedures WHERE appointment_id = ? ' . ($hasBookedVisitFlags ? 'AND is_removed = 0 ' : '') . 'ORDER BY id');
                $st->bind_param('i', $id); $st->execute();
                $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
                if (!$rows) throw new RuntimeException('Record the procedures and actual charges before completing treatment.');
                $items = []; $total = 0;
                foreach ($rows as $r) {
                    $charge = (float)$r['charge'];
                    if ($charge < 0) throw new RuntimeException('Invalid procedure charge.');
                    $total += $charge;
                    $items[] = ['id' => $r['service_id'], 'procedure_id' => (int)$r['id'], 'title' => $r['procedure_name'], 'price' => $charge, 'quantity' => 1, 'total' => $charge, 'teeth' => $r['tooth_numbers'] ?? ''];
                }
                // Do not recalculate a bill that already has payments or a different total.
                $existing = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
                $existing->bind_param('i', $id); $existing->execute();
                $bill = $existing->get_result()->fetch_assoc(); $existing->close();
                if ($bill) throw new RuntimeException('An invoice already exists for this appointment. Review it in Billing before completing to avoid changing payment history.');
                if ($total > 99999999.99) throw new RuntimeException('Charge exceeds the billing limit.');
                $billCode = 'BILL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
                $json = json_encode($items, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $bs = $conn->prepare("INSERT INTO billing (billing_code, appointment_id, patient_name, patient_phone, patient_email, services, subtotal, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unpaid')");
                $bs->bind_param('sissssdd', $billCode, $id, $current['full_name'], $current['phone'], $current['email'], $json, $total, $total);
                $bs->execute(); $bs->close();
                $update = $conn->prepare("UPDATE appointments SET status = 'completed', treatment_completed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'in_progress'");
                $update->bind_param('i', $id); $update->execute();
                if ($update->affected_rows !== 1) throw new RuntimeException('Appointment changed while completing treatment.');
                $update->close();
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Treatment completed and unpaid invoice generated.', 'billing_code' => $billCode]);
                exit;
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Treatment operation failed for appointment ' . $id . ': ' . $e->getMessage());
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save treatment. Please contact the clinic administrator.']);
            exit;
        }
    }
    switch ($_POST['action']) {

        case 'save_tooth':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            $cond  = $_POST['condition_key'] ?? 'healthy';
            $notes = trim($_POST['notes'] ?? '');
            $allowedConditions = ['healthy', 'caries', 'filled', 'crown', 'root_canal', 'missing', 'extraction', 'impacted', 'fractured', 'other'];

            if ($tooth === '' || !in_array($cond, $allowedConditions)) {
                $response['message'] = 'Invalid tooth data.';
                break;
            }

            $updatedBy = $_SESSION['user_name'] ?? 'Staff';

            $stmt = $conn->prepare(
                "INSERT INTO dental_chart (patient_key, tooth_number, condition_key, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    condition_key = VALUES(condition_key),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('sssss', $patientKey, $tooth, $cond, $notes, $updatedBy);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} updated.";
                $response['updated_by'] = $updatedBy;
                $response['updated_at'] = date('M j, Y g:i A');
            } else {
                $response['message'] = 'Failed to save tooth record.';
            }
            $stmt->close();
            break;

        case 'reset_tooth':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            if ($tooth === '') {
                $response['message'] = 'Invalid tooth.';
                break;
            }
            $stmt = $conn->prepare("DELETE FROM dental_chart WHERE patient_key = ? AND tooth_number = ?");
            $stmt->bind_param('ss', $patientKey, $tooth);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} reset to healthy.";
            } else {
                $response['message'] = 'Failed to reset tooth.';
            }
            $stmt->close();
            break;

        case 'update_personalInfo':
        case 'update_appointment':
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $data = json_decode($_POST['data'] ?? '{}', true);
            
            if (!$appointmentId || empty($data)) {
                $response['message'] = 'Invalid data.';
                break;
            }
            
            if (array_key_exists('appointment_date', $data) || array_key_exists('appointment_time', $data)) {
                http_response_code(422);
                $response['message'] = 'Scheduled date and time are read-only on Patient Record. Use the authorized rescheduling workflow.';
                break;
            }
            $updates = [];
            $params = [];
            $types = '';
            
            // Patient demographics are managed only in Patients. Treatments/status use dedicated actions.
            $allowedFields = ['notes', 'internal_notes']; // Schedule is immutable on this page.
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updates[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (empty($updates)) {
                $response['message'] = 'No valid fields to update.';
                break;
            }
            
            // ✅ Bump updated_at so the receipt stale-check works
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $params[] = $appointmentId;
            $types .= 'i';
            
            $sql = "UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Appointment updated successfully.';
            } else {
                $response['message'] = 'Failed to update appointment: ' . $stmt->error;
            }
            $stmt->close();
            break;

        case 'update_medical':
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $data = json_decode($_POST['data'] ?? '{}', true);
            
            if (!$appointmentId || empty($data)) {
                $response['message'] = 'Invalid data.';
                break;
            }
            
            $updates = [];
            $params = [];
            $types = '';
            
            $allowedFields = ['under_physician_care', 'physician_name', 'pregnant', 'taking_medicine', 
                             'medicine_details', 'conditions', 'other_condition_details',
                             'allergic_local_anesthesia', 'allergic_antibiotics', 'allergic_pain_killer',
                             'allergic_other', 'allergic_other_details', 'hospitalized', 'hospitalized_when',
                             'hospitalized_why', 'worn_denture', 'last_dental_visit', 'last_dental_visit_reason'];
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updates[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (empty($updates)) {
                $response['message'] = 'No valid fields to update.';
                break;
            }
            
            // ✅ Bump updated_at so the receipt stale-check works
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $params[] = $appointmentId;
            $types .= 'i';
            
            $sql = "UPDATE medical_dental_history SET " . implode(', ', $updates) . " WHERE appointment_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Medical history updated successfully.';
            } else {
                $response['message'] = 'Failed to update medical history: ' . $stmt->error;
            }
            $stmt->close();
            break;
    }

    echo json_encode($response);
    exit;
}

// ---- Services ----
$servicesMap = [];
$res = $conn->query("SELECT * FROM services");
if ($res) while ($row = $res->fetch_assoc()) $servicesMap[$row['id']] = $row;

$serviceDetails = [];
if (!empty($appointment['services'])) {
    $decoded = json_decode($appointment['services'], true);
    $serviceIds = is_array($decoded) ? $decoded : array_map('trim', explode(',', $appointment['services']));
    foreach ($serviceIds as $sid) {
        if (isset($servicesMap[$sid])) $serviceDetails[] = $servicesMap[$sid];
    }
}
$totalPrice = array_sum(array_column($serviceDetails, 'price'));
// Original booked-service catalog prices are the initial visit charges.
// Any changed charge is stored only in appointment_procedures.
// One-time snapshot of booked services. A parent-row lock makes concurrent page loads safe.
// Removed entries remain soft-deleted so they never reappear on refresh.
if ($hasBookedVisitFlags && $serviceDetails && in_array($appointment['status'], ['confirmed','in_progress'], true)) {
    try {
        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT id FROM appointments WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $id); $lock->execute(); $lock->close();
        $billingProbe = $conn->prepare('SELECT id FROM billing WHERE appointment_id = ? LIMIT 1');
        $billingProbe->bind_param('i',$id); $billingProbe->execute();
        $invoiceExists = (bool)$billingProbe->get_result()->fetch_assoc(); $billingProbe->close();
        if (!$invoiceExists) {
            $exists = $conn->prepare('SELECT id FROM appointment_procedures WHERE appointment_id = ? AND service_id = ? LIMIT 1');
            $insert = $conn->prepare('INSERT INTO appointment_procedures (appointment_id, service_id, procedure_name, description, dentist_id, charge, created_by, booked_auto) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
            foreach ($serviceDetails as $svc) {
                $sid = (int)$svc['id'];
                $exists->bind_param('ii', $id, $sid); $exists->execute();
                if ($exists->get_result()->fetch_assoc()) continue;
                $title=(string)$svc['title']; $desc=(string)($svc['description']??'');
                $dentistId=!empty($appointment['dentist_id'])?(int)$appointment['dentist_id']:null;
                $price=(float)$svc['price']; $actor=(int)($_SESSION['staff_id']??$_SESSION['user_id']??0);
                $insert->bind_param('iissidi',$id,$sid,$title,$desc,$dentistId,$price,$actor);
                $insert->execute();
            }
            $exists->close(); $insert->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Booked service initialization failed for appointment ' . $id . ': ' . $e->getMessage());
    }
}
$visitProcedures = [];
$visitCharge = (!$hasBookedVisitFlags) ? (float)$totalPrice : 0.0;
if ($hasProcedureTable) {
    $ps = $conn->prepare('SELECT id, procedure_name, description, tooth_numbers, charge, dentist_id, created_at' . ($hasBookedVisitFlags ? ', booked_auto' : '') . ' FROM appointment_procedures WHERE appointment_id = ? ' . ($hasBookedVisitFlags ? 'AND is_removed = 0 ' : '') . 'ORDER BY id');
    $ps->bind_param('i', $id); $ps->execute();
    $visitProcedures = $ps->get_result()->fetch_all(MYSQLI_ASSOC); $ps->close();
    foreach ($visitProcedures as $procedure) $visitCharge += (float)$procedure['charge'];
}
// This appointment's clinician-recorded tooth selections only. Do not infer
// treated teeth from the examination chart, which represents a different record.
$visitSelectedTeeth = [];
foreach ($visitProcedures as $procedure) {
    $rawTeeth = trim((string)($procedure['tooth_numbers'] ?? ''));
    if ($rawTeeth === '') continue;
    $parts = preg_split('/[,;\s]+/', $rawTeeth, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        if (preg_match('/^(?:[1-8][1-8]|all|n\/?a)$/i', $part)) {
            $normalized = strtoupper($part);
            $visitSelectedTeeth[$normalized] = $normalized;
        }
    }
}


// ---- Active services (for the picker when editing) ----
$allServices = [];
$resAll = $conn->query("SELECT id, title, price FROM services WHERE is_active = 1 ORDER BY title");
if ($resAll) while ($row = $resAll->fetch_assoc()) $allServices[] = $row;

// ---- Currently selected service IDs (for pre-checking) ----
$selectedServiceIds = [];
foreach ($serviceDetails as $s) $selectedServiceIds[] = (int)$s['id'];

// ---- Active Dentists (for the dropdown) ----
$dentistsList = [];
$resDentists = $conn->query("SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY first_name, last_name");
if ($resDentists) while ($row = $resDentists->fetch_assoc()) $dentistsList[] = $row;

// ---- Dentist ----
$dentist = null;
if (!empty($appointment['dentist_id'])) {
    $stmt = $conn->prepare("SELECT * FROM dentists WHERE id = ?");
    $stmt->bind_param('i', $appointment['dentist_id']);
    $stmt->execute();
    $dentist = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ---- Medical / Dental History ----
$stmt = $conn->prepare("SELECT * FROM medical_dental_history WHERE appointment_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$history = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Dental Chart ----
$stmt = $conn->prepare("SELECT tooth_number, condition_key, notes, updated_by, updated_at FROM dental_chart WHERE patient_key = ?");
$stmt->bind_param('s', $patientKey);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chartMap = [];
foreach ($chartRows as $r) $chartMap[$r['tooth_number']] = $r;

$recordedMarks = count($chartMap);
$lastExamDate = null;
$lastUpdatedBy = null;
foreach ($chartMap as $r) {
    if (!$lastExamDate || $r['updated_at'] > $lastExamDate) {
        $lastExamDate = $r['updated_at'];
        $lastUpdatedBy = $r['updated_by'];
    }
}

// ---- Patient appointment history ----
$patientHistory = [];
if ($linkedPatient) {
    $stmt = $conn->prepare('SELECT id, appointment_date, appointment_time, arrived_at, status, services FROM appointments WHERE patient_id = ? AND id <> ? ORDER BY appointment_date DESC, appointment_time DESC');
    $patientId = (int)$linkedPatient['id'];
    $stmt->bind_param('ii', $patientId, $id);
} elseif (!empty($appointment['phone']) || !empty($appointment['email'])) {
    // Legacy unlinked records: contact matching is a fallback only.
    $stmt = $conn->prepare("SELECT id, appointment_date, appointment_time, arrived_at, status, services FROM appointments WHERE patient_id IS NULL AND ((phone <> '' AND phone = ?) OR (email <> '' AND email = ?)) AND id <> ? ORDER BY appointment_date DESC, appointment_time DESC");
    $stmt->bind_param('ssi', $appointment['phone'], $appointment['email'], $id);
}
if (isset($stmt)) {
    $stmt->execute();
    $patientHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function statusMeta($status) {
    $map = [
        'pending'   => ['label' => 'Pending',   'class' => 'st-pending'],
        'approved'  => ['label' => 'Approved',  'class' => 'st-approved'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'st-confirmed'],
        'completed' => ['label' => 'Completed', 'class' => 'st-completed'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'st-cancelled'],
        'rejected'  => ['label' => 'Rejected',  'class' => 'st-cancelled'],
    ];
    return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'st-default'];
}
$sm = statusMeta($appointment['status']);
$address = implode(', ', array_filter([$appointment['street_address'], $appointment['barangay'], $appointment['city'], $appointment['province'], $appointment['region']]));

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($appointment['full_name'] ?: 'Patient Record') ?> · Dental Clinic Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
.container-modern { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
@media (min-width: 1024px) { .main-content { margin-left: 260px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }
@media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

.top-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; border-bottom: 1px solid #eef2f6; background: white;
}
.back-link {
    display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:700;
    color: var(--slate); text-decoration:none;
}
.back-link:hover { color: var(--teal); }

.dashboard-card {
    background: white; border-radius: 18px; padding: 1.5rem;
    box-shadow: var(--shadow-card); border: 1px solid #f0f3f7;
    margin-bottom: 1.25rem;
}
.card-title {
    font-size: 15px; font-weight: 800; color: var(--navy); font-family:'DM Sans';
    display:flex; align-items:center; gap:8px; margin-bottom: 14px;
}
.card-title i { color: var(--teal); }

.status-badge {
    padding: 4px 12px; border-radius: 50px; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .3px; white-space: nowrap;
}
.st-pending   { background: var(--amber-bg); color: var(--amber); }
.st-approved  { background: var(--teal-light); color: var(--teal-dark); }
.st-confirmed { background: var(--blue-bg); color: var(--blue); }
.st-completed { background: #dcfce7; color: #166534; }
.st-cancelled { background: var(--red-bg); color: var(--red); }
.st-default   { background: var(--gray-bg); color: var(--slate); }

.detail-item .value select.editable-field,
.detail-item .edit-mode-dentist select {
    width: 100%;
    max-width: 100%;
    padding: 6px 10px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    font-size: 13px;
    color: var(--navy);
    font-weight: 600;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 14px;
    padding-right: 30px;
    transition: border-color .15s, box-shadow .15s;
}
.detail-item .value select.editable-field:focus,
.detail-item .edit-mode-dentist select:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(13,158,140,.15);
}
.edit-mode .edit-mode-dentist select {
    border: 2px solid var(--teal);
    background-color: #f8fafc;
}

.collapsible-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
}
.collapsible-header:hover .collapse-toggle-icon {
    color: var(--teal);
    transform: scale(1.1);
}
.collapse-toggle-icon {
    color: var(--slate);
    font-size: 14px;
    transition: transform .25s ease, color .15s ease;
    padding: 4px 8px;
    border-radius: 6px;
}
.collapsible-card.expanded .collapse-toggle-icon {
    transform: rotate(180deg);
}
.collapsible-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height .35s ease;
}
.collapsible-card.expanded .collapsible-body {
    max-height: 5000px;
    transition: max-height .5s ease;
}
.collapsible-hint {
    font-size: 11.5px;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 2px;
}

.service-picker { display: flex; flex-direction: column; gap: 8px; }

.service-pick-row {
    display: flex; align-items: center; gap: 10px;
    background: #f8fafc; border: 1.5px solid #e2e8f0;
    border-radius: 10px; padding: 10px 12px;
    transition: border-color .15s, background .15s;
}
.service-pick-row:hover { border-color: #cbd5e1; }

.service-pick-row .service-info { flex: 1; min-width: 0; }
.service-pick-row .service-title {
    font-size: 13px; font-weight: 700; color: var(--navy);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.service-pick-row .service-meta {
    font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 2px;
}
.service-pick-row .service-price {
    font-size: 13px; font-weight: 800; color: var(--navy);
    white-space: nowrap;
}
.service-pick-row .remove-service-btn {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--red-bg); color: var(--red);
    border: none; cursor: pointer; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; transition: background .15s;
}
.service-pick-row .remove-service-btn:hover { background: var(--red); color: white; }

.service-empty {
    text-align: center; font-size: 12.5px; color: #94a3b8;
    padding: 18px 10px; background: #f8fafc; border-radius: 10px;
    border: 1.5px dashed #e2e8f0;
}

.service-picker-list {
    display: flex; flex-direction: column; gap: 6px;
    max-height: 320px; overflow-y: auto; padding: 2px;
}
.service-picker-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    border: 1.5px solid #e2e8f0; cursor: pointer;
    transition: all .15s; background: white;
}
.service-picker-item:hover { border-color: var(--teal); background: var(--teal-light); }
.service-picker-item.already-added { opacity: .5; cursor: not-allowed; background: #f8fafc; }
.service-picker-item .info { flex: 1; }
.service-picker-item .title { font-size: 13px; font-weight: 700; color: var(--navy); }
.service-picker-item .price { font-size: 12.5px; font-weight: 700; color: var(--teal-dark); }
.service-picker-item .badge-added {
    font-size: 10px; padding: 2px 8px; border-radius: 50px;
    background: var(--gray-bg); color: var(--slate); font-weight: 700;
    text-transform: uppercase;
}

.patient-header { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.patient-avatar {
    width: 64px; height: 64px; border-radius: 16px; background: var(--teal-light);
    color: var(--teal-dark); display:flex; align-items:center; justify-content:center;
    font-size: 24px; font-weight: 800; flex-shrink:0;
}
.patient-name { font-size: 20px; font-weight: 800; color: var(--navy); }
.patient-sub { font-size: 12.5px; color: var(--slate); margin-top:2px; }

.detail-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px 20px; }
.detail-item span.label { display:block; font-size: 10.5px; color: #94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px; }
.detail-item .value { font-size: 13.5px; color: var(--navy); font-weight:600; }

.pill { display:inline-block; padding:2px 10px; border-radius:50px; font-size:11px; font-weight:700; }
.pill-yes { background: var(--red-bg); color: var(--red); }
.pill-no  { background: #dcfce7; color:#166534; }
.pill-unknown { background: var(--gray-bg); color: var(--slate); }

.service-line { display:flex; justify-content:space-between; padding:8px 0; font-size:13px; border-bottom:1px solid #f1f5f9; }
.service-total { display:flex; justify-content:space-between; padding:10px 0 0; font-weight:800; color:var(--navy); border-top:2px solid #e2e8f0; margin-top:6px; font-size:14px; }

.history-table { width:100%; border-collapse: collapse; font-size: 12.5px; }
.history-table th {
    text-align:left; padding: 8px 10px; background:#f7f9fc; color:#94a3b8;
    font-size: 10.5px; text-transform:uppercase; letter-spacing:.3px; font-weight:700;
}
.history-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; color: var(--navy); }
.history-table tr:last-child td { border-bottom:none; }

.empty-note { color:#94a3b8; font-size: 13px; text-align:center; padding: 20px 0; }

.notes-box { background:#f7f9fc; border-radius:10px; padding:12px 14px; font-size:13px; color:var(--navy); line-height:1.6; }

.chart-topbar {
    display: flex; flex-wrap: wrap; gap: 20px; padding-bottom: 14px; margin-bottom: 16px;
    border-bottom: 1px solid #eef2f6;
}
.chart-topbar .stat { min-width: 140px; }
.chart-topbar .stat span.label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; display:block; margin-bottom: 3px; }
.chart-topbar .stat span.val { font-size: 13.5px; font-weight: 700; color: var(--navy); }

.legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 18px; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: var(--slate); }
.legend-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; border: 1px solid rgba(0,0,0,.08); }

.chart-view-toggle { display: flex; gap: 4px; background: #f1f4f8; padding: 4px; border-radius: 10px; }
.chart-toggle-btn {
    border: none; background: transparent; padding: 6px 14px; border-radius: 7px;
    font-size: 12px; font-weight: 700; color: var(--slate); cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.chart-toggle-btn:hover { color: var(--teal-dark); }
.chart-toggle-btn.active { background: white; color: var(--teal-dark); box-shadow: 0 1px 4px rgba(11,22,40,.1); }
.chart-age-hint {
    font-size: 11px; color: #94a3b8; font-weight: 600; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.chart-age-hint i { color: #cbd5e1; }

.arch-label {
    text-align: center; font-size: 11px; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 1px; padding: 4px 0 8px;
    border-bottom: 1px dashed #e2e8f0; margin-bottom: 8px;
}
.arch-row { display: flex; justify-content: center; gap: 4px; flex-wrap: wrap; margin-bottom: 6px; }
.arch-divider { border-top: 1px dashed #e2e8f0; margin: 10px 0 12px; }

.tooth-cell {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    cursor: pointer; padding: 6px 4px; border-radius: 10px; transition: background .15s;
    width: 65px; min-width: 55px;
}
.tooth-cell:hover { background: #f7f9fc; }
.tooth-img-wrap {
    width: 44px; height: 64px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
    background: white; border: 2.5px solid var(--tooth-color, #cbd5e1);
    box-shadow: 0 1px 4px rgba(11,22,40,.06);
}
.tooth-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
.tooth-cell[data-condition="missing"] .tooth-img-wrap img { opacity: .25; }
.tooth-num { font-size: 13px; font-weight: 700; color: var(--slate); line-height: 1.2; }
.tooth-name { font-size: 9.5px; font-weight: 600; color: #94a3b8; text-align: center; line-height: 1.1; max-width: 58px; }
.tooth-cell:hover .tooth-num { color: var(--teal-dark); }
.tooth-cell:hover .tooth-name { color: var(--teal-dark); }

.tooth-condition-badge {
    font-size: 8px; font-weight: 700; text-transform: uppercase; padding: 1px 6px;
    border-radius: 8px; background: rgba(0,0,0,0.05); color: #94a3b8;
    margin-top: 1px; line-height: 1.3; max-width: 56px; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap;
    text-align: center;
}

#kidsChartView .tooth-img-wrap { border-radius: 12px; }
#kidsChartView .arch-label { color: var(--teal-dark); }

@media (max-width: 768px) {
    .tooth-cell { width: 52px; min-width: 44px; padding: 4px 2px; gap: 3px; }
    .tooth-img-wrap { width: 36px; height: 52px; }
    .tooth-num { font-size: 11px; }
    .tooth-name { font-size: 7.5px; max-width: 46px; }
    .tooth-condition-badge { font-size: 6.5px; max-width: 44px; padding: 1px 4px; }
}
@media (max-width: 480px) {
    .tooth-cell { width: 42px; min-width: 36px; padding: 3px 1px; gap: 2px; }
    .tooth-img-wrap { width: 28px; height: 40px; }
    .tooth-num { font-size: 9px; }
    .tooth-name { font-size: 6px; max-width: 38px; }
    .tooth-condition-badge { font-size: 5.5px; max-width: 36px; padding: 0 3px; }
}

.modal-overlay {
    position: fixed; inset: 0; z-index: 80; background: rgba(11,22,40,.55);
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: 18px; width: 100%; max-width: 440px;
    box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden; max-height: 90vh; overflow-y: auto;
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #f0f3f7;
}
.modal-header h3 { font-size: 15px; font-weight: 700; color: var(--navy); font-family: 'DM Sans'; }
.modal-body { padding: 18px 20px; }
.modal-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 14px 20px; background: #fafbfc; border-top: 1px solid #f0f3f7;
}
.modal-close-btn { background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer; }

.tooth-preview {
    width: 100%; height: 170px; border-radius: 14px; background: #f7f9fc;
    display: flex; flex-direction: column; align-items: center; justify-content: center; margin-bottom: 16px;
    border: 3px solid var(--tooth-color, #cbd5e1);
}
.tooth-preview img { max-height: 120px; }
.tooth-preview-info {
    text-align: center; margin-top: 6px; font-size: 12px; color: var(--slate);
}
.tooth-preview-info strong { color: var(--navy); }

.condition-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 14px; }
.condition-opt {
    display: flex; align-items: center; gap: 7px; border: 1.5px solid #e2e8f0; border-radius: 9px;
    padding: 7px 9px; font-size: 11.5px; font-weight: 600; color: var(--navy); cursor: pointer;
}
.condition-opt input { accent-color: var(--teal); }
.condition-opt .dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.condition-opt.selected { border-color: var(--teal); background: var(--teal-light); }

.notes-textarea {
    width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 13px;
    min-height: 74px; resize: vertical;
}
.notes-textarea:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,.1); }

.tooth-meta { font-size: 11px; color: #94a3b8; margin-top: 8px; }

.btn-xs {
    padding: 7px 13px; border-radius: 8px; font-size: 11.5px; font-weight: 700; border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px;
}
.btn-view { background: #f1f4f8; color: var(--navy); }
.btn-view:hover { background: #e2e8f0; }
.btn-approve { background: var(--teal-light); color: var(--teal-dark); }
.btn-approve:hover { background: var(--teal); color: white; }

.edit-toggle {
    cursor: pointer; color: var(--slate); transition: all 0.3s ease;
    padding: 4px 8px; border-radius: 6px; background: transparent; border: none; font-size: 14px;
}
.edit-toggle:hover { color: var(--teal); background: var(--teal-light); transform: rotate(15deg); }

.edit-mode .editable-field {
    background: #f8fafc; border: 2px solid #0d9e8c; border-radius: 8px;
    padding: 4px 8px; transition: all 0.3s ease;
}
.edit-mode .editable-field:focus {
    outline: none; box-shadow: 0 0 0 3px rgba(13,158,140,0.2); background: white;
}
.edit-mode .editable-field:not(input):not(select):not(textarea) {
    display: block; min-height: 30px; cursor: text;
}
.edit-mode .editable-field input,
.edit-mode .editable-field select,
.edit-mode .editable-field textarea {
    width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0;
    border-radius: 6px; background: white; font-size: 13px; color: #0b1628;
}
.edit-mode .editable-field input:focus,
.edit-mode .editable-field select:focus,
.edit-mode .editable-field textarea:focus {
    outline: none; border-color: #0d9e8c; box-shadow: 0 0 0 3px rgba(13,158,140,0.1);
}

.field-actions {
    display: none; gap: 10px; padding-top: 12px;
    border-top: 1px solid #e2e8f0; margin-top: 12px;
}
.edit-mode .field-actions { display: flex !important; }

.editable-field input, .editable-field select {
    width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0;
    border-radius: 6px; background: white; font-size: 13px; color: #0b1628;
}
.editable-field input:focus, .editable-field select:focus {
    outline: none; border-color: #0d9e8c; box-shadow: 0 0 0 3px rgba(13,158,140,0.1);
}
/* Visit controls are only visible after using the appointment pencil icon. */
#appointmentCard .visit-edit-only { display:none; }
#appointmentCard.visit-editing .visit-remove-button { display:inline-flex !important; align-items:center; justify-content:center; }
#appointmentCard.visit-editing #toggleVisitServiceBtn { display:inline-flex !important; align-items:center; gap:6px; }
#appointmentCard.visit-editing #appointmentActions { display:flex !important; flex-wrap:wrap; }
</style>
</head>
<body>

<div class="main-content" style="
    min-height:100vh;
    background-color:#f7f9fc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
">

 <div class="top-header">
    <a href="appointments.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Appointments</a>
    <div class="flex items-center gap-2">
      <button type="button" class="btn-xs btn-approve" style="padding:9px 16px;font-size:12.5px;" onclick="confirmGenerateReceipt(<?= $id ?>)">
        <i class="fas fa-receipt"></i> Generate Patient Record
      </button>
      <button class="icon-btn" style="width:38px;height:38px;border-radius:10px;border:1px solid #e2e8f0;background:white;color:#64748b;"><i class="fas fa-bell"></i></button>
    </div>
</div>

  <main class="py-6">
    <div class="container-modern">

      <!-- Patient Header -->
      <div class="dashboard-card">
        <div class="patient-header">
          <div class="patient-avatar"><?= h(strtoupper(substr($appointment['full_name'] ?: '?', 0, 1))) ?></div>
          <div class="flex-1">
            <div class="flex items-center gap-3 flex-wrap">
              <div class="patient-name"><?= h($appointment['full_name'] ?: 'Unnamed Patient') ?></div>
              <span class="status-badge <?= $sm['class'] ?>"><?= h($sm['label']) ?></span>
            </div>
            <div class="patient-sub">
              <?php if ($appointment['age']): ?><?= (int)$appointment['age'] ?> yrs &middot; <?php endif; ?>
              <?= h($appointment['occupation'] ?: 'Occupation not specified') ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Appointment-specific patient and treatment summary: id in URL is appointments.id -->
      <div class="dashboard-card" style="padding:24px;margin-bottom:20px;">
        <h3 style="font-size:17px;font-weight:800;color:#0b1628;margin-bottom:14px;">Patient Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" style="font-size:13px;">
          <?php foreach ([
            'Patient ID' => ($linkedPatient['patient_id'] ?? ('Appointment #' . $id)),
            'Full Name' => ($linkedPatient['full_name'] ?? $appointment['full_name'] ?? null),
            'Age' => ($linkedPatient['age'] ?? $appointment['age'] ?? null),
            'Gender' => ($linkedPatient['gender'] ?? null),
            'Occupation' => ($appointment['occupation'] ?? null),
            'Phone' => ($linkedPatient['phone'] ?? $appointment['phone'] ?? null),
            'Email' => ($linkedPatient['email'] ?? $appointment['email'] ?? null),
            'Address' => (!empty($linkedPatient['address']) ? $linkedPatient['address'] : ($address ?: null)),
            'Registered' => ($linkedPatient['created_at'] ?? null),
          ] as $label => $value): ?>
            <div><div style="color:#64748b;font-size:11px;"><?= h($label) ?></div><div style="font-weight:600;word-break:break-word;"><?= h($value === null || trim((string)$value) === '' ? 'Not provided' : $value) ?></div></div>
          <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:#64748b;margin-top:12px;">Patient demographic corrections belong in the Patients module. This appointment does not overwrite permanent patient information.</p>
      </div>
      <div class="dashboard-card" style="padding:24px;margin-bottom:20px;">
        <h3 style="font-size:17px;font-weight:800;color:#0b1628;margin-bottom:14px;">Current Appointment &amp; Treatment Details</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" style="font-size:13px;">
          <?php foreach ([
            'Appointment ID' => '#' . $id,
            'Scheduled Date' => visitScheduledDate($appointment['appointment_date'] ?? null),
            'Scheduled Time' => visitScheduledTime($appointment['appointment_time'] ?? null),
            'Actual Arrival' => ($appointment['arrived_at'] ?? null),
            'Treatment Started' => ($appointment['treatment_started_at'] ?? null),
            'Treatment Completed' => ($appointment['treatment_completed_at'] ?? null),
            'Assigned Dentist' => $dentist ? ('Dr. ' . $dentist['first_name'] . ' ' . $dentist['last_name']) : null,
            'Booked Services' => $serviceDetails ? implode(', ', array_column($serviceDetails, 'title')) : 'None selected',
            'Appointment Status' => ucfirst(str_replace('_',' ', $appointment['status'] ?? 'pending')),
          ] as $label => $value): ?>
            <div><div style="color:#64748b;font-size:11px;"><?= h($label) ?></div><div style="font-weight:600;"><?= h($value === null || trim((string)$value) === '' ? 'Not recorded' : $value) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 flex flex-col gap-5">
          <!-- This Appointment -->
          <div class="dashboard-card" id="appointmentCard">
              <div class="card-title">
                  <i class="fas fa-calendar-check"></i> This Appointment
                  <?php if (in_array($appointment['status'], ['confirmed', 'in_progress'], true)): ?>
                  <button type="button" id="visitEditToggle" class="edit-toggle ml-auto" onclick="toggleVisitChargeEdit()" title="Edit assigned dentist and visit charges" aria-label="Edit appointment details" aria-expanded="false" aria-controls="appointmentActions">
                      <i class="fas fa-pencil-alt"></i>
                  </button>
                  <?php endif; ?>
              </div>
              <div id="appointmentDisplay">
                  <div class="detail-grid mb-4">
                      <div class="detail-item">
                          <span class="label">Date</span>
                          <div class="value" title="Original date from the saved booking — read-only">
                              <?= h(visitScheduledDate($appointment['appointment_date'] ?? null)) ?>
                          </div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Time</span>
                          <div class="value" title="Original time from the saved booking — read-only">
                              <?= h(visitScheduledTime($appointment['appointment_time'] ?? null)) ?>
                          </div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Actual Arrival</span>
                          <div class="value"><?= !empty($appointment['arrived_at']) ? h(date('M j, Y g:i A', strtotime($appointment['arrived_at']))) : 'Not yet arrived' ?></div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Treatment Started</span>
                          <div class="value"><?= !empty($appointment['treatment_started_at']) ? h(date('M j, Y g:i A', strtotime($appointment['treatment_started_at']))) : 'Not started' ?></div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Appointment Status</span>
                          <div class="value"><?= h(ucwords(str_replace('_',' ', $appointment['status']))) ?></div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Tooth Numbers</span>
                          <div class="value" id="visitSelectedTeeth" aria-live="polite">
                              <?= $visitSelectedTeeth ? h(implode(', ', array_values($visitSelectedTeeth))) : 'Not yet recorded by dentist' ?>
                          </div>
                          
                      </div>
                      <div class="detail-item">
                          <span class="label">Dentist</span>

                          <div class="value view-mode-dentist" id="visitDentistView">
                              <?= $dentist ? h('Dr. ' . $dentist['first_name'] . ' ' . $dentist['last_name']) : 'Unassigned' ?>
                          </div>

                          <div class="edit-mode-dentist" id="visitDentistEditor" style="display:none;">
                              <select id="visitDentistSelect" aria-label="Change assigned dentist" class="editable-field" data-field="dentist_id">
                                  <option value="">— Unassigned —</option>
                                  <?php foreach ($dentistsList as $d): ?>
                                      <option value="<?= (int)$d['id'] ?>" <?= (int)$appointment['dentist_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                          Dr. <?= h($d['first_name'] . ' ' . $d['last_name']) ?><?= $d['specialization'] ? ' — ' . h($d['specialization']) : '' ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>
                  </div>

      <!-- Visit-specific procedures and booked services together (catalog is immutable). -->
      <div style="border-top:1px solid #e2e8f0;margin-top:18px;padding-top:18px;" id="performedProcedures">
        <h3 style="font-size:17px;font-weight:800;color:#0b1628;margin-bottom:5px;">Services &amp; Procedures</h3>
        
        <?php if (!$hasProcedureTable): ?>
          <p style="padding:12px;background:#fff7ed;border-radius:9px;color:#9a3412;">Procedure storage is not installed. Your booked service prices are shown below, but editing requires the included SQL migration.</p>
          <?php foreach ($serviceDetails as $svc): ?>
            <div class="service-line"><span><?= h($svc['title']) ?></span><strong>₱<?= number_format((float)$svc['price'], 2) ?></strong></div>
          <?php endforeach; ?>
          <div class="service-total"><strong>Total Visit Charge</strong><strong>₱<?= number_format($totalPrice, 2) ?></strong></div>
        <?php elseif (!$hasBookedVisitFlags): ?>
          <p style="padding:12px;background:#fff7ed;border-radius:9px;color:#9a3412;">Run the included booked-service migration to enable editing and automatic visit charge storage.</p>
          <?php foreach ($serviceDetails as $svc): ?>
            <div class="service-line"><span><?= h($svc['title']) ?></span><strong>₱<?= number_format((float)$svc['price'], 2) ?></strong></div>
          <?php endforeach; ?>
          <div class="service-total"><strong>Default Total</strong><strong>₱<?= number_format($totalPrice, 2) ?></strong></div>
        <?php else: ?>
          <?php if ($visitProcedures): ?>
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0;"><th style="padding:9px;">Procedure</th><th style="padding:9px;">Tooth no.</th><th style="padding:9px;text-align:right;">Charge (₱)</th><th style="padding:9px;width:45px;text-align:center;"> </th></tr></thead>
                <tbody>
                  <?php foreach ($visitProcedures as $proc): ?>
                  <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:12px 9px;"><strong><?= h($proc['procedure_name']) ?></strong><?php if (!empty($proc['booked_auto'])): ?><span style="margin-left:7px;background:#e6f7f5;color:#0a7d6e;font-size:10px;padding:3px 7px;border-radius:999px;white-space:nowrap;">Booked</span><?php endif; ?><div style="color:#64748b;font-size:11px;"><?= h($proc['description']) ?></div></td>
                    <td style="padding:12px 9px;"><?= h($proc['tooth_numbers'] ?: 'Not recorded by dentist') ?></td>
                    <td style="padding:12px 9px;text-align:right;white-space:nowrap;">
                      <?php if ($appointment['status'] === 'in_progress'): ?>
                        <span class="visit-charge-read">₱<?= number_format((float)$proc['charge'], 2) ?></span>
                        <form onsubmit="event.preventDefault(); saveVisitAppointmentEdits(); return false;" class="visit-charge-edit" data-procedure-id="<?= (int)$proc['id'] ?>" style="display:none;gap:5px;align-items:center;justify-content:flex-end;">
                          <input aria-label="Actual visit charge" type="number" min="0" max="99999999.99" step="0.01" required name="charge" value="<?= h(number_format((float)$proc['charge'],2,'.','')) ?>" style="max-width:118px;padding:6px;border:1px solid #cbd5e1;border-radius:7px;">
                        </form>
                      <?php else: ?>₱<?= number_format($proc['charge'], 2) ?><?php endif; ?>
                    </td>
                    <td style="padding:12px 5px;text-align:center;">
                      <?php if ($appointment['status'] === 'in_progress'): ?>
                        <button type="button" class="visit-edit-only visit-remove-button" onclick="removeVisitService(<?= (int)$proc['id'] ?>)" title="Remove this service from this visit" aria-label="Remove <?= h($proc['procedure_name']) ?>" style="display:none;border:0;border-radius:8px;width:34px;height:34px;background:#fee2e2;color:#b91c1c;cursor:pointer;"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                      <?php endif; ?>
                    </td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p style="font-size:13px;color:#64748b;">No service entries recorded. Booked services will appear automatically after installing the included migration and opening an active appointment.</p>
          <?php endif; ?>
          <div style="border-top:2px solid #e2e8f0;padding-top:14px;margin-top:14px;display:flex;justify-content:space-between;font-weight:800;">
            <span>Total Visit Charge</span><span id="visitTotalDisplay" data-saved-total="<?= h(number_format($visitCharge, 2, '.', '')) ?>">₱<?= number_format($visitCharge, 2) ?></span>
          </div>
          <?php if ($appointment['status'] === 'in_progress'): ?>
            <button type="button" id="toggleVisitServiceBtn" class="visit-edit-only" aria-expanded="false" aria-controls="procedureEntryForm" onclick="toggleVisitServiceForm()" style="display:none;margin-top:16px;background:#e6f7f5;color:#0a7d6e;border:1px solid #b9e7df;padding:11px 18px;border-radius:10px;font-weight:800;cursor:pointer;"><i class="fas fa-plus"></i> Add Service</button>
            <form id="procedureEntryForm" hidden style="display:none;margin-top:14px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:12px;padding:16px;gap:12px;" onsubmit="return submitVisitProcedure(event)">
              <strong style="font-size:13px;color:#0b1628;">Add Service or Custom Procedure</strong>
              <label style="font-size:12px;">Select Clinic Service
                <select id="visitServiceSelect" onchange="updateVisitServiceFields()" style="display:block;width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px;margin-top:5px;">
                  <option value="">Custom procedure — enter a name below</option>
                  <?php foreach ($servicesMap as $sid => $svc): if (empty($svc['is_active'])) continue; ?>
                    <option value="<?= (int)$sid ?>" data-price="<?= h($svc['price']) ?>"><?= h($svc['title']) ?> — ₱<?= number_format($svc['price'], 2) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="font-size:12px;">Procedure name <input id="visitProcedureName" maxlength="150" required style="display:block;width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px;margin-top:5px;"></label>
              <label style="font-size:12px;">Notes (administrative, not dental chart notes)<textarea id="visitProcedureDescription" maxlength="2000" rows="2" style="display:block;width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px;margin-top:5px;"></textarea></label>
              <label style="font-size:12px;">Charge for this visit (₱)
                <input type="number" id="visitProcedureCharge" min="0" max="99999999.99" step="0.01" required style="display:block;width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px;margin-top:5px;">
              </label>
              <div style="font-size:11px;color:#64748b;">Tooth numbers and clinical chart changes are dentist-only. Staff may record administrative procedure charges for the visit, but cannot change clinical tooth conditions.</div>
              <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" style="padding:12px;background:#0d9e8c;color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Save Service &amp; Charge</button>
                <button type="button" onclick="toggleVisitServiceForm(false)" style="padding:12px;background:#e2e8f0;color:#334155;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Cancel</button>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

                  <?php if (!empty($appointment['notes'])): ?>
                  <div class="mt-4">
                      <div class="card-title" style="font-size:12.5px; margin-bottom:6px;">
                          <i class="fas fa-comment-dots"></i> Patient Notes
                      </div>
                      <div class="notes-box editable-field" data-field="notes" data-type="textarea">
                          <?= nl2br(h($appointment['notes'])) ?>
                      </div>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($appointment['internal_notes'])): ?>
                  <div class="mt-4">
                      <div class="card-title" style="font-size:12.5px; margin-bottom:6px;">
                          <i class="fas fa-lock"></i> Internal Notes (Staff Only)
                      </div>
                      <div class="notes-box editable-field" data-field="internal_notes" data-type="textarea">
                          <?= nl2br(h($appointment['internal_notes'])) ?>
                      </div>
                  </div>
                  <?php endif; ?>
              </div>
              
              <div class="field-actions" id="appointmentActions" style="display:none;">
                  <button type="button" id="saveVisitEditBtn" class="btn-xs btn-approve" onclick="saveVisitAppointmentEdits()">
                      <i class="fas fa-save"></i> Save Changes
                  </button>
                  <button type="button" class="btn-xs btn-view" onclick="cancelVisitEdit()">
                      <i class="fas fa-times"></i> Cancel
                  </button>
                  <span style="font-size:11px;color:#64748b;align-self:center;">Scheduled date/time are read-only. Charges are for this visit only.</span>
              </div>
          </div>
        </div>

        <!-- Sidebar -->
        <div class="flex flex-col gap-5">
          <div class="dashboard-card">
            <div class="card-title"><i class="fas fa-history"></i> Appointment History</div>
            <?php if ($patientHistory): ?>
              <table class="history-table">
                <thead><tr><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($patientHistory as $h): $hsm = statusMeta($h['status']); ?>
                  <tr style="cursor:pointer;" onclick="window.location.href='patient-record.php?id=<?= (int)$h['id'] ?>'">
                    <td>
                        <?= h($h['appointment_date'] ? date('M j, Y', strtotime($h['appointment_date'])) : '—') ?>
                        <div style="font-size:11px;color:#94a3b8;"><?= h($h['appointment_time'] ? date('g:i A', strtotime($h['appointment_time'])) : '') ?></div>
                    </td>
                    <td><span class="status-badge <?= $hsm['class'] ?>" style="font-size:9.5px;"><?= h($hsm['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="empty-note">No other appointments found for this patient.</p>
            <?php endif; ?>
          </div>

          <div class="dashboard-card">
            <div class="card-title"><i class="fas fa-shield-alt"></i> Consent</div>
            <div class="detail-grid space-y-5">
              <div class="detail-item"><span class="label">Terms Accepted</span><div class="value"><?= $appointment['terms_accepted'] ? yn('yes') : yn('no') ?></div></div>
              <div class="detail-item"><span class="label">Privacy Accepted</span><div class="value"><?= $appointment['privacy_accepted'] ? yn('yes') : yn('no') ?></div></div>
              <div class="detail-item"><span class="label">Verified</span><div class="value"><?= $appointment['is_verified'] ? yn('yes') : yn('no') ?></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Dental Chart -->
      <div class="dashboard-card">
          <div class="card-title" style="justify-content: space-between; flex-wrap: wrap; gap: 10px;">
              <span><i class="fas fa-tooth"></i> Dental Chart / Intraoral Examination <span style="font-family:DM Sans,sans-serif;font-size:10px;background:#e6f7f5;border-radius:7px;padding:4px 8px;color:#0a7d6e;margin-left:8px;">Staff: View Only</span></span>
              <div class="chart-view-toggle" role="tablist" aria-label="Chart view">
                  <button type="button" id="adultToggleBtn" class="chart-toggle-btn <?= $defaultChartView === 'adult' ? 'active' : '' ?>" data-view="adult" onclick="switchChartView('adult')">
                      <i class="fas fa-user"></i> Adult
                  </button>
                  <button type="button" id="kidsToggleBtn" class="chart-toggle-btn <?= $defaultChartView === 'kids' ? 'active' : '' ?>" data-view="kids" onclick="switchChartView('kids')">
                      <i class="fas fa-child"></i> Kids
                  </button>
              </div>
          </div>

          <div class="chart-age-hint">
              <i class="fas fa-info-circle"></i>
              <?php if ($patientAge !== null): ?>
                  Patient is <?= (int)$patientAge ?> yrs old — showing the
                  <?= $defaultChartView === 'kids' ? 'primary (baby) teeth' : 'permanent (adult) teeth' ?> chart by default.
                  You can switch views above at any time (useful for mixed-dentition patients around ages 6–12).
              <?php else: ?>
                  No age on file — defaulting to the adult chart. Add the patient's age to auto-select the right chart.
              <?php endif; ?>
          </div>

          <div class="chart-topbar">
              <div class="stat"><span class="label">Latest Exam Date</span><span class="val" id="lastExamDate"><?= $lastExamDate ? h(date('M j, Y g:i A', strtotime($lastExamDate))) : '—' ?></span></div>
              <div class="stat"><span class="label">Updated By</span><span class="val" id="lastUpdatedBy"><?= h($lastUpdatedBy ?: '—') ?></span></div>
              <div class="stat"><span class="label">Recorded Marks</span><span class="val" id="recordedMarks"><?= $recordedMarks ?> mark(s)</span></div>
              <div class="stat" style="flex:1; min-width:180px;">
                  <span class="label">Notes / Findings</span>
                  <span class="val" id="latestFindings">
                      <?php
                      $latestNoteText = 'No updated notes recorded.';
                      foreach ($chartMap as $r) {
                          if (!empty($r['notes']) && $r['updated_at'] === $lastExamDate) { $latestNoteText = $r['notes']; break; }
                      }
                      echo h($latestNoteText);
                      ?>
                  </span>
              </div>
          </div>

          <div class="legend">
              <?php foreach ($dentalConditions as $key => $c): ?>
              <div class="legend-item"><span class="legend-dot" style="background:<?= $c['color'] ?>;"></span><?= h($c['label']) ?></div>
              <?php endforeach; ?>
          </div>

          <div id="adultChartView" style="display: <?= $defaultChartView === 'adult' ? 'block' : 'none' ?>;">
              <div class="arch-label">Upper Arch (Maxillary)</div>
              <div class="arch-row">
                  <?php foreach ($upperArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>

              <div class="arch-divider"></div>

              <div class="arch-label">Lower Arch (Mandibular)</div>
              <div class="arch-row">
                  <?php foreach ($lowerArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>
          </div>

          <div id="kidsChartView" style="display: <?= $defaultChartView === 'kids' ? 'block' : 'none' ?>;">
              <div class="arch-label">Upper Arch (Maxillary) — Primary Teeth</div>
              <div class="arch-row">
                  <?php foreach ($primaryUpperArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>

              <div class="arch-divider"></div>

              <div class="arch-label">Lower Arch (Mandibular) — Primary Teeth</div>
              <div class="arch-row">
                  <?php foreach ($primaryLowerArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>
          </div>
      </div>

      <!-- Medical & Dental History (collapsible) -->
      <div class="dashboard-card collapsible-card" id="medicalCard">
          <div class="collapsible-header" onclick="toggleMedicalHistory()">
              <div>
                  <div class="card-title" style="margin-bottom:2px;">
                      <i class="fas fa-file-medical"></i> Medical &amp; Dental History                  </div>
                  <div class="collapsible-hint" id="medicalHint">
                      <?= $history ? 'Click to view or edit the submitted medical/dental history form.' : 'No medical/dental history form submitted yet.' ?>
                  </div>
              </div>
              <div class="flex items-center gap-3">
                  <?php if ($history): ?>
                      <button type="button" class="edit-toggle" onclick="event.stopPropagation(); toggleEdit('medical')" title="Edit history">
                          <i class="fas fa-pencil-alt"></i>
                      </button>
                  <?php endif; ?>
                  <i class="fas fa-chevron-down collapse-toggle-icon"></i>
              </div>
          </div>

          <div class="collapsible-body">
              <div class="pt-4" id="medicalDisplay">
                  <?php if ($history): ?>
                      <div class="detail-grid mb-4">
                          <div class="detail-item">
                              <span class="label">Under physician's care</span>
                              <div class="value">
                                  <select class="editable-field" data-field="under_physician_care" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['under_physician_care'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['under_physician_care'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Physician name</span>
                              <div class="value editable-field" data-field="physician_name" data-type="text"><?= h($history['physician_name'] ?: '—') ?></div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Pregnant</span>
                              <div class="value">
                                  <select class="editable-field" data-field="pregnant" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['pregnant'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['pregnant'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Taking medicine</span>
                              <div class="value">
                                  <select class="editable-field" data-field="taking_medicine" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['taking_medicine'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['taking_medicine'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                      </div>
                      
                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Medicine Details</span>
                          <div class="notes-box mt-1 editable-field" data-field="medicine_details" data-type="textarea">
                              <?= nl2br(h($history['medicine_details'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Medical Conditions</span>
                          <div class="notes-box mt-1 editable-field" data-field="conditions" data-type="textarea">
                              <?= nl2br(h($history['conditions'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Other Condition Details</span>
                          <div class="notes-box mt-1 editable-field" data-field="other_condition_details" data-type="textarea">
                              <?= nl2br(h($history['other_condition_details'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Allergies</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Local Anesthesia</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_local_anesthesia" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_local_anesthesia'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_local_anesthesia'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Antibiotics</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_antibiotics" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_antibiotics'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_antibiotics'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Pain Killers</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_pain_killer" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_pain_killer'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_pain_killer'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Other Allergies</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_other" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_other'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_other'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Other Allergy Details</span>
                              <div class="notes-box mt-1 editable-field" data-field="allergic_other_details" data-type="textarea">
                                  <?= nl2br(h($history['allergic_other_details'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Hospitalization History</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Ever Hospitalized</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="hospitalized" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['hospitalized'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['hospitalized'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">When</span>
                                  <div class="value editable-field" data-field="hospitalized_when" data-type="text"><?= h($history['hospitalized_when'] ?: '—') ?></div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Reason for Hospitalization</span>
                              <div class="notes-box mt-1 editable-field" data-field="hospitalized_why" data-type="textarea">
                                  <?= nl2br(h($history['hospitalized_why'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Dental History</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Wears Denture</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="worn_denture" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['worn_denture'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['worn_denture'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Last Dental Visit</span>
                                  <div class="value editable-field" data-field="last_dental_visit" data-type="text"><?= h($history['last_dental_visit'] ?: '—') ?></div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Reason for Last Visit</span>
                              <div class="notes-box mt-1 editable-field" data-field="last_dental_visit_reason" data-type="textarea">
                                  <?= nl2br(h($history['last_dental_visit_reason'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>
                  <?php else: ?>
                      <p class="empty-note">No medical/dental history form has been submitted for this appointment.</p>
                  <?php endif; ?>
              </div>

              <?php if ($history): ?>
              <div class="field-actions" id="medicalActions" style="display:none;">
                  <button class="btn-xs btn-approve" onclick="saveEdits('medical')">
                      <i class="fas fa-save"></i> Save Changes
                  </button>
                  <button class="btn-xs btn-view" onclick="cancelEdit('medical')">
                      <i class="fas fa-times"></i> Cancel
                  </button>
              </div>
              <?php endif; ?>
          </div>
      </div>
    </div>
  </main>
</div>

<!-- TOOTH MODAL -->
<div class="modal-overlay" id="toothModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="toothModalTitle">Tooth #</h3>
      <button class="modal-close-btn" onclick="closeToothModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="tooth-preview" id="toothPreview">
        <img id="toothPreviewImg" src="" alt="">
        <div class="tooth-preview-info" id="toothPreviewInfo"></div>
      </div>

      <div class="detail-item" style="margin-bottom:10px;"><span class="label">Condition</span></div>
      <div class="condition-grid" id="conditionGrid"></div>

      <div id="otherConditionWrap" style="display:none; margin-bottom:14px;">
        <div class="detail-item" style="margin-bottom:6px;">
          <span class="label">Specify Condition <span style="color:#b91c1c;">*</span></span>
        </div>
        <input type="text" id="otherConditionInput" class="notes-textarea"
               style="min-height:auto;padding:9px 12px;"
               placeholder="e.g. Discoloration, Wear, Sensitivity, etc.">
      </div>

      <div class="detail-item" style="margin-bottom:6px;"><span class="label">Notes</span></div>
      <textarea class="notes-textarea" id="toothNotes" placeholder="Add findings for this tooth..."></textarea>

      <p class="tooth-meta" id="toothMeta"></p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-xs btn-view" disabled title="Dentist access required"><i class="fas fa-rotate-left"></i> Reset</button>
      <div class="flex gap-2">
        <button type="button" class="btn-xs btn-view" onclick="closeToothModal()">Cancel</button>
        <button type="button" class="btn-xs btn-approve" disabled title="Dentist access required"><i class="fas fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>

<!-- SERVICE PICKER MODAL -->
<div class="modal-overlay" id="servicePickerModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-header">
      <h3>Add Service</h3>
      <button class="modal-close-btn" onclick="closeServicePicker()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="text" id="serviceSearch" class="notes-textarea"
             style="min-height:auto;padding:9px 12px;margin-bottom:12px;"
             placeholder="Search services..."
             oninput="filterServicePicker(this.value)">
      <div class="service-picker-list" id="servicePickerList"></div>
    </div>
    <div class="modal-footer" style="justify-content:flex-end;">
      <button type="button" class="btn-xs btn-view" onclick="closeServicePicker()">Close</button>
    </div>
  </div>
</div>

<script>
const CONDITIONS = <?= json_encode($dentalConditions) ?>;

<?php
$allToothNumbers = array_merge($upperArch, $lowerArch, $primaryUpperArch, $primaryLowerArch);
$toothNamesJs = [];
foreach ($allToothNumbers as $tn) { $toothNamesJs[$tn] = getToothName($tn); }
?>
const TOOTH_NAMES = <?= json_encode($toothNamesJs) ?>;
const PRIMARY_TOOTH_NUMBERS = <?= json_encode(array_merge($primaryUpperArch, $primaryLowerArch)) ?>;
const PRIMARY_TO_ADULT_IMAGE = <?= json_encode($PRIMARY_TO_ADULT_IMAGE) ?>;

// ===== Services editing =====
const ALL_SERVICES = <?= json_encode($allServices) ?>;
let selectedServices = <?= json_encode(array_values($selectedServiceIds)) ?>;

let chartData = <?= json_encode($chartMap) ?>;
let currentTooth = null;

function toothImagePath(num) {
    num = parseInt(num, 10);
    const imgNum = PRIMARY_TO_ADULT_IMAGE[num] || num;
    const prefix = (imgNum >= 11 && imgNum <= 28) ? 'sup' : 'inf';
    return `../../assets/img/dentadura-${prefix}-${imgNum}.png`;
}

function buildConditionGrid(selectedKey) {
    const grid = document.getElementById('conditionGrid');
    grid.innerHTML = '';
    Object.entries(CONDITIONS).forEach(([key, c]) => {
        const label = document.createElement('label');
        label.className = 'condition-opt' + (key === selectedKey ? ' selected' : '');
        label.innerHTML = `
            <input type="radio" name="condition" value="${key}" ${key === selectedKey ? 'checked' : ''}>
            <span class="dot" style="background:${c.color};"></span>
            ${c.label}
        `;
        label.querySelector('input').addEventListener('change', () => {
            document.querySelectorAll('.condition-opt').forEach(el => el.classList.remove('selected'));
            label.classList.add('selected');
            toggleOtherConditionField(key);
        });
        grid.appendChild(label);
    });

    toggleOtherConditionField(selectedKey);
}

function toggleOtherConditionField(conditionKey) {
    const wrap = document.getElementById('otherConditionWrap');
    if (!wrap) return;
    wrap.style.display = (conditionKey === 'other') ? 'block' : 'none';
}

function openToothModal(num) {
    currentTooth = num;
    const entry = chartData[num] || null;
    const cond = entry ? entry.condition_key : 'healthy';
    const toothName = TOOTH_NAMES[num] || 'Tooth';
    const isPrimary = PRIMARY_TOOTH_NUMBERS.includes(parseInt(num, 10));

    document.getElementById('toothModalTitle').textContent = `Tooth #${num} — ${toothName}${isPrimary ? ' (Primary)' : ''}`;
    document.getElementById('toothPreviewImg').src = toothImagePath(num);
    document.getElementById('toothPreview').style.setProperty('--tooth-color', CONDITIONS[cond].color);
    document.getElementById('toothPreviewInfo').textContent = `${toothName} (${num})${isPrimary ? ' · Baby tooth' : ''}`;
    document.getElementById('toothNotes').value = entry ? (entry.notes || '') : '';
    document.getElementById('otherConditionInput').value =
        (entry && entry.condition_key === 'other' && entry.notes) ? entry.notes : '';

    const meta = document.getElementById('toothMeta');
    meta.textContent = entry && entry.updated_by
        ? `Last updated by ${entry.updated_by} on ${entry.updated_at}`
        : 'No record yet for this tooth.';

    buildConditionGrid(cond);

    document.getElementById('toothModal').classList.add('open');
}

function toggleMedicalHistory() {
    const card = document.getElementById('medicalCard');
    card.classList.toggle('expanded');
}

function renderServicePicker() {
    const container = document.getElementById('servicePicker');
    container.innerHTML = '';

    if (selectedServices.length === 0) {
        container.innerHTML = '<div class="service-empty">No services selected. Click "Add Service" to add one.</div>';
        document.getElementById('servicesEditTotal').textContent = '₱0.00';
        return;
    }

    let total = 0;
    selectedServices.forEach(id => {
        const svc = ALL_SERVICES.find(s => parseInt(s.id) === parseInt(id));
        if (!svc) return;
        total += parseFloat(svc.price);

        const row = document.createElement('div');
        row.className = 'service-pick-row';
        row.innerHTML = `
            <div class="service-info">
                <div class="service-title">${svc.title}</div>
            </div>
            <div class="service-price">₱${parseFloat(svc.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            <button type="button" class="remove-service-btn" title="Remove" onclick="removeService(${svc.id})">
                <i class="fas fa-times"></i>
            </button>
        `;
        container.appendChild(row);
    });

    document.getElementById('servicesEditTotal').textContent =
        '₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function removeService(id) {
    selectedServices = selectedServices.filter(x => parseInt(x) !== parseInt(id));
    renderServicePicker();
}

function openServicePicker() {
    document.getElementById('serviceSearch').value = '';
    renderServiceList(ALL_SERVICES);
    document.getElementById('servicePickerModal').classList.add('open');
}

function closeServicePicker() {
    document.getElementById('servicePickerModal').classList.remove('open');
}

function renderServiceList(list) {
    const container = document.getElementById('servicePickerList');
    container.innerHTML = '';

    if (list.length === 0) {
        container.innerHTML = '<p class="empty-note">No services match your search.</p>';
        return;
    }

    list.forEach(svc => {
        const already = selectedServices.some(id => parseInt(id) === parseInt(svc.id));
        const item = document.createElement('div');
        item.className = 'service-picker-item' + (already ? ' already-added' : '');
        item.innerHTML = `
            <div class="info">
                <div class="title">${svc.title}</div>
                <div class="price">₱${parseFloat(svc.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            </div>
            ${already
                ? '<span class="badge-added">Added</span>'
                : '<i class="fas fa-plus" style="color:var(--teal);"></i>'}
        `;
        if (!already) {
            item.addEventListener('click', () => {
                selectedServices.push(svc.id);
                renderServicePicker();
                closeServicePicker();
            });
        }
        container.appendChild(item);
    });
}

function filterServicePicker(query) {
    const q = query.trim().toLowerCase();
    const filtered = q === ''
        ? ALL_SERVICES
        : ALL_SERVICES.filter(s => s.title.toLowerCase().includes(q));
    renderServiceList(filtered);
}

document.getElementById('servicePickerModal').addEventListener('click', function(e) {
    if (e.target === this) closeServicePicker();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeServicePicker();
});

// ==========================================
// GENERATE RECEIPT — with stale check
// ==========================================
function confirmGenerateReceipt(appointmentId) {
    Swal.fire({
        title: 'Generate Patient Record?',
        text: 'This will create or update the patient record snapshot with the current data.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, generate',
        reverseButtons: true
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Generating...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            window.location.href = '../../api/generate_receipt.php?appointment_id=' + appointmentId;
        }
    });
}

function closeToothModal() {
    document.getElementById('toothModal').classList.remove('open');
    currentTooth = null;
}

function switchChartView(view) {
    document.getElementById('adultChartView').style.display = view === 'adult' ? 'block' : 'none';
    document.getElementById('kidsChartView').style.display = view === 'kids' ? 'block' : 'none';
    document.getElementById('adultToggleBtn').classList.toggle('active', view === 'adult');
    document.getElementById('kidsToggleBtn').classList.toggle('active', view === 'kids');
}

function updateToothCellUI(num, cond, notes) {
    const cell = document.querySelector(`.tooth-cell[data-tooth="${num}"]`);
    if (!cell) return;
    cell.dataset.condition = cond;
    const imgWrap = cell.querySelector('.tooth-img-wrap');
    if (imgWrap) imgWrap.style.setProperty('--tooth-color', CONDITIONS[cond].color);
    
    const existingBadge = cell.querySelector('.tooth-condition-badge');
    if (existingBadge) existingBadge.remove();
    
    if (cond !== 'healthy') {
        const badge = document.createElement('span');
        badge.className = 'tooth-condition-badge';
        badge.style.background = CONDITIONS[cond].color + '20';
        badge.style.color = CONDITIONS[cond].color;
        badge.textContent = (cond === 'other' && notes && notes.trim())
            ? notes.trim()
            : CONDITIONS[cond].label;
        badge.title = badge.textContent;
        cell.appendChild(badge);
    }
}

function refreshTopbar() {
    const entries = Object.values(chartData);
    document.getElementById('recordedMarks').textContent = entries.length + ' mark(s)';
    if (entries.length) {
        const latest = entries.reduce((a, b) => (a.updated_at > b.updated_at ? a : b));
        document.getElementById('lastExamDate').textContent = latest.updated_at;
        document.getElementById('lastUpdatedBy').textContent = latest.updated_by;
        document.getElementById('latestFindings').textContent = latest.notes || 'No updated notes recorded.';
    }
}

function saveTooth() {
    Swal.fire({icon:'info',title:'Read-only dental chart',text:'Only an authorized dentist can edit dental chart findings.'});
    return;
    if (!currentTooth) return;
    const cond = document.querySelector('input[name="condition"]:checked').value;
    const otherText = document.getElementById('otherConditionInput').value.trim();
    const notes = document.getElementById('toothNotes').value;

    if (cond === 'other' && !otherText) {
        Swal.fire({
            icon: 'warning',
            title: 'Condition Required',
            text: 'Please specify the condition when selecting "Other".'
        });
        return;
    }

    // For "Other", use the typed text as the saved notes (so it shows on the badge/tooltip)
    const finalNotes = cond === 'other' ? otherText : notes;

    const formData = new FormData();
    formData.append('action', 'save_tooth');
    formData.append('tooth_number', currentTooth);
    formData.append('condition_key', cond);
    formData.append('notes', finalNotes);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                chartData[currentTooth] = {
                    tooth_number: String(currentTooth),
                    condition_key: cond,
                    notes: finalNotes,
                    updated_by: data.updated_by,
                    updated_at: data.updated_at
                };
                updateToothCellUI(currentTooth, cond, finalNotes);
                refreshTopbar();
                closeToothModal();
                Swal.fire({ icon: 'success', title: 'Saved', text: data.message, timer: 1200, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

function resetTooth() {
    Swal.fire({icon:'info',title:'Read-only dental chart',text:'Only an authorized dentist can reset dental chart findings.'});
    return;
    if (!currentTooth) return;
    Swal.fire({
        title: `Reset tooth #${currentTooth} to healthy?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, reset',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'reset_tooth');
        formData.append('tooth_number', currentTooth);

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    delete chartData[currentTooth];
                    updateToothCellUI(currentTooth, 'healthy');
                    refreshTopbar();
                    closeToothModal();
                    Swal.fire({ icon: 'success', title: 'Reset', text: data.message, timer: 1200, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            });
    });
}

document.getElementById('toothModal').addEventListener('click', function(e) {
    if (e.target === this) closeToothModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeToothModal();
});

// ==========================================
// EDIT MODE MANAGEMENT
// ==========================================
let editMode = {};

function toggleEdit(section) {
    if (section === 'appointment') { toggleVisitChargeEdit(); return; }
    const card = document.getElementById(section + 'Card');
    const display = document.getElementById(section + 'Display');
    const actions = document.getElementById(section + 'Actions');
    
    if (!editMode[section]) {
        editMode[section] = true;
        card.classList.add('edit-mode');

        if (card.classList.contains('collapsible-card')) {
            card.classList.add('expanded');
        }
        
        if (actions) actions.style.display = 'flex';

        if (section === 'appointment') {
            const viewEl = display.querySelector('.view-mode-dentist');
            const editEl = display.querySelector('.edit-mode-dentist');
            if (viewEl && editEl) {
                viewEl.style.display = 'none';
                editEl.style.display = 'block';
            }

            const servicesView = document.getElementById('servicesView');
            const servicesEdit = document.getElementById('servicesEdit');
            if (servicesView && servicesEdit) {
                servicesView.style.display = 'none';
                servicesEdit.style.display = 'block';
                renderServicePicker();
            }
        }
        
        const fields = display.querySelectorAll('.editable-field');
        fields.forEach(field => {
            if (field.tagName === 'INPUT' || field.tagName === 'SELECT' || field.tagName === 'TEXTAREA') return;
            if (field.dataset.field === 'dentist_id') return;
            if (field.closest('#servicesView') || field.closest('#servicesEdit')) return;

            const currentText = field.textContent.trim();
            const fieldName = field.dataset.field;
            const fieldType = field.dataset.type || 'text';
            
            let input;
            if (fieldType === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
            } else {
                input = document.createElement('input');
                input.type = fieldType === 'number' ? 'number' : 'text';
            }
            
            input.className = 'editable-field w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent';
            input.dataset.field = fieldName;
            input.dataset.type = fieldType;
            input.value = currentText !== '—' ? currentText : '';
            input.placeholder = 'Enter ' + fieldName.replace(/_/g, ' ');
            
            field.parentNode.replaceChild(input, field);
        });
        
        display.querySelectorAll('select.editable-field').forEach(select => {
            select.disabled = false;
            select.style.border = '2px solid #0d9e8c';
            select.style.background = 'white';
            select.style.padding = '6px 10px';
            select.style.borderRadius = '6px';
        });
    }
}

function cancelEdit(section) {
    editMode[section] = false;
    location.reload();
}

function saveEdits(section) {
    const display = document.getElementById(section + 'Display');
    const data = {};
    
    display.querySelectorAll('input.editable-field, textarea.editable-field, select.editable-field').forEach(el => {
        if (el.offsetParent === null) return;
        data[el.dataset.field] = el.value;
    });
    
    display.querySelectorAll('.editable-field:not(input):not(textarea):not(select)').forEach(el => {
        if (el.offsetParent === null) return;
        if (el.dataset && el.dataset.field && !(el.dataset.field in data)) {
            data[el.dataset.field] = el.textContent.trim();
        }
    });

    if (section === 'appointment') {
        // Booked services are managed in Appointments; procedures are recorded per visit below.
    }
    
    if (Object.keys(data).length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Changes',
            text: 'No fields were found to save.'
        });
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_' + section);
    formData.append('appointment_id', <?= $id ?>);
    formData.append('data', JSON.stringify(data));
    formData.append('csrf_token', RECORD_CSRF);
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(response => {
        Swal.close();
        if (response.success) {
            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                text: response.message || 'Changes saved successfully.',
                timer: 1500,
                showConfirmButton: false
            });
            editMode[section] = false;
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: response.message || 'Failed to save changes.'
            });
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error saving:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while saving: ' + error.message
        });
    });
}
</script>

<style>
/* The footer action lives after the main page wrapper; align it with that wrapper. */
.treatment-completion-shell { box-sizing:border-box; width:auto; max-width:1200px;
  margin:26px 24px 45px max(284px, calc((100vw - 1200px + 260px)/2));
  padding:0 0 6px; }
.complete-treatment-action { display:flex;align-items:center;justify-content:center;gap:9px;
  width:100%;min-height:49px;padding:13px 18px;background:#0d9e8c;
  border:0;color:white;border-radius:11px;font:800 14px 'DM Sans',sans-serif;
  cursor:pointer;transition:background .15s; }
.complete-treatment-action:hover {background:#0a7d6e;}
.complete-treatment-action:focus-visible {outline:3px solid #0b7568;outline-offset:3px;}
@media(max-width:1023px){.treatment-completion-shell {margin:20px 16px 35px;}}
@media(min-width:1024px) and (max-width:1540px){.treatment-completion-shell {margin-left:284px;margin-right:24px;}}
</style>
<section class="treatment-completion-shell" id="treatmentCompletionArea" aria-labelledby="treatmentCompletionHeading">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;box-shadow:0 8px 28px rgba(11,22,40,.06);">
    <h3 id="treatmentCompletionHeading" style="font-size:18px;font-weight:800;color:#0b1628;margin-bottom:8px;">Treatment Completion</h3>
    <p style="font-size:13px;color:#64748b;margin-bottom:18px;">Review saved procedures, dentist, chart and actual charges before finalizing. Completion creates an unpaid invoice only if no bill exists.</p>
    <?php if ($appointment['status'] === 'in_progress'): ?>
      <button type="button" onclick="goToTreatmentCompletion()" class="complete-treatment-action"><i class="fas fa-check-circle" aria-hidden="true"></i> Complete Treatment</button>
    <?php elseif ($appointment['status'] === 'completed'): ?>
      <div style="background:#e6f7f5;color:#0a7d6e;padding:14px;border-radius:10px;font-weight:700;">Treatment completed. This record is preserved for viewing.</div>
    <?php else: ?>
      <div style="background:#f1f5f9;color:#64748b;padding:14px;border-radius:10px;">Start treatment from Appointment Details before completing this visit.</div>
    <?php endif; ?>
  </div>
</section>
<script>
async function goToTreatmentCompletion() {
  if (typeof Swal === 'undefined') { window.location.href = 'appointment_details.php?id=<?= (int)$id ?>'; return; }
  const r = await Swal.fire({icon:'warning',title:'Review treatment before completing',
    text:'Confirm procedures, tooth numbers, assigned dentist and charges. Complete the treatment using the verified appointment workflow.',
    showCancelButton:true,confirmButtonText:'Confirm Completion',confirmButtonColor:'#0d9e8c'});
  if (!r.isConfirmed) return;
  const data = new FormData();
  data.append('action', 'complete_record_treatment');
  data.append('appointment_id', String(RECORD_APPOINTMENT_ID));
  data.append('csrf_token', RECORD_CSRF);
  try {
    const res = await fetch(window.location.href, {method:'POST',body:data});
    const json = await res.json();
    if (!res.ok || !json.success) throw new Error(json.message || 'Unable to complete treatment.');
    await Swal.fire({icon:'success',title:'Treatment Completed',text:json.message,confirmButtonColor:'#0d9e8c'});
    window.location.reload();
  } catch (e) { Swal.fire({icon:'error',title:'Could Not Complete Treatment',text:e.message}); }
}
</script>
<script>
const RECORD_CSRF = <?= json_encode($recordCsrf) ?>;
const RECORD_APPOINTMENT_ID = <?= (int)$id ?>;
function toggleVisitServiceForm(force) {
    if (!visitEditing) return;
    const form = document.getElementById('procedureEntryForm');
    const btn = document.getElementById('toggleVisitServiceBtn');
    if (!form) return;
    const shouldShow = (typeof force === 'boolean') ? force : form.hidden;
    if (shouldShow && hasPendingVisitEdits()) {
        Swal.fire({icon:'info',title:'Save Changes First',text:'Save your dentist or charge edits before adding a service to avoid losing them.'});
        return;
    }
    form.hidden = !shouldShow;
    form.style.display = shouldShow ? 'grid' : 'none';
    if (btn) btn.setAttribute('aria-expanded', String(shouldShow));
    if (!shouldShow) {
        form.reset();
        updateVisitServiceFields();
    } else {
        form.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
    updateVisitTotalPreview();
}

const VISIT_CAN_EDIT = <?= json_encode(in_array($appointment['status'], ['confirmed','in_progress'], true)) ?>;
let visitEditing = false;
function toggleVisitChargeEdit(force) {
    if (!VISIT_CAN_EDIT) return;
    const next = (typeof force === 'boolean') ? force : !visitEditing;
    if (next === visitEditing) return;
    visitEditing = next;
    const card = document.getElementById('appointmentCard');
    card?.classList.toggle('visit-editing', visitEditing);
    const pencil = document.getElementById('visitEditToggle');
    pencil?.setAttribute('aria-expanded', String(visitEditing));
    const editor = document.getElementById('visitDentistEditor');
    const read = document.getElementById('visitDentistView');
    if (editor) editor.style.display = visitEditing ? 'block' : 'none';
    if (read) read.style.display = visitEditing ? 'none' : 'block';
    const canEditCharges = <?= json_encode($appointment['status'] === 'in_progress') ?>;
    document.querySelectorAll('.visit-charge-edit').forEach(f => {
        f.style.display = visitEditing && canEditCharges ? 'flex' : 'none';
    });
    document.querySelectorAll('.visit-charge-read').forEach(el => {
        el.style.display = visitEditing && canEditCharges ? 'none' : '';
    });
    if (!visitEditing) {
        const form = document.getElementById('procedureEntryForm');
        if (form) { form.reset(); form.hidden=true; form.style.display='none'; }
        const select = document.getElementById('visitDentistSelect');
        if (select) select.value = select.dataset.original || '';
        document.querySelectorAll('.visit-charge-edit input[name="charge"]').forEach(input => {
            input.value = input.dataset.original || input.value;
        });
    }
    updateVisitTotalPreview();
}
function cancelVisitEdit() { toggleVisitChargeEdit(false); }
function hasPendingVisitEdits() {
    const select = document.getElementById('visitDentistSelect');
    if (select && select.value !== select.dataset.original) return true;
    return Array.from(document.querySelectorAll('.visit-charge-edit input[name="charge"]')).some(input => {
        return input.value.trim() !== (input.dataset.original || '').trim();
    });
}
async function saveVisitAppointmentEdits() {
    if (!visitEditing) return;
    const dentist = document.getElementById('visitDentistSelect');
    if (!dentist || !dentist.value) {
        Swal.fire({icon:'warning', title:'Dentist Required', text:'Select an active dentist before saving.'});
        return;
    }
    const charges = [];
    for (const form of document.querySelectorAll('.visit-charge-edit')) {
        const input = form.querySelector('input[name="charge"]');
        if (!input) continue;
        const raw = input.value.trim();
        if (!/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/.test(raw)) {
            Swal.fire({icon:'warning',title:'Invalid Visit Charge',text:'Enter a valid charge (two decimal places maximum).'});
            input.focus();
            return;
        }
        charges.push({id:Number(form.dataset.procedureId),charge:raw});
    }
    const button = document.getElementById('saveVisitEditBtn');
    if (button) button.disabled = true;
    const fd = new FormData();
    fd.set('action','save_visit_edits');
    fd.set('appointment_id',String(RECORD_APPOINTMENT_ID));
    fd.set('csrf_token',RECORD_CSRF);
    fd.set('dentist_id',dentist.value);
    fd.set('charges',JSON.stringify(charges));
    try {
        const response = await fetch(window.location.href,{method:'POST',body:fd});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save changes.');
        await Swal.fire({icon:'success',title:'Appointment Updated',text:result.message,confirmButtonColor:'#0d9e8c'});
        window.location.reload();
    } catch (e) {
        Swal.fire({icon:'error',title:'Changes Not Saved',text:e.message || 'Try again.'});
        if (button) button.disabled = false;
    }
}

function updateVisitTotalPreview() {
    const total = document.getElementById('visitTotalDisplay');
    const form = document.getElementById('procedureEntryForm');
    if (!total) return;
    const saved = Number(total.dataset.savedTotal) || 0;
    const raw = form && !form.hidden ? document.getElementById('visitProcedureCharge')?.value : '';
    const pending = raw !== '' && Number.isFinite(Number(raw)) && Number(raw) >= 0 ? Number(raw) : 0;
    let changed = 0;
    document.querySelectorAll('.visit-charge-edit').forEach(f => {
        if (f.style.display === 'none') return;
        const input = f.querySelector('input[name="charge"]');
        if (!input) return;
        const current = Number(input.value);
        const original = Number(input.dataset.original);
        if (input.value !== '' && Number.isFinite(current) && current >= 0) changed += current - original;
    });
    total.textContent = '₱' + (saved + pending + changed).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
}
const assignedSelect = document.getElementById('visitDentistSelect');
if (assignedSelect) assignedSelect.dataset.original = assignedSelect.value;
if (new URLSearchParams(window.location.search).get('edit') === '1' && VISIT_CAN_EDIT) {
    toggleVisitChargeEdit(true);
}
document.getElementById('visitProcedureCharge')?.addEventListener('input', updateVisitTotalPreview);
document.querySelectorAll('.visit-charge-edit input[name="charge"]').forEach(input => {
    input.dataset.original = input.value;
    input.addEventListener('input', updateVisitTotalPreview);
});
function selectBookedProcedure(serviceId) {
    toggleVisitServiceForm(true);
    const sel = document.getElementById('visitServiceSelect');
    if (!sel) return;
    sel.value = String(serviceId);
    updateVisitServiceFields();
    document.getElementById('procedureEntryForm')?.scrollIntoView({behavior:'smooth',block:'center'});
}
async function updateVisitCharge(e, procedureId) {
    e.preventDefault();
    const form = e.target;
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    const data = new FormData(form);
    data.set('action','update_procedure_charge');
    data.set('appointment_id',String(RECORD_APPOINTMENT_ID));
    data.set('procedure_id',String(procedureId));
    data.set('csrf_token',RECORD_CSRF);
    try {
        const response = await fetch(window.location.href,{method:'POST',body:data});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not update charge.');
        window.location.reload();
    } catch(error) {
        if (typeof Swal !== 'undefined') Swal.fire({icon:'error',title:'Charge Not Saved',text:error.message});
        else alert(error.message);
        if (button) button.disabled = false;
    }
    return false;
}
async function removeVisitService(procedureId) {
    if (!visitEditing) return;
    if (hasPendingVisitEdits()) {
        Swal.fire({icon:'info',title:'Save Changes First',text:'Save your current dentist and charge edits before removing a service.'});
        return;
    }
    const confirmation = typeof Swal !== 'undefined'
        ? await Swal.fire({title:'Remove Service?',text:'Remove this service and its charge from this appointment only? The permanent clinic service will not change.',icon:'warning',showCancelButton:true,confirmButtonColor:'#b91c1c',confirmButtonText:'Remove Service'})
        : {isConfirmed:confirm('Remove this service from this visit?')};
    if (!confirmation.isConfirmed) return;
    const data = new FormData();
    data.set('action','remove_procedure');
    data.set('appointment_id', String(RECORD_APPOINTMENT_ID));
    data.set('procedure_id', String(procedureId));
    data.set('csrf_token', RECORD_CSRF);
    try {
        const response = await fetch(window.location.href,{method:'POST',body:data});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not remove the service.');
        window.location.assign('patient-record.php?id=' + RECORD_APPOINTMENT_ID + '&edit=1');
    } catch (error) {
        if (typeof Swal !== 'undefined') Swal.fire({icon:'error',title:'Service Not Removed',text:error.message});
        else alert(error.message);
    }
}
function updateVisitServiceFields() {
    const sel = document.getElementById('visitServiceSelect');
    const opt = sel.options[sel.selectedIndex];
    const n = document.getElementById('visitProcedureName');
    const p = document.getElementById('visitProcedureCharge');
    if (sel.value) { n.value = opt.textContent.split(' — ₱')[0].trim(); p.value = opt.dataset.price || ''; n.readOnly = true; }
    else { n.value = ''; p.value = ''; n.readOnly = false; }
    updateVisitTotalPreview();
}
async function submitVisitProcedure(e) {
    e.preventDefault();
    if (!visitEditing) return false;
    const f = new FormData();
    f.set('action', 'add_procedure');
    f.set('appointment_id', String(RECORD_APPOINTMENT_ID));
    f.set('csrf_token', RECORD_CSRF);
    f.set('service_id', document.getElementById('visitServiceSelect').value);
    f.set('procedure_name', document.getElementById('visitProcedureName').value);
    f.set('description', document.getElementById('visitProcedureDescription').value);
    f.set('charge', document.getElementById('visitProcedureCharge').value);
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        const r = await fetch(window.location.href,{method:'POST',body:f});
        const result = await r.json();
        if (!r.ok || !result.success) throw new Error(result.message || 'Save failed');
        window.location.assign('patient-record.php?id=' + RECORD_APPOINTMENT_ID + '&edit=1');
    } catch (err) { if(typeof Swal !== 'undefined') Swal.fire({icon:'error',title:'Procedure Not Saved',text:err.message}); else alert(err.message); btn.disabled = false; }
    return false;
}
</script>
</body>
</html>