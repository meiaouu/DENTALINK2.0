<?php
require '../../conn/conn.php';
require '../../functions/audit_log.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin-side auth check — swap this for whatever your admin login actually sets
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$staffName = $_SESSION['admin_name'] ?? 'Admin';

// ---------------- Filters ----------------
$filterStaff  = trim($_GET['staff'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterQ      = trim($_GET['q'] ?? '');
$dateFrom     = trim($_GET['from'] ?? '');
$dateTo       = trim($_GET['to'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;

// ---------------- Sorting (whitelisted columns only) ----------------
$sortableColumns = [
    'created_at' => 'created_at',
    'staff_name' => 'staff_name',
    'action'     => 'action',
    'module'     => 'module',
];
$sortColumn = $_GET['sort'] ?? 'created_at';
if (!array_key_exists($sortColumn, $sortableColumns)) $sortColumn = 'created_at';
$sortDir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';
$sortColumnSql = $sortableColumns[$sortColumn];

$where  = [];
$types  = '';
$params = [];

if ($filterStaff !== '') {
    $where[] = 'staff_name = ?';
    $types .= 's';
    $params[] = $filterStaff;
}
if ($filterModule !== '') {
    $where[] = 'module = ?';
    $types .= 's';
    $params[] = $filterModule;
}
if ($filterAction !== '') {
    $where[] = 'action = ?';
    $types .= 's';
    $params[] = $filterAction;
}
if ($filterQ !== '') {
    $where[] = '(description LIKE ? OR staff_name LIKE ?)';
    $types .= 'ss';
    $like = '%' . $filterQ . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $types .= 's';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= ?';
    $types .= 's';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---------------- Total count for pagination ----------------
$countSql = "SELECT COUNT(*) AS c FROM staff_activity_log $whereSql";
$stmt = $conn->prepare($countSql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// ---------------- Fetch page of logs ----------------
// $sortColumnSql comes from the whitelist above, never from raw user input,
// so it's safe to interpolate directly (column names can't be bound params).
$sql = "SELECT * FROM staff_activity_log $whereSql ORDER BY {$sortColumnSql} {$sortDir}, id {$sortDir} LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$typesWithLimit = $types . 'ii';
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------------- Presentation helpers ----------------
function actionBadge($action) {
    $map = [
        'create'        => ['#16a34a', '#eafaf0', 'Create'],
        'update'        => ['#2563eb', '#eaf1fe', 'Update'],
        'status_change' => ['#7c3aed', '#f3ecfe', 'Status Change'],
        'delete'        => ['#dc2626', '#fdeeee', 'Delete'],
        'login'         => ['#0d9e8c', '#e6f7f5', 'Login'],
        'logout'        => ['#6b7280', '#f3f4f6', 'Logout'],
    ];
    [$fg, $bg, $label] = $map[$action] ?? ['#6b7280', '#f3f4f6', ucfirst(str_replace('_', ' ', $action))];
    return "<span class=\"badge-pill\" style=\"color:{$fg};background:{$bg};\">{$label}</span>";
}

function buildQuery($overrides) {
    $current = $_GET;
    unset($current['ajax']);
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($current[$k]);
        else $current[$k] = $v;
    }
    return '?' . http_build_query($current);
}

function sortLink($label, $col, $currentSort, $currentDir) {
    $nextDir = ($currentSort === $col && $currentDir === 'ASC') ? 'desc' : 'asc';
    $icon = '';
    if ($currentSort === $col) {
        $icon = $currentDir === 'ASC'
            ? ' <i class="fas fa-caret-up"></i>'
            : ' <i class="fas fa-caret-down"></i>';
    }
    $href = h(buildQuery(['sort' => $col, 'dir' => $nextDir, 'page' => null]));
    return '<a class="sort-link" data-ajax-link href="' . $href . '">' . h($label) . $icon . '</a>';
}

// ---------------- Results fragment (shared between full page load and AJAX) ----------------
function renderResultsFragment($logs, $page, $totalPages, $sortColumn, $sortDir) {
    ob_start();
    ?>
    <?php if ($logs): ?>
        <table class="log-table">
            <thead>
                <tr>
                    <th><?= sortLink('Time', 'created_at', $sortColumn, $sortDir) ?></th>
                    <th><?= sortLink('Staff', 'staff_name', $sortColumn, $sortDir) ?></th>
                    <th><?= sortLink('Action', 'action', $sortColumn, $sortDir) ?></th>
                    <th><?= sortLink('Module', 'module', $sortColumn, $sortDir) ?></th>
                    <th>Description</th>
                    <th>Entity</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="t-time">
                        <?= h(date('M j, Y', strtotime($log['created_at']))) ?><br>
                        <?= h(date('g:i A', strtotime($log['created_at']))) ?>
                    </td>
                    <td>
                        <div class="t-staff"><?= h($log['staff_name']) ?></div>
                        <?php if ($log['staff_role']): ?>
                            <div class="t-role"><?= h(ucfirst($log['staff_role'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= actionBadge($log['action']) ?></td>
                    <td><?= h(ucwords(str_replace('_', ' ', $log['module']))) ?></td>
                    <td class="t-desc"><?= h($log['description']) ?></td>
                    <td class="t-entity">
                        <?php if ($log['entity_type']): ?>
                            <?= h(ucfirst($log['entity_type'])) ?> #<?= h($log['entity_id']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination">
            <a data-ajax-link class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(buildQuery(['page' => $page - 1])) ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a data-ajax-link class="<?= $p === $page ? 'active' : '' ?>" href="<?= h(buildQuery(['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a data-ajax-link class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h(buildQuery(['page' => $page + 1])) ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list" style="font-size: 28px; display:block; margin-bottom: 10px; opacity:.4;"></i>
            No matching activity found.
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ---------------- AJAX endpoint: return just the results fragment ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo renderResultsFragment($logs, $page, $totalPages, $sortColumn, $sortDir);
    exit;
}

// ---------------- Filter dropdown options (full page load only) ----------------
$staffOptions  = $conn->query("SELECT DISTINCT staff_name FROM staff_activity_log ORDER BY staff_name ASC")->fetch_all(MYSQLI_ASSOC);
$moduleOptions = $conn->query("SELECT DISTINCT module FROM staff_activity_log ORDER BY module ASC")->fetch_all(MYSQLI_ASSOC);
$actionOptions = $conn->query("SELECT DISTINCT action FROM staff_activity_log ORDER BY action ASC")->fetch_all(MYSQLI_ASSOC);

// ---------------- Stat cards (full page load only) ----------------
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

$todayCount = $conn->query("SELECT COUNT(*) AS c FROM staff_activity_log WHERE DATE(created_at) = '{$today}'")->fetch_assoc()['c'] ?? 0;
$weekCount  = $conn->query("SELECT COUNT(*) AS c FROM staff_activity_log WHERE DATE(created_at) >= '{$weekStart}'")->fetch_assoc()['c'] ?? 0;
$deleteCount = $conn->query("SELECT COUNT(*) AS c FROM staff_activity_log WHERE action = 'delete' AND DATE(created_at) = '{$today}'")->fetch_assoc()['c'] ?? 0;

$mostActiveRow = $conn->query("
    SELECT staff_name, COUNT(*) AS c
    FROM staff_activity_log
    WHERE DATE(created_at) = '{$today}'
    GROUP BY staff_name
    ORDER BY c DESC
    LIMIT 1
")->fetch_assoc();
$mostActiveName = $mostActiveRow['staff_name'] ?? '—';
$mostActiveCount = $mostActiveRow['c'] ?? 0;

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log — Admin — Wansi Calacat Dental Clinic</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
 @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: 'DM Sans', system-ui, sans-serif; }
  body {
    background-color: #f8fafc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
}

.main-content { margin-left: 270px; min-height: 100vh; }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }

.page { max-width: 1320px; margin: 0 auto; padding: 28px 32px 56px; }

.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.topbar h1 { font-size: 22px; font-weight: 800; margin: 0; color: #0b1628;font-family: 'Playfair Display', Georgia, serif; }
.topbar .sub { font-size: 13px; color: #6b7280; font-weight: 500; margin-top: 2px; }

.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: white; border: 1px solid #eef1f6; border-left: 4px solid #0d9e8c;
    border-radius: 12px; padding: 18px 20px;
}
.stat-card.alt1 { border-left-color: #7c3aed; }
.stat-card.alt2 { border-left-color: #dc2626; }
.stat-card.alt3 { border-left-color: #2563eb; }
.stat-card .lbl { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.stat-card .num { font-size: 26px; font-weight: 800; color: #0b1628; margin: 4px 0 2px; }
.stat-card .sub { font-size: 12px; color: #9aa4b2; }

.panel { background: white; border: 1px solid #eef1f6; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; }

.filter-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
.filter-field { display: flex; flex-direction: column; gap: 4px; }
.filter-field label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .3px; }
.filter-field select, .filter-field input {
    border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; font-size: 13px; font-family: inherit; color: #111827;
    min-width: 140px;
}
.filter-field input[type="text"] { min-width: 200px; }
.filter-actions { display: flex; gap: 8px; }
.btn {
    padding: 8px 16px; border-radius: 8px; border: 1px solid #e5e7eb; background: white;
    font-size: 13px; font-weight: 700; color: #111827; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.btn:hover { background: #f3f4f6; }
.btn-primary { background: #0d9e8c; border-color: #0d9e8c; color: white; }
.btn-primary:hover { background: #0a7d6e; }

.log-table { width: 100%; border-collapse: collapse; }
.log-table th {
    text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    color: #9aa4b2; padding: 0 10px 10px; border-bottom: 1px solid #f1f5f9;
}
.log-table th .sort-link { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
.log-table th .sort-link:hover { color: #0d9e8c; }
.log-table td { padding: 11px 10px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.log-table tr:last-child td { border-bottom: none; }
.log-table .t-time { color: #9aa4b2; font-size: 12px; white-space: nowrap; }
.log-table .t-staff { font-weight: 700; color: #0b1628; }
.log-table .t-role { font-size: 11px; color: #9aa4b2; }
.log-table .t-desc { color: #374151; }
.log-table .t-entity { font-size: 11.5px; color: #9aa4b2; }

.badge-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 50px; white-space: nowrap; }

.empty-state { text-align: center; padding: 40px; color: #9aa4b2; font-size: 13.5px; }

.pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 18px; }
.pagination a, .pagination span {
    padding: 6px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 700; text-decoration: none;
    border: 1px solid #e5e7eb; color: #374151;
}
.pagination a:hover { background: #f3f4f6; }
.pagination .active { background: #0d9e8c; border-color: #0d9e8c; color: white; }
.pagination .disabled { opacity: .4; pointer-events: none; }

.results-loading { opacity: .45; pointer-events: none; transition: opacity .15s; }
</style>
</head>
<body>

<div class="main-content">
    <div class="page">

        <div class="topbar">
            <div>
                <h1>Staff Audit Log</h1>
                <div class="sub">A record of every logged staff action across the system.</div>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="lbl">Actions Today</div>
                <div class="num"><?= h($todayCount) ?></div>
                <div class="sub"><?= h(date('F j, Y')) ?></div>
            </div>
            <div class="stat-card alt3">
                <div class="lbl">Actions This Week</div>
                <div class="num"><?= h($weekCount) ?></div>
                <div class="sub">Since Monday</div>
            </div>
            <div class="stat-card alt1">
                <div class="lbl">Most Active Today</div>
                <div class="num" style="font-size:18px;"><?= h($mostActiveName) ?></div>
                <div class="sub"><?= h($mostActiveCount) ?> action<?= $mostActiveCount == 1 ? '' : 's' ?></div>
            </div>
            <div class="stat-card alt2">
                <div class="lbl">Deletions Today</div>
                <div class="num"><?= h($deleteCount) ?></div>
                <div class="sub">Records removed</div>
            </div>
        </div>

        <div class="panel">
            <form class="filter-form" id="filterForm" method="get">
                <div class="filter-field">
                    <label>Staff</label>
                    <select name="staff">
                        <option value="">All staff</option>
                        <?php foreach ($staffOptions as $s): ?>
                            <option value="<?= h($s['staff_name']) ?>" <?= $filterStaff === $s['staff_name'] ? 'selected' : '' ?>>
                                <?= h($s['staff_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label>Module</label>
                    <select name="module">
                        <option value="">All modules</option>
                        <?php foreach ($moduleOptions as $m): ?>
                            <option value="<?= h($m['module']) ?>" <?= $filterModule === $m['module'] ? 'selected' : '' ?>>
                                <?= h(ucwords(str_replace('_', ' ', $m['module']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label>Action</label>
                    <select name="action">
                        <option value="">All actions</option>
                        <?php foreach ($actionOptions as $a): ?>
                            <option value="<?= h($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                                <?= h(ucwords(str_replace('_', ' ', $a['action']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label>From</label>
                    <input type="date" name="from" value="<?= h($dateFrom) ?>">
                </div>
                <div class="filter-field">
                    <label>To</label>
                    <input type="date" name="to" value="<?= h($dateTo) ?>">
                </div>
                <div class="filter-field">
                    <label>Search</label>
                    <input type="text" name="q" id="searchInput" placeholder="Search description or staff..." value="<?= h($filterQ) ?>" autocomplete="off">
                </div>
                <!-- Sort/page carried over automatically via JS; kept as hidden fields for no-JS fallback -->
                <input type="hidden" name="sort" value="<?= h($sortColumn) ?>">
                <input type="hidden" name="dir" value="<?= h(strtolower($sortDir)) ?>">
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="audit-log.php" class="btn"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <div class="panel">
            <div id="resultsPanel"><?= renderResultsFragment($logs, $page, $totalPages, $sortColumn, $sortDir) ?></div>
        </div>

    </div>
</div>

<script>
(function () {
    const form = document.getElementById('filterForm');
    const searchInput = document.getElementById('searchInput');
    const resultsPanel = document.getElementById('resultsPanel');
    let debounceTimer = null;

    function currentParams() {
        const params = new URLSearchParams(new FormData(form));
        // Drop empty values so the URL stays clean
        for (const key of Array.from(params.keys())) {
            if (params.get(key) === '') params.delete(key);
        }
        return params;
    }

    function loadResults(params, { resetPage = false } = {}) {
        if (resetPage) params.delete('page');
        resultsPanel.classList.add('results-loading');

        const fetchParams = new URLSearchParams(params);
        fetchParams.set('ajax', '1');

        fetch('audit-log.php?' + fetchParams.toString())
            .then(res => res.text())
            .then(html => {
                resultsPanel.innerHTML = html;
                resultsPanel.classList.remove('results-loading');
                bindResultLinks();

                // Keep the address bar (and hidden sort fields) in sync so
                // refresh / back-button / sharing the URL still works.
                const shareParams = new URLSearchParams(params);
                const sortField = form.querySelector('input[name="sort"]');
                const dirField = form.querySelector('input[name="dir"]');
                if (shareParams.has('sort')) sortField.value = shareParams.get('sort');
                if (shareParams.has('dir')) dirField.value = shareParams.get('dir');
                history.replaceState(null, '', 'audit-log.php?' + shareParams.toString());
            })
            .catch(() => {
                resultsPanel.classList.remove('results-loading');
            });
    }

    function bindResultLinks() {
        resultsPanel.querySelectorAll('a[data-ajax-link]').forEach(a => {
            if (a.classList.contains('disabled')) return;
            a.addEventListener('click', function (e) {
                e.preventDefault();
                const url = new URL(a.getAttribute('href'), window.location.href);
                loadResults(new URLSearchParams(url.search));
            });
        });
    }

    // Live search: debounce free-text typing so we're not firing a request per keystroke
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            loadResults(currentParams(), { resetPage: true });
        }, 300);
    });

    // Dropdowns / dates apply instantly on change, no need to wait for "Filter"
    form.querySelectorAll('select, input[type="date"]').forEach(el => {
        el.addEventListener('change', function () {
            loadResults(currentParams(), { resetPage: true });
        });
    });

    // "Filter" button still works (and covers browsers with JS quirks)
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        loadResults(currentParams(), { resetPage: true });
    });

    bindResultLinks();
})();
</script>

</body>
</html>