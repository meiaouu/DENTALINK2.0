<?php
/**
 * audit_log.php
 *
 * One function you call from anywhere staff pages do something worth
 * recording. Drop this file in your existing /functions/ folder and
 * require it next to your other requires:
 *
 *     require '../../functions/audit_log.php';
 *
 * It figures out who's logged in on its own, since your pages use a
 * couple of different session key names (staff_name/staff_id/staff_role
 * on some pages, user_name/user_role on others) - it checks both.
 */

if (!function_exists('log_staff_activity')) {

    /**
     * @param mysqli      $conn        Your existing DB connection.
     * @param string      $action      'create' | 'update' | 'delete' | 'status_change' | 'login' | 'logout' | etc.
     * @param string      $module      'appointments' | 'patients' | 'billing' | 'patient_services' | 'chat' | ...
     * @param string      $description Human-readable summary, e.g. "Marked appointment #1042 as in_progress"
     * @param string|null $entityType  e.g. 'appointment', 'patient', 'invoice' (optional)
     * @param int|null    $entityId    The row id affected, e.g. the appointment id (optional)
     * @param mixed|null  $oldValue    Optional array/object/string snapshot of the record before the change
     * @param mixed|null  $newValue    Optional array/object/string snapshot of the record after the change
     */
    function log_staff_activity(
        mysqli $conn,
        string $action,
        string $module,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        $oldValue = null,
        $newValue = null
    ): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $staffId   = isset($_SESSION['staff_id']) ? (int)$_SESSION['staff_id'] : (isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null);
        $staffName = $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? 'Unknown Staff';
        $staffRole = $_SESSION['staff_role'] ?? $_SESSION['user_role'] ?? $_SESSION['admin_role'] ?? null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $oldJson = $oldValue !== null ? (is_string($oldValue) ? $oldValue : json_encode($oldValue)) : null;
        $newJson = $newValue !== null ? (is_string($newValue) ? $newValue : json_encode($newValue)) : null;

        $stmt = $conn->prepare(
            "INSERT INTO staff_activity_log
                (staff_id, staff_name, staff_role, action, module, entity_type, entity_id, description, old_value, new_value, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        if (!$stmt) {
            // Never let logging break the actual feature the staff member is using.
            error_log('audit_log prepare failed: ' . $conn->error);
            return;
        }

        $stmt->bind_param(
            'isssssissss',
            $staffId, $staffName, $staffRole, $action, $module, $entityType, $entityId, $description, $oldJson, $newJson, $ip
        );

        if (!$stmt->execute()) {
            error_log('audit_log insert failed: ' . $stmt->error);
        }

        $stmt->close();
    }
}