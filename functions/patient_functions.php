<?php
/**
 * Patient Functions
 * Contains helper functions for patient management
 */

/**
 * Generate a unique patient ID
 * 
 * @param string $prefix Optional prefix for the ID
 * @return string Generated patient ID
 */
function generatePatientId($prefix = 'PAT') {
    $date = date('Ymd');
    $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    return $prefix . '-' . $date . '-' . $random;
}

/**
 * Validate patient data before saving
 * 
 * @param array $data Patient data to validate
 * @return array ['valid' => bool, 'errors' => array]
 */
function validatePatientData($data) {
    $errors = [];
    
    // Required fields
    if (empty($data['full_name'])) {
        $errors[] = 'Full name is required';
    }
    
    if (empty($data['phone'])) {
        $errors[] = 'Phone number is required';
    }
    
    // Email validation
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Age validation
    if (!empty($data['age']) && (!is_numeric($data['age']) || $data['age'] < 0 || $data['age'] > 150)) {
        $errors[] = 'Invalid age. Please enter a valid age between 0 and 150.';
    }
    
    // Phone validation (Philippine format)
    if (!empty($data['phone'])) {
        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
        if (strlen($phone) < 10 || strlen($phone) > 13) {
            $errors[] = 'Invalid phone number. Please enter a valid Philippine phone number.';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Format patient address from components
 * 
 * @param array $addressComponents Array with keys: street, barangay, city, province, region
 * @return string Formatted address
 */
function formatAddress($addressComponents) {
    $parts = [];
    if (!empty($addressComponents['street'])) $parts[] = $addressComponents['street'];
    if (!empty($addressComponents['barangay'])) $parts[] = $addressComponents['barangay'];
    if (!empty($addressComponents['city'])) $parts[] = $addressComponents['city'];
    if (!empty($addressComponents['province'])) $parts[] = $addressComponents['province'];
    if (!empty($addressComponents['region'])) $parts[] = $addressComponents['region'];
    
    return implode(', ', $parts);
}

/**
 * Get patient status badge HTML
 * 
 * @param string $status Patient status
 * @return string HTML badge
 */
function getPatientStatusBadge($status) {
    $badges = [
        'active' => '<span class="status-badge status-active"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Active</span>',
        'inactive' => '<span class="status-badge status-inactive"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Inactive</span>',
        'pending' => '<span class="status-badge status-pending"><i class="fas fa-circle mr-1" style="font-size:6px;"></i> Pending</span>'
    ];
    
    return $badges[$status] ?? $badges['pending'];
}

/**
 * Get patient source badge HTML
 * 
 * @param string $source Patient source
 * @return string HTML badge
 */
function getPatientSourceBadge($source) {
    $badges = [
        'walkin' => '<span class="source-badge source-walkin"><i class="fas fa-walking mr-1"></i> Walk-in</span>',
        'appointment' => '<span class="source-badge source-appointment"><i class="fas fa-calendar-check mr-1"></i> From Appointment</span>'
    ];
    
    return $badges[$source] ?? $badges['walkin'];
}

/**
 * Get patient's full address from database fields
 * 
 * @param array $patient Patient data with address fields
 * @return string Formatted address
 */
function getPatientFullAddress($patient) {
    $parts = [];
    if (!empty($patient['street_address'])) $parts[] = $patient['street_address'];
    if (!empty($patient['barangay'])) $parts[] = $patient['barangay'];
    if (!empty($patient['city'])) $parts[] = $patient['city'];
    if (!empty($patient['province'])) $parts[] = $patient['province'];
    if (!empty($patient['region'])) $parts[] = $patient['region'];
    
    return !empty($parts) ? implode(', ', $parts) : 'No address provided';
}

/**
 * Calculate age from birth date
 * 
 * @param string $birthDate Birth date (Y-m-d)
 * @return int Age in years
 */
function calculateAge($birthDate) {
    if (empty($birthDate)) return null;
    
    $birth = new DateTime($birthDate);
    $today = new DateTime('today');
    $age = $birth->diff($today)->y;
    
    return $age;
}

/**
 * Check if patient exists by phone or email
 * 
 * @param mysqli $conn Database connection
 * @param string $phone Phone number
 * @param string $email Email address
 * @param int $excludeId Patient ID to exclude from check (for updates)
 * @return array Patient data if exists, null otherwise
 */
function patientExists($conn, $phone, $email, $excludeId = 0) {
    $sql = "SELECT * FROM patients WHERE (phone = ? OR email = ?)";
    $params = [$phone, $email];
    $types = "ss";
    
    if ($excludeId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();
    $stmt->close();
    
    return $patient;
}

/**
 * Get patient appointment history
 * 
 * @param mysqli $conn Database connection
 * @param int $patientId Patient ID
 * @param int $limit Number of records to return
 * @return array Appointment history
 */
function getPatientAppointments($conn, $patientId, $limit = 10) {
    $sql = "SELECT a.* FROM appointments a 
            INNER JOIN patients p ON p.phone = a.phone OR p.email = a.email
            WHERE p.id = ?
            ORDER BY a.created_at DESC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patientId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $appointments;
}

/**
 * Get patient statistics
 * 
 * @param mysqli $conn Database connection
 * @param int $patientId Patient ID
 * @return array Patient statistics
 */
function getPatientStats($conn, $patientId) {
    $stats = [
        'total_appointments' => 0,
        'completed_appointments' => 0,
        'cancelled_appointments' => 0,
        'total_spent' => 0,
        'last_visit' => null
    ];
    
    // Get appointment stats
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                MAX(CASE WHEN status = 'completed' THEN appointment_date END) as last_visit
            FROM appointments 
            WHERE phone IN (SELECT phone FROM patients WHERE id = ?)
            OR email IN (SELECT email FROM patients WHERE id = ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patientId, $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    if ($data) {
        $stats['total_appointments'] = (int)$data['total'];
        $stats['completed_appointments'] = (int)$data['completed'];
        $stats['cancelled_appointments'] = (int)$data['cancelled'];
        $stats['last_visit'] = $data['last_visit'];
    }
    
    return $stats;
}

/**
 * Format patient ID for display
 * 
 * @param string $patientId Raw patient ID
 * @return string Formatted patient ID
 */
function formatPatientId($patientId) {
    return '#' . htmlspecialchars($patientId);
}

/**
 * Get patient full name with title
 * 
 * @param string $fullName Full name
 * @param string $gender Gender
 * @return string Formatted name with title
 */
function getPatientFullName($fullName, $gender = null) {
    $title = '';
    if ($gender === 'Male') {
        $title = 'Mr. ';
    } elseif ($gender === 'Female') {
        $title = 'Ms. ';
    }
    
    return $title . htmlspecialchars($fullName);
}

/**
 * Export patients to CSV
 * 
 * @param mysqli $conn Database connection
 * @param array $patientIds Array of patient IDs to export
 * @return string CSV content
 */
function exportPatientsToCSV($conn, $patientIds = []) {
    $sql = "SELECT * FROM patients";
    if (!empty($patientIds)) {
        $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
        $sql .= " WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($patientIds));
        $stmt->bind_param($types, ...$patientIds);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $patients = $result->fetch_all(MYSQLI_ASSOC);
    
    if (!empty($patientIds) && isset($stmt)) {
        $stmt->close();
    }
    
    // Build CSV
    $csv = "Patient ID,Full Name,Age,Gender,Phone,Email,Address,Status,Source,Created At\n";
    foreach ($patients as $patient) {
        $csv .= implode(',', [
            '"' . $patient['patient_id'] . '"',
            '"' . $patient['full_name'] . '"',
            $patient['age'] ?? '',
            '"' . ($patient['gender'] ?? '') . '"',
            '"' . $patient['phone'] . '"',
            '"' . $patient['email'] . '"',
            '"' . ($patient['address'] ?? '') . '"',
            $patient['status'],
            $patient['source'] ?? 'walkin',
            $patient['created_at'] ?? ''
        ]) . "\n";
    }
    
    return $csv;
}

/**
 * Search patients by various criteria
 * 
 * @param mysqli $conn Database connection
 * @param string $query Search query
 * @param int $limit Number of results
 * @return array Search results
 */
function searchPatients($conn, $query, $limit = 20) {
    $sql = "SELECT * FROM patients 
            WHERE full_name LIKE ? 
            OR phone LIKE ? 
            OR email LIKE ? 
            OR patient_id LIKE ?
            ORDER BY full_name ASC LIMIT ?";
    
    $search_param = "%$query%";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssi", $search_param, $search_param, $search_param, $search_param, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $patients;
}

/**
 * Get patients with upcoming appointments
 * 
 * @param mysqli $conn Database connection
 * @param int $days Number of days to look ahead
 * @param int $limit Number of results
 * @return array Patients with upcoming appointments
 */
function getPatientsWithUpcomingAppointments($conn, $days = 7, $limit = 10) {
    $sql = "SELECT DISTINCT p.* FROM patients p
            INNER JOIN appointments a ON (p.phone = a.phone OR p.email = a.email)
            WHERE a.appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            AND a.status = 'confirmed'
            ORDER BY a.appointment_date ASC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $days, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $patients;
}
?>