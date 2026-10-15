<?php
require '../../conn/conn.php';
require '../../functions/appointment_email.php';
require '../../functions/otp_functions.php';
require_once '../../functions/appointment_patient_search.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!aps_staff_authorized($conn)) { http_response_code(403); exit('Authorized administrator access required.'); }
$adminUserId = (int)($_SESSION['user_id'] ?? 0);
$adminRoleStmt = $conn->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
$adminRoleStmt->bind_param('i', $adminUserId);
$adminRoleStmt->execute();
$adminRoleRow = $adminRoleStmt->get_result()->fetch_assoc();
$adminRoleStmt->close();
if (!$adminRoleRow || ($adminRoleRow['role'] ?? '') !== 'admin') { http_response_code(403); exit('Administrator access required.'); }
if (empty($_SESSION['admin_appt_csrf'])) $_SESSION['admin_appt_csrf'] = bin2hex(random_bytes(32));
$adminAppointmentsCsrf = $_SESSION['admin_appt_csrf'];
// Set timezone
date_default_timezone_set('Asia/Manila');

// Mark this admin as having "seen" the appointments page right now
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("UPDATE users SET seen_appointments_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// STATS HELPER — returns current counts for the dashboard cards
// ============================================================
function computeStats($conn) {
    $stats = [];
    $statuses = ['approved', 'confirmed', 'pending', 'in_progress', 'cancelled'];
    foreach ($statuses as $s) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE status = ?");
        $stmt->bind_param("s", $s);
        $stmt->execute();
        $stats[$s] = (int)$stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();
    }
    $stats['total'] = array_sum($stats);

    $resched = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE is_rescheduled = 1");
    $stats['rescheduled'] = (int)$resched->fetch_assoc()['count'];

    return $stats;
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $verified = isset($_POST['verified']) ? (int)$_POST['verified'] : 0;

    if (in_array($action, ['create', 'update'], true) && !hash_equals($adminAppointmentsCsrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Session token expired. Refresh and try again.']);
        exit;
    }

    // Admin and staff role-checked, read-only autocomplete. No medical history is ever returned.
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
            error_log('Admin patient search failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not search patients. Please try again.']);
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
            $sql .= " AND (a.phone LIKE ? OR a.email LIKE ? OR a.services LIKE ?)";
            $count_sql .= " AND (phone LIKE ? OR email LIKE ? OR services LIKE ?)";
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

        $count_stmt = $conn->prepare($count_sql);
        if (!empty($count_params)) {
            $count_stmt->bind_param($count_types, ...$count_params);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
        $count_stmt->close();

        $sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
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
            if (!is_array($ids)) $ids = [$raw];
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
                $html .= '<td><select class="status-dropdown" onclick="event.stopPropagation();" onchange="updateStatus(' . $appt['id'] . ', this.value)">';
                $html .= '<option value="pending"' . ($appt['status'] === 'pending' ? ' selected' : '') . '>Pending</option>';
                $html .= '<option value="approved"' . ($appt['status'] === 'approved' ? ' selected' : '') . '>Approved</option>';
                if ($appt['status'] === 'confirmed') {
                    $html .= '<option value="confirmed" selected disabled>Arrived at Clinic</option>';
                }
                if ($appt['status'] === 'in_progress') {
                    $html .= '<option value="in_progress" selected disabled>In Progress</option>';
                }
                $html .= '<option value="cancelled"' . ($appt['status'] === 'cancelled' ? ' selected' : '') . '>Cancelled</option>';
                $html .= '</select></td>';
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

        echo json_encode([
            'success' => true,
            'html' => $html,
            'pagination' => $pagination_html,
            'total' => $total_records,
            'page' => $page,
            'total_pages' => $total_pages,
            'stats' => computeStats($conn)
        ]);
        exit;
    }

    // ===== CONFIRM ARRIVAL =====
    if ($action === 'confirm_arrival' && $id > 0) {
        try {
            $check_stmt = $conn->prepare("SELECT arrived_at FROM appointments WHERE id = ?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $row = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($row && $row['arrived_at']) {
                $response = ['success' => false, 'message' => 'Arrival already confirmed at ' . date('g:i A', strtotime($row['arrived_at']))];
            } else {
                $stmt = $conn->prepare("UPDATE appointments SET status = 'confirmed', arrived_at = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Patient arrival confirmed at ' . date('g:i A'), 'arrival_time' => date('g:i A')];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to confirm arrival.'];
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
        if (!empty($response['success'])) {
            $response['stats'] = computeStats($conn);
        }
        echo json_encode($response);
        exit;
    }

    $response = ['success' => false, 'message' => 'Invalid action'];

    // ===== DELETE =====
    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Appointment deleted successfully.'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete appointment.'];
        }
        $stmt->close();
    }

    // ===== UPDATE STATUS =====
    if ($action === 'update_status' && $id > 0 && !empty($status)) {
        try {
            // Fetch appointment
            $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appointment) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
                exit;
            }

            $dentistId = isset($_POST['dentist_id']) ? intval($_POST['dentist_id']) : null;
            $notes     = isset($_POST['notes']) ? trim($_POST['notes']) : '';

            $was_already_approved  = ($appointment['status'] === 'approved');
            $was_already_confirmed = ($appointment['status'] === 'confirmed');

            $is_approving  = ($status === 'approved'  && !$was_already_approved);
            $is_confirming = ($status === 'confirmed' && !$was_already_confirmed);

            // ---- Update the appointment row ----
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
            if ($status === 'confirmed') {
                $sql .= ", arrived_at = NOW()";
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

            // Dentist name (for response only)
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

            // APPROVED → send approval email only
            if ($is_approving) {
                try {
                    $email_sent = sendAppointmentApprovalEmail(
                        $appointment['email'],
                        $appointment['full_name'] ?: 'Patient'
                    );
                    if ($email_sent) {
                        $response['message'] .= ' Approval email sent to patient.';
                    } else {
                        $response['message'] .= ' Status approved but approval email could not be sent.';
                        $response['warning'] = true;
                    }
                } catch (Exception $e) {
                    $response['message'] .= ' Status approved but email failed: ' . $e->getMessage();
                    $response['warning'] = true;
                    error_log('Approval email error: ' . $e->getMessage());
                }
            }

            // CONFIRMED → create patient + create user + send welcome email
            if ($is_confirming) {
                $explicitLinkedPatientId = (int)($appointment['patient_id'] ?? 0);
                $explicitExistingPatient = ($appointment['patient_registration_mode'] ?? '') === 'existing' && $explicitLinkedPatientId > 0;
                if ($explicitExistingPatient) {
                    $linkedStmt = $conn->prepare('SELECT id FROM patients WHERE id = ? LIMIT 1');
                    $linkedStmt->bind_param('i', $explicitLinkedPatientId);
                    $linkedStmt->execute(); $linkedFound = $linkedStmt->get_result()->fetch_assoc(); $linkedStmt->close();
                    if (!$linkedFound) { error_log('Linked patient missing on appointment ' . $id); $response['warning']=true; $response['message'].=' Linked patient record could not be found.'; }
                }
                if (!$explicitExistingPatient) {
                $email = $appointment['email'];
                $phone = $appointment['phone'];

                // STEP 1: Create patient (if not already)
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

                if (!$existing_patient) {
                    $new_patient_id = 'PAT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

                    $address_parts = array_filter([
                        $appointment['street_address'] ?? '',
                        $appointment['barangay'] ?? '',
                        $appointment['city'] ?? '',
                        $appointment['province'] ?? '',
                        $appointment['region'] ?? '',
                    ]);
                    $address_str = implode(', ', $address_parts);

                    $insert_patient = $conn->prepare("
                        INSERT INTO patients 
                            (patient_id, full_name, age, gender, phone, email, address, status, source, appointment_id, created_at)
                        VALUES (?, ?, ?, NULL, ?, ?, ?, 'pending', 'appointment', ?, NOW())
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
                    } else {
                        error_log('Patient insert failed: ' . $insert_patient->error);
                        $response['message'] .= ' Failed to create patient record.';
                        $response['warning'] = true;
                    }
                    $insert_patient->close();
                }

                // STEP 2: Create user + send welcome email
                $check_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows === 0) {
                    $generated_password = generateRandomPassword(10);
                    $hashed_password = password_hash($generated_password, PASSWORD_DEFAULT);

                    $services = json_decode($appointment['services'], true);
                    $service_name = 'Patient';
                    if (is_array($services) && !empty($services)) {
                        $first_service_id = $services[0];
                        $service_stmt = $conn->prepare("SELECT title FROM services WHERE id = ?");
                        $service_stmt->bind_param("i", $first_service_id);
                        $service_stmt->execute();
                        $service_result = $service_stmt->get_result();
                        if ($service_row = $service_result->fetch_assoc()) {
                            $service_name = $service_row['title'];
                        }
                        $service_stmt->close();
                    }

                    $full_name = !empty($appointment['full_name'])
                        ? $appointment['full_name']
                        : "Patient - " . $service_name;
                    $role = 'patient';
                    $is_active = 1;

                    $insert_stmt = $conn->prepare("INSERT INTO users (email, password, full_name, phone, age, occupation, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_stmt->bind_param(
                        "ssssissi",
                        $email,
                        $hashed_password,
                        $full_name,
                        $phone,
                        $appointment['age'],
                        $appointment['occupation'],
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
                    $user_data = $check_result->fetch_assoc();
                    $uid_existing = (int)$user_data['id'];

                    $refresh = $conn->prepare("UPDATE users SET phone = ?, age = ?, occupation = ?, full_name = ? WHERE id = ?");
                    $refresh->bind_param(
                        "sissi",
                        $phone,
                        $appointment['age'],
                        $appointment['occupation'],
                        $appointment['full_name'],
                        $uid_existing
                    );
                    $refresh->execute();
                    $refresh->close();

                    $full_name = $user_data['full_name'];

                    try {
                        if (sendAppointmentConfirmationEmail($email, $full_name)) {
                            $response['message'] .= ' Existing account — details refreshed, confirmation email sent.';
                        } else {
                            $response['message'] .= ' Existing account — details refreshed but email failed.';
                            $response['warning'] = true;
                        }
                    } catch (Exception $e) {
                        $response['message'] .= ' Existing account — email failed: ' . $e->getMessage();
                        $response['warning'] = true;
                        error_log('Confirmation email error: ' . $e->getMessage());
                    }
                }
                $check_stmt->close();
                } // End normal new-patient registration / account workflow
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
        } else {
            $response = ['success' => false, 'message' => 'Failed to update verification.'];
        }
        $stmt->close();
    }

    // ===== RESCHEDULE =====
    if ($action === 'reschedule' && $id > 0) {
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
            if (function_exists('sendRescheduleEmail')) {
                try {
                    sendRescheduleEmail($appointment['email'], $appointment['full_name'] ?: 'Patient', $old_date, $old_time, $new_date, $new_time, $reason);
                    $response['message'] .= ' Patient notified by email.';
                } catch (Exception $e) {
                    $response['message'] .= ' (Email notification failed.)';
                    $response['warning'] = true;
                }
            }
        } else {
            $response = ['success' => false, 'message' => 'Failed to reschedule appointment.'];
        }
        $stmt->close();
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
            $street_address = $patient['address_single_field'] ? $patient['address'] : $patient['street_address'];
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
                            'under_physician_care' => 's', 'physician_name' => 's', 'pregnant' => 's',
                            'taking_medicine' => 's', 'medicine_details' => 's', 'conditions' => 's',
                            'other_condition_details' => 's', 'allergic_local_anesthesia' => 's',
                            'allergic_antibiotics' => 's', 'allergic_pain_killer' => 's', 'allergic_other' => 's',
                            'allergic_other_details' => 's', 'hospitalized' => 's', 'hospitalized_when' => 's',
                            'hospitalized_why' => 's', 'worn_denture' => 's', 'last_dental_visit' => 's',
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

    // ===== ATTACH FRESH STATS TO ANY SUCCESSFUL RESPONSE =====
    if (!empty($response['success']) && !isset($response['stats'])) {
        $response['stats'] = computeStats($conn);
    }

    echo json_encode($response);
    exit;
}

// ===== INITIAL PAGE LOAD =====
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
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
    $sql .= " AND (a.phone LIKE ? OR a.email LIKE ? OR a.services LIKE ?)";
    $count_sql .= " AND (phone LIKE ? OR email LIKE ? OR services LIKE ?)";
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

$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$count_stmt->close();

$sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total = count($appointments);
$stmt->close();

$stats = computeStats($conn);
$total_all = $stats['total'];

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
    if (!is_array($ids)) $ids = [$raw];
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

include 'sidebar.php';
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
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

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

        .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 0.5rem; }
        .appointments-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 750px; }
        .appointments-table th {
            text-align: left; padding: 12px 10px 10px 10px; font-weight: 600;
            color: var(--slate); font-size: 11px; letter-spacing: 0.3px;
            text-transform: uppercase; border-bottom: 2px solid #e9edf2; background: #fafbfc;
        }
        .appointments-table td {
            padding: 14px 10px; border-bottom: 1px solid #f1f4f8; vertical-align: middle;
        }
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
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 10px 16px; background: white; border-radius: 16px;
            padding: 12px 18px; box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7; margin-bottom: 1.5rem;
        }
        .filter-bar .filter-group {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--slate);
        }
        .filter-bar select, .filter-bar input {
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 14px;
            font-size: 13px; background: white; outline: none;
            transition: border 0.2s;
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
            font-size: 12px; color: #94a3b8;
            border-top: 1px dashed #e2e8f0; padding-top: 12px; margin-top: 4px; line-height: 1.6;
        }

        .search-loading { position: relative; }
        .search-loading .search-loader {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%); display: none;
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
    <main class="px-4 py-6 ">
        <div class="container-modern">

            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-dark-blue flex items-center gap-3">Appointments</h1>
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
                    <button onclick="showCreateModal()" class="btn-primary">
                        <i class="fas fa-plus-circle"></i> New Appointment
                    </button>

                    <!-- ============ REMINDER SETTINGS BUTTON ============ -->

                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statTotal"><?= $total_all ?></p>
                            <p class="text-xs text-gray-500 mt-1">all appointments</p>
                        </div>
                        <div class="stat-icon bg-teal-light text-teal"><i class="fas fa-calendar"></i></div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('confirmed')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Arrived</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statConfirmed"><?= $stats['confirmed'] ?></p>
                            <p class="text-xs text-teal font-medium mt-1"><i class="fas fa-map-marker-alt mr-1"></i> at clinic</p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-user-check"></i></div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('approved')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Approved</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statApproved"><?= $stats['approved'] ?? 0 ?></p>
                            <p class="text-xs text-blue-600 font-medium mt-1"><i class="fas fa-check-circle mr-1"></i> approved</p>
                        </div>
                        <div class="stat-icon bg-blue-50 text-blue-600"><i class="fas fa-check-double"></i></div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('pending')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Pending</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statPending"><?= $stats['pending'] ?></p>
                            <p class="text-xs text-amber-600 font-medium mt-1">needs review</p>
                        </div>
                        <div class="stat-icon bg-amber-50 text-amber-600"><i class="fas fa-clock"></i></div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('cancelled')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Cancelled</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statCancelled"><?= $stats['cancelled'] ?></p>
                            <p class="text-xs text-red-500 font-medium mt-1">cancelled</p>
                        </div>
                        <div class="stat-icon bg-red-50 text-red-500"><i class="fas fa-times"></i></div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('rescheduled')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Rescheduled</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statRescheduled"><?= $stats['rescheduled'] ?></p>
                            <p class="text-xs text-purple-600 font-medium mt-1"><i class="fas fa-calendar-alt mr-1"></i> moved</p>
                        </div>
                        <div class="stat-icon bg-purple-50 text-purple-600"><i class="fas fa-calendar-alt"></i></div>
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
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="rescheduled" <?= $status_filter === 'rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                    </select>
                </div>
                <div class="filter-group flex-1 min-w-[200px]">
                    <label for="searchInput" class="sr-only">Search</label>
                    <div class="relative w-full search-loading" id="searchContainer">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="searchInput"
                               placeholder="Search phone, email, or service..."
                               class="w-full pr-10 py-2" style="padding-left:40px"
                               value="<?= htmlspecialchars($search) ?>"
                               autocomplete="off">
                        <div class="search-loader"><i class="fas fa-spinner fa-spin"></i></div>
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
                                    <select class="status-dropdown" onclick="event.stopPropagation();" onchange="updateStatus(<?= $appt['id'] ?>, this.value, this)">
                                        <option value="pending" <?= $appt['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                                        <option value="approved" <?= $appt['status'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                                        <?php if ($appt['status'] === 'confirmed'): ?>
                                            <option value="confirmed" selected disabled>Arrived at Clinic</option>
                                        <?php endif; ?>
                                        <?php if ($appt['status'] === 'in_progress'): ?>
                                            <option value="in_progress" selected disabled>In Progress</option>
                                        <?php endif; ?>
                                        <option value="cancelled" <?= $appt['status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="text-xs">
                                        <div class="font-medium"><?= $appt['appointment_date'] ? date('M d, Y', strtotime($appt['appointment_date'])) : '—' ?></div>
                                        <div class="text-gray-400"><?= $appt['appointment_time'] ? date('g:i A', strtotime($appt['appointment_time'])) : '' ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-icons flex items-center justify-center gap-0" onclick="event.stopPropagation();">
                                        <button onclick="viewAppointment(<?= $appt['id'] ?>)" title="View Details" class="hover:bg-teal-light"><i class="fas fa-eye"></i></button>
                                        <button onclick="editAppointment(<?= $appt['id'] ?>)" title="Edit Appointment" class="hover:bg-teal-light"><i class="fas fa-pen"></i></button>
                                        <button onclick="rescheduleAppointment(<?= $appt['id'] ?>, '<?= $appt['appointment_date'] ?>', '<?= $appt['appointment_time'] ?>')" title="Reschedule" class="hover:bg-teal-light"><i class="fas fa-calendar-alt"></i></button>
                                        <button onclick="deleteAppointment(<?= $appt['id'] ?>)" class="text-danger hover:bg-red-50" title="Delete Appointment"><i class="fas fa-trash-alt"></i></button>
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
                            <button class="pagination-link" onclick="liveSearch(<?= $page - 1 ?>)"><i class="fas fa-chevron-left"></i></button>
                            <?php endif; ?>
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <button class="pagination-link <?= $i === $page ? 'active' : '' ?>" onclick="liveSearch(<?= $i ?>)"><?= $i ?></button>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                            <button class="pagination-link" onclick="liveSearch(<?= $page + 1 ?>)"><i class="fas fa-chevron-right"></i></button>
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
                        <label for="apptFullName"><i class="fas fa-user" style="margin-right: 4px; font-size: 10px;"></i> Full Name</label>
                        <input type="text" id="apptFullName" placeholder="Enter patient's full name">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptAge"><i class="fas fa-calendar" style="margin-right: 4px; font-size: 10px;"></i> Age</label>
                            <input type="number" id="apptAge" placeholder="Age">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptOccupation"><i class="fas fa-briefcase" style="margin-right: 4px; font-size: 10px;"></i> Occupation</label>
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
                            <label for="apptPhone"><i class="fas fa-phone" style="margin-right: 4px; font-size: 10px;"></i> Phone Number</label>
                            <input type="text" id="apptPhone" placeholder="09xx xxx xxxx">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptEmail"><i class="fas fa-envelope" style="margin-right: 4px; font-size: 10px;"></i> Email Address</label>
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
                            <input type="text" id="apptRegion" placeholder="Region">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptProvince">Province</label>
                            <input type="text" id="apptProvince" placeholder="Province">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptCity">City/Municipality</label>
                            <input type="text" id="apptCity" placeholder="City">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptBarangay">Barangay</label>
                            <input type="text" id="apptBarangay" placeholder="Barangay">
                        </div>
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
                            <label for="apptDate"><i class="fas fa-calendar-day" style="margin-right: 4px; font-size: 10px;"></i> Date</label>
                            <input type="date" id="apptDate">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptTime"><i class="fas fa-clock" style="margin-right: 4px; font-size: 10px;"></i> Time</label>
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
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px;"><i class="fas fa-heartbeat" style="margin-right: 4px;"></i> Medical Conditions</label>
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
                        <label for="apptPhysicianName"><i class="fas fa-user-md" style="margin-right: 4px;"></i> Physician Name</label>
                        <input type="text" id="apptPhysicianName" placeholder="Enter physician's name">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptMedicineDetails"><i class="fas fa-pills" style="margin-right: 4px;"></i> Medicine Details</label>
                        <input type="text" id="apptMedicineDetails" placeholder="List any medicines being taken">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptConditions"><i class="fas fa-notes-medical" style="margin-right: 4px;"></i> Medical Conditions Details</label>
                        <input type="text" id="apptConditions" placeholder="Describe any medical conditions">
                    </div>
                    <div class="form-field" style="margin-bottom: 12px;">
                        <label for="apptOtherConditionDetails"><i class="fas fa-plus-circle" style="margin-right: 4px;"></i> Other Condition Details</label>
                        <input type="text" id="apptOtherConditionDetails" placeholder="Any other conditions not listed">
                    </div>

                    <div style="margin: 14px 0 12px 0; border-top: 2px solid #f1f4f8; padding-top: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px;"><i class="fas fa-allergies" style="margin-right: 4px;"></i> Allergies</label>
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
                        <label for="apptAllergicOtherDetails"><i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i> Other Allergy Details</label>
                        <input type="text" id="apptAllergicOtherDetails" placeholder="Describe other allergies">
                    </div>

                    <div style="margin: 14px 0 12px 0; border-top: 2px solid #f1f4f8; padding-top: 14px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px;"><i class="fas fa-hospital" style="margin-right: 4px;"></i> Hospitalization Details</label>
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
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px;"><i class="fas fa-tooth" style="margin-right: 4px;"></i> Dental History</label>
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
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Additional Notes</h4>
                    </div>
                    <div class="form-field" style="margin-bottom: 0;">
                        <textarea id="apptNotes" rows="3" placeholder="Any additional information about the appointment..." style="width:100%; border: 2px solid #e8edf4; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; transition: all 0.2s; background: white; resize: vertical; min-height: 60px;"></textarea>
                    </div>
                </div>

                <div style="background: white; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptStatus"><i class="fas fa-flag" style="margin-right: 4px; font-size: 10px;"></i> Status</label>
                            <select id="apptStatus">
                                <option value="pending">🟡 Pending</option>
                                <option value="approved">🔵 Approved</option>
                                <option value="confirmed" style="display:none;">🟢 Arrive at Clinic</option>
                                <option value="in_progress" style="display:none;">🟣 In Progress</option>
                                <option value="cancelled">🔴 Cancelled</option>
                            </select>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="apptVerified"><i class="fas fa-shield-alt" style="margin-right: 4px; font-size: 10px;"></i> Verification</label>
                            <select id="apptVerified">
                                <option value="0">❌ Not Verified</option>
                                <option value="1">✅ Verified</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="apptViewMeta" class="view-meta hidden"></div>
            </form>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeApptModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="apptSaveBtn" class="btn-primary" onclick="saveAppointment()">
                <i class="fas fa-save"></i> Save Appointment
            </button>
        </div>
    </div>
</div>

<!-- Reschedule Modal -->
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
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeRescheduleModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitReschedule()"><i class="fas fa-check"></i> Confirm Reschedule</button>
        </div>
    </div>
</div>

<script>
let searchTimeout = null;
let currentPage = 1;
let apptMode = 'create';

// ============================================================
// Apply fresh stat numbers to the dashboard cards
// ============================================================
function applyStats(stats) {
    if (!stats) return;
    const map = {
        total: 'statTotal',
        confirmed: 'statConfirmed',
        approved: 'statApproved',
        pending: 'statPending',
        cancelled: 'statCancelled',
        rescheduled: 'statRescheduled'
    };
    Object.keys(map).forEach(key => {
        const el = document.getElementById(map[key]);
        if (el && stats[key] !== undefined) el.textContent = stats[key];
    });

    const totalCount = document.getElementById('totalCount');
    if (totalCount && stats.total !== undefined) totalCount.textContent = stats.total;
}

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
        .then(r => r.json())
        .then(data => {
            if (searchContainer) searchContainer.classList.remove('loading');
            if (data.success) {
                applyStats(data.stats);
                document.getElementById('appointmentsBody').innerHTML = data.html;
                document.getElementById('paginationContainer').innerHTML = data.pagination;
                document.getElementById('recordCount').textContent = data.total + ' records';
                currentPage = page;
                const url = new URL(window.location.href);
                if (searchValue) url.searchParams.set('search', searchValue); else url.searchParams.delete('search');
                if (statusValue && statusValue !== 'all') url.searchParams.set('status', statusValue); else url.searchParams.delete('status');
                url.searchParams.set('page', page);
                window.history.pushState({}, '', url);
            }
        })
        .catch(err => {
            if (searchContainer) searchContainer.classList.remove('loading');
            console.error('Live search error:', err);
        });
}

function debouncedSearch() {
    clearTimeout(searchTimeout);
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');
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
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimeout); liveSearch(1); }
        });
    }
    if (statusFilter) statusFilter.addEventListener('change', handleStatusChange);

    document.getElementById('apptModal').addEventListener('click', function(e) { if (e.target === this) closeApptModal(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeApptModal(); closeRescheduleModal(); }
    });
});

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
            element.value = key === 'street_address' && p.address_single_field ? (p.address || '') : (p[key] ?? '');
            element.readOnly = true;
        });
        const input = document.getElementById('existingPatientSearch');
        input.value = p.full_name; input.readOnly = true;
        document.getElementById('patientSelectedLabel').textContent = `✓ ${p.full_name} · ${p.patient_id || p.id}`;
        document.getElementById('patientSelectedBadge').hidden = false;
        document.getElementById('patientCreateNewButton').hidden = true;
        document.getElementById('patientEditLink').href = 'patients.php?search=' + encodeURIComponent(p.patient_id || p.full_name);
        document.getElementById('patientSearchMessage').textContent = p.address_single_field
            ? 'Selected. The stored address is in one field, so it appears under Street Address without guessing its parts.'
            : 'Patient details loaded from their saved record. Personal information is read-only.';
    } catch (error) {
        if (sequence !== patientSearchSequence) return;
        console.error('Patient selection failed:', error);
        document.getElementById('patientSearchMessage').textContent = error.message || 'Unable to load patient.';
    }
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

const ADMIN_APPT_CSRF = <?= json_encode($adminAppointmentsCsrf) ?>;
function newAppointmentCreateKey() {
    if (window.crypto?.getRandomValues) return Array.from(crypto.getRandomValues(new Uint8Array(16)), b=>b.toString(16).padStart(2,'0')).join('');
    return '';
}
let apptCreateKey = newAppointmentCreateKey();
let apptSaveInFlight = false;

function openApptModal() { document.getElementById('apptModal').classList.add('open'); }

function closeApptModal() {
    clearPatientSelection(false);
    document.getElementById('apptPatientChooser').hidden = false;
    document.getElementById('apptModal').classList.remove('open');
    document.getElementById('apptForm').reset();
    document.getElementById('apptId').value = '';
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.medical-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.checked = false);
    ['apptPhysicianName','apptMedicineDetails','apptConditions','apptOtherConditionDetails',
     'apptAllergicOtherDetails','apptHospitalizedWhen','apptHospitalizedWhy',
     'apptLastDentalVisit','apptLastDentalVisitReason'].forEach(id => document.getElementById(id).value = '');
    setApptFormDisabled(false);
    document.getElementById('apptSaveBtn').style.display = 'inline-flex';
    document.getElementById('apptViewMeta').classList.add('hidden');
    apptMode = 'create';
}

function setApptFormDisabled(disabled) {
    ['apptFullName','apptAge','apptOccupation','apptPhone','apptEmail','apptRegion','apptProvince',
     'apptCity','apptBarangay','apptStreetAddress','apptDate','apptTime','apptNotes','apptStatus','apptVerified']
        .forEach(id => { const el = document.getElementById(id); if (el) el.disabled = disabled; });
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.disabled = disabled);
    document.querySelectorAll('.medical-checkbox').forEach(cb => cb.disabled = disabled);
    document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.disabled = disabled);
}

function populateApptForm(data) {
    document.getElementById('apptPatientChooser').hidden = true;
    clearPatientSelection(false);
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
        } catch (e) { selectedIds = [String(data.services)]; }
    }
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.checked = selectedIds.includes(cb.value));

    if (data.medical_history) {
        const med = data.medical_history;
        document.querySelectorAll('.medical-checkbox').forEach(cb => cb.checked = med[cb.value] === 'yes');
        document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.checked = med[cb.value] === 'yes');
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
        Swal.fire({title:'Select a Patient',text:'Search and select an existing patient first.',icon:'warning'}); return;
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

    if (!phone || !email || selectedServices.length === 0) {
        Swal.fire({ title: 'Missing Info', text: 'Please fill in phone, email, and at least one service.', icon: 'warning' });
        return;
    }

    const action = apptMode === 'edit' ? 'update' : 'create';
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', ADMIN_APPT_CSRF);
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
    const saveBtn = document.getElementById('apptSaveBtn');
    if (saveBtn) saveBtn.disabled = true;
    Swal.fire({title:'Saving...',allowOutsideClick:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
    try {
        const res = await fetch('appointments.php?ajax=1', {method:'POST',body:formData});
        const data = await res.json();
        Swal.close();
        if (data.success) {
            if (data.stats) applyStats(data.stats);
            closeApptModal();
            apptCreateKey = newAppointmentCreateKey();
            Swal.fire({title:'Saved!',text:data.message,icon:'success',timer:1500,showConfirmButton:false}).then(()=>liveSearch(currentPage));
        } else if (data.code === 'possible_duplicate' && action === 'create') {
            const matches = (data.matches || []).map(p=>`${p.full_name} (${p.patient_id||p.id})`).join('; ');
            const decision = await Swal.fire({title:'Possible Existing Patient',text:data.message+' '+matches,icon:'warning',showCancelButton:true,confirmButtonText:'Continue as New Patient',cancelButtonText:'Review Existing Patients'});
            if (decision.isConfirmed) { apptSaveInFlight = false; if(saveBtn)saveBtn.disabled=false; return saveAppointment(true); }
            document.querySelector('input[name="patientMode"][value="existing"]').click();
        } else Swal.fire({title:'Could not save',text:data.message||'Please try again.',icon:'error'});
    } catch(error) {
        console.error('Admin appointment save error:',error);
        Swal.fire({title:'Connection Error',text:'Please check your connection before trying again.',icon:'error'});
    } finally { apptSaveInFlight=false; if(saveBtn)saveBtn.disabled=false; }
}

function fetchAppointment(id, callback) {
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get&id=' + id
    })
    .then(r => r.json()).then(data => callback(data))
    .catch(() => Swal.fire({ title: 'Error!', text: 'Could not load appointment.', icon: 'error' }));
}

function showCreateModal() {
    apptCreateKey = newAppointmentCreateKey();
    resetPatientChooser();
    apptMode = 'create';
    document.getElementById('apptModalTitle').textContent = 'New Appointment';
    document.getElementById('apptId').value = '';
    ['apptFullName','apptAge','apptOccupation','apptPhone','apptEmail','apptRegion','apptProvince',
     'apptCity','apptBarangay','apptStreetAddress','apptDate','apptTime','apptNotes']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('apptStatus').value = 'pending';
    document.getElementById('apptVerified').value = '0';
    document.querySelectorAll('.appt-service-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.medical-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.allergy-checkbox').forEach(cb => cb.checked = false);
    ['apptPhysicianName','apptMedicineDetails','apptConditions','apptOtherConditionDetails',
     'apptAllergicOtherDetails','apptHospitalizedWhen','apptHospitalizedWhy',
     'apptLastDentalVisit','apptLastDentalVisitReason'].forEach(id => document.getElementById(id).value = '');
    setApptFormDisabled(false);
    document.getElementById('apptSaveBtn').style.display = 'inline-flex';
    document.getElementById('apptViewMeta').classList.add('hidden');
    openApptModal();
}

function viewAppointment(id) {
    apptMode = 'view';
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
    fetchAppointment(id, (res) => {
        if (!res.success) { Swal.fire({ title: 'Error!', text: res.message, icon: 'error' }); return; }
        document.getElementById('apptModalTitle').textContent = 'Edit Appointment #' + id;
        populateApptForm(res.data);
        setApptFormDisabled(false);
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
            Swal.fire({ title: 'Deleting...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            fetch('appointments.php?ajax=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + id
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    applyStats(data.stats);
                    const row = document.getElementById('row-' + id);
                    if (row) {
                        row.style.transition = 'all 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => { row.remove(); liveSearch(currentPage); }, 300);
                    }
                    Swal.fire({ title: 'Deleted!', text: data.message, icon: 'success', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
                }
            })
            .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
        }
    });
}

function updateStatus(id, status, selectElement) {
    event.stopPropagation();
    if (selectElement) selectElement.dataset.previousValue = status;

    Swal.fire({ title: 'Updating Status...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('id', id);
    formData.append('status', status);

    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                applyStats(data.stats);
                let icon = data.warning ? 'warning' : 'success';
                let title = data.warning ? 'Updated with Warning' : 'Updated!';
                Swal.fire({ title: title, text: data.message, icon: icon, timer: 3500, showConfirmButton: true });
                setTimeout(() => liveSearch(currentPage), 1800);
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        })
        .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}

function toggleVerification(id, verified) {
    Swal.fire({ title: 'Updating Verification...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    fetch('appointments.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_verification&id=' + id + '&verified=' + verified
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            applyStats(data.stats);
            Swal.fire({ title: 'Updated!', text: data.message, icon: 'success', timer: 1500, showConfirmButton: false });
            setTimeout(() => liveSearch(currentPage), 1500);
        } else {
            Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
        }
    })
    .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}

function rescheduleAppointment(id, currentDate, currentTime) {
    document.getElementById('reschId').value = id;
    document.getElementById('reschDate').value = currentDate || '';
    document.getElementById('reschTime').value = currentTime || '';
    document.getElementById('reschReason').value = '';
    const currentDisplay = (currentDate ? new Date(currentDate).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : 'Not set')
        + (currentTime ? ' at ' + currentTime : '');
    document.getElementById('reschCurrent').textContent = currentDisplay;
    document.getElementById('rescheduleModal').classList.add('open');
}

function closeRescheduleModal() { document.getElementById('rescheduleModal').classList.remove('open'); }

function submitReschedule() {
    const id = document.getElementById('reschId').value;
    const date = document.getElementById('reschDate').value;
    const time = document.getElementById('reschTime').value;
    const reason = document.getElementById('reschReason').value.trim();

    if (!date || !time) { Swal.fire({ title: 'Missing Info', text: 'Please pick both a date and time.', icon: 'warning' }); return; }

    Swal.fire({ title: 'Rescheduling...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

    const formData = new FormData();
    formData.append('action', 'reschedule');
    formData.append('id', id);
    formData.append('appointment_date', date);
    formData.append('appointment_time', time);
    formData.append('reason', reason);

    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            closeRescheduleModal();
            if (data.success) {
                applyStats(data.stats);
                Swal.fire({ title: 'Rescheduled!', text: data.message, icon: data.warning ? 'warning' : 'success', timer: 2000, showConfirmButton: false })
                    .then(() => liveSearch(currentPage));
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        })
        .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}

document.getElementById('rescheduleModal').addEventListener('click', function(e) { if (e.target === this) closeRescheduleModal(); });
</script>

<?php $conn->close(); ?>
</body>
</html>