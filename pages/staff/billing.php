<?php
require '../../conn/conn.php';
require '../../functions/audit_log.php';
require_once '../../functions/appointment_patient_search.php';
require_once '../../functions/billing_payments.php';
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!aps_staff_authorized($conn)) { http_response_code(403); exit('Staff access required'); }
if (empty($_SESSION['dlp_csrf'])) $_SESSION['dlp_csrf'] = bin2hex(random_bytes(32));

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

/*
 * REQUIRED DB CHANGE (run once):
 *
 * ALTER TABLE billing
 *   ADD COLUMN patient_service_id INT NULL AFTER appointment_id,
 *   ADD INDEX idx_billing_patient_service (patient_service_id);
 */

// ========== FUNCTIONS ==========
function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function formatCurrency($amount) { return '₱' . number_format((float)$amount, 2); }
function formatDate($timestamp) {
    if (empty($timestamp)) return '—';
    return date('M j, Y g:i A', strtotime($timestamp));
}

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

function getStatusBadge($status) {
    $badges = [
        'paid' => 'badge-success',
        'unpaid' => 'badge-danger',
        'partial' => 'badge-warning',
        'refunded' => 'badge-info'
    ];
    return $badges[$status] ?? 'badge-info';
}

// ========== DATABASE OPERATIONS ==========

function getBillingRecords($conn, $search = '', $page = 1, $limit = 15, $filter_status = 'all', $date_from = '', $date_to = '') {
    $page = max(1, (int)$page);
    $offset = ($page - 1) * $limit;

    $sql = "SELECT b.*,
            COALESCE(NULLIF(b.patient_name,''),  a.full_name) AS patient_name,
            COALESCE(NULLIF(b.patient_phone,''), a.phone)     AS patient_phone,
            COALESCE(NULLIF(b.patient_email,''), a.email)     AS patient_email,
            a.appointment_date, a.appointment_time,
            d.first_name as dentist_first, d.last_name as dentist_last
            FROM billing b
            LEFT JOIN appointments a ON b.appointment_id = a.id
            LEFT JOIN dentists d ON a.dentist_id = d.id
            WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM billing b WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($search)) {
        $sql .= " AND (b.billing_code LIKE ? OR b.patient_name LIKE ? OR b.patient_phone LIKE ? OR b.patient_email LIKE ?)";
        $count_sql .= " AND (billing_code LIKE ? OR patient_name LIKE ? OR patient_phone LIKE ? OR patient_email LIKE ?)";
        $like = "%$search%";
        $params = [$like, $like, $like, $like];
        $types = "ssss";
    }

    if ($filter_status !== 'all' && !empty($filter_status)) {
        $sql .= " AND b.status = ?";
        $count_sql .= " AND status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    if (!empty($date_from)) {
        $sql .= " AND DATE(b.created_at) >= ?";
        $count_sql .= " AND DATE(created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    if (!empty($date_to)) {
        $sql .= " AND DATE(b.created_at) <= ?";
        $count_sql .= " AND DATE(created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total / $limit));

    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }

    $sql .= " ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'rows' => $rows,
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page
    ];
}

function getBillingStats($conn, $search = '', $filter_status = 'all', $date_from = '', $date_to = '') {
    $stats = [];
    $where = "WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($search)) {
        $where .= " AND (billing_code LIKE ? OR patient_name LIKE ? OR patient_phone LIKE ?)";
        $like = "%$search%";
        $params = [$like, $like, $like];
        $types = "sss";
    }

    if ($filter_status !== 'all' && !empty($filter_status)) {
        $where .= " AND status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    if (!empty($date_from)) {
        $where .= " AND DATE(created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    if (!empty($date_to)) {
        $where .= " AND DATE(created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM billing $where");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stats['total'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(amount_paid,0)), 0) as revenue FROM billing $where");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stats['revenue'] = $stmt->get_result()->fetch_assoc()['revenue'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as unpaid FROM billing $where AND status = 'unpaid'");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stats['unpaid'] = $stmt->get_result()->fetch_assoc()['unpaid'] ?? 0;
    $stmt->close();

    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) as today FROM billing $where AND DATE(created_at) = ?");
    $params_today = array_merge($params, [$today]);
    $types_today = $types . "s";
    if (!empty($params_today)) {
        $stmt->bind_param($types_today, ...$params_today);
    }
    $stmt->execute();
    $stats['today'] = $stmt->get_result()->fetch_assoc()['today'] ?? 0;
    $stmt->close();

    return $stats;
}

function getActiveServices($conn) {
    $result = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY title ASC");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getBillingById($conn, $id) {
    $stmt = $conn->prepare("
        SELECT b.*,
               COALESCE(NULLIF(b.patient_name,''),  a.full_name) AS patient_name,
               COALESCE(NULLIF(b.patient_phone,''), a.phone)     AS patient_phone,
               COALESCE(NULLIF(b.patient_email,''), a.email)     AS patient_email,
               a.appointment_date, a.appointment_time,
               s.title AS linked_service_title
        FROM billing b
        LEFT JOIN appointments a ON b.appointment_id = a.id
        LEFT JOIN patient_services ps ON b.patient_service_id = ps.id
        LEFT JOIN services s ON ps.service_id = s.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    $writeActions=['create_billing','update_billing','delete_billing','update_status','record_payment'];
    if (in_array($_POST['action'], $writeActions,true) && !hash_equals($_SESSION['dlp_csrf'],(string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Session validation failed. Refresh and try again.']);exit;
    }
    if ($_POST['action'] === 'record_payment') {
        try {
            $out=dlp_pay($conn,(int)($_POST['billing_id']??0),(string)($_POST['amount']??''),(string)($_POST['method']??''),(string)($_POST['request_key']??''),(string)($_SESSION['user_name']??'Authorized Staff'));
            echo json_encode(array_merge(['success'=>true,'message'=>$out['duplicate']?'Payment was already saved; using its original receipt.':'Payment recorded and acknowledgment issued.'],$out));
        } catch(Throwable $e) { error_log('Billing payment: '.$e->getMessage()); http_response_code(422);echo json_encode(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Unable to record payment. Please contact an administrator.']); }
        exit;
    }
    if ($_POST['action'] === 'payment_history') {
        $bid=(int)($_POST['billing_id']??0);
        $st=$conn->prepare('SELECT id,receipt_number,session_number,amount,previous_balance,balance_after,method,processed_by,paid_at FROM billing_payment_transactions WHERE billing_id=? ORDER BY session_number ASC');
        $st->bind_param('i',$bid);$st->execute();$payments=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        echo json_encode(['success'=>true,'payments'=>$payments]);exit;
    }
    switch ($_POST['action']) {

        case 'search_services':
            $search = trim($_POST['search'] ?? '');
            $status = $_POST['status'] ?? 'all';

            $sql = "SELECT ps.id, ps.status, ps.total_sessions,
                           p.patient_id AS patient_code, p.full_name, p.phone, p.email,
                           s.id AS service_id, s.title, s.price,
                           (SELECT COUNT(*) FROM billing b WHERE b.patient_service_id = ps.id) AS billed_count
                    FROM patient_services ps
                    JOIN patients p ON ps.patient_id = p.id
                    JOIN services s ON ps.service_id = s.id
                    WHERE ps.status <> 'cancelled'";
            $params = [];
            $types = "";

            if (!empty($search)) {
                $sql .= " AND (p.full_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.patient_id LIKE ? OR s.title LIKE ?)";
                $like = "%$search%";
                $params = [$like, $like, $like, $like, $like];
                $types = "sssss";
            }
            if ($status !== 'all' && !empty($status)) {
                $sql .= " AND ps.status = ?";
                $params[] = $status;
                $types .= "s";
            }
            $sql .= " ORDER BY ps.created_at DESC LIMIT 20";

            $stmt = $conn->prepare($sql);
            if (!empty($params)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $response['services'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $response['success'] = true;
            break;

        case 'create_billing':
            $appointment_id     = !empty($_POST['appointment_id'])     ? (int)$_POST['appointment_id']     : null;
            $patient_service_id = !empty($_POST['patient_service_id']) ? (int)$_POST['patient_service_id'] : null;
            $patient_name = trim($_POST['patient_name'] ?? '');
            $patient_phone = trim($_POST['patient_phone'] ?? '');
            $patient_email = trim($_POST['patient_email'] ?? '');
            $services = json_decode($_POST['services'] ?? '[]', true);
            $discount = (float)($_POST['discount'] ?? 0);
            $discount_type = $_POST['discount_type'] ?? 'none';
            $discount_name = trim($_POST['discount_name'] ?? '');
            $discount_percent = (float)($_POST['discount_percent'] ?? 0);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $status = 'unpaid';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($patient_name)) {
                $response['message'] = 'Patient name is required';
                echo json_encode($response);
                exit;
            }

            if (empty($services)) {
                $response['message'] = 'At least one service is required';
                echo json_encode($response);
                exit;
            }

            if ($appointment_id) {
                $check=$conn->prepare('SELECT id FROM billing WHERE appointment_id=? LIMIT 1');
                $check->bind_param('i',$appointment_id);$check->execute();$dup=$check->get_result()->fetch_assoc();$check->close();
                if ($dup) { $response['message']='This appointment already has an invoice. Open the existing invoice.'; break; }
            }
            $subtotal = 0;
            $service_details = [];
            foreach ($services as $service) {
                $price = (float)($service['price'] ?? 0);
                $quantity = (int)($service['quantity'] ?? 1);
                $subtotal += $price * $quantity;
                $service_details[] = [
                    'id' => $service['id'],
                    'title' => $service['title'],
                    'price' => $price,
                    'quantity' => $quantity,
                    'total' => $price * $quantity
                ];
            }

            if ($discount > $subtotal) $discount = $subtotal;
            if ($discount < 0) $discount = 0;

            $total_amount = $subtotal - $discount;
            $billing_code = 'BILL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

            $services_json = json_encode($service_details);
            $stmt = $conn->prepare("
                INSERT INTO billing (
                    billing_code, patient_service_id, appointment_id, patient_name, patient_phone, patient_email,
                    services, subtotal, discount, discount_type, discount_name, discount_percent,
                    total_amount, payment_method, status, notes, amount_paid, remaining_balance, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
            ");
            $stmt->bind_param(
                "siissssddssddsssd",
                $billing_code, $patient_service_id, $appointment_id, $patient_name, $patient_phone, $patient_email,
                $services_json, $subtotal, $discount, $discount_type, $discount_name, $discount_percent,
                $total_amount, $payment_method, $status, $notes, $total_amount
            );

            if ($stmt->execute()) {
                $billing_id = $conn->insert_id;
                $response['success'] = true;
                $response['message'] = 'Billing created successfully';
                $response['billing_id'] = $billing_id;
                $response['billing_code'] = $billing_code;

                log_staff_activity(
                    $conn, 'create', 'billing',
                    "Created billing {$billing_code} for {$patient_name} (" . formatCurrency($total_amount) . ", {$status})",
                    'billing', $billing_id
                );
            } else {
                $response['message'] = 'Failed to create billing: ' . $conn->error;
            }
            $stmt->close();
            break;

        case 'update_billing':
            $id = (int)($_POST['id'] ?? 0);
            $appointment_id     = !empty($_POST['appointment_id'])     ? (int)$_POST['appointment_id']     : null;
            $patient_service_id = !empty($_POST['patient_service_id']) ? (int)$_POST['patient_service_id'] : null;
            $patient_name = trim($_POST['patient_name'] ?? '');
            $patient_phone = trim($_POST['patient_phone'] ?? '');
            $patient_email = trim($_POST['patient_email'] ?? '');
            $services = json_decode($_POST['services'] ?? '[]', true);
            $discount = (float)($_POST['discount'] ?? 0);
            $discount_type = $_POST['discount_type'] ?? 'none';
            $discount_name = trim($_POST['discount_name'] ?? '');
            $discount_percent = (float)($_POST['discount_percent'] ?? 0);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $status = 'unpaid';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($id)) {
                $response['message'] = 'Billing ID is required';
                echo json_encode($response);
                exit;
            }

            if (empty($patient_name)) {
                $response['message'] = 'Patient name is required';
                echo json_encode($response);
                exit;
            }

            if (empty($services)) {
                $response['message'] = 'At least one service is required';
                echo json_encode($response);
                exit;
            }

            $subtotal = 0;
            $service_details = [];
            foreach ($services as $service) {
                $price = (float)($service['price'] ?? 0);
                $quantity = (int)($service['quantity'] ?? 1);
                $subtotal += $price * $quantity;
                $service_details[] = [
                    'id' => $service['id'],
                    'title' => $service['title'],
                    'price' => $price,
                    'quantity' => $quantity,
                    'total' => $price * $quantity
                ];
            }

            if ($discount > $subtotal) $discount = $subtotal;
            if ($discount < 0) $discount = 0;

            $total_amount = $subtotal - $discount;

            $guard = $conn->prepare('SELECT status,amount_paid FROM billing WHERE id = ?');
            $guard->bind_param('i',$id);$guard->execute();$savedBill=$guard->get_result()->fetch_assoc();$guard->close();
            if (!$savedBill || $savedBill['status'] !== 'unpaid' || $savedBill['amount_paid'] === null || (float)$savedBill['amount_paid'] > 0) {
                $response['message']='Invoices with payment history or closed status are read-only.';break;
            }
            // Preserve independent appointment status, and calculate billing status server-side.
            $status='unpaid';
            $old_stmt = $conn->prepare("SELECT billing_code FROM billing WHERE id = ?");
            $old_stmt->bind_param("i", $id);
            $old_stmt->execute();
            $old_result = $old_stmt->get_result()->fetch_assoc();
            $billing_code = $old_result['billing_code'] ?? 'BILL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $old_stmt->close();

            $services_json = json_encode($service_details);
            $stmt = $conn->prepare("
                UPDATE billing SET
                    patient_service_id = ?, appointment_id = ?, patient_name = ?, patient_phone = ?, patient_email = ?,
                    services = ?, subtotal = ?, discount = ?, discount_type = ?, discount_name = ?,
                    discount_percent = ?, total_amount = ?, remaining_balance = ?, payment_method = ?, status = ?, notes = ?
                WHERE id = ? AND status = 'unpaid' AND amount_paid = 0
            ");
            $stmt->bind_param(
                "iissssddssdddsssi",
                $patient_service_id, $appointment_id, $patient_name, $patient_phone, $patient_email,
                $services_json, $subtotal, $discount, $discount_type, $discount_name,
                $discount_percent, $total_amount, $total_amount, $payment_method, $status, $notes, $id
            );

            if ($stmt->execute()) {
                $verify=$conn->prepare('SELECT status,amount_paid FROM billing WHERE id=?');$verify->bind_param('i',$id);$verify->execute();$afterEdit=$verify->get_result()->fetch_assoc();$verify->close();
                if (!$afterEdit || $afterEdit['status'] !== 'unpaid' || (float)$afterEdit['amount_paid'] > 0) { $response['message']='Invoice changed during edit. Refresh and review payments.'; $stmt->close(); break; }
                $response['success'] = true;
                $response['message'] = 'Billing updated successfully';
                $response['billing_id'] = $id;
                $response['billing_code'] = $billing_code;

                log_staff_activity(
                    $conn, 'update', 'billing',
                    "Updated billing {$billing_code} for {$patient_name} (" . formatCurrency($total_amount) . ", {$status})",
                    'billing', $id
                );
            } else {
                $response['message'] = 'Failed to update billing: ' . $conn->error;
            }
            $stmt->close();
            break;

        case 'get_billing':
            $id = (int)($_POST['id'] ?? 0);
            $billing = getBillingById($conn, $id);
            if ($billing) {
                $response['success'] = true;
                $response['billing'] = $billing;
                $response['services'] = json_decode($billing['services'] ?? '[]', true);
            } else {
                $response['message'] = 'Billing record not found';
            }
            break;

        case 'update_status':
            $response['message'] = 'Payment status is calculated from recorded payments; manual status edits are disabled.';
            break;

        case 'live_search':
            $search = trim($_POST['search'] ?? '');
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;
            $filter_status = $_POST['filter_status'] ?? 'all';
            $date_from = $_POST['date_from'] ?? '';
            $date_to = $_POST['date_to'] ?? '';

            $result = getBillingRecords($conn, $search, $page, $limit, $filter_status, $date_from, $date_to);
            $stats = getBillingStats($conn, $search, $filter_status, $date_from, $date_to);

            $html = '';
            if (empty($result['rows'])) {
                $html = '<div class="text-center py-12 text-gray-400">
                            <i class="fas fa-receipt text-4xl mb-3 block"></i>
                            <p class="text-lg">No billing records found</p>
                            <p class="text-sm">Try adjusting your search filters</p>
                        </div>';
            } else {
                foreach ($result['rows'] as $record) {
                    $html .= renderBillingRow($record);
                }
            }

            $response['success'] = true;
            $response['html'] = $html;
            $response['pagination'] = renderBillingPagination($result['page'], $result['total_pages'], $result['total']);
            $response['total'] = $result['total'];

            $response['stats'] = [
                'total' => number_format($stats['total']),
                'today' => number_format($stats['today']),
                'revenue' => formatCurrency($stats['revenue']),
                'unpaid' => number_format($stats['unpaid'])
            ];
            break;

        case 'delete_billing':
            $id = (int)($_POST['id'] ?? 0);

            $snap_stmt = $conn->prepare("SELECT billing_code, patient_name, total_amount, status FROM billing WHERE id = ?");
            $snap_stmt->bind_param("i", $id);
            $snap_stmt->execute();
            $snapshot = $snap_stmt->get_result()->fetch_assoc();
            $snap_stmt->close();

            $guard=$conn->prepare('SELECT status,amount_paid FROM billing WHERE id=?');$guard->bind_param('i',$id);$guard->execute();$existing=$guard->get_result()->fetch_assoc();$guard->close();
            if (!$existing || $existing['status'] !== 'unpaid' || $existing['amount_paid'] === null || (float)$existing['amount_paid'] > 0) {
                $response['message']='Cannot delete invoices that are paid, partially paid, or require legacy reconciliation.';break;
            }
            $stmt = $conn->prepare("DELETE FROM billing WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Billing record deleted successfully';
                log_staff_activity(
                    $conn, 'delete', 'billing',
                    "Deleted billing #{$id}" . ($snapshot ? " ({$snapshot['billing_code']} — {$snapshot['patient_name']})" : ""),
                    'billing', (int)$id, $snapshot, null
                );
            } else {
                $response['message'] = 'Failed to delete billing record';
            }
            $stmt->close();
            break;
    }

    echo json_encode($response);
    exit;
}

// ========== ROW / PAGINATION RENDERERS ==========
function renderBillingRow($record) {
    $statusClass = getStatusBadge($record['status']);
    ob_start();
    ?>
    <div class="billing-table-row billing-item"
         data-id="<?= $record['id'] ?>"
         data-code="<?= h($record['billing_code']) ?>"
         data-name="<?= h($record['patient_name']) ?>">

        <span class="font-mono text-sm font-medium text-teal"><?= h($record['billing_code']) ?></span>

        <span class="font-medium flex items-center gap-3">
            <div class="avatar bg-teal-light text-teal">
                <?= getInitials($record['patient_name'] ?? 'Unknown') ?>
            </div>
            <?= h($record['patient_name'] ?? 'Unknown Patient') ?>
        </span>

        <span class="text-gray-600 text-sm"><?= h($record['patient_phone'] ?? '—') ?></span>

        <span class="text-sm font-bold text-teal-dark"><?= formatCurrency($record['total_amount'] ?? 0) ?><small class="block text-gray-500 font-normal">Due: <?= ($record['remaining_balance'] === null ? ($record['status'] === 'unpaid' ? formatCurrency($record['total_amount']) : 'Reconcile') : formatCurrency($record['remaining_balance'])) ?></small></span>

        <span><span class="badge <?= $statusClass ?>"><?= ucfirst($record['status'] ?? 'unpaid') ?></span></span>

        <span class="text-gray-600 text-sm"><?= formatDate($record['created_at']) ?></span>

        <span class="text-right flex items-center justify-end gap-1">
            <button class="action-btn view" onclick="viewBilling(<?= $record['id'] ?>)" title="View Details">
                <i class="fas fa-eye"></i>
            </button>
            <?php if (($record['status'] ?? '') === 'unpaid' && $record['amount_paid'] !== null && (float)$record['amount_paid'] <= 0): ?>
            <button class="action-btn edit" onclick="editBilling(<?= $record['id'] ?>)" title="Edit">
                <i class="fas fa-edit"></i>
            </button>
            <?php endif; ?>
            <?php if (in_array($record['status'],['unpaid','partial'],true) && ($record['amount_paid'] !== null || $record['status'] === 'unpaid')): ?>
            <button class="action-btn" onclick="openPayment(<?= (int)$record['id'] ?>, <?= h(json_encode($record['billing_code'])) ?>, <?= h(json_encode((string)($record['remaining_balance'] ?? $record['total_amount']))) ?>)" title="Record Payment"><i class="fas fa-money-bill-wave text-teal"></i></button>
            <?php endif; ?>
            <button class="action-btn" onclick="openHistory(<?= (int)$record['id'] ?>)" title="Payment History"><i class="fas fa-history"></i></button>
            <?php if (($record['status'] ?? '') === 'unpaid' && $record['amount_paid'] !== null && (float)$record['amount_paid'] <= 0): ?>
            <button class="action-btn delete" onclick="deleteBilling(<?= $record['id'] ?>)" title="Delete">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </span>
    </div>
    <?php
    return ob_get_clean();
}

function renderBillingPagination($page, $total_pages, $total) {
    if ($total_pages <= 1) return '';
    ob_start();
    ?>
    <div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">
        <span class="text-xs text-gray-400"><?= $total ?> total records · Page <?= $page ?> of <?= $total_pages ?></span>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
                <button class="pagination-link" onclick="liveSearch(<?= $page - 1 ?>)"><i class="fas fa-chevron-left"></i></button>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <button class="pagination-link <?= $i === $page ? 'active' : '' ?>" onclick="liveSearch(<?= $i ?>)"><?= $i ?></button>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <button class="pagination-link" onclick="liveSearch(<?= $page + 1 ?>)"><i class="fas fa-chevron-right"></i></button>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ========== GET INITIAL DATA ==========
$limit = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$billingResult = getBillingRecords($conn, '', $page, $limit);
$records = $billingResult['rows'];
$stats = getBillingStats($conn);
$services = getActiveServices($conn);

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing · Dental Clinic Dashboard</title>
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
            background-image: linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
        }
        .bg-teal { background-color: var(--teal); }
        .text-teal { color: var(--teal); }
        .text-teal-dark { color: var(--teal-dark); }
        .bg-dark-blue { background-color: var(--navy); }
        .text-dark-blue { color: var(--navy); }
        .bg-light-teal { background-color: var(--teal-light); }
        .bg-soft-gray { background-color: var(--off); }

        .container-modern { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }
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
        .btn-secondary {
            background: white;
            color: var(--navy);
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            border: 1.5px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; }
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-danger:hover { background: #c82333; }

        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 1.5rem 1.25rem;
            box-shadow: var(--shadow-card);
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        .stat-card:hover { border-color: var(--teal); box-shadow: 0 12px 32px rgba(11,22,40,.08); }
        .stat-card .stat-value { transition: all 0.3s ease; }

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
            flex-wrap: wrap;
            gap: 10px;
        }

        .billing-table-row {
            display: grid;
            grid-template-columns: 1.2fr 1.6fr 1fr 1fr 0.9fr 1.2fr 0.6fr;
            padding: 12px 0;
            border-bottom: 1px solid #f1f4f8;
            font-size: 13px;
            align-items: center;
            transition: background 0.2s;
        }
        .billing-table-row:hover { background: #fafbfc; }
        .billing-table-row.head {
            font-weight: 600;
            color: var(--slate);
            font-size: 11px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border-bottom: 2px solid #e9edf2;
            padding-bottom: 10px;
        }
        .billing-table-row.head:hover { background: transparent; }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fef3d7; color: #b45309; }
        .badge-info { background: #d1e7ff; color: #084298; }

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

        .search-input {
            padding: 8px 16px 8px 40px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            font-size: 13px;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 12px center;
            background-size: 16px;
            transition: all 0.2s;
            width: 100%;
            min-width: 200px;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
        }

        .filter-select {
            padding: 8px 14px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            font-size: 13px;
            background: white;
            transition: all 0.2s;
            cursor: pointer;
        }
        .filter-select:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
        }

        .action-btn {
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 14px;
        }
        .action-btn:hover { background: #f1f4f8; }
        .action-btn.view:hover { color: var(--teal); }
        .action-btn.edit:hover { color: #0d6efd; }
        .action-btn.delete:hover { color: #dc3545; }

        .search-loading .search-loader {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        .search-loading.loading .search-loader { display: block; }
        .search-loader i { color: var(--teal); font-size: 13px; }

        .billing-item {
            animation: fadeInRow 0.3s ease;
        }
        @keyframes fadeInRow {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .appointment-search-container {
            position: relative;
        }
        .appointment-search-container .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1.5px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .appointment-search-container .search-results.active {
            display: block;
        }
        .appointment-search-container .search-results .result-item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f1f4f8;
            transition: background 0.15s;
        }
        .appointment-search-container .search-results .result-item:hover {
            background: var(--teal-light);
        }
        .appointment-search-container .search-results .result-item .name {
            font-weight: 600;
            color: var(--navy);
        }
        .appointment-search-container .search-results .result-item .details {
            font-size: 12px;
            color: var(--slate);
        }

        @media (min-width: 1024px) {
            .main-content { margin-left: 260px; }
        }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }

        @media (max-width: 640px) {
            .billing-table-row {
                grid-template-columns: 1fr 1fr;
                gap: 4px 8px;
                padding: 12px 0;
                font-size: 12px;
            }
            .billing-table-row.head { display: none; }
            .stat-card { padding: 1rem; }
            .stat-card .text-3xl { font-size: 1.5rem; }
            .dashboard-card { padding: 1rem; }
            .search-input { min-width: 140px; }
            .filter-select { font-size: 12px; padding: 6px 10px; }
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: white;
            border-radius: 20px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 28px;
            animation: slideUp 0.3s ease;
        }
        .modal-box.wide {
            max-width: 1100px;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: var(--navy);
            margin-bottom: 4px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 60px; }

        .service-row {
            display: grid;
            grid-template-columns: 2fr 1fr 0.8fr 0.5fr;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f4f8;
            align-items: center;
        }
        .service-row.head {
            font-weight: 600;
            font-size: 12px;
            color: var(--slate);
            text-transform: uppercase;
        }

        .auto-load-indicator {
            display: inline-block;
            padding: 4px 12px;
            background: #d4edda;
            color: #155724;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .auto-load-indicator i { margin-right: 4px; }

        .stat-update {
            animation: statPulse 0.5s ease;
        }
        @keyframes statPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .reset-percent-btn {
            background: #f1f4f8;
            border: 1px solid #e2e8f0;
            color: var(--slate);
            font-size: 10.5px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            margin-top: 4px;
        }
        .reset-percent-btn:hover {
            background: var(--teal-light);
            border-color: var(--teal);
            color: var(--teal-dark);
        }

        #discount[data-auto="true"] {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

<div class="main-content">

    <main class="py-6   px-4">
        <div class="container-modern">

            <!-- HEADER -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-dark-blue">Billing</h1>
                    <p class="text-sm text-gray-500">Manage invoices and payments</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus-circle"></i> New Invoice
                    </button>
                </div>
            </div>

            <!-- STATS CARDS -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8" id="statsContainer">
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Invoices</p>
                    <p class="text-3xl font-bold text-dark-blue mt-1 stat-value" id="statTotal"><?= number_format($stats['total']) ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Today's Invoices</p>
                    <p class="text-3xl font-bold text-dark-blue mt-1 stat-value" id="statToday"><?= number_format($stats['today']) ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Revenue</p>
                    <p class="text-3xl font-bold text-teal mt-1 stat-value" id="statRevenue"><?= formatCurrency($stats['revenue']) ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Unpaid Invoices</p>
                    <p class="text-3xl font-bold text-dark-blue mt-1 stat-value" id="statUnpaid"><?= number_format($stats['unpaid']) ?></p>
                </div>
            </div>

            <!-- BILLING TABLE -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span><i class="fas fa-receipt mr-2 text-teal"></i> All Invoices</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="relative search-loading" id="searchContainer">
                            <input type="text" class="search-input" id="searchInput" placeholder="Search invoices..." autocomplete="off">
                            <div class="search-loader"><i class="fas fa-spinner fa-spin"></i></div>
                        </div>

                        <select class="filter-select" id="statusFilter" onchange="liveSearch(1)">
                            <option value="all">All Status</option>
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="partial">Partial</option>
                            <option value="refunded">Refunded</option>
                        </select>

                        <input type="date" class="filter-select" id="dateFrom" style="max-width:150px;" onchange="liveSearch(1)">
                        <span class="text-gray-400 text-sm">to</span>
                        <input type="date" class="filter-select" id="dateTo" style="max-width:150px;" onchange="liveSearch(1)">
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto" id="billingTableContainer">
                    <div class="billing-table-row head">
                        <span>Invoice #</span>
                        <span>Patient</span>
                        <span>Phone</span>
                        <span>Total</span>
                        <span>Status</span>
                        <span>Date</span>
                        <span class="text-right">Actions</span>
                    </div>

                    <div id="billingRows">
                        <?php if (empty($records)): ?>
                            <div class="text-center py-12 text-gray-400">
                                <i class="fas fa-receipt text-4xl mb-3 block"></i>
                                <p class="text-lg">No billing records found</p>
                                <p class="text-sm">Create your first invoice by clicking "New Invoice"</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($records as $record): ?>
                                <?= renderBillingRow($record) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="billingPagination">
                    <?= renderBillingPagination($billingResult['page'], $billingResult['total_pages'], $billingResult['total']) ?>
                </div>
            </div>

            <div class="mt-8 text-center text-[11px] text-gray-400 border-t border-gray-200 pt-5">
                <i class="fas fa-tooth mr-1 text-teal/50"></i> Wansi Calacat Dental Clinic · Billing System
            </div>
        </div>
    </main>
</div>

<!-- ===== CREATE/EDIT BILLING MODAL ===== -->
<div id="billingModal" class="modal-overlay">
    <div class="modal-box wide">
        <div class="flex items-center justify-between mb-4 sticky top-0 bg-white z-10 pb-2 border-b border-gray-100">
            <h3 class="text-xl font-bold text-dark-blue">
                <i class="fas fa-file-invoice text-teal mr-2"></i>
                <span id="modalTitle">New Invoice</span>
            </h3>
            <button onclick="closeModal('billingModal')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>

        <form id="billingForm" onsubmit="submitBilling(event)">
            <input type="hidden" id="billingId" name="billing_id" value="">
            <input type="hidden" id="formAction" name="form_action" value="create_billing">

            <!-- Patient Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label><i class="fas fa-user text-teal"></i> Patient Name *</label>
                    <input type="text" id="patient_name" name="patient_name" required placeholder="Enter patient name">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone text-teal"></i> Phone</label>
                    <input type="text" id="patient_phone" name="patient_phone" placeholder="Enter phone number">
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope text-teal"></i> Email</label>
                <input type="email" id="patient_email" name="patient_email" placeholder="Enter email address">
            </div>

            <!-- Service Search -->
            <div class="form-group appointment-search-container">
                <label><i class="fas fa-tooth text-teal"></i> Search Existing Service (Type to search)</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                    <input type="text" id="serviceSearch" placeholder="Patient name, phone, ID or service..." autocomplete="off" class="w-full">
                    <select id="serviceStatusFilter" class="filter-select">
                        <option value="all">All (Pending, In Progress, Completed)</option>
                        <option value="pending">Pending</option>
                        <option value="in-progress">In Progress</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div id="serviceResults" class="search-results"></div>
                <input type="hidden" id="patient_service_id" name="patient_service_id" value="">
                <input type="hidden" id="appointment_id" name="appointment_id" value="">
                <div id="serviceInfo" class="mt-2 text-sm text-gray-500 hidden"></div>
            </div>

            <!-- Services Section -->
            <div class="form-group">
                <label><i class="fas fa-tooth text-teal"></i> Services *</label>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-2">
                    <select id="serviceSelect" class="col-span-2">
                        <option value="">Select a service...</option>
                        <?php foreach ($services as $svc): ?>
                            <option value="<?= $svc['id'] ?>" data-price="<?= $svc['price'] ?>" data-title="<?= h($svc['title']) ?>">
                                <?= h($svc['title']) ?> - <?= formatCurrency($svc['price']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex gap-2">
                        <input type="number" id="serviceQuantity" value="1" min="1" class="w-20" placeholder="Qty">
                        <button type="button" class="btn-primary" onclick="addService()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div id="selectedServices" class="mt-2"></div>
            </div>

            <!-- Discount Section -->
            <div class="form-group">
                <label><i class="fas fa-tag text-teal"></i> Discount</label>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label style="font-size:11px; font-weight:600; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; display:block;">Type</label>
                        <select id="discountType" name="discount_type" onchange="onDiscountTypeChange()">
                            <option value="none">No Discount</option>
                            <option value="pwd">PWD (20%)</option>
                            <option value="senior">Senior Citizen (20%)</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <div id="customNameGroup" style="display:none;">
                        <label style="font-size:11px; font-weight:600; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; display:block;">Discount Name</label>
                        <input type="text" id="discountName" name="discount_name" placeholder="e.g. Employee, Referral..." maxlength="100">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; display:block;">Percent (%)</label>
                        <input type="number" id="discountPercent" name="discount_percent" value="0" min="0" max="100" step="0.01" onchange="updateTotals()" oninput="updateTotals()">
                        <button type="button" class="reset-percent-btn" id="resetPercentBtn" style="display:none;" onclick="resetPercent()">
                            <i class="fas fa-rotate-left"></i> Reset to 20%
                        </button>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; display:block;">Amount (₱)</label>
                        <input type="number" id="discount" name="discount" value="0" min="0" step="0.01" onchange="updateTotals()" oninput="updateTotals()">
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label><i class="fas fa-credit-card text-teal"></i> Payment Method</label>
                    <select id="payment_method" name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="gcash">GCash</option>
                        <option value="paymaya">PayMaya</option>
                        <option value="insurance">Insurance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-check-circle text-teal"></i> Status</label>
                    <select id="status" name="status" disabled>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-sticky-note text-teal"></i> Notes</label>
                <textarea id="notes" name="notes" placeholder="Additional notes..."></textarea>
            </div>

            <!-- Totals -->
            <div class="bg-light-teal rounded-lg p-4 mt-4">
                <div class="flex justify-between text-sm">
                    <span class="font-medium">Subtotal:</span>
                    <span id="subtotalDisplay">₱0.00</span>
                </div>
                <div class="flex justify-between text-sm" id="discountRow" style="display:none;">
                    <span class="font-medium" id="discountLabel">Discount:</span>
                    <span id="discountDisplay" class="text-red-600">−₱0.00</span>
                </div>
                <div class="flex justify-between text-lg font-bold mt-2 pt-2 border-t border-teal">
                    <span>Total:</span>
                    <span id="totalDisplay" class="text-teal">₱0.00</span>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-100">
                <button type="button" class="btn-secondary" onclick="closeModal('billingModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Save Invoice
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Separate payment transaction modal -->
<div id="paymentModal" class="modal-overlay"><div class="modal-box" style="max-width:520px">
<h3 class="text-xl font-bold mb-4">Record Payment</h3>
<p id="paySummary" class="text-sm text-gray-500 mb-3"></p>
<div class="form-group"><label>Amount (₱)</label><input type="number" id="payAmount" min="0.01" step="0.01" required></div>
<div class="form-group"><label>Payment Method</label><select id="payMethod"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank Transfer</option><option value="credit_card">Card</option><option value="paymaya">Maya</option><option value="insurance">Insurance</option><option value="other">Other</option></select></div>
<div class="flex justify-end gap-2"><button class="btn-secondary" onclick="closeModal('paymentModal')">Cancel</button><button id="paySave" class="btn-primary" onclick="submitPayment()">Confirm Payment</button></div>
</div></div>
<div id="paymentHistoryModal" class="modal-overlay"><div class="modal-box"><div class="flex justify-between"><h3 class="text-xl font-bold">Payment History</h3><button onclick="closeModal('paymentHistoryModal')">✕</button></div><div id="historyBody" class="mt-4"></div></div></div>
<!-- ===== VIEW BILLING MODAL ===== -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box">
        <div class="flex items-center justify-between mb-4 sticky top-0 bg-white z-10 pb-2 border-b border-gray-100">
            <h3 class="text-xl font-bold text-dark-blue">
                <i class="fas fa-file-invoice text-teal mr-2"></i> Invoice Details
            </h3>
            <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <div id="viewContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-teal"></i>
                <p class="text-gray-400 mt-2">Loading...</p>
            </div>
        </div>
        <div class="flex justify-end mt-4 pt-4 border-t border-gray-100">
            <button class="btn-secondary" onclick="closeModal('viewModal')">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<script>
const DLP_CSRF=<?= json_encode($_SESSION['dlp_csrf']) ?>;
let activePayment=null, paymentKey=null;
function openPayment(id,code,balance){
  activePayment={id,code,balance};paymentKey=null;
  document.getElementById('paySummary').textContent=`${code} · Remaining balance ₱${balance}`;
  document.getElementById('payAmount').value='';
  document.getElementById('payAmount').max=balance;
  openModal('paymentModal');
}
async function submitPayment(){
 if(!activePayment)return;
 const amount=document.getElementById('payAmount').value;
 const method=document.getElementById('payMethod').value;
 if(!amount || Number(amount)<=0 || Number(amount)>Number(activePayment.balance)) {Swal.fire('Invalid payment','Enter an amount within the remaining balance.','warning');return;}
 const ok=await Swal.fire({title:'Confirm payment?',text:`Record ₱${Number(amount).toFixed(2)} for ${activePayment.code}?`,showCancelButton:true,icon:'question'});
 if(!ok.isConfirmed)return;
 if(!paymentKey) paymentKey=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const btn=document.getElementById('paySave');btn.disabled=true;
 try{
  const fd=new FormData();Object.entries({action:'record_payment',csrf_token:DLP_CSRF,billing_id:activePayment.id,amount,method,request_key:paymentKey}).forEach(([k,v])=>fd.append(k,v));
  const response=await fetch(location.href,{method:'POST',body:fd});const result=await response.json();
  if(!result.success)throw new Error(result.message || 'Payment failed');
  closeModal('paymentModal');await Swal.fire({icon:'success',title:'Payment saved',text:result.message+' Receipt: '+result.receipt_number});
  liveSearch(1);paymentKey=null;
  window.open('payment_receipt.php?receipt='+encodeURIComponent(result.receipt_number),'_blank','noopener');
 }catch(e){Swal.fire('Payment not saved',e.message,'error');}finally{btn.disabled=false;}
}
async function openHistory(id){
 openModal('paymentHistoryModal');const box=document.getElementById('historyBody');box.textContent='Loading payment records...';
 try{const fd=new FormData();fd.append('action','payment_history');fd.append('billing_id',id);
  const resp=await fetch(location.href,{method:'POST',body:fd});const data=await resp.json();if(!data.success)throw Error(data.message||'Unable to load');
  box.replaceChildren();
  if(!data.payments.length){box.textContent='No itemized payment transactions recorded. Older invoices may need manual reconciliation.';return;}
  data.payments.forEach(p=>{const row=document.createElement('div');row.className='flex justify-between items-center border-b py-3 gap-3';
   const detail=document.createElement('div');detail.textContent=`Session ${p.session_number} · ${p.paid_at} · ₱${p.amount} · Balance ₱${p.balance_after}`;
   const a=document.createElement('a');a.href='payment_receipt.php?receipt='+encodeURIComponent(p.receipt_number);a.target='_blank';a.rel='noopener';a.textContent='View / Print';a.className='text-teal font-semibold';row.append(detail,a);box.append(row);
  });
 }catch(e){box.textContent=e.message;}
}
// ========== GLOBAL VARIABLES ==========
let selectedServices = [];
let serviceIdCounter = 0;
let currentPage = <?= (int)$billingResult['page'] ?>;
let searchTimeout = null;
let isSearching = false;
let serviceSearchTimeout = null;
let serviceCache = {};

// ========== MODAL CONTROLS ==========
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// ========== CREATE MODAL ==========
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'New Invoice';
    document.getElementById('formAction').value = 'create_billing';
    document.getElementById('billingId').value = '';
    document.getElementById('billingForm').reset();
    selectedServices = [];
    serviceIdCounter = 0;
    document.getElementById('selectedServices').innerHTML = '';

    // Reset service search state
    document.getElementById('appointment_id').value = '';
    document.getElementById('patient_service_id').value = '';
    document.getElementById('serviceResults').classList.remove('active');
    document.getElementById('serviceResults').innerHTML = '';
    document.getElementById('serviceInfo').classList.add('hidden');
    document.getElementById('serviceSearch').value = '';

    // Reset discount state
    document.getElementById('discountType').value = 'none';
    document.getElementById('discountPercent').value = 0;
    document.getElementById('discount').value = 0;
    document.getElementById('discountName').value = '';
    document.getElementById('customNameGroup').style.display = 'none';
    document.getElementById('resetPercentBtn').style.display = 'none';
    document.getElementById('discount').removeAttribute('data-auto');

    updateTotals();
    openModal('billingModal');
}

function editBilling(id) {
    document.getElementById('modalTitle').textContent = 'Edit Invoice';
    document.getElementById('formAction').value = 'update_billing';
    document.getElementById('billingId').value = id;

    const formData = new FormData();
    formData.append('action', 'get_billing');
    formData.append('id', id);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const billing = data.billing;
                document.getElementById('patient_name').value = billing.patient_name || '';
                document.getElementById('patient_phone').value = billing.patient_phone || '';
                document.getElementById('patient_email').value = billing.patient_email || '';
                document.getElementById('payment_method').value = billing.payment_method || 'cash';
                document.getElementById('status').value = billing.status || 'unpaid';
                document.getElementById('notes').value = billing.notes || '';
                document.getElementById('appointment_id').value = billing.appointment_id || '';
                document.getElementById('patient_service_id').value = billing.patient_service_id || '';
                document.getElementById('serviceSearch').value = billing.linked_service_title || '';
                document.getElementById('serviceResults').classList.remove('active');
                document.getElementById('serviceInfo').classList.add('hidden');

                // Restore discount state
                const savedType = billing.discount_type || 'none';
                document.getElementById('discountType').value = savedType;
                document.getElementById('discountPercent').value = billing.discount_percent || 0;
                document.getElementById('discount').value = billing.discount || 0;
                document.getElementById('discountName').value = billing.discount_name || '';
                updateDiscountFields(savedType, /* keepExisting */ true);

                // Load services
                selectedServices = [];
                serviceIdCounter = 0;
                (data.services || []).forEach(service => {
                    selectedServices.push({
                        id: ++serviceIdCounter,
                        service_id: service.id || service.service_id,
                        title: service.title,
                        price: parseFloat(service.price || 0),
                        quantity: parseInt(service.quantity || 1)
                    });
                });

                renderSelectedServices();
                updateTotals();

                openModal('billingModal');
            } else {
                Swal.fire('Error', data.message || 'Failed to load billing', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Failed to load billing data', 'error');
        });
}

// ========== DISCOUNT FIELD VISIBILITY ==========
function updateDiscountFields(type, keepExisting = false) {
    const percentEl = document.getElementById('discountPercent');
    const resetBtn = document.getElementById('resetPercentBtn');
    const customNameGroup = document.getElementById('customNameGroup');
    const customNameInput = document.getElementById('discountName');

    percentEl.removeAttribute('readonly');
    percentEl.style.background = '';
    percentEl.style.color = '';

    if (type === 'pwd') {
        if (!keepExisting) percentEl.value = 20;
        resetBtn.style.display = 'inline-flex';
        customNameGroup.style.display = 'none';
        customNameInput.value = '';
    } else if (type === 'senior') {
        if (!keepExisting) percentEl.value = 20;
        resetBtn.style.display = 'inline-flex';
        customNameGroup.style.display = 'none';
        customNameInput.value = '';
    } else if (type === 'custom') {
        customNameGroup.style.display = 'block';
        if (!keepExisting) {
            percentEl.value = percentEl.value || 0;
            customNameInput.value = '';
        }
        resetBtn.style.display = 'none';
    } else {
        if (!keepExisting) {
            percentEl.value = 0;
            customNameInput.value = '';
        }
        resetBtn.style.display = 'none';
        customNameGroup.style.display = 'none';
    }
}

function onDiscountTypeChange() {
    const type = document.getElementById('discountType').value;
    updateDiscountFields(type, /* keepExisting */ false);
    updateTotals();
}

function resetPercent() {
    const type = document.getElementById('discountType').value;
    if (type === 'pwd' || type === 'senior') {
        document.getElementById('discountPercent').value = 20;
        updateTotals();
    }
}

// ========== SERVICE SEARCH (patient_services) ==========
document.getElementById('serviceSearch').addEventListener('input', function () {
    clearTimeout(serviceSearchTimeout);
    const q = this.value.trim();
    if (q.length < 2) {
        const r = document.getElementById('serviceResults');
        r.classList.remove('active');
        r.innerHTML = '';
        return;
    }
    serviceSearchTimeout = setTimeout(() => searchServicesLive(q), 300);
});

document.getElementById('serviceStatusFilter').addEventListener('change', function () {
    const q = document.getElementById('serviceSearch').value.trim();
    if (q.length >= 2) searchServicesLive(q);
});

document.addEventListener('click', function (e) {
    const c = document.querySelector('.appointment-search-container');
    if (c && !c.contains(e.target)) {
        document.getElementById('serviceResults').classList.remove('active');
    }
});

function searchServicesLive(search) {
    const resultsDiv = document.getElementById('serviceResults');
    resultsDiv.innerHTML = '<div class="result-item text-gray-400"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    resultsDiv.classList.add('active');

    const formData = new FormData();
    formData.append('action', 'search_services');
    formData.append('search', search);
    formData.append('status', document.getElementById('serviceStatusFilter').value);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.services.length > 0) {
                serviceCache = {};
                resultsDiv.innerHTML = data.services.map(s => {
                    serviceCache[s.id] = s;
                    const billed = parseInt(s.billed_count) > 0
                        ? '<span class="badge badge-info">Already billed</span>' : '';
                    return `
                        <div class="result-item" onclick="selectPatientService(${s.id})">
                            <div class="name">${h(s.full_name)}
                                <span style="font-weight:400;font-size:11px;color:#94a3b8;">#${h(s.patient_code)}</span>
                            </div>
                            <div class="details">
                                ${h(s.title)} · ${formatCurrency(s.price)} · ${s.total_sessions} session(s)
                                <span class="badge ${getStatusBadge(s.status)}">${h(s.status)}</span> ${billed}
                            </div>
                        </div>`;
                }).join('');
            } else {
                resultsDiv.innerHTML = '<div class="result-item text-gray-400">No services found. Try a different search.</div>';
            }
        })
        .catch(() => {
            resultsDiv.innerHTML = '<div class="result-item text-red-400">Search failed. Please try again.</div>';
        });
}

function selectPatientService(id) {
    const s = serviceCache[id];
    if (!s) return;

    document.getElementById('patient_service_id').value = s.id;
    document.getElementById('patient_name').value  = s.full_name || '';
    document.getElementById('patient_phone').value = s.phone || '';
    document.getElementById('patient_email').value = s.email || '';
    document.getElementById('serviceSearch').value = `${s.full_name} — ${s.title}`;
    document.getElementById('serviceResults').classList.remove('active');

    // Load the service into the invoice
    selectedServices = [{
        id: ++serviceIdCounter,
        service_id: s.service_id,
        title: s.title,
        price: parseFloat(s.price || 0),
        quantity: 1
    }];
    renderSelectedServices();
    updateTotals();

    const info = document.getElementById('serviceInfo');
    const warn = parseInt(s.billed_count) > 0
        ? ' <b>(this service already has an invoice)</b>' : '';
    info.innerHTML = `<span class="auto-load-indicator" style="background:#d1e7ff;color:#084298;">
        <i class="fas fa-check-circle"></i> Loaded "${h(s.title)}" for ${h(s.full_name)}${warn}</span>`;
    info.classList.remove('hidden');
}

// ========== SERVICE MANAGEMENT ==========
function addService() {
    const select = document.getElementById('serviceSelect');
    const quantity = parseInt(document.getElementById('serviceQuantity').value) || 1;

    if (!select.value) {
        Swal.fire('Error', 'Please select a service', 'warning');
        return;
    }

    const option = select.options[select.selectedIndex];
    const service = {
        id: ++serviceIdCounter,
        service_id: select.value,
        title: option.dataset.title,
        price: parseFloat(option.dataset.price),
        quantity: quantity
    };

    selectedServices.push(service);
    renderSelectedServices();
    updateTotals();
    select.value = '';
    document.getElementById('serviceQuantity').value = 1;
}

function removeService(id) {
    selectedServices = selectedServices.filter(s => s.id !== id);
    renderSelectedServices();
    updateTotals();
}

function updateServiceQty(id, val) {
    const svc = selectedServices.find(s => s.id === id);
    if (!svc) return;
    svc.quantity = Math.max(1, parseInt(val) || 1);
    renderSelectedServices();
    updateTotals();
}

function renderSelectedServices() {
    const container = document.getElementById('selectedServices');
    if (selectedServices.length === 0) {
        container.innerHTML = '<p class="text-gray-400 text-sm">No services added yet.</p>';
        return;
    }

    container.innerHTML = `
        <div class="service-row head">
            <span>Service</span>
            <span>Price</span>
            <span>Qty</span>
            <span>Total</span>
        </div>
        ${selectedServices.map(s => `
            <div class="service-row">
                <span class="font-medium">${h(s.title)}</span>
                <span>${formatCurrency(s.price)}</span>
                <span><input type="number" min="1" value="${s.quantity}" style="width:60px;padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:6px;" onchange="updateServiceQty(${s.id}, this.value)"></span>
                <span class="flex items-center justify-between">
                    <span class="font-bold text-teal-dark">${formatCurrency(s.price * s.quantity)}</span>
                    <button type="button" onclick="removeService(${s.id})" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-times"></i>
                    </button>
                </span>
            </div>
        `).join('')}
    `;
}

// ========== TOTALS ==========
function updateTotals() {
    const subtotal = selectedServices.reduce((sum, s) => sum + (s.price * s.quantity), 0);

    const type = document.getElementById('discountType').value;
    const percentInput = parseFloat(document.getElementById('discountPercent').value) || 0;
    const discountInput = document.getElementById('discount').value;

    let discount = 0;

    if (type === 'pwd' || type === 'senior' || type === 'custom') {
        discount = subtotal * (percentInput / 100);
        document.getElementById('discount').value = discount.toFixed(2);
        document.getElementById('discount').setAttribute('data-auto', 'true');
    } else {
        discount = parseFloat(discountInput) || 0;
        document.getElementById('discount').removeAttribute('data-auto');
    }

    if (percentInput > 100) document.getElementById('discountPercent').value = 100;
    if (percentInput < 0) document.getElementById('discountPercent').value = 0;

    if (discount > subtotal) discount = subtotal;
    if (discount < 0) discount = 0;

    const total = subtotal - discount;

    document.getElementById('subtotalDisplay').textContent = formatCurrency(subtotal);

    const discountRow = document.getElementById('discountRow');
    if (discount > 0) {
        discountRow.style.display = 'flex';
        let label;
        if (type === 'pwd') {
            label = `PWD Discount (${percentInput}%)`;
        } else if (type === 'senior') {
            label = `Senior Citizen Discount (${percentInput}%)`;
        } else if (type === 'custom') {
            const customName = (document.getElementById('discountName').value || '').trim();
            label = `${customName || 'Discount'} (${percentInput}%)`;
        } else {
            label = 'Discount';
        }
        document.getElementById('discountLabel').textContent = label + ':';
    } else {
        discountRow.style.display = 'none';
    }

    document.getElementById('discountDisplay').textContent = '−' + formatCurrency(discount);
    document.getElementById('totalDisplay').textContent = formatCurrency(total);
}

// ========== SUBMIT BILLING ==========
function submitBilling(event) {
    event.preventDefault();

    const services = selectedServices.map(s => ({
        id: s.service_id,
        title: s.title,
        price: s.price,
        quantity: s.quantity
    }));

    if (services.length === 0) {
        Swal.fire('Error', 'Please add at least one service', 'warning');
        return;
    }

    const formAction = document.getElementById('formAction').value;
    const billingId = document.getElementById('billingId').value;

    const formData = new FormData();
    formData.append('action', formAction);
    formData.append('csrf_token', DLP_CSRF);

    if (billingId) {
        formData.append('id', billingId);
    }

    formData.append('appointment_id', document.getElementById('appointment_id').value);
    formData.append('patient_service_id', document.getElementById('patient_service_id').value);
    formData.append('patient_name', document.getElementById('patient_name').value);
    formData.append('patient_phone', document.getElementById('patient_phone').value);
    formData.append('patient_email', document.getElementById('patient_email').value);
    formData.append('services', JSON.stringify(services));
    formData.append('discount', document.getElementById('discount').value);
    formData.append('discount_type', document.getElementById('discountType').value);
    formData.append('discount_name', document.getElementById('discountName').value || '');
    formData.append('discount_percent', document.getElementById('discountPercent').value || 0);
    formData.append('payment_method', document.getElementById('payment_method').value);
    formData.append('status', document.getElementById('status').value);
    formData.append('notes', document.getElementById('notes').value);

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message, 'success');
                closeModal('billingModal');
                liveSearch(currentPage);
            } else {
                Swal.fire('Error', data.message || 'Failed to save billing', 'error');
            }
        })
        .catch(() => {
            Swal.close();
            Swal.fire('Error', 'Failed to save billing record', 'error');
        });
}

// ========== VIEW BILLING ==========
function viewBilling(id) {
    openModal('viewModal');
    document.getElementById('viewContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-2xl text-teal"></i>
            <p class="text-gray-400 mt-2">Loading...</p>
        </div>
    `;

    const formData = new FormData();
    formData.append('action', 'get_billing');
    formData.append('id', id);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const b = data.billing;
                const services = data.services || [];

                const pct = parseFloat(b.discount_percent || 0);
                let discountLabel = 'Discount';
                if (b.discount_type === 'pwd') {
                    discountLabel = `PWD Discount (${pct}%)`;
                } else if (b.discount_type === 'senior') {
                    discountLabel = `Senior Citizen Discount (${pct}%)`;
                } else if (b.discount_type === 'custom') {
                    discountLabel = `${h(b.discount_name) || 'Custom Discount'} (${pct}%)`;
                }

                let html = `
                    <div class="space-y-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="text-2xl font-bold text-teal">${h(b.billing_code)}</h4>
                                <p class="text-gray-500 text-sm">${formatDate(b.created_at)}</p>
                            </div>
                            <span class="badge ${getStatusBadge(b.status)} text-lg px-4 py-2">${ucfirst(b.status)}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4 p-4 bg-light-teal rounded-lg">
                            <div>
                                <p class="text-xs text-gray-500">Patient</p>
                                <p class="font-bold">${h(b.patient_name)}</p>
                                <p class="text-sm">${h(b.patient_phone || 'No phone')}</p>
                                <p class="text-sm">${h(b.patient_email || 'No email')}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Service</p>
                                <p class="text-sm font-medium">${h(b.linked_service_title || 'Manual / Walk-in')}</p>
                                <p class="text-xs text-gray-500 mt-2">Payment: ${ucfirst(b.payment_method)}</p>
                            </div>
                        </div>

                        <div>
                            <h5 class="font-bold mb-2">Services</h5>
                            <div class="border rounded-lg overflow-hidden">
                                <div class="grid grid-cols-4 gap-2 p-2 bg-gray-50 font-semibold text-xs text-gray-500">
                                    <span>Service</span>
                                    <span>Price</span>
                                    <span>Qty</span>
                                    <span>Total</span>
                                </div>
                                ${services.map(s => `
                                    <div class="grid grid-cols-4 gap-2 p-2 border-t">
                                        <span>${h(s.title)}</span>
                                        <span>${formatCurrency(s.price)}</span>
                                        <span>${s.quantity}</span>
                                        <span class="font-bold">${formatCurrency(s.price * s.quantity)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>

                        <div class="text-right border-t pt-4">
                            <p class="text-sm">Subtotal: ${formatCurrency(b.subtotal || 0)}</p>
                            ${parseFloat(b.discount || 0) > 0 ? `
                                <p class="text-sm text-red-600">
                                    ${discountLabel}: −${formatCurrency(b.discount || 0)}
                                </p>
                            ` : ''}
                            <p class="text-2xl font-bold text-teal">Total: ${formatCurrency(b.total_amount || 0)}</p>
                            ${b.notes ? `<p class="text-sm text-gray-500 mt-2">Notes: ${h(b.notes)}</p>` : ''}
                        </div>
                    </div>
                `;
                document.getElementById('viewContent').innerHTML = html;
            } else {
                document.getElementById('viewContent').innerHTML = `
                    <div class="text-center py-8 text-red-400">
                        <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                        <p>${h(data.message) || 'Failed to load billing'}</p>
                    </div>
                `;
            }
        })
        .catch(() => {
            document.getElementById('viewContent').innerHTML = `
                <div class="text-center py-8 text-red-400">
                    <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                    <p>Failed to load billing details</p>
                </div>
            `;
        });
}

// ========== DELETE BILLING ==========
function deleteBilling(id) {
    Swal.fire({
        title: 'Delete Invoice?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_billing');
            formData.append('csrf_token', DLP_CSRF);
            formData.append('id', id);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Deleted!', data.message, 'success');
                        liveSearch(currentPage);
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(() => {
                    Swal.fire('Error', 'Failed to delete billing', 'error');
                });
        }
    });
}

// ========== LIVE SEARCH ==========
function liveSearch(page = 1) {
    if (isSearching) return;
    isSearching = true;

    const search = document.getElementById('searchInput').value.trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;

    const searchContainer = document.getElementById('searchContainer');
    searchContainer.classList.add('loading');

    const formData = new FormData();
    formData.append('action', 'live_search');
    formData.append('search', search);
    formData.append('page', page);
    formData.append('filter_status', statusFilter);
    formData.append('date_from', dateFrom);
    formData.append('date_to', dateTo);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            searchContainer.classList.remove('loading');
            isSearching = false;

            if (data.success) {
                document.getElementById('billingRows').innerHTML = data.html;
                document.getElementById('billingPagination').innerHTML = data.pagination;
                currentPage = page;

                if (data.stats) {
                    updateStat('statTotal', data.stats.total);
                    updateStat('statToday', data.stats.today);
                    updateStat('statRevenue', data.stats.revenue);
                    updateStat('statUnpaid', data.stats.unpaid);
                }
            }
        })
        .catch(() => {
            searchContainer.classList.remove('loading');
            isSearching = false;
        });
}

function updateStat(elementId, value) {
    const el = document.getElementById(elementId);
    if (el && el.textContent !== value) {
        el.textContent = value;
        el.classList.remove('stat-update');
        void el.offsetWidth;
        el.classList.add('stat-update');
    }
}

// ========== RESET FILTERS ==========
function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    liveSearch(1);
}

// ========== SEARCH INPUT HANDLING ==========
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        liveSearch(1);
    }, 400);
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimeout);
        liveSearch(1);
    }
});

// ========== UTILITY FUNCTIONS ==========
function h(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function getStatusBadge(status) {
    const badges = {
        'paid': 'badge-success',
        'unpaid': 'badge-danger',
        'partial': 'badge-warning',
        'refunded': 'badge-info',
        'completed': 'badge-success',
        'confirmed': 'badge-success',
        'in-progress': 'badge-info',
        'pending': 'badge-warning',
        'cancelled': 'badge-danger'
    };
    return badges[status] || 'badge-info';
}

// ========== CLOSE MODAL ON CLICK OUTSIDE ==========
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// ========== CLOSE MODAL ON ESC ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
});

// ========== DISCOUNT INPUT LISTENERS ==========
document.getElementById('discount').addEventListener('input', updateTotals);
document.getElementById('discountPercent').addEventListener('input', updateTotals);
document.getElementById('discountName').addEventListener('input', updateTotals);
</script>
</body>
</html>