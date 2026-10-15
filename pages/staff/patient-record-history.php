<?php
require '../../conn/conn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// ========== FUNCTIONS ==========
function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

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

function formatDate($timestamp) {
    if (empty($timestamp)) return '—';
    return date('M j, Y g:i A', strtotime($timestamp));
}

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// ========== DENTAL CHART HELPERS (read-only) ==========
function toothNameFromNum($num) {
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
    return $names[(int)$num] ?? 'Tooth';
}

// ========== DATABASE OPERATIONS ==========

// Get paginated archive records
function getArchivesPaginated($conn, $search = '', $page = 1, $limit = 15) {
    $page = max(1, (int)$page);
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM patient_record_archive WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM patient_record_archive WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($search)) {
        $sql .= " AND (full_name LIKE ? OR receipt_code LIKE ? OR generated_by LIKE ?)";
        $count_sql .= " AND (full_name LIKE ? OR receipt_code LIKE ? OR generated_by LIKE ?)";
        $like = "%$search%";
        $params = [$like, $like, $like];
        $types = "sss";
    }

    // Count total records
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total / $limit));

    // Clamp page to valid range
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
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

// Get statistics
function getArchiveStats($conn) {
    $stats = [];
    
    // Total records
    $result = $conn->query("SELECT COUNT(*) as total FROM patient_record_archive");
    $stats['total'] = $result->fetch_assoc()['total'] ?? 0;
    
    // Today's records
    $result = $conn->query("SELECT COUNT(*) as today FROM patient_record_archive WHERE DATE(created_at) = CURDATE()");
    $stats['today'] = $result->fetch_assoc()['today'] ?? 0;
    
    // Unique patients
    $result = $conn->query("SELECT COUNT(DISTINCT patient_key) as unique_patients FROM patient_record_archive WHERE patient_key IS NOT NULL");
    $stats['unique_patients'] = $result->fetch_assoc()['unique_patients'] ?? 0;
    
    return $stats;
}

// Get record by ID
function getArchiveById($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM patient_record_archive WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {
        return false;
    }
}

// Try to extract the linked appointment id from a snapshot JSON blob
function resolveAppointmentId($record) {
    if (empty($record['snapshot_json'])) return 0;

    $snap = json_decode($record['snapshot_json'], true);
    if (!is_array($snap)) return 0;

    $candidates = [
        $snap['appointment']['id']     ?? null,
        $snap['appointment_id']        ?? null,
        $snap['appointment']['appt_id'] ?? null,
    ];

    foreach ($candidates as $c) {
        if (!empty($c) && is_numeric($c)) return (int)$c;
    }

    return 0;
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    switch ($_POST['action']) {
        case 'live_search':
            $search = trim($_POST['search'] ?? '');
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;

            $result = getArchivesPaginated($conn, $search, $page, $limit);

            $html = '';
            if (empty($result['rows'])) {
                $html = '<div class="text-center py-12 text-gray-400"><i class="fas fa-archive text-4xl mb-3 block"></i><p class="text-lg">No archive records found</p><p class="text-sm">Try adjusting your search</p></div>';
            } else {
                foreach ($result['rows'] as $record) {
                    $html .= renderArchiveRow($record);
                }
            }

            $response['success'] = true;
            $response['html'] = $html;
            $response['pagination'] = renderArchivePagination($result['page'], $result['total_pages'], $result['total']);
            $response['total'] = $result['total'];
            break;
            
        case 'get_record':
            $id = $_POST['id'] ?? 0;
            $record = getArchiveById($conn, $id);
            if ($record) {
                $response['success'] = true;
                $response['record'] = $record;
                $response['appointment_id'] = resolveAppointmentId($record);
            } else {
                $response['message'] = 'Record not found';
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// ========== ROW / PAGINATION RENDERERS ==========
function renderArchiveRow($record) {
    // Resolve the appointment id so the Print button can link directly
    $appointmentId = resolveAppointmentId($record);

    ob_start();
    ?>
    <div class="archive-table-row archive-item" 
         data-id="<?= $record['id'] ?>"
         data-receipt="<?= h($record['receipt_code']) ?>"
         data-name="<?= h($record['full_name']) ?>"
         data-generated="<?= h($record['generated_by']) ?>"
         data-appointment-id="<?= (int)$appointmentId ?>">
        
        <span class="font-mono text-sm font-medium text-teal"><?= h($record['receipt_code'] ?? '—') ?></span>
        
        <span class="font-medium flex items-center gap-3">
            <div class="avatar bg-teal-light text-teal">
                <?= getInitials($record['full_name'] ?? 'Unknown') ?>
            </div>
            <?= h($record['full_name'] ?? 'Unknown Patient') ?>
        </span>
        
        <span class="text-gray-600 text-sm"><?= h($record['generated_by'] ?? 'System') ?></span>
        
        <span class="text-gray-600 text-sm"><?= formatDate($record['created_at']) ?></span>
        
        <span class="text-right flex items-center justify-end gap-1">
            <button class="action-btn view" onclick="viewRecord(<?= $record['id'] ?>)" title="View Details">
                <i class="fas fa-eye"></i>
            </button>
            <button class="action-btn print" 
                    onclick="printFromArchive(<?= $record['id'] ?>, <?= (int)$appointmentId ?>)" 
                    title="Print Dental Certificate">
                <i class="fas fa-print"></i>
            </button>
        </span>
    </div>
    <?php
    return ob_get_clean();
}

function renderArchivePagination($page, $total_pages, $total) {
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

// ========== GET DATA ==========
$limit = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$archiveResult = getArchivesPaginated($conn, '', $page, $limit);
$records = $archiveResult['rows'];
$stats = getArchiveStats($conn);

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patient Archive · Dental Clinic Dashboard</title>
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

    .stat-card {
      background: white;
      border-radius: 18px;
      padding: 1.5rem 1.25rem;
      box-shadow: var(--shadow-card);
      border: 1px solid #eef2f6;
      transition: all 0.2s;
    }
    .stat-card:hover { border-color: var(--teal); box-shadow: 0 12px 32px rgba(11,22,40,.08); }

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

    .archive-table-row {
      display: grid;
      grid-template-columns: 1.2fr 1.8fr 1.2fr 1.5fr 0.8fr;
      padding: 12px 0;
      border-bottom: 1px solid #f1f4f8;
      font-size: 13px;
      align-items: center;
    }
    .archive-table-row.head {
      font-weight: 600;
      color: var(--slate);
      font-size: 11px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
      border-bottom: 2px solid #e9edf2;
      padding-bottom: 10px;
    }

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

    @media (min-width: 1024px) {
      .main-content { margin-left: 260px; }
    }
    @media (max-width: 1023px) { .main-content { margin-left: 0; } }

    @media (max-width: 640px) {
      .archive-table-row {
        grid-template-columns: 1fr 1fr;
        gap: 4px 8px;
        padding: 12px 0;
        font-size: 12px;
      }
      .archive-table-row.head { display: none; }
      .stat-card { padding: 1rem; }
      .stat-card .text-3xl { font-size: 1.5rem; }
      .dashboard-card { padding: 1rem; }
    }

    @media (max-width: 1024px) and (min-width: 641px) {
      .archive-table-row {
        grid-template-columns: 1fr 1.5fr 1fr 1.2fr 0.8fr;
        font-size: 12px;
      }
    }

    .search-input {
      padding: 8px 16px 8px 40px;
      border-radius: 10px;
      border: 1.5px solid #e2e8f0;
      font-size: 13px;
      background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 12px center;
      background-size: 16px;
      transition: all 0.2s;
      width: 100%;
      max-width: 280px;
    }
    .search-input:focus {
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
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: inherit;
    }
    .action-btn:hover { background: #f1f4f8; }
    .action-btn.view:hover { color: var(--teal); }
    .action-btn.print:hover { color: #7c3aed; background: #ede9fe; }

    .search-loading { position: relative; }
    .search-loading .search-loader {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      display: none;
    }
    .search-loading.loading .search-loader { display: block; }
    .search-loader i { color: var(--teal); font-size: 13px; }

    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    /* Snapshot viewer - improved styling */
    .snapshot-section {
      background: #f8fafc;
      border-radius: 10px;
      padding: 16px;
      margin-bottom: 12px;
    }
    .snapshot-section h4 {
      font-size: 13px;
      font-weight: 700;
      color: var(--navy);
      margin: 0 0 8px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .snapshot-section h4 i {
      color: var(--teal);
      width: 18px;
    }
    .snapshot-section .field-row {
      display: flex;
      padding: 4px 0;
      font-size: 13px;
      border-bottom: 1px solid #eef2f6;
    }
    .snapshot-section .field-row:last-child {
      border-bottom: none;
    }
    .snapshot-section .field-label {
      font-weight: 600;
      color: var(--slate);
      min-width: 120px;
      flex-shrink: 0;
    }
    .snapshot-section .field-value {
      color: var(--navy);
    }
    .snapshot-section .field-value .badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600;
      margin: 2px 4px 2px 0;
    }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    .badge-danger { background: #fce4e4; color: #991b1b; }

    .service-tag {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600;
      background: var(--teal-light);
      color: var(--teal);
      margin: 2px 4px 2px 0;
    }

    .modal-content {
      animation: slideUp 0.3s ease;
      max-height: 90vh;
      overflow-y: auto;
    }
    .modal-content::-webkit-scrollbar {
      width: 6px;
    }
    .modal-content::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 10px;
    }
    .modal-content::-webkit-scrollbar-thumb {
      background: var(--teal);
      border-radius: 10px;
    }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }

    /* Print button in modal */
    .btn-ghost {
      background: #f1f4f8;
      color: #64748b;
      padding: 10px 22px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 13px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }
    .btn-ghost:hover { background: #e2e8f0; }

    /* ===== Dental Chart (archive view) ===== */
    .chart-legend {
      display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 14px;
    }
    .chart-legend .legend-item {
      display: flex; align-items: center; gap: 6px;
      font-size: 11px; font-weight: 600; color: var(--slate);
    }
    .chart-legend .legend-dot {
      width: 11px; height: 11px; border-radius: 50%;
      flex-shrink: 0; border: 1px solid rgba(0,0,0,.08);
    }

    .arch-label {
      text-align: center; font-size: 11px; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: 1px; padding: 4px 0 8px;
      border-bottom: 1px dashed #e2e8f0; margin-bottom: 8px;
    }
    .arch-row {
      display: flex; justify-content: center; gap: 4px; flex-wrap: wrap; margin-bottom: 6px;
    }
    .arch-divider { border-top: 1px dashed #e2e8f0; margin: 10px 0 12px; }

    .tooth-cell {
      display: flex; flex-direction: column; align-items: center; gap: 4px;
      padding: 6px 4px; border-radius: 10px; width: 40px; min-width: 40px;
      cursor: default;
    }
    .tooth-img-wrap {
      width: 34px; height: 54px; border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      background: white; border: 2.5px solid var(--tooth-color, #cbd5e1);
      box-shadow: 0 1px 4px rgba(11,22,40,.06);
    }
    .tooth-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .tooth-cell[data-condition="missing"] .tooth-img-wrap img { opacity: .25; }
    .tooth-num { font-size: 13px; font-weight: 700; color: var(--slate); line-height: 1.2; }
    .tooth-name {
      font-size: 9.5px; font-weight: 600; color: #94a3b8;
      text-align: center; line-height: 1.1; max-width: 58px;
    }
    .tooth-condition-badge {
      font-size: 8px; font-weight: 700; text-transform: uppercase;
      padding: 1px 6px; border-radius: 8px; background: rgba(0,0,0,0.05);
      color: #94a3b8; margin-top: 1px; line-height: 1.3;
      max-width: 56px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      text-align: center;
    }

    .chart-summary {
      display: flex; flex-wrap: wrap; gap: 18px;
      padding-bottom: 12px; margin-bottom: 14px;
      border-bottom: 1px solid #eef2f6;
    }
    .chart-summary .stat span.label {
      font-size: 10.5px; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .4px;
      display: block; margin-bottom: 3px;
    }
    .chart-summary .stat span.val {
      font-size: 13px; font-weight: 700; color: var(--navy);
    }

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
  </style>
</head>
<body>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

  <main class="py-6 px-4">
    <div class="container-modern">

      <!-- HEADER -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold text-dark-blue">Appointments Archive</h1>
          <p class="text-sm text-gray-500">History of Completed patient appointment</p>
        </div>
        <div class="flex flex-wrap gap-2">
        
        </div>
      </div>

      <!-- STATS CARDS -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Records</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= number_format($stats['total']) ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Today's Records</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= number_format($stats['today']) ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Unique Patients</p>
          <p class="text-3xl font-bold text-dark-blue mt-1"><?= number_format($stats['unique_patients']) ?></p>
        </div>
        <div class="stat-card">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Last Updated</p>
          <p class="text-lg font-bold text-dark-blue mt-1"><?= date('M j, Y') ?></p>
        </div>
      </div>

      <!-- RECORDS TABLE -->
      <div class="dashboard-card">
        <div class="card-title">
          <span><i class="fas fa-archive mr-2 text-teal"></i> All Archive Records</span>
          <div class="flex items-center gap-3">
            <div class="relative search-loading" id="searchContainer">
              <input type="text" class="search-input" id="searchInput" placeholder="Search records..." autocomplete="off">
              <div class="search-loader"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
          </div>
        </div>

        <div class="mt-4 overflow-x-auto" id="archiveTableContainer">
          <div class="archive-table-row head">
            <span>Receipt #</span>
            <span>Patient Name</span>
            <span>Generated By</span>
            <span>Created At</span>
            <span class="text-right">Actions</span>
          </div>

          <div id="archiveRows">
            <?php if (empty($records)): ?>
              <div class="text-center py-12 text-gray-400">
                <i class="fas fa-archive text-4xl mb-3 block"></i>
                <p class="text-lg">No archive records found</p>
                <p class="text-sm">Records will appear here when patients are archived</p>
              </div>
            <?php else: ?>
              <?php foreach ($records as $record): ?>
                <?= renderArchiveRow($record) ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div id="archivePagination">
          <?= renderArchivePagination($archiveResult['page'], $archiveResult['total_pages'], $archiveResult['total']) ?>
        </div>
      </div>

      <div class="mt-8 text-center text-[11px] text-gray-400 border-t border-gray-200 pt-5">
        <i class="fas fa-tooth mr-1 text-teal/50"></i> Wansi Calacat Dental Clinic · Patient Archive
      </div>
    </div>
  </main>
</div>

<!-- ===== VIEW RECORD MODAL ===== -->
<div id="viewModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl max-w-4xl w-full p-6 shadow-2xl modal-content">
    <div class="flex items-center justify-between mb-4 sticky top-0 bg-white z-10 pb-2">
      <h3 class="text-xl font-bold text-dark-blue">
        <i class="fas fa-file-medical text-teal mr-2"></i> Record Details
      </h3>
      <button onclick="closeViewModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    
    <div id="recordDetails">
      <!-- Loading state -->
      <div id="loadingState" class="text-center py-8">
        <i class="fas fa-spinner fa-spin text-2xl text-teal"></i>
        <p class="text-gray-400 mt-2">Loading record details...</p>
      </div>
      
      <!-- Content will be populated by JavaScript -->
      <div id="recordContent" style="display:none;"></div>
    </div>
    
    <div class="mt-4 flex justify-end gap-2 sticky bottom-0 bg-white pt-2 border-t border-gray-100">
      <button onclick="closeViewModal()" class="btn-ghost">
        <i class="fas fa-times"></i> Close
      </button>
      <button id="modalPrintBtn" onclick="printCurrentRecord()" class="btn-primary">
        <i class="fas fa-print"></i> Print Dental Certificate
      </button>
    </div>
  </div>
</div>

<script>
// ========== LIVE SEARCH / PAGINATION ==========
let searchTimeout = null;
let currentPage = <?= (int)$archiveResult['page'] ?>;
let currentModalAppointmentId = null;

function liveSearch(page = 1) {
    const search = document.getElementById('searchInput').value.trim();
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');

    const formData = new FormData();
    formData.append('action', 'live_search');
    formData.append('search', search);
    formData.append('page', page);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (searchContainer) searchContainer.classList.remove('loading');
            if (data.success) {
                document.getElementById('archiveRows').innerHTML = data.html;
                document.getElementById('archivePagination').innerHTML = data.pagination;
                currentPage = page;
            }
        })
        .catch(() => {
            if (searchContainer) searchContainer.classList.remove('loading');
        });
}

document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const searchContainer = document.getElementById('searchContainer');
    if (searchContainer) searchContainer.classList.add('loading');
    searchTimeout = setTimeout(() => liveSearch(1), 400);
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimeout);
        liveSearch(1);
    }
});

// ========== VIEW RECORD ==========
function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
    currentModalAppointmentId = null;
}

function formatDateDisplay(dateStr) {
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

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}

function getStatusBadge(status) {
    const badges = {
        'confirmed': 'badge-success',
        'approved': 'badge-info',
        'in_progress': 'badge-warning',
        'completed': 'badge-success',
        'cancelled': 'badge-danger'
    };
    return badges[status] || 'badge-info';
}

// ========== DENTAL CHART (read-only, from snapshot) ==========
const DENTAL_CONDITIONS = {
  healthy:    { label: 'Healthy',        color: '#cbd5e1' },
  caries:     { label: 'Caries / Decay', color: '#dc3545' },
  filled:     { label: 'Filled',         color: '#1e40af' },
  crown:      { label: 'Crown',          color: '#b45309' },
  root_canal: { label: 'Root Canal',     color: '#7c3aed' },
  missing:    { label: 'Missing',        color: '#64748b' },
  extraction: { label: 'For Extraction', color: '#7f1d1d' },
  impacted:   { label: 'Impacted',       color: '#ea580c' },
  fractured:  { label: 'Fractured',      color: '#db2777' },
  other:      { label: 'Other',          color: '#0891b2' },
};

const TOOTH_NAMES_JS = {
  18:'3rd Molar',17:'2nd Molar',16:'1st Molar',15:'2nd Premolar',14:'1st Premolar',
  13:'Canine',12:'Lateral Incisor',11:'Central Incisor',
  21:'Central Incisor',22:'Lateral Incisor',23:'Canine',24:'1st Premolar',25:'2nd Premolar',
  26:'1st Molar',27:'2nd Molar',28:'3rd Molar',
  31:'Central Incisor',32:'Lateral Incisor',33:'Canine',34:'1st Premolar',35:'2nd Premolar',
  36:'1st Molar',37:'2nd Molar',38:'3rd Molar',
  41:'Central Incisor',42:'Lateral Incisor',43:'Canine',44:'1st Premolar',45:'2nd Premolar',
  46:'1st Molar',47:'2nd Molar',48:'3rd Molar',
  55:'2nd Molar',54:'1st Molar',53:'Canine',52:'Lateral Incisor',51:'Central Incisor',
  61:'Central Incisor',62:'Lateral Incisor',63:'Canine',64:'1st Molar',65:'2nd Molar',
  71:'Central Incisor',72:'Lateral Incisor',73:'Canine',74:'1st Molar',75:'2nd Molar',
  81:'Central Incisor',82:'Lateral Incisor',83:'Canine',84:'1st Molar',85:'2nd Molar',
};

const PRIMARY_TO_ADULT_IMAGE_JS = {
  55:17,54:16,53:13,52:12,51:11,61:21,62:22,63:23,64:26,65:27,
  71:31,72:32,73:33,74:36,75:37,81:41,82:42,83:43,84:46,85:47,
};

const UPPER_ADULT = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
const LOWER_ADULT = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
const UPPER_KIDS  = [55,54,53,52,51,61,62,63,64,65];
const LOWER_KIDS  = [85,84,83,82,81,71,72,73,74,75];

function archiveToothImage(num) {
  num = parseInt(num, 10);
  const imgNum = PRIMARY_TO_ADULT_IMAGE_JS[num] || num;
  const prefix = (imgNum >= 11 && imgNum <= 28) ? 'sup' : 'inf';
  // If your archive.php lives at a different folder depth, adjust this path.
  return `../../assets/img/dentadura-${prefix}-${imgNum}.png`;
}

function escapeHtml(str) {
  return String(str == null ? '' : str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function buildToothCell(num, chartMap) {
  const entry = chartMap[num] || chartMap[String(num)] || null;
  const cond  = entry ? (entry.condition_key || 'healthy') : 'healthy';
  const meta  = DENTAL_CONDITIONS[cond] || DENTAL_CONDITIONS.healthy;
  const name  = TOOTH_NAMES_JS[num] || 'Tooth';

  // For "Other", prefer the custom note text on the badge
  const badgeText = (cond === 'other' && entry && entry.notes && String(entry.notes).trim())
    ? String(entry.notes).trim()
    : meta.label;

  const badge = cond !== 'healthy'
    ? `<span class="tooth-condition-badge" style="background:${meta.color}20;color:${meta.color};" title="${escapeHtml(badgeText)}">${escapeHtml(badgeText)}</span>`
    : '';

  const title = entry && entry.notes
    ? ` title="Notes: ${escapeHtml(entry.notes)}"`
    : '';

  return `
    <div class="tooth-cell" data-condition="${cond}"${title}>
      <div class="tooth-img-wrap" style="--tooth-color:${meta.color};">
        <img src="${archiveToothImage(num)}" alt="Tooth ${num}"
             onerror="this.style.opacity=0.15;">
      </div>
      <span class="tooth-num">${num}</span>
      <span class="tooth-name">${name}</span>
      ${badge}
    </div>`;
}

function renderArchiveDentalChart(chartData) {
  const chartMap = {};
  if (Array.isArray(chartData)) {
    chartData.forEach(r => {
      if (r && r.tooth_number !== undefined && r.tooth_number !== null) {
        chartMap[String(r.tooth_number)] = r;
      }
    });
  }

  const recordedCount = Object.keys(chartMap).length;

  let latest = null;
  Object.values(chartMap).forEach(r => {
    if (!latest || (r.updated_at && r.updated_at > latest.updated_at)) latest = r;
  });

  const hasKidsMarks  = Object.keys(chartMap).some(k => UPPER_KIDS.includes(parseInt(k,10)) || LOWER_KIDS.includes(parseInt(k,10)));
  const hasAdultMarks = Object.keys(chartMap).some(k => UPPER_ADULT.includes(parseInt(k,10)) || LOWER_ADULT.includes(parseInt(k,10)));
  const showKids  = hasKidsMarks && !hasAdultMarks;
  const showAdult = !showKids;

  const legendHtml = Object.entries(DENTAL_CONDITIONS).map(([k, c]) =>
    `<div class="legend-item"><span class="legend-dot" style="background:${c.color};"></span>${c.label}</div>`
  ).join('');

  const summaryHtml = `
    <div class="chart-summary">
      <div class="stat"><span class="label">Recorded Marks</span><span class="val">${recordedCount} mark(s)</span></div>
      <div class="stat"><span class="label">Latest Update</span><span class="val">${latest && latest.updated_at ? latest.updated_at : '—'}</span></div>
      <div class="stat"><span class="label">Updated By</span><span class="val">${latest && latest.updated_by ? latest.updated_by : '—'}</span></div>
    </div>`;

  const adultHtml = showAdult ? `
    <div class="arch-label">Upper Arch (Maxillary)</div>
    <div class="arch-row">${UPPER_ADULT.map(n => buildToothCell(n, chartMap)).join('')}</div>
    <div class="arch-divider"></div>
    <div class="arch-label">Lower Arch (Mandibular)</div>
    <div class="arch-row">${LOWER_ADULT.map(n => buildToothCell(n, chartMap)).join('')}</div>
  ` : '';

  const kidsHtml = showKids ? `
    <div class="arch-label">Upper Arch — Primary Teeth</div>
    <div class="arch-row">${UPPER_KIDS.map(n => buildToothCell(n, chartMap)).join('')}</div>
    <div class="arch-divider"></div>
    <div class="arch-label">Lower Arch — Primary Teeth</div>
    <div class="arch-row">${LOWER_KIDS.map(n => buildToothCell(n, chartMap)).join('')}</div>
  ` : '';

  return `
    <div class="snapshot-section">
      <h4><i class="fas fa-tooth"></i> Dental Chart / Intraoral Examination</h4>
      ${recordedCount === 0
        ? `<p class="text-sm text-gray-400" style="padding:8px 0;">No dental chart marks recorded in this snapshot.</p>`
        : `${summaryHtml}<div class="chart-legend">${legendHtml}</div>${adultHtml}${kidsHtml}`}
    </div>`;
}

function renderSnapshot(data) {
    let html = '';
    
    // Appointment Details
    if (data.appointment) {
        const appt = data.appointment;
        html += `
            <div class="snapshot-section">
                <h4><i class="fas fa-calendar-check"></i> Appointment Details</h4>
                <div class="grid-2">
                    <div class="field-row"><span class="field-label">Patient:</span><span class="field-value font-medium">${appt.full_name || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Phone:</span><span class="field-value">${appt.phone || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Email:</span><span class="field-value">${appt.email || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Age:</span><span class="field-value">${appt.age || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Occupation:</span><span class="field-value">${appt.occupation || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Status:</span><span class="field-value"><span class="badge ${getStatusBadge(appt.status)}">${appt.status || '—'}</span></span></div>
                    <div class="field-row"><span class="field-label">Date:</span><span class="field-value">${appt.appointment_date || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Time:</span><span class="field-value">${appt.appointment_time || '—'}</span></div>
                    <div class="field-row" style="grid-column: 1 / -1;"><span class="field-label">Address:</span><span class="field-value">${[appt.street_address, appt.barangay, appt.city, appt.province, appt.region].filter(Boolean).join(', ') || '—'}</span></div>
                    ${appt.notes ? `<div class="field-row" style="grid-column: 1 / -1;"><span class="field-label">Notes:</span><span class="field-value">${appt.notes}</span></div>` : ''}
                </div>
            </div>
        `;
    }
    
    // Dentist Details
    if (data.dentist) {
        const dentist = data.dentist;
        html += `
            <div class="snapshot-section">
                <h4><i class="fas fa-user-md"></i> Dentist</h4>
                <div class="grid-2">
                    <div class="field-row"><span class="field-label">Name:</span><span class="field-value font-medium">Dr. ${dentist.first_name || ''} ${dentist.last_name || ''}</span></div>
                    <div class="field-row"><span class="field-label">Specialization:</span><span class="field-value">${dentist.specialization || '—'}</span></div>
                    <div class="field-row"><span class="field-label">License:</span><span class="field-value">${dentist.license_number || '—'}</span></div>
                    <div class="field-row"><span class="field-label">Experience:</span><span class="field-value">${dentist.years_experience || '—'} years</span></div>
                </div>
            </div>
        `;
    }
    
    // Services
    if (data.services && data.services.length > 0) {
        html += `
            <div class="snapshot-section">
                <h4><i class="fas fa-tooth"></i> Services</h4>
                <div>
                    ${data.services.map(s => `
                        <span class="service-tag">${s.title || s}</span>
                    `).join(' ')}
                    ${data.total_price ? `<span class="field-row" style="margin-top:8px;"><span class="field-label">Total:</span><span class="field-value font-bold text-teal">${formatCurrency(data.total_price)}</span></span>` : ''}
                </div>
            </div>
        `;
    }
    
    // Medical History
    if (data.medical_history) {
        const mh = data.medical_history;
        const conditions = [];
        if (mh.under_physician_care === 'yes') conditions.push('Under physician care');
        if (mh.pregnant === 'yes') conditions.push('Pregnant');
        if (mh.taking_medicine === 'yes') conditions.push('Taking medicine');
        if (mh.hospitalized === 'yes') conditions.push('Hospitalized');
        if (mh.worn_denture === 'yes') conditions.push('Wears denture');
        
        html += `
            <div class="snapshot-section">
                <h4><i class="fas fa-heartbeat"></i> Medical History</h4>
                <div class="grid-2">
                    ${conditions.length > 0 ? `<div class="field-row" style="grid-column: 1 / -1;"><span class="field-label">Conditions:</span><span class="field-value">${conditions.join(', ')}</span></div>` : ''}
                    ${mh.medicine_details ? `<div class="field-row" style="grid-column: 1 / -1;"><span class="field-label">Medicine Details:</span><span class="field-value">${mh.medicine_details}</span></div>` : ''}
                    ${mh.conditions ? `<div class="field-row" style="grid-column: 1 / -1;"><span class="field-label">Other Conditions:</span><span class="field-value">${mh.conditions}</span></div>` : ''}
                    <div class="field-row"><span class="field-label">Allergies:</span><span class="field-value">${[
                        mh.allergic_local_anesthesia === 'yes' ? 'Local anesthesia' : '',
                        mh.allergic_antibiotics === 'yes' ? 'Antibiotics' : '',
                        mh.allergic_pain_killer === 'yes' ? 'Pain killers' : '',
                        mh.allergic_other === 'yes' ? mh.allergic_other_details || 'Other' : ''
                    ].filter(Boolean).join(', ') || 'None'}</span></div>
                    ${mh.last_dental_visit ? `<div class="field-row"><span class="field-label">Last Dental Visit:</span><span class="field-value">${mh.last_dental_visit}${mh.last_dental_visit_reason ? ` (${mh.last_dental_visit_reason})` : ''}</span></div>` : ''}
                </div>
            </div>
        `;
    }
    
    // Dental Chart (visual, read-only)
    if (data.dental_chart && data.dental_chart.length > 0) {
        html += renderArchiveDentalChart(data.dental_chart);
    }
    
    // Other Appointments
    if (data.other_appointments && data.other_appointments.length > 0) {
        html += `
            <div class="snapshot-section">
                <h4><i class="fas fa-clock"></i> Other Appointments</h4>
                <div>
                    ${data.other_appointments.map(oa => `
                        <div class="field-row">
                            <span class="field-label">${oa.appointment_date || '—'}</span>
                            <span class="field-value">${oa.appointment_time || '—'} · <span class="badge ${getStatusBadge(oa.status)}">${oa.status || '—'}</span></span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    // Generated At
    if (data.generated_at) {
        html += `
            <div class="snapshot-section" style="margin-bottom:0;">
                <div class="field-row">
                    <span class="field-label">Generated At:</span>
                    <span class="field-value text-gray-500 text-sm">${formatDateDisplay(data.generated_at)}</span>
                </div>
            </div>
        `;
    }
    
    return html;
}

async function viewRecord(id) {
    const loadingState = document.getElementById('loadingState');
    const recordContent = document.getElementById('recordContent');
    
    // Show loading
    loadingState.style.display = 'block';
    recordContent.style.display = 'none';
    document.getElementById('viewModal').classList.remove('hidden');
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_record');
        formData.append('id', id);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        // Hide loading
        loadingState.style.display = 'none';
        
        if (data.success) {
            const record = data.record;
            
            // Parse the snapshot JSON
            let snapshotData = {};
            try {
                if (typeof record.snapshot_json === 'string') {
                    snapshotData = JSON.parse(record.snapshot_json);
                } else {
                    snapshotData = record.snapshot_json || {};
                }
            } catch (e) {
                console.error('Error parsing snapshot:', e);
                snapshotData = { raw: record.snapshot_json || 'No data available' };
            }
            
            // Render the snapshot
            recordContent.innerHTML = renderSnapshot(snapshotData);
            recordContent.style.display = 'block';

            // Resolve the appointment id for the print button
            currentModalAppointmentId = data.appointment_id
                || (snapshotData.appointment && snapshotData.appointment.id)
                || snapshotData.appointment_id
                || null;

            const printBtn = document.getElementById('modalPrintBtn');
            if (printBtn) {
                printBtn.disabled = !currentModalAppointmentId;
                printBtn.style.opacity = currentModalAppointmentId ? '1' : '0.5';
                printBtn.style.cursor = currentModalAppointmentId ? 'pointer' : 'not-allowed';
                printBtn.title = currentModalAppointmentId
                    ? 'Print Dental Certificate'
                    : 'No linked appointment for this record';
            }
            
        } else {
            recordContent.innerHTML = `
                <div class="text-center py-8 text-red-400">
                    <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                    <p>${data.message || 'Failed to load record'}</p>
                </div>
            `;
            recordContent.style.display = 'block';

            currentModalAppointmentId = null;
            const printBtn = document.getElementById('modalPrintBtn');
            if (printBtn) {
                printBtn.disabled = true;
                printBtn.style.opacity = '0.5';
                printBtn.style.cursor = 'not-allowed';
            }
        }
    } catch (error) {
        loadingState.style.display = 'none';
        recordContent.innerHTML = `
            <div class="text-center py-8 text-red-400">
                <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                <p>Failed to load record details</p>
            </div>
        `;
        recordContent.style.display = 'block';

        currentModalAppointmentId = null;
        const printBtn = document.getElementById('modalPrintBtn');
        if (printBtn) {
            printBtn.disabled = true;
            printBtn.style.opacity = '0.5';
            printBtn.style.cursor = 'not-allowed';
        }
    }
}

// ========== PRINT FUNCTIONS ==========
function printFromArchive(recordId, appointmentId) {
    // Fast path: if the appointment id is already known, open the certificate directly
    if (appointmentId && appointmentId > 0) {
        window.open('print_dental_certificate.php?id=' + appointmentId, '_blank');
        return;
    }

    // Slow path: fetch the record and try to resolve the id from the snapshot
    Swal.fire({
        title: 'Preparing certificate...',
        text: 'Looking up the linked appointment.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'get_record');
    formData.append('id', recordId);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            Swal.close();

            if (!data.success || !data.record) {
                Swal.fire('Error', 'Could not load the archive record.', 'error');
                return;
            }

            let apptId = data.appointment_id || 0;

            if (!apptId) {
                let snap = {};
                try {
                    snap = typeof data.record.snapshot_json === 'string'
                        ? JSON.parse(data.record.snapshot_json)
                        : (data.record.snapshot_json || {});
                } catch (e) { snap = {}; }

                apptId = (snap.appointment && snap.appointment.id)
                    || snap.appointment_id
                    || 0;
            }

            if (!apptId) {
                Swal.fire({
                    icon: 'info',
                    title: 'No linked appointment',
                    text: 'This archive record is not linked to a live appointment, so a dental certificate cannot be generated from it.'
                });
                return;
            }

            window.open('print_dental_certificate.php?id=' + apptId, '_blank');
        })
        .catch(() => {
            Swal.close();
            Swal.fire('Error', 'Could not load the archive record.', 'error');
        });
}

function printCurrentRecord() {
    if (!currentModalAppointmentId) {
        Swal.fire({
            icon: 'info',
            title: 'No linked appointment',
            text: 'This archive record has no linked live appointment to print a certificate for.'
        });
        return;
    }
    window.open('print_dental_certificate.php?id=' + currentModalAppointmentId, '_blank');
}

// ========== CLOSE MODAL ON CLICK OUTSIDE ==========
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});

// ========== CLOSE MODAL ON ESC ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeViewModal();
});
</script>

</body>
</html>