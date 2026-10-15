<?php
// api/load_reviews.php — paginated + filterable reviews feed
require '../conn/conn.php';  // adjust path if needed
header('Content-Type: application/json');

// ---- Input ----
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit  = min(20, max(1, (int)($_GET['limit'] ?? 8)));
$filter = $_GET['filter'] ?? 'all';

// ---- Build WHERE for filter ----
$whereRating = '';
if ($filter === '5') $whereRating = ' AND f.rating = 5 ';
elseif ($filter === '4') $whereRating = ' AND f.rating = 4 ';

// ---- Total count for current filter ----
$countSql = "
    SELECT COUNT(*) AS c
    FROM feedback f
    WHERE f.rating >= 4
      AND f.comment IS NOT NULL
      AND TRIM(f.comment) <> ''
      $whereRating
";
$total = (int)$conn->query($countSql)->fetch_assoc()['c'];

// ---- Fetch page ----
$sql = "
    SELECT 
        f.id,
        f.rating,
        f.comment,
        f.created_at,
        p.full_name,
        s.title AS service_title,
        d.first_name AS dentist_first,
        d.last_name  AS dentist_last
    FROM feedback f
    JOIN patients          p  ON f.patient_id         = p.id
    JOIN patient_services  ps ON f.patient_service_id = ps.id
    JOIN services          s  ON ps.service_id        = s.id
    JOIN dentists          d  ON f.dentist_id         = d.id
    WHERE f.rating >= 4
      AND f.comment IS NOT NULL
      AND TRIM(f.comment) <> ''
      $whereRating
    ORDER BY f.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Render HTML (same markup as homepage cards) ----
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$html = '';
foreach ($rows as $fb) {
    $fullName = trim($fb['full_name'] ?? '');
    if ($fullName === '') $fullName = 'Patient';

    $parts     = preg_split('/\s+/', $fullName);
    $firstName = $parts[0] ?? 'Patient';
    $lastName  = end($parts) ?: '';

    $safeName = $firstName;
    if (count($parts) > 1 && $lastName !== $firstName) {
        $safeName .= ' ' . strtoupper(substr($lastName, 0, 1)) . '.';
    }
    $initial = strtoupper(substr($firstName, 0, 1));

    $rating = (int)$fb['rating'];
    $stars  = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= '<i class="fa' . ($i <= $rating ? 's' : 'r') . ' fa-star ' . ($i > $rating ? 'empty' : '') . '"></i>';
    }

    $html .= '
    <div class="feedback-card">
        <div class="feedback-card-stars">' . $stars . '</div>
        <p class="feedback-card-comment">' . h($fb['comment']) . '</p>
        <div class="feedback-card-footer">
            <div class="feedback-avatar">' . h($initial) . '</div>
            <div class="feedback-author">
                <div class="feedback-author-name">' . h($safeName) . '</div>
                <div class="feedback-author-meta">
                    <i class="far fa-calendar-alt"></i> ' . date('M d, Y', strtotime($fb['created_at'])) . '
                    <span class="dot">·</span>
                    <i class="fas fa-user-md"></i> Dr. ' . h($fb['dentist_first'] . ' ' . $fb['dentist_last']) . '
                </div>
                <span class="feedback-service-tag">' . h($fb['service_title']) . '</span>
            </div>
        </div>
    </div>';
}

echo json_encode([
    'success' => true,
    'html'    => $html,
    'total'   => $total,
    'reviews' => array_map(fn($r) => ['id' => (int)$r['id']], $rows),
]);


