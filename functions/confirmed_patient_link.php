<?php
/** Shared clinic-patient linkage. Requires appointments/patients InnoDB for transactions. */
function dcl_get_patient_for_appointment(mysqli $db, array $appt): int {
    $appointmentId = (int)$appt['id'];
    $linked = (int)($appt['patient_id'] ?? 0);
    if ($linked > 0) {
        $q=$db->prepare('SELECT id FROM patients WHERE id=? LIMIT 1');
        $q->bind_param('i',$linked); $q->execute(); $found=$q->get_result()->fetch_assoc(); $q->close();
        if (!$found) throw new DomainException('The linked patient record is missing. Please review it in Patients.');
        return $linked;
    }
    // Strongest existing link, including the older confirmation workflow.
    $q=$db->prepare('SELECT id FROM patients WHERE appointment_id=? ORDER BY id LIMIT 2');
    $q->bind_param('i',$appointmentId); $q->execute(); $hits=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    if (count($hits)>1) throw new DomainException('Multiple patient records reference this appointment. Please reconcile them in Patients.');
    if (count($hits)===1) return (int)$hits[0]['id'];

    // Only link an existing record on a unique full-name AND contact match.
    // Names or phone/email alone are never sufficient.
    $name=trim((string)($appt['full_name']??''));
    $email=trim((string)($appt['email']??''));
    $phone=trim((string)($appt['phone']??''));
    if ($name==='') throw new DomainException('Patient name is missing; verify before confirming.');
    $q=$db->prepare("SELECT id FROM patients WHERE LOWER(TRIM(full_name))=LOWER(?) AND ((?<>'' AND LOWER(email)=LOWER(?)) OR (?<>'' AND phone=?)) ORDER BY id LIMIT 2");
    $q->bind_param('sssss',$name,$email,$email,$phone,$phone); $q->execute(); $hits=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    if (count($hits)>1) throw new DomainException('More than one patient matches. Link the correct record through Patients first.');
    if (count($hits)===1) return (int)$hits[0]['id'];

    // If any record shares a name or contact, stop rather than create a potential duplicate.
    $q=$db->prepare("SELECT id FROM patients WHERE LOWER(TRIM(full_name))=LOWER(?) OR (?<>'' AND LOWER(email)=LOWER(?)) OR (?<>'' AND phone=?) LIMIT 1");
    $q->bind_param('sssss',$name,$email,$email,$phone,$phone); $q->execute(); $possible=$q->get_result()->fetch_assoc(); $q->close();
    if ($possible) throw new DomainException('Possible existing patient found. Verify and link this appointment before confirming.');

    $patientCode='PAT-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
    $age = isset($appt['age']) && $appt['age']!=='' ? (int)$appt['age'] : null;
    $addressParts=[];
    foreach (['street_address','barangay','city','province','region'] as $field) {
        $value=trim((string)($appt[$field]??''));
        if ($value!=='' && $value!=='—' && $value!=='-') $addressParts[]=$value;
    }
    $address=implode(', ', $addressParts);
    $q=$db->prepare("INSERT INTO patients (patient_id,full_name,age,phone,email,address,status,source,appointment_id,created_at) VALUES (?,?,?,?,?,?,'active','appointment',?,NOW())");
    $q->bind_param('ssisssi',$patientCode,$name,$age,$phone,$email,$address,$appointmentId);
    $q->execute(); $created=(int)$db->insert_id; $q->close();
    return $created;
}
/**
 * Confirm + link within one transaction. Both tables MUST be InnoDB.
 * Idempotent: a second confirmation preserves arrived_at.
 */
function dcl_confirm_appointment(mysqli $db, int $appointmentId, ?int $dentistId=null, ?string $notes=null): array {
    $db->begin_transaction();
    try {
        $q=$db->prepare('SELECT * FROM appointments WHERE id=? FOR UPDATE');
        $q->bind_param('i',$appointmentId); $q->execute(); $appt=$q->get_result()->fetch_assoc(); $q->close();
        if (!$appt) throw new DomainException('Appointment does not exist.');
        if (in_array($appt['status'],['cancelled','rejected','completed'],true)) throw new DomainException('This appointment cannot be confirmed in its current status.');
        $patientId=dcl_get_patient_for_appointment($db,$appt);
        $q=$db->prepare("UPDATE appointments SET patient_id=?,status='confirmed',arrived_at=COALESCE(arrived_at,NOW()),updated_at=NOW() WHERE id=?");
        $q->bind_param('ii',$patientId,$appointmentId); $q->execute(); $q->close();
        if ($dentistId!==null && $dentistId>0) {
            $q=$db->prepare('SELECT id FROM dentists WHERE id=? AND is_active=1');
            $q->bind_param('i',$dentistId); $q->execute();$valid=$q->get_result()->fetch_assoc();$q->close();
            if (!$valid) throw new DomainException('Selected dentist is not active.');
            $q=$db->prepare('UPDATE appointments SET dentist_id=? WHERE id=?');
            $q->bind_param('ii',$dentistId,$appointmentId);$q->execute();$q->close();
        }
        if ($notes!==null && trim($notes)!=='') {
            $q=$db->prepare('UPDATE appointments SET internal_notes=? WHERE id=?');
            $q->bind_param('si',$notes,$appointmentId); $q->execute(); $q->close();
        }
        $db->commit();
        return ['patient_id'=>$patientId,'already_confirmed'=>$appt['status']==='confirmed'];
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
