<?php
// api/save_appointment_patient.php - FINAL FIXED VERSION

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Set JSON header
header('Content-Type: application/json');

// Create a log function
function log_debug($message) {
    error_log("save_appointment_patient: " . $message);
}

log_debug("=== Starting save_appointment_patient ===");

try {
    // Include database connection
    log_debug("Including conn.php");
    require_once '../conn/conn.php';
    
    // Check if connection exists
    if (!$conn) {
        log_debug("Database connection failed");
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    log_debug("Database connection successful");

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        log_debug("Starting session");
        session_start();
    }

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        log_debug("User not logged in");
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    log_debug("User logged in: " . $_SESSION['user_id']);

    // Get the JSON input
    log_debug("Getting JSON input");
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        log_debug("Invalid JSON input");
        echo json_encode(['success' => false, 'message' => 'Invalid request data']);
        exit;
    }
    log_debug("Input received: " . json_encode($input));

    // Validate required fields
    $required = ['phone', 'services', 'appointment_date', 'appointment_time', 'full_name'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            log_debug("Missing field: $field");
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit;
        }
    }
    log_debug("All required fields present");

    // Sanitize inputs
    $phone = mysqli_real_escape_string($conn, $input['phone']);
    $email = isset($input['email']) ? mysqli_real_escape_string($conn, $input['email']) : '';
    $services = json_encode($input['services']);
    $appointment_date = mysqli_real_escape_string($conn, $input['appointment_date']);
    $appointment_time = mysqli_real_escape_string($conn, $input['appointment_time']);
    $full_name = mysqli_real_escape_string($conn, $input['full_name']);
    $age = isset($input['age']) ? (int)$input['age'] : 0;
    $occupation = isset($input['occupation']) ? mysqli_real_escape_string($conn, $input['occupation']) : '';
    $street_address = isset($input['street_address']) ? mysqli_real_escape_string($conn, $input['street_address']) : '';
    $notes = isset($input['notes']) ? mysqli_real_escape_string($conn, $input['notes']) : '';
    $terms_accepted = isset($input['terms_accepted']) ? (int)$input['terms_accepted'] : 0;
    $privacy_accepted = isset($input['privacy_accepted']) ? (int)$input['privacy_accepted'] : 0;
    $is_verified = isset($input['is_verified']) ? (int)$input['is_verified'] : 0;

    log_debug("Sanitized data: phone=$phone, email=$email, date=$appointment_date");

    // Check if patient already has an appointment
    log_debug("Checking for existing appointment");
    $check_sql = "SELECT id FROM appointments WHERE phone = '$phone' AND appointment_date = '$appointment_date' AND appointment_time = '$appointment_time' AND status != 'cancelled'";
    log_debug("Check SQL: $check_sql");
    
    $check_result = mysqli_query($conn, $check_sql);
    if (!$check_result) {
        log_debug("Check query failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }

    if (mysqli_num_rows($check_result) > 0) {
        log_debug("Existing appointment found");
        echo json_encode(['success' => false, 'message' => 'You already have an appointment at this date and time.']);
        exit;
    }
    log_debug("No existing appointment found");

    // STEP 1: Insert appointment WITHOUT tracking_token first
    log_debug("Inserting appointment");
    $insert_sql = "
        INSERT INTO appointments (
            phone, email, services, appointment_date, appointment_time, 
            full_name, age, occupation, street_address, notes,
            terms_accepted, privacy_accepted, is_verified, status
        ) VALUES (
            '$phone', '$email', '$services', '$appointment_date', '$appointment_time',
            '$full_name', $age, '$occupation', '$street_address', '$notes',
            $terms_accepted, $privacy_accepted, $is_verified, 'pending'
        )
    ";
    log_debug("Insert SQL: $insert_sql");

    $result = mysqli_query($conn, $insert_sql);
    
    if (!$result) {
        log_debug("Insert failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to save appointment: ' . mysqli_error($conn)]);
        exit;
    }

    // STEP 2: Get the appointment ID
    $appointment_id = mysqli_insert_id($conn);
    log_debug("Appointment inserted with ID: $appointment_id");

    // STEP 3: Generate tracking token using the SAME format as guest bookings
    // This is Base64 ENCODED, NOT HASHED!
    // Format: base64_encode("appointment_id|timestamp|random")
    $timestamp = time();
    $random = mt_rand(1000, 9999);
    $token_data = $appointment_id . '|' . $timestamp . '|' . $random;
    $tracking_token = base64_encode($token_data);
    log_debug("Tracking token generated: $tracking_token");
    log_debug("Token data: $token_data");

    // STEP 4: Update the appointment with the tracking token
    $update_sql = "UPDATE appointments SET tracking_token = '$tracking_token' WHERE id = $appointment_id";
    log_debug("Update SQL: $update_sql");
    $update_result = mysqli_query($conn, $update_sql);
    if (!$update_result) {
        log_debug("Update failed: " . mysqli_error($conn));
        // Continue anyway - the appointment exists, just without a token
    } else {
        log_debug("Tracking token updated successfully");
    }

    // Save medical history if data exists
    if (isset($input['under_physician_care']) || isset($input['pregnant'])) {
        log_debug("Saving medical history");
        
        // Check if medical_dental_history table exists
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'medical_dental_history'");
        if (mysqli_num_rows($table_check) > 0) {
            log_debug("medical_dental_history table exists");
            
            // Get columns
            $columns_result = mysqli_query($conn, "SHOW COLUMNS FROM medical_dental_history");
            $columns = [];
            while ($col = mysqli_fetch_assoc($columns_result)) {
                $columns[] = $col['Field'];
            }
            log_debug("Columns: " . implode(', ', $columns));
            
            // Build insert for medical history
            $mh_fields = [];
            $mh_values = [];
            
            $field_mapping = [
                'appointment_id' => $appointment_id,
                'under_physician_care' => isset($input['under_physician_care']) ? "'" . mysqli_real_escape_string($conn, $input['under_physician_care']) . "'" : 'NULL',
                'physician_name' => isset($input['physician_name']) ? "'" . mysqli_real_escape_string($conn, $input['physician_name']) . "'" : 'NULL',
                'pregnant' => isset($input['pregnant']) ? "'" . mysqli_real_escape_string($conn, $input['pregnant']) . "'" : 'NULL',
                'taking_medicine' => isset($input['taking_medicine']) ? "'" . mysqli_real_escape_string($conn, $input['taking_medicine']) . "'" : 'NULL',
                'medicine_details' => isset($input['medicine_details']) ? "'" . mysqli_real_escape_string($conn, $input['medicine_details']) . "'" : 'NULL',
                'conditions' => isset($input['conditions']) ? "'" . mysqli_real_escape_string($conn, $input['conditions']) . "'" : 'NULL',
                'other_condition_details' => isset($input['other_condition_details']) ? "'" . mysqli_real_escape_string($conn, $input['other_condition_details']) . "'" : 'NULL',
                'allergic_local_anesthesia' => isset($input['allergic_local_anesthesia']) ? "'" . mysqli_real_escape_string($conn, $input['allergic_local_anesthesia']) . "'" : 'NULL',
                'allergic_antibiotics' => isset($input['allergic_antibiotics']) ? "'" . mysqli_real_escape_string($conn, $input['allergic_antibiotics']) . "'" : 'NULL',
                'allergic_pain_killer' => isset($input['allergic_pain_killer']) ? "'" . mysqli_real_escape_string($conn, $input['allergic_pain_killer']) . "'" : 'NULL',
                'allergic_other' => isset($input['allergic_other']) ? "'" . mysqli_real_escape_string($conn, $input['allergic_other']) . "'" : 'NULL',
                'allergic_other_details' => isset($input['allergic_other_details']) ? "'" . mysqli_real_escape_string($conn, $input['allergic_other_details']) . "'" : 'NULL',
                'hospitalized' => isset($input['hospitalized']) ? "'" . mysqli_real_escape_string($conn, $input['hospitalized']) . "'" : 'NULL',
                'hospitalized_when' => isset($input['hospitalized_when']) ? "'" . mysqli_real_escape_string($conn, $input['hospitalized_when']) . "'" : 'NULL',
                'hospitalized_why' => isset($input['hospitalized_why']) ? "'" . mysqli_real_escape_string($conn, $input['hospitalized_why']) . "'" : 'NULL',
                'worn_denture' => isset($input['worn_denture']) ? "'" . mysqli_real_escape_string($conn, $input['worn_denture']) . "'" : 'NULL',
                'last_dental_visit' => isset($input['last_dental_visit']) ? "'" . mysqli_real_escape_string($conn, $input['last_dental_visit']) . "'" : 'NULL',
                'last_dental_visit_reason' => isset($input['last_dental_visit_reason']) ? "'" . mysqli_real_escape_string($conn, $input['last_dental_visit_reason']) . "'" : 'NULL'
            ];
            
            foreach ($field_mapping as $field => $value) {
                if (in_array($field, $columns)) {
                    $mh_fields[] = $field;
                    $mh_values[] = $value;
                }
            }
            
            if (!empty($mh_fields)) {
                $mh_sql = "INSERT INTO medical_dental_history (" . implode(", ", $mh_fields) . ") VALUES (" . implode(", ", $mh_values) . ")";
                log_debug("Medical history SQL: $mh_sql");
                
                $mh_result = mysqli_query($conn, $mh_sql);
                if (!$mh_result) {
                    log_debug("Medical history insert failed: " . mysqli_error($conn));
                } else {
                    log_debug("Medical history saved successfully");
                }
            }
        } else {
            log_debug("medical_dental_history table does not exist");
        }
    }

    // Success response
    log_debug("Sending success response");
    echo json_encode([
        'success' => true,
        'message' => 'Appointment request submitted successfully',
        'appointment_id' => $appointment_id,
        'tracking_token' => $tracking_token
    ]);

} catch (Exception $e) {
    log_debug("Exception: " . $e->getMessage());
    log_debug("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

// Close connection
if (isset($conn) && $conn) {
    mysqli_close($conn);
}

log_debug("=== Ending save_appointment_patient ===");
exit;
?>