<?php
require '../../conn/conn.php';
require '../../functions/patient_functions.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

// ============================================================
// NOTE: Auto-sync of appointments → patients has been REMOVED.
// Patients are now created ONLY when an admin confirms the
// appointment from appointment_details.php (see appointments.php
// → update_status handler, "is_approving" block).
// ============================================================

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Live search handler
    if ($action === 'live_search') {
        $search = isset($_POST['search']) ? $_POST['search'] : '';
        $status_filter = isset($_POST['status']) ? $_POST['status'] : '';
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT * FROM patients WHERE 1=1";
        $count_sql = "SELECT COUNT(*) as total FROM patients WHERE 1=1";
        $params = [];
        $count_params = [];
        $types = "";
        $count_types = "";

        if (!empty($status_filter) && $status_filter !== 'all') {
            $sql .= " AND status = ?";
            $count_sql .= " AND status = ?";
            $params[] = $status_filter;
            $count_params[] = $status_filter;
            $types .= "s";
            $count_types .= "s";
        }

        if (!empty($search)) {
            $sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR patient_id LIKE ?)";
            $count_sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR patient_id LIKE ?)";
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
        $count_result = $count_stmt->get_result();
        $total_records = $count_result->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
        $count_stmt->close();

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $patients = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $html = '';
        if (count($patients) > 0) {
            foreach ($patients as $patient) {
                $html .= '<tr id="row-' . $patient['id'] . '" onclick="window.location.href=\'patient_details.php?id=' . $patient['id'] . '\'" style="cursor:pointer;">';
                $html .= '<td><span class="font-mono text-xs font-semibold text-dark-blue">#' . htmlspecialchars($patient['patient_id']) . '</span></td>';
                $html .= '<td><div class="font-medium text-dark-blue">' . htmlspecialchars($patient['full_name']) . '</div>';
                $html .= '<div class="text-xs text-gray-500">' . htmlspecialchars($patient['email']) . '</div></td>';
                $html .= '<td><div class="text-sm">' . htmlspecialchars($patient['phone']) . '</div></td>';
                $html .= '<td><div class="text-sm">' . ($patient['age'] ? htmlspecialchars($patient['age']) . ' yrs' : '—') . '</div></td>';
                $html .= '<td><div class="text-sm">' . htmlspecialchars($patient['gender'] ?? '—') . '</div></td>';
                $html .= '<td>';
                if ($patient['status'] === 'active') {
                    $html .= '<span class="status-badge status-active"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Active</span>';
                } elseif ($patient['status'] === 'inactive') {
                    $html .= '<span class="status-badge status-inactive"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Inactive</span>';
                } else {
                    $html .= '<span class="status-badge status-pending"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Pending</span>';
                }
                $html .= '</td>';
                $html .= '<td><div class="text-xs"><div class="font-medium">' . date('M d, Y', strtotime($patient['created_at'])) . '</div>';
                $html .= '<div class="text-gray-400">' . date('g:i A', strtotime($patient['created_at'])) . '</div></div></td>';
                $html .= '<td><div class="action-icons flex items-center justify-center gap-0" onclick="event.stopPropagation();">';
                $html .= '<button onclick="viewPatient(' . $patient['id'] . ')" title="View Details" class="hover:bg-teal-light"><i class="fas fa-eye"></i></button>';
                $html .= '<button onclick="editPatient(' . $patient['id'] . ')" title="Edit Patient" class="hover:bg-teal-light"><i class="fas fa-pen"></i></button>';
                $html .= '<button onclick="deletePatient(' . $patient['id'] . ')" class="text-danger hover:bg-red-50" title="Delete Patient"><i class="fas fa-trash-alt"></i></button>';
                $html .= '</div></td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-users-slash"></i><h3>No patients found</h3><p>Try adjusting your filters or add a new patient.</p></div></td></tr>';
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

        $response = [
            'success' => true,
            'html' => $html,
            'pagination' => $pagination_html,
            'total' => $total_records,
            'page' => $page,
            'total_pages' => $total_pages
        ];
        echo json_encode($response);
        exit;
    }

    // Delete patient
    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Patient deleted successfully.'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete patient.'];
        }
        $stmt->close();
        echo json_encode($response);
        exit;
    }

    // Get patient for edit/view
    if ($action === 'get' && $id > 0) {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $r = $stmt->get_result();
        $patient = $r->fetch_assoc();
        $stmt->close();

        if ($patient) {
            $response = ['success' => true, 'data' => $patient];
        } else {
            $response = ['success' => false, 'message' => 'Patient not found.'];
        }
        echo json_encode($response);
        exit;
    }

    // Create patient
    if ($action === 'create') {
        $patient_id = trim($_POST['patient_id'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($full_name !== '' && $phone !== '') {
            if (empty($patient_id)) {
                $patient_id = 'PAT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
            }

            $stmt = $conn->prepare("INSERT INTO patients (patient_id, full_name, age, gender, phone, email, address, status, source, appointment_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'walkin', 0, NOW())");
            $stmt->bind_param("ssisssss", $patient_id, $full_name, $age, $gender, $phone, $email, $address, $status);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Patient created successfully.', 'id' => $stmt->insert_id];
            } else {
                $response = ['success' => false, 'message' => 'Failed to create patient.'];
            }
            $stmt->close();
        } else {
            $response = ['success' => false, 'message' => 'Please fill in all required fields.'];
        }
        echo json_encode($response);
        exit;
    }

    // Update patient
    if ($action === 'update' && $id > 0) {
        $patient_id = trim($_POST['patient_id'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($full_name !== '' && $phone !== '') {
            $stmt = $conn->prepare("UPDATE patients SET patient_id = ?, full_name = ?, age = ?, gender = ?, phone = ?, email = ?, address = ?, status = ? WHERE id = ?");
            $stmt->bind_param("ssisssssi", $patient_id, $full_name, $age, $gender, $phone, $email, $address, $status, $id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Patient updated successfully.'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update patient.'];
            }
            $stmt->close();
        } else {
            $response = ['success' => false, 'message' => 'Please fill in all required fields.'];
        }
        echo json_encode($response);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Get filter parameters for initial load
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$sql = "SELECT * FROM patients WHERE 1=1";
$count_sql = "SELECT COUNT(*) as total FROM patients WHERE 1=1";
$params = [];
$count_params = [];
$types = "";
$count_types = "";

if (!empty($status_filter) && $status_filter !== 'all') {
    $sql .= " AND status = ?";
    $count_sql .= " AND status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
    $types .= "s";
    $count_types .= "s";
}

if (!empty($search)) {
    $sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR patient_id LIKE ?)";
    $count_sql .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR patient_id LIKE ?)";
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
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$count_stmt->close();

$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$patients = $result->fetch_all(MYSQLI_ASSOC);
$total = count($patients);
$stmt->close();

$stats = [];
$statuses = ['active', 'inactive', 'pending'];
foreach ($statuses as $s) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM patients WHERE status = ?");
    $stmt->bind_param("s", $s);
    $stmt->execute();
    $r = $stmt->get_result();
    $stats[$s] = $r->fetch_assoc()['count'];
    $stmt->close();
}
$total_all = array_sum($stats);

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients · Dental Clinic</title>
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
        .patients-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 850px;
        }
        .patients-table th {
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
        .patients-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f1f4f8;
            vertical-align: middle;
        }
        .patients-table tr:hover td { background: #fafbfc; }
        .patients-table tr:last-child td { border-bottom: none; }

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            min-width: 80px;
            text-align: center;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-pending { background: #fef3d7; color: #b45309; }

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

        @media (min-width: 1024px) { .main-content { margin-left: 260px; } }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }
        @media (max-width: 640px) {
            .stat-card { padding: 1rem; }
            .stat-card .text-3xl { font-size: 1.5rem; }
            .dashboard-card { padding: 1rem; }
            .patients-table { min-width: 700px; font-size: 12px; }
            .patients-table th, .patients-table td { padding: 8px 6px; }
        }

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

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 48px; color: #d0d7e2; margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; font-weight: 600; color: var(--navy); margin-bottom: 8px; }
        .empty-state p { color: var(--slate); font-size: 14px; }

        .search-loading { position: relative; }
        .search-loading .search-loader {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        .search-loading.loading .search-loader { display: block; }
        .search-loader i { color: var(--teal); font-size: 14px; }

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
            max-width: 600px;
            box-shadow: 0 24px 60px rgba(11,22,40,.35);
            overflow: hidden;
            animation: modalPop .18s ease-out;
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
        .modal-body { padding: 20px 24px; max-height: 70vh; overflow-y: auto; background: #f8fafc; }
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            padding: 16px 24px;
            background: white;
            border-top: 1px solid #f0f3f7;
            border-radius: 0 0 18px 18px;
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
        .view-meta {
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px dashed #e2e8f0;
            padding-top: 12px;
            margin-top: 4px;
            line-height: 1.6;
        }

        .patients-table tbody tr {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .patients-table tbody tr:hover { background-color: #f8fafc; }
        .patients-table tbody tr .action-icons button { cursor: pointer; }
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
                        Patients
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">
                        Manage all registered patients · <span id="totalCount"><?= $total_all ?></span> total
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button onclick="showCreateModal()" class="btn-primary">
                        <i class="fas fa-plus-circle"></i> New Patient
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statTotal"><?= $total_all ?></p>
                            <p class="text-xs text-gray-500 mt-1">all patients</p>
                        </div>
                        <div class="stat-icon bg-teal-light text-teal">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card" onclick="filterByStatus('active')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Active</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statActive"><?= $stats['active'] ?></p>
                            <p class="text-xs text-green-600 font-medium mt-1"><i class="fas fa-check-circle mr-1"></i> active patients</p>
                        </div>
                        <div class="stat-icon bg-green-100 text-green-600">
                            <i class="fas fa-user-check"></i>
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
                <div class="stat-card" onclick="filterByStatus('inactive')">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Inactive</p>
                            <p class="text-3xl font-bold text-dark-blue mt-1" id="statInactive"><?= $stats['inactive'] ?></p>
                            <p class="text-xs text-red-500 font-medium mt-1">inactive accounts</p>
                        </div>
                        <div class="stat-icon bg-red-50 text-red-500">
                            <i class="fas fa-user-slash"></i>
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
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group flex-1 min-w-[200px]">
                    <label for="searchInput" class="sr-only">Search</label>
                    <div class="relative w-full search-loading" id="searchContainer">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="searchInput"
                               placeholder="Search by name, phone, email, or ID..."
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

            <!-- Patients Table -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span><i class="fas fa-list-ul mr-2 text-teal"></i> Patient List</span>
                    <span class="text-xs text-gray-400 bg-gray-50 px-3 py-1 rounded-full" id="recordCount">
                        <?= $total ?> of <?= $total_records ?> records
                    </span>
                </div>

                <div class="table-container">
                    <table class="patients-table">
                        <thead>
                            <tr>
                                <th>Patient ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-center" style="min-width:100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="patientsBody">
                            <?php if ($total > 0): ?>
                            <?php foreach ($patients as $patient): ?>
                            <tr id="row-<?= $patient['id'] ?>" onclick="window.location.href='patient_details.php?id=<?= $patient['id'] ?>'">
                                <td><span class="font-mono text-xs font-semibold text-dark-blue">#<?= htmlspecialchars($patient['patient_id']) ?></span></td>
                                <td>
                                    <div class="font-medium text-dark-blue"><?= htmlspecialchars($patient['full_name']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($patient['email']) ?></div>
                                </td>
                                <td><div class="text-sm"><?= htmlspecialchars($patient['phone']) ?></div></td>
                                <td><div class="text-sm"><?= $patient['age'] ? htmlspecialchars($patient['age']) . ' yrs' : '—' ?></div></td>
                                <td><div class="text-sm"><?= htmlspecialchars($patient['gender'] ?? '—') ?></div></td>
                                <td>
                                    <?php if ($patient['status'] === 'active'): ?>
                                    <span class="status-badge status-active"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Active</span>
                                    <?php elseif ($patient['status'] === 'inactive'): ?>
                                    <span class="status-badge status-inactive"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Inactive</span>
                                    <?php else: ?>
                                    <span class="status-badge status-pending"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-xs">
                                        <div class="font-medium"><?= date('M d, Y', strtotime($patient['created_at'])) ?></div>
                                        <div class="text-gray-400"><?= date('g:i A', strtotime($patient['created_at'])) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-icons flex items-center justify-center gap-0" onclick="event.stopPropagation();">
                                        <button onclick="window.location.href='patient_details.php?id=<?= $patient['id'] ?>'" title="View Details" class="hover:bg-teal-light">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="editPatient(<?= $patient['id'] ?>)" title="Edit Patient" class="hover:bg-teal-light">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button onclick="deletePatient(<?= $patient['id'] ?>)" class="text-danger hover:bg-red-50" title="Delete Patient">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-users-slash"></i>
                                        <h3>No patients found</h3>
                                        <p>Try adjusting your filters or add a new patient.</p>
                                        <button onclick="showCreateModal()" class="btn-primary mt-4 inline-flex">
                                            <i class="fas fa-plus-circle"></i> Add Patient
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
                Wansi Calacat Dental Clinic · Patients Management
            </div>
        </div>
    </main>
</div>

<!-- Patient Modal (manual entry only) -->
<div id="patientModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header" style="background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%); border-radius: 18px 18px 0 0; padding: 18px 24px;">
            <h3 id="patientModalTitle" style="color: white; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-user-plus" style="color: #0d9e8c;"></i>
                New Patient
            </h3>
            <button type="button" class="modal-close-btn" onclick="closePatientModal()" style="color: rgba(255,255,255,0.6); font-size: 22px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="modal-body" style="padding: 24px 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc;">
            <form id="patientForm" onsubmit="return false;">
                <input type="hidden" id="patientId" value="">

                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-user-circle" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Personal Information</h4>
                    </div>

                    <div class="form-field" style="margin-bottom: 14px;">
                        <label for="patientPatientId">Patient ID</label>
                        <input type="text" id="patientPatientId" placeholder="Auto-generated if left empty">
                    </div>

                    <div class="form-field" style="margin-bottom: 14px;">
                        <label for="patientFullName">Full Name <span style="color: #e74c3c;">*</span></label>
                        <input type="text" id="patientFullName" placeholder="Enter patient's full name" required>
                    </div>

                    <div class="form-row">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="patientAge">Age</label>
                            <input type="number" id="patientAge" placeholder="Age">
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="patientGender">Gender</label>
                            <select id="patientGender">
                                <option value="">Select gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #eef2f6; padding-bottom: 10px;">
                        <i class="fas fa-address-card" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Contact Information</h4>
                    </div>

                    <div class="form-row">
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="patientPhone">Phone <span style="color: #e74c3c;">*</span></label>
                            <input type="text" id="patientPhone" placeholder="09xx xxx xxxx" required>
                        </div>
                        <div class="form-field" style="margin-bottom: 0;">
                            <label for="patientEmail">Email</label>
                            <input type="email" id="patientEmail" placeholder="patient@email.com">
                        </div>
                    </div>
                </div>

                <div style="background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <i class="fas fa-map-marker-alt" style="color: #0d9e8c; font-size: 16px;"></i>
                        <h4 style="font-size: 13px; font-weight: 700; color: #0b1628; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Address</h4>
                    </div>
                    <div class="form-field" style="margin-bottom: 0;">
                        <label for="patientAddress">Address</label>
                        <textarea id="patientAddress" rows="2" placeholder="Patient's address"></textarea>
                    </div>
                </div>

                <div style="background: white; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                    <div class="form-field" style="margin-bottom: 0;">
                        <label for="patientStatus">Status</label>
                        <select id="patientStatus">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div id="patientViewMeta" class="view-meta hidden"></div>
            </form>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closePatientModal()" style="padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 2px solid #e2e8f0; color: #64748b; background: white;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="patientSaveBtn" class="btn-primary" onclick="savePatient()" style="padding: 10px 26px; border-radius: 10px; font-weight: 600; font-size: 13px; background: linear-gradient(135deg, #0d9e8c 0%, #0a7d6e 100%); color: white; border: none;">
                <i class="fas fa-save"></i> Add Patient
            </button>
        </div>
    </div>
</div>

<script>
// ===== GLOBAL VARIABLES =====
let searchTimeout = null;
let currentPage = 1;
let patientMode = 'create';

// ===== SEARCH FUNCTIONS =====
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

    fetch('patients.php?ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (searchContainer) searchContainer.classList.remove('loading');

        if (data.success) {
            const tbody = document.getElementById('patientsBody');
            if (tbody) tbody.innerHTML = data.html;

            const paginationContainer = document.getElementById('paginationContainer');
            if (paginationContainer) paginationContainer.innerHTML = data.pagination;

            const recordCount = document.getElementById('recordCount');
            if (recordCount) recordCount.textContent = data.total + ' records';

            const totalCount = document.getElementById('totalCount');
            if (totalCount) totalCount.textContent = data.total;

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

    document.getElementById('patientModal').addEventListener('click', function(e) {
        if (e.target === this) closePatientModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closePatientModal();
    });
});

// ===== MODAL FUNCTIONS =====
function openPatientModal() {
    document.getElementById('patientModal').classList.add('open');
}

function closePatientModal() {
    document.getElementById('patientModal').classList.remove('open');
    document.getElementById('patientForm').reset();
    document.getElementById('patientId').value = '';
    document.getElementById('patientSaveBtn').style.display = 'inline-flex';
    document.getElementById('patientViewMeta').classList.add('hidden');
    patientMode = 'create';
}

function setPatientFormDisabled(disabled) {
    const fields = ['patientPatientId', 'patientFullName', 'patientAge', 'patientGender',
                    'patientPhone', 'patientEmail', 'patientAddress', 'patientStatus'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
    });
}

function populatePatientForm(data) {
    document.getElementById('patientId').value = data.id || '';
    document.getElementById('patientPatientId').value = data.patient_id || '';
    document.getElementById('patientFullName').value = data.full_name || '';
    document.getElementById('patientAge').value = data.age || '';
    document.getElementById('patientGender').value = data.gender || '';
    document.getElementById('patientPhone').value = data.phone || '';
    document.getElementById('patientEmail').value = data.email || '';
    document.getElementById('patientAddress').value = data.address || '';
    document.getElementById('patientStatus').value = data.status || 'active';
}

function showCreateModal() {
    patientMode = 'create';
    document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-user-plus" style="color: #0d9e8c;"></i> New Patient';
    document.getElementById('patientId').value = '';
    document.getElementById('patientPatientId').value = '';
    document.getElementById('patientFullName').value = '';
    document.getElementById('patientAge').value = '';
    document.getElementById('patientGender').value = '';
    document.getElementById('patientPhone').value = '';
    document.getElementById('patientEmail').value = '';
    document.getElementById('patientAddress').value = '';
    document.getElementById('patientStatus').value = 'active';
    setPatientFormDisabled(false);
    document.getElementById('patientSaveBtn').style.display = 'inline-flex';
    document.getElementById('patientSaveBtn').innerHTML = '<i class="fas fa-save"></i> Add Patient';
    document.getElementById('patientViewMeta').classList.add('hidden');
    openPatientModal();
}

function editPatient(id) {
    patientMode = 'edit';
    fetchPatient(id, (res) => {
        if (!res.success) {
            Swal.fire({ title: 'Error!', text: res.message, icon: 'error' });
            return;
        }
        document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-pen" style="color: #0d9e8c;"></i> Edit Patient #' + res.data.patient_id;
        populatePatientForm(res.data);
        setPatientFormDisabled(false);
        document.getElementById('patientSaveBtn').style.display = 'inline-flex';
        document.getElementById('patientSaveBtn').innerHTML = '<i class="fas fa-save"></i> Update Patient';
        document.getElementById('patientViewMeta').classList.add('hidden');
        openPatientModal();
    });
}

function fetchPatient(id, callback) {
    fetch('patients.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get&id=' + id
    })
    .then(response => response.json())
    .then(data => callback(data))
    .catch(() => Swal.fire({ title: 'Error!', text: 'Could not load patient.', icon: 'error' }));
}

function savePatient() {
    const id = document.getElementById('patientId').value;
    const patient_id = document.getElementById('patientPatientId').value.trim();
    const full_name = document.getElementById('patientFullName').value.trim();
    const age = document.getElementById('patientAge').value.trim();
    const gender = document.getElementById('patientGender').value;
    const phone = document.getElementById('patientPhone').value.trim();
    const email = document.getElementById('patientEmail').value.trim();
    const address = document.getElementById('patientAddress').value.trim();
    const status = document.getElementById('patientStatus').value;

    if (!full_name || !phone) {
        Swal.fire({
            title: 'Missing Info',
            text: 'Please fill in full name and phone number.',
            icon: 'warning'
        });
        return;
    }

    const action = patientMode === 'edit' ? 'update' : 'create';
    const formData = new FormData();
    formData.append('action', action);
    if (id) formData.append('id', id);
    formData.append('patient_id', patient_id);
    formData.append('full_name', full_name);
    formData.append('age', age);
    formData.append('gender', gender);
    formData.append('phone', phone);
    formData.append('email', email);
    formData.append('address', address);
    formData.append('status', status);

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('patients.php?ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closePatientModal();
            Swal.fire({
                title: 'Success!',
                text: data.message,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => liveSearch(currentPage));
        } else {
            Swal.fire({ title: 'Error!', text: data.message || 'Something went wrong.', icon: 'error' });
        }
    })
    .catch(() => {
        Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' });
    });
}

function deletePatient(id) {
    Swal.fire({
        title: 'Delete Patient',
        text: 'Are you sure you want to delete this patient? This action cannot be undone!',
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

            fetch('patients.php?ajax=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + id
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
            .catch(() => {
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
</script>

<?php
$conn->close();
?>
</body>
</html>