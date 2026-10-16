<?php
require '../../conn/conn.php';
require_once '../../functions/confirmed_patient_link.php';
require '../../functions/appointment_email.php';
require '../../functions/audit_log.php';
require '../../functions/bulk_email_appointment.php';
require_once '../../functions/appointment_patient_search.php';
// Set timezone
date_default_timezone_set('Asia/Manila');
// Make database NOW() timestamps match clinic-local Philippines time on this connection.
$conn->query("SET time_zone = '+08:00'");
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// This file previously had no access guard. Patient autocomplete must never be public.
try { $staffAuthorized = aps_staff_authorized($conn); }
catch (Throwable $e) { error_log('Appointments auth check failed: ' . $e->getMessage()); $staffAuthorized = false; }
if (!$staffAuthorized) {
    http_response_code(403);
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Authorized staff access required.']);
    } else { echo 'Authorized staff access required.'; }
    exit;
}
if (empty($_SESSION['staff_appointments_csrf'])) {
    $_SESSION['staff_appointments_csrf'] = bin2hex(random_bytes(32));
}
$staffAppointmentsCsrf = $_SESSION['staff_appointments_csrf'];

if (isset($_SESSION['staff_id'])) {
    $stmt = $conn->prepare("UPDATE users SET seen_appointments_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['staff_id']);
    $stmt->execute();
    $stmt->close();
}
// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $verified = isset($_POST['verified']) ? (int)$_POST['verified'] : 0;

    // Every mutating AJAX action requires the authenticated staff session CSRF token.
    $readonlyActions = ['live_search', 'bulk_list', 'get', 'search_patients', 'get_patient', 'address_suggestions'];
    if (!in_array($action, $readonlyActions, true)
        && !hash_equals($staffAppointmentsCsrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
        exit;
    }

    // Staff-only, read-only autocomplete. No medical history is ever returned.
    if ($action === 'search_patients' || $action === 'get_patient') {
        try {
            if ($action === 'search_patients') {
                $patients = aps_search_patients($conn, (string)($_POST['q'] ?? ''));
                echo json_encode(['success' => true, 'patients' => $patients], JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $patient = aps_get_patient($conn, (int)($_POST['patient_id'] ?? 0));
                if (!$patient) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Patient not found.']); }
                else echo json_encode(['success' => true, 'patient' => $patient], JSON_INVALID_UTF8_SUBSTITUTE);
            }
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Staff patient search failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not search patients. Please try again.']);
        }
        exit;
    }


    // Historical address choices are suggestions only: new entries stay editable.
    // These come from the same appointment booking columns used by the public flow.
    if ($action === 'address_suggestions') {
        try {
            $suggestions = [];
            foreach (['region', 'province', 'city', 'barangay'] as $field) {
                $sql = "SELECT DISTINCT `$field` AS value FROM appointments WHERE `$field` IS NOT NULL AND TRIM(`$field`) <> '' AND `$field` <> '—' ORDER BY `$field` ASC LIMIT 150";
                $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
                $suggestions[$field] = array_column($rows, 'value');
            }
            echo json_encode(['success' => true, 'suggestions' => $suggestions], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('Address suggestions failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Address suggestions unavailable. You can type an address manually.']);
        }
        exit;
    }

    // ===== LIVE SEARCH HANDLER =====
    if ($action === 'live_search') {
        $search = isset($_POST['search']) ? $_POST['search'] : '';
        $status_filter = isset($_POST['status']) ? $_POST['status'] : '';
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT a.* FROM appointments a WHERE 1=1";
        $count_sql = "SELECT COUNT(*) as total FROM appointments a WHERE 1=1";
        $params = [];
        $count_params = [];
        $types = "";
        $count_types = "";

        if (!empty($status_filter) && $status_filter !== 'all') {
            if ($status_filter === 'rescheduled') {
                $sql .= " AND a.is_rescheduled = 1";
                $count_sql .= " AND is_rescheduled = 1";
            } else {
                $sql .= " AND a.status = ?";
                $count_sql .= " AND status = ?";
                $params[] = $status_filter;
                $count_params[] = $status_filter;
                $types .= "s";
                $count_types .= "s";
            }
        }

        if (!empty($search)) {
            $sql .= " AND (a.full_name LIKE ? OR a.phone LIKE ? OR a.email LIKE ? OR a.services LIKE ?)";
            $count_sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR services LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $count_params[] = $search_param;
            $count_params[] = $search_param;
            $count_params[] = $search_param;
            $count_params[] = $search_param;
            $types .= "ssss";
            $count_types .= "ssss";
        }

        $count_stmt = $conn->prepare($count_sql);
        if (!empty($count_params)) {
            $count_stmt->bind_param($count_types, ...$count_params);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
        $count_stmt->close();

        $sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $services_result = $conn->query("SELECT id, title, price, duration, icon FROM services WHERE is_active = 1 ORDER BY sort_order, title");
        $services = $services_result->fetch_all(MYSQLI_ASSOC);
        
        $service_lookup = [];
        foreach ($services as $s) {
            $service_lookup[$s['id']] = $s;
            $service_lookup[strtolower($s['title'])] = $s;
            $service_lookup[strtolower((string)$s['id'])] = $s;
        }

        function resolve_services_live($raw, $lookup) {
            $ids = json_decode($raw, true);
            if (!is_array($ids)) { $ids = [$raw]; }
            $titles = [];
            $total = 0.0;
            foreach ($ids as $val) {
                $key = is_string($val) ? strtolower($val) : $val;
                if (isset($lookup[$val])) {
                    $titles[] = $lookup[$val]['title'];
                    $total += (float)$lookup[$val]['price'];
                } elseif (isset($lookup[$key])) {
                    $titles[] = $lookup[$key]['title'];
                    $total += (float)$lookup[$key]['price'];
                } else {
                    $titles[] = (string)$val;
                }
            }
            return ['titles' => $titles, 'total' => $total];
        }

        $html = '';
        if (count($appointments) > 0) {
            foreach ($appointments as $appt) {
                $service_info = resolve_services_live($appt['services'], $service_lookup);
                $html .= '<tr id="row-' . $appt['id'] . '" onclick="window.location.href=\'appointment_details.php?id=' . $appt['id'] . '\'" style="cursor:pointer;">';
                $html .= '<td class="font-semibold text-dark-blue">#' . $appt['id'] . '</td>';
                $html .= '<td><div class="text-sm font-medium">' . htmlspecialchars($appt['phone']) . '</div>';
                $html .= '<div class="text-xs text-gray-500">' . htmlspecialchars($appt['email']) . '</div></td>';
                $html .= '<td><div class="flex flex-wrap gap-1 items-center" style="max-width:220px;">';
                foreach ($service_info['titles'] as $t) {
                    $html .= '<span class="service-tag">' . htmlspecialchars($t) . '</span>';
                }
                $html .= '</div>';
                if ($service_info['total'] > 0) {
                    $html .= '<div class="text-xs text-gray-400 mt-1">₱' . number_format($service_info['total'], 2) . ' total</div>';
                }
                $html .= '</td>';
                $html .= '<td><span class="clickable-status" onclick="event.stopPropagation(); toggleVerification(' . $appt['id'] . ', ' . ($appt['is_verified'] ? 0 : 1) . ')">';
                if ($appt['is_verified']) {
                    $html .= '<span class="status-badge badge-yes" style="background:#d4edda;color:#155724;"><i class="fas fa-check-circle mr-1"></i> Yes</span>';
                } else {
                    $html .= '<span class="status-badge badge-no" style="background:#f8d7da;color:#721c24;"><i class="fas fa-circle mr-1"></i> No</span>';
                }
                $html .= '</span></td>';

                if ($appt['status'] === 'completed') {
                    $html .= '<td><span class="status-badge" style="background:#dcfce7;color:#166534;white-space:nowrap"><i class="fas fa-check-circle"></i> Completed</span>';
                    if (!empty($appt['treatment_completed_at'])) $html .= '<div class="text-xs text-gray-400 mt-1">Finished ' . htmlspecialchars(date('M d, Y g:i A', strtotime($appt['treatment_completed_at']))) . '</div>';
                    $html .= '</td>';
                } else {
                $html .= '<td><select class="status-dropdown" onclick="event.stopPropagation();" onchange="updateStatus(' . $appt['id'] . ', this.value, this)">';
                $html .= '<option value="pending"' . ($appt['status'] === 'pending' ? ' selected' : '') . '>Pending</option>';
                $html .= '<option value="approved"' . ($appt['status'] === 'approved' ? ' selected' : '') . '>Approved</option>';
                if ($appt['status'] === 'approved') {
                    $html .= '<option value="confirmed">Confirm Patient Arrival</option>';
                }
                if ($appt['status'] === 'confirmed') {
                    $html .= '<option value="confirmed" selected disabled>Arrived at Clinic</option>';
                }
                if ($appt['status'] === 'in_progress') {
                    $html .= '<option value="in_progress" selected disabled>In Progress</option>';
                }
                $html .= '<option value="cancelled"' . ($appt['status'] === 'cancelled' ? ' selected' : '') . '>Cancelled</option>';
                $html .= '</select></td>';
                }

                $html .= '<td><div class="text-xs"><div class="font-medium">' . ($appt['appointment_date'] ? date('M d, Y', strtotime($appt['appointment_date'])) : '—') . '</div>';
                $html .= '<div class="text-gray-400">' . ($appt['appointment_time'] ? date('g:i A', strtotime($appt['appointment_time'])) : '') . '</div></div></td>';
                $html .= '<td><div class="action-icons flex items-center justify-center gap-0" onclick="event.stopPropagation();">';
                $html .= '<button onclick="viewAppointment(' . $appt['id'] . ')" title="View Details" class="hover:bg-teal-light"><i class="fas fa-eye"></i></button>';
                $html .= '<button onclick="editAppointment(' . $appt['id'] . ')" title="Edit Appointment" class="hover:bg-teal-light"><i class="fas fa-pen"></i></button>';
                $html .= '<button onclick="rescheduleAppointment(' . $appt['id'] . ', \'' . $appt['appointment_date'] . '\', \'' . $appt['appointment_time'] . '\')" title="Reschedule" class="hover:bg-teal-light"><i class="fas fa-calendar-alt"></i></button>';
                $html .= '<button onclick="deleteAppointment(' . $appt['id'] . ')" class="text-danger hover:bg-red-50" title="Delete Appointment"><i class="fas fa-trash-alt"></i></button>';
                $html .= '</div></td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-calendar-times"></i><h3>No appointments found</h3><p>Try adjusting your filters or create a new appointment.</p></div></td></tr>';
        }

        $pagination_html = '';
        if ($total_pages > 1) {
            $pagination_html .= '<div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">';
            $pagination_html .= '<span class="text-xs text-gray-400">Showing ' . ($offset + 1) . '–' . min($offset + $limit, $total_records) . ' of ' . $total_records . ' records</span>';
            $pagination_html .= '<div class="flex gap-1">';
            if ($page > 1) {
                $pagination_html .= '<button class="pagination-link" onclick="liveSearch(' . ($page - 1) . ')"><i class="fas fa-chevron-left"></i></button>';
            }
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++) {
                $active = $i === $page ? ' active' : '';
                $pagination_html .= '<button class="pagination-link' . $active . '" onclick="liveSearch(' . $i . ')">' . $i . '</button>';
            }
            if ($page < $total_pages) {
                $pagination_html .= '<button class="pagination-link" onclick="liveSearch(' . ($page + 1) . ')"><i class="fas fa-chevron-right"></i></button>';
            }
            $pagination_html .= '</div></div>';
        }

        $statusSummary = ['pending' => 0, 'approved' => 0, 'confirmed' => 0,
            'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'total' => 0];
        $summaryResult = $conn->query('SELECT status, COUNT(*) AS tally FROM appointments GROUP BY status');
        while ($summaryRow = $summaryResult->fetch_assoc()) {
            $statusSummary['total'] += (int)$summaryRow['tally'];
            if (array_key_exists($summaryRow['status'], $statusSummary)) {
                $statusSummary[$summaryRow['status']] = (int)$summaryRow['tally'];
            }
        }
        $statusSummary['rescheduled'] = (int)$conn->query('SELECT COUNT(*) AS tally FROM appointments WHERE is_rescheduled = 1')->fetch_assoc()['tally'];

        $response = [
            'success' => true,
            'html' => $html,
            'pagination' => $pagination_html,
            'total' => $total_records,
            'page' => $page,
            'total_pages' => $total_pages,
            'stats' => $statusSummary
        ];
        echo json_encode($response);
        exit;
    }

    // ===== CONFIRM ARRIVAL =====
    if ($action === 'confirm_arrival' && $id > 0) {
        try {
            // Only approved appointments may proceed to patient arrival confirmation.
            $gate = $conn->prepare('SELECT status FROM appointments WHERE id = ? LIMIT 1');
            $gate->bind_param('i', $id);
            $gate->execute();
            $gateRow = $gate->get_result()->fetch_assoc();
            $gate->close();
            if (!$gateRow || $gateRow['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'Approve the appointment before confirming patient arrival.']);
                exit;
            }
            $linked = dcl_confirm_appointment($conn, $id);
            $response = ['success' => true, 'message' => 'Patient confirmed and linked to clinic record.', 'patient_id' => $linked['patient_id']];
        } catch (DomainException $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Patient confirmation failed: '.$e->getMessage());
            $response = ['success' => false, 'message' => 'Confirmation could not be saved. Please try again.'];
        }
        echo json_encode($response);
        exit;
    }

    // ===== RESCHEDULE =====
    if ($action === 'reschedule' && $id > 0) {
        try {
            $new_date = trim($_POST['appointment_date'] ?? '');
            $new_time = trim($_POST['appointment_time'] ?? '');
            $reason   = trim($_POST['reason'] ?? '');

            if ($new_date === '' || $new_time === '') {
                echo json_encode(['success' => false, 'message' => 'Please provide both a new date and time.']);
                exit;
            }

            $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appointment) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
                exit;
            }

            $old_date = $appointment['appointment_date'];
            $old_time = $appointment['appointment_time'];

            $stmt = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, previous_date = ?, previous_time = ?, is_rescheduled = 1, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssi", $new_date, $new_time, $old_date, $old_time, $id);

            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Appointment rescheduled successfully.'];
                $stmt->close();

                if (function_exists('log_staff_activity')) {
                    log_staff_activity(
                        $conn, 'reschedule', 'appointments',
                        "Rescheduled appointment #{$id} ({$appointment['full_name']}) from {$old_date} {$old_time} to {$new_date} {$new_time}",
                        'appointment', $id,
                        ['appointment_date' => $old_date, 'appointment_time' => $old_time],
                        ['appointment_date' => $new_date, 'appointment_time' => $new_time]
                    );
                }

                if (function_exists('sendRescheduleEmail')) {
                    try {
                        $email_sent = sendRescheduleEmail(
                            $appointment['email'],
                            $appointment['full_name'] ?: 'Patient',
                            $old_date,
                            $old_time,
                            $new_date,
                            $new_time,
                            $reason
                        );
                        if ($email_sent) {
                            $response['message'] .= ' Patient notified by email.';
                        } else {
                            $response['message'] .= ' (Email notification failed.)';
                            $response['warning'] = true;
                        }
                    } catch (Exception $e) {
                        $response['message'] .= ' (Email notification failed: ' . $e->getMessage() . ')';
                        $response['warning'] = true;
                    }
                }
            } else {
                $response = ['success' => false, 'message' => 'Failed to reschedule appointment.'];
                $stmt->close();
            }
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }

        echo json_encode($response);
        exit;
    }

    $response = ['success' => false, 'message' => 'Invalid action'];

    // ===== DELETE =====
    if ($action === 'delete' && $id > 0) {
        $snap_stmt = $conn->prepare("SELECT full_name, appointment_date, appointment_time, status FROM appointments WHERE id = ?");
        $snap_stmt->bind_param("i", $id);
        $snap_stmt->execute();
        $snapshot = $snap_stmt->get_result()->fetch_assoc();
        $snap_stmt->close();

        $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Appointment deleted successfully.'];
            log_staff_activity(
                $conn, 'delete', 'appointments',
                "Deleted appointment #{$id}" . ($snapshot ? " for {$snapshot['full_name']} ({$snapshot['appointment_date']} {$snapshot['appointment_time']})" : ""),
                'appointment', $id, $snapshot, null
            );
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete appointment.'];
        }
        $stmt->close();
    }

    // ===== UPDATE STATUS =====
    if ($action === 'update_status' && $id > 0 && !empty($status)) {
        try {
            $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appointment) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
                exit;
            }

            // Do not allow repeated confirmation to rewind an active or completed treatment.
            if ($status === 'confirmed' && $appointment['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'Appointment must be Approved before patient arrival can be confirmed.']);
                exit;
            }

            // Treatment state transitions are explicit. Arrival confirmation is not completion.
            if (in_array($status, ['in_progress', 'completed'], true)) {
                $requiredPrevious = $status === 'in_progress' ? 'confirmed' : 'in_progress';
                if (($appointment['status'] ?? '') !== $requiredPrevious) {
                    echo json_encode(['success' => false, 'message' => 'This treatment cannot change from its current status. Refresh the appointment and try again.']);
                    exit;
                }
                // The dentist may begin a confirmed appointment earlier than its booked date.
                // Keep appointment_date unchanged for history and scheduling. Only the explicit
                // status transition controls whether this patient is actively being treated.
                if (empty($appointment['dentist_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Assign a dentist before beginning treatment.']);
                    exit;
                }
            }
            $dentistId = isset($_POST['dentist_id']) ? intval($_POST['dentist_id']) : null;
            $notes     = isset($_POST['notes']) ? trim($_POST['notes']) : '';

            // Verify an explicit existing-patient association before changing status.
            if ($status === 'confirmed' && !empty($appointment['patient_id'])
                && !aps_get_patient($conn, (int)$appointment['patient_id'])) {
                echo json_encode(['success' => false, 'message' => 'The selected patient no longer exists. Please review this appointment.']);
                exit;
            }

            $was_already_approved  = ($appointment['status'] === 'approved');
            $was_already_confirmed = ($appointment['status'] === 'confirmed');

            $is_approving  = ($status === 'approved'  && !$was_already_approved);
            $is_confirming = ($status === 'confirmed' && !$was_already_confirmed);

            // Unified confirmed transition: patient registration + appointment link are atomic.
            // No web login account is generated from clinic confirmation.
            if ($status === 'confirmed') {
                try {
                    $linked = dcl_confirm_appointment($conn, $id, $dentistId, $notes);
                    echo json_encode(['success'=>true, 'message'=>'Appointment confirmed and clinic patient linked.', 'patient_id'=>$linked['patient_id']]);
                } catch (DomainException $e) {
                    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
                } catch (Throwable $e) {
                    error_log('Confirm appointment error: '.$e->getMessage());
                    echo json_encode(['success'=>false,'message'=>'Confirmation failed. Please try again.']);
                }
                exit;
            }

            // ---- Update the appointment ----
            $sql = "UPDATE appointments SET status = ?";
            $params = [$status];
            $types = "s";

            if ($dentistId !== null && $dentistId > 0) {
                $sql .= ", dentist_id = ?";
                $params[] = $dentistId;
                $types .= "i";
            }
            if (!empty($notes)) {
                $sql .= ", internal_notes = ?";
                $params[] = $notes;
                $types .= "s";
            }
            // Preserve the scheduled appointment date/time; record the actual event time.
            if ($status === 'confirmed') {
                $sql .= ", arrived_at = COALESCE(arrived_at, NOW())";
            } elseif ($status === 'in_progress') {
                $sql .= ", treatment_started_at = COALESCE(treatment_started_at, NOW())";
            } elseif ($status === 'completed') {
                $sql .= ", treatment_completed_at = COALESCE(treatment_completed_at, NOW())";
            }
            $sql .= ", updated_at = NOW() WHERE id = ?";
            $params[] = $id;
            $types .= "i";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) {
                $stmt->close();
                echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
                exit;
            }
            $stmt->close();

            $response = ['success' => true, 'message' => 'Status updated successfully.'];

            if (function_exists('log_staff_activity')) {
                log_staff_activity(
                    $conn, 'status_change', 'appointments',
                    "Changed appointment #{$id} ({$appointment['full_name']}) status from '{$appointment['status']}' to '{$status}'",
                    'appointment', $id,
                    ['status' => $appointment['status'], 'dentist_id' => $appointment['dentist_id']],
                    ['status' => $status, 'dentist_id' => $dentistId]
                );
            }

            if ($dentistId !== null && $dentistId > 0) {
                $dentist_stmt = $conn->prepare("SELECT first_name, last_name FROM dentists WHERE id = ?");
                $dentist_stmt->bind_param("i", $dentistId);
                $dentist_stmt->execute();
                $dentist_result = $dentist_stmt->get_result();
                if ($dentist_row = $dentist_result->fetch_assoc()) {
                    $dentist_name = 'Dr. ' . $dentist_row['first_name'] . ' ' . $dentist_row['last_name'];
                    $response['dentist'] = $dentist_name;
                    $response['message'] .= ' Assigned to ' . $dentist_name . '.';
                }
                $dentist_stmt->close();
            }

            // ============================================================
            // APPROVED → send approval email only
            // ============================================================
            if ($is_approving) {
                try {
                    $email_sent = sendAppointmentConfirmationEmail(
                        $appointment['email'],
                        $appointment['full_name'] ?: 'Patient'
                    );
                    if ($email_sent) {
                        $response['message'] .= ' Approval email sent to patient.';
                    } else {
                        $response['message'] .= ' Status approved but approval email failed.';
                        $response['warning'] = true;
                    }
                } catch (Exception $e) {
                    $response['message'] .= ' Status approved but email failed: ' . $e->getMessage();
                    $response['warning'] = true;
                    error_log('Approval email error: ' . $e->getMessage());
                }
            }

            // ============================================================
            // CONFIRMED → create patient + user + welcome email
            // ============================================================
            if ($is_confirming) {
                if (!empty($appointment['patient_id'])) {
                    // Do not overwrite patients/users or create another patient on confirmation.
                    $response['message'] .= ' Existing patient record preserved.';
                    if (filter_var($appointment['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                        try {
                            if (!sendAppointmentConfirmationEmail($appointment['email'], $appointment['full_name'] ?: 'Patient')) {
                                $response['warning'] = true;
                                $response['message'] .= ' Confirmation email could not be sent.';
                            }
                        } catch (Throwable $e) {
                            error_log('Existing patient confirmation email: ' . $e->getMessage());
                            $response['warning'] = true;
                            $response['message'] .= ' Confirmation email could not be sent.';
                        }
                    }
                } else {
                // Legacy/new-patient confirmation workflow remains unchanged.
                $email = $appointment['email'];
                $phone = $appointment['phone'];

                // Build address string once
                $address_parts = array_filter([
                    $appointment['street_address'] ?? '',
                    $appointment['barangay'] ?? '',
                    $appointment['city'] ?? '',
                    $appointment['province'] ?? '',
                    $appointment['region'] ?? '',
                ]);
                $address_str = implode(', ', $address_parts);

                // ---- STEP 1: Patient record ----
                $patient_stmt = $conn->prepare("
                    SELECT id FROM patients 
                    WHERE (email <> '' AND email = ?) 
                       OR (phone <> '' AND phone = ?)
                    LIMIT 1
                ");
                $patient_stmt->bind_param("ss", $email, $phone);
                $patient_stmt->execute();
                $existing_patient = $patient_stmt->get_result()->fetch_assoc();
                $patient_stmt->close();

                // An explicitly NEW patient must never be merged by shared phone/email.
                // Historical appointments with NULL registration_mode keep their prior behavior.
                $isExplicitNewPatient = ($appointment['patient_registration_mode'] ?? '') === 'new';
                if ($existing_patient && !$isExplicitNewPatient) {
                    $update_patient = $conn->prepare("
                        UPDATE patients 
                        SET full_name = ?, age = ?, phone = ?, email = ?, address = ?, appointment_id = ?
                        WHERE id = ?
                    ");
                    $update_patient->bind_param(
                        "sissssi",
                        $appointment['full_name'],
                        $appointment['age'],
                        $phone,
                        $email,
                        $address_str,
                        $id,
                        $existing_patient['id']
                    );
                    if ($update_patient->execute()) {
                        $response['message'] .= ' Patient record updated.';
                    }
                    $update_patient->close();
                } else {
                    $new_patient_id = 'PAT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

                    $insert_patient = $conn->prepare("
                        INSERT INTO patients 
                            (patient_id, full_name, age, gender, phone, email, address, status, source, appointment_id, created_at)
                        VALUES (?, ?, ?, NULL, ?, ?, ?, 'active', 'appointment', ?, NOW())
                    ");
                    $insert_patient->bind_param(
                        "ssisssi",
                        $new_patient_id,
                        $appointment['full_name'],
                        $appointment['age'],
                        $phone,
                        $email,
                        $address_str,
                        $id
                    );

                    if ($insert_patient->execute()) {
                        $response['message'] .= ' Patient record created.';
                        if ($isExplicitNewPatient && isset($appointment['patient_id'])) {
                            $newPatientInternalId = (int)$conn->insert_id;
                            $linkStmt = $conn->prepare('UPDATE appointments SET patient_id = ? WHERE id = ?');
                            $linkStmt->bind_param('ii', $newPatientInternalId, $id);
                            $linkStmt->execute();
                            $linkStmt->close();
                        }
                    } else {
                        error_log('Patient insert failed: ' . $insert_patient->error);
                        $response['message'] .= ' Failed to create patient record.';
                        $response['warning'] = true;
                    }
                    $insert_patient->close();
                }

                // ---- STEP 2: User account + welcome email ----
                $check_stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows === 0) {
                    $generated_password = generateRandomPassword(10);
                    $hashed_password = password_hash($generated_password, PASSWORD_DEFAULT);

                    $full_name = !empty($appointment['full_name']) ? $appointment['full_name'] : 'Patient';
                    $role = 'patient';
                    $is_active = 1;

                    $insert_stmt = $conn->prepare("
                        INSERT INTO users (email, password, full_name, phone, age, occupation, address, role, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->bind_param(
                        "ssssisssi",
                        $email,
                        $hashed_password,
                        $full_name,
                        $phone,
                        $appointment['age'],
                        $appointment['occupation'],
                        $address_str,
                        $role,
                        $is_active
                    );

                    if ($insert_stmt->execute()) {
                        $insert_stmt->close();
                        try {
                            if (sendWelcomeEmail($email, $full_name, $generated_password)) {
                                $response['message'] .= ' User account created and welcome email sent.';
                            } else {
                                $response['message'] .= ' User account created but welcome email failed.';
                                $response['warning'] = true;
                            }
                        } catch (Exception $e) {
                            $response['message'] .= ' User account created but email failed: ' . $e->getMessage();
                            $response['warning'] = true;
                            error_log('Welcome email error: ' . $e->getMessage());
                        }
                    } else {
                        $response['message'] .= ' Failed to create user account.';
                        $response['warning'] = true;
                        error_log('User insert failed: ' . $insert_stmt->error);
                        $insert_stmt->close();
                    }
                } else {
                    // Existing accounts must not be overwritten by explicitly new patients;
                    // family members can share an email address.
                    $user_data = $check_result->fetch_assoc();
                    $uid_existing = (int)$user_data['id'];
                    $full_name = $user_data['full_name'];

                    // Never let a patient appointment modify a staff/admin account
                    // if an email address was accidentally reused.
                    if (!$isExplicitNewPatient && ($user_data['role'] ?? '') === 'patient') {
                    $refresh = $conn->prepare("
                        UPDATE users 
                        SET phone = ?, age = ?, occupation = ?, address = ?, full_name = ?
                        WHERE id = ?
                    ");
                    $refresh->bind_param(
                        "sisssi",
                        $phone,
                        $appointment['age'],
                        $appointment['occupation'],
                        $address_str,
                        $appointment['full_name'],
                        $uid_existing
                    );
                    $refresh->execute();
                    $refresh->close();
                    }

                    try {
                        if (sendAppointmentConfirmationEmail($email, $full_name)) {
                            $response['message'] .= ($isExplicitNewPatient ? ' Existing account preserved, confirmation email sent.' : ' Existing account — details refreshed, confirmation email sent.');
                        } else {
                            $response['message'] .= ($isExplicitNewPatient ? ' Existing account preserved but email failed.' : ' Existing account — details refreshed but email failed.');
                            $response['warning'] = true;
                        }
                    } catch (Exception $e) {
                        $response['message'] .= ' Existing account — email failed: ' . $e->getMessage();
                        $response['warning'] = true;
                        error_log('Confirmation email error: ' . $e->getMessage());
                    }
                }
                $check_stmt->close();
                } // End legacy/new-patient confirmation workflow
            }
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            error_log("Error in update_status: " . $e->getMessage());
        }
    }

    // ===== UPDATE VERIFICATION =====
    if ($action === 'update_verification' && $id > 0) {
        $stmt = $conn->prepare("UPDATE appointments SET is_verified = ? WHERE id = ?");
        $stmt->bind_param("ii", $verified, $id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Verification status updated.'];
            log_staff_activity(
                $conn, 'update', 'appointments',
                "Set appointment #{$id} verification to " . ($verified ? 'verified' : 'unverified'),
                'appointment', $id, null, ['is_verified' => $verified]
            );
        } else {
            $response = ['success' => false, 'message' => 'Failed to update verification.'];
        }
        $stmt->close();
    }

    /* ---------- SMART BULK REMINDERS ---------- */
    if ($action === 'bulk_list') {
        try {
            $from = trim((string)($_POST['date_from'] ?? ''));
            $to = trim((string)($_POST['date_to'] ?? ''));
            $d1 = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
            $d2 = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
            $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
            if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $from || $d2->format('Y-m-d') !== $to || $d1 < $today || $d2 < $d1 || $d1->diff($d2)->days > 30) {
                http_response_code(422);
                echo json_encode(['success'=>false,'message'=>'Choose a valid future range of up to 31 calendar days.']);
                exit;
            }
            $stmt = $conn->prepare("SELECT id, full_name, email, phone, appointment_date, appointment_time, status,
                    reminder_sent, reminder_sent_at, dentist_id,
                    CASE WHEN status = 'approved' AND (arrived_at IS NULL) AND
                              (appointment_date > CURDATE() OR
                               (appointment_date = CURDATE() AND appointment_time > CURTIME())) AND
                              reminder_sent = 0 AND email <> ''
                         THEN 1 ELSE 0 END AS eligible
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ?
                ORDER BY appointment_date ASC, appointment_time ASC, id ASC LIMIT 500");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            foreach ($rows as &$r) {
                $r['eligible'] = (bool)$r['eligible'] && filter_var($r['email'], FILTER_VALIDATE_EMAIL) !== false;
                $r['reason'] = $r['eligible'] ? 'Ready to send' : (
                    (int)$r['reminder_sent'] === 1 ? 'Already reminded' :
                    ($r['status'] === 'completed' ? 'Completed' :
                    (in_array($r['status'], ['confirmed','in_progress'], true) ? 'Already arrived / in treatment' :
                    ($r['status'] === 'cancelled' ? 'Cancelled' :
                    ($r['status'] !== 'approved' ? 'Awaiting approval' :
                    (!filter_var($r['email'], FILTER_VALIDATE_EMAIL) ? 'No valid email address' : 'Appointment time has passed'))))));
            }
            unset($r);
            echo json_encode(['success'=>true,'recipients'=>$rows,'count'=>count($rows)], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('Bulk reminder recipient list failed: '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>'Could not load reminder recipients.']);
        }
        exit;
    }

    if ($action === 'bulk_notify') {
        $ids = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);
        $customMessage = trim((string)($_POST['custom_message'] ?? ''));
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 25 || strlen($customMessage) > 1000) {
            http_response_code(422);
            echo json_encode(['success'=>false,'message'=>'Select 1 to 25 recipients, and keep your note below 1000 characters.']);
            exit;
        }
        $cleanIds = array_values(array_unique(array_map('intval', $ids)));
        if (count($cleanIds) !== count($ids) || count(array_filter($cleanIds, fn($v) => $v > 0)) !== count($cleanIds)) {
            http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid or duplicate recipients.']); exit;
        }
        $svcLookup = [];
        try {
            $sr = $conn->query('SELECT id, title FROM services');
            while ($service = $sr->fetch_assoc()) $svcLookup[(string)$service['id']] = $service['title'];
        } catch (Throwable $e) { error_log('Reminder service lookup: '.$e->getMessage()); }
        $results = []; $sent = 0; $skipped = 0; $failed = 0;
        foreach ($cleanIds as $apptId) {
            // An advisory lock prevents two staff requests from sending the same reminder concurrently.
            $lockName = 'dental_reminder_'.$apptId;
            $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 2) AS acquired');
            $lockStmt->bind_param('s', $lockName); $lockStmt->execute();
            $lockAcquired = (int)($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
            $lockStmt->close();
            if (!$lockAcquired) { $results[]=['id'=>$apptId,'outcome'=>'skipped','detail'=>'Another reminder request is processing']; $skipped++; continue; }
            try {
                $stmt = $conn->prepare('SELECT id, full_name, email, services, appointment_date, appointment_time, status, arrived_at, reminder_sent FROM appointments WHERE id = ?');
                $stmt->bind_param('i', $apptId); $stmt->execute();
                $appt = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$appt || $appt['status'] !== 'approved' || !empty($appt['arrived_at']) || (int)$appt['reminder_sent'] !== 0 ||
                    !filter_var($appt['email'] ?? '', FILTER_VALIDATE_EMAIL) ||
                    ($appt['appointment_date'].' '.$appt['appointment_time']) <= date('Y-m-d H:i:s')) {
                    $results[]=['id'=>$apptId,'outcome'=>'skipped','detail'=>'No longer eligible, already reminded, or invalid email']; $skipped++; continue;
                }
                $serviceIds = json_decode((string)$appt['services'],true);
                if (!is_array($serviceIds)) $serviceIds = [$appt['services']];
                $serviceNames = [];
                foreach ($serviceIds as $sid) $serviceNames[] = $svcLookup[(string)$sid] ?? 'Dental service';
                $ok = sendAppointmentReminderEmail($appt['email'], $appt['full_name'] ?: 'Patient',
                    $appt['appointment_date'], $appt['appointment_time'], implode(', ', $serviceNames), $customMessage);
                if ($ok) {
                    $update = $conn->prepare('UPDATE appointments SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ? AND reminder_sent = 0');
                    $update->bind_param('i', $apptId); $update->execute(); $update->close();
                    $sent++; $results[]=['id'=>$apptId,'outcome'=>'sent','detail'=>'Email sender reported success'];
                } else {
                    $failed++; $results[]=['id'=>$apptId,'outcome'=>'failed','detail'=>'Email sender reported failure'];
                }
            } catch (Throwable $e) {
                error_log('Bulk reminder failed for appointment '.$apptId.': '.$e->getMessage());
                $failed++; $results[]=['id'=>$apptId,'outcome'=>'failed','detail'=>'Server error; check mail logs'];
            } finally {
                $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
                $release->bind_param('s',$lockName); $release->execute(); $release->close();
            }
        }
        try {
            log_staff_activity($conn, 'bulk_notify', 'appointments', "Bulk appointment reminders: {$sent} sent, {$failed} failed, {$skipped} skipped", 'appointment', 0, null,
                ['ids'=>$cleanIds,'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped]);
        } catch (Throwable $e) { error_log('Reminder audit log failed: '.$e->getMessage()); }
        echo json_encode(['success'=>true,'message'=>"{$sent} sent, {$failed} failed, {$skipped} skipped.",
            'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped,'results'=>$results]);
        exit;
    }

    // ===== GET =====
    if ($action === 'get' && $id > 0) {
        $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $appt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($appt) {
            $med_stmt = $conn->prepare("SELECT * FROM medical_dental_history WHERE appointment_id = ?");
            $med_stmt->bind_param("i", $id);
            $med_stmt->execute();
            $appt['medical_history'] = $med_stmt->get_result()->fetch_assoc();
            $med_stmt->close();
            $response = ['success' => true, 'data' => $appt];
        } else {
            $response = ['success' => false, 'message' => 'Appointment not found.'];
        }
    }

    // ===== CREATE =====
    if ($action === 'create') {
        $full_name = trim($_POST['full_name'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $appointment_time = trim($_POST['appointment_time'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $services_val = trim($_POST['services'] ?? '');
        $new_status = $_POST['status'] ?? 'pending';
        $new_verified = isset($_POST['is_verified']) ? (int)$_POST['is_verified'] : 0;
        $medical_history = isset($_POST['medical_history']) ? json_decode($_POST['medical_history'], true) : [];

        // A newly created appointment must still travel through the clinic's
        // approval/arrival workflow; creating it as confirmed would skip patient
        // registration performed during staff confirmation.
        if (!in_array($new_status, ['pending', 'approved'], true)) {
            echo json_encode(['success' => false, 'message' => 'New appointments must start as Pending or Approved.']);
            exit;
        }

        // Reject malformed ages before MySQLi can silently cast them to zero.
        if ($age !== '' && (!ctype_digit((string)$age) || (int)$age > 120)) {
            echo json_encode(['success' => false, 'message' => 'Enter an age between 0 and 120, or leave it blank.']);
            exit;
        }

        // Patient source is only trusted after a fresh database lookup.
        $patientMode = ($_POST['patient_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';
        $selectedPatientId = null;
        $appointmentColumns = aps_columns($conn, 'appointments');
        if ($patientMode === 'existing') {
            if (!isset($appointmentColumns['patient_id'])) {
                echo json_encode(['success' => false, 'message' => 'The patient-link database migration is required before booking existing patients.']);
                exit;
            }
            $requestedId = filter_var($_POST['selected_patient_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$requestedId || $requestedId < 1) {
                echo json_encode(['success' => false, 'message' => 'Please select an existing patient from search suggestions.']);
                exit;
            }
            $patient = aps_get_patient($conn, (int)$requestedId);
            if (!$patient || ($patient['status'] === 'inactive')) {
                echo json_encode(['success' => false, 'message' => 'This patient is no longer available for booking.']);
                exit;
            }
            $selectedPatientId = $patient['id'];
            // Ignore client-supplied personal fields, including empty or modified values.
            $full_name = $patient['full_name'];
            $age = $patient['age'];
            $occupation = $patient['occupation'];
            $phone = $patient['phone'];
            $email = $patient['email'];
            $region = $patient['region']; $province = $patient['province'];
            $city = $patient['city']; $barangay = $patient['barangay'];
            $street_address = trim((string)($patient['street_address'] ?? '')) !== '' ? $patient['street_address'] : ($patient['address'] ?? '');
        } else {
            // Warn about duplicate contacts, but permit deliberate override for shared contacts.
            if (($_POST['override_duplicate'] ?? '0') !== '1') {
                $possibleMatches = aps_possible_matches($conn, $email, $phone, $full_name);
                if ($possibleMatches) {
                    echo json_encode(['success' => false, 'code' => 'possible_duplicate',
                        'message' => 'A patient with matching name or contact information exists. Review the matches before registering someone new.',
                        'matches' => $possibleMatches], JSON_INVALID_UTF8_SUBSTITUTE);
                    exit;
                }
            }
        }

        // Validate date, time, and actual service IDs on the server, not just in JavaScript.
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $appointment_date);
        $validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $appointment_time);
        $serviceIds = json_decode($services_val, true);
        $serviceIdsValid = is_array($serviceIds) && count($serviceIds) > 0
            && count($serviceIds) <= 30;
        if ($serviceIdsValid) {
            foreach ($serviceIds as $serviceId) {
                if (!is_scalar($serviceId) || !ctype_digit((string)$serviceId) || (int)$serviceId < 1) {
                    $serviceIdsValid = false;
                    break;
                }
            }
        }
        if (!$validDate || $validDate->format('Y-m-d') !== $appointment_date || !$validTime || !$serviceIdsValid) {
            echo json_encode(['success' => false, 'message' => 'Choose a valid date, time and at least one service.']);
            exit;
        }
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $serviceTypes = str_repeat('i', count($serviceIds));
        $serviceCheck = $conn->prepare("SELECT COUNT(*) AS total FROM services WHERE is_active = 1 AND id IN ($servicePlaceholders)");
        $serviceCheck->bind_param($serviceTypes, ...$serviceIds);
        $serviceCheck->execute();
        $activeServiceCount = (int)($serviceCheck->get_result()->fetch_assoc()['total'] ?? 0);
        $serviceCheck->close();
        if ($activeServiceCount !== count($serviceIds)) {
            echo json_encode(['success' => false, 'message' => 'One or more selected services are unavailable. Refresh and try again.']);
            exit;
        }
        $services_val = json_encode($serviceIds);

        if ($full_name !== '' && ($patientMode === 'existing' || ($phone !== '' && $email !== ''))
            && $services_val !== '' && $appointment_date !== '' && $appointment_time !== '') {
            $createKey = strtolower(trim((string)($_POST['create_key'] ?? '')));
            $hasCreateKey = isset($appointmentColumns['create_request_key']) && preg_match('/^[a-f0-9]{32}$/D', $createKey);
            if (isset($appointmentColumns['create_request_key']) && !$hasCreateKey) {
                echo json_encode(['success' => false, 'message' => 'Unable to initialize the appointment request. Please refresh and try again.']);
                exit;
            }
            if ($hasCreateKey) {
                $keyStmt = $conn->prepare('SELECT id FROM appointments WHERE create_request_key = ? LIMIT 1');
                $keyStmt->bind_param('s', $createKey);
                $keyStmt->execute();
                $previous = $keyStmt->get_result()->fetch_assoc();
                $keyStmt->close();
                if ($previous) {
                    echo json_encode(['success' => true, 'message' => 'Appointment already saved.', 'id' => (int)$previous['id']]);
                    exit;
                }
            }
            $conn->begin_transaction();
            try {
                $columnsSql = 'full_name, age, occupation, phone, email, region, province, city, barangay, street_address, appointment_date, appointment_time, notes, services, terms_accepted, privacy_accepted, is_verified, status';
                $valuesSql = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?';
                $bindTypes = 'sissssssssssssii';
                $bindValues = [$full_name, $age, $occupation, $phone, $email, $region, $province, $city,
                    $barangay, $street_address, $appointment_date, $appointment_time, $notes,
                    $services_val, $new_verified, $new_status];
                if (isset($appointmentColumns['patient_id'])) {
                    $columnsSql .= ', patient_id'; $valuesSql .= ', ?';
                    $bindTypes .= 'i'; $bindValues[] = $selectedPatientId;
                }
                if (isset($appointmentColumns['patient_registration_mode'])) {
                    $columnsSql .= ', patient_registration_mode'; $valuesSql .= ', ?';
                    $bindTypes .= 's'; $bindValues[] = $patientMode;
                }
                if ($hasCreateKey) {
                    $columnsSql .= ', create_request_key'; $valuesSql .= ', ?';
                    $bindTypes .= 's'; $bindValues[] = $createKey;
                }
                $stmt = $conn->prepare('INSERT INTO appointments (' . $columnsSql . ') VALUES (' . $valuesSql . ')');
                $stmt->bind_param($bindTypes, ...$bindValues);
                
                if ($stmt->execute()) {
                    $appointment_id = $stmt->insert_id;
                    $stmt->close();
                    
                    if (!empty($medical_history)) {
                        $insertFields = ['appointment_id'];
                        $insertValues = [$appointment_id];
                        $placeholders = ['?'];
                        $types = "i";
                        $fieldMap = [
                            'under_physician_care' => 's','physician_name' => 's','pregnant' => 's',
                            'taking_medicine' => 's','medicine_details' => 's','conditions' => 's',
                            'other_condition_details' => 's','allergic_local_anesthesia' => 's',
                            'allergic_antibiotics' => 's','allergic_pain_killer' => 's','allergic_other' => 's',
                            'allergic_other_details' => 's','hospitalized' => 's','hospitalized_when' => 's',
                            'hospitalized_why' => 's','worn_denture' => 's','last_dental_visit' => 's',
                            'last_dental_visit_reason' => 's'
                        ];
                        foreach ($fieldMap as $field => $type) {
                            if (isset($medical_history[$field])) {
                                $insertFields[] = $field;
                                $insertValues[] = $medical_history[$field];
                                $placeholders[] = '?';
                                $types .= $type;
                            }
                        }
                        if (count($insertFields) > 1) {
                            $sql = "INSERT INTO medical_dental_history (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param($types, ...$insertValues);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Appointment created successfully.', 'id' => $appointment_id];
                    try {
                        log_staff_activity($conn, 'create', 'appointments',
                            "Created appointment #{$appointment_id} for {$full_name} on {$appointment_date} {$appointment_time}",
                            'appointment', $appointment_id);
                    } catch (Throwable $logError) {
                        error_log('Appointment saved, but audit log failed: ' . $logError->getMessage());
                    }
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to create appointment.'];
                }
            } catch (Throwable $e) {
                $conn->rollback();
                // Unique create_request_key prevents simultaneous double submissions.
                if ($hasCreateKey && $e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
                    $retryStmt = $conn->prepare('SELECT id FROM appointments WHERE create_request_key = ? LIMIT 1');
                    $retryStmt->bind_param('s', $createKey); $retryStmt->execute();
                    $previous = $retryStmt->get_result()->fetch_assoc(); $retryStmt->close();
                    if ($previous) {
                        $response = ['success' => true, 'message' => 'Appointment already saved.', 'id' => (int)$previous['id']];
                    } else $response = ['success' => false, 'message' => 'Appointment could not be saved. Please try again.'];
                } else {
                    error_log('Staff appointment creation failed: ' . $e->getMessage());
                    $response = ['success' => false, 'message' => 'Appointment could not be saved. Please review the details or try again.'];
                }
            }
        } else {
            $response = ['success' => false, 'message' => 'Enter a patient name, appointment date/time and service. New patients also require phone and email.'];
        }
    }

    // ===== UPDATE =====
    if ($action === 'update' && $id > 0) {
        $full_name = trim($_POST['full_name'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $appointment_time = trim($_POST['appointment_time'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $services_val = trim($_POST['services'] ?? '');
        $new_status = $_POST['status'] ?? 'pending';
        $new_verified = isset($_POST['is_verified']) ? (int)$_POST['is_verified'] : 0;
        $medical_history = isset($_POST['medical_history']) ? json_decode($_POST['medical_history'], true) : [];

        // When an appointment is linked to a registered patient, booking edits
        // may alter schedule and services, but never their demographics.
        // Ignore forged POST updates to these fields as well as UI edits.
        $lookupStmt = $conn->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
        $lookupStmt->bind_param('i', $id);
        $lookupStmt->execute();
        $existingAppointment = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();
        if (!$existingAppointment) {
            echo json_encode(['success' => false, 'message' => 'Appointment no longer exists.']);
            exit;
        }
        // Editing must not approve, confirm, cancel or rewind a booking. Use
        // the dedicated status endpoint so approval/arrival checks are enforced.
        $new_status = $existingAppointment['status'];

        if (!empty($existingAppointment['patient_id'])) {
            foreach (['full_name', 'age', 'occupation', 'phone', 'email', 'region',
                      'province', 'city', 'barangay', 'street_address'] as $field) {
                $$field = $existingAppointment[$field] ?? '';
            }
        }
        if ($phone !== '' && $email !== '' && $services_val !== '') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE appointments SET full_name = ?, age = ?, occupation = ?, phone = ?, email = ?, region = ?, province = ?, city = ?, barangay = ?, street_address = ?, appointment_date = ?, appointment_time = ?, notes = ?, services = ?, status = ?, is_verified = ? WHERE id = ?");
                $stmt->bind_param("sisssssssssssssii", $full_name, $age, $occupation, $phone, $email, $region, $province, $city, $barangay, $street_address, $appointment_date, $appointment_time, $notes, $services_val, $new_status, $new_verified, $id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    
                    if (!empty($medical_history)) {
                        $check_stmt = $conn->prepare("SELECT id FROM medical_dental_history WHERE appointment_id = ?");
                        $check_stmt->bind_param("i", $id);
                        $check_stmt->execute();
                        $exists = $check_stmt->get_result()->num_rows > 0;
                        $check_stmt->close();
                        
                        $fieldMap = [
                            'under_physician_care' => 's','physician_name' => 's','pregnant' => 's',
                            'taking_medicine' => 's','medicine_details' => 's','conditions' => 's',
                            'other_condition_details' => 's','allergic_local_anesthesia' => 's',
                            'allergic_antibiotics' => 's','allergic_pain_killer' => 's','allergic_other' => 's',
                            'allergic_other_details' => 's','hospitalized' => 's','hospitalized_when' => 's',
                            'hospitalized_why' => 's','worn_denture' => 's','last_dental_visit' => 's',
                            'last_dental_visit_reason' => 's'
                        ];
                        
                        if ($exists) {
                            $updateFields = [];
                            $params = [];
                            $types = "";
                            foreach ($fieldMap as $field => $type) {
                                if (isset($medical_history[$field])) {
                                    $updateFields[] = "$field = ?";
                                    $params[] = $medical_history[$field];
                                    $types .= $type;
                                }
                            }
                            if (!empty($updateFields)) {
                                $params[] = $id;
                                $types .= "i";
                                $sql = "UPDATE medical_dental_history SET " . implode(', ', $updateFields) . " WHERE appointment_id = ?";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param($types, ...$params);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            $insertFields = ['appointment_id'];
                            $insertValues = [$id];
                            $placeholders = ['?'];
                            $types = "i";
                            foreach ($fieldMap as $field => $type) {
                                if (isset($medical_history[$field])) {
                                    $insertFields[] = $field;
                                    $insertValues[] = $medical_history[$field];
                                    $placeholders[] = '?';
                                    $types .= $type;
                                }
                            }
                            if (count($insertFields) > 1) {
                                $sql = "INSERT INTO medical_dental_history (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param($types, ...$insertValues);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                    }
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Appointment updated successfully.'];
                    log_staff_activity($conn, 'update', 'appointments',
                        "Edited appointment #{$id} ({$full_name})", 'appointment', $id);
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to update appointment.'];
                }
            } catch (Exception $e) {
                $conn->rollback();
                $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
        } else {
            $response = ['success' => false, 'message' => 'Please fill in all required fields.'];
        }
    }

    echo json_encode($response);
    exit;
}

// ===== INITIAL PAGE LOAD =====
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$sql = "SELECT a.* FROM appointments a WHERE 1=1";
$count_sql = "SELECT COUNT(*) as total FROM appointments a WHERE 1=1";
$params = [];
$count_params = [];
$types = "";
$count_types = "";

if (!empty($status_filter) && $status_filter !== 'all') {
    if ($status_filter === 'rescheduled') {
        $sql .= " AND a.is_rescheduled = 1";
        $count_sql .= " AND is_rescheduled = 1";
    } else {
        $sql .= " AND a.status = ?";
        $count_sql .= " AND status = ?";
        $params[] = $status_filter;
        $count_params[] = $status_filter;
        $types .= "s";
        $count_types .= "s";
    }
}

if (!empty($search)) {
    $sql .= " AND (a.full_name LIKE ? OR a.phone LIKE ? OR a.email LIKE ? OR a.services LIKE ?)";
    $count_sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR services LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $types .= "ssss";
    $count_types .= "ssss";
}

$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$count_stmt->close();

$sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total = count($appointments);
$stmt->close();

$stats = [];
$statuses = ['approved', 'confirmed', 'pending', 'in_progress', 'completed', 'cancelled'];
foreach ($statuses as $s) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE status = ?");
    $stmt->bind_param("s", $s);
    $stmt->execute();
    $stats[$s] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}
$total_all = (int)$conn->query('SELECT COUNT(*) AS total FROM appointments')->fetch_assoc()['total'];

$resched_result = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE is_rescheduled = 1");
$stats['rescheduled'] = $resched_result->fetch_assoc()['count'];

$services_result = $conn->query("SELECT id, title, price, duration, icon FROM services WHERE is_active = 1 ORDER BY sort_order, title");
$services = $services_result->fetch_all(MYSQLI_ASSOC);

$service_lookup = [];
foreach ($services as $s) {
    $service_lookup[$s['id']] = $s;
    $service_lookup[strtolower($s['title'])] = $s;
    $service_lookup[strtolower((string)$s['id'])] = $s;
}

function resolve_services($raw, $lookup) {
    $ids = json_decode($raw, true);
    if (!is_array($ids)) { $ids = [$raw]; }
    $titles = [];
    $total = 0.0;
    foreach ($ids as $val) {
        $key = is_string($val) ? strtolower($val) : $val;
        if (isset($lookup[$val])) {
            $titles[] = $lookup[$val]['title'];
            $total += (float)$lookup[$val]['price'];
        } elseif (isset($lookup[$key])) {
            $titles[] = $lookup[$key]['title'];
            $total += (float)$lookup[$key]['price'];
        } else {
            $titles[] = (string)$val;
        }
    }
    return ['titles' => $titles, 'total' => $total];
}

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments · Dental Clinic</title>
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
        .border-teal { border-color: var(--teal); }

        .container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
        @media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

        .btn-primary {
            background: var(--teal); color: white; padding: 10px 22px;
            border-radius: 10px; font-weight: 600; font-size: 13px;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 14px rgba(13,158,140,0.25);
            border: none; cursor: pointer; text-decoration: none;
        }
        .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 10px 28px rgba(13,158,140,0.35); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .btn-outline {
            border: 1.5px solid var(--teal); color: var(--teal);
            padding: 8px 18px; border-radius: 10px; font-weight: 600; font-size: 12px;
            transition: all 0.2s; background: transparent; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-outline:hover { background: var(--teal); color: white; }

        .stat-card {
            background: white; border-radius: 18px; padding: 1.5rem 1.25rem;
            box-shadow: var(--shadow-card); border: 1px solid #eef2f6;
            transition: all 0.2s; cursor: pointer;
        }
        .stat-card:hover { border-color: var(--teal); box-shadow: 0 12px 32px rgba(11,22,40,.08); }
        .stat-card .stat-icon {
            width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }

        .dashboard-card {
            background: white; border-radius: 18px; padding: 1.5rem 1.25rem;
            box-shadow: var(--shadow-card); border: 1px solid #f0f3f7;
        }
        .dashboard-card .card-title {
            font-size: 13px; font-weight: 700; color: var(--navy);
            display: flex; align-items: center; justify-content: space-between;
        }

        .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 0.5rem; }
        .appointments-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 750px; }
        .appointments-table th {
            text-align: left; padding: 12px 10px 10px 10px; font-weight: 600;
            color: var(--slate); font-size: 11px; letter-spacing: 0.3px;
            text-transform: uppercase; border-bottom: 2px solid #e9edf2; background: #fafbfc;
        }
        .appointments-table td { padding: 14px 10px; border-bottom: 1px solid #f1f4f8; vertical-align: middle; }
        .appointments-table tr:hover td { background: #fafbfc; }
        .appointments-table tr:last-child td { border-bottom: none; }

        .status-badge {
            display: inline-block; padding: 4px 14px; border-radius: 50px;
            font-size: 11px; font-weight: 600; min-width: 80px; text-align: center;
        }
        .status-confirmed { background: #e6f7f5; color: #0d7a6e; }
        .status-approved { background: #d1e7ff; color: #084298; }
        .status-pending { background: #fef3d7; color: #b45309; }
        .status-cancelled { background: #fee9e7; color: #b91c1c; }

        .badge-yes { background: #d4edda; color: #155724; }
        .badge-no { background: #f8d7da; color: #721c24; }

        .action-icons a, .action-icons button {
            color: var(--slate); margin: 0 3px; transition: all 0.15s;
            font-size: 14px; padding: 4px 6px; border-radius: 6px;
            background: none; border: none; cursor: pointer;
        }
        .action-icons a:hover, .action-icons button:hover { background: var(--teal-light); color: var(--teal); }
        .action-icons a.text-danger:hover, .action-icons button.text-danger:hover { background: #fee9e7; color: #e74c3c; }

        @media (min-width: 1024px) { .main-content { margin-left: 260px; } }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }
        @media (max-width: 640px) {
            .stat-card { padding: 1rem; }
            .stat-card .text-3xl { font-size: 1.5rem; }
            .dashboard-card { padding: 1rem; }
            .appointments-table { min-width: 600px; font-size: 12px; }
            .appointments-table th, .appointments-table td { padding: 8px 6px; }
            .status-badge { min-width: 60px; font-size: 10px; padding: 2px 10px; }
        }

        .filter-bar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px;
            background: white; border-radius: 16px; padding: 12px 18px;
            box-shadow: var(--shadow-card); border: 1px solid #f0f3f7; margin-bottom: 1.5rem;
        }
        .filter-bar .filter-group { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--slate); }
        .filter-bar select, .filter-bar input {
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 14px;
            font-size: 13px; background: white; outline: none; transition: border 0.2s;
        }
        .filter-bar select:focus, .filter-bar input:focus { border-color: var(--teal); }

        .status-dropdown {
            padding: 5px 10px; border-radius: 6px; border: 1px solid #e2e8f0;
            font-size: 12px; background: white; cursor: pointer;
        }
        .status-dropdown:focus { outline: none; border-color: var(--teal); }

        .service-tag {
            display: inline-block; background: var(--teal-light); color: var(--teal-dark);
            padding: 3px 12px; border-radius: 12px; font-size: 11px; font-weight: 600;
        }

        .pagination-link {
            padding: 6px 14px; border-radius: 8px; border: 1px solid #e2e8f0;
            font-size: 13px; color: var(--slate); transition: all 0.2s;
            text-decoration: none; display: inline-block; cursor: pointer; background: white;
        }
        .pagination-link:hover { background: #f1f4f8; border-color: var(--teal); }
        .pagination-link.active { background: var(--teal); color: white; border-color: var(--teal); }

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 48px; color: #d0d7e2; margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; font-weight: 600; color: var(--navy); margin-bottom: 8px; }
        .empty-state p { color: var(--slate); font-size: 14px; }

        .clickable-status { cursor: pointer; transition: opacity 0.2s; }
        .clickable-status:hover { opacity: 0.7; }

        .patient-chooser { position:relative; background:#fff; border-radius:12px; padding:18px 20px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .patient-chooser-title { display:flex; align-items:center; gap:8px; padding-bottom:10px; margin-bottom:14px; border-bottom:2px solid #eef2f6; text-transform:uppercase; font-size:13px; letter-spacing:.5px; font-weight:700; color:#0b1628; }
        .patient-chooser-title i {color:#0d9e8c;}
        .patient-mode-toggle { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:14px; font-size:13px; }
        .patient-mode-toggle label { display:flex; gap:7px; align-items:center; cursor:pointer; }
        .patient-mode-toggle input { accent-color:#0d9e8c; }
        .patient-search-label { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:6px; }
        .patient-search-wrap { position:relative; }
        .patient-search-wrap > .fa-search { position:absolute; left:12px; top:14px; color:#0d9e8c; pointer-events:none; }
        #existingPatientSearch { width:100%; padding:11px 37px; border:1px solid #dce4ee; border-radius:10px; background:#fff; font-size:13px; outline-offset:2px; }
        #patientSearchSpinner { position:absolute; right:13px; top:11px; color:#0d9e8c; }
        #patientSearchResults { position:absolute; top:calc(100% + 3px); left:0; right:0; z-index:110; max-height:220px; overflow-y:auto; border:1px solid #dce4ee; border-radius:10px; background:white; box-shadow:0 12px 28px rgba(11,22,40,.18); }
        .patient-option { display:block; width:100%; border:0; border-bottom:1px solid #edf0f5; background:white; text-align:left; padding:10px 12px; cursor:pointer; }
        .patient-option:hover, .patient-option[aria-selected=true] { background:#e6f7f5; }
        .patient-option strong { display:block; color:#0b1628; font-size:13px; }
        .patient-option small { display:block; color:#64748b; font-size:11px; margin-top:3px; overflow-wrap:anywhere; }
        .patient-chooser-hint { font-size:11px; color:#64748b; padding-top:8px; }
        .selected-patient-badge { display:flex; align-items:center; flex-wrap:wrap; gap:9px; font-size:12px; margin-top:10px; color:#0a7d6e; }
        .selected-patient-badge button, .selected-patient-badge a, .patient-create-new { font-size:11px; padding:6px 9px; border:1px solid #0d9e8c; color:#0a7d6e; border-radius:7px; background:white; text-decoration:none; cursor:pointer; }
        .patient-create-new { margin-top:6px; }
        .patient-chooser[hidden], #patientSearchArea[hidden], .patient-chooser [hidden], .patient-search-wrap [hidden] { display:none !important; }
        @media(max-width:500px) { .patient-chooser {padding:14px;} #existingPatientSearch {font-size:12px;} }

        .modal-overlay {
            position: fixed; inset: 0; z-index: 60;
            background: rgba(11, 22, 40, 0.55); backdrop-filter: blur(2px);
            display: none; align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: white; border-radius: 18px; width: 100%; max-width: 480px;
            box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden;
            animation: modalPop .18s ease-out;
        }
        @keyframes modalPop {
            from { opacity: 0; transform: translateY(8px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px; border-bottom: 1px solid #f0f3f7;
        }
        .modal-header h3 { font-size: 17px; font-weight: 700; color: var(--navy); }
        .modal-close-btn {
            background: none; border: none; color: #94a3b8; font-size: 20px;
            line-height: 1; cursor: pointer; padding: 4px; border-radius: 6px;
        }
        .modal-close-btn:hover { background: #f1f4f8; color: var(--navy); }
        .modal-body { padding: 20px 22px; max-height: 65vh; overflow-y: auto; }
        .modal-footer {
            display: flex; align-items: center; justify-content: flex-end; gap: 8px;
            padding: 16px 22px; background: #fafbfc; border-top: 1px solid #f0f3f7;
        }
        .form-field { margin-bottom: 16px; }
        .form-field label {
            display: block; font-size: 11px; font-weight: 700; color: var(--slate);
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px;
        }
        .form-field input, .form-field select {
            width: 100%; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 9px 12px; font-size: 13px; outline: none;
            transition: border 0.2s; background: white;
        }
        .form-field input:focus, .form-field select:focus { border-color: var(--teal); }
        .form-field input:disabled, .form-field select:disabled {
            background: #fafbfc; color: #64748b; cursor: not-allowed;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .view-meta {
            font-size: 12px; color: #94a3b8; border-top: 1px dashed #e2e8f0;
            padding-top: 12px; margin-top: 4px; line-height: 1.6;
        }

        .search-loading { position: relative; }
        .search-loading .search-loader {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%); display: none;
        }
        .search-loading.loading .search-loader { display: block; }
        .search-loader i { color: var(--teal); font-size: 14px; }

        .appointments-table tbody tr { cursor: pointer; transition: background-color 0.15s ease; }
        .appointments-table tbody tr:hover { background-color: #f8fafc; }

        .appointments-table tbody tr .clickable-status,
        .appointments-table tbody tr .status-dropdown,
        .appointments-table tbody tr .action-icons button { cursor: default; }
        .appointments-table tbody tr .action-icons button { cursor: pointer; }
        .appointments-table tbody tr .clickable-status { cursor: pointer; }
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
                         Appointments
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Manage all patient appointments · <span id="totalCount"><?= $total_all ?></span> total</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-xs btn-view" onclick="openReminderSettings()">
    <i class="fas fa-cog"></i> Auto Reminder Settings
</button>

<!-- ============ REMINDER SETTINGS MODAL ============ -->
<div class="modal-overlay" id="reminderSettingsModal">
  <div class="modal-box" style="max-width:640px;">
    <div class="modal-header">
      <h3><i class="fas fa-bell" style="color:var(--teal);"></i> Auto Email Reminder Settings</h3>
      <button class="modal-close-btn" onclick="closeReminderSettings()">&times;</button>
    </div>

    <div class="modal-body" style="max-height:70vh;overflow-y:auto;">

      <!-- Toggle -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:#f7f9fc;border-radius:10px;margin-bottom:16px;">
        <div>
          <div style="font-weight:700;color:var(--navy);font-size:14px;">Enable Auto Reminder Emails</div>
          <div style="font-size:11.5px;color:#94a3b8;margin-top:2px;">When ON, reminder emails are sent automatically each day.</div>
        </div>
        <label class="switch">
          <input type="checkbox" id="reminderEnabled">
          <span class="slider"></span>
        </label>
      </div>

      <!-- Days before -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">
          Send reminder how many days before?
        </label>
        <input type="number" id="reminderDays" min="1" max="30" value="3"
               style="width:120px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:700;color:var(--navy);">
        <span style="font-size:12px;color:#94a3b8;margin-left:8px;">day(s) before appointment</span>
      </div>

      <!-- Subject -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">Email Subject</label>
        <input type="text" id="reminderSubject"
               style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      </div>

      <!-- Heading -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">Email Heading</label>
        <input type="text" id="reminderHeading"
               style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      </div>

      <!-- Message body -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">
          Message Body
          <span style="font-weight:500;color:#94a3b8;font-size:11px;">(use placeholders below)</span>
        </label>
        <textarea id="reminderBody" rows="7"
                  style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>

        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
          <span style="font-size:10.5px;color:#94a3b8;font-weight:700;align-self:center;">Insert:</span>
          <button type="button" class="tag-btn" onclick="insertTag('{name}')">{name}</button>
          <button type="button" class="tag-btn" onclick="insertTag('{date}')">{date}</button>
          <button type="button" class="tag-btn" onclick="insertTag('{time}')">{time}</button>
          <button type="button" class="tag-btn" onclick="insertTag('{service}')">{service}</button>
          <button type="button" class="tag-btn" onclick="insertTag('{days}')">{days}</button>
          <button type="button" class="tag-btn" onclick="insertTag('{clinic}')">{clinic}</button>
        </div>
      </div>

      <!-- Button text -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">Button Text</label>
        <input type="text" id="reminderButton"
               style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      </div>

      <!-- Footer -->
      <div style="margin-bottom:8px;">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">Footer Note</label>
        <input type="text" id="reminderFooter"
               style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      </div>

    </div>

    <div class="modal-footer" style="justify-content:space-between;">
      <button type="button" class="btn-xs btn-view" onclick="sendRemindersNow()">
        <i class="fas fa-paper-plane"></i> Send Now
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn-xs btn-view" onclick="closeReminderSettings()">Cancel</button>
        <button type="button" class="btn-xs btn-approve" onclick="saveReminderSettings()">
          <i class="fas fa-save"></i> Save Settings
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============ STYLES ============ -->
<style>
.switch { position: relative; display: inline-block; width: 50px; height: 26px; flex-shrink:0; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; inset: 0;
    background-color: #cbd5e1; transition: .3s; border-radius: 26px;
}
.slider:before {
    position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px;
    background-color: white; transition: .3s; border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.switch input:checked + .slider { background-color: #0d9e8c; }
.switch input:checked + .slider:before { transform: translateX(24px); }

.tag-btn {
    font-size: 11px; padding: 4px 10px; border-radius: 6px;
    background: #e6f7f5; color: #0a7d6e; border: 1px solid #b2e5df;
    cursor: pointer; font-weight: 700; font-family: monospace;
}
.tag-btn:hover { background: #0d9e8c; color: white; }
</style>

<!-- ============ SCRIPT ============ -->
<script>
function openReminderSettings() {
    document.getElementById('reminderSettingsModal').classList.add('open');

    // Show loading
    Swal.fire({ title: 'Loading settings...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('../../api/reminder_settings.php')
        .then(r => r.json())
        .then(data => {
            Swal.close();
            if (!data.success || !data.settings) {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Could not load settings.' });
                return;
            }
            const s = data.settings;
            document.getElementById('reminderEnabled').checked = parseInt(s.is_enabled) === 1;
            document.getElementById('reminderDays').value      = s.days_before;
            document.getElementById('reminderSubject').value   = s.subject;
            document.getElementById('reminderHeading').value   = s.heading;
            document.getElementById('reminderBody').value      = s.message_body;
            document.getElementById('reminderButton').value    = s.button_text;
            document.getElementById('reminderFooter').value    = s.footer_note;
        })
        .catch(err => {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: err.message });
        });
}

function closeReminderSettings() {
    document.getElementById('reminderSettingsModal').classList.remove('open');
}

function insertTag(tag) {
    const ta = document.getElementById('reminderBody');
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    ta.value = ta.value.substring(0, start) + tag + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + tag.length;
    ta.focus();
}

function saveReminderSettings() {
    const payload = {
        is_enabled:   document.getElementById('reminderEnabled').checked ? 1 : 0,
        days_before:  parseInt(document.getElementById('reminderDays').value) || 3,
        subject:      document.getElementById('reminderSubject').value.trim(),
        heading:      document.getElementById('reminderHeading').value.trim(),
        message_body: document.getElementById('reminderBody').value.trim(),
        button_text:  document.getElementById('reminderButton').value.trim(),
        footer_note:  document.getElementById('reminderFooter').value.trim()
    };

    if (!payload.subject || !payload.heading || !payload.message_body) {
        Swal.fire({ icon: 'warning', title: 'Missing fields', text: 'Subject, heading, and message body are required.' });
        return;
    }

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('../../api/reminder_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, timer: 1400, showConfirmButton: false });
            closeReminderSettings();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Error', text: err.message });
    });
}

function sendRemindersNow() {
    Swal.fire({
        title: 'Send reminders now?',
        text: 'This will email all patients whose appointment is within the selected timeframe.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, send',
        reverseButtons: true
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Sending...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('../../cron/send_appointment_reminders.php?key=CHANGE_THIS_TO_A_LONG_RANDOM_STRING_1234567890')
            .then(res => res.text())
            .then(text => {
                Swal.fire({
                    icon: 'success',
                    title: 'Done',
                    html: '<pre style="text-align:left;font-size:12px;max-height:300px;overflow:auto;background:#f7f9fc;padding:10px;border-radius:8px;">' +
                          text.replace(/</g, '&lt;') + '</pre>'
                });
            })
            .catch(err => Swal.fire({ icon: 'error', title: 'Error', text: err.message }));
    });
}

// Close modal when clicking overlay
document.getElementById('reminderSettingsModal').addEventListener('click', function(e) {
    if (e.target === this) closeReminderSettings();
});
</script>
                    <button onclick="showBulkNotifyModal()" class="btn-outline">
                        <i class="fas fa-paper-plane"></i> Send Reminders
                    </button>
                    <button onclick="showCreateModal()" class="btn-primary">
                        <i class="fas fa-plus-circle"></i> New Appointment
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4 mb-8">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statTotal"><?= $total_all ?></p>
                            <p class="text-xs text-gray-500 mt-1">all appointments</p>
                        </div>
                        <div class="stat-icon bg-teal-light text-teal">
                            <i class="fas fa-calendar"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('confirmed')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Arrived</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statConfirmed"><?= $stats['confirmed'] ?></p>
                            <p class="text-xs text-teal font-medium mt-1"><i class="fas fa-map-marker-alt mr-1"></i> at clinic</p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="filterByStatus('approved')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Approved</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statApproved"><?= $stats['approved'] ?? 0 ?></p>
                            <p class="text-xs text-blue-600 font-medium mt-1"><i class="fas fa-check-circle mr-1"></i> approved</p>
                        </div>
                        <div class="stat-icon bg-blue-50 text-blue-600">
                            <i class="fas fa-check-double"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="filterByStatus('pending')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Pending</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statPending"><?= $stats['pending'] ?></p>
                            <p class="text-xs text-amber-600 font-medium mt-1">needs review</p>
                        </div>
                        <div class="stat-icon bg-amber-50 text-amber-600">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="filterByStatus('completed')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Completed</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statCompleted"><?= $stats['completed'] ?? 0 ?></p>
                            <p class="text-xs text-green-600 font-medium mt-1">finished treatments</p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-circle-check"></i></div>
                    </div>
                </div>

                <div class="stat-card" onclick="filterByStatus('cancelled')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Cancelled</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statCancelled"><?= $stats['cancelled'] ?></p>
                            <p class="text-xs text-red-500 font-medium mt-1">cancelled</p>
                        </div>
                        <div class="stat-icon bg-red-50 text-red-500">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card" onclick="filterByStatus('rescheduled')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Rescheduled</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statRescheduled"><?= $stats['rescheduled'] ?></p>
                            <p class="text-xs text-purple-600 font-medium mt-1"><i class="fas fa-calendar-alt mr-1"></i> moved</p>
                        </div>
                        <div class="stat-icon bg-purple-50 text-purple-600">
                            <i class="fas fa-calendar-alt"></i>
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
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Arrive at Clinic</option>
                        <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="rescheduled" <?= $status_filter === 'rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                    </select>
                </div>
                <div class="filter-group flex-1 min-w-[200px]">
                    <label for="searchInput" class="sr-only">Search</label>
                    <div class="relative w-full search-loading" id="searchContainer">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="searchInput" 
                               placeholder="Search name, phone, email, or service..."
                               class="w-full pr-10 py-2" style="padding-left:40px"
                               value="<?= htmlspecialchars($search) ?>"
                               autocomplete="off">
                        <div class="search-loader">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                    </div>
                </div>
                <span class="text-xs text-gray-400 ml-auto hidden lg:inline">
                    <i class="far fa-calendar-alt mr-1"></i> <?= date('F d, Y h:i A') ?>
                </span>
            </div>

            <!-- Appointments Table -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span><i class="fas fa-list-ul mr-2 text-teal"></i> Appointment List</span>
                    <span class="text-xs text-gray-400 bg-gray-50 px-3 py-1 rounded-full" id="recordCount">
                        <?= $total ?> of <?= $total_records ?> records
                    </span>
                </div>

                <div class="table-container">
                    <table class="appointments-table">
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Patient Contact</th>
                                <th>Service</th>
                                <th>Verified</th>
                                <th>Status</th>
                                <th>Appointment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="appointmentsBody">
                            <?php if ($total > 0): ?>
                            <?php foreach ($appointments as $appt):
                                $service_info = resolve_services($appt['services'], $service_lookup);
                            ?>
                            <tr id="row-<?= $appt['id'] ?>" 
                                onclick="window.location.href='appointment_details.php?id=<?= $appt['id'] ?>'" 
                                style="cursor: pointer; transition: background 0.2s;"
                                onmouseover="this.style.backgroundColor='#f8fafc'" 
                                onmouseout="this.style.backgroundColor=''">
                                
                                <td class="font-semibold text-dark-blue">#<?= $appt['id'] ?></td>
                                <td>
                                    <div class="text-sm font-medium"><?= htmlspecialchars($appt['phone']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($appt['email']) ?></div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1 items-center" style="max-width:220px;">
                                        <?php foreach ($service_info['titles'] as $t): ?>
                                        <span class="service-tag"><?= htmlspecialchars($t) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($service_info['total'] > 0): ?>
                                    <div class="text-xs text-gray-400 mt-1">₱<?= number_format($service_info['total'], 2) ?> total</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="clickable-status" onclick="event.stopPropagation(); toggleVerification(<?= $appt['id'] ?>, <?= $appt['is_verified'] ? 0 : 1 ?>)">
                                        <?php if ($appt['is_verified']): ?>
                                            <span class="status-badge badge-yes" style="background:#d4edda;color:#155724;">
                                                <i class="fas fa-check-circle mr-1"></i> Yes
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge badge-no" style="background:#f8d7da;color:#721c24;">
                                                <i class="fas fa-circle mr-1"></i> No
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($appt['status'] === 'completed'): ?>
                                        <span class="status-badge" style="background:#dcfce7;color:#166534;white-space:nowrap"><i class="fas fa-check-circle"></i> Completed</span>
                                        <?php if (!empty($appt['treatment_completed_at'])): ?>
                                            <div class="text-xs text-gray-400 mt-1">Finished <?= htmlspecialchars(date('M d, Y g:i A', strtotime($appt['treatment_completed_at']))) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <select class="status-dropdown" onclick="event.stopPropagation();" onchange="updateStatus(<?= $appt['id'] ?>, this.value, this)">
                                        <option value="pending" <?= $appt['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                                        <option value="approved" <?= $appt['status'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                                        <?php if ($appt['status'] === 'approved'): ?>
                                            <option value="confirmed">Confirm Patient Arrival</option>
                                        <?php endif; ?>
                                        <?php if ($appt['status'] === 'confirmed'): ?>
                                            <option value="confirmed" selected disabled>Arrived at Clinic</option>
                                        <?php endif; ?>
                                        <?php if ($appt['status'] === 'in_progress'): ?>
                                            <option value="in_progress" selected disabled>In Progress</option>
                                        <?php endif; ?>
                                        <option value="cancelled" <?= $appt['status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                    </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-xs">
                                        <div class="font-medium"><?= $appt['appointment_date'] ? date('M d, Y', strtotime($appt['appointment_date'])) : '—' ?></div>
                                        <div class="text-gray-400"><?= $appt['appointment_time'] ? date('g:i A', strtotime($appt['appointment_time'])) : '' ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-icons flex items-center justify-center gap-0" onclick="event.stopPropagation();">
                                        <button onclick="viewAppointment(<?= $appt['id'] ?>)" title="View Details" class="hover:bg-teal-light">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="editAppointment(<?= $appt['id'] ?>)" title="Edit Appointment" class="hover:bg-teal-light">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button onclick="rescheduleAppointment(<?= $appt['id'] ?>, '<?= $appt['appointment_date'] ?>', '<?= $appt['appointment_time'] ?>')" title="Reschedule" class="hover:bg-teal-light">
                                            <i class="fas fa-calendar-alt"></i>
                                        </button>
                                        <button onclick="deleteAppointment(<?= $appt['id'] ?>)" class="text-danger hover:bg-red-50" title="Delete Appointment">
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
                                        <i class="fas fa-calendar-times"></i>
                                        <h3>No appointments found</h3>
                                        <p>Try adjusting your filters or create a new appointment.</p>
                                        <button onclick="showCreateModal()" class="btn-primary mt-4 inline-flex">
                                            <i class="fas fa-plus-circle"></i> Create Appointment
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

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

            <div class="mt-8 text-center text-xs text-gray-400 border-t border-gray-200 pt-5">
                <i class="fas fa-tooth mr-1 text-teal/50"></i>
                Wansi Calacat Dental Clinic · Appointments Management 
            </div>
        </div>
    </main>
</div>

<!-- Appointment Modal -->
<div id="apptModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); border-radius: 18px 18px 0 0; padding: 20px 24px;">
            <h3 id="apptModalTitle" style="color: white; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-calendar-plus" style="color: #0d9e8c;"></i>
                New Appointment
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeApptModal()" style="color: rgba(255,255,255,0.6); font-size: 24px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body" style="padding: 24px 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc;">
            <form id="apptForm" onsubmit="return false;">
                <input type="hidden" id="apptId" value="">
                <input type="hidden" id="apptPatientId" value="">
                <section id="apptPatientChooser" class="patient-chooser" aria-label="Patient selection">
                    <div class="patient-chooser-title"><i class="fas fa-users" aria-hidden="true"></i> Patient Selection</div>
                    <div class="patient-mode-toggle" role="group" aria-label="Select patient type">
                        <label><input type="radio" name="patientMode" value="existing"> Existing Patient</label>
                        <label><input type="radio" name="patientMode" value="new" checked> New Patient</label>
                    </div>
                    <div id="patientSearchArea" hidden>
                        <label for="existingPatientSearch" class="patient-search-label">Search Registered Patient</label>
                        <div class="patient-search-wrap">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" id="existingPatientSearch" autocomplete="off"
                                role="combobox" aria-autocomplete="list" aria-controls="patientSearchResults"
                                aria-expanded="false" placeholder="Search by patient name, email, phone number, or patient ID...">
                            <span id="patientSearchSpinner" hidden aria-label="Searching"><i class="fas fa-spinner fa-spin"></i></span>
                            <div id="patientSearchResults" role="listbox" aria-label="Matching patients" hidden></div>
                        </div>
                        <p id="patientSearchMessage" class="patient-chooser-hint" role="status" aria-live="polite">Type a name, email, phone number or patient ID.</p>
                        <div id="patientSelectedBadge" class="selected-patient-badge" hidden>
                            <span id="patientSelectedLabel"></span>
                            <button type="button" id="patientChangeButton">Change Patient</button>
                            <button type="button" id="patientClearButton">Clear Selection</button>
                            <a id="patientEditLink" href="patients.php" target="_blank" rel="noopener noreferrer">Edit in Patients</a>
                        </div>
                        <button type="button" id="patientCreateNewButton" class="patient-create-new" hidden>Create New Patient</button>
                    </div>
                </section>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-user-circle" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Personal Information</h4>
                    </div>
                    
                    <div class="form-field" style="margin-bottom: 14px;">
                        <label for="apptFullName">Full Name</label>
                        <input type="text" id="apptFullName" placeholder="Enter patient's full name">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptAge">Age</label>
                            <input type="number" id="apptAge" placeholder="Age">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptOccupation">Occupation</label>
                            <input type="text" id="apptOccupation" placeholder="Occupation">
                        </div>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-address-card" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Contact Information</h4>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptPhone">Phone Number</label>
                            <input type="text" id="apptPhone" placeholder="09xx xxx xxxx">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptEmail">Email Address</label>
                            <input type="email" id="apptEmail" placeholder="patient@email.com">
                        </div>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-map-marker-alt" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Address</h4>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptRegion">Region</label>
                            <input type="text" id="apptRegion" list="apptRegionOptions" autocomplete="off" placeholder="Select or type region"><datalist id="apptRegionOptions"></datalist>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptProvince">Province</label>
                            <input type="text" id="apptProvince" list="apptProvinceOptions" autocomplete="off" placeholder="Select or type province"><datalist id="apptProvinceOptions"></datalist>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptCity">City/Municipality</label>
                            <input type="text" id="apptCity" list="apptCityOptions" autocomplete="off" placeholder="Select or type city/municipality"><datalist id="apptCityOptions"></datalist>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptBarangay">Barangay</label>
                            <input type="text" id="apptBarangay" list="apptBarangayOptions" autocomplete="off" placeholder="Select or type barangay"><datalist id="apptBarangayOptions"></datalist>
                        </div>
                    </div>
                    <div class="form-field" id="patientStoredAddressBlock" hidden style="margin-top:14px; margin-bottom:14px;">
                        <label for="patientStoredAddress">Saved Patient Address (view only)</label>
                        <textarea id="patientStoredAddress" rows="2" readonly style="width:100%; border:1px solid #ccd5df; border-radius:9px; padding:9px; background:#f1f5f9;"></textarea>
                        <small>The clinic's original address is preserved. To correct it, use Edit in Patients.</small>
                    </div>
                    <div class="form-field" style="margin-top: 14px; margin-bottom: 0;">
                        <label for="apptStreetAddress">Street Address</label>
                        <input type="text" id="apptStreetAddress" placeholder="Street address, building, etc.">
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-clock" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Appointment Schedule</h4>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptDate">Date</label>
                            <input type="date" id="apptDate">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptTime">Time</label>
                            <input type="time" id="apptTime">
                        </div>
                    </div>
                </div>

                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-file-medical" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Medical & Dental History</h4>
                        <span style="margin-left: auto; font-size: 10px; color: #94a3b8; background: #f1f4f8; padding: 2px 10px; border-radius: 12px; font-weight: 600;">Optional</span>
                    </div>
                    
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Medical Conditions</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="medical-checkbox" value="under_physician_care" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Under physician's care</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="medical-checkbox" value="pregnant" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Pregnant</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="medical-checkbox" value="taking_medicine" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Taking medicine</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="medical-checkbox" value="hospitalized" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Hospitalized before</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="medical-checkbox" value="worn_denture" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Worn denture</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptPhysicianName">Physician Name</label>
                        <input type="text" id="apptPhysicianName" placeholder="Enter physician's name">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptMedicineDetails">Medicine Details</label>
                        <input type="text" id="apptMedicineDetails" placeholder="List any medicines being taken">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptConditions">Medical Conditions Details</label>
                        <input type="text" id="apptConditions" placeholder="Describe any medical conditions">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptOtherConditionDetails">Other Condition Details</label>
                        <input type="text" id="apptOtherConditionDetails" placeholder="Any other conditions not listed">
                    </div>
                    
                    <div style="margin: 14px 0 12px 0; border-top: 2px solid #f1f4f8; padding-top: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Allergies</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="allergy-checkbox" value="allergic_local_anesthesia" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Local Anesthesia</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="allergy-checkbox" value="allergic_antibiotics" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Antibiotics</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="allergy-checkbox" value="allergic_pain_killer" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Pain Killers</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px;">
                                <input type="checkbox" class="allergy-checkbox" value="allergic_other" style="accent-color: #0d9e8c; width: 16px; height: 16px;">
                                <span>Other Allergies</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptAllergicOtherDetails">Other Allergy Details</label>
                        <input type="text" id="apptAllergicOtherDetails" placeholder="Describe other allergies">
                    </div>
                    
                    <div style="margin: 14px 0 12px 0; border-top: 2px solid #f1f4f8; padding-top: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Hospitalization Details</label>
                        <div class="form-field" style="margin-bottom: 12px;">
                            <label for="apptHospitalizedWhen">When were you hospitalized?</label>
                            <input type="text" id="apptHospitalizedWhen" placeholder="e.g., Last year, 2022, etc.">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptHospitalizedWhy">Reason for hospitalization</label>
                            <input type="text" id="apptHospitalizedWhy" placeholder="Reason for hospitalization">
                        </div>
                    </div>
                    
                    <div style="margin: 14px 0 0 0; border-top: 2px solid #f1f4f8; padding-top: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Dental History</label>
                        <div class="form-field" style="margin-bottom: 12px;">
                            <label for="apptLastDentalVisit">Last Dental Visit</label>
                            <input type="text" id="apptLastDentalVisit" placeholder="When was your last dental visit?">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptLastDentalVisitReason">Reason for Last Visit</label>
                            <input type="text" id="apptLastDentalVisitReason" placeholder="Why did you visit the dentist last time?">
                        </div>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-tooth" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Dental Services</h4>
                        <span style="margin-left: auto; font-size: 10px; color: #94a3b8; background: #f1f4f8; padding: 2px 10px; border-radius: 12px; font-weight: 600;">Select all that apply</span>
                    </div>
                    <div id="apptServices" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; border: 2px solid #e8edf4; border-radius: 10px; padding: 12px 16px; max-height: 180px; overflow-y: auto; background: #fafbfc;">
                        <?php foreach ($services as $s): ?>
                        <label class="flex items-center gap-2 py-1.5 px-1 text-sm" style="cursor:pointer; border-radius: 6px;">
                            <input type="checkbox" class="appt-service-checkbox" value="<?= (int)$s['id'] ?>" style="width: 16px; height: 16px; accent-color: #0d9e8c; cursor: pointer;">
                            <span style="font-weight: 500; color: #0b1628;"><?= htmlspecialchars($s['title']) ?></span>
                            <span style="font-size: 11px; color: #94a3b8; margin-left: auto;">₱<?= number_format($s['price'], 2) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <i class="fas fa-sticky-note" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; margin: 0;">Additional Notes</h4>
                    </div>
                    <div class="form-field" style="margin-bottom: 0;">
                        <textarea id="apptNotes" rows="3" placeholder="Any additional information about the appointment..." style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; background: white; resize: vertical; min-height: 60px;"></textarea>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptStatus">Status</label>
                            <select id="apptStatus" style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; background: white; font-weight: 500; cursor: pointer;">
                                <option value="pending">🟡 Pending</option>
                                <option value="approved">🔵 Approved</option>
                                <option value="confirmed" style="display:none;">🟢 Arrive at Clinic</option>
                                <option value="in_progress" style="display:none;">🟣 In Progress</option>
                                <option value="cancelled">🔴 Cancelled</option>
                            </select>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptVerified">Verification</label>
                            <select id="apptVerified" style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; background: white; font-weight: 500; cursor: pointer;">
                                <option value="0">❌ Not Verified</option>
                                <option value="1">✅ Verified</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div id="apptViewMeta" class="view-meta hidden"></div>
            </form>
        </div>
        
        <div class="modal-footer" style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 16px 24px; background: white; border-top: 1px solid #eef2f6; border-radius: 0 0 18px 18px;">
            <button type="button" class="btn-outline" onclick="closeApptModal()" style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white; cursor: pointer;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="apptSaveBtn" class="btn-primary" onclick="saveAppointment()" style="padding: 10px 26px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(13,158,140,0.3); display: inline-flex; align-items: center; gap: 8px;">
                <i class="fas fa-save"></i> Save Appointment
            </button>
        </div>
    </div>
</div>

<!-- ARRIVAL MODAL -->
<div id="arrivalModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 420px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%);">
            <h3 style="color: white; display: flex; align-items: center; gap: 10px; margin: 0;">
                <i class="fas fa-user-check"></i>
                Confirm Patient Arrival
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeArrivalModal()" style="color: rgba(255,255,255,0.8);">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 24px; text-align: center;">
            <div style="width: 72px; height: 72px; background: #e6f7f5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <i class="fas fa-map-marker-alt" style="font-size: 32px; color: #0d9e8c;"></i>
            </div>
            <h4 style="font-size: 16px; font-weight: 700; color: #0b1628; margin-bottom: 8px;">Confirm Arrival</h4>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">
                Are you sure this patient has arrived at the clinic?<br>
                This will record the current time.
            </p>
            <div style="background: #f8fafc; border-radius: 10px; padding: 12px; margin-bottom: 20px;">
                <p style="font-size: 12px; color: #94a3b8; margin-bottom: 4px;">Current Time</p>
                <p id="arrivalCurrentTime" style="font-size: 20px; font-weight: 700; color: #0b1628; margin: 0;"></p>
            </div>
        </div>
        <div class="modal-footer" style="justify-content: center;">
            <button type="button" class="btn-outline" onclick="closeArrivalModal()" style="padding: 10px 24px;">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitArrival()" style="padding: 10px 28px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%);">
                <i class="fas fa-check-circle"></i> Yes, Confirm Arrival
            </button>
        </div>
    </div>
</div>

<!-- RESCHEDULE MODAL -->
<div id="rescheduleModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 420px;">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-alt" style="color:#0d9e8c;"></i> Reschedule Appointment</h3>
            <button type="button" class="modal-close-btn" onclick="closeRescheduleModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="reschId">
            <div class="form-field">
                <label>Current Schedule</label>
                <div id="reschCurrent" style="font-size:13px;color:#64748b;padding:8px 12px;background:#f8fafc;border-radius:8px;"></div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label for="reschDate">New Date</label>
                    <input type="date" id="reschDate">
                </div>
                <div class="form-field">
                    <label for="reschTime">New Time</label>
                    <input type="time" id="reschTime">
                </div>
            </div>
            <div class="form-field">
                <label for="reschReason">Reason (optional)</label>
                <input type="text" id="reschReason" placeholder="e.g. Patient requested, dentist unavailable...">
            </div>
            <div style="background:#e6f7f5;border-radius:8px;padding:10px 12px;margin-top:8px;">
                <p style="font-size:12px;color:#0a7d6e;margin:0;">
                    <i class="fas fa-envelope" style="margin-right:4px;"></i>
                    The patient will be notified by email automatically.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeRescheduleModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitReschedule()"><i class="fas fa-check"></i> Confirm Reschedule</button>
        </div>
    </div>
</div>

<!-- DETAIL MODAL -->
<div id="apptDetailModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 680px; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 60px rgba(11,22,40,0.35);">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); padding: 20px 28px; border-bottom: none; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 44px; height: 44px; background: rgba(13,158,140,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-calendar-check" style="color: #0d9e8c; font-size: 20px;"></i>
                </div>
                <div>
                    <h3 id="apptDetailTitle" style="color: white; font-size: 18px; font-weight: 700; margin: 0; font-family: 'Playfair Display', serif;">Appointment Details</h3>
                    <p style="color: rgba(255,255,255,0.5); font-size: 12px; margin: 2px 0 0 0;">
                        <i class="fas fa-id-badge" style="margin-right: 4px;"></i> 
                        <span id="apptDetailId">#---</span>
                    </p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeDetailModal()" style="width: 36px; height: 36px; border-radius: 10px; border: none; background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.6); font-size: 18px; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body" style="padding: 0; max-height: 70vh; overflow-y: auto; background: #f7f9fc;">
            <div id="apptDetailContent" style="padding: 24px 28px;">
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 0;">
                    <div style="width: 48px; height: 48px; border: 3px solid #e8edf4; border-top-color: #0d9e8c; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                    <p style="color: #94a3b8; margin-top: 16px; font-size: 14px; font-weight: 500;">Loading appointment details...</p>
                </div>
            </div>
        </div>
        
        <div class="modal-footer" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 28px; background: white; border-top: 1px solid #eef2f6;">
            <div style="display: flex; gap: 8px;">
                <span id="apptDetailStatus" style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #eef2f6; color: #64748b;">
                    <i class="fas fa-circle" style="font-size: 6px;"></i> Loading...
                </span>
                <span id="apptDetailVerified" style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #eef2f6; color: #64748b;">
                    <i class="fas fa-shield-alt"></i> ---
                </span>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-outline" onclick="closeDetailModal()" style="padding: 9px 20px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white; cursor: pointer;">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn-primary" onclick="editFromDetail()" style="padding: 9px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(13,158,140,0.25);">
                    <i class="fas fa-pen"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes spin { to { transform: rotate(360deg); } }
    .detail-section { background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid #f0f3f7; }
    .detail-section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #f1f4f8; }
    .detail-section-header i { color: #0d9e8c; font-size: 16px; width: 20px; }
    .detail-section-header h4 { font-size: 12px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
    .detail-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 6px 0; border-bottom: 1px dashed #f1f4f8; }
    .detail-item:last-child { border-bottom: none; }
    .detail-label { font-size: 12px; color: #94a3b8; font-weight: 500; min-width: 100px; }
    .detail-value { font-size: 13px; color: #0b1628; font-weight: 600; text-align: right; flex: 1; margin-left: 16px; word-break: break-word; }
    .detail-value.empty { color: #cbd5e1; font-weight: 400; font-style: italic; }
    .detail-tag { display: inline-block; background: #e6f7f5; color: #0a7d6e; padding: 4px 14px; border-radius: 12px; font-size: 12px; font-weight: 600; margin: 2px 4px 2px 0; }
    .status-badge-detail { display: inline-flex; align-items: center; gap: 6px; padding: 4px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .status-badge-detail.confirmed { background: #d4edda; color: #155724; }
    .status-badge-detail.approved { background: #d1e7ff; color: #084298; }
    .status-badge-detail.pending { background: #fef3d7; color: #b45309; }
    .status-badge-detail.in_progress { background: #ede9fe; color: #6d28d9; }
    .status-badge-detail.cancelled { background: #fee9e7; color: #b91c1c; }
    .verified-badge-detail { display: inline-flex; align-items: center; gap: 6px; padding: 4px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .verified-badge-detail.yes { background: #d4edda; color: #155724; }
    .verified-badge-detail.no { background: #f8d7da; color: #721c24; }
    .address-line { display: block; padding: 2px 0; font-size: 13px; color: #0b1628; }
    .address-line:not(:last-child) { border-bottom: 1px dashed #f1f4f8; padding-bottom: 4px; margin-bottom: 4px; }
    .notes-box { background: #f8fafc; border-radius: 10px; padding: 14px 16px; border-left: 3px solid #0d9e8c; font-size: 13px; color: #334155; line-height: 1.6; margin-top: 4px; }
    .notes-box.empty { color: #cbd5e1; font-style: italic; border-left-color: #e2e8f0; }
    .arrival-highlight { background: #e6f7f5; margin-top: 8px; padding: 10px 12px; border-radius: 8px; border-bottom: none; }
    .arrival-highlight .detail-label { color: #0d7a6e; font-weight: 700; }
    .arrival-highlight .detail-value { color: #0d7a6e; font-weight: 700; }
    .resched-highlight { background: #fef3d7; margin-top: 8px; padding: 10px 12px; border-radius: 8px; border-bottom: none; }
    .resched-highlight .detail-label { color: #b45309; font-weight: 700; }
    .resched-highlight .detail-value { color: #b45309; font-weight: 700; }
</style>

<!-- SMART BULK REMINDER CENTER -->
<style>
.remind-modal {max-width:850px;width:min(850px,95vw)}
.remind-panel{border:1px solid #e5edf2;border-radius:14px;background:#f8fafc;padding:16px;margin:12px 0}
.remind-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.remind-grid input,.remind-filter,.remind-note{border:1px solid #cbd5e1;border-radius:9px;padding:10px 12px;width:100%;background:#fff;font-size:13px}
.remind-row{display:grid;grid-template-columns:24px minmax(140px,1fr) minmax(175px,1fr) 95px;gap:12px;align-items:center;padding:13px 14px;border-bottom:1px solid #e8edf3;font-size:12px}
.remind-row:last-child{border-bottom:0}.remind-row.disabled{opacity:.58}.remind-tag{font-size:10px;font-weight:700;border-radius:20px;padding:4px 8px;background:#e6f7f5;color:#087b70;display:inline-block}
.remind-row.disabled .remind-tag{background:#eef2f6;color:#64748b}.remind-muted{color:#64748b;font-size:11px;margin-top:3px;overflow-wrap:anywhere}
.remind-stats{display:flex;gap:9px;flex-wrap:wrap}.remind-stats span{background:#eff6f8;border-radius:8px;padding:7px 11px;font-size:12px;color:#334155;font-weight:600}
@media(max-width:630px){.remind-grid{grid-template-columns:1fr}.remind-row{grid-template-columns:24px 1fr;gap:6px}.remind-row>div:nth-child(3),.remind-row>div:nth-child(4){grid-column:2}}
</style>
<div id="bulkNotifyModal" class="modal-overlay">
  <div class="modal-box remind-modal">
    <div class="modal-header"><h3><i class="fas fa-envelope-open-text text-teal mr-2"></i>Appointment Reminder Center</h3>
      <button type="button" class="modal-close-btn" onclick="closeBulkNotifyModal()" aria-label="Close"><i class="fas fa-times"></i></button></div>
    <div class="modal-body" style="max-height:73vh;overflow-y:auto">
      <p style="font-size:12px;color:#64748b;margin-bottom:10px">Reminders are sent by email for <b>approved, upcoming</b> appointments only. Completed, checked-in, cancelled, pending, and previously reminded bookings are not selectable.</p>
      <div class="remind-panel"><div class="remind-grid">
        <label style="font-size:12px;font-weight:700">From date<input type="date" id="bulkFromDate" aria-label="From date"></label>
        <label style="font-size:12px;font-weight:700">To date<input type="date" id="bulkToDate" aria-label="To date"></label>
      </div><button type="button" class="btn-primary" id="bulkLoadBtn" onclick="loadBulkRecipients()" style="margin-top:12px"><i class="fas fa-search"></i> Find appointments</button></div>
      <div id="bulkStage2" style="display:none">
        <div class="remind-stats" style="margin-bottom:10px"><span id="remindEligible">0 need reminders</span><span id="remindSent">0 already reminded</span><span id="remindOthers">0 not eligible</span></div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:9px">
          <input id="remindSearch" type="search" class="remind-filter" placeholder="Search patient, email or appointment #" oninput="renderBulkRecipients()" style="flex:1;min-width:185px">
          <select id="remindStatusFilter" class="remind-filter" onchange="renderBulkRecipients()" style="width:auto"><option value="all">All</option><option value="eligible">Needs reminder</option><option value="sent">Already reminded</option><option value="others">Not eligible</option></select>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:9px;margin-bottom:8px;flex-wrap:wrap"><strong id="bulkRecipientCount" style="font-size:12px">0 selected</strong><div><button type="button" class="btn-outline" onclick="bulkSelectAll(true)">Select eligible</button> <button type="button" class="btn-outline" onclick="bulkSelectAll(false)">Clear</button></div></div>
        <div id="bulkRecipientList" style="max-height:285px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:11px;background:white" aria-live="polite"></div>
        <label style="font-size:12px;font-weight:700;display:block;margin-top:13px">Optional extra note for patients</label>
        <textarea id="bulkNotifyMessage" class="remind-note" maxlength="1000" rows="2" placeholder="Example: Please arrive 10 minutes early. The standard appointment details are included automatically."></textarea>
        <p class="remind-muted">Please review the selected patients before sending. The email provider's success response does not guarantee inbox delivery.</p>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn-outline" onclick="closeBulkNotifyModal()">Close</button><button type="button" id="bulkSendBtn" class="btn-primary" onclick="sendBulkNotify()" style="display:none"><i class="fas fa-paper-plane"></i> Review & Send</button></div>
  </div>
</div>
<script>
let bulkRecipients = [];
let bulkSelected = new Set();
let bulkBusy = false;
const bulkEscape = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function showBulkNotifyModal() {
  const today = new Date(); const ph = new Date(today.toLocaleString('en-US',{timeZone:'Asia/Manila'}));
  const fmt = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  document.getElementById('bulkFromDate').value=fmt(ph);ph.setDate(ph.getDate()+7);
  document.getElementById('bulkToDate').value=fmt(ph);
  document.getElementById('bulkStage2').style.display='none';
  document.getElementById('bulkSendBtn').style.display='none';
  document.getElementById('bulkNotifyModal').classList.add('open');
}
function closeBulkNotifyModal(){if(!bulkBusy)document.getElementById('bulkNotifyModal').classList.remove('open')}
async function loadBulkRecipients(){
  if(bulkBusy)return;
  const from=document.getElementById('bulkFromDate').value,to=document.getElementById('bulkToDate').value;
  if(!from||!to||to<from){Swal.fire({icon:'warning',text:'Choose a valid date range.'});return;}
  bulkBusy=true;const btn=document.getElementById('bulkLoadBtn');btn.disabled=true;
  const form=new FormData();form.append('action','bulk_list');form.append('date_from',from);form.append('date_to',to);
  try{
    const res=await fetch('appointments.php?ajax=1',{method:'POST',body:form});const data=await res.json();
    if(!data.success)throw Error(data.message||'Could not load patients');
    bulkRecipients=data.recipients||[];bulkSelected=new Set(bulkRecipients.filter(r=>r.eligible).slice(0,25).map(r=>Number(r.id)));
    document.getElementById('remindSearch').value='';document.getElementById('remindStatusFilter').value='all';
    document.getElementById('bulkStage2').style.display='block';document.getElementById('bulkSendBtn').style.display='inline-flex';
    document.getElementById('remindEligible').textContent=`${bulkRecipients.filter(r=>r.eligible).length} need reminders`;
    document.getElementById('remindSent').textContent=`${bulkRecipients.filter(r=>Number(r.reminder_sent)===1).length} already reminded`;
    document.getElementById('remindOthers').textContent=`${bulkRecipients.filter(r=>!r.eligible&&Number(r.reminder_sent)!==1).length} not eligible`;
    renderBulkRecipients();
  }catch(e){Swal.fire({icon:'error',title:'Unable to load reminders',text:e.message});}
  finally{bulkBusy=false;btn.disabled=false;}
}
function renderBulkRecipients(){
  const search=document.getElementById('remindSearch').value.toLowerCase().trim(),filter=document.getElementById('remindStatusFilter').value;
  const list=bulkRecipients.filter(r=>{
    if(filter==='eligible'&&!r.eligible)return false;
    if(filter==='sent'&&Number(r.reminder_sent)!==1)return false;
    if(filter==='others'&&(r.eligible||Number(r.reminder_sent)===1))return false;
    return `${r.id} ${r.full_name} ${r.email}`.toLowerCase().includes(search);
  });
  document.getElementById('bulkRecipientList').innerHTML=list.length ? list.map(r=>{
    const id=Number(r.id),date=bulkEscape(r.appointment_date),time=bulkEscape(r.appointment_time?.slice(0,5)||'—');
    return `<label class="remind-row ${r.eligible?'':'disabled'}"><input type="checkbox" class="bulk-recipient-cb" value="${id}" ${r.eligible?'':'disabled'} ${bulkSelected.has(id)?'checked':''} onchange="bulkToggle(${id},this.checked)"><div><b>${bulkEscape(r.full_name||'Unnamed patient')}</b> <span class="remind-muted">#${id}</span><div class="remind-muted">${bulkEscape(r.email||'No email')}</div></div><div><b>${date} · ${time}</b><div class="remind-muted">${bulkEscape(r.status)}</div></div><div><span class="remind-tag">${bulkEscape(r.reason)}</span>${r.reminder_sent_at?`<div class="remind-muted">${bulkEscape(r.reminder_sent_at)}</div>`:''}</div></label>`;
  }).join('') : '<div style="padding:24px;text-align:center;color:#64748b">No appointments match these filters.</div>';
  updateBulkCount();
}
function bulkToggle(id,checked){if(checked)bulkSelected.add(id);else bulkSelected.delete(id);updateBulkCount();}
function bulkSelectAll(yes){bulkSelected=new Set(yes?bulkRecipients.filter(r=>r.eligible).slice(0,25).map(r=>Number(r.id)):[]);renderBulkRecipients();}
function updateBulkCount(){document.getElementById('bulkRecipientCount').textContent=`${bulkSelected.size} patient(s) selected (max 25 per batch)`;document.getElementById('bulkSendBtn').disabled=!bulkSelected.size||bulkBusy;}
async function sendBulkNotify(){
  if(bulkBusy)return;
  const ids=[...bulkSelected];if(!ids.length||ids.length>25){Swal.fire({icon:'warning',text:'Select 1–25 eligible patients for one batch.'});return;}
  const sure=await Swal.fire({title:'Send appointment reminders?',html:`Email reminders will be sent to <b>${ids.length}</b> selected patient(s). Already reminded patients will be skipped.`,icon:'question',showCancelButton:true,confirmButtonText:'Send reminders',confirmButtonColor:'#0d9e8c'});
  if(!sure.isConfirmed)return;
  bulkBusy=true;document.getElementById('bulkSendBtn').disabled=true;
  const form=new FormData();form.append('action','bulk_notify');form.append('csrf_token',STAFF_APPT_CSRF);form.append('recipient_ids',JSON.stringify(ids));form.append('custom_message',document.getElementById('bulkNotifyMessage').value.trim());
  Swal.fire({title:'Sending email reminders…',text:'Please keep this page open.',allowOutsideClick:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
  try{
    const res=await fetch('appointments.php?ajax=1',{method:'POST',body:form});const data=await res.json();
    if(!data.success)throw Error(data.message||'Unable to send reminders');
    const details=(data.results||[]).map(r=>`#${Number(r.id)}: ${bulkEscape(r.outcome)} – ${bulkEscape(r.detail)}`).join('\n');
    await Swal.fire({title:'Reminder results',icon:data.failed?'warning':'success',html:`<p>${Number(data.sent)} sent · ${Number(data.failed)} failed · ${Number(data.skipped)} skipped</p><pre style="text-align:left;white-space:pre-wrap;max-height:200px;overflow:auto;font-size:11px">${details}</pre>`});
    bulkBusy=false; await loadBulkRecipients();
  }catch(e){Swal.fire({icon:'error',title:'Sending problem',text:e.message});}
  finally{bulkBusy=false;updateBulkCount();}
}
</script>

<script>
/* ============ MAIN JS ============ */
let searchTimeout = null;
let currentPage = 1;
let apptMode = 'create';
let currentDetailId = null;
let arrivalApptId = null;
let arrivalSelectElement = null;

function liveSearch(page = 1) {
    const searchValue = document.getElementById('searchInput').value.trim();
    const statusValue = document.getElementById('statusFilter').value;
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');
    
    const formData = new FormData();
    formData.append('action', 'live_search');
    formData.append('search', searchValue);
    formData.append('status', statusValue);
    formData.append('page', page);
    
    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (searchContainer) searchContainer.classList.remove('loading');
        if (data.success) {
            const tbody = document.getElementById('appointmentsBody');
            if (tbody) tbody.innerHTML = data.html;
            const paginationContainer = document.getElementById('paginationContainer');
            if (paginationContainer) paginationContainer.innerHTML = data.pagination;
            const recordCount = document.getElementById('recordCount');
            if (recordCount) recordCount.textContent = data.total + ' records';
            const totalCount = document.getElementById('totalCount');
            if (totalCount && data.stats) totalCount.textContent = data.stats.total;
            if (data.stats) {
                const statTargets = { statTotal: 'total', statPending: 'pending', statApproved: 'approved',
                    statConfirmed: 'confirmed', statCompleted: 'completed',
                    statCancelled: 'cancelled', statRescheduled: 'rescheduled' };
                Object.entries(statTargets).forEach(([elementId, key]) => {
                    const node = document.getElementById(elementId);
                    if (node) node.textContent = data.stats[key] ?? 0;
                });
            }
            currentPage = page;
            const url = new URL(window.location.href);
            if (searchValue) url.searchParams.set('search', searchValue);
            else url.searchParams.delete('search');
            if (statusValue && statusValue !== 'all') url.searchParams.set('status', statusValue);
            else url.searchParams.delete('status');
            url.searchParams.set('page', page);
            window.history.pushState({}, '', url);
        }
    })
    .catch(error => {
        if (searchContainer) searchContainer.classList.remove('loading');
        console.error('Live search error:', error);
    });
}

function debouncedSearch() {
    clearTimeout(searchTimeout);
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');
    searchTimeout = setTimeout(() => { liveSearch(1); }, 400);
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
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimeout); liveSearch(1); }
        });
    }
    if (statusFilter) statusFilter.addEventListener('change', function(e) { handleStatusChange(); });
    
    const apptModal = document.getElementById('apptModal');
    if (apptModal) apptModal.addEventListener('click', function(e) { if (e.target === this) closeApptModal(); });
    const arrivalModal = document.getElementById('arrivalModal');
    if (arrivalModal) arrivalModal.addEventListener('click', function(e) { if (e.target === this) closeArrivalModal(); });
    const detailModal = document.getElementById('apptDetailModal');
    if (detailModal) detailModal.addEventListener('click', function(e) { if (e.target === this) closeDetailModal(); });
    const rescheduleModal = document.getElementById('rescheduleModal');
    if (rescheduleModal) rescheduleModal.addEventListener('click', function(e) { if (e.target === this) closeRescheduleModal(); });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const searchBox = document.getElementById('existingPatientSearch');
            const resultBox = document.getElementById('patientSearchResults');
            if (document.activeElement === searchBox && resultBox && !resultBox.hidden) {
                cancelPatientSearch();
                return;
            }
            closeApptModal();
            closeArrivalModal();
            closeDetailModal();
            closeRescheduleModal();
        }
    });
});

// The patient picker is scoped to New Appointment only; editing remains unchanged.
const PATIENT_FIELD_IDS = {
    full_name:'apptFullName', age:'apptAge', occupation:'apptOccupation',
    phone:'apptPhone', email:'apptEmail', region:'apptRegion', province:'apptProvince',
    city:'apptCity', barangay:'apptBarangay', street_address:'apptStreetAddress'
};
let patientSearchAbort = null;
let patientSearchTimer = null;
let patientSearchSequence = 0;
let patientSearchItems = [];
let patientFocusIndex = -1;
// Keep a draft so temporarily searching existing patients cannot erase data
// already typed into the New Patient form.
let newPatientDraft = null;
let previousPatientMode = 'new';

function cancelPatientSearch() {
    clearTimeout(patientSearchTimer);
    if (patientSearchAbort) patientSearchAbort.abort();
    patientSearchAbort = null;
    patientSearchSequence++;
    document.getElementById('patientSearchSpinner').hidden = true;
    document.getElementById('patientSearchResults').hidden = true;
    document.getElementById('existingPatientSearch').setAttribute('aria-expanded','false');
}
function clearPatientSelection(clearFields = true) {
    cancelPatientSearch();
    document.getElementById('apptPatientId').value = '';
    document.getElementById('patientSelectedBadge').hidden = true;
    document.getElementById('patientChangeButton').hidden = false;
    document.getElementById('patientClearButton').hidden = false;
    document.getElementById('patientStoredAddressBlock').hidden = true;
    document.getElementById('patientStoredAddress').value = '';
    const search = document.getElementById('existingPatientSearch');
    search.readOnly = false;
    search.value = '';
    patientSearchItems = [];
    patientFocusIndex = -1;
    if (clearFields) Object.values(PATIENT_FIELD_IDS).forEach(id => document.getElementById(id).value = '');
    Object.values(PATIENT_FIELD_IDS).forEach(id => document.getElementById(id).readOnly = false);
    document.getElementById('patientSearchMessage').textContent = 'Type a name, email, phone number or patient ID.';
    document.getElementById('patientCreateNewButton').hidden = true;
}
function resetPatientChooser() {
    newPatientDraft = null;
    previousPatientMode = 'new';
    document.getElementById('apptPatientChooser').hidden = false;
    document.querySelector('input[name="patientMode"][value="new"]').checked = true;
    clearPatientSelection(true);
    document.getElementById('patientSearchArea').hidden = true;
    loadAppointmentAddressSuggestions();
}
function switchPatientMode(mode) {
    if (apptMode !== 'create' || mode === previousPatientMode) return;
    if (previousPatientMode === 'new' && mode === 'existing') {
        newPatientDraft = {};
        Object.entries(PATIENT_FIELD_IDS).forEach(([key, id]) => {
            newPatientDraft[id] = document.getElementById(id).value;
        });
    }
    clearPatientSelection(true);
    document.getElementById('patientSearchArea').hidden = mode !== 'existing';
    if (mode === 'new') loadAppointmentAddressSuggestions();
    if (mode === 'existing') {
        document.getElementById('existingPatientSearch').focus();
    } else if (newPatientDraft) {
        Object.entries(newPatientDraft).forEach(([id, value]) => { document.getElementById(id).value = value; });
    }
    previousPatientMode = mode;
}
function renderPatientOptions() {
    const list = document.getElementById('patientSearchResults');
    list.replaceChildren();
    patientSearchItems.forEach((p, i) => {
        const btn = document.createElement('button'); btn.type = 'button';
        btn.className = 'patient-option'; btn.role = 'option';
        btn.id = 'patientSearchOption' + i;
        btn.setAttribute('aria-selected', String(i === patientFocusIndex));
        const name = document.createElement('strong'); name.textContent = p.full_name || 'Unnamed Patient';
        const meta = document.createElement('small');
        meta.textContent = `#${p.patient_id || p.id} · ${p.phone || 'Phone not provided'} · ${p.email || 'Email not provided'}`;
        btn.append(name, meta);
        btn.addEventListener('click', () => selectPatient(p.id));
        list.append(btn);
    });
    list.hidden = !patientSearchItems.length;
    const input = document.getElementById('existingPatientSearch');
    input.setAttribute('aria-expanded', patientSearchItems.length ? 'true' : 'false');
    if (patientFocusIndex >= 0) input.setAttribute('aria-activedescendant', 'patientSearchOption' + patientFocusIndex);
    else input.removeAttribute('aria-activedescendant');
}
async function searchPatients(q) {
    cancelPatientSearch();
    const searchSequence = patientSearchSequence;
    if (!q.trim()) { patientSearchItems=[]; renderPatientOptions(); return; }
    patientSearchAbort = new AbortController();
    document.getElementById('patientSearchSpinner').hidden = false;
    document.getElementById('patientSearchMessage').textContent = 'Searching registered patients...';
    try {
        const form = new FormData(); form.append('action','search_patients'); form.append('q',q);
        const res = await fetch('appointments.php?ajax=1', {method:'POST',body:form,signal:patientSearchAbort.signal});
        const data = await res.json();
        if (searchSequence !== patientSearchSequence || !document.getElementById('apptModal').classList.contains('open') ||
            document.querySelector('input[name="patientMode"]:checked')?.value !== 'existing') return;
        if (!res.ok || !data.success) throw new Error(data.message || 'Search unavailable.');
        patientSearchItems = data.patients || []; patientFocusIndex = -1;
        renderPatientOptions();
        document.getElementById('patientSearchMessage').textContent = patientSearchItems.length
            ? `Found ${patientSearchItems.length} matching patient(s).`
            : 'No existing patient found. You can register a new patient.';
        document.getElementById('patientCreateNewButton').hidden = patientSearchItems.length !== 0;
    } catch (error) {
        if (error.name === 'AbortError' || searchSequence !== patientSearchSequence) return;
        console.error('Patient lookup failed:', error);
        document.getElementById('patientSearchMessage').textContent = 'Patient search is temporarily unavailable. Please try again.';
        patientSearchItems=[]; renderPatientOptions();
    } finally {
        if (searchSequence === patientSearchSequence) document.getElementById('patientSearchSpinner').hidden = true;
    }
}
async function selectPatient(id) {
    cancelPatientSearch();
    const sequence = patientSearchSequence;
    document.getElementById('patientSearchMessage').textContent = 'Loading patient details...';
    try {
        const form = new FormData(); form.append('action','get_patient'); form.append('patient_id',id);
        const response = await fetch('appointments.php?ajax=1', {method:'POST',body:form});
        const data = await response.json();
        if (sequence !== patientSearchSequence || !document.getElementById('apptModal').classList.contains('open') ||
            document.querySelector('input[name="patientMode"]:checked')?.value !== 'existing') return;
        if (!response.ok || !data.success) throw new Error(data.message || 'Patient unavailable.');
        const p = data.patient;
        if (p.status === 'inactive') throw new Error('This patient is marked inactive. Review the patient record before booking.');
        document.getElementById('apptPatientId').value = p.id;
        Object.entries(PATIENT_FIELD_IDS).forEach(([key, fieldId]) => {
            const element = document.getElementById(fieldId);
            element.value = p[key] ?? '';
            element.readOnly = true;
        });
        const rawAddress = (p.address || '').trim();
        document.getElementById('patientStoredAddress').value = rawAddress;
        document.getElementById('patientStoredAddressBlock').hidden = !rawAddress;
        const input = document.getElementById('existingPatientSearch');
        input.value = p.full_name; input.readOnly = true;
        document.getElementById('patientSelectedLabel').textContent = `✓ ${p.full_name} · ${p.patient_id || p.id}`;
        document.getElementById('patientSelectedBadge').hidden = false;
        document.getElementById('patientCreateNewButton').hidden = true;
        document.getElementById('patientEditLink').href = 'patients.php?search=' + encodeURIComponent(p.patient_id || p.full_name);
        document.getElementById('patientSearchMessage').textContent = p.address_single_field
            ? 'Selected. The full saved address is shown separately; booking location fields are filled where available.'
            : 'Patient details loaded from their saved record. Personal information is read-only.';
    } catch (error) {
        if (sequence !== patientSearchSequence) return;
        console.error('Patient selection failed:', error);
        document.getElementById('patientSearchMessage').textContent = error.message || 'Unable to load patient.';
    }
}
let addressSuggestionsLoaded = false;
async function loadAppointmentAddressSuggestions() {
    if (addressSuggestionsLoaded) return;
    try {
        const form = new FormData(); form.append('action', 'address_suggestions');
        const response = await fetch('appointments.php?ajax=1', {method:'POST', body:form});
        const result = await response.json();
        if (!response.ok || !result.success) return;
        for (const [field, inputId] of Object.entries({region:'apptRegion',province:'apptProvince',city:'apptCity',barangay:'apptBarangay'})) {
            const list = document.getElementById(inputId + 'Options');
            list.replaceChildren();
            (result.suggestions?.[field] || []).forEach(value => {
                const option = document.createElement('option'); option.value = value; list.append(option);
            });
        }
        addressSuggestionsLoaded = true;
    } catch (error) { console.warn('Address suggestions unavailable; manual input remains enabled.'); }
}
function initPatientPicker() {
    document.querySelectorAll('input[name="patientMode"]').forEach(r => r.addEventListener('change', e => switchPatientMode(e.target.value)));
    const input = document.getElementById('existingPatientSearch');
    input.addEventListener('input', () => {
        cancelPatientSearch();
        patientSearchItems=[]; patientFocusIndex=-1;
        document.getElementById('patientCreateNewButton').hidden = true;
        const q=input.value;
        if (!q.trim()) { document.getElementById('patientSearchMessage').textContent='Type to search patients.'; return; }
        patientSearchTimer = setTimeout(() => searchPatients(q), 300);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { cancelPatientSearch(); return; }
        if (!patientSearchItems.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            patientFocusIndex = (patientFocusIndex + (e.key === 'ArrowDown' ? 1 : -1) + patientSearchItems.length) % patientSearchItems.length;
            renderPatientOptions();
            document.getElementById('patientSearchOption'+patientFocusIndex)?.scrollIntoView({block:'nearest'});
        } else if (e.key === 'Enter' && patientFocusIndex >= 0) {
            e.preventDefault(); selectPatient(patientSearchItems[patientFocusIndex].id);
        }
    });
    document.getElementById('patientChangeButton').addEventListener('click', () => { clearPatientSelection(); input.focus(); });
    document.getElementById('patientClearButton').addEventListener('click', () => clearPatientSelection());
    document.getElementById('patientCreateNewButton').addEventListener('click', () => {
        document.querySelector('input[name="patientMode"][value="new"]').click();
    });
}
initPatientPicker();

const STAFF_APPT_CSRF = <?= json_encode($staffAppointmentsCsrf) ?>;
function newAppointmentCreateKey() {
    if (window.crypto && crypto.getRandomValues) {
        const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2,'0')).join('');
    }
    return ''; // Browser without WebCrypto: frontend still guards double-click; no key persisted.
}
let apptCreateKey = newAppointmentCreateKey();
let apptSaveInFlight = false;

function openApptModal() { document.getElementById('apptModal').classList.add('open'); }

function closeApptModal() {
    cancelPatientSearch();
    clearPatientSelection(false);
    document.getElementById('apptModal').classList.remove('open');
    document.getElementById('apptForm').reset();
    document.getElementById('apptId').value = '';
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.medical-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.checked = false);
    const medFields = ['apptPhysicianName', 'apptMedicineDetails', 'apptConditions', 
                       'apptOtherConditionDetails', 'apptAllergicOtherDetails', 
                       'apptHospitalizedWhen', 'apptHospitalizedWhy', 
                       'apptLastDentalVisit', 'apptLastDentalVisitReason'];
    medFields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    setApptFormDisabled(false);
    document.getElementById('apptSaveBtn').style.display = 'inline-flex';
    document.getElementById('apptViewMeta').classList.add('hidden');
    apptMode = 'create';
}

function setApptFormDisabled(disabled) {
    const fields = ['apptFullName', 'apptAge', 'apptOccupation', 'apptPhone', 'apptEmail', 
                    'apptRegion', 'apptProvince', 'apptCity', 'apptBarangay', 'apptStreetAddress',
                    'apptDate', 'apptTime', 'apptNotes', 'apptStatus', 'apptVerified'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
    });
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.disabled = disabled);
}

function populateApptForm(data) {
    document.getElementById('apptId').value = data.id || '';
    document.getElementById('apptFullName').value = data.full_name || '';
    document.getElementById('apptAge').value = data.age || '';
    document.getElementById('apptOccupation').value = data.occupation || '';
    document.getElementById('apptPhone').value = data.phone || '';
    document.getElementById('apptEmail').value = data.email || '';
    document.getElementById('apptRegion').value = data.region || '';
    document.getElementById('apptProvince').value = data.province || '';
    document.getElementById('apptCity').value = data.city || '';
    document.getElementById('apptBarangay').value = data.barangay || '';
    document.getElementById('apptStreetAddress').value = data.street_address || '';
    document.getElementById('apptDate').value = data.appointment_date || '';
    document.getElementById('apptTime').value = data.appointment_time || '';
    document.getElementById('apptNotes').value = data.notes || '';
    document.getElementById('apptStatus').value = data.status || 'pending';
    document.getElementById('apptVerified').value = data.is_verified ? '1' : '0';

    let selectedIds = [];
    if (data.services) {
        try {
            const parsed = JSON.parse(data.services);
            selectedIds = Array.isArray(parsed) ? parsed.map(String) : [String(parsed)];
        } catch (e) {
            selectedIds = [String(data.services)];
        }
    }
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => {
        cb.checked = selectedIds.includes(cb.value);
    });
    
    if (data.medical_history) {
        const med = data.medical_history;
        document.querySelectorAll('.medical-checkbox').forEach(cb => { cb.checked = med[cb.value] === 'yes'; });
        document.querySelectorAll('.allergy-checkbox').forEach(cb => { cb.checked = med[cb.value] === 'yes'; });
        document.getElementById('apptPhysicianName').value = med.physician_name || '';
        document.getElementById('apptMedicineDetails').value = med.medicine_details || '';
        document.getElementById('apptConditions').value = med.conditions || '';
        document.getElementById('apptOtherConditionDetails').value = med.other_condition_details || '';
        document.getElementById('apptAllergicOtherDetails').value = med.allergic_other_details || '';
        document.getElementById('apptHospitalizedWhen').value = med.hospitalized_when || '';
        document.getElementById('apptHospitalizedWhy').value = med.hospitalized_why || '';
        document.getElementById('apptLastDentalVisit').value = med.last_dental_visit || '';
        document.getElementById('apptLastDentalVisitReason').value = med.last_dental_visit_reason || '';
    }
}

async function saveAppointment(overrideDuplicate = false) {
    if (apptSaveInFlight) return;
    const patientMode = document.querySelector('input[name="patientMode"]:checked')?.value || 'new';
    if (apptMode === 'create' && patientMode === 'existing' && !document.getElementById('apptPatientId').value) {
        Swal.fire({ title:'Select a Patient', text:'Search and choose an existing patient first.', icon:'warning' }); return;
    }
    const id = document.getElementById('apptId').value;
    const fullName = document.getElementById('apptFullName').value.trim();
    const age = document.getElementById('apptAge').value.trim();
    const occupation = document.getElementById('apptOccupation').value.trim();
    const phone = document.getElementById('apptPhone').value.trim();
    const email = document.getElementById('apptEmail').value.trim();
    const region = document.getElementById('apptRegion').value.trim();
    const province = document.getElementById('apptProvince').value.trim();
    const city = document.getElementById('apptCity').value.trim();
    const barangay = document.getElementById('apptBarangay').value.trim();
    const streetAddress = document.getElementById('apptStreetAddress').value.trim();
    const appointmentDate = document.getElementById('apptDate').value;
    const appointmentTime = document.getElementById('apptTime').value;
    const notes = document.getElementById('apptNotes').value.trim();
    const selectedServices = Array.from(document.querySelectorAll('.appt-service-checkbox:checked')).map(cb => cb.value);
    const status = document.getElementById('apptStatus').value;
    const verified = document.getElementById('apptVerified').value;

    const medicalHistory = {
        under_physician_care: document.querySelector('.medical-checkbox[value="under_physician_care"]')?.checked ? 'yes' : 'no',
        physician_name: document.getElementById('apptPhysicianName').value.trim(),
        pregnant: document.querySelector('.medical-checkbox[value="pregnant"]')?.checked ? 'yes' : 'no',
        taking_medicine: document.querySelector('.medical-checkbox[value="taking_medicine"]')?.checked ? 'yes' : 'no',
        medicine_details: document.getElementById('apptMedicineDetails').value.trim(),
        conditions: document.getElementById('apptConditions').value.trim(),
        other_condition_details: document.getElementById('apptOtherConditionDetails').value.trim(),
        allergic_local_anesthesia: document.querySelector('.allergy-checkbox[value="allergic_local_anesthesia"]')?.checked ? 'yes' : 'no',
        allergic_antibiotics: document.querySelector('.allergy-checkbox[value="allergic_antibiotics"]')?.checked ? 'yes' : 'no',
        allergic_pain_killer: document.querySelector('.allergy-checkbox[value="allergic_pain_killer"]')?.checked ? 'yes' : 'no',
        allergic_other: document.querySelector('.allergy-checkbox[value="allergic_other"]')?.checked ? 'yes' : 'no',
        allergic_other_details: document.getElementById('apptAllergicOtherDetails').value.trim(),
        hospitalized: document.querySelector('.medical-checkbox[value="hospitalized"]')?.checked ? 'yes' : 'no',
        hospitalized_when: document.getElementById('apptHospitalizedWhen').value.trim(),
        hospitalized_why: document.getElementById('apptHospitalizedWhy').value.trim(),
        worn_denture: document.querySelector('.medical-checkbox[value="worn_denture"]')?.checked ? 'yes' : 'no',
        last_dental_visit: document.getElementById('apptLastDentalVisit').value.trim(),
        last_dental_visit_reason: document.getElementById('apptLastDentalVisitReason').value.trim()
    };

    if ((apptMode !== 'create' || patientMode === 'new') && (!phone || !email) || !fullName || !appointmentDate || !appointmentTime || !selectedServices.length) {
        Swal.fire({ title: 'Missing Info', text: 'Provide a patient, appointment date, time and service. New patients also require phone and email.', icon: 'warning' });
        return;
    }

    const action = apptMode === 'edit' ? 'update' : 'create';
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', STAFF_APPT_CSRF);
    if (action === 'create') {
        formData.append('patient_mode', patientMode);
        formData.append('selected_patient_id', document.getElementById('apptPatientId').value);
        formData.append('override_duplicate', overrideDuplicate ? '1' : '0');
        formData.append('create_key', apptCreateKey);
    }
    formData.append('id', id);
    formData.append('full_name', fullName);
    formData.append('age', age);
    formData.append('occupation', occupation);
    formData.append('phone', phone);
    formData.append('email', email);
    formData.append('region', region);
    formData.append('province', province);
    formData.append('city', city);
    formData.append('barangay', barangay);
    formData.append('street_address', streetAddress);
    formData.append('appointment_date', appointmentDate);
    formData.append('appointment_time', appointmentTime);
    formData.append('notes', notes);
    formData.append('services', JSON.stringify(selectedServices));
    formData.append('status', status);
    formData.append('is_verified', verified);
    formData.append('medical_history', JSON.stringify(medicalHistory));

    apptSaveInFlight = true;
    const saveButton = document.getElementById('apptSaveBtn');
    saveButton.disabled = true;
    Swal.fire({ title: 'Saving...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    try {
        const response = await fetch('appointments.php?ajax=1', { method:'POST', body:formData });
        const data = await response.json();
        Swal.close();
        if (data.success) {
            closeApptModal();
            apptCreateKey = newAppointmentCreateKey();
            Swal.fire({ title:'Saved!', text:data.message, icon:'success', timer:1500, showConfirmButton:false })
                .then(() => liveSearch(currentPage));
        } else if (data.code === 'possible_duplicate' && action === 'create') {
            const matches = (data.matches || []).map(p => `${p.full_name || 'Unnamed'} (${p.patient_id || p.id})`).join('; ');
            const decision = await Swal.fire({
                title:'Possible Existing Patient', text: `${data.message} Matches: ${matches}`,
                icon:'warning', showCancelButton:true, confirmButtonText:'Continue as New Patient',
                cancelButtonText:'Review Existing Patients', confirmButtonColor:'#0d9e8c'
            });
            if (decision.isConfirmed) {
                apptSaveInFlight = false; saveButton.disabled = false;
                return await saveAppointment(true);
            }
            document.querySelector('input[name="patientMode"][value="existing"]').click();
            document.getElementById('existingPatientSearch').focus();
        } else {
            Swal.fire({ title:'Error!', text:data.message || 'Unable to save appointment.', icon:'error' });
        }
    } catch (error) {
        console.error('Appointment save request failed:', error);
        Swal.fire({ title:'Connection Error', text:'Unable to save. Check your connection before trying again.', icon:'error' });
    } finally {
        apptSaveInFlight = false;
        saveButton.disabled = false;
    }

}

function fetchAppointment(id, callback) {
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get&id=' + id
    })
    .then(response => response.json())
    .then(data => callback(data))
    .catch(() => Swal.fire({ title: 'Error!', text: 'Could not load appointment.', icon: 'error' }));
}

function showCreateModal() {
    apptMode = 'create';
    apptCreateKey = newAppointmentCreateKey();
    apptSaveInFlight = false;
    document.getElementById('apptSaveBtn').disabled = false;
    resetPatientChooser();
    document.getElementById('apptModalTitle').textContent = 'New Appointment';
    document.getElementById('apptId').value = '';
    const fields = ['apptFullName', 'apptAge', 'apptOccupation', 'apptPhone', 'apptEmail', 
                    'apptRegion', 'apptProvince', 'apptCity', 'apptBarangay', 'apptStreetAddress',
                    'apptDate', 'apptTime', 'apptNotes'];
    fields.forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('apptStatus').value = 'pending';
    document.getElementById('apptVerified').value = '0';
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.medical-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.checked = false);
    const medFields = ['apptPhysicianName', 'apptMedicineDetails', 'apptConditions', 
                       'apptOtherConditionDetails', 'apptAllergicOtherDetails', 
                       'apptHospitalizedWhen', 'apptHospitalizedWhy', 
                       'apptLastDentalVisit', 'apptLastDentalVisitReason'];
    medFields.forEach(id => { document.getElementById(id).value = ''; });
    setApptFormDisabled(false);
    document.getElementById('apptSaveBtn').style.display = 'inline-flex';
    document.getElementById('apptViewMeta').classList.add('hidden');
    openApptModal();
}

function viewAppointment(id) {
    apptMode = 'view';
    document.getElementById('apptPatientChooser').hidden = true;
    fetchAppointment(id, (res) => {
        if (!res.success) { Swal.fire({ title: 'Error!', text: res.message, icon: 'error' }); return; }
        document.getElementById('apptModalTitle').textContent = 'Appointment #' + id;
        populateApptForm(res.data);
        setApptFormDisabled(true);
        document.getElementById('apptSaveBtn').style.display = 'none';
        const meta = document.getElementById('apptViewMeta');
        meta.innerHTML = 'Created: ' + (res.data.created_at || '—') + '<br>Last updated: ' + (res.data.updated_at || '—');
        meta.classList.remove('hidden');
        openApptModal();
    });
}

function editAppointment(id) {
    apptMode = 'edit';
    document.getElementById('apptPatientChooser').hidden = true;
    fetchAppointment(id, (res) => {
        if (!res.success) { Swal.fire({ title: 'Error!', text: res.message, icon: 'error' }); return; }
        document.getElementById('apptModalTitle').textContent = 'Edit Appointment #' + id;
        populateApptForm(res.data);
        setApptFormDisabled(false);
        // A linked patient's personal information can only be changed in Patients.
        if (Number(res.data.patient_id || 0) > 0) {
            Object.values(PATIENT_FIELD_IDS).forEach(fieldId => {
                const field = document.getElementById(fieldId);
                field.readOnly = true;
                field.title = 'To edit this information, open the Patients module.';
            });
            document.getElementById('apptPatientChooser').hidden = false;
            document.getElementById('patientSearchArea').hidden = true;
            document.getElementById('patientSelectedBadge').hidden = false;
            document.getElementById('patientSelectedLabel').textContent = 'Linked patient — demographics are read-only';
            document.getElementById('patientChangeButton').hidden = true;
            document.getElementById('patientClearButton').hidden = true;
            document.getElementById('patientEditLink').href = 'patients.php';
        }
        document.getElementById('apptSaveBtn').style.display = 'inline-flex';
        document.getElementById('apptViewMeta').classList.add('hidden');
        openApptModal();
    });
}

function deleteAppointment(id) {
    Swal.fire({
        title: 'Delete Appointment',
        text: 'Are you sure you want to delete this appointment? This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Deleting...', text: 'Please wait', icon: 'info', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            fetch('appointments.php?ajax=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(STAFF_APPT_CSRF)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const row = document.getElementById('row-' + id);
                    if (row) {
                        row.style.transition = 'all 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => { row.remove(); liveSearch(currentPage); }, 300);
                    }
                    Swal.fire({ title: 'Deleted!', text: data.message, icon: 'success', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ title: 'Error!', text: data.message, icon: 'error', confirmButtonText: 'OK' });
                }
            })
            .catch(error => {
                Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error', confirmButtonText: 'OK' });
            });
        }
    });
}

/* ===== updateStatus intercepts "confirmed" ===== */
function updateStatus(id, status, selectElement) {
    event.stopPropagation();
    
    if (status === 'confirmed') {
        openArrivalModal(id, selectElement);
        return;
    }
    
    if (selectElement) selectElement.dataset.previousValue = status;
    
    Swal.fire({ title: 'Updating Status...', text: 'Please wait', icon: 'info', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_status&id=' + id + '&status=' + status + '&csrf_token=' + encodeURIComponent(STAFF_APPT_CSRF)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let icon = 'success';
            let title = 'Updated!';
            if (data.warning) { icon = 'warning'; title = 'Updated with Warning'; }
            Swal.fire({ title: title, text: data.message, icon: icon, timer: 3500, showConfirmButton: true });
            setTimeout(() => liveSearch(currentPage), 1800);
        } else {
            Swal.fire({ title: 'Error!', text: data.message, icon: 'error', confirmButtonText: 'OK' });
        }
    })
    .catch(error => {
        Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error', confirmButtonText: 'OK' });
    });
}

/* ===== ARRIVAL MODAL ===== */
function openArrivalModal(id, selectElement) {
    arrivalApptId = id;
    arrivalSelectElement = selectElement;
    if (selectElement && !selectElement.dataset.previousValue) {
        // Confirmation option is only exposed on approved appointments.
        selectElement.dataset.previousValue = 'approved';
    }
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    document.getElementById('arrivalCurrentTime').textContent = timeString;
    document.getElementById('arrivalModal').classList.add('open');
}

function closeArrivalModal() {
    document.getElementById('arrivalModal').classList.remove('open');
    if (arrivalSelectElement) arrivalSelectElement.value = arrivalSelectElement.dataset.previousValue || 'pending';
    arrivalApptId = null;
    arrivalSelectElement = null;
}

function submitArrival() {
    if (!arrivalApptId) return;
    const apptId = arrivalApptId;
    const selectEl = arrivalSelectElement;
    closeArrivalModal();
    Swal.fire({ title: 'Confirming Arrival...', text: 'Please wait', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=confirm_arrival&id=' + apptId + '&csrf_token=' + encodeURIComponent(STAFF_APPT_CSRF)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({ title: 'Arrival Confirmed!', text: data.message || 'Patient arrival recorded successfully.', icon: 'success', timer: 2500, showConfirmButton: true, confirmButtonText: 'Great!' });
            if (selectEl) selectEl.dataset.previousValue = 'confirmed';
            setTimeout(() => liveSearch(currentPage), 1500);
        } else {
            Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            if (selectEl) selectEl.value = selectEl.dataset.previousValue || 'pending';
        }
    })
    .catch(() => {
        Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' });
        if (selectEl) selectEl.value = selectEl.dataset.previousValue || 'pending';
    });
}

/* ===== RESCHEDULE MODAL ===== */
function rescheduleAppointment(id, currentDate, currentTime) {
    event.stopPropagation();
    document.getElementById('reschId').value = id;
    document.getElementById('reschDate').value = currentDate || '';
    document.getElementById('reschTime').value = currentTime || '';
    document.getElementById('reschReason').value = '';

    const currentDisplay = (currentDate ? new Date(currentDate).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : 'Not set')
        + (currentTime ? ' at ' + currentTime : '');
    document.getElementById('reschCurrent').textContent = currentDisplay;

    document.getElementById('rescheduleModal').classList.add('open');
}

function closeRescheduleModal() {
    document.getElementById('rescheduleModal').classList.remove('open');
}

function submitReschedule() {
    const id = document.getElementById('reschId').value;
    const date = document.getElementById('reschDate').value;
    const time = document.getElementById('reschTime').value;
    const reason = document.getElementById('reschReason').value.trim();

    if (!date || !time) {
        Swal.fire({ title: 'Missing Info', text: 'Please pick both a date and time.', icon: 'warning' });
        return;
    }

    Swal.fire({ title: 'Rescheduling...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

    const formData = new FormData();
    formData.append('action', 'reschedule');
    formData.append('csrf_token', STAFF_APPT_CSRF);
    formData.append('id', id);
    formData.append('appointment_date', date);
    formData.append('appointment_time', time);
    formData.append('reason', reason);

    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            closeRescheduleModal();
            if (data.success) {
                Swal.fire({ 
                    title: 'Rescheduled!', 
                    text: data.message, 
                    icon: data.warning ? 'warning' : 'success', 
                    timer: 3000, 
                    showConfirmButton: true 
                }).then(() => liveSearch(currentPage));
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        })
        .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}

/* ===== TOGGLE VERIFICATION ===== */
function toggleVerification(id, verified) {
    Swal.fire({ title: 'Updating Verification...', text: 'Please wait', icon: 'info', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_verification&id=' + id + '&verified=' + verified + '&csrf_token=' + encodeURIComponent(STAFF_APPT_CSRF)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({ title: 'Updated!', text: data.message, icon: 'success', timer: 1500, showConfirmButton: false });
            setTimeout(() => liveSearch(currentPage), 1500);
        } else {
            Swal.fire({ title: 'Error!', text: data.message, icon: 'error', confirmButtonText: 'OK' });
        }
    })
    .catch(error => {
        Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error', confirmButtonText: 'OK' });
    });
}

/* ===== DETAIL MODAL ===== */
function showAppointmentDetails(id) {
    currentDetailId = id;
    document.getElementById('apptDetailModal').classList.add('open');
    document.getElementById('apptDetailContent').innerHTML = `
        <div class="flex justify-center py-8">
            <i class="fas fa-spinner fa-spin text-teal text-2xl"></i>
            <span class="ml-3 text-gray-500">Loading appointment details...</span>
        </div>`;
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get&id=' + id
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) { displayAppointmentDetails(data.data); }
        else {
            document.getElementById('apptDetailContent').innerHTML = `<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-4xl mb-3"></i><p>${data.message}</p></div>`;
        }
    })
    .catch(error => {
        document.getElementById('apptDetailContent').innerHTML = `<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-triangle text-4xl mb-3"></i><p>Error loading appointment details.</p></div>`;
    });
}

function displayAppointmentDetails(appt) {
    document.getElementById('apptDetailId').textContent = '#' + appt.id;
    document.getElementById('apptDetailTitle').textContent = 'Appointment Details';
    
    const statusMap = {
        'confirmed': { class: 'confirmed', label: 'Arrived at Clinic' },
        'approved': { class: 'approved', label: 'Approved' },
        'pending': { class: 'pending', label: 'Pending' },
        'in_progress': { class: 'in_progress', label: 'In Progress' },
        'cancelled': { class: 'cancelled', label: 'Cancelled' }
    };
    const status = appt.status || 'pending';
    const statusInfo = statusMap[status] || statusMap.pending;
    
    document.getElementById('apptDetailStatus').innerHTML = `<span class="status-badge-detail ${statusInfo.class}"><i class="fas fa-circle" style="font-size: 6px;"></i> ${statusInfo.label}</span>`;
    document.getElementById('apptDetailVerified').innerHTML = `<span class="verified-badge-detail ${appt.is_verified ? 'yes' : 'no'}"><i class="fas ${appt.is_verified ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${appt.is_verified ? 'Verified' : 'Not Verified'}</span>`;
    
    let servicesHtml = '';
    try {
        const serviceIds = JSON.parse(appt.services);
        if (Array.isArray(serviceIds) && serviceIds.length > 0) {
            servicesHtml = serviceIds.map(id => `<span class="detail-tag">Service #${id}</span>`).join('');
        } else {
            servicesHtml = '<span class="detail-value empty">No services selected</span>';
        }
    } catch (e) {
        servicesHtml = `<span class="detail-tag">${appt.services || 'N/A'}</span>`;
    }
    
    const createdDate = appt.created_at ? new Date(appt.created_at) : null;
    const apptDate = appt.appointment_date ? new Date(appt.appointment_date) : null;
    const arrivalDate = appt.arrived_at ? new Date(appt.arrived_at) : null;
    const prevDate = appt.previous_date ? new Date(appt.previous_date) : null;
    
    const addressParts = [];
    if (appt.street_address) addressParts.push(appt.street_address);
    if (appt.barangay) addressParts.push(appt.barangay);
    if (appt.city) addressParts.push(appt.city);
    if (appt.province) addressParts.push(appt.province);
    if (appt.region) addressParts.push(appt.region);
    const hasAddress = addressParts.length > 0;
    
    const isRescheduled = appt.is_rescheduled == 1 && prevDate;
    
    const html = `
        <div class="detail-section">
            <div class="detail-section-header"><i class="fas fa-user-circle"></i><h4>Personal Information</h4></div>
            <div class="detail-item"><span class="detail-label">Full Name</span><span class="detail-value">${appt.full_name || '<span class="empty">Not provided</span>'}</span></div>
            <div class="detail-item"><span class="detail-label">Age</span><span class="detail-value">${appt.age || '<span class="empty">Not provided</span>'}</span></div>
            <div class="detail-item"><span class="detail-label">Occupation</span><span class="detail-value">${appt.occupation || '<span class="empty">Not provided</span>'}</span></div>
        </div>
        <div class="detail-section">
            <div class="detail-section-header"><i class="fas fa-address-card"></i><h4>Contact Information</h4></div>
            <div class="detail-item"><span class="detail-label">Phone</span><span class="detail-value">${appt.phone || '<span class="empty">Not provided</span>'}</span></div>
            <div class="detail-item"><span class="detail-label">Email</span><span class="detail-value">${appt.email || '<span class="empty">Not provided</span>'}</span></div>
        </div>
        <div class="detail-section">
            <div class="detail-section-header"><i class="fas fa-map-marker-alt"></i><h4>Address</h4></div>
            ${hasAddress ? `
                ${appt.region ? `<div class="address-line"><strong>Region:</strong> ${appt.region}</div>` : ''}
                ${appt.province ? `<div class="address-line"><strong>Province:</strong> ${appt.province}</div>` : ''}
                ${appt.city ? `<div class="address-line"><strong>City/Municipality:</strong> ${appt.city}</div>` : ''}
                ${appt.barangay ? `<div class="address-line"><strong>Barangay:</strong> ${appt.barangay}</div>` : ''}
                ${appt.street_address ? `<div class="address-line"><strong>Street:</strong> ${appt.street_address}</div>` : ''}
            ` : `<span class="detail-value empty">No address provided</span>`}
        </div>
        <div class="detail-section">
            <div class="detail-section-header"><i class="fas fa-clock"></i><h4>Appointment Schedule</h4></div>
            <div class="detail-item"><span class="detail-label">Date</span><span class="detail-value">${apptDate ? apptDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) : '<span class="empty">Not set</span>'}</span></div>
            <div class="detail-item"><span class="detail-label">Time</span><span class="detail-value">${appt.appointment_time || '<span class="empty">Not set</span>'}</span></div>
            ${isRescheduled ? `
            <div class="detail-item resched-highlight">
                <span class="detail-label"><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i> Previous Schedule</span>
                <span class="detail-value">${prevDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}${appt.previous_time ? ' at ' + appt.previous_time : ''}</span>
            </div>` : ''}
            ${arrivalDate ? `
            <div class="detail-item arrival-highlight">
                <span class="detail-label"><i class="fas fa-user-check" style="margin-right: 4px;"></i> Arrived At</span>
                <span class="detail-value">${arrivalDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
            </div>` : ''}
            <div class="detail-item" style="border-bottom: none; padding-top: 8px; margin-top: 4px; border-top: 1px solid #f1f4f8;">
                <span class="detail-label">Created</span>
                <span class="detail-value" style="font-size: 12px; font-weight: 400; color: #94a3b8;">${createdDate ? createdDate.toLocaleString() : 'N/A'}</span>
            </div>
        </div>
        <div class="detail-section">
            <div class="detail-section-header"><i class="fas fa-tooth"></i><h4>Dental Services</h4></div>
            <div style="display: flex; flex-wrap: wrap; gap: 4px; padding: 4px 0;">${servicesHtml}</div>
        </div>
        <div class="detail-section" style="margin-bottom: 0;">
            <div class="detail-section-header"><i class="fas fa-sticky-note"></i><h4>Notes</h4></div>
            <div class="notes-box ${!appt.notes ? 'empty' : ''}">${appt.notes || 'No additional notes provided.'}</div>
        </div>`;
    
    document.getElementById('apptDetailContent').innerHTML = html;
}

function closeDetailModal() {
    document.getElementById('apptDetailModal').classList.remove('open');
    currentDetailId = null;
}

function editFromDetail() {
    if (currentDetailId) {
        closeDetailModal();
        editAppointment(currentDetailId);
    }
}
</script>

<?php
$conn->close();
?>
</body>
</html>