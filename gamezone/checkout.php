<?php require __DIR__.'/bootstrap.php'; require __DIR__.'/layout.php';
if (!user()) { flash('برای ثبت سفارش ابتدا وارد حساب شوید یا ثبت‌نام کنید.'); go('account.php?back=checkout.php'); }
$error=''; $lock = is_mysql() ? ' FOR UPDATE' : '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf();
 if (!$db) $error='اتصال به پایگاه داده برقرار نیست.';
 elseif (empty($_SESSION['cart'])) $error='سبد خرید خالی است.';
 else {
  try {
   $db->beginTransaction(); $lines=[]; $total=0;
   $q=$db->prepare('SELECT v.*, p.title FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.product_id=? AND v.region=? AND p.active=1'.$lock);
   foreach ($_SESSION['cart'] as $key=>$qty) {
    [$pid,$rg]=cart_split((string)$key); $q->execute([$pid,$rg]); $item=$q->fetch(); $qty=(int)$qty;
    if (!$item||$qty<1||$item['stock']<$qty) throw new RuntimeException('موجودی یکی از محصولات تغییر کرده است.');
    $total+=$item['price']*$qty; $lines[]=[$item,$qty];
   }
   $db->prepare("INSERT INTO orders (user_id,total,status) VALUES (?,?,'awaiting_payment')")->execute([user()['id'],$total]);
   $oid=(int)$db->lastInsertId();
   $line=$db->prepare('INSERT INTO order_items (order_id,product_id,title,region,price,quantity) VALUES (?,?,?,?,?,?)');
   foreach ($lines as [$item,$qty]) $line->execute([$oid,$item['product_id'],$item['title'],$item['region'],$item['price'],$qty]);
   $db->commit(); $_SESSION['cart']=[];
   go('pay.php?o='.$oid);
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $error=$e instanceof RuntimeException?$e->getMessage():'ثبت سفارش ناموفق بود.'; }
 }
}
$cartView=[]; $cartProblem=false; $grandTotal=0;
foreach (products() as $p) foreach ($p['variants'] as $v) { $key=cart_key((int)$p['id'],$v['region']); if(!isset($_SESSION['cart'][$key])) continue; $qty=(int)$_SESSION['cart'][$key];
 if($qty<1||$qty>(int)$v['stock']){$cartProblem=true;continue;}
 $cartView[]=[['title'=>$p['title'],'region'=>$v['region'],'price'=>$v['price']],$qty]; $grandTotal+=(int)$v['price']*$qty; }
if (count($cartView)!==count($_SESSION['cart']??[])) $cartProblem=true;
page_start('تکمیل خرید'); ?>
<div class="pn-narrow">
 <h1>تکمیل خرید</h1>
 <div class="steps"><span class="on">۱. سبد</span><span class="on">۲. تأیید</span><span>۳. پرداخت</span><span>۴. دریافت کد</span></div>
 <?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
 <?php if($cartProblem):?><p class="error">محصول، موجودی یا ریجن سبد تغییر کرده است. به فروشگاه برگردید و سبد را دوباره بررسی کنید.</p><?php endif;?>
 <div class="pn-card">
  <?php if(!$cartView):?><div class="empty">سبد خرید خالی است. <a class="button primary sm" href="index.php#products">رفتن به فروشگاه</a></div><?php endif;?>
  <?php foreach($cartView as [$p,$qty]):?><div class="ord-line"><span><?=h($p['title'])?> <small>ریجن <?=h(region_name((string)$p['region']))?> × <?=fa($qty)?></small></span><b><?=money((int)$p['price']*$qty)?></b></div><?php endforeach;?>
  <div class="ord-total"><span>مبلغ قابل پرداخت</span><b><?=money($grandTotal)?></b></div>
 </div>
 <p class="muted">کدها بلافاصله بعد از پرداخت در «پنل کاربری ← سفارش‌ها» نمایش داده می‌شوند.</p>
 <form method="post"><?=csrf_field()?><button class="button primary wide" <?=(!cart_count()||$cartProblem)?'disabled':''?>>ثبت سفارش و پرداخت ←</button></form>
</div>
<?php page_end();
