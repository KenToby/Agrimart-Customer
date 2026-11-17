<?php
// Customer site API.  Usage: customer_api.php?action=<name>  (POST JSON body for writes)
//   products    -> products that approved farmers are selling        (reads agrimart_admin)
//   order_create-> saves a customer order and sends it to the farmers (writes both databases)
//   my_orders   -> the customer's orders with their current status    (reads both databases)
require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $code = 400, array $extra = []): void { out(['ok' => false, 'error' => $m] + $extra, $code); }

// Delivery fee rule: 40 pesos, free when the items total is 500 pesos or more
function delivery_fee(float $subtotal): float { return $subtotal >= 500 ? 0.0 : 40.0; }

// The farmer site uses English names, the shop page shows Filipino names
const CAT_LABEL  = ['Vegetables' => 'Gulay', 'Fruits' => 'Prutas', 'Rice & Grains' => 'Bigas at Butil',
                    'Fish & Seafood' => "Isda't Dagat", 'Poultry & Eggs' => 'Manok at Itlog'];
const UNIT_LABEL = ['kilo' => 'kilo', 'piece' => 'piraso', 'dozen' => 'dozen', 'sack' => 'sako', 'bundle' => 'bungkos'];

/**
 * Loads customer orders. $pairs = [['code' => 'SF-100001', 'token' => '...'], ...]
 * An order is only returned when the code AND its secret token match, so nobody
 * can read other customers' orders by guessing order numbers.
 * Returns [orders, statusMap]; statusMap = farmer order id => status and note.
 */
function load_orders(array $pairs): array {
    $cdb = db();
    $found = [];
    $find = $cdb->prepare('SELECT * FROM customer_orders WHERE order_code = ? AND access_token = ?');
    foreach (array_slice($pairs, 0, 50) as $p) {
        $code = (string)($p['code'] ?? ''); $tok = (string)($p['token'] ?? '');
        if (!preg_match('/^SF-\d{6,12}$/', $code) || !preg_match('/^[0-9a-f]{16}$/', $tok)) continue;
        $find->execute([$code, $tok]);
        if ($row = $find->fetch()) $found[(int)$row['id']] = $row;
    }
    if (!$found) return [[], []];

    $ids = array_keys($found);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $items = [];
    $st = $cdb->prepare("SELECT order_id,name,icon,unit,price,qty FROM customer_order_items WHERE order_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    foreach ($st as $r) {
        $items[$r['order_id']][] = ['name' => $r['name'], 'icon' => $r['icon'], 'unit' => $r['unit'],
                                    'price' => (float)$r['price'], 'qty' => (int)$r['qty']];
    }

    $subs = [];
    $st = $cdb->prepare("SELECT order_id, farmer_order_id FROM customer_farmer_orders WHERE order_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    foreach ($st as $r) $subs[$r['order_id']][] = $r['farmer_order_id'];

    // Current status of each farmer order lives in the farmer database
    $statusMap = [];
    $allSubs = array_merge(...array_values($subs));
    if ($allSubs) {
        $ph2 = implode(',', array_fill(0, count($allSubs), '?'));
        $st = admin_db()->prepare("SELECT id, status, farmer_note FROM orders WHERE id IN ($ph2)");
        $st->execute($allSubs);
        foreach ($st as $r) $statusMap[$r['id']] = ['status' => $r['status'], 'note' => $r['farmer_note'] ?? ''];
    }

    krsort($found);   // newest first
    $list = [];
    foreach ($found as $id => $r) {
        $list[] = [
            'id' => $r['order_code'], 'subIds' => $subs[$id] ?? [], 'createdAt' => $r['created_at'],
            'items' => $items[$id] ?? [], 'subtotal' => (float)$r['subtotal'], 'delivery' => (float)$r['delivery_fee'],
            'total' => (float)$r['total'], 'pay' => $r['pay_method'], 'name' => $r['customer_name'],
            'phone' => $r['phone'], 'address' => $r['address'],
        ];
    }
    return [$list, $statusMap];
}

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$cdb = null; $adb = null;

try {
switch ($action) {

/* ---------- Products sold by approved farmers ---------- */
case 'products':
    $rows = admin_db()->query(
        'SELECT p.id, p.farmer_id, p.name, p.category, p.price, p.qty, p.unit, p.icon, p.photo, p.harvest_date, f.name AS farmer_name
         FROM products p JOIN farmers f ON f.id = p.farmer_id
         WHERE f.status = "approved" AND p.qty > 0
         ORDER BY p.created_at DESC, p.id DESC'
    )->fetchAll();
    $products = array_map(fn($r) => [
        'id' => $r['id'], 'farmerId' => $r['farmer_id'], 'name' => $r['name'], 'farmer' => $r['farmer_name'],
        'cat' => CAT_LABEL[$r['category']] ?? $r['category'], 'price' => (float)$r['price'],
        'unit' => UNIT_LABEL[$r['unit']] ?? $r['unit'], 'qty' => (int)$r['qty'], 'icon' => $r['icon'],
        'photo' => $r['photo'], 'harvestDate' => $r['harvest_date'], 'fromFarmer' => true,
    ], $rows);
    out(['ok' => true, 'products' => $products]);

/* ---------- Place an order ---------- */
case 'order_create':
    $name    = trim((string)($in['name'] ?? ''));
    $phone   = trim((string)($in['phone'] ?? ''));
    $address = trim((string)($in['address'] ?? ''));
    $pay     = ($in['pay'] ?? '') === 'GCash' ? 'GCash' : 'COD';
    $raw     = $in['items'] ?? [];
    if ($name === '' || $phone === '' || $address === '') fail('Pakikompleto ang pangalan, numero, at address bago magpatuloy.');
    if (!is_array($raw) || !$raw || count($raw) > 100) fail('Walang laman ang basket.');

    $cdb = db(); $adb = admin_db();
    $adb->beginTransaction(); $cdb->beginTransaction();
    $abort = function (string $msg, int $code = 400, array $extra = []) use ($adb, $cdb) {
        if ($adb->inTransaction()) $adb->rollBack();
        if ($cdb->inTransaction()) $cdb->rollBack();
        fail($msg, $code, $extra);
    };

    // 1) Check and price every item. Farmer products are priced from the database, not from the browser.
    $lines = []; $byFarmer = []; $subtotal = 0.0; $seen = [];
    foreach ($raw as $it) {
        $pid = substr((string)($it['id'] ?? ''), 0, 20);
        $q   = (int)($it['qty'] ?? 0);
        if ($pid === '' || $q <= 0 || $q > 1000 || isset($seen[$pid])) $abort('May maling item sa basket.');
        $seen[$pid] = true;

        if (!empty($it['fromFarmer'])) {
            $st = $adb->prepare('SELECT p.*, f.status AS fstatus FROM products p JOIN farmers f ON f.id = p.farmer_id WHERE p.id = ? FOR UPDATE');
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p || $p['fstatus'] !== 'approved')
                $abort('Wala na ang paninda na "' . (string)($it['name'] ?? '') . '".', 409, ['refresh' => true]);
            if ((int)$p['qty'] < $q)
                $abort('Kulang ang stock ng "' . $p['name'] . '". Natitira: ' . (int)$p['qty'] . '.', 409, ['refresh' => true]);
            $line = ['pid' => $p['id'], 'name' => $p['name'], 'icon' => $p['icon'], 'unit' => UNIT_LABEL[$p['unit']] ?? $p['unit'],
                     'price' => (float)$p['price'], 'qty' => $q, 'fid' => $p['farmer_id']];
        } else {   // built-in sample product: only saved in the customer database
            $price = (float)($it['price'] ?? 0);
            $pname = mb_substr(trim((string)($it['name'] ?? '')), 0, 120);
            $unit  = mb_substr(trim((string)($it['unit'] ?? '')), 0, 20);
            if ($price <= 0 || $price > 100000 || $pname === '' || $unit === '') $abort('May maling item sa basket.');
            $line = ['pid' => $pid, 'name' => $pname, 'icon' => mb_substr((string)($it['icon'] ?? ''), 0, 10), 'unit' => $unit,
                     'price' => $price, 'qty' => $q, 'fid' => null];
        }
        $subtotal += $q * $line['price'];
        $lines[] = $line;
        if ($line['fid']) $byFarmer[$line['fid']][] = $line;
    }
    if (count($byFarmer) > 26) $abort('Masyadong maraming farmer sa isang order.');
    $delivery = delivery_fee($subtotal);
    $total    = $subtotal + $delivery;

    // 2) Save the order in the customer database
    $token = bin2hex(random_bytes(8));
    $cdb->prepare('INSERT INTO customer_orders (access_token, customer_name, phone, address, pay_method, subtotal, delivery_fee, total) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$token, mb_substr($name, 0, 120), mb_substr($phone, 0, 30), mb_substr($address, 0, 255), $pay, $subtotal, $delivery, $total]);
    $oid  = (int)$cdb->lastInsertId();
    $code = 'SF-' . (100000 + $oid);
    $cdb->prepare('UPDATE customer_orders SET order_code = ? WHERE id = ?')->execute([$code, $oid]);

    $insItem = $cdb->prepare('INSERT INTO customer_order_items (order_id, product_id, name, icon, unit, price, qty, sent_to_farmer) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($lines as $l) $insItem->execute([$oid, $l['pid'], $l['name'], $l['icon'], $l['unit'], $l['price'], $l['qty'], $l['fid'] ? 1 : 0]);

    // 3) Send one order to each farmer (agrimart_admin) and reduce the stock
    $fids = array_keys($byFarmer);
    foreach ($fids as $i => $fid) {
        $sid = count($fids) > 1 ? $code . '-' . chr(65 + $i) : $code;
        $subSub = 0.0;
        foreach ($byFarmer[$fid] as $l) $subSub += $l['qty'] * $l['price'];
        $adb->prepare('INSERT INTO orders (id, buyer_name, farmer_id, total, delivery_fee, status, mode, phone, address, pay_method) VALUES (?,?,?,?,?,"Pending","Delivery",?,?,?)')
            ->execute([$sid, mb_substr($name, 0, 120), $fid, $subSub, $i === 0 ? $delivery : 0, mb_substr($phone, 0, 30), mb_substr($address, 0, 255), $pay]);
        foreach ($byFarmer[$fid] as $l) {
            $adb->prepare('INSERT INTO order_items (order_id, product_id, name, qty, price_each) VALUES (?,?,?,?,?)')
                ->execute([$sid, $l['pid'], $l['name'], $l['qty'], $l['price']]);
            $adb->prepare('UPDATE products SET qty = qty - ? WHERE id = ?')->execute([$l['qty'], $l['pid']]);
        }
        $cdb->prepare('INSERT INTO customer_farmer_orders (order_id, farmer_order_id) VALUES (?,?)')->execute([$oid, $sid]);
    }

    // Two separate databases cannot share one transaction: farmer side first, then the customer side.
    $adb->commit();
    $cdb->commit();

    [$list, $statusMap] = load_orders([['code' => $code, 'token' => $token]]);
    out(['ok' => true, 'order' => $list[0], 'token' => $token, 'statusMap' => $statusMap]);

/* ---------- The customer's own orders + current status ---------- */
case 'my_orders':
    [$list, $statusMap] = load_orders(is_array($in['orders'] ?? null) ? $in['orders'] : []);
    out(['ok' => true, 'orders' => $list, 'statusMap' => $statusMap]);

default:
    fail('Unknown action.', 404);
}
} catch (Throwable $e) {
    if ($adb && $adb->inTransaction()) $adb->rollBack();
    if ($cdb && $cdb->inTransaction()) $cdb->rollBack();
    error_log('[agrimart customer] ' . $e->getMessage());
    fail('Server error. Check that MySQL is running and that both databases were imported (agrimart_admin and agrimart_customer).', 500);
}
