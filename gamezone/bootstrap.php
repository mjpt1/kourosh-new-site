<?php
declare(strict_types=1);
ini_set('display_errors','0');
if (session_status() !== PHP_SESSION_ACTIVE) {
 session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
 session_start();
}
$config = is_file(__DIR__.'/config.php') ? require __DIR__.'/config.php' : [];
$db = null;
$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
if (getenv('GZ_SQLITE')) { // فقط برای تست محلی
 $db = new PDO('sqlite:'.getenv('GZ_SQLITE'), null, null, $opts);
} elseif (!empty($config['db_name'])) {
 try { $db = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], $opts + [PDO::ATTR_EMULATE_PREPARES=>false]); } catch (Throwable $e) { error_log('Gamezone database connection failed'); }
}
function is_mysql(): bool { global $db; return $db && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

/* ---------- ارتقای خودکار دیتابیس (بدون پاک شدن داده) ---------- */
function db_has_col(string $t, string $c): bool {
 global $db;
 if (is_mysql()) { $q=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t,$c]); return (int)$q->fetchColumn() > 0; }
 foreach ($db->query("PRAGMA table_info($t)")->fetchAll() as $r) if ($r['name']===$c) return true; return false;
}
function db_sql(string $sql): string {
 if (is_mysql()) return $sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
 return str_replace(['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY','MEDIUMTEXT'], ['INTEGER PRIMARY KEY AUTOINCREMENT','TEXT'], $sql);
}
function migrate(): void {
 global $db; if (!$db) return;
 $ver = 4;
 try { $cur = (int)($db->query("SELECT v FROM settings WHERE k='schema_ver'")->fetchColumn() ?: 0); } catch (Throwable $e) { $cur = 0; }
 if ($cur >= $ver) return;
 $db->exec(db_sql('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v MEDIUMTEXT)'));
 $db->exec(db_sql('CREATE TABLE IF NOT EXISTS codes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, code VARCHAR(500) NOT NULL, order_id INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, sold_at TIMESTAMP NULL)'));
 $db->exec(db_sql('CREATE TABLE IF NOT EXISTS tickets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NULL, subject VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'open\', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL)'));
 $db->exec(db_sql('CREATE TABLE IF NOT EXISTS ticket_msgs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL, from_admin TINYINT NOT NULL DEFAULT 0, body MEDIUMTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)'));
 $cols = [
  'users'=>['phone'=>'VARCHAR(20) NULL','blocked'=>'TINYINT NOT NULL DEFAULT 0','last_login'=>'TIMESTAMP NULL'],
  'orders'=>['admin_note'=>'MEDIUMTEXT NULL','authority'=>'VARCHAR(64) NULL','ref_id'=>'VARCHAR(64) NULL','paid_at'=>'TIMESTAMP NULL','updated_at'=>'TIMESTAMP NULL'],
  'order_items'=>['codes'=>'MEDIUMTEXT NULL'],
  'products'=>['description'=>'MEDIUMTEXT NULL','sold'=>'INT NOT NULL DEFAULT 0'],
 ];
 foreach ($cols as $t=>$list) foreach ($list as $c=>$def) if (!db_has_col($t,$c)) $db->exec("ALTER TABLE $t ADD COLUMN $c ".(is_mysql()?$def:str_replace('MEDIUMTEXT','TEXT',$def)));
 $q = $db->prepare(is_mysql() ? 'REPLACE INTO settings (k,v) VALUES (?,?)' : 'INSERT OR REPLACE INTO settings (k,v) VALUES (?,?)');
 // هر محصول می‌تواند چند ریجن با قیمت و موجودی جدا داشته باشد
 $db->exec(db_sql('CREATE TABLE IF NOT EXISTS product_variants (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, region VARCHAR(32) NOT NULL, price INT UNSIGNED NOT NULL, old_price INT UNSIGNED NOT NULL DEFAULT 0, stock INT UNSIGNED NOT NULL DEFAULT 0, sold INT NOT NULL DEFAULT 0)'));
 if (!db_has_col('codes','region')) $db->exec("ALTER TABLE codes ADD COLUMN region VARCHAR(32) NOT NULL DEFAULT ''");
 if ((int)$db->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() === 0)
  $db->exec("INSERT INTO product_variants (product_id,region,price,old_price,stock,sold) SELECT id,region,price,old_price,stock,sold FROM products WHERE region<>'unassigned' AND region<>''");
 $q->execute(['schema_ver',(string)$ver]);
}
try { migrate(); } catch (Throwable $e) { error_log('Gamezone migrate: '.$e->getMessage()); }

/* ---------- تنظیمات ---------- */
function setting(string $k, string $def=''): string {
 global $db; static $all = null; if (!$db) return $def;
 if ($all === null) { $all = []; try { foreach ($db->query('SELECT k,v FROM settings') as $r) $all[$r['k']] = (string)$r['v']; } catch (Throwable $e) {} }
 return ($all[$k] ?? '') !== '' ? $all[$k] : $def;
}
function set_setting(string $k, string $v): void {
 global $db; $q = $db->prepare(is_mysql() ? 'REPLACE INTO settings (k,v) VALUES (?,?)' : 'INSERT OR REPLACE INTO settings (k,v) VALUES (?,?)'); $q->execute([$k,$v]);
}

/* ---------- عمومی ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
function check_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('درخواست نامعتبر است. صفحه را دوباره باز کنید.'); } }
function fa($n): string { return strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function money($n): string { return fa(number_format((int)$n)).' تومان'; }
function regions(): array { return ['unassigned'=>'تعیین نشده','us'=>'آمریکا','tr'=>'ترکیه','eu'=>'اروپا','gb'=>'بریتانیا','ae'=>'امارات','sa'=>'عربستان','ca'=>'کانادا','au'=>'استرالیا','jp'=>'ژاپن','hk'=>'هنگ‌کنگ','br'=>'برزیل','global'=>'جهانی']; }
function region_name(string $code): string { return regions()[$code] ?? 'تعیین نشده'; }
function region_ready($item): bool { $code=(string)($item['region']??'unassigned'); return $code!=='unassigned' && isset(regions()[$code]); }
function categories(): array { return ['Steam','PlayStation','Xbox','PUBG']; }
function products(): array {
 global $db;
 if (!$db) { $ps = require __DIR__.'/sample.php'; foreach ($ps as $i=>$p) $ps[$i]['variants'] = region_ready($p) ? [['region'=>$p['region'],'price'=>$p['price'],'old_price'=>$p['old_price'],'stock'=>$p['stock'],'sold'=>0]] : []; return $ps; }
 $ps = $db->query('SELECT * FROM products WHERE active=1 ORDER BY id DESC')->fetchAll();
 $vs = []; foreach ($db->query('SELECT * FROM product_variants ORDER BY price') as $v) if (isset(regions()[$v['region']]) && $v['region']!=='unassigned') $vs[$v['product_id']][] = $v;
 foreach ($ps as $i=>$p) $ps[$i]['variants'] = $vs[$p['id']] ?? [];
 return $ps;
}
// یک ریجن مشخص از یک محصول فعال
function variant(int $pid, string $region): ?array {
 global $db; if (!$db) return null;
 $q = $db->prepare('SELECT v.*, p.title, p.image, p.category, p.amount FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.product_id=? AND v.region=? AND p.active=1');
 $q->execute([$pid,$region]); return $q->fetch() ?: null;
}
function cart_key(int $pid, string $region): string { return $pid.':'.$region; }
function cart_split(string $key): array { $a = explode(':', $key, 2); return [(int)$a[0], (string)($a[1] ?? '')]; }
function cart_count(): int { return array_sum($_SESSION['cart'] ?? []); }
function go(string $url): void { header('Location: '.$url); exit; }
function flash(?string $s=null): string { if ($s!==null) { $_SESSION['flash']=$s; return ''; } $s=$_SESSION['flash']??''; unset($_SESSION['flash']); return $s; }
function text_len(string $s): int { return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s); }
function base_url(): string {
 $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off';
 $dir = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')),'/');
 $dir = preg_replace('~/admin$~','',$dir);
 return ($https?'https':'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').$dir.'/';
}

/* ---------- کاربر ---------- */
function user(): ?array {
 global $db; static $u = false;
 if ($u !== false) return $u;
 $u = null;
 if ($db && !empty($_SESSION['user_id'])) {
  $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([(int)$_SESSION['user_id']]); $u=$q->fetch() ?: null;
  if (!$u || !empty($u['blocked'])) { unset($_SESSION['user_id'],$_SESSION['admin']); $u=null; }
  else $_SESSION['admin'] = $u['role']==='admin';
 }
 return $u;
}
function admin(): bool { return (user()['role'] ?? '') === 'admin'; }

/* ---------- وضعیت سفارش ---------- */
function statuses(): array { return ['awaiting_payment'=>'در انتظار پرداخت','paid'=>'پرداخت شده','processing'=>'در حال آماده‌سازی','delivered'=>'تحویل شده','canceled'=>'لغو شده','refunded'=>'بازگشت وجه']; }
function status_label(string $s): string { return statuses()[$s] ?? $s; }
function status_badge(string $s): string { return '<span class="st st-'.h($s).'">'.h(status_label($s)).'</span>'; }
function jdate_str($ts): string {
 if (!$ts) return '—';
 $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
 [$gy,$gm,$gd] = array_map('intval', explode('-', date('Y-m-d',$t)));
 $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
 $gy2=($gm>2)?($gy+1):$gy;
 $days=355666+(365*$gy)+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$g_d_m[$gm-1];
 $jy=-1595+(33*intdiv($days,12053)); $days%=12053; $jy+=4*intdiv($days,1461); $days%=1461;
 if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;}
 if($days<186){$jm=1+intdiv($days,31);$jd=1+($days%31);}else{$jm=7+intdiv($days-186,30);$jd=1+(($days-186)%30);}
 return fa(sprintf('%04d/%02d/%02d – %s',$jy,$jm,$jd,date('H:i',$t)));
}

/* ---------- تحویل خودکار کد ---------- */
// سفارش پرداخت‌شده را از انبار کد تحویل می‌دهد. اگر کد کافی نباشد، سفارش «در حال آماده‌سازی» می‌ماند تا مدیر دستی تحویل دهد.
function deliver_order(int $oid): string {
 global $db; $lock = is_mysql() ? ' FOR UPDATE' : '';
 $db->beginTransaction();
 try {
  $items = $db->prepare('SELECT * FROM order_items WHERE order_id=?'.$lock); $items->execute([$oid]);
  $all = true;
  $pick = $db->prepare("SELECT id,code FROM codes WHERE product_id=? AND (region=? OR region='') AND order_id IS NULL ORDER BY region DESC, id LIMIT ?".$lock);
  $take = $db->prepare('UPDATE codes SET order_id=?, sold_at=CURRENT_TIMESTAMP WHERE id=? AND order_id IS NULL');
  $save = $db->prepare('UPDATE order_items SET codes=? WHERE id=?');
  foreach ($items->fetchAll() as $it) {
   $have = array_values(array_filter(preg_split('/\R/', (string)$it['codes'])));
   $need = (int)$it['quantity'] - count($have);
   if ($need > 0 && $it['product_id']) {
    $pick->bindValue(1,(int)$it['product_id'],PDO::PARAM_INT); $pick->bindValue(2,(string)$it['region']); $pick->bindValue(3,$need,PDO::PARAM_INT); $pick->execute();
    foreach ($pick->fetchAll() as $c) { $take->execute([$oid,$c['id']]); if ($take->rowCount()) $have[] = $c['code']; }
    $save->execute([implode("\n",$have),$it['id']]);
   }
   if (count($have) < (int)$it['quantity']) $all = false;
  }
  $st = $all ? 'delivered' : 'processing';
  $db->prepare('UPDATE orders SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$st,$oid]);
  $db->commit(); return $st;
 } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
// پرداخت را یک بار و فقط یک بار ثبت می‌کند (جلوگیری از تحویل دوباره)
function mark_paid(int $oid, string $ref=''): bool {
 global $db;
 $q=$db->prepare("UPDATE orders SET status='paid', ref_id=?, paid_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='awaiting_payment'");
 $q->execute([$ref,$oid]); if (!$q->rowCount()) return false;
 $it=$db->prepare('SELECT product_id,region,quantity FROM order_items WHERE order_id=?'); $it->execute([$oid]);
 $up=$db->prepare('UPDATE products SET stock=CASE WHEN stock>=? THEN stock-? ELSE 0 END, sold=sold+? WHERE id=?');
 $uv=$db->prepare('UPDATE product_variants SET stock=CASE WHEN stock>=? THEN stock-? ELSE 0 END, sold=sold+? WHERE product_id=? AND region=?');
 foreach ($it->fetchAll() as $r) if ($r['product_id']) { $n=(int)$r['quantity']; $up->execute([$n,$n,$n,$r['product_id']]); $uv->execute([$n,$n,$n,$r['product_id'],$r['region']]); }
 deliver_order($oid); return true;
}

/* ---------- زرین‌پال ---------- */
function zarinpal(string $path, array $data): array {
 $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/'.$path.'.json');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_TIMEOUT=>20]);
 $r = curl_exec($ch); curl_close($ch);
 return json_decode((string)$r, true) ?: [];
}
function ticket_badge(string $s): string { $l=['open'=>'منتظر پاسخ','answered'=>'پاسخ داده شد','seen'=>'دیده شد','closed'=>'بسته']; return '<span class="st tk-'.h($s).'">'.h($l[$s]??$s).'</span>'; }
