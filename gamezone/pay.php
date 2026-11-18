<?php require __DIR__.'/bootstrap.php'; require __DIR__.'/layout.php';
$u=user(); if(!$u||!$db) go('account.php');
$q=$db->prepare("SELECT * FROM orders WHERE id=? AND user_id=?"); $q->execute([(int)($_GET['o']??$_POST['o']??0),$u['id']]); $o=$q->fetch();
if(!$o){ flash('سفارش پیدا نشد.'); go('account.php?tab=orders'); }
if($o['status']!=='awaiting_payment') go('account.php?tab=order&o='.$o['id']);
$merchant = setting('zarinpal_merchant');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 check_csrf();
 if(($_POST['mode']??'')==='demo' && admin()){ // پرداخت آزمایشی فقط برای مدیر
  mark_paid((int)$o['id'],'TEST-'.time()); go('account.php?tab=order&new=1&o='.$o['id']);
 }
 if($merchant){
  $r = zarinpal('request',['merchant_id'=>$merchant,'amount'=>(int)$o['total']*10,'callback_url'=>base_url().'verify.php?o='.$o['id'],'description'=>'سفارش #'.$o['id'].' گیم‌زون','metadata'=>array_filter(['email'=>$u['email'],'mobile'=>$u['phone']??''])]);
  if(($r['data']['code']??0)==100){ $db->prepare('UPDATE orders SET authority=? WHERE id=?')->execute([$r['data']['authority'],$o['id']]); go('https://payment.zarinpal.com/pg/StartPay/'.$r['data']['authority']); }
  $error='اتصال به درگاه برقرار نشد. چند لحظه دیگر دوباره امتحان کنید.';
 }
}
page_start('پرداخت سفارش'); ?>
<div class="pn-narrow">
 <h1>پرداخت سفارش #<?=fa($o['id'])?></h1>
 <div class="steps"><span class="on">۱. سبد</span><span class="on">۲. تأیید</span><span class="on">۳. پرداخت</span><span>۴. دریافت کد</span></div>
 <?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
 <div class="pn-card"><div class="ord-total"><span>مبلغ</span><b><?=money($o['total'])?></b></div></div>
 <?php if($merchant):?>
  <form method="post"><?=csrf_field()?><input type="hidden" name="o" value="<?=$o['id']?>"><button class="button primary wide">پرداخت آنلاین با زرین‌پال ←</button></form>
 <?php else:?>
  <p class="notice">درگاه پرداخت هنوز فعال نشده است. سفارش شما ثبت شد؛ برای پرداخت با پشتیبانی هماهنگ کنید<?=setting('support_telegram')?' (تلگرام: '.h(setting('support_telegram')).')':''?>. بعد از تأیید پرداخت، کد در پنل شما قرار می‌گیرد.</p>
 <?php endif;?>
 <?php if(admin()):?><form method="post"><?=csrf_field()?><input type="hidden" name="o" value="<?=$o['id']?>"><input type="hidden" name="mode" value="demo"><button class="button wide">پرداخت آزمایشی (فقط مدیر)</button></form><?php endif;?>
 <p><a href="account.php?tab=orders">← سفارش‌های من</a></p>
</div>
<?php page_end();
