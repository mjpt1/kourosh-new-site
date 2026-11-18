<?php require __DIR__.'/bootstrap.php';
if(!$db) exit('خطای پایگاه داده');
$oid=(int)($_GET['o']??0); $auth=(string)($_GET['Authority']??'');
$q=$db->prepare('SELECT * FROM orders WHERE id=?'); $q->execute([$oid]); $o=$q->fetch();
if(!$o || $o['authority']!==$auth || $auth===''){ flash('اطلاعات پرداخت نامعتبر است.'); go('account.php?tab=orders'); }
if($o['status']!=='awaiting_payment') go('account.php?tab=order&o='.$oid);
if(($_GET['Status']??'')!=='OK'){ flash('پرداخت انجام نشد یا لغو شد. می‌توانید دوباره پرداخت کنید.'); go('account.php?tab=order&o='.$oid); }
$r = zarinpal('verify',['merchant_id'=>setting('zarinpal_merchant'),'amount'=>(int)$o['total']*10,'authority'=>$auth]);
$code = (int)($r['data']['code'] ?? 0);
if($code===100 || $code===101){
 mark_paid($oid,(string)($r['data']['ref_id']??''));
 go('account.php?tab=order&new=1&o='.$oid);
}
flash('پرداخت تأیید نشد. اگر مبلغ کسر شده، تا ۷۲ ساعت به حساب شما برمی‌گردد.');
go('account.php?tab=order&o='.$oid);
