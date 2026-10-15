<?php
// DentaLink payment ledger. Include only after authenticated staff session and DB connection.
function dlp_money_cents($value): int {
    $s=trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $s)) throw new RuntimeException('Enter a valid amount with up to two decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$s,2),2,'');
    return (int)$whole * 100 + (int)str_pad($fraction,2,'0');
}
function dlp_decimal(int $cents): string { return sprintf('%d.%02d',intdiv($cents,100),$cents%100); }
function dlp_pay(mysqli $conn,int $billingId,string $amount,string $method,string $requestKey,string $processedBy): array {
    $allowed=['cash','credit_card','bank_transfer','gcash','paymaya','insurance','other'];
    if ($billingId <= 0 || !in_array($method,$allowed,true) || !preg_match('/^[a-f0-9]{32}$/D',$requestKey)) throw new RuntimeException('Invalid payment request.');
    $cents=dlp_money_cents($amount);
    if ($cents<=0) throw new RuntimeException('Payment must be greater than zero.');
    $conn->begin_transaction();
    try {
        $st=$conn->prepare('SELECT * FROM billing WHERE id=? FOR UPDATE');
        $st->bind_param('i',$billingId);$st->execute();$bill=$st->get_result()->fetch_assoc();$st->close();
        if (!$bill) throw new RuntimeException('Invoice not found.');
        $dup=$conn->prepare('SELECT receipt_number,billing_id FROM billing_payment_transactions WHERE request_key=? LIMIT 1');
        $dup->bind_param('s',$requestKey);$dup->execute();$duplicate=$dup->get_result()->fetch_assoc();$dup->close();
        if ($duplicate && (int)$duplicate['billing_id'] !== $billingId) throw new RuntimeException('Duplicate request key belongs to another invoice.');
        if ($duplicate) { $conn->commit(); return ['duplicate'=>true,'receipt_number'=>$duplicate['receipt_number']]; }
        // Completion workflow may insert an unpaid bill without the ledger columns.
        if ($bill['status']==='unpaid' && $bill['amount_paid']===null && $bill['remaining_balance']===null) {
            $fix=$conn->prepare("UPDATE billing SET amount_paid=0,remaining_balance=total_amount WHERE id=? AND status='unpaid'");
            $fix->bind_param('i',$billingId);$fix->execute();$fix->close();
            $bill['amount_paid']='0.00';$bill['remaining_balance']=$bill['total_amount'];
        }
        if (!in_array($bill['status'],['unpaid','partial'],true) || $bill['amount_paid']===null || $bill['remaining_balance']===null)
            throw new RuntimeException('This invoice is closed or has legacy payment records that must be reconciled first.');
        $total=dlp_money_cents((string)$bill['total_amount']);
        $paid=dlp_money_cents((string)$bill['amount_paid']);
        $previous=$total-$paid;
        if ($previous<=0) throw new RuntimeException('This invoice is already fully paid.');
        if ($cents>$previous) throw new RuntimeException('Amount exceeds the remaining balance.');
        $n=$conn->prepare('SELECT COALESCE(MAX(session_number),0)+1 AS next_session FROM billing_payment_transactions WHERE billing_id=?');
        $n->bind_param('i',$billingId);$n->execute();$session=(int)$n->get_result()->fetch_assoc()['next_session'];$n->close();
        $newPaid=$paid+$cents; $after=$total-$newPaid;
        $receipt='PAY-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
        $now=date('Y-m-d H:i:s');
        $snapshot=[
          'clinic'=>'Wansi Calacat Dental Clinic','type'=>'Payment Acknowledgment',
          'receipt_number'=>$receipt,'billing_code'=>$bill['billing_code'],
          'patient_name'=>$bill['patient_name'],'services'=>json_decode($bill['services'],true) ?: [],
          'invoice_total'=>dlp_decimal($total),'session'=>$session,
          'previous_balance'=>dlp_decimal($previous),'amount'=>dlp_decimal($cents),
          'paid_total_after'=>dlp_decimal($newPaid),'balance_after'=>dlp_decimal($after),
          'method'=>$method,'processed_by'=>$processedBy,'paid_at'=>$now,
          'payment_status'=>$after===0?'paid':'partial'
        ];
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        $p=dlp_decimal($cents);$prev=dlp_decimal($previous);$balance=dlp_decimal($after);$new=dlp_decimal($newPaid);
        $st=$conn->prepare('INSERT INTO billing_payment_transactions (billing_id,receipt_number,request_key,session_number,amount,previous_balance,balance_after,paid_total_after,method,processed_by,paid_at,receipt_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->bind_param('ississssssss',$billingId,$receipt,$requestKey,$session,$p,$prev,$balance,$new,$method,$processedBy,$now,$json);$st->execute();$st->close();
        $status=$after===0?'paid':'partial';
        $st=$conn->prepare('UPDATE billing SET amount_paid=?,remaining_balance=?,status=?,paid_at=CASE WHEN ? = \'paid\' THEN ? ELSE NULL END WHERE id=?');
        $st->bind_param('sssssi',$new,$balance,$status,$status,$now,$billingId);$st->execute();$st->close();
        $conn->commit();
        return ['duplicate'=>false,'receipt_number'=>$receipt,'amount_paid'=>$new,'balance'=>$balance,'status'=>$status];
    } catch(Throwable $e) { $conn->rollback(); throw $e; }
}
