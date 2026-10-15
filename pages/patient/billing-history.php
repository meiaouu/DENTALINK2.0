<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

 

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function formatCurrency($amount) { return '₱' . number_format((float)$amount, 2); }
function formatDate($timestamp) {
    if (empty($timestamp)) return '—';
    return date('M j, Y g:i A', strtotime($timestamp));
}

function getStatusBadge($status) {
    $badges = [
        'paid' => 'badge-success',
        'unpaid' => 'badge-danger',
        'partial' => 'badge-warning',
        'refunded' => 'badge-info'
    ];
    $labels = [
        'paid' => 'Paid',
        'unpaid' => 'Unpaid',
        'partial' => 'Partial',
        'refunded' => 'Refunded'
    ];
    return [
        'class' => $badges[$status] ?? 'badge-info',
        'label' => $labels[$status] ?? ucfirst($status)
    ];
}

// Get patient ID from session
$patientId = $_SESSION['user_id'] ?? 0;
$patientEmail = $_SESSION['email'] ?? '';
$patientPhone = $_SESSION['phone'] ?? '';

// Get patient details from users table
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'patient'");
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    header('Location: ../../index.php');
    exit;
}

// Get billing history for this patient
// Match by patient_name, patient_phone, or patient_email from billing table
// Also match by phone/email from appointments table
$billingSql = "
    SELECT b.*, 
           a.appointment_date, 
           a.appointment_time,
           a.status as appointment_status
    FROM billing b
    LEFT JOIN appointments a ON b.appointment_id = a.id
    WHERE b.patient_email = ? 
       OR b.patient_phone = ?
       OR b.patient_name = ?
       OR a.email = ?
       OR a.phone = ?
    ORDER BY b.created_at DESC
";

$stmt = $conn->prepare($billingSql);
$stmt->bind_param("sssss", 
    $patientEmail, 
    $patientPhone, 
    $patient['full_name'],
    $patientEmail,
    $patientPhone
);
$stmt->execute();
$billingRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalBills = count($billingRecords);
$totalPaid = 0;
$totalUnpaid = 0;
$totalAmount = 0;

foreach ($billingRecords as $record) {
    $totalAmount += (float)$record['total_amount'];
    if ($record['status'] === 'paid') {
        $totalPaid += (float)$record['total_amount'];
    } elseif ($record['status'] === 'unpaid' || $record['status'] === 'partial') {
        $totalUnpaid += (float)$record['total_amount'];
    }
}

// Pagination
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, ceil($totalBills / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$paginatedRecords = array_slice($billingRecords, $offset, $perPage);

include '../patient/psidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Billing History · Wansi Calacat Dental Clinic</title>
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

        .container-modern { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        @media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

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

        .billing-row {
            display: grid;
            grid-template-columns: 1.2fr 1.2fr 1fr 1fr 0.8fr 0.6fr;
            padding: 12px 0;
            border-bottom: 1px solid #f1f4f8;
            font-size: 13px;
            align-items: center;
            transition: background 0.2s;
        }
        .billing-row:hover { background: #fafbfc; }
        .billing-row.head {
            font-weight: 600;
            color: var(--slate);
            font-size: 11px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border-bottom: 2px solid #e9edf2;
            padding-bottom: 10px;
        }
        .billing-row.head:hover { background: transparent; }

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
        .pagination-link.disabled { opacity: 0.4; pointer-events: none; }

        /* View Modal */
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
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 28px;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (min-width: 1024px) {
            .main-content { margin-left: 260px; }
        }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }

        @media (max-width: 640px) {
            .billing-row {
                grid-template-columns: 1fr 1fr;
                gap: 4px 8px;
                padding: 12px 0;
                font-size: 12px;
            }
            .billing-row.head { display: none; }
            .stat-card { padding: 1rem; }
            .stat-card .text-3xl { font-size: 1.5rem; }
            .dashboard-card { padding: 1rem; }
        }

        .service-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .service-item:last-child { border-bottom: none; }
        
        .btn-primary {
            background: var(--teal);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary:hover { background: #0a7d6e; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(13,158,140,0.3); }
        .btn-secondary {
            background: #f1f4f8;
            color: var(--navy);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-secondary:hover { background: #e2e8f0; }
        
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

        /* ===== Receipt / Print Preview ===== */
        #printModal .modal-box {
            max-width: 480px;
            padding: 0;
            background: #e9edf2;
        }
        .receipt-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 20px 20px 0 0;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .receipt-paper {
            background: white;
            margin: 20px;
            padding: 28px 24px;
            border-radius: 4px;
            box-shadow: 0 1px 4px rgba(11,22,40,.1);
            font-size: 13px;
            color: #1e293b;
        }
        .receipt-paper .r-clinic {
            text-align: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 2px dashed #cbd5e1;
        }
        .receipt-paper .r-clinic h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            margin: 0 0 4px;
        }
        .receipt-paper .r-clinic p {
            font-size: 11px;
            color: #64748b;
            margin: 1px 0;
        }
        .receipt-paper .r-title {
            text-align: center;
            font-weight: 700;
            letter-spacing: 1.5px;
            font-size: 12px;
            color: var(--teal-dark);
            margin-bottom: 14px;
        }
        .r-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
        }
        .r-row .label { color: #64748b; }
        .r-row .value { font-weight: 600; color: #1e293b; text-align: right; }
        .r-divider {
            border-top: 1px dashed #cbd5e1;
            margin: 12px 0;
        }
        .r-services .service-item { font-size: 12px; }
        .r-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 10px;
            margin-top: 6px;
            border-top: 2px solid #1e293b;
        }
        .r-total-row .label { font-weight: 700; font-size: 13px; }
        .r-total-row .value { font-weight: 800; font-size: 18px; color: var(--teal-dark); }
        .r-footer {
            text-align: center;
            margin-top: 18px;
            padding-top: 12px;
            border-top: 2px dashed #cbd5e1;
            font-size: 11px;
            color: #94a3b8;
        }

        @media print {
            body * { visibility: hidden; }
            #printModal, #printModal * { visibility: visible; }
            #printModal {
                position: fixed;
                inset: 0;
                background: white !important;
                backdrop-filter: none;
                padding: 0;
                display: flex !important;
            }
            #printModal .modal-box {
                box-shadow: none;
                max-width: 100%;
                background: white;
                max-height: none;
                overflow: visible;
            }
            .receipt-toolbar { display: none !important; }
            .receipt-paper { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="main-content">
    <main class="py-6">
        <div class="container-modern">

            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-dark-blue">My Billing History</h1>
                    <p class="text-sm text-gray-500">View all your invoices and payment records</p>
                </div>
                <div class="text-sm text-gray-500">
                    <i class="fas fa-user mr-1"></i> <?= h($patient['full_name']) ?>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Invoices</p>
                    <p class="text-3xl font-bold text-dark-blue mt-1"><?= $totalBills ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Amount</p>
                    <p class="text-3xl font-bold text-teal mt-1"><?= formatCurrency($totalAmount) ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Paid</p>
                    <p class="text-3xl font-bold text-green-600 mt-1"><?= formatCurrency($totalPaid) ?></p>
                </div>
                <div class="stat-card">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Outstanding Balance</p>
                    <p class="text-3xl font-bold text-red-600 mt-1"><?= formatCurrency($totalUnpaid) ?></p>
                </div>
            </div>

            <!-- Billing Table -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span><i class="fas fa-receipt mr-2 text-teal"></i> Invoices</span>
                    <span class="text-xs text-gray-400"><?= $totalBills ?> record(s)</span>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <div class="billing-row head">
                        <span>Invoice #</span>
                        <span>Date</span>
                        <span>Services</span>
                        <span>Total</span>
                        <span>Status</span>
                        <span class="text-right">Actions</span>
                    </div>

                    <?php if (empty($paginatedRecords)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="fas fa-receipt text-4xl mb-3 block"></i>
                            <p class="text-lg">No billing records found</p>
                            <p class="text-sm">You don't have any invoices yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($paginatedRecords as $record): 
                            $badge = getStatusBadge($record['status']);
                            $services = json_decode($record['services'] ?? '[]', true);
                            $serviceNames = array_column($services, 'title');
                            $serviceList = implode(', ', array_slice($serviceNames, 0, 2));
                            if (count($serviceNames) > 2) {
                                $serviceList .= ' +' . (count($serviceNames) - 2) . ' more';
                            }
                        ?>
                        <div class="billing-row">
                            <span class="font-mono text-sm font-medium text-teal"><?= h($record['billing_code']) ?></span>
                            <span class="text-gray-600 text-sm"><?= formatDate($record['created_at']) ?></span>
                            <span class="text-sm text-gray-600 truncate" title="<?= h(implode(', ', $serviceNames)) ?>">
                                <?= h($serviceList ?: '—') ?>
                            </span>
                            <span class="text-sm font-bold text-dark-blue"><?= formatCurrency($record['total_amount']) ?></span>
                            <span><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></span>
                            <span class="text-right flex items-center justify-end gap-1">
                                <button class="action-btn view" onclick="viewBilling(<?= $record['id'] ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($record['status'] === 'paid'): ?>
                                <button class="action-btn view" onclick="printReceipt(<?= $record['id'] ?>)" title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </button>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex flex-wrap items-center justify-between mt-6 pt-4 border-t border-gray-100 gap-4">
                    <span class="text-xs text-gray-400">Page <?= $page ?> of <?= $totalPages ?></span>
                    <div class="flex gap-1">
                        <a class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>" href="?page=<?= $page - 1 ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                            <a class="pagination-link <?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?page=<?= $page + 1 ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="mt-8 text-center text-[11px] text-gray-400 border-t border-gray-200 pt-5">
                <i class="fas fa-tooth mr-1 text-teal/50"></i> Wansi Calacat Dental Clinic · Patient Portal
            </div>
        </div>
    </main>
</div>

<!-- View Billing Modal -->
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

<!-- Print Preview Modal -->
<div id="printModal" class="modal-overlay">
    <div class="modal-box">
        <div class="receipt-toolbar">
            <h3 class="text-base font-bold text-dark-blue">
                <i class="fas fa-print text-teal mr-2"></i> Receipt Preview
            </h3>
            <div class="flex items-center gap-2">
                <button class="btn-primary" id="printNowBtn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn-secondary" onclick="closeModal('printModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
        <div id="printContent">
            <div class="text-center py-12">
                <i class="fas fa-spinner fa-spin text-2xl text-teal"></i>
                <p class="text-gray-400 mt-2">Preparing receipt...</p>
            </div>
        </div>
    </div>
</div>

<script>
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
    formData.append('user_id', '<?= $_SESSION['user_id'] ?? 0 ?>');

    fetch('../../api/billing-api.php', { 
        method: 'POST', 
        body: formData,
        credentials: 'include'
    })
    .then(response => {
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        console.log('Content-Type:', contentType);
        
        if (!contentType || !contentType.includes('application/json')) {
            // If not JSON, get the text to show the error
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error('API returned HTML instead of JSON. Check PHP errors.\n' + text.substring(0, 500));
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('API Response:', data);
        
        if (data.success) {
            displayBillingDetails(data, document.getElementById('viewContent'));
        } else {
            document.getElementById('viewContent').innerHTML = `
                <div class="text-center py-8 text-red-400">
                    <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                    <p>${data.message || 'Failed to load billing details'}</p>
                    <p class="text-sm text-gray-400 mt-2">Please try again or contact support.</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('viewContent').innerHTML = `
            <div class="text-center py-8 text-red-400">
                <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                <p>Failed to load billing details</p>
                <div class="text-left mt-4 p-4 bg-gray-100 rounded-lg">
                    <p class="text-sm font-semibold">Error Details:</p>
                    <pre class="text-xs mt-2 whitespace-pre-wrap">${error.message}</pre>
                </div>
                <p class="text-sm text-gray-400 mt-2">Check console for more details.</p>
            </div>
        `;
    });
}

// Add the display function
function displayBillingDetails(data, container) {
    const b = data.billing;
    const services = data.services || [];
    
    let servicesHtml = services.map(s => `
        <div class="service-item">
            <span>${h(s.title || 'Service')}</span>
            <span>${formatCurrency(s.price || 0)}</span>
        </div>
    `).join('');
    
    const badge = getStatusBadge(b.status);
    
    container.innerHTML = `
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-gray-400">Invoice #</p>
                    <p class="font-medium">${h(b.billing_code)}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Date</p>
                    <p class="font-medium">${formatDate(b.created_at)}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Patient</p>
                    <p class="font-medium">${h(b.patient_name)}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Status</p>
                    <span class="badge ${badge.class}">${badge.label}</span>
                </div>
            </div>
            
            <div>
                <p class="text-xs text-gray-400 mb-2">Services</p>
                <div class="bg-gray-50 rounded-lg p-3">
                    ${servicesHtml || '<p class="text-gray-400 text-sm">No services listed</p>'}
                </div>
            </div>
            
            <div class="flex justify-between items-center pt-3 border-t border-gray-200">
                <p class="text-xs text-gray-400">Total Amount</p>
                <p class="text-xl font-bold text-teal">${formatCurrency(b.total_amount)}</p>
            </div>
            
            ${b.payment_date ? `
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-400">Payment Date</span>
                <span class="font-medium">${formatDate(b.payment_date)}</span>
            </div>
            ` : ''}
            
            ${b.notes ? `
            <div>
                <p class="text-xs text-gray-400">Notes</p>
                <p class="text-sm">${h(b.notes)}</p>
            </div>
            ` : ''}
        </div>
    `;
}

// ========== PRINT RECEIPT (with preview) ==========
function printReceipt(id) {
    openModal('printModal');
    document.getElementById('printNowBtn').disabled = true;
    document.getElementById('printContent').innerHTML = `
        <div class="text-center py-12">
            <i class="fas fa-spinner fa-spin text-2xl text-teal"></i>
            <p class="text-gray-400 mt-2">Preparing receipt...</p>
        </div>
    `;

    const formData = new FormData();
    formData.append('action', 'get_billing');
    formData.append('id', id);
    formData.append('user_id', '<?= $_SESSION['user_id'] ?? 0 ?>');

    fetch('../../api/billing-api.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('API returned HTML instead of JSON. Check PHP errors.\n' + text.substring(0, 500));
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            renderReceiptPreview(data);
            document.getElementById('printNowBtn').disabled = false;
        } else {
            document.getElementById('printContent').innerHTML = `
                <div class="text-center py-12 text-red-400">
                    <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                    <p>${data.message || 'Failed to load receipt'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('printContent').innerHTML = `
            <div class="text-center py-12 text-red-400">
                <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                <p>Failed to load receipt</p>
                <pre class="text-xs mt-3 text-left bg-gray-100 p-3 rounded whitespace-pre-wrap">${h(error.message)}</pre>
            </div>
        `;
    });
}

function renderReceiptPreview(data) {
    const b = data.billing;
    const services = data.services || [];

    const servicesHtml = services.map(s => `
        <div class="service-item">
            <span>${h(s.title || 'Service')}</span>
            <span>${formatCurrency(s.price || 0)}</span>
        </div>
    `).join('') || '<p class="text-gray-400 text-xs">No services listed</p>';

    document.getElementById('printContent').innerHTML = `
        <div class="receipt-paper">
            <div class="r-clinic">
                <h2>Wansi Calacat Dental Clinic</h2>
                <p>Receipt</p>
            </div>
            <div class="r-title">RECEIPT</div>

            <div class="r-row"><span class="label">Invoice #</span><span class="value">${h(b.billing_code)}</span></div>
            <div class="r-row"><span class="label">Date</span><span class="value">${formatDate(b.created_at)}</span></div>
            <div class="r-row"><span class="label">Patient</span><span class="value">${h(b.patient_name)}</span></div>
            <div class="r-row"><span class="label">Status</span><span class="value">${h(getStatusBadge(b.status).label)}</span></div>
            ${b.payment_date ? `<div class="r-row"><span class="label">Payment Date</span><span class="value">${formatDate(b.payment_date)}</span></div>` : ''}

            <div class="r-divider"></div>

            <div class="r-services">
                ${servicesHtml}
            </div>

            <div class="r-total-row">
                <span class="label">Total Paid</span>
                <span class="value">${formatCurrency(b.total_amount)}</span>
            </div>

            ${b.notes ? `<div class="mt-3"><p class="text-xs text-gray-400">Notes</p><p class="text-xs">${h(b.notes)}</p></div>` : ''}

            <div class="r-footer">
                Thank you for visiting Wansi Calacat Dental Clinic!<br>
                This receipt was generated electronically.
            </div>
        </div>
    `;
}

// ========== MODAL CONTROLS ==========
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on click outside
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// Close modal on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        });
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
    return '₱' + parseFloat(amount || 0).toFixed(2);
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
        'paid': { class: 'badge-success', label: 'Paid' },
        'unpaid': { class: 'badge-danger', label: 'Unpaid' },
        'partial': { class: 'badge-warning', label: 'Partial' },
        'refunded': { class: 'badge-info', label: 'Refunded' }
    };
    return badges[status] || { class: 'badge-info', label: ucfirst(status) };
}
</script>
</body>
</html>