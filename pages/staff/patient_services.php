<?php
// patient_services.php - Complete Single File Solution with Tabs and Session Management
// Supports adding a service for: an existing patient, an existing appointment, or a walk-in.

/*
 * REQUIRED DB CHANGE (run once):
 *
 * ALTER TABLE patient_services
 *   ADD COLUMN appointment_id INT NULL AFTER dentist_id,
 *   ADD INDEX idx_ps_appointment (appointment_id);
 */

require '../../conn/conn.php';
require '../../functions/patient_functions.php';
require '../../functions/audit_log.php';
require_once '../../functions/appointment_patient_search.php';
require_once '../../functions/connected_sessions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!aps_staff_authorized($conn)) { http_response_code(403); exit('Staff access required.'); }
dcs_csrf();

// Set timezone
date_default_timezone_set('Asia/Manila');

// ========== HELPERS ==========

// Find an active patient record that matches an appointment's phone or email
function findPatientByContact($conn, $phone, $email) {
    $phone = trim($phone ?? '');
    $email = trim($email ?? '');
    if ($phone === '' && $email === '') return null;

    $stmt = $conn->prepare("
        SELECT id, patient_id, full_name, phone, email, source
        FROM patients
        WHERE status = 'active'
          AND ((? <> '' AND phone = ?) OR (? <> '' AND email = ?))
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param("ssss", $phone, $phone, $email, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Get the services booked on an appointment (service_appointments first, then the JSON column)
function getAppointmentServiceList($conn, $appointment_id, $servicesJson = null) {
    $list = [];

    $stmt = $conn->prepare("
        SELECT s.id, s.title, s.price
        FROM service_appointments sa
        JOIN services s ON sa.service_id = s.id
        WHERE sa.appointment_id = ?
    ");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($list) && !empty($servicesJson)) {
        $ids = json_decode($servicesJson, true);
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!empty($ids)) {
                $in = implode(',', $ids);
                $res = $conn->query("SELECT id, title, price FROM services WHERE id IN ($in)");
                while ($row = $res->fetch_assoc()) {
                    $list[] = $row;
                }
            }
        }
    }
    return $list;
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if (str_starts_with($action, 'connected_')) dcs_handle($conn);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Search existing appointments (to add as a patient service)
    if ($action === 'search_appointments') {
        try {
            $search = isset($_POST['search']) ? trim($_POST['search']) : '';
            $like = "%$search%";

            $stmt = $conn->prepare("
                SELECT a.id, a.full_name, a.phone, a.email,
                       a.appointment_date, a.appointment_time, a.status,
                       a.dentist_id, a.services,
                       (SELECT COUNT(*) FROM patient_services ps WHERE ps.appointment_id = a.id) AS added_count
                FROM appointments a
                WHERE a.status IN ('approved','confirmed','completed')
                  AND (a.full_name LIKE ? OR a.phone LIKE ? OR a.email LIKE ?)
                ORDER BY a.appointment_date DESC, a.appointment_time DESC
                LIMIT 20
            ");
            $stmt->bind_param("sss", $like, $like, $like);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as &$row) {
                $row['service_list'] = getAppointmentServiceList($conn, (int)$row['id'], $row['services']);
                $row['matched_patient'] = findPatientByContact($conn, $row['phone'], $row['email']);
                unset($row['services']);
            }
            unset($row);

            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Find (or create) the patient record for an appointment
    if ($action === 'resolve_appointment_patient') {
        try {
            $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

            $hasPatientLink = $conn->query("SHOW COLUMNS FROM appointments LIKE 'patient_id'")->num_rows > 0;
            $apptCols = $hasPatientLink ? 'id, patient_id, full_name, phone, email' : 'id, full_name, phone, email';
            $stmt = $conn->prepare("SELECT {$apptCols} FROM appointments WHERE id = ?");
            $stmt->bind_param("i", $appointment_id);
            $stmt->execute();
            $appt = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appt) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                exit;
            }

            // Never pick a different person's record because two people share a phone/email.
            if ($hasPatientLink && !empty($appt['patient_id'])) {
                $patientPk = (int)$appt['patient_id'];
                $byId = $conn->prepare('SELECT id, patient_id, full_name, phone, email, source FROM patients WHERE id = ? LIMIT 1');
                $byId->bind_param('i', $patientPk);
                $byId->execute();
                $linkedPatient = $byId->get_result()->fetch_assoc();
                $byId->close();
                if (!$linkedPatient) {
                    echo json_encode(['success' => false, 'message' => 'Linked patient record is missing. Please review the appointment.']);
                    exit;
                }
                echo json_encode(['success' => true, 'id' => (int)$linkedPatient['id'],
                    'patient_id' => $linkedPatient['patient_id'], 'created' => false]);
                exit;
            }

            $existing = findPatientByContact($conn, $appt['phone'], $appt['email']);
            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'id' => (int)$existing['id'],
                    'patient_id' => $existing['patient_id'],
                    'created' => false
                ]);
                exit;
            }

            // No matching patient: register one from the appointment details
            $year = date('Y');
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM patients WHERE YEAR(created_at) = ?");
            $stmt->bind_param("i", $year);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['count'] + 1;
            $stmt->close();
            $patient_code = 'PAT-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

            $full_name = trim($appt['full_name'] ?? '');
            $phone = trim($appt['phone'] ?? '');
            $email = trim($appt['email'] ?? '');
            $age = 0;
            $gender = '';
            $address = '';
            $source = 'appointment';
            $pstatus = 'active';

            $stmt = $conn->prepare("INSERT INTO patients (patient_id, full_name, age, gender, phone, email, address, status, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssissssss", $patient_code, $full_name, $age, $gender, $phone, $email, $address, $pstatus, $source);
            $stmt->execute();
            $new_id = $conn->insert_id;
            $stmt->close();
            if ($hasPatientLink && $new_id > 0) {
                $link = $conn->prepare('UPDATE appointments SET patient_id = ? WHERE id = ? AND patient_id IS NULL');
                $link->bind_param('ii', $new_id, $appointment_id);
                $link->execute();
                $link->close();
            }

            echo json_encode([
                'success' => true,
                'id' => (int)$new_id,
                'patient_id' => $patient_code,
                'created' => true
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to prepare patient: ' . $e->getMessage()]);
        }
        exit;
    }

    // Create walk-in patient
    if ($action === 'create_walkin_patient') {
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $age = isset($_POST['age']) ? intval($_POST['age']) : 0;
        $gender = isset($_POST['gender']) ? $_POST['gender'] : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $source = 'walkin';
        $status = 'active';

        // Generate patient ID
        $year = date('Y');
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM patients WHERE YEAR(created_at) = ?");
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $count = $row['count'] + 1;
        $stmt->close();
        $patient_id = 'PAT-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        if (empty($full_name) || empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Full name and phone are required']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO patients (patient_id, full_name, age, gender, phone, email, address, status, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssissssss", $patient_id, $full_name, $age, $gender, $phone, $email, $address, $status, $source);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            echo json_encode(['success' => true, 'id' => $new_id, 'patient_id' => $patient_id, 'message' => 'Patient created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create patient: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    }

    // Get pending sessions for a service
    if ($action === 'get_pending_session') {
        $service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
        if ($service_id > 0) {
            $stmt = $conn->prepare("
                SELECT id 
                FROM service_sessions 
                WHERE patient_service_id = ? AND status = 'scheduled'
                ORDER BY session_number 
                LIMIT 1
            ");
            $stmt->bind_param("i", $service_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                echo json_encode(['success' => true, 'session_id' => $row['id']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No pending sessions']);
            }
            $stmt->close();
        }
        exit;
    }

    // Search patients for dropdown
    if ($action === 'search_patients') {
        $search = isset($_POST['search']) ? $_POST['search'] : '';
        $sql = "SELECT id, patient_id, full_name, phone, email, source 
                FROM patients 
                WHERE status = 'active' 
                AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR patient_id LIKE ?)
                ORDER BY full_name LIMIT 20";
        $search_param = "%$search%";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $patients = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode(['success' => true, 'data' => $patients]);
        exit;
    }

    // Delete patient service
    if ($action === 'delete' && $id > 0) {
        $managed=dcs_one($conn,'SELECT agreed_total FROM patient_services WHERE id=?','i',[$id]);
        if ($managed && $managed['agreed_total'] !== null) dcs_json(['success'=>false,'message'=>'Connected treatment records cannot be deleted. Use an authorized reconciliation.'],422);
        $snap_stmt = $conn->prepare("
            SELECT ps.status, p.full_name AS patient_name, s.title AS service_title
            FROM patient_services ps
            JOIN patients p ON ps.patient_id = p.id
            JOIN services s ON ps.service_id = s.id
            WHERE ps.id = ?
        ");
        $snap_stmt->bind_param("i", $id);
        $snap_stmt->execute();
        $snapshot = $snap_stmt->get_result()->fetch_assoc();
        $snap_stmt->close();

        $conn->begin_transaction();
        try {
            // Delete sessions first
            $stmt = $conn->prepare("DELETE FROM service_sessions WHERE patient_service_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            
            // Delete patient service
            $stmt = $conn->prepare("DELETE FROM patient_services WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            log_staff_activity(
                $conn, 'delete', 'patient_services',
                "Deleted service #{$id}" . ($snapshot ? " ({$snapshot['service_title']} for {$snapshot['patient_name']})" : ""),
                'patient_service', $id, $snapshot, null
            );
            echo json_encode(['success' => true, 'message' => 'Service deleted successfully.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to delete service.']);
        }
        exit;
    }

    // Get service details for edit/view
    if ($action === 'get' && $id > 0) {
        $stmt = $conn->prepare("
            SELECT ps.*, 
                   p.full_name as patient_name, p.patient_id as patient_code, p.phone, p.source,
                   s.title as service_title, s.price, s.duration,
                   d.first_name as dentist_first, d.last_name as dentist_last
            FROM patient_services ps
            JOIN patients p ON ps.patient_id = p.id
            JOIN services s ON ps.service_id = s.id
            JOIN dentists d ON ps.dentist_id = d.id
            WHERE ps.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $service = $result->fetch_assoc();
        $stmt->close();

        if ($service) {
            // Get sessions with dates
            $stmt = $conn->prepare("SELECT * FROM service_sessions WHERE patient_service_id = ? ORDER BY session_number");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $service['sessions'] = $sessions;
            
            echo json_encode(['success' => true, 'data' => $service]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Service not found.']);
        }
        exit;
    }

    // Update session status and date
    if ($action === 'update_session') {
        $managed=dcs_one($conn,'SELECT ps.agreed_total FROM patient_services ps JOIN service_sessions ss ON ss.patient_service_id=ps.id WHERE ss.id=?','i',[(int)($_POST['session_id']??0)]);
        if ($managed && $managed['agreed_total'] !== null) dcs_json(['success'=>false,'message'=>'Connected sessions must be updated using their linked appointment workflow.'],422);
        $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        $session_date = isset($_POST['session_date']) ? $_POST['session_date'] : null;
        $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
        
        if ($session_id > 0 && $status) {
            $stmt = $conn->prepare("UPDATE service_sessions SET status = ?, date = ?, notes = ? WHERE id = ?");
            $stmt->bind_param("sssi", $status, $session_date, $notes, $session_id);
            if ($stmt->execute()) {
                // Update patient_services status if all sessions completed
                $stmt2 = $conn->prepare("
                    SELECT ps.id, ps.total_sessions, 
                           COUNT(ses.id) as completed_count
                    FROM patient_services ps
                    LEFT JOIN service_sessions ses ON ps.id = ses.patient_service_id AND ses.status = 'completed'
                    WHERE ps.id = (SELECT patient_service_id FROM service_sessions WHERE id = ?)
                    GROUP BY ps.id
                ");
                $stmt2->bind_param("i", $session_id);
                $stmt2->execute();
                $result = $stmt2->get_result();
                $row = $result->fetch_assoc();
                $stmt2->close();
                
                if ($row && $row['completed_count'] >= $row['total_sessions']) {
                    $stmt3 = $conn->prepare("UPDATE patient_services SET status = 'completed' WHERE id = ?");
                    $stmt3->bind_param("i", $row['id']);
                    $stmt3->execute();
                    $stmt3->close();
                }

                log_staff_activity(
                    $conn, 'update', 'patient_services',
                    "Updated session #{$session_id} to status '{$status}'" . ($session_date ? " (date: {$session_date})" : ""),
                    'service_session', $session_id, null, ['status' => $status, 'date' => $session_date]
                );

                echo json_encode(['success' => true, 'message' => 'Session updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update session']);
            }
                        $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid session data']);
        }
        exit;
    }

    // ===== ADD A NEW SESSION TO AN EXISTING SERVICE =====
    if ($action === 'add_session') {
        $managed=dcs_one($conn,'SELECT agreed_total FROM patient_services WHERE id=?','i',[(int)($_POST['patient_service_id']??0)]);
        if ($managed && $managed['agreed_total'] !== null) dcs_json(['success'=>false,'message'=>'Use Session Charges to add a connected session and sync billing.'],422);
        $patient_service_id = isset($_POST['patient_service_id']) ? (int)$_POST['patient_service_id'] : 0;
        $session_date = isset($_POST['session_date']) && $_POST['session_date'] !== '' ? $_POST['session_date'] : null;
        $session_status = isset($_POST['session_status']) && $_POST['session_status'] !== '' ? $_POST['session_status'] : 'scheduled';
        $session_notes = isset($_POST['session_notes']) ? trim($_POST['session_notes']) : '';

        if ($patient_service_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid service.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // Find the current max session number for this service
            $stmt = $conn->prepare("SELECT COALESCE(MAX(session_number), 0) AS max_num FROM service_sessions WHERE patient_service_id = ?");
            $stmt->bind_param("i", $patient_service_id);
            $stmt->execute();
            $max_num = (int)$stmt->get_result()->fetch_assoc()['max_num'];
            $stmt->close();

            $new_session_number = $max_num + 1;

            // Insert the new session
            $stmt = $conn->prepare("INSERT INTO service_sessions (patient_service_id, session_number, status, date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $patient_service_id, $new_session_number, $session_status, $session_date, $session_notes);
            $stmt->execute();
            $new_session_id = $stmt->insert_id;
            $stmt->close();

            // Bump total_sessions on the parent service
            $stmt = $conn->prepare("UPDATE patient_services SET total_sessions = total_sessions + 1 WHERE id = ?");
            $stmt->bind_param("i", $patient_service_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            log_staff_activity(
                $conn, 'create', 'patient_services',
                "Added session #{$new_session_number} to service #{$patient_service_id}",
                'service_session', $new_session_id
            );

            echo json_encode([
                'success' => true,
                'message' => 'Session added successfully.',
                'session_number' => $new_session_number,
                'session_id' => $new_session_id
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to add session: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'live_search') {
        $search = isset($_POST['search']) ? $_POST['search'] : '';
        $status_filter = isset($_POST['status']) ? $_POST['status'] : '';
        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT ps.*, 
                       p.full_name as patient_name, p.patient_id as patient_code, p.source,
                       s.title as service_title, s.price,
                       d.first_name as dentist_first, d.last_name as dentist_last,
                       COUNT(ses.id) as total_sessions_count,
                       SUM(CASE WHEN ses.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions_count
                FROM patient_services ps
                JOIN patients p ON ps.patient_id = p.id
                JOIN services s ON ps.service_id = s.id
                JOIN dentists d ON ps.dentist_id = d.id
                LEFT JOIN service_sessions ses ON ps.id = ses.patient_service_id
                WHERE 1=1";
        $count_sql = "SELECT COUNT(DISTINCT ps.id) as total 
                      FROM patient_services ps
                      JOIN patients p ON ps.patient_id = p.id
                      JOIN services s ON ps.service_id = s.id
                      JOIN dentists d ON ps.dentist_id = d.id
                      WHERE 1=1";

        $params = []; $count_params = []; $types = ""; $count_types = "";

        if (!empty($status_filter) && $status_filter !== 'all') {
            $sql .= " AND ps.status = ?";
            $count_sql .= " AND ps.status = ?";
            $params[] = $status_filter; $count_params[] = $status_filter;
            $types .= "s"; $count_types .= "s";
        }
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR s.title LIKE ?)";
            $count_sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR s.title LIKE ?)";
            $sp = "%$search%";
            $params[] = $sp; $params[] = $sp; $params[] = $sp;
            $count_params[] = $sp; $count_params[] = $sp; $count_params[] = $sp;
            $types .= "sss"; $count_types .= "sss";
        }

        $sql .= " GROUP BY ps.id ORDER BY ps.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = $offset;
        $types .= "ii";

        $count_stmt = $conn->prepare($count_sql);
        if (!empty($count_params)) $count_stmt->bind_param($count_types, ...$count_params);
        $count_stmt->execute();
        $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
        $count_stmt->close();

        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $total = count($services);
        $stmt->close();

        // Build table rows HTML
        ob_start();
        if ($total > 0):
            foreach ($services as $service):
                $done = (int)($service['completed_sessions_count'] ?? 0);
                $tot = (int)($service['total_sessions'] ?? 0);
                $pct = $tot > 0 ? round(($done / $tot) * 100) : 0;
                $pct = max(0, min(100, $pct));
                $barClass = $pct >= 100 ? 'done' : ($pct >= 67 ? 'high' : ($pct >= 34 ? 'mid' : 'low'));
        ?>
        <tr id="row-<?= $service['id'] ?>">
            <td>
                <div class="font-medium text-dark-blue"><?= htmlspecialchars($service['patient_name']) ?></div>
                <div class="text-xs text-gray-500"><?= htmlspecialchars($service['patient_code']) ?></div>
                <span class="badge <?= $service['source'] == 'walkin' ? 'badge-walkin' : 'badge-appointment' ?>" style="font-size:10px; padding:2px 10px; border-radius:12px;">
                    <?= ucfirst($service['source'] ?? 'N/A') ?>
                </span>
            </td>
            <td>
                <div class="font-medium"><?= htmlspecialchars($service['service_title']) ?></div>
                <div class="text-xs text-gray-500">$<?= number_format($service['price'] ?? 0, 2) ?></div>
            </td>
            <td>Dr. <?= htmlspecialchars($service['dentist_first'] . ' ' . $service['dentist_last']) ?></td>
            <td>
                <div class="session-progress">
                    <div class="progress-labels">
                        <span class="progress-count"><?= $done ?>/<?= $tot ?> sessions</span>
                        <span class="progress-pct"><?= $pct ?>%</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar <?= $barClass ?>" style="width: <?= $pct ?>%;"></div>
                    </div>
                </div>
            </td>
            <td><span class="status-badge status-<?= str_replace('-', '', $service['status']) ?>"><?= ucfirst($service['status']) ?></span></td>
            <td>
                <div class="text-xs">
                    <div class="font-medium"><?= date('M d, Y', strtotime($service['created_at'])) ?></div>
                    <div class="text-gray-400"><?= date('g:i A', strtotime($service['created_at'])) ?></div>
                </div>
            </td>
            <td>
                <div class="action-icons flex items-center justify-center gap-0">
                    <button onclick="viewService(<?= $service['id'] ?>)" title="View Details" class="hover:bg-teal-light"><i class="fas fa-eye"></i></button>
                    <button onclick="editService(<?= $service['id'] ?>)" title="Edit Service" class="hover:bg-teal-light"><i class="fas fa-pen"></i></button>
                    <button onclick="deleteService(<?= $service['id'] ?>)" class="text-danger hover:bg-red-50" title="Delete Service"><i class="fas fa-trash-alt"></i></button>
                </div>
            </td>
        </tr>
        <?php
            endforeach;
        else:
        ?>
        <tr><td colspan="7">
            <div class="empty-state">
                <i class="fas fa-tooth"></i>
                <h3>No services found</h3>
                <p>Try adjusting your filters or add a new patient service.</p>
                <button onclick="showCreateModal()" class="btn-primary mt-4 inline-flex"><i class="fas fa-plus-circle"></i> Add Service</button>
            </div>
        </td></tr>
        <?php endif;
        $rows_html = ob_get_clean();

        // Build pagination HTML
        ob_start();
        if ($total_pages > 1): ?>
        <div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">
            <span class="text-xs text-gray-400">Showing <?= $offset + 1 ?>–<?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> records</span>
            <div class="flex gap-1">
                <?php if ($page > 1): ?><button class="pagination-link" onclick="liveSearch(<?= $page - 1 ?>)"><i class="fas fa-chevron-left"></i></button><?php endif; ?>
                <?php
                $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                <button class="pagination-link <?= $i === $page ? 'active' : '' ?>" onclick="liveSearch(<?= $i ?>)"><?= $i ?></button>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><button class="pagination-link" onclick="liveSearch(<?= $page + 1 ?>)"><i class="fas fa-chevron-right"></i></button><?php endif; ?>
            </div>
        </div>
        <?php endif;
        $pagination_html = ob_get_clean();

        echo json_encode([
            'success' => true,
            'html' => $rows_html,
            'pagination' => $pagination_html,
            'total' => $total_records
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success_message = null;
    $error_message = null;

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_service':
                $patient_id = intval($_POST['patient_id']);
                $service_id = intval($_POST['service_id']);
                $dentist_id = intval($_POST['dentist_id']);
                $appointment_id = !empty($_POST['appointment_id']) ? intval($_POST['appointment_id']) : null;
                $total_sessions = intval($_POST['total_sessions']);
                $status = $_POST['status'] ?? 'pending';
                $notes = $_POST['notes'] ?? '';
                $session_dates = isset($_POST['session_dates']) ? $_POST['session_dates'] : [];
                $session_statuses = isset($_POST['session_statuses']) ? $_POST['session_statuses'] : [];
                $session_notes_arr = isset($_POST['session_notes']) ? $_POST['session_notes'] : [];                
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO patient_services (patient_id, service_id, dentist_id, appointment_id, total_sessions, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiiiss", $patient_id, $service_id, $dentist_id, $appointment_id, $total_sessions, $status, $notes);
                    
                    if ($stmt->execute()) {
                        $patient_service_id = $conn->insert_id;
                        
                        // Create individual sessions with dates
                        for ($i = 1; $i <= $total_sessions; $i++) {
                            $session_date = isset($session_dates[$i-1]) && !empty($session_dates[$i-1]) ? $session_dates[$i-1] : null;
                            $session_status = isset($session_statuses[$i-1]) && !empty($session_statuses[$i-1]) ? $session_statuses[$i-1] : 'scheduled';
                            $session_note = isset($session_notes_arr[$i-1]) ? $session_notes_arr[$i-1] : '';
                            $session_stmt = $conn->prepare("INSERT INTO service_sessions (patient_service_id, session_number, status, date, notes) VALUES (?, ?, ?, ?, ?)");
                            $session_stmt->bind_param("iisss", $patient_service_id, $i, $session_status, $session_date, $session_note);
                            $session_stmt->execute();
                            $session_stmt->close();
                        }
                        
                        $conn->commit();
                        $success_message = "Service added successfully!";
                        log_staff_activity(
                            $conn, 'create', 'patient_services',
                            "Added service (patient #{$patient_id}, service #{$service_id}, {$total_sessions} session(s))" . ($appointment_id ? " from appointment #{$appointment_id}" : ""),
                            'patient_service', $patient_service_id
                        );
                    } else {
                        throw new Exception($stmt->error);
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error: " . $e->getMessage();
                }
                break;
                
            case 'update_service':
                $id = intval($_POST['id']);
                $managed=dcs_one($conn,'SELECT agreed_total FROM patient_services WHERE id=?','i',[$id]);
                if ($managed && $managed['agreed_total'] !== null) { $error_message='Connected treatments must be edited through Session Charges; invoice and completed sessions are protected.'; break; }
                $patient_id = intval($_POST['patient_id']);
                $service_id = intval($_POST['service_id']);
                $dentist_id = intval($_POST['dentist_id']);
                $total_sessions = intval($_POST['total_sessions']);
                $status = $_POST['status'] ?? 'pending';
                $notes = $_POST['notes'] ?? '';
                $session_dates = isset($_POST['session_dates']) ? $_POST['session_dates'] : [];
                $session_statuses = isset($_POST['session_statuses']) ? $_POST['session_statuses'] : [];
                $session_notes_arr = isset($_POST['session_notes']) ? $_POST['session_notes'] : [];                
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE patient_services SET patient_id = ?, service_id = ?, dentist_id = ?, total_sessions = ?, status = ?, notes = ? WHERE id = ?");
                    $stmt->bind_param("iiiissi", $patient_id, $service_id, $dentist_id, $total_sessions, $status, $notes, $id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Update sessions - keep existing sessions, update dates for matching numbers
                    for ($i = 1; $i <= $total_sessions; $i++) {
                        $session_date = isset($session_dates[$i-1]) && !empty($session_dates[$i-1]) ? $session_dates[$i-1] : null;
                        $session_status = isset($session_statuses[$i-1]) && !empty($session_statuses[$i-1]) ? $session_statuses[$i-1] : 'scheduled';
                        $session_note = isset($session_notes_arr[$i-1]) ? $session_notes_arr[$i-1] : '';
                        
                        // Check if session exists
                        $check_stmt = $conn->prepare("SELECT id FROM service_sessions WHERE patient_service_id = ? AND session_number = ?");
                        $check_stmt->bind_param("ii", $id, $i);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            // Update existing session
                            $update_stmt = $conn->prepare("UPDATE service_sessions SET date = ?, status = ?, notes = ? WHERE patient_service_id = ? AND session_number = ?");
                            $update_stmt->bind_param("sssii", $session_date, $session_status, $session_note, $id, $i);
                            $update_stmt->execute();
                            $update_stmt->close();
                        } else {
                            // Create new session
                            $insert_stmt = $conn->prepare("INSERT INTO service_sessions (patient_service_id, session_number, status, date, notes) VALUES (?, ?, ?, ?, ?)");
                            $insert_stmt->bind_param("iisss", $id, $i, $session_status, $session_date, $session_note);
                            $insert_stmt->execute();
                            $insert_stmt->close();
                        }
                        $check_stmt->close();
                    }
                    
                    // Remove extra sessions if total decreased
                    $delete_stmt = $conn->prepare("DELETE FROM service_sessions WHERE patient_service_id = ? AND session_number > ?");
                    $delete_stmt->bind_param("ii", $id, $total_sessions);
                    $delete_stmt->execute();
                    $delete_stmt->close();
                    
                    // Auto-complete the service if every session is completed
                    $recalc_stmt = $conn->prepare("
                        SELECT COUNT(*) as total_ct,
                               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_ct
                        FROM service_sessions WHERE patient_service_id = ?
                    ");
                    $recalc_stmt->bind_param("i", $id);
                    $recalc_stmt->execute();
                    $recalc_row = $recalc_stmt->get_result()->fetch_assoc();
                    $recalc_stmt->close();

                    if ($recalc_row && $recalc_row['total_ct'] > 0 && $recalc_row['completed_ct'] >= $recalc_row['total_ct']) {
                        $set_completed = $conn->prepare("UPDATE patient_services SET status = 'completed' WHERE id = ?");
                        $set_completed->bind_param("i", $id);
                        $set_completed->execute();
                        $set_completed->close();
                    }

                    $conn->commit();
                    $success_message = "Service updated successfully!";
                    log_staff_activity(
                        $conn, 'update', 'patient_services',
                        "Edited service #{$id} (patient #{$patient_id}, service #{$service_id}, status '{$status}')",
                        'patient_service', $id
                    );
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error: " . $e->getMessage();
                }
                break;
        }
    }

    // JSON response for the fetch() save calls (so failures are not mistaken for success)
    if (!empty($_POST['json'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => !empty($success_message) && empty($error_message),
            'message' => $success_message ?? $error_message ?? 'Unknown error'
        ]);
        exit;
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build base query for initial load
$sql = "SELECT ps.*, 
               p.full_name as patient_name, p.patient_id as patient_code, p.source,
               s.title as service_title, s.price,
               d.first_name as dentist_first, d.last_name as dentist_last,
               COUNT(ses.id) as total_sessions_count,
               SUM(CASE WHEN ses.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions_count
        FROM patient_services ps
        JOIN patients p ON ps.patient_id = p.id
        JOIN services s ON ps.service_id = s.id
        JOIN dentists d ON ps.dentist_id = d.id
        LEFT JOIN service_sessions ses ON ps.id = ses.patient_service_id
        WHERE 1=1";

$count_sql = "SELECT COUNT(DISTINCT ps.id) as total 
              FROM patient_services ps
              JOIN patients p ON ps.patient_id = p.id
              JOIN services s ON ps.service_id = s.id
              JOIN dentists d ON ps.dentist_id = d.id
              WHERE 1=1";

$params = [];
$count_params = [];
$types = "";
$count_types = "";

// Apply status filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $sql .= " AND ps.status = ?";
    $count_sql .= " AND ps.status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
    $types .= "s";
    $count_types .= "s";
}

// Apply search
if (!empty($search)) {
    $sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR s.title LIKE ?)";
    $count_sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR s.title LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $types .= "sss";
    $count_types .= "sss";
}

$sql .= " GROUP BY ps.id ORDER BY ps.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Get total count
$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$count_stmt->close();

// Get patient services
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$services = $result->fetch_all(MYSQLI_ASSOC);
$total = count($services);
$stmt->close();

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM patient_services
")->fetch_assoc();

// Get patients for dropdown
$patients_query = "SELECT id, patient_id, full_name, phone, email, source FROM patients WHERE status = 'active' ORDER BY full_name";
$patients_result = $conn->query($patients_query);

// Get services for dropdown
$services_query = "SELECT id, title, description, duration, price FROM services WHERE is_active = 1 ORDER BY sort_order";
$services_result = $conn->query($services_query);

// Get dentists for dropdown
$dentists_query = "SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY first_name";
$dentists_result = $conn->query($dentists_query);

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Services · Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: 'DM Sans', system-ui, sans-serif; }
        h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }

        :root {
            --navy: #0b1628;
            --teal: #0d9e8c;
            --teal-dark: #0a7d6e;
            --teal-light: #e6f7f5;
            --off: #f7f9fc;
            --slate: #64748b;
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
        .bg-dark-blue { background-color: var(--navy); }
        .text-dark-blue { color: var(--navy); }
        .bg-light-teal { background-color: var(--teal-light); }
        .bg-soft-gray { background-color: var(--off); }

        .container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
        @media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

        .btn-primary {
            background: var(--teal);
            color: white;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(13,158,140,0.25);
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 10px 28px rgba(13,158,140,0.35); }

        .btn-outline {
            border: 1.5px solid var(--teal);
            color: var(--teal);
            padding: 8px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s;
            background: transparent;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-outline:hover { background: var(--teal); color: white; }

        .btn-sm {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-sm-success { background: #d4edda; color: #155724; }
        .btn-sm-success:hover { background: #c3e6cb; }
        .btn-sm-danger { background: #f8d7da; color: #721c24; }
        .btn-sm-danger:hover { background: #f5c6cb; }
        .btn-sm-warning { background: #fff3cd; color: #856404; }
        .btn-sm-warning:hover { background: #ffe8a1; }
        .btn-sm-info { background: #cce5ff; color: #004085; }
        .btn-sm-info:hover { background: #b8d4f0; }

        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 1.5rem 1.25rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #eef2f6;
            transition: all 0.2s;
            cursor: pointer;
        }
        .stat-card:hover { border-color: var(--teal); box-shadow: 0 12px 32px rgba(11,22,40,.08); }
        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .dashboard-card {
            background: white;
            border-radius: 18px;
            padding: 1.5rem 1.25rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
        }
        .dashboard-card .card-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 0.5rem;
        }
        .services-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 900px;
        }
        .services-table th {
            text-align: left;
            padding: 12px 10px 10px 10px;
            font-weight: 600;
            color: var(--slate);
            font-size: 11px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border-bottom: 2px solid #e9edf2;
            background: #fafbfc;
        }
        .services-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f1f4f8;
            vertical-align: middle;
        }
        .services-table tr:hover td {
            background: #fafbfc;
        }
        .services-table tr:last-child td { border-bottom: none; }

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .badge-walkin { background: #e8f5e9; color: #2e7d32; }
        .badge-appointment { background: #e3f2fd; color: #1565c0; }

        .action-icons a, .action-icons button {
            color: var(--slate);
            margin: 0 3px;
            transition: all 0.15s;
            font-size: 14px;
            padding: 4px 6px;
            border-radius: 6px;
            background: none;
            border: none;
            cursor: pointer;
        }
        .action-icons a:hover, .action-icons button:hover { background: var(--teal-light); color: var(--teal); }
        .action-icons a.text-danger:hover, .action-icons button.text-danger:hover { background: #fee9e7; color: #e74c3c; }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 16px;
            background: white;
            border-radius: 16px;
            padding: 12px 18px;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            margin-bottom: 1.5rem;
        }
        .filter-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--slate);
        }
        .filter-bar select, .filter-bar input {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 7px 14px;
            font-size: 13px;
            background: white;
            outline: none;
            transition: border 0.2s;
        }
        .filter-bar select:focus, .filter-bar input:focus { border-color: var(--teal); }

        .pagination-link {
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 13px;
            color: var(--slate);
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
            background: white;
        }
        .pagination-link:hover { background: #f1f4f8; border-color: var(--teal); }
        .pagination-link.active { background: var(--teal); color: white; border-color: var(--teal); }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
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

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 60;
            background: rgba(11, 22, 40, 0.55);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: white;
            border-radius: 18px;
            width: 100%;
            max-width: 720px;
            max-height: 95vh;
            box-shadow: 0 24px 60px rgba(11,22,40,.35);
            overflow: hidden;
            animation: modalPop .18s ease-out;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalPop {
            from { opacity: 0; transform: translateY(8px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #f0f3f7;
            flex-shrink: 0;
        }
        .modal-header h3 { font-size: 17px; font-weight: 700; color: var(--navy); }
        .modal-close-btn {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
        }
        .modal-close-btn:hover { background: #f1f4f8; color: var(--navy); }
        .modal-body { 
            padding: 20px 24px; 
            overflow-y: auto; 
            background: #f8fafc;
            flex: 1;
        }
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            padding: 16px 24px;
            background: white;
            border-top: 1px solid #f0f3f7;
            border-radius: 0 0 18px 18px;
            flex-shrink: 0;
        }
        .form-field { margin-bottom: 16px; }
        .form-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--slate);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }
        .form-field input, .form-field select, .form-field textarea {
            width: 100%;
            border: 2px solid #e8edf4;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
            background: white;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: #0b1628;
        }
        .form-field input:focus, .form-field select:focus, .form-field textarea:focus { 
            border-color: var(--teal); 
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
        }
        .form-field textarea { resize: vertical; min-height: 60px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        /* Tabs */
        .tab-container {
            display: flex;
            gap: 4px;
            background: #f1f4f8;
            padding: 4px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .tab-btn {
            flex: 1;
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.25s;
            background: transparent;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .tab-btn:hover { color: var(--navy); }
        .tab-btn.active {
            background: white;
            color: var(--teal);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Patient / appointment search */
        .patient-search-wrapper {
            position: relative;
        }
        .patient-search-wrapper .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        #patientSearchResults,
        #appointmentSearchResults {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e8edf4;
            border-radius: 10px;
            max-height: 240px;
            overflow-y: auto;
            z-index: 10;
            display: none;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        #patientSearchResults.show,
        #appointmentSearchResults.show { display: block; }
        .patient-search-item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f1f4f8;
            transition: background 0.15s;
        }
        .patient-search-item:hover { background: var(--teal-light); }
        .patient-search-item:last-child { border-bottom: none; }
        .patient-search-item .name { font-weight: 600; color: var(--navy); font-size: 14px; }
        .patient-search-item .details { font-size: 12px; color: #94a3b8; }
        
        .selected-patient {
            background: var(--teal-light);
            border: 2px solid var(--teal);
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }
        .selected-patient .info .name { font-weight: 600; color: var(--navy); }
        .selected-patient .info .details { font-size: 12px; color: #94a3b8; }

        .mini-badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 6px;
        }
        .mini-badge.ok { background: #d4edda; color: #155724; }
        .mini-badge.new { background: #fff3cd; color: #856404; }
        .mini-badge.added { background: #d1e7ff; color: #084298; }

        .appt-service-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            border: 1.5px solid #cfe9e5;
            background: white;
            color: var(--navy);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .appt-service-chip:hover { border-color: var(--teal); }
        .appt-service-chip.active { background: var(--teal); border-color: var(--teal); color: white; }

        /* Session management */
        .session-list {
            max-height: 300px;
            overflow-y: auto;
        }
        .session-item {
            background: white;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 8px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        .session-item:hover { border-color: var(--teal); }
        .session-item .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .session-item .session-number {
            font-weight: 600;
            color: var(--navy);
            font-size: 14px;
        }
        .session-item .session-controls {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .session-item .session-controls select,
        .session-item .session-controls input[type="date"] {
            padding: 4px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            background: white;
        }
        .session-item .session-controls select:focus,
        .session-item .session-controls input[type="date"]:focus {
            border-color: var(--teal);
        }
        .session-item .session-notes {
            margin-top: 8px;
            width: 100%;
        }
        .session-item .session-notes input {
            width: 100%;
            padding: 4px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
        }
        .session-item .session-notes input:focus {
            border-color: var(--teal);
        }

        /* Walk-in form */
        .walkin-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        @media (max-width: 640px) {
            .walkin-form-row {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .tab-btn { padding: 10px 6px; font-size: 12px; gap: 5px; }
            .session-item .session-controls {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
            }
            .session-item .session-controls select,
            .session-item .session-controls input[type="date"] {
                width: 100%;
            }
        }

        @media (min-width: 1024px) {
            .main-content { margin-left: 260px; }
        }
        @media (max-width: 1023px) {
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

<div class="main-content">
    <main class="py-6 px-4">
        <div class="container-modern">

            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-dark-blue flex items-center gap-3">
                         Patient Services
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Manage all patient services and sessions · <span id="totalCount"><?= $stats['total'] ?? 0 ?></span> total</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button onclick="openConnectedModal()" class="btn-primary" style="background:#0b1628"><i class="fas fa-link"></i> Connected Treatment</button>
                    <button onclick="showCreateModal()" class="btn-primary">
                        <i class="fas fa-plus-circle"></i> Add Service
                    </button>
                   
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1"><?= $stats['total'] ?? 0 ?></p>
                            <p class="text-xs text-gray-500 mt-1">all services</p>
                        </div>
                        <div class="stat-icon bg-teal-light text-teal">
                            <i class="fas fa-list"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('pending')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Pending</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1"><?= $stats['pending'] ?? 0 ?></p>
                            <p class="text-xs text-amber-600 font-medium mt-1">awaiting start</p>
                        </div>
                        <div class="stat-icon bg-amber-50 text-amber-600">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('in-progress')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">In Progress</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1"><?= $stats['in_progress'] ?? 0 ?></p>
                            <p class="text-xs text-blue-600 font-medium mt-1">active treatment</p>
                        </div>
                        <div class="stat-icon bg-blue-50 text-blue-600">
                            <i class="fas fa-spinner"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('completed')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Completed</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1"><?= $stats['completed'] ?? 0 ?></p>
                            <p class="text-xs text-green-600 font-medium mt-1">fully done</p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('cancelled')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Cancelled</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1"><?= $stats['cancelled'] ?? 0 ?></p>
                            <p class="text-xs text-red-500 font-medium mt-1">cancelled</p>
                        </div>
                        <div class="stat-icon bg-red-50 text-red-500">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <i class="fas fa-filter text-teal"></i>
                    <span class="font-medium">Filter:</span>
                </div>
                <div class="filter-group">
                    <label for="statusFilter" class="sr-only">Status</label>
                    <select id="statusFilter" name="status" class="w-36">
                        <option value="all" <?= $status_filter === 'all' || empty($status_filter) ? 'selected' : '' ?>>All Status</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in-progress" <?= $status_filter === 'in-progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group flex-1 min-w-[200px]">
                    <label for="searchInput" class="sr-only">Search</label>
                    <div class="relative w-full">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="searchInput" 
                               placeholder="Search by patient, service, or ID..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg"
                               style="padding-left:30px !important"
                               value="<?= htmlspecialchars($search) ?>"
                               autocomplete="off">
                    </div>
                </div>
                <span class="text-xs text-gray-400 ml-auto hidden lg:inline">
                    <i class="far fa-calendar-alt mr-1"></i> <?= date('F d, Y h:i A') ?>
                </span>
            </div>

            <!-- Services Table -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span><i class="fas fa-list-ul mr-2 text-teal"></i> Service List</span>
                    <span class="text-xs text-gray-400 bg-gray-50 px-3 py-1 rounded-full" id="recordCount">
                        <?= $total ?> of <?= $total_records ?> records
                    </span>
                </div>

                <div class="table-container">
                    <table class="services-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Service</th>
                                <th>Dentist</th>
                                <th>Sessions</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-center" style="min-width:100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="servicesBody">
                            <?php if ($total > 0): ?>
                            <?php foreach ($services as $service): 
                                $done = (int)($service['completed_sessions_count'] ?? 0);
                                $tot = (int)($service['total_sessions'] ?? 0);
                                $pct = $tot > 0 ? round(($done / $tot) * 100) : 0;
                                $pct = max(0, min(100, $pct));
                                $barClass = $pct >= 100 ? 'done' : ($pct >= 67 ? 'high' : ($pct >= 34 ? 'mid' : 'low'));
                            ?>
                            <tr id="row-<?= $service['id'] ?>">
                                <td>
                                    <div class="font-medium text-dark-blue"><?= htmlspecialchars($service['patient_name']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($service['patient_code']) ?></div>
                                    <span class="badge <?= $service['source'] == 'walkin' ? 'badge-walkin' : 'badge-appointment' ?>" style="font-size:10px; padding:2px 10px; border-radius:12px;">
                                        <?= ucfirst($service['source'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="font-medium"><?= htmlspecialchars($service['service_title']) ?></div>
                                    <div class="text-xs text-gray-500">$<?= number_format($service['price'] ?? 0, 2) ?></div>
                                </td>
                                <td>
                                    Dr. <?= htmlspecialchars($service['dentist_first'] . ' ' . $service['dentist_last']) ?>
                                </td>
                                <td>
                                    <div class="session-progress">
                                        <div class="progress-labels">
                                            <span class="progress-count"><?= $done ?>/<?= $tot ?> sessions</span>
                                            <span class="progress-pct"><?= $pct ?>%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar <?= $barClass ?>" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= str_replace('-', '', $service['status']) ?>">
                                        <?= ucfirst($service['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-xs">
                                        <div class="font-medium"><?= date('M d, Y', strtotime($service['created_at'])) ?></div>
                                        <div class="text-gray-400"><?= date('g:i A', strtotime($service['created_at'])) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-icons flex items-center justify-center gap-0">
                                        <button onclick="viewService(<?= $service['id'] ?>)" title="View Details" class="hover:bg-teal-light">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="editService(<?= $service['id'] ?>)" title="Edit Service" class="hover:bg-teal-light">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button onclick="deleteService(<?= $service['id'] ?>)" class="text-danger hover:bg-red-50" title="Delete Service">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-tooth"></i>
                                        <h3>No services found</h3>
                                        <p>Try adjusting your filters or add a new patient service.</p>
                                        <button onclick="showCreateModal()" class="btn-primary mt-4 inline-flex">
                                            <i class="fas fa-plus-circle"></i> Add Service
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div id="paginationContainer">
                    <?php if ($total_pages > 1): ?>
                    <div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">
                        <span class="text-xs text-gray-400">
                            Showing <?= $offset + 1 ?>–<?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> records
                        </span>
                        <div class="flex gap-1">
                            <?php if ($page > 1): ?>
                            <button class="pagination-link" onclick="liveSearch(<?= $page - 1 ?>)">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <button class="pagination-link <?= $i === $page ? 'active' : '' ?>" onclick="liveSearch(<?= $i ?>)">
                                <?= $i ?>
                            </button>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                            <button class="pagination-link" onclick="liveSearch(<?= $page + 1 ?>)">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center text-xs text-gray-400 border-t border-gray-200 pt-5">
                <i class="fas fa-tooth mr-1 text-teal/50"></i>
                Wansi Calacat Dental Clinic · Patient Services Management
            </div>
        </div>
    </main>
</div>

<!-- Add/Edit Service Modal -->
<div id="serviceModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); border-radius: 18px 18px 0 0; padding: 18px 24px;">
            <h3 id="serviceModalTitle" style="color: white; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-plus-circle" style="color: #0d9e8c;"></i>
                New Patient Service
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeServiceModal()" style="color: rgba(255,255,255,0.6); font-size: 22px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body" style="padding: 24px 28px; overflow-y: auto; background: #f8fafc;">
            <form id="serviceForm" onsubmit="return false;">
                <input type="hidden" id="serviceId" value="">
                <input type="hidden" id="selectedPatientId" value="">
                <input type="hidden" id="selectedAppointmentId" value="">
                
                <!-- Patient Selection Tabs -->
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px;">
                        <i class="fas fa-user" style="color: #0d9e8c; font-size: 14px;"></i>
                        <span style="font-size: 12px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px;">Patient Selection</span>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="tab-container">
                        <button type="button" class="tab-btn active" data-tab="existing" onclick="switchTab('existing')">
                            <i class="fas fa-users"></i> Existing Patient
                        </button>
                        <button type="button" class="tab-btn" data-tab="appointment" onclick="switchTab('appointment')">
                            <i class="fas fa-calendar-check"></i> From Appointment
                        </button>
                        <button type="button" class="tab-btn" data-tab="walkin" onclick="switchTab('walkin')">
                            <i class="fas fa-walking"></i> Walk-in
                        </button>
                    </div>
                    
                    <!-- Existing Patient Tab -->
                    <div id="tab-existing" class="tab-content active">
                        <div class="patient-search-wrapper">
                            <input type="text" id="patientSearch" placeholder="Search for existing patient..." 
                                   oninput="searchPatients(this.value)"
                                   onfocus="this.style.borderColor='#0d9e8c'; this.style.boxShadow='0 0 0 4px rgba(13,158,140,0.1)'" 
                                   onblur="setTimeout(() => { document.getElementById('patientSearchResults').classList.remove('show'); }, 200)"
                                   style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 40px 10px 14px; font-size: 14px; outline: none; transition: all 0.2s; background: white;">
                            <i class="fas fa-search search-icon" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#94a3b8;"></i>
                            <div id="patientSearchResults"></div>
                        </div>
                        
                        <div id="selectedPatientDisplay" style="display: none; margin-top: 12px;" class="selected-patient">
                            <div class="info">
                                <div class="name" id="selectedPatientName">—</div>
                                <div class="details">
                                    <span id="selectedPatientCode">—</span> · 
                                    <span id="selectedPatientPhone">—</span> · 
                                    <span id="selectedPatientSource">—</span>
                                </div>
                            </div>
                            <button type="button" onclick="clearSelectedPatient()" style="padding: 4px 12px; border-radius: 6px; border: 1px solid #e8edf4; background: white; color: #e74c3c; font-size: 12px; cursor: pointer;">
                                <i class="fas fa-times"></i> Change
                            </button>
                        </div>
                    </div>

                    <!-- From Appointment Tab -->
                    <div id="tab-appointment" class="tab-content">
                        <div class="patient-search-wrapper">
                            <input type="text" id="appointmentSearch" placeholder="Search appointment by name, phone or email..." 
                                   oninput="searchAppointments(this.value)"
                                   onfocus="this.style.borderColor='#0d9e8c'; this.style.boxShadow='0 0 0 4px rgba(13,158,140,0.1)'" 
                                   onblur="setTimeout(() => { document.getElementById('appointmentSearchResults').classList.remove('show'); }, 200)"
                                   autocomplete="off"
                                   style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 40px 10px 14px; font-size: 14px; outline: none; transition: all 0.2s; background: white;">
                            <i class="fas fa-search search-icon" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#94a3b8;"></i>
                            <div id="appointmentSearchResults"></div>
                        </div>
                        <div style="font-size: 11px; color: #94a3b8; margin-top: 6px;">
                            <i class="fas fa-info-circle"></i> Shows approved, confirmed and completed appointments.
                        </div>

                        <div id="selectedAppointmentDisplay" style="display: none; margin-top: 12px; flex-direction: column; align-items: stretch; gap: 10px;" class="selected-patient">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                                <div class="info">
                                    <div class="name" id="selectedApptName">—</div>
                                    <div class="details" id="selectedApptDetails">—</div>
                                    <div class="details" id="selectedApptPatientNote" style="margin-top: 4px;"></div>
                                </div>
                                <button type="button" onclick="clearSelectedAppointment()" style="padding: 4px 12px; border-radius: 6px; border: 1px solid #e8edf4; background: white; color: #e74c3c; font-size: 12px; cursor: pointer; flex-shrink: 0;">
                                    <i class="fas fa-times"></i> Change
                                </button>
                            </div>
                            <div id="selectedApptServicesWrap" style="display: none;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px;">Services on this appointment (click to use)</div>
                                <div id="selectedApptServices" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Walk-in Tab -->
                    <div id="tab-walkin" class="tab-content">
                        <div style="background: #f8fafc; border-radius: 10px; padding: 16px; border: 2px dashed #d0d7e2;">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                                <i class="fas fa-user-plus" style="color: #0d9e8c; font-size: 20px;"></i>
                                <div>
                                    <div style="font-weight: 600; color: #0b1628; font-size: 14px;">New Walk-in Patient</div>
                                    <div style="font-size: 12px; color: #94a3b8;">Register a new walk-in patient</div>
                                </div>
                            </div>
                            
                            <div class="walkin-form-row">
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinFullName">Full Name <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" id="walkinFullName" placeholder="Enter full name">
                                </div>
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinPhone">Phone <span style="color: #e74c3c;">*</span></label>
                                    <input type="text" id="walkinPhone" placeholder="09xx xxx xxxx">
                                </div>
                            </div>
                            <div class="walkin-form-row" style="margin-top: 12px;">
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinEmail">Email</label>
                                    <input type="email" id="walkinEmail" placeholder="patient@email.com">
                                </div>
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinAge">Age</label>
                                    <input type="number" id="walkinAge" placeholder="Age">
                                </div>
                            </div>
                            <div class="walkin-form-row" style="margin-top: 12px;">
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinGender">Gender</label>
                                    <select id="walkinGender">
                                        <option value="">Select gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-field" style="margin-bottom: 0;">
                                    <label for="walkinAddress">Address</label>
                                    <input type="text" id="walkinAddress" placeholder="Patient's address">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Service Details -->
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-tooth" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Service Details</h4>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="serviceSelect">Service <span style="color: #e74c3c;">*</span></label>
                            <select id="serviceSelect" required>
                                <option value="">Choose a service...</option>
                                <?php if ($services_result && $services_result->num_rows > 0): ?>
                                    <?php while($row = $services_result->fetch_assoc()): ?>
                                        <option value="<?= $row['id'] ?>" data-price="<?= $row['price'] ?>" data-duration="<?= $row['duration'] ?>">
                                            <?= htmlspecialchars($row['title']) ?> 
                                            ($<?= number_format($row['price'] ?? 0, 2) ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="dentistSelect">Dentist <span style="color: #e74c3c;">*</span></label>
                            <select id="dentistSelect" required>
                                <option value="">Choose a dentist...</option>
                                <?php if ($dentists_result && $dentists_result->num_rows > 0): ?>
                                    <?php while($row = $dentists_result->fetch_assoc()): ?>
                                        <option value="<?= $row['id'] ?>">
                                            Dr. <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                            <?php if($row['specialization']): ?>
                                                (<?= htmlspecialchars($row['specialization']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row" style="margin-top: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="totalSessions">Total Sessions <span style="color: #e74c3c;">*</span></label>
                            <input type="number" id="totalSessions" min="1" value="1" required onchange="updateSessionDates()">
                            <small style="color: #94a3b8; font-size: 11px;">Number of sessions required</small>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="serviceStatus">Status</label>
                            <select id="serviceStatus">
                                <option value="pending">Pending</option>
                                <option value="in-progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Sessions Management -->
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-calendar-alt" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Sessions Schedule</h4>
                        <span style="margin-left: auto; font-size: 11px; color: #94a3b8;" id="sessionCountLabel">0 sessions</span>
                    </div>
                    
                    <div id="sessionList" class="session-list">
                        <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 13px;">
                            <i class="fas fa-calendar-plus" style="display: block; font-size: 24px; margin-bottom: 8px; color: #d0d7e2;"></i>
                            Set the number of sessions above to schedule dates
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                <div style="background: white; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <i class="fas fa-sticky-note" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Notes</h4>
                    </div>
                    
                    <div class="form-field" style="margin-bottom: 0;">
                        <label for="serviceNotes">Additional Notes</label>
                        <textarea id="serviceNotes" rows="2" placeholder="Any additional notes about this service..." style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; transition: all 0.2s; background: white; font-family: 'DM Sans', system-ui, sans-serif; resize: vertical; min-height: 60px;"></textarea>
                    </div>
                </div>
                
                <div id="serviceViewMeta" class="hidden" style="margin-top: 12px; padding: 12px 16px; background: #f8fafc; border-radius: 10px; border: 1px solid #eef2f6; font-size: 12px; color: #94a3b8; line-height: 1.6;"></div>
            </form>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeServiceModal()" style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white; transition: all 0.2s; cursor: pointer;"
                    onmouseover="this.style.backgroundColor='#f8fafc'; this.style.borderColor='#cbd5e1'" 
                    onmouseout="this.style.backgroundColor='white'; this.style.borderColor='#e2e8f0'">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="serviceSaveBtn" class="btn-primary" onclick="saveService()" style="padding: 10px 26px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 14px rgba(13,158,140,0.3); display: inline-flex; align-items: center; gap: 8px;"
                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 25px rgba(13,158,140,0.4)'" 
                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 14px rgba(13,158,140,0.3)'">
                <i class="fas fa-save"></i> Add Service
            </button>
        </div>
    </div>
</div>


<!-- Connected appointment / service / session billing setup -->
<div class="modal-overlay" id="connectedModal" role="dialog" aria-modal="true" aria-label="Connected treatment and sessions">
  <div class="modal-box" style="max-width:790px">
    <div class="modal-header" style="background:#0b1628;color:#fff">
      <h3 style="color:white"><i class="fas fa-link" style="color:#0d9e8c"></i> Connected Treatment & Session Pricing</h3>
      <button type="button" onclick="closeConnectedModal()" class="modal-close-btn" style="color:white" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-field"><label>Treatment Source</label><select id="dcsSource" onchange="dcsSwitchSource()">
        <option value="appointment">From Completed Appointment</option><option value="patient">New Treatment for Existing Patient</option>
      </select></div>
      <div class="form-field"><label>Search Patient Name / Completed Appointment</label><input id="dcsLookup" placeholder="Enter 2 or more characters..." oninput="dcsSearch()"><div id="dcsResults" style="margin-top:8px"></div></div>
      <div id="dcsSelection" style="font-size:12px;color:#0a7d6e;margin:10px 0"></div>
      <div class="form-row"><div class="form-field"><label>Service</label><select id="dcsService" onchange="dcsChooseService()"><option value="">Choose service</option>
      <?php $dcsServices=$conn->query('SELECT id,title,price FROM services WHERE is_active=1 ORDER BY title'); while($sv=$dcsServices->fetch_assoc()): ?>
      <option value="<?= (int)$sv['id'] ?>" data-price="<?= htmlspecialchars($sv['price'],ENT_QUOTES) ?>"><?= htmlspecialchars($sv['title'],ENT_QUOTES) ?></option><?php endwhile; ?></select></div>
      <div class="form-field"><label>Assigned Dentist</label><select id="dcsDentist"><option value="">Choose dentist</option>
      <?php $dcsDentists=$conn->query('SELECT id,first_name,last_name FROM dentists WHERE is_active=1 ORDER BY first_name'); while($dr=$dcsDentists->fetch_assoc()): ?>
      <option value="<?= (int)$dr['id'] ?>"><?= htmlspecialchars('Dr. '.$dr['first_name'].' '.$dr['last_name'],ENT_QUOTES) ?></option><?php endwhile; ?></select></div></div>
      <div class="form-row"><div class="form-field"><label>Total Treatment Charge (₱)</label><input id="dcsTotal" type="number" min="0" max="9999999" step="0.01" value="0.00" oninput="dcsBuildSessions(false)"></div>
      <div class="form-field"><label>Total Sessions</label><input id="dcsNumber" type="number" min="1" max="60" value="1" oninput="dcsBuildSessions(false)"></div></div>
      <div style="display:flex;align-items:center;gap:10px;background:#e6f7f5;border-radius:10px;padding:12px;margin:12px 0">
        <input id="dcsSplit" type="checkbox" onchange="dcsBuildSessions(false)" style="width:19px;height:19px;accent-color:#0d9e8c">
        <div><strong>Enable per-session pricing</strong><div style="font-size:12px;color:#64748b">Off: total is charged once; additional sessions have zero extra charge. On: distribute the total across sessions, with editable amounts.</div></div>
      </div>
      <div id="dcsSessionPreview"></div>
      <div id="dcsMessage" role="status" style="color:#b45309;font-size:12px;margin-top:8px"></div>
    </div>
    <div class="modal-footer"><button class="btn-outline" onclick="closeConnectedModal()">Cancel</button><button id="dcsSubmit" class="btn-primary" onclick="dcsSave()"><i class="fas fa-save"></i> Save & Sync Billing</button></div>
  </div>
</div>
<!-- Session pricing editor for a saved treatment -->
<div class="modal-overlay" id="dcsPricingModal" role="dialog" aria-modal="true" aria-label="Edit session pricing">
 <div class="modal-box" style="max-width:650px"><div class="modal-header" style="background:#0b1628"><h3 style="color:white">Session Pricing & Billing</h3><button style="color:white" class="modal-close-btn" onclick="dcsClosePricing()">&times;</button></div>
 <div class="modal-body"><div id="dcsPricingList"></div><div id="dcsPricingWarning" style="font-size:12px;color:#b45309;margin-top:10px"></div>
 <div class="form-row" style="margin-top:18px"><div class="form-field"><label>New Session Date</label><input id="dcsNewDate" type="date"></div><div class="form-field"><label>New Session Charge (₱)</label><input id="dcsNewAmount" type="number" min="0" step="0.01" value="0.00"></div></div></div>
 <div class="modal-footer"><button class="btn-outline" onclick="dcsClosePricing()">Close</button><button class="btn-primary" onclick="dcsAddSession()"><i class="fas fa-plus"></i> Add Session</button><button class="btn-primary" onclick="dcsUpdatePrices()"><i class="fas fa-save"></i> Update Prices</button></div>
 </div></div>

<!-- View Service Modal -->
<div id="viewServiceModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 800px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); border-radius: 18px 18px 0 0; padding: 18px 24px;">
            <h3 id="viewServiceTitle" style="color: white; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-eye" style="color: #0d9e8c;"></i>
                Service Details
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeViewServiceModal()" style="color: rgba(255,255,255,0.6); font-size: 22px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body" style="padding: 24px 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc;">
            <div id="viewServiceContent">
                <div style="text-align: center; padding: 40px 0;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #0d9e8c;"></i>
                    <p style="margin-top: 12px; color: #94a3b8;">Loading service details...</p>
                </div>
            </div>
        </div>
        
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
            <button type="button" id="dcsPriceBtn" class="btn-primary" onclick="dcsOpenPricing(currentViewingServiceId)" style="background:#0b1628"><i class="fas fa-coins"></i> Session Charges</button>
             <button type="button" id="viewAddSessionBtn" class="btn-primary" onclick="openAddSessionFromView()"
                    style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(13,158,140,0.25);">
                <i class="fas fa-plus-circle"></i> Add Session
            </button>
            <button type="button" class="btn-outline" onclick="closeViewServiceModal()" style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white; transition: all 0.2s; cursor: pointer;"
                    onmouseover="this.style.backgroundColor='#f8fafc'; this.style.borderColor='#cbd5e1'" 
                    onmouseout="this.style.backgroundColor='white'; this.style.borderColor='#e2e8f0'">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ===== ADD SESSION MODAL ===== -->
<div id="addSessionModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 440px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); border-radius: 18px 18px 0 0; padding: 18px 24px;">
            <h3 style="color: white; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0;">
                <i class="fas fa-calendar-plus" style="color: #0d9e8c;"></i>
                Add New Session
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeAddSessionModal()" style="color: rgba(255,255,255,0.6); font-size: 22px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 22px 24px; background: #f8fafc;">
            <input type="hidden" id="addSessionServiceId">
            <div style="background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                <div class="form-field">
                    <label for="addSessionDate">Session Date</label>
                    <input type="date" id="addSessionDate">
                </div>
                <div class="form-field">
                    <label for="addSessionStatus">Status</label>
                    <select id="addSessionStatus">
                        <option value="scheduled">Scheduled</option>
                        <option value="in-progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="no-show">No Show</option>
                    </select>
                </div>
                <div class="form-field" style="margin-bottom: 0;">
                    <label for="addSessionNotes">Notes <span style="font-weight: 400; color: #94a3b8; text-transform: none;">(optional)</span></label>
                    <input type="text" id="addSessionNotes" placeholder="Notes for this session...">
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn-outline" onclick="closeAddSessionModal()"
                    style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white; cursor: pointer;">
                Cancel
            </button>
            <button type="button" class="btn-primary" onclick="submitAddSession()"
                    style="padding: 10px 24px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(13,158,140,0.25);">
                <i class="fas fa-check"></i> Add Session
            </button>
        </div>
    </div>
</div>

<script>
// ===== GLOBAL VARIABLES =====
let searchTimeout = null;
let currentPage = 1;
let serviceMode = 'create';
let currentServiceId = null;
let selectedPatientData = null;
let patientSearchTimeout = null;
let sessionData = [];
let selectedAppointmentData = null;
let appointmentSearchTimeout = null;
let appointmentCache = {};

// ===== SMALL HELPERS =====
function esc(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function formatApptDate(dateStr) {
    if (!dateStr) return 'No date';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ===== REAPPLY PROGRESS BAR STYLES =====
function reapplyProgressBarStyles() {
    // Find all progress bars in the table
    document.querySelectorAll('#servicesBody .session-progress').forEach(function(container) {
        // Find the progress bar within this container
        const bar = container.querySelector('.progress-bar');
        const labels = container.querySelector('.progress-labels');
        
        if (bar && labels) {
            // Get the percentage from the progress count
            const pctSpan = labels.querySelector('.progress-pct');
            
            if (pctSpan) {
                // Extract the percentage from the text (e.g., "75%")
                const pctText = pctSpan.textContent.replace('%', '');
                const pct = parseInt(pctText) || 0;
                
                // Remove all existing classes except 'progress-bar'
                bar.className = 'progress-bar';
                
                // Add the appropriate class based on percentage
                if (pct >= 100) {
                    bar.classList.add('done');
                } else if (pct >= 67) {
                    bar.classList.add('high');
                } else if (pct >= 34) {
                    bar.classList.add('mid');
                } else {
                    bar.classList.add('low');
                }
                
                // Ensure width is set correctly
                bar.style.width = pct + '%';
            }
        }
    });
}

// ===== SEARCH FUNCTIONS =====
function liveSearch(page = 1) {
    const searchValue = document.getElementById('searchInput').value.trim();
    const statusValue = document.getElementById('statusFilter').value;
    
    const formData = new FormData();
    formData.append('action', 'live_search');
    formData.append('search', searchValue);
    formData.append('status', statusValue);
    formData.append('page', page);
    
    fetch('patient_services.php?ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const tbody = document.getElementById('servicesBody');
            if (tbody) {
                tbody.innerHTML = data.html;
                
                // IMPORTANT: Reapply progress bar styles after updating HTML
                reapplyProgressBarStyles();
            }
            
            const paginationContainer = document.getElementById('paginationContainer');
            if (paginationContainer) paginationContainer.innerHTML = data.pagination;
            
            const recordCount = document.getElementById('recordCount');
            if (recordCount) recordCount.textContent = data.total + ' records';
            
            const totalCount = document.getElementById('totalCount');
            if (totalCount) totalCount.textContent = data.total;
            
            currentPage = page;
        }
    })
    .catch(error => console.error('Live search error:', error));
}

function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => liveSearch(1), 400);
}

function handleStatusChange() { liveSearch(1); }

function filterByStatus(status) {
    document.getElementById('statusFilter').value = status;
    liveSearch(1);
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    
    if (searchInput) {
        searchInput.addEventListener('input', debouncedSearch);
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimeout);
                liveSearch(1);
            }
        });
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', handleStatusChange);
    }
    
     document.getElementById('serviceModal').addEventListener('click', function(e) {
        if (e.target === this) closeServiceModal();
    });
    
    document.getElementById('viewServiceModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewServiceModal();
    });

    document.getElementById('addSessionModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddSessionModal();
    });
    
     document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeServiceModal();
            closeViewServiceModal();
            closeAddSessionModal();
        }
    });
    
    // Reapply progress bar styles on initial load
    setTimeout(reapplyProgressBarStyles, 100);
});

// ===== TAB SWITCHING =====
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.tab === tab) btn.classList.add('active');
    });
    
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById('tab-' + tab).classList.add('active');
    
    // Clear selections that don't belong to the tab being shown
    if (tab !== 'existing') clearSelectedPatient();
    if (tab !== 'appointment') clearSelectedAppointment();
}

// ===== PATIENT SEARCH =====
function searchPatients(query) {
    const resultsContainer = document.getElementById('patientSearchResults');
    
    if (query.length < 2) {
        resultsContainer.classList.remove('show');
        return;
    }
    
    clearTimeout(patientSearchTimeout);
    patientSearchTimeout = setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'search_patients');
        formData.append('search', query);
        
        fetch('patient_services.php?ajax=1', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                let html = '';
                data.data.forEach(patient => {
                    const sourceLabel = patient.source === 'walkin' ? 'Walk-in' : 'Appointment';
                    const sourceClass = patient.source === 'walkin' ? 'badge-walkin' : 'badge-appointment';
                    html += `
                        <div class="patient-search-item" onclick="selectPatient(${patient.id}, '${patient.full_name}', '${patient.patient_id}', '${patient.phone}', '${patient.source}')">
                            <div class="name">${patient.full_name}</div>
                            <div class="details">
                                #${patient.patient_id} · ${patient.phone} · ${patient.email || 'No email'}
                                <span class="source-badge ${sourceClass}" style="margin-left: 8px; padding: 1px 8px; border-radius: 10px; font-size: 10px;">${sourceLabel}</span>
                            </div>
                        </div>
                    `;
                });
                resultsContainer.innerHTML = html;
                resultsContainer.classList.add('show');
            } else {
                resultsContainer.innerHTML = `
                    <div style="padding: 14px; text-align: center; color: #94a3b8; font-size: 13px;">
                        <i class="fas fa-search" style="display: block; margin-bottom: 6px; font-size: 18px;"></i>
                        No patients found. <button type="button" onclick="switchTab('walkin')" style="color: #0d9e8c; background: none; border: none; cursor: pointer; text-decoration: underline;">Register a walk-in</button> instead.
                    </div>
                `;
                resultsContainer.classList.add('show');
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            resultsContainer.innerHTML = `<div style="padding: 14px; text-align: center; color: #e74c3c; font-size: 13px;">Error searching patients</div>`;
            resultsContainer.classList.add('show');
        });
    }, 300);
}

function selectPatient(id, name, code, phone, source) {
    selectedPatientData = { id, name, code, phone, source };
    document.getElementById('selectedPatientId').value = id;
    document.getElementById('patientSearch').value = name;
    document.getElementById('selectedPatientName').textContent = name;
    document.getElementById('selectedPatientCode').textContent = '#' + code;
    document.getElementById('selectedPatientPhone').textContent = phone || 'No phone';
    document.getElementById('selectedPatientSource').textContent = source === 'walkin' ? 'Walk-in' : 'Appointment';
    document.getElementById('selectedPatientDisplay').style.display = 'flex';
    document.getElementById('patientSearchResults').classList.remove('show');
}

function clearSelectedPatient() {
    selectedPatientData = null;
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('patientSearch').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
}

// ===== APPOINTMENT SEARCH =====
function searchAppointments(query) {
    const resultsContainer = document.getElementById('appointmentSearchResults');
    query = (query || '').trim();

    if (query.length < 2) {
        resultsContainer.classList.remove('show');
        return;
    }

    clearTimeout(appointmentSearchTimeout);
    appointmentSearchTimeout = setTimeout(() => {
        resultsContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: #94a3b8; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
        resultsContainer.classList.add('show');

        const formData = new FormData();
        formData.append('action', 'search_appointments');
        formData.append('search', query);

        fetch('patient_services.php?ajax=1', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                resultsContainer.innerHTML = `<div style="padding: 14px; text-align: center; color: #e74c3c; font-size: 13px;">${esc(data.message || 'Search failed')}</div>`;
                return;
            }

            if (data.data.length === 0) {
                resultsContainer.innerHTML = `
                    <div style="padding: 14px; text-align: center; color: #94a3b8; font-size: 13px;">
                        <i class="fas fa-calendar-times" style="display: block; margin-bottom: 6px; font-size: 18px;"></i>
                        No approved, confirmed or completed appointments found.
                    </div>`;
                return;
            }

            appointmentCache = {};
            resultsContainer.innerHTML = data.data.map(a => {
                appointmentCache[a.id] = a;
                const svcText = a.service_list && a.service_list.length
                    ? a.service_list.map(s => esc(s.title)).join(', ')
                    : 'No services listed';
                const added = parseInt(a.added_count) > 0
                    ? '<span class="mini-badge added">Already added</span>' : '';
                return `
                    <div class="patient-search-item" onclick="selectAppointment(${a.id})">
                        <div class="name">${esc(a.full_name)}
                            <span class="mini-badge ok" style="background:#e3f2fd;color:#1565c0;">${esc(a.status)}</span>${added}
                        </div>
                        <div class="details">
                            ${esc(formatApptDate(a.appointment_date))} ${esc(a.appointment_time || '')} · ${esc(a.phone || 'No phone')}
                        </div>
                        <div class="details"><i class="fas fa-tooth"></i> ${svcText}</div>
                    </div>`;
            }).join('');
        })
        .catch(error => {
            console.error('Appointment search error:', error);
            resultsContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: #e74c3c; font-size: 13px;">Search failed. Please try again.</div>';
        });
    }, 300);
}

function selectAppointment(id) {
    const a = appointmentCache[id];
    if (!a) return;

    selectedAppointmentData = a;
    document.getElementById('selectedAppointmentId').value = a.id;
    document.getElementById('appointmentSearch').value = a.full_name || '';
    document.getElementById('appointmentSearchResults').classList.remove('show');

    // Summary card
    document.getElementById('selectedApptName').textContent = a.full_name || '—';
    document.getElementById('selectedApptDetails').textContent =
        `${formatApptDate(a.appointment_date)} ${a.appointment_time || ''} · ${a.phone || 'No phone'} · ${ucfirst(a.status || '')}`;

    const note = document.getElementById('selectedApptPatientNote');
    if (a.matched_patient) {
        note.innerHTML = `<span class="mini-badge ok" style="margin-left:0;"><i class="fas fa-check"></i> Existing patient record #${esc(a.matched_patient.patient_id)} will be used</span>`;
    } else {
        note.innerHTML = '<span class="mini-badge new" style="margin-left:0;"><i class="fas fa-user-plus"></i> No patient record found - one will be created on save</span>';
    }
    if (parseInt(a.added_count) > 0) {
        note.innerHTML += ' <span class="mini-badge added">A service was already added from this appointment</span>';
    }

    document.getElementById('selectedAppointmentDisplay').style.display = 'flex';

    // Prefill dentist
    if (a.dentist_id) {
        document.getElementById('dentistSelect').value = a.dentist_id;
    }

    // Service chips + prefill first service
    const wrap = document.getElementById('selectedApptServicesWrap');
    const chips = document.getElementById('selectedApptServices');
    const list = a.service_list || [];
    if (list.length > 0) {
        chips.innerHTML = list.map(s =>
            `<button type="button" class="appt-service-chip" data-service-id="${s.id}" onclick="applyAppointmentService(${s.id})">${esc(s.title)}</button>`
        ).join('');
        wrap.style.display = 'block';
        applyAppointmentService(list[0].id);
    } else {
        chips.innerHTML = '';
        wrap.style.display = 'none';
    }

    // Prefill a single session on the appointment date
    sessionData = [{
        number: 1,
        date: a.appointment_date || '',
        status: a.status === 'completed' ? 'completed' : 'scheduled',
        notes: ''
    }];
    document.getElementById('totalSessions').value = 1;
    updateSessionDates();
}

function applyAppointmentService(serviceId) {
    document.getElementById('serviceSelect').value = serviceId;
    document.querySelectorAll('#selectedApptServices .appt-service-chip').forEach(chip => {
        chip.classList.toggle('active', String(chip.dataset.serviceId) === String(serviceId));
    });
}

function clearSelectedAppointment() {
    selectedAppointmentData = null;
    document.getElementById('selectedAppointmentId').value = '';
    document.getElementById('appointmentSearch').value = '';
    document.getElementById('selectedAppointmentDisplay').style.display = 'none';
    document.getElementById('selectedApptServices').innerHTML = '';
    document.getElementById('selectedApptServicesWrap').style.display = 'none';
    const r = document.getElementById('appointmentSearchResults');
    r.classList.remove('show');
    r.innerHTML = '';
}

function resolveAppointmentPatient(appointmentId) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'resolve_appointment_patient');
        formData.append('appointment_id', appointmentId);

        fetch('patient_services.php?ajax=1', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) resolve(data);
            else reject(data.message || 'Failed to prepare patient');
        })
        .catch(error => reject('An unexpected error occurred: ' + error));
    });
}

// ===== SESSION MANAGEMENT =====
function updateSessionDates() {
    const totalSessions = parseInt(document.getElementById('totalSessions').value) || 0;
    const sessionList = document.getElementById('sessionList');
    const sessionCountLabel = document.getElementById('sessionCountLabel');
    
    sessionCountLabel.textContent = totalSessions + ' sessions';
    
    if (totalSessions <= 0) {
        sessionList.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 13px;">
                <i class="fas fa-calendar-plus" style="display: block; font-size: 24px; margin-bottom: 8px; color: #d0d7e2;"></i>
                Set the number of sessions above to schedule dates
            </div>
        `;
        return;
    }
    
    let html = '';
    const today = new Date().toISOString().split('T')[0];
    
    // Use existing session data if available, otherwise create new
    for (let i = 1; i <= totalSessions; i++) {
        const existing = sessionData.find(s => s.number === i);
        const dateValue = existing ? existing.date : '';
        const statusValue = existing ? existing.status : 'scheduled';
        const notesValue = existing ? existing.notes : '';
        // Allow past dates that were prefilled (e.g. a completed appointment)
        const minDate = (dateValue && dateValue < today) ? dateValue : today;
        
        html += `
            <div class="session-item">
                <div class="session-header">
                    <div class="session-number">Session ${i}</div>
                    <div class="session-controls">
                        <input type="date" class="session-date" data-session="${i}" value="${dateValue}" min="${minDate}">
                        <select class="session-status" data-session="${i}">
                            <option value="scheduled" ${statusValue === 'scheduled' ? 'selected' : ''}>Scheduled</option>
                            <option value="in-progress" ${statusValue === 'in-progress' ? 'selected' : ''}>In Progress</option>
                            <option value="completed" ${statusValue === 'completed' ? 'selected' : ''}>Completed</option>
                            <option value="cancelled" ${statusValue === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                            <option value="no-show" ${statusValue === 'no-show' ? 'selected' : ''}>No Show</option>
                        </select>
                    </div>
                </div>
                <div class="session-notes">
                    <input type="text" class="session-notes-input" data-session="${i}" placeholder="Notes for this session..." value="${notesValue}">
                </div>
            </div>
        `;
    }
    
    sessionList.innerHTML = html;
}

function getSessionData() {
    const sessionItems = document.querySelectorAll('.session-item');
    const sessions = [];
    sessionItems.forEach((item, index) => {
        const dateInput = item.querySelector('.session-date');
        const statusSelect = item.querySelector('.session-status');
        const notesInput = item.querySelector('.session-notes-input');
        sessions.push({
            number: index + 1,
            date: dateInput ? dateInput.value : '',
            status: statusSelect ? statusSelect.value : 'scheduled',
            notes: notesInput ? notesInput.value : ''
        });
    });
    return sessions;
}

// ===== CREATE WALK-IN PATIENT =====
function createWalkinPatient(fullName, phone, email, age, gender, address) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'create_walkin_patient');
        formData.append('full_name', fullName);
        formData.append('phone', phone);
        formData.append('email', email);
        formData.append('age', age);
        formData.append('gender', gender);
        formData.append('address', address);
        
        fetch('patient_services.php?ajax=1', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                resolve(data);
            } else {
                reject(data.message || 'Failed to create patient');
            }
        })
        .catch(error => {
            reject('An unexpected error occurred: ' + error);
        });
    });
}

// ===== MODAL FUNCTIONS =====
function openServiceModal() {
    document.getElementById('serviceModal').classList.add('open');
}

function closeServiceModal() {
    document.getElementById('serviceModal').classList.remove('open');
    document.getElementById('serviceForm').reset();
    document.getElementById('serviceId').value = '';
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
    selectedPatientData = null;
    document.getElementById('patientSearch').value = '';
    clearSelectedAppointment();
    document.getElementById('serviceSaveBtn').style.display = 'inline-flex';
    document.getElementById('serviceViewMeta').classList.add('hidden');
    serviceMode = 'create';
    sessionData = [];
    // Reset tabs to existing
    switchTab('existing');
}

function closeViewServiceModal() {
    document.getElementById('viewServiceModal').classList.remove('open');
}

// ===== ADD SESSION FROM VIEW MODAL =====
let currentViewingServiceId = null;

function openAddSessionFromView() {
    if (!currentViewingServiceId) {
        Swal.fire({ title: 'Error!', text: 'No service loaded.', icon: 'error' });
        return;
    }
    document.getElementById('addSessionServiceId').value = currentViewingServiceId;
    document.getElementById('addSessionDate').value = '';
    document.getElementById('addSessionStatus').value = 'scheduled';
    document.getElementById('addSessionNotes').value = '';
    document.getElementById('addSessionModal').classList.add('open');
}

function closeAddSessionModal() {
    document.getElementById('addSessionModal').classList.remove('open');
}

function submitAddSession() {
    const serviceId = document.getElementById('addSessionServiceId').value;
    const date = document.getElementById('addSessionDate').value;
    const status = document.getElementById('addSessionStatus').value;
    const notes = document.getElementById('addSessionNotes').value.trim();

    if (!serviceId) {
        Swal.fire({ title: 'Error!', text: 'Missing service ID.', icon: 'error' });
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_session');
    formData.append('patient_service_id', serviceId);
    formData.append('session_date', date);
    formData.append('session_status', status);
    formData.append('session_notes', notes);

    Swal.fire({
        title: 'Adding Session...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('patient_services.php?ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeAddSessionModal();

            // Refresh the view modal so the new session appears
            // Close it first, then reopen to reload fresh data
            closeViewServiceModal();
            setTimeout(() => {
                viewService(currentViewingServiceId);
            }, 250);

            // Refresh the main table in the background
            liveSearch(currentPage);

            Swal.fire({
                title: 'Added!',
                text: data.message,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        } else {
            Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' });
    });
}

function showCreateModal() {
    serviceMode = 'create';
    document.getElementById('serviceModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: #0d9e8c;"></i> New Patient Service';
    document.getElementById('serviceId').value = '';
    document.getElementById('serviceSelect').value = '';
    document.getElementById('dentistSelect').value = '';
    document.getElementById('totalSessions').value = '1';
    document.getElementById('serviceStatus').value = 'pending';
    document.getElementById('serviceNotes').value = '';
    document.getElementById('serviceSaveBtn').innerHTML = '<i class="fas fa-save"></i> Add Service';
    document.getElementById('serviceSaveBtn').style.display = 'inline-flex';
    document.getElementById('serviceViewMeta').classList.add('hidden');
    clearSelectedPatient();
    clearSelectedAppointment();
    sessionData = [];
    // Reset walk-in form
    document.getElementById('walkinFullName').value = '';
    document.getElementById('walkinPhone').value = '';
    document.getElementById('walkinEmail').value = '';
    document.getElementById('walkinAge').value = '';
    document.getElementById('walkinGender').value = '';
    document.getElementById('walkinAddress').value = '';
    switchTab('existing');
    openServiceModal();
    updateSessionDates();
}

function viewService(id) {
    currentViewingServiceId = id;
    fetchService(id, (res) => {
        if (!res.success) {
            Swal.fire({ title: 'Error!', text: res.message, icon: 'error' });
            return;
        }
        const data = res.data;
        document.getElementById('viewAddSessionBtn').style.display = data.agreed_total !== null ? 'none' : 'inline-flex';
        document.getElementById('dcsPriceBtn').style.display = data.agreed_total !== null ? 'inline-flex' : 'none';
        document.getElementById('viewServiceTitle').innerHTML = `<i class="fas fa-eye" style="color: #0d9e8c;"></i> Service #${data.id}`; 
        let sessionsHtml = '';
        if (data.sessions && data.sessions.length > 0) {
            sessionsHtml = '<div style="margin-top: 12px;">';
            data.sessions.forEach(session => {
                const statusClass = session.status === 'completed' ? 'status-completed' : 
                                   session.status === 'cancelled' ? 'status-cancelled' :
                                   session.status === 'in-progress' ? 'status-in-progress' : 'status-pending';
                const dateDisplay = session.date ? new Date(session.date).toLocaleDateString() : 'Not scheduled';
                sessionsHtml += `
                    <div style="background: white; border-radius: 8px; padding: 10px 14px; margin-bottom: 6px; border: 1px solid #eef2f6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span style="font-weight: 600; color: #0b1628;">Session ${session.session_number}</span>
                            <span style="margin-left: 12px; font-size: 12px; color: #94a3b8;"><i class="far fa-calendar-alt"></i> ${dateDisplay}</span>
                        </div>
                        <div>
                            <span class="status-badge ${statusClass}">${ucfirst(session.status)}</span>
                            ${session.notes ? `<span style="margin-left: 8px; font-size: 11px; color: #94a3b8;"><i class="fas fa-sticky-note"></i> ${session.notes}</span>` : ''}
                        </div>
                    </div>
                `;
            });
            sessionsHtml += '</div>';
        } else {
            sessionsHtml = '<p style="color:#94a3b8; font-size:13px;">No sessions created yet.</p>';
        }
        
        document.getElementById('viewServiceContent').innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div style="background:white; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:8px;">Patient</div>
                    <div style="font-weight:600; color:#0b1628;">${data.patient_name}</div>
                    <div style="font-size:12px; color:#94a3b8;">${data.patient_code}</div>
                    <div style="font-size:12px; color:#94a3b8;">${data.phone || 'No phone'}</div>
                    <span class="badge ${data.source === 'walkin' ? 'badge-walkin' : 'badge-appointment'}" style="font-size:10px; padding:2px 10px; border-radius:12px; margin-top:4px;">
                        ${ucfirst(data.source || 'N/A')}
                    </span>
                </div>
                <div style="background:white; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:8px;">Service</div>
                    <div style="font-weight:600; color:#0b1628;">${data.service_title}</div>
                    <div style="font-size:12px; color:#94a3b8;">$${Number(data.price).toFixed(2)} · ${data.duration}</div>
                </div>
                <div style="background:white; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:8px;">Dentist</div>
                    <div style="font-weight:600; color:#0b1628;">Dr. ${data.dentist_first} ${data.dentist_last}</div>
                </div>
                <div style="background:white; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:8px;">Status</div>
                    <span class="status-badge status-${data.status.replace('-', '')}">${ucfirst(data.status)}</span>
                    <div style="font-size:12px; color:#94a3b8; margin-top:4px;">${data.completed_sessions_count || 0}/${data.total_sessions} sessions completed</div>
                </div>
            </div>
            <div style="margin-top:16px; background:white; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:8px;">Sessions</div>
                ${sessionsHtml}
                ${data.notes ? `<div style="margin-top:12px; padding-top:12px; border-top:1px solid #eef2f6;"><div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px;">Notes</div><div style="font-size:13px; color:#0b1628;">${data.notes}</div></div>` : ''}
                <div style="margin-top:12px; padding-top:12px; border-top:1px solid #eef2f6; font-size:12px; color:#94a3b8;">
                    <div>Created: ${new Date(data.created_at).toLocaleString()}</div>
                    ${data.updated_at ? `<div>Updated: ${new Date(data.updated_at).toLocaleString()}</div>` : ''}
                </div>
            </div>
        `;
        openViewServiceModal();
    });
}

function openViewServiceModal() {
    document.getElementById('viewServiceModal').classList.add('open');
}

function editService(id) {
    serviceMode = 'edit';
    currentServiceId = id;
    document.getElementById('serviceModalTitle').innerHTML = '<i class="fas fa-pen" style="color: #0d9e8c;"></i> Edit Service';
    document.getElementById('serviceSaveBtn').innerHTML = '<i class="fas fa-save"></i> Update Service';
    document.getElementById('serviceSaveBtn').style.display = 'inline-flex';
    document.getElementById('serviceViewMeta').classList.add('hidden');
    
    fetchService(id, (res) => {
        if (!res.success) {
            Swal.fire({ title: 'Error!', text: res.message, icon: 'error' });
            return;
        }
        const data = res.data;
        document.getElementById('serviceId').value = data.id;
        document.getElementById('selectedPatientId').value = data.patient_id;
        document.getElementById('patientSearch').value = data.patient_name;
        document.getElementById('selectedPatientName').textContent = data.patient_name;
        document.getElementById('selectedPatientCode').textContent = '#' + data.patient_code;
        document.getElementById('selectedPatientPhone').textContent = data.phone || 'No phone';
        document.getElementById('selectedPatientSource').textContent = data.source === 'walkin' ? 'Walk-in' : 'Appointment';
        document.getElementById('selectedPatientDisplay').style.display = 'flex';
        document.getElementById('serviceSelect').value = data.service_id;
        document.getElementById('dentistSelect').value = data.dentist_id;
        document.getElementById('totalSessions').value = data.total_sessions;
        document.getElementById('serviceStatus').value = data.status;
        document.getElementById('serviceNotes').value = data.notes || '';
        selectedPatientData = { 
            id: data.patient_id, 
            name: data.patient_name,
            code: data.patient_code,
            phone: data.phone,
            source: data.source
        };
        
        // Load session data
        sessionData = [];
        if (data.sessions) {
            data.sessions.forEach(session => {
                sessionData.push({
                    number: session.session_number,
                    date: session.date || '',
                    status: session.status || 'scheduled',
                    notes: session.notes || ''
                });
            });
        }
        
        // Switch to existing tab
        switchTab('existing');
        openServiceModal();
        updateSessionDates();
    });
}

function fetchService(id, callback) {
    const formData = new FormData();
    formData.append('action', 'get');
    formData.append('id', id);
    
    fetch('patient_services.php?ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => callback(data))
    .catch(() => Swal.fire({ title: 'Error!', text: 'Could not load service.', icon: 'error' }));
}

function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function saveService() {
    const id = document.getElementById('serviceId').value;
    const service_id = document.getElementById('serviceSelect').value;
    const dentist_id = document.getElementById('dentistSelect').value;
    const total_sessions = parseInt(document.getElementById('totalSessions').value) || 0;
    const status = document.getElementById('serviceStatus').value;
    const notes = document.getElementById('serviceNotes').value;
    
    // Check which tab is active
    const activeTab = document.querySelector('.tab-btn.active');
    const tab = activeTab ? activeTab.dataset.tab : 'existing';
    
    let patient_id = document.getElementById('selectedPatientId').value;

    // Validate shared fields BEFORE creating any patient record
    if (!service_id) {
        Swal.fire({ title: 'Missing Info', text: 'Please select a service.', icon: 'warning' });
        return;
    }
    if (!dentist_id) {
        Swal.fire({ title: 'Missing Info', text: 'Please select a dentist.', icon: 'warning' });
        return;
    }
    if (!total_sessions || total_sessions < 1) {
        Swal.fire({ title: 'Invalid Input', text: 'Please enter a valid number of sessions.', icon: 'warning' });
        return;
    }
    
    // If walk-in tab, create patient first
    if (tab === 'walkin') {
        const fullName = document.getElementById('walkinFullName').value.trim();
        const phone = document.getElementById('walkinPhone').value.trim();
        const email = document.getElementById('walkinEmail').value.trim();
        const age = document.getElementById('walkinAge').value.trim();
        const gender = document.getElementById('walkinGender').value;
        const address = document.getElementById('walkinAddress').value.trim();
        
        if (!fullName || !phone) {
            Swal.fire({ title: 'Missing Info', text: 'Please fill in full name and phone number for the walk-in patient.', icon: 'warning' });
            return;
        }
        
        // Create walk-in patient using the new function
        Swal.fire({
            title: 'Creating Patient...',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });
        
        createWalkinPatient(fullName, phone, email, age, gender, address)
            .then(data => {
                patient_id = data.id;
                selectedPatientData = { 
                    id: data.id, 
                    name: fullName,
                    code: data.patient_id,
                    phone: phone,
                    source: 'walkin'
                };
                document.getElementById('selectedPatientId').value = patient_id;
                Swal.close();
                // Continue with service creation
                saveServiceData(id, patient_id, service_id, dentist_id, total_sessions, status, notes);
            })
            .catch(error => {
                Swal.fire({ title: 'Error!', text: error, icon: 'error' });
            });
        return;
    }

    // If appointment tab, find (or create) the patient for that appointment first
    if (tab === 'appointment') {
        if (!selectedAppointmentData) {
            Swal.fire({ title: 'Missing Info', text: 'Please search and select an appointment.', icon: 'warning' });
            return;
        }

        const appointmentId = selectedAppointmentData.id;

        Swal.fire({
            title: 'Preparing patient...',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        resolveAppointmentPatient(appointmentId)
            .then(data => {
                Swal.close();
                saveServiceData(id, data.id, service_id, dentist_id, total_sessions, status, notes, appointmentId);
            })
            .catch(error => {
                Swal.fire({ title: 'Error!', text: error, icon: 'error' });
            });
        return;
    }
    
    if (!patient_id) {
        Swal.fire({ title: 'Missing Info', text: 'Please select a patient, pick an appointment, or register a walk-in.', icon: 'warning' });
        return;
    }
    
    saveServiceData(id, patient_id, service_id, dentist_id, total_sessions, status, notes);
}

function saveServiceData(id, patient_id, service_id, dentist_id, total_sessions, status, notes, appointment_id = null) {
    if (!service_id) {
        Swal.fire({ title: 'Missing Info', text: 'Please select a service.', icon: 'warning' });
        return;
    }
    if (!dentist_id) {
        Swal.fire({ title: 'Missing Info', text: 'Please select a dentist.', icon: 'warning' });
        return;
    }
    if (!total_sessions || total_sessions < 1) {
        Swal.fire({ title: 'Invalid Input', text: 'Please enter a valid number of sessions.', icon: 'warning' });
        return;
    }
    
    // Get full session data
    const sessions = getSessionData();
    const sessionDates = sessions.map(s => s.date);
    const sessionStatuses = sessions.map(s => s.status);
    const sessionNotesArr = sessions.map(s => s.notes);
    
    const formData = new FormData();
    formData.append('action', id ? 'update_service' : 'add_service');
    formData.append('json', '1');
    if (id) formData.append('id', id);
    formData.append('patient_id', patient_id);
    formData.append('service_id', service_id);
    formData.append('dentist_id', dentist_id);
    formData.append('total_sessions', total_sessions);
    formData.append('status', status);
    formData.append('notes', notes);
    if (!id && appointment_id) formData.append('appointment_id', appointment_id);
    
    // Send session data
    sessionDates.forEach((date, index) => {
        formData.append('session_dates[]', date);
        formData.append('session_statuses[]', sessionStatuses[index]);
        formData.append('session_notes[]', sessionNotesArr[index]);
    });

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('patient_services.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeServiceModal();
            Swal.fire({
                title: 'Success!',
                text: data.message,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                liveSearch(currentPage);
                // Reapply progress bar styles after live search
                setTimeout(reapplyProgressBarStyles, 100);
            });
        } else {
            Swal.fire({ title: 'Error!', text: data.message || 'Something went wrong.', icon: 'error' });
        }
    })
    .catch(() => {
        Swal.fire({ title: 'Error!', text: 'Unexpected response from the server.', icon: 'error' });
    });
}

function deleteService(id) {
    Swal.fire({
        title: 'Delete Service',
        text: 'Are you sure you want to delete this service? All sessions will also be deleted!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                text: 'Please wait',
                icon: 'info',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('patient_services.php?ajax=1', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const row = document.getElementById('row-' + id);
                    if (row) {
                        row.style.transition = 'all 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => {
                            row.remove();
                            liveSearch(currentPage);
                            // Reapply progress bar styles after live search
                            setTimeout(reapplyProgressBarStyles, 100);
                        }, 300);
                    }
                    Swal.fire({
                        title: 'Deleted!',
                        text: data.message,
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    title: 'Error!',
                    text: 'An unexpected error occurred.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            });
        }
    });
}

function exportData() {
    Swal.fire({
        title: 'Export Data',
        text: 'Preparing export file...',
        icon: 'info',
        timer: 1500,
        showConfirmButton: false
    });
    setTimeout(() => {
        window.location.href = 'services_export.php';
    }, 1500);
}

// Connected Sessions: separate, validated workflow sharing the same patient_services and billing tables.
const dcsCsrf = <?= json_encode(dcs_csrf()) ?>;
let dcsChosen = null, dcsAppts = [], dcsPatients = [], dcsSearchTimer = null, dcsCurrentPs = 0;
const dcsById = id => document.getElementById(id);
function openConnectedModal(){dcsChosen=null;dcsById('dcsLookup').value='';dcsById('dcsSelection').textContent='';dcsById('dcsResults').innerHTML='';dcsById('dcsSource').value='appointment';dcsById('dcsSplit').checked=false;dcsById('dcsNumber').value='1';dcsById('dcsTotal').value='0.00';dcsById('dcsService').value='';dcsById('dcsDentist').value='';dcsById('dcsMessage').textContent='';dcsSwitchSource();dcsById('connectedModal').classList.add('open');}
function closeConnectedModal(){dcsById('connectedModal').classList.remove('open');}
function dcsSwitchSource(){dcsChosen=null;dcsById('dcsResults').innerHTML='';dcsById('dcsSelection').textContent='';dcsById('dcsLookup').value='';dcsById('dcsService').disabled=false;dcsBuildSessions(false);}
function dcsSearch(){clearTimeout(dcsSearchTimer);const q=dcsById('dcsLookup').value.trim();if(q.length<2){dcsById('dcsResults').innerHTML='';return;}dcsSearchTimer=setTimeout(async()=>{const fd=new FormData();fd.append('action','connected_lookup');fd.append('q',q);try{const res=await fetch('patient_services.php?ajax=1',{method:'POST',body:fd});const obj=await res.json();if(!obj.success)throw Error(obj.message);dcsAppts=obj.appointments;dcsPatients=obj.patients;const source=dcsById('dcsSource').value;const entries=source==='appointment'?dcsAppts:dcsPatients;dcsById('dcsResults').innerHTML=entries.map((item,i)=>`<button type="button" onclick="dcsPick(${i})" style="display:block;text-align:left;padding:8px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0">${esc(item.full_name)} ${source==='appointment'?'— Appointment #'+item.id+' ('+esc(item.appointment_date)+')':''}</button>`).join('')||'<p>No matches found</p>';}catch(e){dcsById('dcsMessage').textContent='Search unavailable: '+e.message;}},300);}
function dcsPick(i){const appt=dcsById('dcsSource').value==='appointment';dcsChosen=(appt?dcsAppts:dcsPatients)[i];if(!dcsChosen)return;dcsById('dcsResults').innerHTML='';dcsById('dcsSelection').textContent=(appt?'Completed appointment #'+dcsChosen.id:'Existing patient #'+dcsChosen.id)+' — '+dcsChosen.full_name;if(appt){dcsById('dcsDentist').value=dcsChosen.dentist_id||'';const allowed=new Set((dcsChosen.procedures||[]).map(p=>String(p.service_id)));Array.from(dcsById('dcsService').options).forEach(opt=>{opt.disabled=opt.value!==''&&!allowed.has(opt.value)});const first=dcsChosen.procedures?.[0];if(first){dcsById('dcsService').value=first.service_id;dcsById('dcsTotal').value=first.charge;}}else{Array.from(dcsById('dcsService').options).forEach(o=>o.disabled=false);dcsById('dcsService').value='';dcsById('dcsTotal').value='0.00';}dcsBuildSessions(false);}
function dcsChooseService(){const sel=dcsById('dcsService');if(!dcsChosen)return;const proc=dcsById('dcsSource').value==='appointment'?(dcsChosen.procedures||[]).find(p=>String(p.service_id)===sel.value):null;dcsById('dcsTotal').value=proc?proc.charge:sel.selectedOptions[0]?.dataset.price||'0.00';dcsBuildSessions(false);}
function dcsBuildSessions(preserve){const n=Math.min(60,Math.max(1,parseInt(dcsById('dcsNumber').value)||1));const split=dcsById('dcsSplit').checked;const total=Math.round((parseFloat(dcsById('dcsTotal').value)||0)*100);const amounts=Array.from({length:n},(_,i)=>split?Math.floor(total/n)+(i<total%n?1:0):(i===0?total:0));dcsById('dcsSessionPreview').innerHTML=amounts.map((c,i)=>`<div class="form-row" style="align-items:center;border-bottom:1px solid #e2e8f0;padding:8px 0"><div><b>Session ${i+1}</b>${i===0&&dcsById('dcsSource').value==='appointment'?'<span class="mini-badge ok">From completed appointment</span>':''}</div><div class="form-field" style="margin:0"><label>Session Charge (₱)</label><input class="dcsAmount" type="number" min="0" step="0.01" value="${(c/100).toFixed(2)}" ${!split?'readonly':''}></div></div>`).join('');}
async function dcsRequest(fd){fd.append('csrf',dcsCsrf);const res=await fetch('patient_services.php?ajax=1',{method:'POST',body:fd});const data=await res.json();if(!data.success)throw Error(data.message||'Unable to save');return data;}
async function dcsSave(){if(!dcsChosen||!dcsById('dcsService').value||!dcsById('dcsDentist').value){dcsById('dcsMessage').textContent='Choose a patient/appointment, service, and dentist.';return;}const btn=dcsById('dcsSubmit');btn.disabled=true;try{const fd=new FormData();fd.append('action','connected_create');fd.append('appointment_id',dcsById('dcsSource').value==='appointment'?dcsChosen.id:'');fd.append('patient_id',dcsById('dcsSource').value==='patient'?dcsChosen.id:'');fd.append('service_id',dcsById('dcsService').value);fd.append('dentist_id',dcsById('dcsDentist').value);fd.append('sessions',dcsById('dcsNumber').value);fd.append('total',dcsById('dcsTotal').value);fd.append('split',dcsById('dcsSplit').checked?'1':'0');fd.append('amounts',JSON.stringify([...document.querySelectorAll('.dcsAmount')].map(e=>e.value)));const data=await dcsRequest(fd);closeConnectedModal();await Swal.fire('Connected',`Service #${data.patient_service_id} linked to invoice ${data.billing.code}`,'success');location.reload();}catch(e){dcsById('dcsMessage').textContent=e.message;}finally{btn.disabled=false;}}
function dcsClosePricing(){dcsById('dcsPricingModal').classList.remove('open');}
async function dcsOpenPricing(id){if(!id)return;const fd=new FormData();fd.append('action','get');fd.append('id',id);try{const res=await fetch('patient_services.php?ajax=1',{method:'POST',body:fd});const body=await res.json();if(!body.success)throw Error(body.message);if(body.data.agreed_total===null)throw Error('Legacy treatment pricing must be reconciled before using this editor.');dcsCurrentPs=id;dcsById('dcsPricingList').innerHTML=(body.data.sessions||[]).map(s=>`<div style="padding:10px 0;border-bottom:1px solid #e2e8f0"><b>Session ${s.session_number}</b> — ${esc(s.status)} ${s.appointment_id?'· Appointment #'+s.appointment_id:''}<label style="display:block;font-size:11px;color:#64748b">Charge (₱)</label><input type="number" class="dcsEditCharge" min="0" step="0.01" value="${esc(s.session_charge??'0.00')}" ${Number(s.charge_locked)?'readonly title="Completed appointment session is locked"':''} style="padding:7px 10px;width:100%;border:1px solid #ddd;border-radius:7px"></div>`).join('');dcsById('dcsPricingWarning').textContent='Changes sync with Billing only if the invoice has no recorded payments or protected discounts. Completed appointment charges cannot be changed here.';dcsById('dcsPricingModal').classList.add('open');}catch(e){Swal.fire('Cannot open',e.message,'warning');}}
async function dcsUpdatePrices(){try{const fd=new FormData();fd.append('action','connected_update_charges');fd.append('patient_service_id',dcsCurrentPs);fd.append('amounts',JSON.stringify([...document.querySelectorAll('.dcsEditCharge')].map(x=>x.value)));const d=await dcsRequest(fd);dcsClosePricing();Swal.fire('Updated',`Invoice ${d.billing.code} synchronized.`,'success');liveSearch(currentPage);}catch(e){Swal.fire('Cannot update',e.message,'error');}}
async function dcsAddSession(){try{const fd=new FormData();fd.append('action','connected_add_session');fd.append('patient_service_id',dcsCurrentPs);fd.append('date',dcsById('dcsNewDate').value);fd.append('charge',dcsById('dcsNewAmount').value);const d=await dcsRequest(fd);await dcsOpenPricing(dcsCurrentPs);Swal.fire('Added',`Invoice ${d.billing.code} synchronized.`,'success');liveSearch(currentPage);}catch(e){Swal.fire('Cannot add session',e.message,'error');}}

</script>

<?php
$conn->close();
?>
</body>
</html>