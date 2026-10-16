<?php
/** DentaLink linked treatments: loaded only from authenticated staff patient_services.php. */
function dcs_money($input): int {
    $s=trim((string)$input);
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D',$s)) throw new RuntimeException('Invalid monetary amount.');
    [$w,$f]=array_pad(explode('.',$s,2),2,''); return ((int)$w)*100+(int)str_pad($f,2,'0');
}
function dcs_dec(int $c):string { return sprintf('%d.%02d',intdiv($c,100),$c%100); }
function dcs_one(mysqli $db,string $sql,string $types='',array $params=[]):?array {
    $s=$db->prepare($sql);if($types)$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function dcs_rows(mysqli $db,string $sql,string $types='',array $params=[]):array {
    $s=$db->prepare($sql);if($types)$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r;
}
function dcs_json(array $data,int $status=200):void { http_response_code($status); header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE);exit; }
function dcs_csrf():string { if(empty($_SESSION['dcs_csrf']))$_SESSION['dcs_csrf']=bin2hex(random_bytes(32)); return $_SESSION['dcs_csrf']; }
function dcs_save_bill(mysqli $db,int $psId,?int $apptId,array $patient,array $service,int $totalCents):array {
    $bill=dcs_one($db,'SELECT * FROM billing WHERE patient_service_id=? LIMIT 1 FOR UPDATE','i',[$psId]);
    if (!$bill && $apptId) {
        $all=dcs_rows($db,'SELECT * FROM billing WHERE appointment_id=? FOR UPDATE','i',[$apptId]);
        if(count($all)>1)throw new RuntimeException('Multiple existing invoices for this appointment require reconciliation.');
        $bill=$all[0]??null;
        if($bill && $bill['patient_service_id']!==null && (int)$bill['patient_service_id']!==$psId)
            throw new RuntimeException('Appointment invoice is already linked to a different treatment; review in Billing.');
    }
    $json=json_encode([['id'=>$service['id'],'title'=>$service['title'],'price'=>dcs_dec($totalCents),'quantity'=>1,'total'=>dcs_dec($totalCents),'patient_service_id'=>$psId]],JSON_THROW_ON_ERROR);
    $newTotal=dcs_dec($totalCents);
    if($bill) {
        // Existing invoices with payments or no verifiable payment provenance are immutable.
        $ledger=dcs_one($db,'SELECT COUNT(*) AS c FROM billing_payment_transactions WHERE billing_id=?','i',[(int)$bill['id']]);
        $hasPayments=(int)($ledger['c']??0)>0;
        $oldTotal=dcs_money((string)$bill['total_amount']);
        $existingLines=json_decode((string)$bill['services'],true);
        if (is_array($existingLines) && count($existingLines)>1 && $oldTotal!==$totalCents)
            throw new RuntimeException('This appointment invoice covers multiple procedures. Review it in Billing; its other charges cannot be replaced by one treatment.');
        $paidKnown=$bill['amount_paid']!==null && dcs_money((string)$bill['amount_paid'])>0;
        if(($hasPayments||$paidKnown||in_array($bill['status'],['paid','partial','refunded'],true)) && $oldTotal!==$totalCents)
            throw new RuntimeException('Invoice already has payment history or is closed. Cannot reprice; reconcile in Billing.');
        if($bill['status']==='unpaid' && !$hasPayments && !$paidKnown) {
            // Preserve existing invoice adjustments. Only update if no discount is present.
            if(dcs_money((string)$bill['discount'])>0 && $oldTotal!==$totalCents)
                throw new RuntimeException('Invoice has a discount; review changes in Billing before repricing.');
            $st=$db->prepare('UPDATE billing SET patient_service_id=?,services=?,subtotal=?,total_amount=?,amount_paid=0,remaining_balance=? WHERE id=? AND status=\'unpaid\'');
            $st->bind_param('issssi',$psId,$json,$newTotal,$newTotal,$newTotal,$bill['id']);$st->execute();$st->close();
        } else if ($bill['patient_service_id']===null) {
            $st=$db->prepare('UPDATE billing SET patient_service_id=? WHERE id=?');$st->bind_param('ii',$psId,$bill['id']);$st->execute();$st->close();
        }
        return ['id'=>(int)$bill['id'],'code'=>$bill['billing_code'],'status'=>$bill['status']];
    }
    $code='BILL-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
    $name=$patient['full_name'];$phone=$patient['phone']??'';$email=$patient['email']??'';
    $st=$db->prepare("INSERT INTO billing (billing_code,appointment_id,patient_service_id,patient_name,patient_phone,patient_email,services,subtotal,total_amount,amount_paid,remaining_balance,status) VALUES (?,?,?,?,?,?,?,?,?,0,?,'unpaid')");
    $st->bind_param('siisssssss',$code,$apptId,$psId,$name,$phone,$email,$json,$newTotal,$newTotal,$newTotal);
    $st->execute();$id=$db->insert_id;$st->close();
    return ['id'=>(int)$id,'code'=>$code,'status'=>'unpaid'];
}
function dcs_recalc(mysqli $db,int $psId):void {
    $counts=dcs_one($db,"SELECT COUNT(*) AS total,SUM(status='completed') AS done FROM service_sessions WHERE patient_service_id=?",'i',[$psId]);
    $total=(int)$counts['total'];$done=(int)$counts['done'];$state=$done===$total && $total>0?'completed':($done>0?'in-progress':'pending');
    $s=$db->prepare('UPDATE patient_services SET total_sessions=?,completed_sessions=?,status=? WHERE id=?');
    $s->bind_param('iisi',$total,$done,$state,$psId);$s->execute();$s->close();
}
/** Called from the page's AJAX handler. Response is JSON and stops processing. */
function dcs_handle(mysqli $db):void {
    $action=$_POST['action']??'';
    if($action==='connected_lookup') {
        $q=trim((string)($_POST['q']??''));if(mb_strlen($q)<2)dcs_json(['success'=>true,'appointments'=>[],'patients'=>[]]);
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
        $appts=dcs_rows($db,"SELECT a.id,a.patient_id,a.full_name,a.appointment_date,a.appointment_time,a.dentist_id,a.status FROM appointments a WHERE a.status='completed' AND (a.full_name LIKE ? OR CAST(a.id AS CHAR) LIKE ?) ORDER BY a.appointment_date DESC LIMIT 15",'ss',[$like,$like]);
        foreach($appts as &$a) {
            $a['procedures']=dcs_rows($db,'SELECT ap.id,ap.service_id,ap.procedure_name,ap.charge,ap.dentist_id FROM appointment_procedures ap WHERE ap.appointment_id=? AND ap.service_id IS NOT NULL AND COALESCE(ap.is_removed,0)=0 ORDER BY ap.id','i',[(int)$a['id']]);
        }unset($a);
        $patients=dcs_rows($db,"SELECT id,patient_id,full_name FROM patients WHERE status='active' AND (full_name LIKE ? OR patient_id LIKE ?) ORDER BY full_name LIMIT 15",'ss',[$like,$like]);
        dcs_json(['success'=>true,'appointments'=>$appts,'patients'=>$patients]);
    }
    if(!in_array($action,['connected_create','connected_add_session','connected_update_charges'],true))dcs_json(['success'=>false,'message'=>'Invalid action'],400);
    if(!hash_equals(dcs_csrf(),(string)($_POST['csrf']??'')))dcs_json(['success'=>false,'message'=>'Session expired. Refresh this page.'],403);
    $db->begin_transaction();
    try {
        if($action==='connected_create') {
            $appointmentId=(int)($_POST['appointment_id']??0);$patientId=(int)($_POST['patient_id']??0);
            $serviceId=(int)($_POST['service_id']??0);$dentistId=(int)($_POST['dentist_id']??0);
            $n=(int)($_POST['sessions']??0);if($n<1||$n>60)throw new RuntimeException('Choose 1–60 sessions.');
            $split=($_POST['split']??'0')==='1';
            $svc=dcs_one($db,'SELECT id,title,price FROM services WHERE id=? AND is_active=1','i',[$serviceId]);
            $dent=dcs_one($db,'SELECT id FROM dentists WHERE id=? AND is_active=1','i',[$dentistId]);
            if(!$svc||!$dent)throw new RuntimeException('Select an active service and dentist.');
            $appt=null;$proc=null;
            if($appointmentId>0) {
                $appt=dcs_one($db,'SELECT * FROM appointments WHERE id=? FOR UPDATE','i',[$appointmentId]);
                if(!$appt||$appt['status']!=='completed')throw new RuntimeException('Choose a completed appointment.');
                if(empty($appt['patient_id']))throw new RuntimeException('Appointment needs a verified patient record link before importing.');
                $patientId=(int)$appt['patient_id'];
                $proc=dcs_one($db,'SELECT id,service_id,procedure_name,charge FROM appointment_procedures WHERE appointment_id=? AND service_id=? AND COALESCE(is_removed,0)=0 ORDER BY id LIMIT 1','ii',[$appointmentId,$serviceId]);
                if(!$proc)throw new RuntimeException('This service was not recorded as a performed procedure in the selected appointment.');
                $duplicate=dcs_one($db,'SELECT id FROM patient_services WHERE appointment_id=? AND service_id=? LIMIT 1 FOR UPDATE','ii',[$appointmentId,$serviceId]);
                if($duplicate)throw new RuntimeException('This completed appointment service is already imported. Open its existing record.');
            }
            $patient=dcs_one($db,"SELECT id,full_name,phone,email FROM patients WHERE id=? AND status='active'",'i',[$patientId]);
            if(!$patient)throw new RuntimeException('Select a valid active patient.');
            // The completed appointment's actual procedure charge wins over catalog price.
            $base=dcs_money((string)($proc['charge']??$svc['price']));
            $entered=(string)($_POST['total']??dcs_dec($base));$agreed=dcs_money($entered);
            if($agreed>999999999)throw new RuntimeException('Amount is too large.');
            $amounts=[];
            if($split) {
                $parts=json_decode((string)($_POST['amounts']??'[]'),true);
                if(!is_array($parts)||count($parts)!==$n)throw new RuntimeException('Enter the amount for each session.');
                foreach($parts as $v)$amounts[]=dcs_money((string)$v);
                if(array_sum($amounts)!==$agreed)throw new RuntimeException('Session amounts must add up to the treatment total.');
            } else $amounts=array_merge([$agreed],array_fill(0,$n-1,0));
            $mode=$split?'per_session':'package';
            $st=$db->prepare('INSERT INTO patient_services (patient_id,service_id,dentist_id,appointment_id,total_sessions,completed_sessions,status,pricing_mode,agreed_total,notes) VALUES (?,?,?,?,?,0,?,?,?,\'\')');
            $initialStatus=$appt?'in-progress':'pending';$price=dcs_dec($agreed);
            $st->bind_param('iiiiisss',$patientId,$serviceId,$dentistId,$appointmentId,$n,$initialStatus,$mode,$price);$st->execute();$psId=$db->insert_id;$st->close();
            for($i=0;$i<$n;$i++) {
                $number=$i+1;$linked=$i===0&&$appt?$appointmentId:null;
                $date=$i===0&&$appt?$appt['appointment_date']:null;
                $time=$i===0&&$appt?$appt['appointment_time']:null;
                $status=$i===0&&$appt?'completed':'scheduled';$charge=dcs_dec($amounts[$i]);$locked=$linked?1:0;
                $st=$db->prepare('INSERT INTO service_sessions (patient_service_id,session_number,appointment_id,date,time,status,session_charge,charge_locked) VALUES (?,?,?,?,?,?,?,?)');
                $st->bind_param('iiissssi',$psId,$number,$linked,$date,$time,$status,$charge,$locked);$st->execute();$st->close();
            }
            dcs_recalc($db,$psId);
            $bill=dcs_save_bill($db,$psId,$appointmentId,$patient,$svc,$agreed);
        } else {
            $psId=(int)($_POST['patient_service_id']??0);
            $ps=dcs_one($db,'SELECT * FROM patient_services WHERE id=? FOR UPDATE','i',[$psId]);
            if(!$ps)throw new RuntimeException('Treatment not found.');
            $patient=dcs_one($db,'SELECT id,full_name,phone,email FROM patients WHERE id=?','i',[(int)$ps['patient_id']]);
            $svc=dcs_one($db,'SELECT id,title FROM services WHERE id=?','i',[(int)$ps['service_id']]);
            if(!$patient||!$svc)throw new RuntimeException('Missing patient/service.');
            if($action==='connected_add_session') {
                $charge=dcs_money((string)($_POST['charge']??'0'));
                $date=(string)($_POST['date']??'');
                if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new RuntimeException('Invalid session date.');
                $date=$date===''?null:$date;
                $count=dcs_one($db,'SELECT COALESCE(MAX(session_number),0) AS last FROM service_sessions WHERE patient_service_id=?','i',[$psId]);
                $seq=(int)$count['last']+1;$str=dcs_dec($charge);
                $st=$db->prepare("INSERT INTO service_sessions (patient_service_id,session_number,date,status,session_charge) VALUES (?,?,?,'scheduled',?)");
                $st->bind_param('iiss',$psId,$seq,$date,$str);$st->execute();$st->close();
            } else {
                $rows=dcs_rows($db,'SELECT * FROM service_sessions WHERE patient_service_id=? ORDER BY session_number FOR UPDATE','i',[$psId]);
                $parts=json_decode((string)($_POST['amounts']??'[]'),true);
                if(!is_array($parts)||count($parts)!==count($rows))throw new RuntimeException('Incomplete session price list.');
                $amounts=[];
                foreach($parts as $k=>$v) {
                    $c=dcs_money((string)$v);
                    if((int)$rows[$k]['charge_locked']===1 && dcs_money((string)($rows[$k]['session_charge']??'0'))!==$c)
                        throw new RuntimeException('A completed appointment session is locked. Adjust its original visit charge only through an authorized reconciliation.');
                    $amounts[]=$c;
                    $dec=dcs_dec($c);$sid=(int)$rows[$k]['id'];
                    $st=$db->prepare('UPDATE service_sessions SET session_charge=? WHERE id=?');$st->bind_param('si',$dec,$sid);$st->execute();$st->close();
                }
            }
            $sessions=dcs_rows($db,'SELECT session_charge FROM service_sessions WHERE patient_service_id=?','i',[$psId]);
            $total=0;foreach($sessions as $s)$total+=dcs_money((string)($s['session_charge']??'0'));
            $mode='per_session';$decimal=dcs_dec($total);
            $st=$db->prepare('UPDATE patient_services SET pricing_mode=?,agreed_total=? WHERE id=?');$st->bind_param('ssi',$mode,$decimal,$psId);$st->execute();$st->close();
            dcs_recalc($db,$psId);
            $bill=dcs_save_bill($db,$psId,$ps['appointment_id']?(int)$ps['appointment_id']:null,$patient,$svc,$total);
        }
        $db->commit();
        dcs_json(['success'=>true,'message'=>'Treatment sessions and invoice synchronized.','patient_service_id'=>$psId,'billing'=>$bill]);
    } catch(Throwable $e) {
        $db->rollback();error_log('DentaLink session sync: '.$e->getMessage());dcs_json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Database update failed. Please contact administrator.'],422);
    }
}
