<?php require __DIR__.'/bootstrap.php'; require __DIR__.'/layout.php';
$error=''; $u=user();
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');
if (!preg_match('~^[a-z]+\.php(\?[\w=&%-]*)?$~', $back)) $back = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf(); $action=$_POST['action']??'';
 if ($action==='logout') { unset($_SESSION['user_id'],$_SESSION['admin']); session_regenerate_id(true); go('account.php'); }
 if (!$db) $error='اتصال به پایگاه داده برقرار نیست.';
 elseif (!$u && in_array($action,['login','register'],true)) {
  $email=strtolower(trim((string)($_POST['email']??''))); $pass=(string)($_POST['password']??''); $name=trim((string)($_POST['name']??''));
  $tries = $_SESSION['login_tries'] ?? 0;
  if ($tries >= 8 && time() - ($_SESSION['login_last'] ?? 0) < 300) $error='تلاش‌های زیادی انجام شد. ۵ دقیقه دیگر دوباره امتحان کنید.';
  elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($pass)<8||strlen($pass)>72) $error='ایمیل معتبر و رمز عبور ۸ تا ۷۲ کاراکتری وارد کنید.';
  elseif ($action==='register') {
   $phone = preg_replace('/\D/','', strtr((string)($_POST['phone']??''),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
   if (text_len($name)<2||text_len($name)>100) $error='نام باید بین ۲ تا ۱۰۰ نویسه باشد.';
   elseif ($phone!=='' && !preg_match('/^09\d{9}$/',$phone)) $error='شماره موبایل باید مثل ۰۹۱۲۱۲۳۴۵۶۷ باشد.';
   else { try { $q=$db->prepare('INSERT INTO users (name,email,password,phone) VALUES (?,?,?,?)'); $q->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$phone?:null]); session_regenerate_id(true); $_SESSION['user_id']=(int)$db->lastInsertId(); flash('خوش آمدید! حساب شما ساخته شد.'); go($back ?: 'account.php'); } catch (PDOException $e) { $error='این ایمیل قبلاً ثبت شده است. وارد شوید.'; } }
  } else {
   $q=$db->prepare('SELECT id,password,role,blocked FROM users WHERE email=?'); $q->execute([$email]); $row=$q->fetch();
   if ($row && password_verify($pass,$row['password'])) {
    if (!empty($row['blocked'])) $error='حساب شما مسدود شده است. با پشتیبانی تماس بگیرید.';
    else { session_regenerate_id(true); $_SESSION['user_id']=(int)$row['id']; $_SESSION['admin']=$row['role']==='admin'; unset($_SESSION['login_tries']); $db->prepare('UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=?')->execute([$row['id']]); go($back ?: 'account.php'); }
   } else { $_SESSION['login_tries']=$tries+1; $_SESSION['login_last']=time(); $error='ایمیل یا رمز عبور نادرست است.'; }
  }
 }
 elseif ($u) {
  if ($action==='profile') {
   $name=trim((string)($_POST['name']??'')); $phone=preg_replace('/\D/','',(string)($_POST['phone']??''));
   if (text_len($name)<2||text_len($name)>100) $error='نام باید بین ۲ تا ۱۰۰ نویسه باشد.';
   elseif ($phone!=='' && !preg_match('/^09\d{9}$/',$phone)) $error='شماره موبایل نامعتبر است.';
   else { $db->prepare('UPDATE users SET name=?,phone=? WHERE id=?')->execute([$name,$phone?:null,$u['id']]); flash('اطلاعات حساب ذخیره شد.'); go('account.php?tab=profile'); }
  }
  if ($action==='password') {
   $q=$db->prepare('SELECT password FROM users WHERE id=?'); $q->execute([$u['id']]);
   $new=(string)($_POST['new']??'');
   if (!password_verify((string)($_POST['old']??''),(string)$q->fetchColumn())) $error='رمز فعلی درست نیست.';
   elseif (strlen($new)<8||strlen($new)>72||$new!==(string)($_POST['new2']??'')) $error='رمز جدید باید ۸ تا ۷۲ کاراکتر و با تکرارش یکسان باشد.';
   else { $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]); flash('رمز عبور عوض شد.'); go('account.php?tab=profile'); }
  }
  if ($action==='ticket') {
   $subject=trim((string)($_POST['subject']??'')); $body=trim((string)($_POST['body']??'')); $oid=(int)($_POST['order_id']??0);
   if (text_len($subject)<3||text_len($subject)>200||text_len($body)<3||text_len($body)>5000) $error='موضوع و متن پیام را کامل بنویسید.';
   else {
    if ($oid) { $c=$db->prepare('SELECT id FROM orders WHERE id=? AND user_id=?'); $c->execute([$oid,$u['id']]); if(!$c->fetch()) $oid=0; }
    $db->prepare("INSERT INTO tickets (user_id,order_id,subject,status,updated_at) VALUES (?,?,?,'open',CURRENT_TIMESTAMP)")->execute([$u['id'],$oid?:null,$subject]);
    $tid=(int)$db->lastInsertId(); $db->prepare('INSERT INTO ticket_msgs (ticket_id,from_admin,body) VALUES (?,0,?)')->execute([$tid,$body]);
    flash('تیکت شما ثبت شد. به‌زودی پاسخ می‌دهیم.'); go('account.php?tab=ticket&t='.$tid);
   }
  }
  if ($action==='reply') {
   $tid=(int)($_POST['t']??0); $body=trim((string)($_POST['body']??''));
   $c=$db->prepare('SELECT id FROM tickets WHERE id=? AND user_id=?'); $c->execute([$tid,$u['id']]);
   if ($c->fetch() && text_len($body)>=2 && text_len($body)<=5000) {
    $db->prepare('INSERT INTO ticket_msgs (ticket_id,from_admin,body) VALUES (?,0,?)')->execute([$tid,$body]);
    $db->prepare("UPDATE tickets SET status='open',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$tid]);
   }
   go('account.php?tab=ticket&t='.$tid);
  }
 }
}

/* ---------- مهمان: ورود / ثبت‌نام ---------- */
if (!$u) {
 page_start('ورود و ثبت‌نام'); $mode = ($_POST['action'] ?? $_GET['mode'] ?? 'login')==='register' ? 'register' : 'login'; ?>
 <div class="auth">
  <div class="auth-art"><span class="brand-icon">✣</span><h1>به <em>گیم‌زون</em> خوش اومدی</h1><p>با یک حساب، همه خریدها و کدهات همیشه در دسترسته.</p><ul><li>⚡ دریافت کد بلافاصله بعد از پرداخت</li><li>◇ تاریخچه کامل سفارش‌ها</li><li>♧ پشتیبانی با تیکت</li></ul></div>
  <div class="auth-box">
   <div class="auth-tabs"><a href="?mode=login<?=$back?'&back='.urlencode($back):''?>" class="<?=$mode==='login'?'on':''?>">ورود</a><a href="?mode=register<?=$back?'&back='.urlencode($back):''?>" class="<?=$mode==='register'?'on':''?>">ثبت‌نام</a></div>
   <?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
   <?php if($mode==='login'):?>
   <form method="post" class="pn-form"><?=csrf_field()?><input type="hidden" name="action" value="login"><input type="hidden" name="back" value="<?=h($back)?>">
    <label>ایمیل<input required type="email" name="email" dir="ltr" autocomplete="email" value="<?=h($_POST['email']??'')?>"></label>
    <label>رمز عبور<input required type="password" name="password" minlength="8" maxlength="72" dir="ltr" autocomplete="current-password"></label>
    <button class="button primary wide">ورود به حساب ←</button>
    <p class="muted">حساب نداری؟ <a href="?mode=register<?=$back?'&back='.urlencode($back):''?>">ثبت‌نام کن</a></p>
   </form>
   <?php else:?>
   <form method="post" class="pn-form"><?=csrf_field()?><input type="hidden" name="action" value="register"><input type="hidden" name="back" value="<?=h($back)?>">
    <label>نام و نام خانوادگی<input required name="name" maxlength="100" autocomplete="name" value="<?=h($_POST['name']??'')?>"></label>
    <label>ایمیل<input required type="email" name="email" dir="ltr" autocomplete="email" value="<?=h($_POST['email']??'')?>"></label>
    <label>موبایل (اختیاری)<input name="phone" inputmode="numeric" dir="ltr" placeholder="09121234567" value="<?=h($_POST['phone']??'')?>"></label>
    <label>رمز عبور<input required type="password" name="password" minlength="8" maxlength="72" dir="ltr" autocomplete="new-password"></label>
    <button class="button primary wide">ساخت حساب ←</button>
   </form>
   <?php endif;?>
  </div>
 </div>
 <?php page_end(); exit;
}

/* ---------- پنل مشتری ---------- */
$tab = (string)($_GET['tab'] ?? 'dash');
$uid = (int)$u['id'];
$st=$db->prepare("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN status IN ('paid','processing','delivered') THEN total ELSE 0 END),0) s, SUM(CASE WHEN status='awaiting_payment' THEN 1 ELSE 0 END) w, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) d FROM orders WHERE user_id=?"); $st->execute([$uid]); $stats=$st->fetch();
$openT=$db->prepare("SELECT COUNT(*) FROM tickets WHERE user_id=? AND status='answered'"); $openT->execute([$uid]); $answered=(int)$openT->fetchColumn();
$lines=$db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');

function order_card(array $o, PDOStatement $lines, bool $full=false): void {
 $lines->execute([$o['id']]); $items=$lines->fetchAll(); ?>
 <article class="ord">
  <header><div><b>سفارش #<?=fa($o['id'])?></b><small><?=jdate_str($o['created_at'])?></small></div><?=status_badge($o['status'])?></header>
  <?php foreach($items as $it): $codes=array_values(array_filter(preg_split('/\R/',(string)$it['codes']))); ?>
   <div class="ord-line"><span><?=h($it['title'])?> <small>ریجن <?=h(region_name((string)$it['region']))?> × <?=fa($it['quantity'])?></small></span><b><?=money($it['price']*$it['quantity'])?></b></div>
   <?php foreach($codes as $c):?><div class="code-box"><code><?=h($c)?></code><button type="button" class="mini" data-reveal>نمایش کد</button><button type="button" class="mini" data-copy="<?=h($c)?>">کپی</button></div><?php endforeach;?>
  <?php endforeach;?>
  <?php if(!empty($o['admin_note'])):?><p class="ord-note">✉ پیام فروشگاه: <?=nl2br(h($o['admin_note']))?></p><?php endif;?>
  <?php if($o['status']==='processing'):?><p class="muted">پرداخت شما ثبت شد. کد به‌زودی توسط پشتیبانی همین‌جا قرار می‌گیرد.</p><?php endif;?>
  <footer><span>جمع: <b><?=money($o['total'])?></b></span><span class="ord-act"><?php if($o['status']==='awaiting_payment'):?><a class="button primary sm" href="pay.php?o=<?=(int)$o['id']?>">پرداخت</a><?php endif;?><?php if(!$full):?><a class="button sm" href="account.php?tab=order&o=<?=(int)$o['id']?>">جزئیات</a><?php endif;?><a class="button sm" href="account.php?tab=tickets&new=1&o=<?=(int)$o['id']?>">پشتیبانی</a></span></footer>
 </article>
<?php }

page_start('پنل کاربری');
$level = 1 + intdiv((int)$stats['s'], 2000000); $xp = (int)(((int)$stats['s'] % 2000000) / 20000);
?>
<div class="pn">
 <aside class="pn-side">
  <div class="pn-user"><span class="avatar"><?=h(mb_substr($u['name'],0,1,'UTF-8'))?></span><div><b><?=h($u['name'])?></b><small>LEVEL <?=$level?> · گیمر</small><i class="xp"><i style="width:<?=$xp?>%"></i></i></div></div>
  <nav>
   <a class="<?=$tab==='dash'?'on':''?>" href="account.php">◈ داشبورد</a>
   <a class="<?=in_array($tab,['orders','order'],true)?'on':''?>" href="account.php?tab=orders">▤ سفارش‌ها و کدها</a>
   <a class="<?=in_array($tab,['tickets','ticket'],true)?'on':''?>" href="account.php?tab=tickets">✉ پشتیبانی <?php if($answered):?><em class="dot"><?=fa($answered)?></em><?php endif;?></a>
   <a class="<?=$tab==='profile'?'on':''?>" href="account.php?tab=profile">♙ حساب کاربری</a>
   <?php if(admin()):?><a href="admin/">⚙ پنل مدیریت</a><?php endif;?>
  </nav>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button class="pn-logout">⏻ خروج</button></form>
 </aside>
 <section class="pn-main">
 <?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>

 <?php if($tab==='dash'): $q=$db->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 3'); $q->execute([$uid]); $recent=$q->fetchAll(); ?>
  <h1>سلام <?=h($u['name'])?> 👾</h1>
  <div class="kpis">
   <div><small>کل سفارش‌ها</small><b><?=fa((int)$stats['c'])?></b></div>
   <div><small>تحویل شده</small><b><?=fa((int)$stats['d'])?></b></div>
   <div><small>در انتظار پرداخت</small><b><?=fa((int)$stats['w'])?></b></div>
   <div><small>مجموع خرید</small><b><?=money($stats['s'])?></b></div>
  </div>
  <div class="pn-head"><h2>آخرین سفارش‌ها</h2><a href="account.php?tab=orders">همه ←</a></div>
  <?php if(!$recent):?><div class="empty">هنوز خریدی نداری. <a class="button primary sm" href="index.php#products">برو به فروشگاه</a></div><?php endif;?>
  <?php foreach($recent as $o) order_card($o,$lines);?>

 <?php elseif($tab==='orders'): $f=(string)($_GET['f']??''); $sql='SELECT * FROM orders WHERE user_id=?'; $args=[$uid]; if(isset(statuses()[$f])){$sql.=' AND status=?';$args[]=$f;} $q=$db->prepare($sql.' ORDER BY id DESC'); $q->execute($args); $list=$q->fetchAll(); ?>
  <h1>سفارش‌ها و کدها</h1>
  <div class="chips"><a class="<?=$f===''?'on':''?>" href="account.php?tab=orders">همه</a><?php foreach(['awaiting_payment','processing','delivered','canceled'] as $s):?><a class="<?=$f===$s?'on':''?>" href="account.php?tab=orders&f=<?=$s?>"><?=status_label($s)?></a><?php endforeach;?></div>
  <?php if(!$list):?><div class="empty">سفارشی پیدا نشد.</div><?php endif;?>
  <?php foreach($list as $o) order_card($o,$lines);?>

 <?php elseif($tab==='order'): $q=$db->prepare('SELECT * FROM orders WHERE id=? AND user_id=?'); $q->execute([(int)($_GET['o']??0),$uid]); $o=$q->fetch(); ?>
  <div class="pn-head"><h1>جزئیات سفارش</h1><a href="account.php?tab=orders">← بازگشت</a></div>
  <?php if(!$o):?><div class="empty">سفارش پیدا نشد.</div><?php else: if(!empty($_GET['new'])):?><p class="notice">🎉 پرداخت موفق بود. کدهای شما پایین همین صفحه است.</p><?php endif; order_card($o,$lines,true); ?>
  <div class="pn-card"><p>کد پیگیری پرداخت: <b dir="ltr"><?=h($o['ref_id']?:'—')?></b></p><p>زمان پرداخت: <?=jdate_str($o['paid_at'])?></p></div><?php endif;?>

 <?php elseif($tab==='tickets'): $q=$db->prepare('SELECT * FROM tickets WHERE user_id=? ORDER BY COALESCE(updated_at,created_at) DESC'); $q->execute([$uid]); $ts=$q->fetchAll(); $q=$db->prepare('SELECT id FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 30'); $q->execute([$uid]); $myOrders=$q->fetchAll(PDO::FETCH_COLUMN); ?>
  <h1>پشتیبانی</h1>
  <details class="pn-card" <?=!empty($_GET['new'])||$error?'open':''?>><summary class="button primary sm">+ تیکت جدید</summary>
   <form method="post" class="pn-form"><?=csrf_field()?><input type="hidden" name="action" value="ticket">
    <label>موضوع<input name="subject" required maxlength="200" value="<?=!empty($_GET['o'])?'درباره سفارش #'.(int)$_GET['o']:''?>"></label>
    <label>سفارش مرتبط<select name="order_id"><option value="0">— ندارد —</option><?php foreach($myOrders as $id):?><option value="<?=$id?>" <?=(int)($_GET['o']??0)===(int)$id?'selected':''?>>#<?=fa($id)?></option><?php endforeach;?></select></label>
    <label>پیام<textarea name="body" rows="5" required maxlength="5000"></textarea></label>
    <button class="button primary">ارسال تیکت</button>
   </form></details>
  <?php if(!$ts):?><div class="empty">هنوز تیکتی نفرستاده‌ای.</div><?php endif;?>
  <div class="tlist"><?php foreach($ts as $t):?><a href="account.php?tab=ticket&t=<?=$t['id']?>"><b><?=h($t['subject'])?></b><small><?=jdate_str($t['updated_at']?:$t['created_at'])?></small><?=ticket_badge($t['status'])?></a><?php endforeach;?></div>

 <?php elseif($tab==='ticket'): $q=$db->prepare('SELECT * FROM tickets WHERE id=? AND user_id=?'); $q->execute([(int)($_GET['t']??0),$uid]); $t=$q->fetch(); ?>
  <div class="pn-head"><h1><?=$t?h($t['subject']):'تیکت'?></h1><a href="account.php?tab=tickets">← بازگشت</a></div>
  <?php if(!$t):?><div class="empty">تیکت پیدا نشد.</div><?php else: if($t['status']==='answered') $db->prepare("UPDATE tickets SET status='seen' WHERE id=?")->execute([$t['id']]); $m=$db->prepare('SELECT * FROM ticket_msgs WHERE ticket_id=? ORDER BY id'); $m->execute([$t['id']]); ?>
   <div class="chat"><?php foreach($m->fetchAll() as $msg):?><div class="msg <?=$msg['from_admin']?'adm':'me'?>"><small><?=$msg['from_admin']?'پشتیبانی گیم‌زون':'شما'?> · <?=jdate_str($msg['created_at'])?></small><p><?=nl2br(h($msg['body']))?></p></div><?php endforeach;?></div>
   <?php if($t['status']!=='closed'):?><form method="post" class="pn-form"><?=csrf_field()?><input type="hidden" name="action" value="reply"><input type="hidden" name="t" value="<?=$t['id']?>"><label>پاسخ شما<textarea name="body" rows="3" required maxlength="5000"></textarea></label><button class="button primary">ارسال</button></form><?php else:?><p class="muted">این تیکت بسته شده است. برای موضوع جدید تیکت تازه بفرستید.</p><?php endif;?>
  <?php endif;?>

 <?php else: ?>
  <h1>حساب کاربری</h1>
  <div class="pn-grid">
   <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="profile"><h2>اطلاعات من</h2>
    <label>نام<input name="name" required maxlength="100" value="<?=h($u['name'])?>"></label>
    <label>ایمیل<input value="<?=h($u['email'])?>" dir="ltr" disabled></label>
    <label>موبایل<input name="phone" dir="ltr" inputmode="numeric" value="<?=h($u['phone']??'')?>"></label>
    <button class="button primary">ذخیره</button></form>
   <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="password"><h2>تغییر رمز</h2>
    <label>رمز فعلی<input type="password" name="old" required dir="ltr" autocomplete="current-password"></label>
    <label>رمز جدید<input type="password" name="new" required minlength="8" maxlength="72" dir="ltr" autocomplete="new-password"></label>
    <label>تکرار رمز جدید<input type="password" name="new2" required minlength="8" maxlength="72" dir="ltr" autocomplete="new-password"></label>
    <button class="button primary">تغییر رمز</button></form>
  </div>
  <p class="muted">عضو از <?=jdate_str($u['created_at'])?></p>
 <?php endif;?>
 </section>
</div>
<?php page_end();
