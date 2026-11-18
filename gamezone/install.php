<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params(['httponly'=>true,'secure'=>$https,'samesite'=>'Strict']);
session_start();
function eh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_host(string $host): bool { return strlen($host)<=253 && (bool)preg_match('/^(?=.{1,253}$)(?!-)[A-Za-z0-9.-]+(?<!-)$/D',$host) && strpos($host,'..')===false; }
$installed=is_file(__DIR__.'/config.php')||is_file(__DIR__.'/install.lock');
$keyPath=__DIR__.'/installer-key.php';
$setupKey=is_file($keyPath)?require $keyPath:null;
$missing=[];
if(!$https)$missing[]='این صفحه باید مستقیماً با HTTPS باز شود.';
if(!extension_loaded('pdo_mysql'))$missing[]='افزونه PHP pdo_mysql فعال نیست.';
if(!is_writable(__DIR__))$missing[]='پوشه سایت برای PHP قابل نوشتن نیست.';
if(!is_file(__DIR__.'/schema.sql'))$missing[]='فایل schema.sql موجود نیست.';
if(!is_string($setupKey)||strlen($setupKey)<32)$missing[]='فایل کلید نصب وجود ندارد یا معتبر نیست.';
$error='';$success=false;$cleanup='';$csrf=$_SESSION['install_csrf']??bin2hex(random_bytes(32));$_SESSION['install_csrf']=$csrf;
if($_SERVER['REQUEST_METHOD']==='POST' && !$installed && !$missing){
 $givenCsrf=(string)($_POST['csrf']??'');$givenKey=(string)($_POST['setup_key']??'');
 $dbHost=trim((string)($_POST['db_host']??'localhost'));$dbName=trim((string)($_POST['db_name']??''));
 $dbUser=trim((string)($_POST['db_user']??''));$dbPass=(string)($_POST['db_pass']??'');
 $email=trim((string)($_POST['admin_email']??''));$name=trim((string)($_POST['admin_name']??''));$pass=(string)($_POST['admin_password']??'');
 if(!hash_equals($csrf,$givenCsrf)||!hash_equals($setupKey,$givenKey))$error='کلید نصب یا درخواست معتبر نیست.';
 elseif(!valid_host($dbHost)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$dbName)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$dbUser)||strlen($dbPass)>256||str_contains($dbPass,chr(0)))$error='میزبان، نام دیتابیس و نام کاربر دیتابیس را بررسی کنید. نام‌ها باید با پیشوند کامل cPanel وارد شوند.';
 elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||$name===''||strlen($name)>200||strlen($pass)<12||strlen($pass)>72||$pass!==(string)($_POST['admin_password_confirm']??''))$error='نام و ایمیل مدیر و رمز یکسان ۱۲ تا ۷۲ کاراکتری وارد کنید.';
 else {
  $lock=@fopen(__DIR__.'/.install-running','x');
  if(!$lock)$error='نصب دیگری در حال اجراست یا قفل نصب باقی مانده است. در صورت توقف نصب، راهنما را بررسی کنید.';
  else {
   fclose($lock);$started=false;$stage='آماده‌سازی';
   try {
    if(is_file(__DIR__.'/config.php')||is_file(__DIR__.'/install.lock'))throw new RuntimeException('سایت قبلاً تنظیم شده است.');
    $stage='اتصال به دیتابیس';$pdo=new PDO('mysql:host='.$dbHost.';dbname='.$dbName.';charset=utf8mb4',$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $stage='بررسی خالی بودن دیتابیس';if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('دیتابیس خالی نیست. برای محافظت از داده‌ها یک دیتابیس تازه و خالی انتخاب کنید.');
    $started=true;
    $stage='ساخت جدول‌ها';$sql=file_get_contents(__DIR__.'/schema.sql');if($sql===false)throw new RuntimeException('خواندن فایل ساخت جدول‌ها ناموفق بود.');
    foreach(preg_split('/;\\s*(?:\\r?\\n|$)/',$sql) as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
    $stage='ساخت حساب مدیر';$query=$pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,'admin')");$passwordHash=password_hash($pass,PASSWORD_DEFAULT);if(!is_string($passwordHash))throw new RuntimeException('ساخت رمز امن مدیر ناموفق بود.');$query->execute([$name,$email,$passwordHash]);
    $stage='ذخیره تنظیمات';$config=['db_host'=>$dbHost,'db_name'=>$dbName,'db_user'=>$dbUser,'db_pass'=>$dbPass,'payment_driver'=>'disabled'];
    $out=@fopen(__DIR__.'/config.php','x');if(!$out)throw new RuntimeException('فایل تنظیمات ساخته نشد. دسترسی نوشتن را بررسی کنید.');
    $contents="<?php\nreturn ".var_export($config,true).";\n";$written=fwrite($out,$contents);$flushed=fflush($out);fclose($out);
    if($written!==strlen($contents)||!$flushed){@unlink(__DIR__.'/config.php');throw new RuntimeException('ذخیره کامل تنظیمات انجام نشد.');}
    @chmod(__DIR__.'/config.php',0600);@file_put_contents(__DIR__.'/install.lock',"installed\n",LOCK_EX);@unlink($keyPath);$success=true;unset($_SESSION['install_csrf']);session_regenerate_id(true);
   } catch(Throwable $e) {
    $error='نصب در مرحله «'.$stage.'» متوقف شد. '.($e instanceof RuntimeException?$e->getMessage():'جزئیات اتصال یا مجوزها را در پنل هاست بررسی کنید.');
    if($started)$cleanup='ممکن است جدول‌های ناقص در دیتابیس تازه ساخته شده باشند. پیش از تلاش مجدد وضعیت دیتابیس را بررسی کنید یا یک دیتابیس خالی دیگر بسازید؛ اطلاعات موجود را حذف نکنید.';
   } finally {unset($dbPass,$pass);@unlink(__DIR__.'/.install-running');}
  }
 }
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>نصب گیم‌زون</title><link rel="stylesheet" href="assets/css/style.css?v=4"></head><body><main class="container"><div class="form-wrap"><a class="brand" href="index.php"><span class="brand-icon">✣</span><span>گیم<span class="brand-accent">‌زون</span><small>GAMEZONE STORE</small></span></a><h1>نصب فروشگاه</h1>
<?php if($success): ?><p class="notice">نصب انجام شد. حساب مدیر ساخته شد و پرداخت آنلاین غیرفعال است. اکنون فایل install.php و فایل ZIP بارگذاری‌شده را از هاست حذف کنید.</p><a href="account.php">ورود مدیر ←</a>
<?php elseif($installed): ?><p class="notice">فروشگاه قبلاً تنظیم شده است. نصب‌کننده برای محافظت از داده‌ها غیرفعال است.</p><a href="index.php">بازگشت به فروشگاه ←</a>
<?php elseif($missing): ?><p class="error">پیش‌نیازهای نصب فراهم نیست:</p><ul><?php foreach($missing as $item):?><li><?=eh($item)?></li><?php endforeach;?></ul><p>اگر سایت قبلاً نصب شده است، هیچ فایلی از تنظیمات یا دیتابیس آن را حذف نکنید.</p>
<?php else: ?>
<p>ابتدا در cPanel یک دیتابیس خالی و کاربر MySQL بسازید و کاربر را با همه دسترسی‌ها به دیتابیس اختصاص دهید. سپس اطلاعات کامل دیتابیس را وارد کنید. به توکن API نیازی نیست.</p>
<p>ابتدا فایل ZIP بارگذاری‌شده را از پوشه عمومی حذف کنید؛ سپس کلید یک‌بارمصرف را در File Manager از فایل <code>installer-key.php</code> بخوانید.</p>
<?php if($error):?><p class="error" role="alert"><?=eh($error)?></p><?php endif;?><?php if($cleanup):?><p class="notice"><?=eh($cleanup)?></p><?php endif;?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=eh($csrf)?>">
<label>کلید یک‌بارمصرف نصب<input name="setup_key" type="password" required autocomplete="off"></label>
<label>میزبان MySQL<input name="db_host" value="<?=eh($_POST['db_host']??'localhost')?>" required autocomplete="off"></label>
<label>نام کامل دیتابیس (همراه پیشوند cPanel)<input name="db_name" value="<?=eh($_POST['db_name']??'')?>" required maxlength="64" autocomplete="off"></label>
<label>نام کامل کاربر دیتابیس (همراه پیشوند cPanel)<input name="db_user" value="<?=eh($_POST['db_user']??'')?>" required maxlength="64" autocomplete="off"></label>
<label>رمز کاربر دیتابیس<input name="db_pass" type="password" required maxlength="256" autocomplete="new-password"></label>
<label>نام مدیر فروشگاه<input name="admin_name" required maxlength="100" autocomplete="off"></label>
<label>ایمیل مدیر فروشگاه<input name="admin_email" required type="email" maxlength="190" autocomplete="off"></label>
<label>رمز حساب مدیر (حداقل ۱۲ کاراکتر)<input name="admin_password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<label>تکرار رمز مدیر<input name="admin_password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<button type="submit" class="button primary">نصب فروشگاه و ساخت جدول‌ها</button></form><p>برای سایت قبلاً نصب‌شده از این صفحه استفاده نکنید. در صورت خطای نصب ممکن است جدول‌های ناقص در دیتابیس باقی بمانند.</p>
<?php endif;?></div></main></body></html>
