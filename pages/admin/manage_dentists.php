<?php
require '../../conn/conn.php';

// Check if user is logged in (adjust as needed)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Handle CRUD operations
$message = '';
$messageType = '';

// Add Dentist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $license_number = trim($_POST['license_number'] ?? '');
        $years_experience = intval($_POST['years_experience'] ?? 0);
        $bio = trim($_POST['bio'] ?? '');

        // Validate
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            // Check if email already exists
            $check = $conn->prepare("SELECT id FROM dentists WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = 'A dentist with this email already exists.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO dentists (first_name, last_name, specialization, email, phone, license_number, years_experience, bio) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssis", $first_name, $last_name, $specialization, $email, $phone, $license_number, $years_experience, $bio);

                if ($stmt->execute()) {
                    $message = 'Dentist added successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error adding dentist: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    // Update Dentist
    if ($_POST['action'] === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $license_number = trim($_POST['license_number'] ?? '');
        $years_experience = intval($_POST['years_experience'] ?? 0);
        $bio = trim($_POST['bio'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($first_name) || empty($last_name) || empty($email)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            // Check if email exists for other dentists
            $check = $conn->prepare("SELECT id FROM dentists WHERE email = ? AND id != ?");
            $check->bind_param("si", $email, $id);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = 'A dentist with this email already exists.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE dentists SET first_name = ?, last_name = ?, specialization = ?, email = ?, phone = ?, license_number = ?, years_experience = ?, bio = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("ssssssisii", $first_name, $last_name, $specialization, $email, $phone, $license_number, $years_experience, $bio, $is_active, $id);

                if ($stmt->execute()) {
                    $message = 'Dentist updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error updating dentist: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    // Delete Dentist
    if ($_POST['action'] === 'delete') {
        $id = intval($_POST['id'] ?? 0);

        // Check if dentist has appointments
        $check = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE dentist_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        $row = $result->fetch_assoc();
        $check->close();

        if ($row['count'] > 0) {
            $message = 'Cannot delete this dentist. They have existing appointments.';
            $messageType = 'danger';
        } else {
            $stmt = $conn->prepare("DELETE FROM dentists WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $message = 'Dentist deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error deleting dentist: ' . $conn->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Get all dentists
$dentists = [];
$result = $conn->query("SELECT * FROM dentists ORDER BY is_active DESC, last_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dentists[] = $row;
    }
}

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Dentists · Dental Clinic</title>
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
            --slate: #64748b;
            --line: #eef1f6;
            --amber: #d97706;
        }

        body {
            background-color: #f8fafc;
            background-image:
                linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
        }

        @media (min-width: 1024px) { .main-content { margin-left: 260px; } }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }

        .top-bar {
           
            padding: 16px 32px;
             display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
           
            top: 0;
            z-index: 30;
        }

        .page-body { padding: 28px 32px 48px; max-width: 1320px; margin: 0 auto; }

        /* Stat cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 18px 22px;
            border: 1px solid var(--line);
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(11,22,40,0.06); }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }

        .btn-teal {
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(13,158,140,0.25);
        }
        .btn-teal:hover { filter: brightness(1.05); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(13,158,140,0.35); }
        .btn-teal:active { transform: translateY(0); }

        .btn-outline {
            background: white;
            color: var(--slate);
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--line);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

        /* Search / filter bar */
        .toolbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
        }
        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
        }
        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate);
            font-size: 13px;
        }
        .search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            font-size: 14px;
            background: white;
            transition: all 0.2s;
        }
        .search-input:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
            outline: none;
        }
        .filter-select {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            font-size: 14px;
            background: white;
            color: var(--navy);
            font-weight: 500;
            cursor: pointer;
        }
        .filter-select:focus { border-color: var(--teal); outline: none; }

        .dentist-card {
            background: white;
            border-radius: 18px;
            border: 1px solid var(--line);
            padding: 20px;
            transition: all 0.25s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        .dentist-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--teal), var(--teal-dark));
            opacity: 0;
            transition: opacity 0.2s;
        }
        .dentist-card:hover {
            box-shadow: 0 12px 28px rgba(11,22,40,0.09);
            transform: translateY(-3px);
            border-color: rgba(13,158,140,0.25);
        }
        .dentist-card:hover::before { opacity: 1; }
        .dentist-card.is-inactive { opacity: 0.75; }

        .status-badge {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .status-badge::before {
            content: '';
            width: 6px; height: 6px; border-radius: 50%;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-active::before { background: #22c55e; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-inactive::before { background: #ef4444; }

        .avatar-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal-light), #d3f0ec);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--teal-dark);
            font-weight: 700;
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--teal-light);
            flex-shrink: 0;
        }

        .spec-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--teal-dark);
            background: var(--teal-light);
            padding: 2px 10px;
            border-radius: 50px;
            margin-top: 2px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--slate);
        }
        .info-row i { width: 16px; color: var(--teal); font-size: 12px; }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(11,22,40,0.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; animation: fadeIn 0.2s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 620px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 64px rgba(0,0,0,0.25);
            animation: slideUp 0.25s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(16px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .modal-header {
            background: linear-gradient(135deg, var(--navy), #16233a);
            padding: 22px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-header h3 { color: white; margin: 0; }
        .modal-header p { color: rgba(255,255,255,0.6); font-size: 13px; margin-top: 2px; }
        .modal-close-btn {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            color: white;
            display: flex; align-items: center; justify-content: center;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .modal-close-btn:hover { background: rgba(255,255,255,0.2); }

        .modal-body { padding: 28px 32px; overflow-y: auto; }
        .modal-footer { padding: 18px 32px; border-top: 1px solid var(--line); flex-shrink: 0; }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 4px;
        }
        .form-label .required { color: #dc2626; }

        .form-control {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-control:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,0.1);
            outline: none;
        }
        textarea.form-control { resize: vertical; min-height: 80px; }

        .active-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--teal-light);
            border-radius: 12px;
            padding: 12px 16px;
        }
        .switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider-toggle {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .2s; border-radius: 24px;
        }
        .slider-toggle:before {
            position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%;
        }
        input:checked + .slider-toggle { background-color: var(--teal); }
        input:checked + .slider-toggle:before { transform: translateX(18px); }

        .empty-state {
            text-align: center;
            padding: 64px 24px;
            background: white;
            border-radius: 18px;
            border: 1.5px dashed #e2e8f0;
        }
        .empty-state-icon {
            width: 84px; height: 84px; border-radius: 50%;
            background: var(--teal-light);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px; color: var(--teal);
        }

        @media (max-width: 640px) {
            .top-bar { padding: 14px 18px; }
            .page-body { padding: 18px 16px 40px; }
            .modal-body, .modal-header, .modal-footer { padding-left: 20px; padding-right: 20px; }
        }
    </style>
</head>
<body>

<div class="main-content ">
    <!-- Top Bar -->
    <div class="top-bar">
        <div>
            <h2 class="text-xl font-bold text-[#0b1628]">Manage Dentists</h2>
            <p class="text-sm text-[#64748b]">Add, edit, and manage dental staff</p>
        </div>
        <div>
            <button onclick="openAddModal()" class="btn-teal">
                <i class="fas fa-plus"></i> Add Dentist
            </button>
        </div>
    </div>

    <div class="page-body">
        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--teal-light); color:var(--teal-dark);"><i class="fas fa-user-md"></i></div>
                <div>
                    <div class="text-sm text-[#64748b]">Total Dentists</div>
                    <div class="text-2xl font-bold text-[#0b1628]"><?= count($dentists) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#d4edda; color:#155724;"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="text-sm text-[#64748b]">Active</div>
                    <div class="text-2xl font-bold text-[#0d9e8c]">
                        <?= count(array_filter($dentists, fn($d) => $d['is_active'] == 1)) ?>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#f8d7da; color:#721c24;"><i class="fas fa-pause-circle"></i></div>
                <div>
                    <div class="text-sm text-[#64748b]">Inactive</div>
                    <div class="text-2xl font-bold text-[#dc3545]">
                        <?= count(array_filter($dentists, fn($d) => $d['is_active'] == 0)) ?>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef3c7; color:#92400e;"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="text-sm text-[#64748b]">Avg. Experience</div>
                    <div class="text-2xl font-bold text-[#0b1628]">
                        <?php
                        $avg = count($dentists) > 0 ? array_sum(array_column($dentists, 'years_experience')) / count($dentists) : 0;
                        echo round($avg, 1) . ' yrs';
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toolbar: search + filter -->
        <div class="toolbar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, specialization, or email...">
            </div>
            <select id="statusFilter" class="filter-select">
                <option value="all">All Statuses</option>
                <option value="active">Active Only</option>
                <option value="inactive">Inactive Only</option>
            </select>
        </div>

        <!-- Dentists Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="dentistsGrid">
            <?php if (empty($dentists)): ?>
                <div class="col-span-full empty-state">
                    <div class="empty-state-icon"><i class="fas fa-user-md"></i></div>
                    <p class="text-[#0b1628] font-semibold">No dentists added yet</p>
                    <p class="text-[#64748b] text-sm mt-1">Get started by adding your first dental staff member.</p>
                    <button onclick="openAddModal()" class="btn-teal mt-4 mx-auto">
                        <i class="fas fa-plus"></i> Add Your First Dentist
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($dentists as $dentist): ?>
                    <div class="dentist-card <?= $dentist['is_active'] ? '' : 'is-inactive' ?>"
                         data-name="<?= htmlspecialchars(strtolower($dentist['first_name'] . ' ' . $dentist['last_name'])) ?>"
                         data-spec="<?= htmlspecialchars(strtolower($dentist['specialization'] ?? '')) ?>"
                         data-email="<?= htmlspecialchars(strtolower($dentist['email'])) ?>"
                         data-status="<?= $dentist['is_active'] ? 'active' : 'inactive' ?>">
                        <div class="flex items-start gap-4">
                            <div class="avatar-placeholder">
                                <?= strtoupper(substr($dentist['first_name'], 0, 1) . substr($dentist['last_name'], 0, 1)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-bold text-[#0b1628]">Dr. <?= htmlspecialchars($dentist['first_name'] . ' ' . $dentist['last_name']) ?></h3>
                                        <span class="spec-tag"><i class="fas fa-tooth"></i> <?= htmlspecialchars($dentist['specialization'] ?: 'General Dentistry') ?></span>
                                    </div>
                                    <span class="status-badge <?= $dentist['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $dentist['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>

                                <div class="mt-3 space-y-1.5 text-sm">
                                    <?php if ($dentist['email']): ?>
                                        <div class="info-row"><i class="fas fa-envelope"></i> <span class="truncate"><?= htmlspecialchars($dentist['email']) ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($dentist['phone']): ?>
                                        <div class="info-row"><i class="fas fa-phone"></i> <?= htmlspecialchars($dentist['phone']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($dentist['license_number']): ?>
                                        <div class="info-row"><i class="fas fa-id-card"></i> License: <?= htmlspecialchars($dentist['license_number']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($dentist['years_experience'] > 0): ?>
                                        <div class="info-row"><i class="fas fa-calendar"></i> <?= $dentist['years_experience'] ?> years experience</div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($dentist['bio']): ?>
                                    <p class="mt-3 text-sm text-[#64748b] line-clamp-2"><?= htmlspecialchars($dentist['bio']) ?></p>
                                <?php endif; ?>

                                <div class="mt-4 flex gap-2">
                                    <button onclick="editDentist(<?= htmlspecialchars(json_encode($dentist)) ?>)" class="btn-outline">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="deleteDentist(<?= $dentist['id'] ?>, '<?= htmlspecialchars($dentist['first_name'] . ' ' . $dentist['last_name'], ENT_QUOTES) ?>')" class="btn-outline text-red-600 hover:bg-red-50 hover:border-red-200">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- No-results state (shown via JS when search filters everything out) -->
        <div class="empty-state hidden" id="noResults">
            <div class="empty-state-icon"><i class="fas fa-search"></i></div>
            <p class="text-[#0b1628] font-semibold">No dentists match your search</p>
            <p class="text-[#64748b] text-sm mt-1">Try a different name, specialization, or filter.</p>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="dentistModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle">Add Dentist</h3>
                <p id="modalSubtitle">Enter the details of the new dental staff member</p>
            </div>
            <button onclick="closeModal()" class="modal-close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="" id="dentistForm">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="dentistId" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="firstName" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" id="lastName" class="form-control" required>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label">Specialization</label>
                    <input type="text" name="specialization" id="specialization" class="form-control" placeholder="e.g., Orthodontics, Oral Surgery">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="phone" class="form-control" placeholder="09123456789">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="form-label">License Number</label>
                        <input type="text" name="license_number" id="licenseNumber" class="form-control" placeholder="DENT-2024-001">
                    </div>
                    <div>
                        <label class="form-label">Years of Experience</label>
                        <input type="number" name="years_experience" id="yearsExperience" class="form-control" min="0" value="0">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label">Bio</label>
                    <textarea name="bio" id="bio" class="form-control" placeholder="Brief description of the dentist's background and expertise..."></textarea>
                </div>

                <div class="mt-4 active-toggle" id="activeStatusDiv">
                    <label class="switch">
                        <input type="checkbox" name="is_active" id="isActive" value="1" checked>
                        <span class="slider-toggle"></span>
                    </label>
                    <div>
                        <div class="text-sm font-semibold text-[#0b1628]">Active status</div>
                        <div class="text-xs text-[#64748b]">Inactive dentists won't appear for new bookings</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer flex gap-3">
                <button type="submit" class="btn-teal flex-1 justify-center" id="submitBtn">
                    <i class="fas fa-save"></i> Save Dentist
                </button>
                <button type="button" onclick="closeModal()" class="btn-outline">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ---- Server message -> SweetAlert ----
const serverMessage = <?= json_encode($message) ?>;
const serverMessageType = <?= json_encode($messageType) ?>;

document.addEventListener('DOMContentLoaded', () => {
    if (serverMessage) {
        Swal.fire({
            icon: serverMessageType === 'success' ? 'success' : 'error',
            title: serverMessageType === 'success' ? 'Success!' : 'Something went wrong',
            text: serverMessage,
            confirmButtonColor: '#0d9e8c',
            timer: serverMessageType === 'success' ? 2600 : undefined,
            timerProgressBar: serverMessageType === 'success',
        });
    }
});

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Dentist';
    document.getElementById('modalSubtitle').textContent = 'Enter the details of the new dental staff member';
    document.getElementById('formAction').value = 'add';
    document.getElementById('dentistId').value = '0';
    document.getElementById('firstName').value = '';
    document.getElementById('lastName').value = '';
    document.getElementById('specialization').value = '';
    document.getElementById('email').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('licenseNumber').value = '';
    document.getElementById('yearsExperience').value = '0';
    document.getElementById('bio').value = '';
    document.getElementById('isActive').checked = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Dentist';
    document.getElementById('dentistModal').classList.add('active');
}

function editDentist(dentist) {
    document.getElementById('modalTitle').textContent = 'Edit Dentist';
    document.getElementById('modalSubtitle').textContent = 'Update the details for Dr. ' + dentist.first_name + ' ' + dentist.last_name;
    document.getElementById('formAction').value = 'update';
    document.getElementById('dentistId').value = dentist.id;
    document.getElementById('firstName').value = dentist.first_name;
    document.getElementById('lastName').value = dentist.last_name;
    document.getElementById('specialization').value = dentist.specialization || '';
    document.getElementById('email').value = dentist.email;
    document.getElementById('phone').value = dentist.phone || '';
    document.getElementById('licenseNumber').value = dentist.license_number || '';
    document.getElementById('yearsExperience').value = dentist.years_experience || 0;
    document.getElementById('bio').value = dentist.bio || '';
    document.getElementById('isActive').checked = dentist.is_active == 1;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Update Dentist';
    document.getElementById('dentistModal').classList.add('active');
}

function closeModal() {
    document.getElementById('dentistModal').classList.remove('active');
}

// Client-side validation before submit, using SweetAlert instead of native alerts
document.getElementById('dentistForm').addEventListener('submit', function (e) {
    const firstName = document.getElementById('firstName').value.trim();
    const lastName = document.getElementById('lastName').value.trim();
    const email = document.getElementById('email').value.trim();

    if (!firstName || !lastName || !email) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Missing information',
            text: 'First name, last name, and email are required.',
            confirmButtonColor: '#0d9e8c'
        });
        return;
    }

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

function deleteDentist(id, name) {
    Swal.fire({
        title: 'Delete Dentist',
        html: `Are you sure you want to delete <strong>Dr. ${name}</strong>?<br><span style="color:#94a3b8;font-size:13px;">This action cannot be undone.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Close modal on outside click
document.getElementById('dentistModal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeModal();
    }
});
// Close modal on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});

// ---- Live search + status filter ----
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const grid = document.getElementById('dentistsGrid');
const noResults = document.getElementById('noResults');

function applyFilters() {
    const query = searchInput.value.trim().toLowerCase();
    const status = statusFilter.value;
    const cards = grid.querySelectorAll('.dentist-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const matchesQuery = !query ||
            card.dataset.name.includes(query) ||
            card.dataset.spec.includes(query) ||
            card.dataset.email.includes(query);
        const matchesStatus = status === 'all' || card.dataset.status === status;
        const show = matchesQuery && matchesStatus;
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    if (cards.length > 0) {
        noResults.classList.toggle('hidden', visibleCount !== 0);
    }
}

if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
}
</script>

</body>
</html>