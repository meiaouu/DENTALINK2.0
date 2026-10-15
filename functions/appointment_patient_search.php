<?php
/** Staff-only patient search helpers. Demographic data only (no medical records). */

declare(strict_types=1);

function aps_staff_authorized(mysqli $conn): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $staffId = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($staffId < 1 || !(($_SESSION['staff_logged_in'] ?? false) === true || ($_SESSION['logged_in'] ?? false) === true)) return false;
    $stmt = $conn->prepare('SELECT role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && in_array($row['role'] ?? '', ['staff', 'admin'], true) && (int)($row['is_active'] ?? 0) === 1;
}

function aps_columns(mysqli $conn, string $table): array {
    if (!in_array($table, ['patients', 'appointments'], true)) throw new InvalidArgumentException('Invalid table');
    $result = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
    $cols = [];
    while ($row = $result->fetch_assoc()) $cols[$row['Field']] = true;
    return $cols;
}

function aps_like(string $value): string {
    // ! is the explicit LIKE escape character; SQL terms themselves are bound.
    return '%' . str_replace(['!', '%', '_', '\\'], ['!!', '!%', '!_', '!\\'], $value) . '%';
}

function aps_public_fields(array $cols): array {
    return array_values(array_intersect([
        'id', 'patient_id', 'full_name', 'first_name', 'middle_name', 'last_name',
        'age', 'date_of_birth', 'birth_date', 'dob', 'occupation', 'phone', 'email',
        'region', 'province', 'city', 'municipality', 'barangay', 'street_address',
        'house_number', 'address', 'status', 'appointment_id'
    ], array_keys($cols)));
}

function aps_patient_label(array $row): string {
    $name = trim((string)($row['full_name'] ?? ''));
    if ($name !== '') return $name;
    return trim(implode(' ', array_filter([
        (string)($row['first_name'] ?? ''), (string)($row['middle_name'] ?? ''), (string)($row['last_name'] ?? '')
    ], fn($v) => $v !== '')));
}

function aps_patient_payload(array $p): array {
    $dob = $p['date_of_birth'] ?? $p['birth_date'] ?? $p['dob'] ?? null;
    $age = is_numeric($p['age'] ?? null) ? (int)$p['age'] : null;
    if ($dob && $dob !== '0000-00-00') {
        try {
            $birth = new DateTimeImmutable((string)$dob);
            $today = new DateTimeImmutable('today');
            if ($birth <= $today) $age = $birth->diff($today)->y;
        } catch (Throwable $e) { /* Keep stored age, if any. */ }
    }
    // Current DentaLink patients schema stores address in one field.
    // Do not guess administrative subdivisions from a comma-separated string.
    $rawAddress = trim((string)($p['address'] ?? ''));
    $hasStructuredAddress = false;
    foreach (['region', 'province', 'city', 'municipality', 'barangay', 'street_address'] as $key) {
        if (trim((string)($p[$key] ?? '')) !== '') $hasStructuredAddress = true;
    }
    return [
        'id' => (int)($p['id'] ?? 0),
        'patient_id' => (string)($p['patient_id'] ?? ($p['id'] ?? '')),
        'full_name' => aps_patient_label($p),
        'age' => $age,
        'occupation' => (string)($p['occupation'] ?? ''),
        'phone' => (string)($p['phone'] ?? ''),
        'email' => (string)($p['email'] ?? ''),
        'region' => (string)($p['region'] ?? ''),
        'province' => (string)($p['province'] ?? ''),
        'city' => (string)($p['city'] ?? ($p['municipality'] ?? '')),
        'barangay' => (string)($p['barangay'] ?? ''),
        'street_address' => (string)($p['street_address'] ?? ($p['house_number'] ?? '')),
        'address' => $rawAddress,
        'address_single_field' => !$hasStructuredAddress && $rawAddress !== '',
        'status' => (string)($p['status'] ?? '')
    ];
}

function aps_get_patient(mysqli $conn, int $id, ?array $cols = null): ?array {
    if ($id < 1) return null;
    $cols = $cols ?? aps_columns($conn, 'patients');
    if (!isset($cols['id'])) throw new RuntimeException('Patient primary key is unavailable.');
    $selected = aps_public_fields($cols);
    $sql = 'SELECT ' . implode(', ', array_map(fn($col) => '`' . $col . '`', $selected)) . ' FROM patients WHERE id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $patient = aps_patient_payload($row);
    // Read booking fields only from appointments explicitly linked to this patient.
    // Never guess identity from a shared email or telephone number.
    $appCols = aps_columns($conn, 'appointments');
    $available = array_values(array_intersect(
        ['age', 'occupation', 'region', 'province', 'city', 'barangay', 'street_address'], array_keys($appCols)
    ));
    $sourceAppointmentId = (int)($row['appointment_id'] ?? 0);
    $stored = [];
    if ($available) {
        $fields = implode(', ', array_map(fn($c) => '`' . $c . '`', $available));
        if (isset($appCols['patient_id'])) {
            $sql = 'SELECT ' . $fields . ' FROM appointments WHERE patient_id = ? ORDER BY created_at DESC, id DESC LIMIT 1';
            $appointmentStmt = $conn->prepare($sql);
            $appointmentStmt->bind_param('i', $id);
            $appointmentStmt->execute();
            $stored = $appointmentStmt->get_result()->fetch_assoc() ?: [];
            $appointmentStmt->close();
        }
        if (!$stored && $sourceAppointmentId > 0) {
            $sql = 'SELECT ' . $fields . ' FROM appointments WHERE id = ? LIMIT 1';
            $appointmentStmt = $conn->prepare($sql);
            $appointmentStmt->bind_param('i', $sourceAppointmentId);
            $appointmentStmt->execute();
            $stored = $appointmentStmt->get_result()->fetch_assoc() ?: [];
            $appointmentStmt->close();
        }
        foreach ($available as $col) {
            if (trim((string)($patient[$col] ?? '')) === '' && trim((string)($stored[$col] ?? '')) !== ''
                && trim((string)$stored[$col]) !== '—') {
                $patient[$col] = $stored[$col];
            }
        }
    }
    // Preserve an unstructured patient address exactly. Never split a street or
    // barangay on commas and pretend the result is an administrative division.
    $patient['address_single_field'] = trim((string)($patient['address'] ?? '')) !== '';
    return $patient;
}

function aps_search_patients(mysqli $conn, string $query, int $limit = 10): array {
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    if ($query === '') return [];
    if (strlen($query) > 180) throw new InvalidArgumentException('Please enter a shorter search.');
    $columns = aps_columns($conn, 'patients');
    $selected = aps_public_fields($columns);
    $nameCols = array_intersect(['full_name', 'first_name', 'middle_name', 'last_name'], array_keys($columns));
    $contactCols = array_intersect(['email', 'phone', 'patient_id'], array_keys($columns));
    if (!$nameCols && !$contactCols) throw new RuntimeException('Patient search fields are unavailable.');

    $params = []; $types = ''; $clauses = [];
    // A token may be in any name component, so "Marjorie Pul" matches full names and split columns.
    $parts = array_slice(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY), 0, 8);
    $tokenClauses = [];
    foreach ($parts as $token) {
        $alternatives = [];
        foreach ($nameCols as $col) {
            $alternatives[] = 'LOWER(`' . $col . '`) LIKE LOWER(?) ESCAPE \'!\'';
            $params[] = aps_like($token); $types .= 's';
        }
        if ($alternatives) $tokenClauses[] = '(' . implode(' OR ', $alternatives) . ')';
    }
    if ($tokenClauses) $clauses[] = '(' . implode(' AND ', $tokenClauses) . ')';
    foreach ($contactCols as $col) {
        $clauses[] = 'LOWER(`' . $col . '`) LIKE LOWER(?) ESCAPE \'!\'';
        $params[] = aps_like($query); $types .= 's';
    }
    $clauses[] = 'CAST(`id` AS CHAR) LIKE ? ESCAPE \'!\'';
    $params[] = aps_like($query); $types .= 's';
    $limit = max(1, min(10, $limit));
    $sql = 'SELECT ' . implode(', ', array_map(fn($col) => '`' . $col . '`', $selected)) .
        ' FROM patients WHERE (' . implode(' OR ', $clauses) . ') ORDER BY `id` DESC LIMIT ?';
    $params[] = $limit; $types .= 'i';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static function ($r) {
        $p = aps_patient_payload($r);
        return array_intersect_key($p, array_flip(['id', 'patient_id', 'full_name', 'phone', 'email']));
    }, $rows);
}

function aps_possible_matches(mysqli $conn, string $email, string $phone, string $name = ''): array {
    $cols = aps_columns($conn, 'patients');
    $conditions = []; $params = []; $types = '';
    if ($email !== '' && isset($cols['email'])) {
        $conditions[] = '(email IS NOT NULL AND email <> \'\' AND LOWER(email) = LOWER(?))';
        $params[] = $email; $types .= 's';
    }
    if ($phone !== '' && isset($cols['phone'])) {
        $conditions[] = '(phone IS NOT NULL AND phone <> \'\' AND phone = ?)';
        $params[] = $phone; $types .= 's';
    }
    if ($name !== '' && isset($cols['full_name'])) {
        $conditions[] = '(LOWER(TRIM(full_name)) = LOWER(?))';
        $params[] = trim($name); $types .= 's';
    }
    if (!$conditions) return [];
    $stmt = $conn->prepare('SELECT `id`, ' . (isset($cols['patient_id']) ? '`patient_id`' : '`id` AS patient_id') .
        ', ' . (isset($cols['full_name']) ? '`full_name`' : 'CONCAT_WS(\' \', `first_name`, `last_name`) AS full_name') .
        ' FROM patients WHERE (' . implode(' OR ', $conditions) . ') LIMIT 5');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
