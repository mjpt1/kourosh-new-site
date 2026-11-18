<?php require __DIR__.'/../bootstrap.php';
if(!admin()){ flash('برای ورود به مدیریت با حساب مدیر وارد شوید.'); go('../account.php?back=account.php'); }
if(!$db) exit('اتصال به پایگاه داده برقرار نیست.');
$tab=(string)($_GET['tab']??'dash'); $error='';
$imgDir = __DIR__.'/../assets/images/products/';

/* ================= عملیات ================= */
if($_SERVER['REQUEST_METHOD']==='POST'){
 check_csrf(); $a=$_POST['action']??''; $id=(int)($_POST['id']??0);
 switch($a){
 case 'p_save':
  $title=trim($_POST['title']??''); $category=trim($_POST['category']??''); $amount=trim($_POST['amount']??'');
  $vars=[]; $badVar=false;
  foreach(regions() as $rc=>$rl){ if($rc==='unassigned'||empty($_POST['on'][$rc])) continue;
   $vp=filter_var($_POST['vprice'][$rc]??'',FILTER_VALIDATE_INT); $vo=filter_var(($_POST['vold'][$rc]??'')===''?0:$_POST['vold'][$rc],FILTER_VALIDATE_INT); $vs=filter_var(($_POST['vstock'][$rc]??'')===''?0:$_POST['vstock'][$rc],FILTER_VALIDATE_INT);
   if($vp===false||$vp<1||$vo===false||$vo<0||$vs===false||$vs<0){ $badVar=true; continue; }
   $vars[$rc]=[$vp,$vo,$vs]; }
  $region=$vars?array_key_first($vars):'unassigned'; $price=$vars?min(array_column($vars,0)):0; $old=0; $stock=array_sum(array_column($vars,2));
  $image=trim($_POST['image']??''); $desc=trim($_POST['description']??'');
  if(!empty($_FILES['upload']['tmp_name']) && is_uploaded_file($_FILES['upload']['tmp_name'])){
   $info=@getimagesize($_FILES['upload']['tmp_name']); $ext=['image/webp'=>'webp','image/png'=>'png','image/jpeg'=>'jpg'][$info['mime']??'']??null;
   if(!$ext||$_FILES['upload']['size']>3*1024*1024) $error='تصویر باید webp، png یا jpg و کمتر از ۳ مگابایت باشد.';
   else { $name='up-'.bin2hex(random_bytes(6)).'.'.$ext; if(move_uploaded_file($_FILES['upload']['tmp_name'],$imgDir.$name)) $image='assets/images/products/'.$name; else $error='ذخیره تصویر ممکن نشد (دسترسی پوشه را بررسی کنید).'; }
  }
  if(!$error && $badVar) $error='برای هر ریجن تیک‌خورده، قیمت (بیشتر از صفر) و موجودی درست وارد کنید.';
  if(!$error && (!$title||text_len($title)>200||!in_array($category,categories(),true)||!$amount||text_len($amount)>60||!preg_match('~^assets/images/products/[a-zA-Z0-9_-]+\.(webp|png|jpg|jpeg)$~',$image)||!is_file(__DIR__.'/../'.$image)))
   $error='همه فیلدها را درست وارد کنید؛ تصویر را آپلود یا از لیست انتخاب کنید.';
  if(!$error){
   if($id) $db->prepare('UPDATE products SET title=?,category=?,amount=?,region=?,price=?,old_price=?,stock=?,image=?,description=? WHERE id=?')->execute([$title,$category,$amount,$region,$price,$old,$stock,$image,$desc,$id]);
   else { $db->prepare('INSERT INTO products (title,category,amount,region,price,old_price,stock,image,description) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$title,$category,$amount,$region,$price,$old,$stock,$image,$desc]); $id=(int)$db->lastInsertId(); }
   // ریجن‌ها: به‌روزرسانی، افزودن و حذف (آمار فروش هر ریجن حفظ می‌شود)
   $ex=$db->prepare('SELECT region FROM product_variants WHERE product_id=?'); $ex->execute([$id]); $have=$ex->fetchAll(PDO::FETCH_COLUMN);
   $u=$db->prepare('UPDATE product_variants SET price=?,old_price=?,stock=? WHERE product_id=? AND region=?'); $n=$db->prepare('INSERT INTO product_variants (product_id,region,price,old_price,stock) VALUES (?,?,?,?,?)'); $d=$db->prepare('DELETE FROM product_variants WHERE product_id=? AND region=?');
   foreach($vars as $rc=>[$vp,$vo,$vs]) in_array($rc,$have,true)?$u->execute([$vp,$vo,$vs,$id,$rc]):$n->execute([$id,$rc,$vp,$vo,$vs]);
   foreach($have as $rc) if(!isset($vars[$rc])) $d->execute([$id,$rc]);
   flash($vars?'محصول ذخیره شد.':'محصول ذخیره شد، اما هیچ ریجنی ندارد و قابل خرید نیست.'); go('?tab=products');
  }
  $tab='product'; break;
 case 'p_toggle': $db->prepare('UPDATE products SET active=1-active WHERE id=?')->execute([$id]); flash('وضعیت نمایش محصول عوض شد.'); go('?tab=products');
 case 'p_dup': $db->prepare('INSERT INTO products (title,category,amount,region,price,old_price,stock,image,description,active) SELECT title,category,amount,region,price,old_price,0,image,description,0 FROM products WHERE id=?')->execute([$id]); $nid=(int)$db->lastInsertId(); $db->prepare('INSERT INTO product_variants (product_id,region,price,old_price,stock) SELECT ?,region,price,old_price,0 FROM product_variants WHERE product_id=?')->execute([$nid,$id]); flash('یک کپی (مخفی) با همان ریجن‌ها ساخته شد؛ عنوان و قیمت‌ها را ویرایش کنید.'); go('?tab=product&id='.$nid);
 case 'c_add':
  [$pid,$crg]=cart_split((string)($_POST['product_id']??'')); $list=array_unique(array_filter(array_map('trim',preg_split('/\R/',(string)($_POST['codes']??'')))));
  $ex=$db->prepare('SELECT id FROM product_variants WHERE product_id=? AND region=?'); $ex->execute([$pid,$crg]);
  if(!$ex->fetch()||!$list){ $error='محصول و حداقل یک کد را وارد کنید.'; $tab='codes'; break; }
  $ins=$db->prepare('INSERT INTO codes (product_id,region,code) VALUES (?,?,?)'); $n=0; foreach($list as $c){ if(text_len($c)<=500){ $ins->execute([$pid,$crg,$c]); $n++; } }
  if(!empty($_POST['sync'])) { $db->prepare('UPDATE product_variants SET stock=stock+? WHERE product_id=? AND region=?')->execute([$n,$pid,$crg]); $db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$n,$pid]); }
  flash(fa($n).' کد به انبار اضافه شد.'); go('?tab=codes&p='.$pid);
 case 'c_del': $db->prepare('DELETE FROM codes WHERE id=? AND order_id IS NULL')->execute([$id]); flash('کد حذف شد.'); go('?tab=codes&p='.(int)($_POST['p']??0));
 case 'c_sync': $db->exec("UPDATE product_variants SET stock=(SELECT COUNT(*) FROM codes WHERE codes.product_id=product_variants.product_id AND codes.region=product_variants.region AND codes.order_id IS NULL)"); $db->exec('UPDATE products SET stock=(SELECT COALESCE(SUM(stock),0) FROM product_variants v WHERE v.product_id=products.id)'); flash('موجودی همه محصولات با تعداد کدهای انبار یکی شد.'); go('?tab=codes');
 case 'o_update':
  $q=$db->prepare('SELECT * FROM orders WHERE id=?'); $q->execute([$id]); $o=$q->fetch(); if(!$o) go('?tab=orders');
  $status=(string)($_POST['status']??$o['status']); $note=trim((string)($_POST['admin_note']??''));
  $up=$db->prepare('UPDATE order_items SET codes=? WHERE id=? AND order_id=?');
  foreach((array)($_POST['codes']??[]) as $iid=>$txt) $up->execute([trim(str_replace("\r",'',(string)$txt)),(int)$iid,$id]);
  if($o['status']==='awaiting_payment' && in_array($status,['paid','processing','delivered'],true)){ mark_paid($id,'MANUAL'); $q->execute([$id]); $o=$q->fetch(); if($status==='processing'||$status==='paid') $status=$o['status']; }
  if(isset(statuses()[$status])) $db->prepare('UPDATE orders SET status=?,admin_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$note,$id]);
  flash('سفارش #'.$id.' به‌روز شد.'); go('?tab=order&o='.$id);
 case 'o_autofill': deliver_order($id); flash('کدهای موجود از انبار برداشته شد.'); go('?tab=order&o='.$id);
 case 'u_save':
  $role=($_POST['role']??'')==='admin'?'admin':'customer'; $blocked=empty($_POST['blocked'])?0:1;
  if($id===(int)user()['id']){ $role='admin'; $blocked=0; }
  $db->prepare('UPDATE users SET name=?,phone=?,role=?,blocked=? WHERE id=?')->execute([trim((string)$_POST['name']),trim((string)$_POST['phone'])?:null,$role,$blocked,$id]);
  if(($p=(string)($_POST['newpass']??''))!==''){ if(strlen($p)<8) { flash('رمز جدید باید حداقل ۸ کاراکتر باشد.'); go('?tab=user&u='.$id); } $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($p,PASSWORD_DEFAULT),$id]); }
  flash('کاربر ذخیره شد.'); go('?tab=user&u='.$id);
 case 't_reply':
  $body=trim((string)($_POST['body']??''));
  if($body!==''){ $db->prepare('INSERT INTO ticket_msgs (ticket_id,from_admin,body) VALUES (?,1,?)')->execute([$id,$body]); $db->prepare("UPDATE tickets SET status='answered',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]); }
  if(!empty($_POST['close'])) $db->prepare("UPDATE tickets SET status='closed' WHERE id=?")->execute([$id]);
  flash('پاسخ ثبت شد.'); go('?tab=ticket&t='.$id);
 case 's_save':
  foreach(['zarinpal_merchant','support_telegram','support_phone','support_email','announcement','enamad'] as $k) set_setting($k,trim((string)($_POST[$k]??'')));
  flash('تنظیمات ذخیره شد.'); go('?tab=settings');
 }
}

/* ================= داده‌های مشترک ================= */
$cnt=fn($sql)=>(int)$db->query($sql)->fetchColumn();
$badgeOrders=$cnt("SELECT COUNT(*) FROM orders WHERE status IN ('paid','processing')");
$badgeTickets=$cnt("SELECT COUNT(*) FROM tickets WHERE status='open'");
$lines=$db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
$tabs=['dash'=>'◈ داشبورد','orders'=>'▤ سفارش‌ها','products'=>'▣ محصولات','codes'=>'⌗ انبار کد','users'=>'♙ کاربران','tickets'=>'✉ تیکت‌ها','settings'=>'⚙ تنظیمات'];
$activeTab=['order'=>'orders','product'=>'products','user'=>'users','ticket'=>'tickets'][$tab]??$tab;
$css='../assets/css/style.css?v='.filemtime(__DIR__.'/../assets/css/style.css');
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>مدیریت گیم‌زون</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"><link rel="stylesheet" href="<?=$css?>"><style>body{font-family:Vazirmatn,Tahoma,sans-serif}button,input,select,textarea{font-family:inherit}</style></head><body class="adm">
<div class="pn adm-wrap">
<aside class="pn-side">
 <a class="brand" href="../index.php"><span class="brand-icon">✣</span><span>گیم<span class="brand-accent">‌زون</span><small>ADMIN PANEL</small></span></a>
 <nav><?php foreach($tabs as $k=>$l):?><a class="<?=$activeTab===$k?'on':''?>" href="?tab=<?=$k?>"><?=$l?><?php if($k==='orders'&&$badgeOrders):?><em class="dot"><?=fa($badgeOrders)?></em><?php endif;?><?php if($k==='tickets'&&$badgeTickets):?><em class="dot"><?=fa($badgeTickets)?></em><?php endif;?></a><?php endforeach;?>
 <a href="../index.php" target="_blank">↗ مشاهده سایت</a><a href="../account.php">♙ حساب من</a></nav>
</aside>
<section class="pn-main">
<?php if($m=flash()):?><p class="notice"><?=h($m)?></p><?php endif;?>
<?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>

<?php /* ---------- داشبورد ---------- */ if($tab==='dash'):
 $paidSt="status IN ('paid','processing','delivered')";
 $today=$cnt("SELECT COALESCE(SUM(total),0) FROM orders WHERE $paidSt AND DATE(COALESCE(paid_at,created_at))=DATE(CURRENT_TIMESTAMP)");
 $month=$cnt("SELECT COALESCE(SUM(total),0) FROM orders WHERE $paidSt AND COALESCE(paid_at,created_at)>=".(is_mysql()?"DATE_SUB(NOW(),INTERVAL 30 DAY)":"datetime('now','-30 days')"));
 $all=$cnt("SELECT COALESCE(SUM(total),0) FROM orders WHERE $paidSt");
 $users=$cnt('SELECT COUNT(*) FROM users'); $codesLeft=$cnt('SELECT COUNT(*) FROM codes WHERE order_id IS NULL');
 $days=[]; $q=$db->query("SELECT DATE(COALESCE(paid_at,created_at)) d, SUM(total) s FROM orders WHERE $paidSt GROUP BY DATE(COALESCE(paid_at,created_at))"); foreach($q as $r) $days[$r['d']]=(int)$r['s'];
 $chart=[]; for($i=13;$i>=0;$i--){ $d=date('Y-m-d',strtotime("-$i day")); $chart[$d]=$days[$d]??0; } $max=max(1,max($chart));
 $low=$db->query("SELECT p.id,p.title,v.region,v.stock,(SELECT COUNT(*) FROM codes c WHERE c.product_id=p.id AND c.region=v.region AND c.order_id IS NULL) codes FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1 AND v.stock<=3 ORDER BY v.stock LIMIT 8")->fetchAll();
 $top=$db->query('SELECT title,SUM(quantity) n,SUM(price*quantity) s FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.'.$paidSt.' GROUP BY title ORDER BY n DESC LIMIT 6')->fetchAll();
 $pending=$db->query("SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.status IN ('paid','processing') ORDER BY o.id LIMIT 10")->fetchAll(); ?>
 <h1>داشبورد</h1>
 <div class="kpis">
  <div><small>فروش امروز</small><b><?=money($today)?></b></div>
  <div><small>فروش ۳۰ روز</small><b><?=money($month)?></b></div>
  <div><small>کل فروش</small><b><?=money($all)?></b></div>
  <div><small>کاربران</small><b><?=fa($users)?></b></div>
  <div class="<?=$badgeOrders?'warn':''?>"><small>سفارش منتظر تحویل</small><b><?=fa($badgeOrders)?></b></div>
  <div class="<?=$badgeTickets?'warn':''?>"><small>تیکت باز</small><b><?=fa($badgeTickets)?></b></div>
  <div><small>کد آماده در انبار</small><b><?=fa($codesLeft)?></b></div>
 </div>
 <div class="pn-card"><h2>فروش ۱۴ روز اخیر</h2><div class="bars"><?php foreach($chart as $d=>$v):?><div title="<?=h(money($v))?>"><i style="height:<?=max(2,round($v/$max*100))?>%"></i><small><?=mb_substr(jdate_str($d),8,2,'UTF-8')?></small></div><?php endforeach;?></div></div>
 <div class="pn-grid">
  <div class="pn-card"><h2>⚠ منتظر تحویل</h2><?php if(!$pending):?><p class="muted">همه سفارش‌ها تحویل شده‌اند ✓</p><?php endif;?><?php foreach($pending as $o):?><a class="row-link" href="?tab=order&o=<?=$o['id']?>">#<?=fa($o['id'])?> — <?=h($o['name'])?> <?=status_badge($o['status'])?></a><?php endforeach;?></div>
  <div class="pn-card"><h2>موجودی کم</h2><?php if(!$low):?><p class="muted">موجودی همه محصولات کافی است.</p><?php endif;?><?php foreach($low as $p):?><a class="row-link" href="?tab=codes&p=<?=$p['id']?>"><?=h($p['title'])?> <small>(<?=h(region_name($p['region']))?>)</small> — موجودی <?=fa($p['stock'])?> / کد <?=fa($p['codes'])?></a><?php endforeach;?></div>
  <div class="pn-card"><h2>پرفروش‌ها</h2><?php foreach($top as $t):?><div class="row-link"><?=h($t['title'])?> — <?=fa($t['n'])?> عدد · <?=money($t['s'])?></div><?php endforeach;?><?php if(!$top):?><p class="muted">هنوز فروشی ثبت نشده.</p><?php endif;?></div>
 </div>

<?php /* ---------- سفارش‌ها ---------- */ elseif($tab==='orders'):
 $f=(string)($_GET['f']??''); $s=trim((string)($_GET['s']??'')); $where=[]; $args=[];
 if(isset(statuses()[$f])){ $where[]='o.status=?'; $args[]=$f; }
 if($s!==''){ $where[]='(o.id=? OR u.email LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)'; array_push($args,(int)$s,"%$s%","%$s%","%$s%"); }
 $q=$db->prepare('SELECT o.*,u.name,u.email FROM orders o JOIN users u ON u.id=o.user_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY o.id DESC LIMIT 200'); $q->execute($args); $list=$q->fetchAll(); ?>
 <h1>سفارش‌ها</h1>
 <form class="toolbar"><input type="hidden" name="tab" value="orders"><input name="s" value="<?=h($s)?>" placeholder="شماره سفارش، نام، ایمیل یا موبایل"><select name="f"><option value="">همه وضعیت‌ها</option><?php foreach(statuses() as $k=>$l):?><option value="<?=$k?>" <?=$f===$k?'selected':''?>><?=$l?></option><?php endforeach;?></select><button class="button sm primary">جست‌وجو</button></form>
 <div class="tbl"><table class="admin-table"><tr><th>#</th><th>مشتری</th><th>اقلام</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
 <?php foreach($list as $o): $lines->execute([$o['id']]); ?><tr><td><?=fa($o['id'])?></td><td><?=h($o['name'])?><br><small dir="ltr"><?=h($o['email'])?></small></td><td><?php foreach($lines->fetchAll() as $l):?><?=h($l['title'])?> <small>(<?=h(region_name($l['region']))?>)</small> × <?=fa($l['quantity'])?><br><?php endforeach;?></td><td><?=money($o['total'])?></td><td><?=status_badge($o['status'])?></td><td><small><?=jdate_str($o['created_at'])?></small></td><td><a class="button sm" href="?tab=order&o=<?=$o['id']?>">مدیریت</a></td></tr><?php endforeach;?>
 </table><?php if(!$list):?><p class="muted">سفارشی پیدا نشد.</p><?php endif;?></div>

<?php elseif($tab==='order'): $q=$db->prepare('SELECT o.*,u.name,u.email,u.phone FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?'); $q->execute([(int)($_GET['o']??0)]); $o=$q->fetch(); ?>
 <div class="pn-head"><h1>سفارش #<?=fa($o['id']??0)?></h1><a href="?tab=orders">← همه سفارش‌ها</a></div>
 <?php if(!$o):?><div class="empty">سفارش پیدا نشد.</div><?php else: $lines->execute([$o['id']]); $items=$lines->fetchAll(); ?>
 <div class="pn-grid">
  <div class="pn-card"><h2>مشتری</h2><p><?=h($o['name'])?></p><p dir="ltr"><?=h($o['email'])?></p><p dir="ltr"><?=h($o['phone']?:'—')?></p><a href="?tab=user&u=<?=$o['user_id']?>">پروفایل مشتری ←</a></div>
  <div class="pn-card"><h2>پرداخت</h2><p>وضعیت: <?=status_badge($o['status'])?></p><p>مبلغ: <b><?=money($o['total'])?></b></p><p>کد رهگیری: <span dir="ltr"><?=h($o['ref_id']?:'—')?></span></p><p>ثبت: <?=jdate_str($o['created_at'])?><br>پرداخت: <?=jdate_str($o['paid_at'])?></p></div>
 </div>
 <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="o_update"><input type="hidden" name="id" value="<?=$o['id']?>">
  <h2>تحویل کد به مشتری</h2><p class="muted">هر کد را در یک خط بنویسید. مشتری همین کدها را در پنلش می‌بیند.</p>
  <?php foreach($items as $it): $have=count(array_filter(preg_split('/\R/',(string)$it['codes']))); ?>
   <label><?=h($it['title'])?> — <?=h(region_name($it['region']))?> × <?=fa($it['quantity'])?> <small class="<?=$have>=$it['quantity']?'ok':'bad'?>">(<?=fa($have)?> از <?=fa($it['quantity'])?> کد)</small>
   <textarea name="codes[<?=$it['id']?>]" rows="<?=max(2,(int)$it['quantity'])?>" dir="ltr"><?=h($it['codes'])?></textarea></label>
  <?php endforeach;?>
  <label>پیام برای مشتری (در پنلش نمایش داده می‌شود)<textarea name="admin_note" rows="2"><?=h($o['admin_note'])?></textarea></label>
  <label>وضعیت سفارش<select name="status"><?php foreach(statuses() as $k=>$l):?><option value="<?=$k?>" <?=$o['status']===$k?'selected':''?>><?=$l?></option><?php endforeach;?></select></label>
  <div class="btns"><button class="button primary">ذخیره و ارسال برای مشتری</button></div>
 </form>
 <?php if(in_array($o['status'],['paid','processing'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="o_autofill"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="button">⚡ برداشتن خودکار کد از انبار</button></form><?php endif;?>
 <?php endif;?>

<?php /* ---------- محصولات ---------- */ elseif($tab==='products'):
 $list=$db->query('SELECT p.*,(SELECT COUNT(*) FROM codes c WHERE c.product_id=p.id AND c.order_id IS NULL) codes FROM products p ORDER BY p.active DESC, p.id DESC')->fetchAll(); $pv=[]; foreach($db->query('SELECT * FROM product_variants ORDER BY price') as $v) $pv[$v['product_id']][]=$v; ?>
 <div class="pn-head"><h1>محصولات</h1><a class="button primary sm" href="?tab=product">+ محصول جدید</a></div>
 <div class="tbl"><table class="admin-table"><tr><th></th><th>عنوان</th><th>ریجن‌ها (قیمت · موجودی)</th><th>کد انبار</th><th>فروش</th><th>عملیات</th></tr>
 <?php foreach($list as $p):?><tr class="<?=$p['active']?'':'off'?>"><td><img src="../<?=h($p['image'])?>" alt="" width="44" height="44" class="thumb"></td><td><?=h($p['title'])?><br><small><?=h($p['category'])?> · <?=h($p['amount'])?></small></td><td><?php if(empty($pv[$p['id']])):?><span class="bad">بدون ریجن — قابل خرید نیست</span><?php endif;?><?php foreach($pv[$p['id']]??[] as $v):?><div class="vline"><b><?=h(region_name($v['region']))?></b> <?=money($v['price'])?> · <span class="<?=$v['stock']?'ok':'bad'?>"><?=fa($v['stock'])?></span></div><?php endforeach;?></td><td><a href="?tab=codes&p=<?=$p['id']?>"><?=fa($p['codes'])?></a></td><td><?=fa($p['sold']??0)?></td>
 <td class="acts"><a class="button sm" href="?tab=product&id=<?=$p['id']?>">ویرایش</a><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$p['id']?>"><button class="button sm" name="action" value="p_toggle"><?=$p['active']?'مخفی کن':'نمایش بده'?></button><button class="button sm" name="action" value="p_dup" title="کپی محصول با همه ریجن‌ها">کپی</button></form></td></tr><?php endforeach;?>
 </table></div>

<?php elseif($tab==='product'):
 $e=[]; if(!empty($_GET['id'])){ $q=$db->prepare('SELECT * FROM products WHERE id=?'); $q->execute([(int)$_GET['id']]); $e=$q->fetch()?:[]; }
 if($_SERVER['REQUEST_METHOD']==='POST') $e=array_merge($e,$_POST);
 $images=array_map(fn($f)=>'assets/images/products/'.basename($f), glob($imgDir.'*.{webp,png,jpg,jpeg}',GLOB_BRACE)?:[]); $cur=$e['image']??($images[0]??''); ?>
 <div class="pn-head"><h1><?=!empty($e['id'])?'ویرایش محصول':'محصول جدید'?></h1><a href="?tab=products">← محصولات</a></div>
 <form method="post" enctype="multipart/form-data" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="p_save"><input type="hidden" name="id" value="<?=(int)($e['id']??0)?>">
  <div class="pn-grid">
   <label>عنوان<input name="title" required maxlength="200" value="<?=h($e['title']??'')?>"></label>
   <label>دسته‌بندی<select name="category"><?php foreach(categories() as $c):?><option <?=($e['category']??'')===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></label>
   <label>مقدار (مثلاً ۲۰ دلار)<input name="amount" required maxlength="60" value="<?=h($e['amount']??'')?>"></label>
  </div>
  <?php $cv=[]; if(!empty($e['id'])){ $q=$db->prepare('SELECT * FROM product_variants WHERE product_id=?'); $q->execute([(int)$e['id']]); foreach($q as $v) $cv[$v['region']]=$v; }
   if($_SERVER['REQUEST_METHOD']==='POST'){ $cv=[]; foreach((array)($_POST['on']??[]) as $rc=>$_) $cv[$rc]=['price'=>$_POST['vprice'][$rc]??'','old_price'=>$_POST['vold'][$rc]??'','stock'=>$_POST['vstock'][$rc]??'']; } ?>
  <h2>ریجن‌ها، قیمت و موجودی</h2>
  <p class="muted">هر ریجنی که این محصول برایش موجود است را تیک بزنید و قیمت و موجودی‌اش را بنویسید. مشتری روی کارت محصول ریجن را انتخاب می‌کند.</p>
  <div class="tbl"><table class="admin-table vtable"><tr><th>ریجن</th><th>قیمت (تومان)</th><th>قیمت قبل از تخفیف</th><th>موجودی</th></tr>
  <?php foreach(regions() as $rc=>$rl): if($rc==='unassigned') continue; $v=$cv[$rc]??null; ?><tr class="<?=$v?'sel':''?>"><td><label class="check"><input type="checkbox" name="on[<?=$rc?>]" value="1" <?=$v?'checked':''?> onchange="this.closest('tr').classList.toggle('sel',this.checked)"> <?=h($rl)?></label></td><td><input type="number" min="1" name="vprice[<?=$rc?>]" value="<?=h($v['price']??'')?>"></td><td><input type="number" min="0" name="vold[<?=$rc?>]" value="<?=h($v['old_price']??'')?>" placeholder="۰"></td><td><input type="number" min="0" name="vstock[<?=$rc?>]" value="<?=h($v['stock']??'')?>" placeholder="۰"></td></tr><?php endforeach;?>
  </table></div>
  <label>توضیحات<textarea name="description" rows="3"><?=h($e['description']??'')?></textarea></label>
  <h2>تصویر</h2>
  <div class="img-pick"><?php foreach($images as $im):?><label><input type="radio" name="image" value="<?=h($im)?>" <?=$cur===$im?'checked':''?>><img src="../<?=h($im)?>" alt="" loading="lazy"></label><?php endforeach;?></div>
  <label>یا آپلود تصویر جدید (webp / png / jpg، حداکثر ۳ مگابایت)<input type="file" name="upload" accept="image/webp,image/png,image/jpeg"></label>
  <button class="button primary">ذخیره محصول</button>
 </form>

<?php /* ---------- انبار کد ---------- */ elseif($tab==='codes'):
 $pid=(int)($_GET['p']??0); $prods=$db->query('SELECT p.id,p.title,v.region,p.active,(SELECT COUNT(*) FROM codes c WHERE c.product_id=p.id AND c.region=v.region AND c.order_id IS NULL) free,(SELECT COUNT(*) FROM codes c WHERE c.product_id=p.id AND c.region=v.region AND c.order_id IS NOT NULL) used FROM product_variants v JOIN products p ON p.id=v.product_id ORDER BY p.active DESC,p.id DESC,v.region')->fetchAll(); ?>
 <h1>انبار کد</h1>
 <p class="muted">کدها را اینجا اضافه کنید. بعد از هر پرداخت موفق، کد به‌صورت خودکار به مشتری تحویل داده می‌شود. اگر کد تمام شده باشد، سفارش در «منتظر تحویل» می‌ماند.</p>
 <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="c_add">
  <?php if(!$prods):?><p class="error">اول در «محصولات» برای محصول‌ها ریجن تعریف کنید.</p><?php endif;?><label>محصول و ریجن<select name="product_id"><?php foreach($prods as $p):?><option value="<?=h(cart_key((int)$p['id'],$p['region']))?>" <?=$pid===(int)$p['id']?'selected':''?>><?=h($p['title'])?> — <?=h(region_name($p['region']))?><?=$p['active']?'':' (مخفی)'?></option><?php endforeach;?></select></label>
  <label>کدها (هر خط یک کد)<textarea name="codes" rows="6" dir="ltr" required placeholder="XXXX-XXXX-XXXX"></textarea></label>
  <label class="check"><input type="checkbox" name="sync" value="1" checked> موجودی همین ریجن هم به همین تعداد زیاد شود</label>
  <button class="button primary">افزودن به انبار</button>
 </form>
 <div class="tbl"><table class="admin-table"><tr><th>محصول</th><th>آماده</th><th>فروخته</th><th></th></tr><?php foreach($prods as $p):?><tr class="<?=$pid===(int)$p['id']?'sel':''?>"><td><?=h($p['title'])?> <small>(<?=h(region_name($p['region']))?>)</small></td><td class="<?=$p['free']?'ok':'bad'?>"><?=fa($p['free'])?></td><td><?=fa($p['used'])?></td><td><a class="button sm" href="?tab=codes&p=<?=$p['id']?>">نمایش کدها</a></td></tr><?php endforeach;?></table></div>
 <form method="post" onsubmit="return confirm('موجودی هر ریجن برابر تعداد کدهای آماده همان ریجن شود؟')"><?=csrf_field()?><input type="hidden" name="action" value="c_sync"><button class="button sm">همگام‌سازی موجودی با انبار کد</button></form>
 <?php if($pid): $q=$db->prepare('SELECT * FROM codes WHERE product_id=? ORDER BY order_id IS NULL DESC, id DESC LIMIT 300'); $q->execute([$pid]); ?>
  <div class="pn-card"><h2>کدهای این محصول</h2><div class="tbl"><table class="admin-table"><tr><th>کد</th><th>ریجن</th><th>وضعیت</th><th></th></tr><?php foreach($q as $c):?><tr><td dir="ltr"><code><?=h($c['code'])?></code></td><td><?=h($c['region']===''?'همه ریجن‌ها':region_name($c['region']))?></td><td><?=$c['order_id']?'<a href="?tab=order&o='.(int)$c['order_id'].'">فروخته — سفارش #'.fa($c['order_id']).'</a>':'<span class="ok">آماده</span>'?></td><td><?php if(!$c['order_id']):?><form method="post" onsubmit="return confirm('این کد حذف شود؟')"><?=csrf_field()?><input type="hidden" name="action" value="c_del"><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="p" value="<?=$pid?>"><button class="button sm">حذف</button></form><?php endif;?></td></tr><?php endforeach;?></table></div></div>
 <?php endif;?>

<?php /* ---------- کاربران ---------- */ elseif($tab==='users'):
 $s=trim((string)($_GET['s']??'')); $q=$db->prepare("SELECT u.*,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) oc,(SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.user_id=u.id AND o.status IN ('paid','processing','delivered')) spent FROM users u WHERE u.name LIKE ? OR u.email LIKE ? OR COALESCE(u.phone,'') LIKE ? ORDER BY u.id DESC LIMIT 300"); $q->execute(["%$s%","%$s%","%$s%"]); ?>
 <h1>کاربران</h1>
 <form class="toolbar"><input type="hidden" name="tab" value="users"><input name="s" value="<?=h($s)?>" placeholder="نام، ایمیل یا موبایل"><button class="button sm primary">جست‌وجو</button></form>
 <div class="tbl"><table class="admin-table"><tr><th>نام</th><th>ایمیل</th><th>موبایل</th><th>سفارش</th><th>خرید</th><th>نقش</th><th></th></tr><?php foreach($q as $r):?><tr class="<?=$r['blocked']?'off':''?>"><td><?=h($r['name'])?></td><td dir="ltr"><?=h($r['email'])?></td><td dir="ltr"><?=h($r['phone']?:'—')?></td><td><?=fa($r['oc'])?></td><td><?=money($r['spent'])?></td><td><?=$r['role']==='admin'?'مدیر':'مشتری'?><?=$r['blocked']?' · مسدود':''?></td><td><a class="button sm" href="?tab=user&u=<?=$r['id']?>">مدیریت</a></td></tr><?php endforeach;?></table></div>

<?php elseif($tab==='user'): $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([(int)($_GET['u']??0)]); $r=$q->fetch(); ?>
 <div class="pn-head"><h1>کاربر</h1><a href="?tab=users">← کاربران</a></div>
 <?php if(!$r):?><div class="empty">کاربر پیدا نشد.</div><?php else: $o=$db->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC'); $o->execute([$r['id']]); ?>
 <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="u_save"><input type="hidden" name="id" value="<?=$r['id']?>">
  <div class="pn-grid"><label>نام<input name="name" value="<?=h($r['name'])?>" required></label><label>ایمیل<input value="<?=h($r['email'])?>" disabled dir="ltr"></label><label>موبایل<input name="phone" value="<?=h($r['phone'])?>" dir="ltr"></label>
  <label>نقش<select name="role"><option value="customer">مشتری</option><option value="admin" <?=$r['role']==='admin'?'selected':''?>>مدیر</option></select></label>
  <label>رمز جدید (خالی = بدون تغییر)<input name="newpass" type="text" minlength="8" dir="ltr" autocomplete="off"></label></div>
  <label class="check"><input type="checkbox" name="blocked" value="1" <?=$r['blocked']?'checked':''?>> حساب مسدود باشد</label>
  <p class="muted">عضویت: <?=jdate_str($r['created_at'])?> · آخرین ورود: <?=jdate_str($r['last_login']??null)?></p>
  <button class="button primary">ذخیره</button></form>
 <div class="pn-card"><h2>سفارش‌های این کاربر</h2><?php foreach($o as $x):?><a class="row-link" href="?tab=order&o=<?=$x['id']?>">#<?=fa($x['id'])?> — <?=money($x['total'])?> <?=status_badge($x['status'])?> <small><?=jdate_str($x['created_at'])?></small></a><?php endforeach;?></div>
 <?php endif;?>

<?php /* ---------- تیکت‌ها ---------- */ elseif($tab==='tickets'):
 $f=(string)($_GET['f']??'open'); $sql='SELECT t.*,u.name FROM tickets t JOIN users u ON u.id=t.user_id'.($f==='all'?'':' WHERE t.status=?').' ORDER BY COALESCE(t.updated_at,t.created_at) DESC LIMIT 200'; $q=$db->prepare($sql); $q->execute($f==='all'?[]:[$f]); ?>
 <h1>تیکت‌های پشتیبانی</h1>
 <div class="chips"><?php foreach(['open'=>'منتظر پاسخ','answered'=>'پاسخ داده شده','closed'=>'بسته','all'=>'همه'] as $k=>$l):?><a class="<?=$f===$k?'on':''?>" href="?tab=tickets&f=<?=$k?>"><?=$l?></a><?php endforeach;?></div>
 <div class="tlist"><?php $any=false; foreach($q as $t): $any=true;?><a href="?tab=ticket&t=<?=$t['id']?>"><b><?=h($t['subject'])?></b><small><?=h($t['name'])?> · <?=jdate_str($t['updated_at']?:$t['created_at'])?></small><?=ticket_badge($t['status'])?></a><?php endforeach;?></div><?php if(!$any):?><div class="empty">تیکتی نیست.</div><?php endif;?>

<?php elseif($tab==='ticket'): $q=$db->prepare('SELECT t.*,u.name,u.email FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?'); $q->execute([(int)($_GET['t']??0)]); $t=$q->fetch(); ?>
 <div class="pn-head"><h1><?=$t?h($t['subject']):'تیکت'?></h1><a href="?tab=tickets">← تیکت‌ها</a></div>
 <?php if($t): $m=$db->prepare('SELECT * FROM ticket_msgs WHERE ticket_id=? ORDER BY id'); $m->execute([$t['id']]); ?>
 <p class="muted"><?=h($t['name'])?> (<span dir="ltr"><?=h($t['email'])?></span>) <?php if($t['order_id']):?>· <a href="?tab=order&o=<?=$t['order_id']?>">سفارش #<?=fa($t['order_id'])?></a><?php endif;?> · <?=ticket_badge($t['status'])?></p>
 <div class="chat"><?php foreach($m as $msg):?><div class="msg <?=$msg['from_admin']?'me':'adm'?>"><small><?=$msg['from_admin']?'پشتیبانی':h($t['name'])?> · <?=jdate_str($msg['created_at'])?></small><p><?=nl2br(h($msg['body']))?></p></div><?php endforeach;?></div>
 <form method="post" class="pn-form"><?=csrf_field()?><input type="hidden" name="action" value="t_reply"><input type="hidden" name="id" value="<?=$t['id']?>"><label>پاسخ<textarea name="body" rows="4"></textarea></label><label class="check"><input type="checkbox" name="close" value="1"> بستن تیکت</label><button class="button primary">ارسال پاسخ</button></form>
 <?php else:?><div class="empty">تیکت پیدا نشد.</div><?php endif;?>

<?php /* ---------- تنظیمات ---------- */ else: ?>
 <h1>تنظیمات</h1>
 <form method="post" class="pn-card pn-form"><?=csrf_field()?><input type="hidden" name="action" value="s_save">
  <h2>درگاه پرداخت</h2>
  <label>مرچنت کد زرین‌پال (خالی = پرداخت آنلاین غیرفعال)<input name="zarinpal_merchant" dir="ltr" value="<?=h(setting('zarinpal_merchant'))?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label>
  <h2>پشتیبانی</h2>
  <div class="pn-grid"><label>آیدی تلگرام<input name="support_telegram" dir="ltr" value="<?=h(setting('support_telegram'))?>" placeholder="@gamezone_support"></label><label>تلفن<input name="support_phone" dir="ltr" value="<?=h(setting('support_phone'))?>"></label><label>ایمیل<input name="support_email" dir="ltr" value="<?=h(setting('support_email'))?>"></label></div>
  <h2>سایت</h2>
  <label>متن نوار اطلاعیه بالای سایت (خالی = متن پیش‌فرض)<input name="announcement" value="<?=h(setting('announcement'))?>"></label>
  <label>کد نماد اعتماد (اینماد) — HTML<textarea name="enamad" rows="2" dir="ltr"><?=h(setting('enamad'))?></textarea></label>
  <button class="button primary">ذخیره تنظیمات</button>
 </form>
 <p class="muted">رمز حساب مدیر را از «حساب من ← حساب کاربری» عوض کنید.</p>
<?php endif;?>
</section></div>
</body></html>
