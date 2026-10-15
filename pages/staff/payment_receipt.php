<?php
require '../../conn/conn.php';
require_once '../../functions/appointment_patient_search.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!aps_staff_authorized($conn)) {http_response_code(403);exit('Not authorized');}
$code=trim((string)($_GET['receipt']??''));
if (!preg_match('/^PAY-[0-9]{8}-[A-F0-9]{12}$/D',$code)) {http_response_code(400);exit('Invalid reference');}
$st=$conn->prepare('SELECT receipt_snapshot FROM billing_payment_transactions WHERE receipt_number=? LIMIT 1');
$st->bind_param('s',$code);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
if (!$row) {http_response_code(404);exit('Receipt not found');}
$snap=json_decode($row['receipt_snapshot'],true);
if (!is_array($snap)) {http_response_code(500);exit('Receipt snapshot unavailable');}
function esc($s){return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8');}
function peso($s){return '₱'.number_format((float)$s,2);}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= esc($code) ?> · Payment Acknowledgment</title>
<style>body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;color:#142338}main{max-width:650px;margin:32px auto;padding:32px;background:#fff;border-radius:16px;box-shadow:0 8px 26px #0001}h1{margin:0;font-size:21px}h2{font-size:17px;margin:20px 0 12px}.sub{color:#64748b}.teal{color:#0c9387}.line{display:flex;justify-content:space-between;gap:15px;padding:9px 0;border-bottom:1px solid #e2e8f0}.total{font-weight:bold;font-size:18px}button{background:#0d9e8c;color:white;border:0;border-radius:8px;padding:11px 20px;cursor:pointer}.muted{font-size:12px;color:#64748b;margin-top:24px}@media print{body{background:white}main{box-shadow:none;margin:0;max-width:none}button{display:none}}</style></head><body><main>
<div style="text-align:center"><h1><?=esc($snap['clinic'])?></h1><p class="sub">Payment Acknowledgment — Not an Official Tax Receipt</p><h2 class="teal"><?=esc($snap['receipt_number'])?></h2><p class="sub"><?=esc($snap['paid_at'])?></p></div>
<h2>Patient and Invoice</h2>
<div class="line"><span>Patient</span><strong><?=esc($snap['patient_name'])?></strong></div><div class="line"><span>Invoice</span><strong><?=esc($snap['billing_code'])?></strong></div><div class="line"><span>Session</span><strong><?= (int)$snap['session'] ?></strong></div>
<h2>Treatment Summary</h2>
<?php foreach(($snap['services']??[]) as $svc): ?><div class="line"><span><?=esc($svc['title']??'Service')?></span><span><?=peso($svc['total']??($svc['price']??0))?></span></div><?php endforeach;?>
<h2>Payment Details</h2>
<div class="line"><span>Invoice Total</span><span><?=peso($snap['invoice_total'])?></span></div>
<div class="line"><span>Previous Balance</span><span><?=peso($snap['previous_balance'])?></span></div>
<div class="line total"><span>Paid this Session</span><span class="teal"><?=peso($snap['amount'])?></span></div>
<div class="line"><span>Total Paid to Date</span><span><?=peso($snap['paid_total_after'])?></span></div>
<div class="line total"><span>Remaining Balance</span><span><?=peso($snap['balance_after'])?></span></div>
<div class="line"><span>Method</span><span><?=esc(ucwords(str_replace('_',' ',$snap['method'])))?></span></div>
<div class="line"><span>Processed by</span><span><?=esc($snap['processed_by'])?></span></div>
<div class="line"><span>Payment Status</span><strong><?=esc(ucfirst($snap['payment_status']))?></strong></div>
<p class="muted">This is a record of payment received, not a BIR official invoice or official tax receipt. This acknowledgment is a fixed snapshot of this specific payment.</p>
<div style="text-align:center"><button onclick="window.print()">Print / Save as PDF</button></div>
</main></body></html>
