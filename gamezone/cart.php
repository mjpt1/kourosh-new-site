<?php
require __DIR__.'/bootstrap.php'; header('Content-Type: application/json; charset=utf-8');
// سبد بر اساس «محصول:ریجن» نگه داشته می‌شود تا یک محصول با چند ریجن هم‌زمان در سبد باشد
$cart = $_SESSION['cart'] ?? [];
foreach (array_keys($cart) as $k) if (!str_contains((string)$k, ':')) unset($cart[$k]); // سبدهای نسخه قدیمی
$all = [];
foreach (products() as $p) foreach ($p['variants'] as $v) $all[cart_key((int)$p['id'], $v['region'])] = $p + ['v'=>$v];
$message = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf(); $key = (string)($_POST['id'] ?? ''); $action = $_POST['action'] ?? '';
 if ($action==='remove') unset($cart[$key]);
 elseif ($action==='minus') { if (isset($cart[$key]) && --$cart[$key] <= 0) unset($cart[$key]); }
 elseif ($action==='add') {
  if (!str_contains($key, ':')) $message = 'اول ریجن را انتخاب کنید.';
  elseif (!isset($all[$key])) $message = 'این ریجن برای این محصول موجود نیست.';
  elseif (($cart[$key] ?? 0) < (int)$all[$key]['v']['stock']) { $cart[$key] = ($cart[$key] ?? 0) + 1; $message = 'به سبد خرید اضافه شد.'; }
  else $message = 'موجودی این ریجن کافی نیست.';
 }
}
$items = []; $total = 0;
foreach ($cart as $key=>$qty) {
 if (!isset($all[$key])) { unset($cart[$key]); $message = 'یک کالا به علت تغییر موجودی یا ریجن از سبد حذف شد.'; continue; }
 $p = $all[$key]; $qty = min((int)$qty, (int)$p['v']['stock']);
 if ($qty < 1) { unset($cart[$key]); continue; }
 $cart[$key] = $qty; $total += (int)$p['v']['price'] * $qty;
 $items[] = ['id'=>$key, 'title'=>$p['title'], 'region'=>region_name($p['v']['region']), 'price'=>(int)$p['v']['price'], 'image'=>$p['image'], 'quantity'=>$qty];
}
$_SESSION['cart'] = $cart; unset($_SESSION['cart_regions']);
echo json_encode(['items'=>$items, 'count'=>cart_count(), 'total'=>$total, 'message'=>$message], JSON_UNESCAPED_UNICODE);
