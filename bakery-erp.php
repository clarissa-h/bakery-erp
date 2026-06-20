<?php
ini_set('display_errors', 0);
ini_set('log_errors',     1);
error_reporting(E_ALL);

// ── Database credentials ──────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'bakery-erp');
define('DB_USER',    'root');   // ← change me
define('DB_PASS',    '');       // ← change me
define('DB_CHARSET', 'utf8mb4');

// CORS: set to your domain in production, e.g. 'https://yourdomain.com'
// Leave empty to allow all origins (fine for local dev).
define('ALLOWED_ORIGIN', '');

// ── Session ───────────────────────────────────────────────────
session_start();

// ─────────────────────────────────────────────────────────────
//  API MODE  — runs only when ?resource= is present
// ─────────────────────────────────────────────────────────────
if (isset($_GET['resource'])) {

    header('Content-Type: application/json; charset=utf-8');
    $ao = ALLOWED_ORIGIN ?: '*';
    header('Access-Control-Allow-Origin: ' . $ao);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    // ── Database connection (singleton) ──────────────────────
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $pdo;
    }

    // ── Response helpers ─────────────────────────────────────
    function respond(mixed $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode(['status' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    function respond_error(string $msg, int $status = 400): void {
        http_response_code($status);
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    function respond_list(array $rows, int $total = 0): void {
        http_response_code(200);
        echo json_encode(
            ['status' => 'ok', 'total' => $total ?: count($rows), 'data' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }
    function method(): string { return strtoupper($_SERVER['REQUEST_METHOD']); }
    function body(): array    { return (array) json_decode(file_get_contents('php://input'), true); }
    function require_body(array $b, array $fields): void {
        foreach ($fields as $f) {
            if (!isset($b[$f]) || (is_string($b[$f]) && trim($b[$f]) === ''))
                respond_error("Field '$f' is required.");
        }
    }
    function ip(string $k, int $d = 0): int       { return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }
    function sp(string $k, string $d = ''): string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

    // ── SETTINGS ─────────────────────────────────────────────
    function h_settings(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            respond($pdo->query('SELECT * FROM settings LIMIT 1')->fetch());
        }
        if (method() === 'PUT') {
            $b = body();
            $pdo->prepare("UPDATE settings SET bakery_name=:bn,owner_name=:on,phone=:ph,address=:ad,
                tax_rate=:tr,currency=:cu,receipt_message=:rm,social_info=:si WHERE id=1")->execute([
                ':bn' => $b['bakery_name']    ?? '', ':on' => $b['owner_name'] ?? '',
                ':ph' => $b['phone']          ?? '', ':ad' => $b['address']    ?? '',
                ':tr' => $b['tax_rate']       ?? 10, ':cu' => $b['currency']   ?? 'IDR',
                ':rm' => $b['receipt_message']?? '', ':si' => $b['social_info']?? '',
            ]);
            respond(['updated' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── CUSTOMERS ────────────────────────────────────────────
    function h_customers(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($id > 0) {
                $s = $pdo->prepare('SELECT * FROM customers WHERE id=?'); $s->execute([$id]);
                $r = $s->fetch(); $r ? respond($r) : respond_error('Not found.', 404);
            }
            $q = sp('q'); $sql = 'SELECT * FROM customers'; $p = [];
            if ($q !== '') { $sql .= ' WHERE name LIKE :q OR phone LIKE :q2'; $p[':q'] = "%$q%"; $p[':q2'] = "%$q%"; }
            $sql .= ' ORDER BY total_orders DESC, name ASC';
            $s = $pdo->prepare($sql); $s->execute($p);
            respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['name', 'phone']);
            $s = $pdo->prepare("INSERT INTO customers (name,phone,instagram,birthday,allergies,notes) VALUES (:n,:p,:i,:b,:a,:no)");
            $s->execute([':n' => trim($b['name']), ':p' => trim($b['phone']), ':i' => $b['instagram'] ?? '',
                ':b' => ($b['birthday'] ?? '') ?: null, ':a' => $b['allergies'] ?? '', ':no' => $b['notes'] ?? '']);
            respond(['id' => (int)$pdo->lastInsertId()], 201);
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['name', 'phone']);
            $pdo->prepare("UPDATE customers SET name=:n,phone=:p,instagram=:i,birthday=:b,allergies=:a,notes=:no WHERE id=:id")
                ->execute([':n' => trim($b['name']), ':p' => trim($b['phone']), ':i' => $b['instagram'] ?? '',
                    ':b' => ($b['birthday'] ?? '') ?: null, ':a' => $b['allergies'] ?? '',
                    ':no' => $b['notes'] ?? '', ':id' => $id]);
            respond(['updated' => true]);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$id]);
            respond(['deleted' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── INVENTORY ────────────────────────────────────────────
    function h_inventory(int $id, string $action): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($action === 'low_stock')  { respond_list($pdo->query('SELECT * FROM v_low_stock')->fetchAll()); }
            if ($action === 'categories') { respond_list($pdo->query('SELECT * FROM ingredient_categories ORDER BY name')->fetchAll()); }
            if ($id > 0) {
                $s = $pdo->prepare("SELECT i.*,ic.name AS category_name FROM inventory i JOIN ingredient_categories ic ON ic.id=i.category_id WHERE i.id=?");
                $s->execute([$id]); $r = $s->fetch(); $r ? respond($r) : respond_error('Not found.', 404);
            }
            $q = sp('q'); $cat = ip('category_id');
            $sql = "SELECT i.*,ic.name AS category_name FROM inventory i JOIN ingredient_categories ic ON ic.id=i.category_id";
            $w = []; $p = [];
            if ($q !== '')  { $w[] = 'i.name LIKE :q';       $p[':q']   = "%$q%"; }
            if ($cat > 0)   { $w[] = 'i.category_id=:cat';   $p[':cat'] = $cat; }
            if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
            $sql .= ' ORDER BY ic.name,i.name';
            $s = $pdo->prepare($sql); $s->execute($p);
            respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['name', 'category_id']);
            $s = $pdo->prepare("INSERT INTO inventory (category_id,name,stock,unit,min_stock,cost_per_unit,supplier) VALUES (:ci,:n,:s,:u,:ms,:cpu,:sup)");
            $s->execute([':ci' => (int)$b['category_id'], ':n' => trim($b['name']),
                ':s' => (float)($b['stock'] ?? 0), ':u' => $b['unit'] ?? 'g',
                ':ms' => (float)($b['min_stock'] ?? 0), ':cpu' => (float)($b['cost_per_unit'] ?? 0),
                ':sup' => $b['supplier'] ?? '']);
            respond(['id' => (int)$pdo->lastInsertId()], 201);
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['name', 'category_id']);
            $pdo->prepare("UPDATE inventory SET category_id=:ci,name=:n,stock=:s,unit=:u,min_stock=:ms,cost_per_unit=:cpu,supplier=:sup WHERE id=:id")
                ->execute([':ci' => (int)$b['category_id'], ':n' => trim($b['name']),
                    ':s' => (float)($b['stock'] ?? 0), ':u' => $b['unit'] ?? 'g',
                    ':ms' => (float)($b['min_stock'] ?? 0), ':cpu' => (float)($b['cost_per_unit'] ?? 0),
                    ':sup' => $b['supplier'] ?? '', ':id' => $id]);
            respond(['updated' => true]);
        }
        if (method() === 'PATCH' && $action === 'restock') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['qty']);
            $qty = (float)$b['qty']; if ($qty <= 0) respond_error('Quantity must be positive.');
            $pdo->prepare('CALL sp_restock_ingredient(:id,:qty,:cost,:notes)')->execute([
                ':id' => $id, ':qty' => $qty, ':cost' => (float)($b['cost_total'] ?? 0), ':notes' => $b['notes'] ?? null]);
            $ns = $pdo->prepare('SELECT stock FROM inventory WHERE id=?'); $ns->execute([$id]);
            respond(['restocked' => true, 'new_stock' => (float)$ns->fetchColumn()]);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('DELETE FROM inventory WHERE id=?')->execute([$id]);
            respond(['deleted' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── PRODUCTS ─────────────────────────────────────────────
    function h_products(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($id > 0) {
                $s = $pdo->prepare("SELECT p.*,pc.name AS category_name FROM products p JOIN product_categories pc ON pc.id=p.category_id WHERE p.id=?");
                $s->execute([$id]); $r = $s->fetch(); $r ? respond($r) : respond_error('Not found.', 404);
            }
            $cat = ip('category_id'); $q = sp('q'); $all = sp('all');
            $sql = "SELECT p.*,pc.name AS category_name FROM products p JOIN product_categories pc ON pc.id=p.category_id";
            $w = []; $p = [];
            if ($all !== '1') { $w[] = 'p.is_active=1'; }
            if ($cat > 0)     { $w[] = 'p.category_id=:cat'; $p[':cat'] = $cat; }
            if ($q !== '')    { $w[] = 'p.name LIKE :q';      $p[':q']   = "%$q%"; }
            if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
            $sql .= ' ORDER BY pc.name,p.name';
            $s = $pdo->prepare($sql); $s->execute($p);
            respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['name', 'price', 'category_id']);
            $s = $pdo->prepare("INSERT INTO products (category_id,name,image_url,price,stock) VALUES (:ci,:n,:img,:pr,:s)");
            $s->execute([':ci' => (int)$b['category_id'], ':n' => trim($b['name']),
                ':img' => $b['image_url'] ?? '', ':pr' => (float)$b['price'], ':s' => (int)($b['stock'] ?? 0)]);
            respond(['id' => (int)$pdo->lastInsertId()], 201);
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['name', 'price', 'category_id']);
            $pdo->prepare("UPDATE products SET category_id=:ci,name=:n,image_url=:img,price=:pr,stock=:s,is_active=:ia WHERE id=:id")
                ->execute([':ci' => (int)$b['category_id'], ':n' => trim($b['name']),
                    ':img' => $b['image_url'] ?? '', ':pr' => (float)$b['price'],
                    ':s' => (int)($b['stock'] ?? 0), ':ia' => (int)($b['is_active'] ?? 1), ':id' => $id]);
            respond(['updated' => true]);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
            respond(['deactivated' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── RECIPES ──────────────────────────────────────────────
    function h_recipes(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($id > 0) {
                $s = $pdo->prepare("SELECT r.*,pc.name AS category_name,p.name AS product_name FROM recipes r LEFT JOIN product_categories pc ON pc.id=r.category_id LEFT JOIN products p ON p.id=r.product_id WHERE r.id=?");
                $s->execute([$id]); $recipe = $s->fetch();
                if (!$recipe) respond_error('Not found.', 404);
                $ri = $pdo->prepare("SELECT ri.*,i.name AS ingredient_name FROM recipe_ingredients ri JOIN inventory i ON i.id=ri.inventory_id WHERE ri.recipe_id=? ORDER BY i.name");
                $ri->execute([$id]); $recipe['ingredients'] = $ri->fetchAll();
                respond($recipe);
            }
            $sql = "SELECT r.*,pc.name AS category_name FROM recipes r LEFT JOIN product_categories pc ON pc.id=r.category_id WHERE r.is_active=1 ORDER BY r.name";
            respond_list($pdo->query($sql)->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['name']);
            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare("INSERT INTO recipes (product_id,category_id,name,yield_qty,yield_unit,prep_minutes,bake_minutes,instructions,notes) VALUES (:pi,:ci,:n,:yq,:yu,:pm,:bm,:ins,:no)");
                $s->execute([':pi' => ($b['product_id'] ?? null) ?: null, ':ci' => ($b['category_id'] ?? null) ?: null,
                    ':n'  => trim($b['name']), ':yq' => (int)($b['yield_qty'] ?? 1), ':yu' => $b['yield_unit'] ?? 'pcs',
                    ':pm' => (int)($b['prep_minutes'] ?? 0), ':bm' => (int)($b['bake_minutes'] ?? 0),
                    ':ins' => $b['instructions'] ?? '', ':no' => $b['notes'] ?? '']);
                $rid = (int)$pdo->lastInsertId();
                if (!empty($b['ingredients']) && is_array($b['ingredients'])) {
                    $is = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id,inventory_id,quantity,unit,notes) VALUES (:ri,:ii,:q,:u,:n)");
                    foreach ($b['ingredients'] as $ing) {
                        if (empty($ing['inventory_id'])) continue;
                        $is->execute([':ri' => $rid, ':ii' => (int)$ing['inventory_id'],
                            ':q' => (float)($ing['quantity'] ?? 0), ':u' => $ing['unit'] ?? 'g', ':n' => $ing['notes'] ?? '']);
                    }
                }
                $pdo->commit(); respond(['id' => $rid], 201);
            } catch (Throwable $e) { $pdo->rollBack(); respond_error('Failed: ' . $e->getMessage(), 500); }
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['name']);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE recipes SET product_id=:pi,category_id=:ci,name=:n,yield_qty=:yq,yield_unit=:yu,prep_minutes=:pm,bake_minutes=:bm,instructions=:ins,notes=:no WHERE id=:id")
                    ->execute([':pi' => ($b['product_id'] ?? null) ?: null, ':ci' => ($b['category_id'] ?? null) ?: null,
                        ':n'  => trim($b['name']), ':yq' => (int)($b['yield_qty'] ?? 1), ':yu' => $b['yield_unit'] ?? 'pcs',
                        ':pm' => (int)($b['prep_minutes'] ?? 0), ':bm' => (int)($b['bake_minutes'] ?? 0),
                        ':ins' => $b['instructions'] ?? '', ':no' => $b['notes'] ?? '', ':id' => $id]);
                if (isset($b['ingredients']) && is_array($b['ingredients'])) {
                    $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id=?')->execute([$id]);
                    $is = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id,inventory_id,quantity,unit,notes) VALUES (:ri,:ii,:q,:u,:n)");
                    foreach ($b['ingredients'] as $ing) {
                        if (empty($ing['inventory_id'])) continue;
                        $is->execute([':ri' => $id, ':ii' => (int)$ing['inventory_id'],
                            ':q' => (float)($ing['quantity'] ?? 0), ':u' => $ing['unit'] ?? 'g', ':n' => $ing['notes'] ?? '']);
                    }
                }
                $pdo->commit(); respond(['updated' => true]);
            } catch (Throwable $e) { $pdo->rollBack(); respond_error('Failed: ' . $e->getMessage(), 500); }
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('DELETE FROM recipes WHERE id=?')->execute([$id]);
            respond(['deleted' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── ORDERS ───────────────────────────────────────────────
    function h_orders(int $id, string $action): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($id > 0) {
                $s = $pdo->prepare('SELECT * FROM v_order_summary WHERE id=?'); $s->execute([$id]);
                $order = $s->fetch(); if (!$order) respond_error('Not found.', 404);
                $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id=?'); $items->execute([$id]);
                $order['items'] = $items->fetchAll(); respond($order);
            }
            $status = sp('status'); $date = sp('date'); $q = sp('q'); $type = sp('type');
            $limit  = max(1, ip('limit', 50)); $offset = max(0, ip('offset', 0));
            $sql = "SELECT v.*, GROUP_CONCAT(CONCAT(oi.item_name,' x',oi.qty) ORDER BY oi.id SEPARATOR ', ') AS items_summary
                     FROM v_order_summary v LEFT JOIN order_items oi ON oi.order_id = v.id WHERE 1"; $p = [];
            if ($status !== '') { $sql .= ' AND v.status=:s';  $p[':s'] = $status; }
            if ($date   !== '') { $sql .= ' AND v.pickup_date=:d'; $p[':d'] = $date; }
            if ($type   !== '') { $sql .= ' AND v.type=:t';    $p[':t'] = $type; }
            if ($q      !== '') { $sql .= ' AND (v.customer_name LIKE :q OR v.order_ref LIKE :q2)'; $p[':q'] = "%$q%"; $p[':q2'] = "%$q%"; }
            $sql .= ' GROUP BY v.id ORDER BY v.created_at DESC LIMIT :lim OFFSET :off';
            $s = $pdo->prepare($sql);
            foreach ($p as $k => $v) $s->bindValue($k, $v);
            $s->bindValue(':lim', $limit, PDO::PARAM_INT); $s->bindValue(':off', $offset, PDO::PARAM_INT);
            $s->execute(); respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); if (empty($b['items']) || !is_array($b['items'])) respond_error('Order needs items.');
            $pdo->beginTransaction();
            try {
                // MariaDB/PDO doesn't allow multiple statements in one query().
                // Split into: CALL (sets the user variable), then SELECT it separately.
                $pdo->exec('CALL sp_next_order_ref(@ref)');
                $order_ref = $pdo->query('SELECT @ref AS ref')->fetchColumn();
                $s = $pdo->prepare("INSERT INTO orders (order_ref,customer_id,guest_name,guest_phone,type,pickup_date,pickup_time,notes,subtotal,discount,tax_amount,total,deposit,status,payment_method) VALUES (:ref,:ci,:gn,:gp,:t,:pd,:pt,:no,:sub,:dis,:tax,:tot,:dep,:st,:pm)");
                $s->execute([':ref' => $order_ref, ':ci' => ($b['customer_id'] ?? null) ?: null,
                    ':gn' => $b['guest_name'] ?? '', ':gp' => $b['guest_phone'] ?? '',
                    ':t'  => $b['type'] ?? 'walkin', ':pd' => ($b['pickup_date'] ?? '') ?: null,
                    ':pt' => ($b['pickup_time'] ?? '') ?: null, ':no' => $b['notes'] ?? '',
                    ':sub' => (float)($b['subtotal'] ?? 0), ':dis' => (float)($b['discount'] ?? 0),
                    ':tax' => (float)($b['tax_amount'] ?? 0), ':tot' => (float)($b['total'] ?? 0),
                    ':dep' => (float)($b['deposit'] ?? 0), ':st' => $b['status'] ?? 'pending',
                    ':pm' => $b['payment_method'] ?? 'Cash']);
                $oid = (int)$pdo->lastInsertId();
                $is = $pdo->prepare("INSERT INTO order_items (order_id,product_id,item_name,image_url,qty,unit_price,line_total) VALUES (:oi,:pi,:in,:img,:q,:up,:lt)");
                foreach ($b['items'] as $item) {
                    $is->execute([':oi' => $oid, ':pi' => ($item['product_id'] ?? null) ?: null,
                        ':in' => $item['item_name'] ?? $item['name'] ?? '', ':img' => $item['image_url'] ?? '',
                        ':q'  => (int)($item['qty'] ?? 1), ':up' => (float)($item['unit_price'] ?? $item['price'] ?? 0),
                        ':lt' => (float)($item['line_total'] ?? 0)]);
                    if (!empty($item['product_id'])) {
                        $pdo->prepare("UPDATE products SET stock=GREATEST(0,stock-:q) WHERE id=:id")
                            ->execute([':q' => (int)$item['qty'], ':id' => (int)$item['product_id']]);
                    }
                }
                $pdo->commit(); respond(['id' => $oid, 'order_ref' => $order_ref], 201);
            } catch (Throwable $e) { $pdo->rollBack(); respond_error('Failed: ' . $e->getMessage(), 500); }
        }
        if (method() === 'PATCH' && $action === 'status') {
            if (!$id) respond_error('ID required.', 400); $b = body(); require_body($b, ['status']);
            $allowed = ['pending', 'in-progress', 'ready', 'completed', 'cancelled'];
            if (!in_array($b['status'], $allowed, true)) respond_error('Invalid status.');
            if ($b['status'] === 'completed') {
                $pdo->prepare('CALL sp_complete_order(?)')->execute([$id]);
            } else {
                $pdo->prepare("UPDATE orders SET status=?,updated_at=NOW() WHERE id=?")->execute([$b['status'], $id]);
            }
            respond(['updated' => true]);
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body();
            $pdo->prepare("UPDATE orders SET customer_id=:ci,guest_name=:gn,guest_phone=:gp,pickup_date=:pd,pickup_time=:pt,notes=:no,subtotal=:sub,discount=:dis,tax_amount=:tax,total=:tot,deposit=:dep,status=:st,payment_method=:pm WHERE id=:id")
                ->execute([':ci' => ($b['customer_id'] ?? null) ?: null, ':gn' => $b['guest_name'] ?? '',
                    ':gp' => $b['guest_phone'] ?? '', ':pd' => ($b['pickup_date'] ?? '') ?: null,
                    ':pt' => ($b['pickup_time'] ?? '') ?: null, ':no' => $b['notes'] ?? '',
                    ':sub' => (float)($b['subtotal'] ?? 0), ':dis' => (float)($b['discount'] ?? 0),
                    ':tax' => (float)($b['tax_amount'] ?? 0), ':tot' => (float)($b['total'] ?? 0),
                    ':dep' => (float)($b['deposit'] ?? 0), ':st' => $b['status'] ?? 'pending',
                    ':pm' => $b['payment_method'] ?? 'Cash', ':id' => $id]);
            respond(['updated' => true]);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$id]);
            respond(['cancelled' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── PRODUCTION ───────────────────────────────────────────
    function h_production(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            if ($id > 0) {
                $s = $pdo->prepare('SELECT * FROM production_batches WHERE id=?'); $s->execute([$id]);
                $r = $s->fetch(); $r ? respond($r) : respond_error('Not found.', 404);
            }
            $date = sp('date'); $status = sp('status'); $q = sp('q');
            $sql = 'SELECT pb.*,r.name AS recipe_name FROM production_batches pb LEFT JOIN recipes r ON r.id=pb.recipe_id WHERE 1';
            $p = [];
            if ($date   !== '') { $sql .= ' AND pb.batch_date=:d';         $p[':d'] = $date; }
            if ($status !== '') { $sql .= ' AND pb.status=:s';             $p[':s'] = $status; }
            if ($q      !== '') { $sql .= ' AND pb.product_name LIKE :q';  $p[':q'] = "%$q%"; }
            $sql .= ' ORDER BY pb.batch_date DESC,pb.id DESC';
            $s = $pdo->prepare($sql); $s->execute($p);
            respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['product_name', 'batch_date']);
            $pdo->beginTransaction();
            try {
                $recipeId    = ($b['recipe_id']     ?? null) ?: null;
                $productId   = ($b['product_id']    ?? null) ?: null;
                $qtyProduced = (int)($b['qty_produced']   ?? 0);
                $batchesCount = max(1, (int)($b['batches_count'] ?? 1));

                $s = $pdo->prepare("INSERT INTO production_batches (recipe_id,product_id,product_name,qty_produced,batches_count,baker,batch_date,start_time,end_time,status,notes) VALUES (:ri,:pi,:pn,:qp,:bc,:ba,:bd,:st,:et,:ss,:no)");
                $s->execute([':ri' => $recipeId, ':pi' => $productId,
                    ':pn' => trim($b['product_name']), ':qp' => $qtyProduced, ':bc' => $batchesCount,
                    ':ba' => $b['baker'] ?? '', ':bd' => $b['batch_date'],
                    ':st' => ($b['start_time'] ?? '') ?: null, ':et' => ($b['end_time'] ?? '') ?: null,
                    ':ss' => $b['status'] ?? 'in-progress', ':no' => $b['notes'] ?? '']);
                $batchId = (int)$pdo->lastInsertId();

                // Deduct inventory based on recipe ingredients × batches_count
                $deducted = [];
                if ($recipeId && $batchesCount > 0) {
                    $ings = $pdo->prepare('SELECT ri.inventory_id, ri.quantity, ri.unit, i.name FROM recipe_ingredients ri JOIN inventory i ON i.id=ri.inventory_id WHERE ri.recipe_id=?');
                    $ings->execute([$recipeId]);
                    foreach ($ings->fetchAll() as $ing) {
                        $needed = (float)$ing['quantity'] * $batchesCount;
                        $pdo->prepare('UPDATE inventory SET stock=GREATEST(0, stock-:n) WHERE id=:id')
                            ->execute([':n' => $needed, ':id' => $ing['inventory_id']]);
                        $deducted[] = ['name' => $ing['name'], 'qty' => round($needed, 2), 'unit' => $ing['unit']];
                    }
                }

                // Add produced qty to product stock
                $stockAdded = 0;
                if ($productId && $qtyProduced > 0) {
                    $pdo->prepare('UPDATE products SET stock=stock+:q WHERE id=:id')
                        ->execute([':q' => $qtyProduced, ':id' => $productId]);
                    $stockAdded = $qtyProduced;
                }

                $pdo->commit();
                respond(['id' => $batchId, 'inventory_deducted' => $deducted, 'stock_added' => $stockAdded], 201);
            } catch (Throwable $e) { $pdo->rollBack(); respond_error('Failed: ' . $e->getMessage(), 500); }
        }
        if (method() === 'PUT') {
            if (!$id) respond_error('ID required.', 400); $b = body();
            $pdo->prepare("UPDATE production_batches SET recipe_id=:ri,product_id=:pi,product_name=:pn,qty_produced=:qp,baker=:ba,batch_date=:bd,start_time=:st,end_time=:et,status=:ss,notes=:no WHERE id=:id")
                ->execute([':ri' => ($b['recipe_id'] ?? null) ?: null, ':pi' => ($b['product_id'] ?? null) ?: null,
                    ':pn' => trim($b['product_name'] ?? ''), ':qp' => (int)($b['qty_produced'] ?? 0),
                    ':ba' => $b['baker'] ?? '', ':bd' => $b['batch_date'] ?? date('Y-m-d'),
                    ':st' => ($b['start_time'] ?? '') ?: null, ':et' => ($b['end_time'] ?? '') ?: null,
                    ':ss' => $b['status'] ?? 'in-progress', ':no' => $b['notes'] ?? '', ':id' => $id]);
            respond(['updated' => true]);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('DELETE FROM production_batches WHERE id=?')->execute([$id]);
            respond(['deleted' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── EXPENSES ─────────────────────────────────────────────
    function h_expenses(int $id): void {
        $pdo = db();
        if (method() === 'GET') {
            $cat = sp('category'); $month = sp('month');
            $sql = 'SELECT * FROM expenses WHERE 1'; $p = [];
            if ($cat   !== '') { $sql .= ' AND category=:cat';                          $p[':cat'] = $cat; }
            if ($month !== '') { $sql .= " AND DATE_FORMAT(expense_date,'%Y-%m')=:m";   $p[':m']   = $month; }
            $sql .= ' ORDER BY expense_date DESC';
            $s = $pdo->prepare($sql); $s->execute($p);
            respond_list($s->fetchAll());
        }
        if (method() === 'POST') {
            $b = body(); require_body($b, ['description', 'amount', 'expense_date']);
            $s = $pdo->prepare("INSERT INTO expenses (category,description,amount,expense_date,reference,notes) VALUES (:c,:d,:a,:ed,:r,:n)");
            $s->execute([':c' => $b['category'] ?? 'Other', ':d' => trim($b['description']),
                ':a' => (float)$b['amount'], ':ed' => $b['expense_date'],
                ':r' => $b['reference'] ?? '', ':n' => $b['notes'] ?? '']);
            respond(['id' => (int)$pdo->lastInsertId()], 201);
        }
        if (method() === 'DELETE') {
            if (!$id) respond_error('ID required.', 400);
            $pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);
            respond(['deleted' => true]);
        }
        respond_error('Method not allowed.', 405);
    }

    // ── REPORTS ──────────────────────────────────────────────
    function h_reports(string $action): void {
        if (method() !== 'GET') respond_error('Method not allowed.', 405);
        $pdo = db(); $period = sp('period', 'month');
        $df = match ($period) {
            'week'  => 'AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)',
            'year'  => 'AND YEAR(created_at)=YEAR(CURDATE())',
            default => "AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')",
        };
        if ($action === 'summary' || $action === '') {
            $rev = $pdo->query("SELECT COUNT(*) AS num_orders,SUM(subtotal) AS total_subtotal,SUM(discount) AS total_discount,SUM(tax_amount) AS total_tax,SUM(total) AS total_revenue FROM orders WHERE status!='cancelled' $df")->fetch();
            $exp = $pdo->query("SELECT SUM(amount) FROM expenses WHERE " . match ($period) {
                'week'  => 'expense_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)',
                'year'  => 'YEAR(expense_date)=YEAR(CURDATE())',
                default => "DATE_FORMAT(expense_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')",
            })->fetchColumn();
            $revenue = (float)($rev['total_revenue'] ?? 0); $expenses = (float)($exp ?? 0);
            respond(['period' => $period, 'num_orders' => (int)($rev['num_orders'] ?? 0),
                'total_revenue' => $revenue, 'total_expenses' => $expenses,
                'gross_profit'  => $revenue - $expenses,
                'margin_pct'    => $revenue > 0 ? round(($revenue - $expenses) / $revenue * 100, 1) : 0]);
        }
        if ($action === 'top_products') {
            respond_list($pdo->query("SELECT oi.item_name,oi.image_url,SUM(oi.qty) AS total_qty,SUM(oi.line_total) AS total_revenue FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status!='cancelled' GROUP BY oi.item_name,oi.image_url ORDER BY total_revenue DESC LIMIT 10")->fetchAll());
        }
        if ($action === 'expenses_by_category') {
            respond_list($pdo->query('SELECT * FROM v_monthly_expenses LIMIT 24')->fetchAll());
        }
        if ($action === 'daily_revenue') {
            respond_list($pdo->query("SELECT DATE(created_at) AS sale_date,COUNT(*) AS num_orders,SUM(total) AS daily_revenue FROM orders WHERE status!='cancelled' AND created_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY sale_date DESC")->fetchAll());
        }
        respond_error('Unknown report action.', 400);
    }

    // ── DASHBOARD ────────────────────────────────────────────
    function h_dashboard(): void {
        if (method() !== 'GET') respond_error('Method not allowed.', 405);
        $pdo = db(); $today = date('Y-m-d');
        $tr = $pdo->prepare("SELECT COUNT(*) AS num,COALESCE(SUM(total),0) AS revenue FROM orders WHERE status!='cancelled' AND DATE(created_at)=?");
        $tr->execute([$today]); $tr = $tr->fetch();
        $ti = $pdo->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status!='cancelled' AND DATE(o.created_at)=?");
        $ti->execute([$today]); $ti = $ti->fetchColumn();
        $pending  = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN('pending','in-progress')")->fetchColumn();
        $lowcount = $pdo->query('SELECT COUNT(*) FROM v_low_stock')->fetchColumn();
        $recent   = $pdo->query('SELECT * FROM v_order_summary ORDER BY created_at DESC LIMIT 5')->fetchAll();
        $lowitems = $pdo->query('SELECT * FROM v_low_stock')->fetchAll();
        $todprod  = $pdo->prepare("SELECT * FROM production_batches WHERE batch_date=? ORDER BY id DESC");
        $todprod->execute([$today]);
        respond([
            'today_revenue'    => (float)$tr['revenue'],
            'today_orders'     => (int)$tr['num'],
            'today_items'      => (int)$ti,
            'pending_orders'   => (int)$pending,
            'low_stock_count'  => (int)$lowcount,
            'recent_orders'    => $recent,
            'low_stock_items'  => $lowitems,
            'today_production' => $todprod->fetchAll(),
        ]);
    }

    // ── Auto-migration: add batches_count column if missing ──────
    try {
        db()->exec("ALTER TABLE production_batches ADD COLUMN batches_count INT NOT NULL DEFAULT 1");
    } catch (Throwable $_) { /* column already exists – ignore */ }

    // ── Router — placed here so all handler functions above are defined first ──
    $resource = sp('resource');
    $id       = ip('id');
    $action   = sp('action');

    try {
        match ($resource) {
            'settings'   => h_settings($id),
            'customers'  => h_customers($id),
            'inventory'  => h_inventory($id, $action),
            'products'   => h_products($id),
            'recipes'    => h_recipes($id),
            'orders'     => h_orders($id, $action),
            'production' => h_production($id),
            'expenses'   => h_expenses($id),
            'reports'    => h_reports($action),
            'dashboard'  => h_dashboard(),
            default      => respond_error("Unknown resource: $resource", 404),
        };
    } catch (PDOException $e) {
        respond_error('Database error: ' . $e->getMessage(), 500);
    } catch (Throwable $e) {
        respond_error('Server error: '   . $e->getMessage(), 500);
    }

    exit; // Safety net — all handler functions already exit via respond*()

} // end API MODE
// ─────────────────────────────────────────────────────────────
//  FRONTEND MODE  — falls through to here for normal page loads
// ─────────────────────────────────────────────────────────────

// ── Locale helpers used server-side ──────────────────────────
setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'Indonesian');

/**
 * Format today's date in Indonesian long form.
 * e.g.  "Rabu, 4 Juni 2025"
 */
function phpTodayLabel(): string {
    $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $months = [
        1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
        5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
        9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
    ];
    $now = new DateTime('now');
    return $days[(int)$now->format('w')] . ', '
         . (int)$now->format('j') . ' '
         . $months[(int)$now->format('n')] . ' '
         . $now->format('Y');
}

/**
 * ISO date string for today (YYYY-MM-DD).
 */
function phpToday(): string {
    return (new DateTime('now'))->format('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mocardi — Bakery Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --primary:      #DD5D7E;
  --primary-dark: #BB3859;
  --primary-light:#E8899A;
  --pink-bg:      #FDF0F3;
  --pink-soft:    #FEEEF1;
  --pink-border:  #F2C4CC;
  --white:        #FFFFFF;
  --text-main:    #2D1219;
  --text-sub:     #7A4050;
  --text-muted:   #B08090;
  --sidebar-bg:   #9E2A47;
  --sidebar-w:    210px;
  --green:        #5BA882;
  --amber:        #C49A3C;
  --red-alert:    #C23B5E;
  --border:       #F2C4CC;
  --shadow:       0 1px 10px rgba(194,59,94,0.08);
  --shadow-md:    0 4px 24px rgba(194,59,94,0.12);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;background:var(--pink-bg);color:var(--text-main);min-height:100vh;display:flex;font-size:13px;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);background:var(--sidebar-bg);height:100vh;position:fixed;left:0;top:0;display:flex;flex-direction:column;overflow:hidden;z-index:100;}
.sidebar-logo{padding:24px 20px 18px;}
.logo-wordmark{font-size:26px;font-weight:700;color:#fff;letter-spacing:0.01em;line-height:1.1;}
.logo-tagline{font-size:9px;color:rgba(255,255,255,0.5);letter-spacing:0.15em;text-transform:uppercase;margin-top:3px;}
.sidebar-nav{flex:1;padding:8px 0;overflow-y:auto;min-height:0;}
.db-status{display:flex;align-items:center;gap:7px;padding:7px 14px;font-size:11px;border-radius:20px;margin:0 12px 10px;border:1px solid transparent;}
.db-status.connected{background:rgba(194,59,94,0.2);color:#f4a0b0;border-color:rgba(194,59,94,0.35);}
.db-status.disconnected{background:rgba(194,59,94,0.2);color:#f4a0b0;border-color:rgba(194,59,94,0.35);}
.db-status.checking{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.5);}
.db-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.nav-section{padding:10px 20px 4px;font-size:9px;color:rgba(255,255,255,0.35);letter-spacing:0.13em;text-transform:uppercase;margin-top:4px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 16px;cursor:pointer;border-radius:10px;margin:2px 10px;color:rgba(255,255,255,0.6);font-size:12.5px;font-weight:400;transition:all 0.15s;user-select:none;}
.nav-item:hover{background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.95);}
.nav-item.active{background:var(--primary);color:#fff;font-weight:600;}
.nav-icon{width:16px;text-align:center;flex-shrink:0;font-size:13px;}
.sidebar-footer{padding:14px;}
.user-badge{background:rgba(255,255,255,0.1);border-radius:12px;padding:11px 13px;display:flex;align-items:center;gap:10px;}
.user-avatar{width:34px;height:34px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0;}
.user-name{font-size:12.5px;color:#fff;font-weight:600;}
.user-role{font-size:10px;color:rgba(255,255,255,0.4);margin-top:1px;}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;min-height:100vh;display:flex;flex-direction:column;}
.topbar{background:var(--white);border-bottom:1px solid var(--border);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.page-title{font-size:18px;font-weight:700;color:#CC2550;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.date-chip{display:flex;align-items:center;gap:7px;font-size:12px;color:#DC3D67;background:var(--pink-bg);padding:6px 14px;border-radius:8px;border:1px solid var(--border);}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:8px 18px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all 0.15s;}
.btn:disabled{opacity:0.5;cursor:not-allowed;}
.btn-primary{background:var(--primary-dark);color:#fff;}
.btn-primary:hover:not(:disabled){background:var(--primary-dark);}
.btn-outline{background:transparent;color:var(--primary);border:1.5px solid var(--border);}
.btn-outline:hover:not(:disabled){background:var(--pink-bg);}
.btn-danger{background:#c23b5e;color:#fff;}
.btn-danger:hover:not(:disabled){background:#9e2a47;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover:not(:disabled){background:#3d8a65;}
.btn-sm{padding:5px 13px;font-size:11.5px;}

/* ── PAGES ── */
.page{display:none;padding:16px 28px;}
.page.active{display:block;}
#page-pos.active{display:flex;flex-direction:column;padding:16px 28px;height:calc(100vh - 60px);overflow:hidden;}

/* ── CARDS ── */
.card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.card-title{font-size:14px;font-weight:700;color:var(--text-main);margin-bottom:16px;}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:16px;box-shadow:var(--shadow);}
.stat-icon-wrap{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;background:#FEEEF1;}
.stat-label{font-size:12px;color:#7A4050;font-weight:500;letter-spacing:0.04em;margin-bottom:6px;text-transform: uppercase;}
.stat-value{font-size:20px;font-weight:700;color:var(--primary);line-height:1.2;margin-bottom:6px;}
.stat-sub{font-size:11px;color:#B08090;line-height:1.5;}

/* ── INPUT ── */
.filter-input{height:44px;padding:0 16px;border:1.5px solid var(--border);border-radius:14px;font-family:inherit;font-size:14px;color:var(--text-main);background:#fff;display:flex;align-items:center;min-width:190px;}

/* ── GRID ── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.mb-16{margin-bottom:16px;}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{text-align:left;padding:10px 13px;font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.09em;border-bottom:1.5px solid var(--border);background:var(--pink-bg);}
td{padding:11px 13px;border-bottom:1px solid var(--border);color:var(--text-main);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--pink-bg);}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:600;letter-spacing:0.02em;}
.badge-green{background:#E6F4EC;color:#2e7d52;}
.badge-amber{background:#FEF3E0;color:#8a5e00;}
.badge-red{background:#FDE8EF;color:#9E2A47;}
.badge-blue{background:#E8F0FE;color:#1a56c4;}
.badge-pink{background:var(--pink-soft);color:var(--primary);}
.badge-muted{background:var(--pink-bg);color:var(--text-sub);}

/* ── FORMS ── */
.form-group{margin-bottom:13px;}
label{display:block;font-size:11px;font-weight:600;color:var(--text-sub);margin-bottom:4px;letter-spacing:0.01em;}
input,select,textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:12.5px;color:var(--text-main);background:#fff;transition:border 0.15s;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(194,59,94,0.08);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}

/* ── DASHBOARD HERO ── */
.dash-hero{background:url('images/banner.png');background-position:center;background-size:cover;background-repeat:no-repeat;border-radius:16px;padding:28px 32px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;}
.dash-hero-title{font-size:28px;font-weight:650;color:#CC2550;line-height:1.1;letter-spacing:-1px;margin-bottom:8px;}
.dash-hero-sub{font-size:13px;font-weight:400;color:#8B8B8B;line-height:1.5;}

/* ── RECENT ORDER ITEM ── */
.recent-order-item{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--border);}
.recent-order-item:last-child{border-bottom:none;}
.order-avatar{width:36px;height:36px;border-radius:10px;background:var(--pink-soft);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.order-name{font-size:13px;font-weight:600;color:var(--text-main);}
.order-ref{font-size:11px;color:var(--text-muted);}

/* ── POS ── */
.pos-layout{display:grid;grid-template-columns:1fr 320px;gap:16px;height:calc(100vh - 90px);}
.pos-products{background:var(--white);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;height:100%;overflow:hidden;}
.pos-products-scroll{flex:1;overflow-y:auto;min-height:0;}
.pos-search-wrap{padding:14px 16px;border-bottom:1px solid var(--border);flex-shrink:0;}
.pos-search-input{display:flex;align-items:center;gap:10px;background:white;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;}
.pos-search-input i{color:var(--text-muted);font-size:13px;}
.pos-search-input input{border:none;background:transparent;font-family:inherit;font-size:13px;color:var(--text-main);flex:1;outline:none;padding:0;}
.pos-cats{display:flex;gap:7px;padding:12px 16px;border-bottom:1px solid var(--border);overflow-x:auto;flex-shrink:0;}
.cat-pill{flex-shrink:0;padding:6px 14px;border-radius:20px;font-size:12px;cursor:pointer;border:1.5px solid var(--border);color:var(--text-sub);background:transparent;font-family:inherit;transition:all 0.15s;white-space:nowrap;font-weight:500;}
.cat-pill.active,.cat-pill:hover{background:var(--primary-dark);color:#fff;border-color:var(--primary-dark);}
.pos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;padding:14px 16px;align-content:start;}
.product-tile{background:var(--white);border:1.5px solid var(--border);border-radius:12px;padding:0;cursor:pointer;transition:all 0.15s;overflow:hidden;display:flex;flex-direction:column;}
.product-tile:hover{border-color:var(--primary);box-shadow:0 0 0 3px rgba(194,59,94,0.08);}
.product-tile.out-of-stock{opacity:0.45;cursor:not-allowed;}
.tile-img{width:100%;aspect-ratio:4/3;background:var(--pink-bg);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.tile-img img{width:100%;height:100%;object-fit:cover;}
.tile-img .no-img{display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;gap:4px;}
.tile-img .no-img i{font-size:22px;color:var(--pink-border);}
.tile-body{padding:10px 12px;}
.tile-name{font-size:12px;font-weight:600;color:var(--text-main);line-height:1.3;}
.tile-price{font-size:13px;font-weight:700;color:var(--primary);margin-top:2px;}
.tile-stock{font-size:10px;color:var(--text-muted);margin-top:1px;}

/* ── CART ── */
.pos-cart{background:var(--white);border:1px solid var(--border);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:var(--shadow);min-height:0;}
.cart-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--primary-dark);}
.cart-header-title{font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px;}
.cart-clear-btn{background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);color:#fff;border-radius:7px;padding:4px 12px;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;}
.cart-clear-btn:hover{background:rgba(255,255,255,0.25);}
.cart-items{flex:1;overflow-y:auto;padding:8px 0;}
.cart-item{display:flex;align-items:center;padding:13px 15px;gap:10px;border-bottom:1px solid var(--border);}
.cart-item:last-child{border-bottom:none;}
.cart-item-img{width:50px;height:50px;border-radius:8px;background:var(--pink-bg);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;}
.cart-item-img img{width:100%;height:100%;object-fit:cover;}
.cart-item-name{flex:1;font-size:12.5px;font-weight:500;line-height:1.3;}
.cart-item-price{font-size:12px;color:black;min-width:60px;text-align:right;font-weight:600;}
.qty-ctrl{display:flex;align-items:center;gap:4px;}
.qty-btn{width:22px;height:22px;border:none;border-radius:6px;background:#FCECEF;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;color:var(--primary);transition:0.15s;}
.qty-btn:hover{background:#F7D6DE;}
.qty-num{width:24px;height:22px;border-radius:6px;background:#fff;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--text-main);}
.cart-footer{padding:14px 18px;border-top:1px solid var(--border);background:white;}
.cart-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:12.5px;color:var(--text-sub);}
.cart-total-row{display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:var(--primary);margin:10px 0;padding-top:8px;border-top:1px solid var(--border);}
.cart-customer{margin-bottom:10px;}
.cart-customer input{font-size:12.5px;}
.cart-actions{display:flex;gap:8px;}
.cart-empty{text-align:center;padding:44px 20px;color:var(--text-muted);}
.cart-empty i{font-size:40px;color:var(--pink-border);margin-bottom:10px;}
.cart-item{display:flex;align-items:flex-start;gap:12px;}
.cart-item-info{flex:1;display:flex;flex-direction:column;gap:8px;}

/*__ KATALOG PRODUK __*/
.settings-product{display:flex;align-items:center;gap:12px;}
.settings-product-img{width:46px;height:46px;border-radius:10px;overflow:hidden;background:var(--pink-bg);flex-shrink:0;}
.settings-product-img img{width:100%;height:100%;object-fit:cover;}

/* ── RECIPE CARDS ── */
.recipes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;}
.recipe-card{background:var(--white);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.recipe-img{width:100%;aspect-ratio:16/9;background:var(--pink-bg);display:flex;align-items:center;justify-content:center;overflow:hidden;}
.recipe-img img{width:100%;height:100%;object-fit:cover;}
.recipe-img .no-img-placeholder{width:100%;height:100%;background:linear-gradient(135deg,#f5e6ea,#fadadd);display:flex;align-items:center;justify-content:center;}
.recipe-img .no-img-placeholder i{font-size:28px;color:var(--pink-border);}
.recipe-body{padding:16px 20px;}
.recipe-name{font-size:15px;font-weight:700;color:var(--primary-dark);margin-bottom:20px;}
.recipe-meta{display:flex;align-items:center;gap:30px;margin-bottom:20px;}
.recipe-meta-item{display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-sub);}
.recipe-meta-item i{color:var(--primary-dark);font-size:16px;}
.recipe-meta-item strong{font-weight:600;color:var(--text-main)}
.recipe-meta-item span{display:block;font-size:11px;color:var(--text-muted);margin-top:0.5px;}
.recipe-cara{background:var(--pink-bg);border-radius:10px;padding:12px;margin-bottom:12px;}
.recipe-cara-title{font-size:11px;font-weight:700;color:var(--primary);margin-bottom:6px;}
.recipe-cara ol{padding-left:16px;font-size:11.5px;color:var(--text-sub);line-height:1.8;}
.recipe-footer{padding-top:4px;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(45,18,25,0.45);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px;width:500px;max-width:96vw;max-height:92vh;overflow-y:auto;position:relative;box-shadow:var(--shadow-md);}
.modal-title{font-size:18px;font-weight:700;color:var(--text-main);margin-bottom:18px;}
.modal-close{position:absolute;top:16px;right:16px;background:var(--pink-bg);border:none;cursor:pointer;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--text-muted);}
.modal-close:hover{background:var(--pink-soft);color:var(--primary);}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;border-top:1px solid var(--border);padding-top:16px;}

/* ── RECEIPT ── */
.receipt{font-size:12px;line-height:1.9;border:1px dashed var(--border);border-radius:10px;padding:16px;background:var(--pink-bg);}
.receipt-title{text-align:center;font-size:16px;font-weight:700;color:var(--primary);}
.receipt-sub{text-align:center;font-size:11px;color:var(--text-muted);margin-bottom:4px;}
.receipt-divider{border:none;border-top:1px dashed var(--border);margin:7px 0;}
.receipt-row{display:flex;justify-content:space-between;}
.receipt-total{font-weight:700;font-size:14px;color:var(--primary);}

/* ── INVENTORY ── */
.pagination{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:16px;}
.page-btn{min-width:32px;height:32px;border:1px solid var(--border);background:white;border-radius:8px;cursor:pointer;font-size:12px;transition:.2s;}
.page-btn:hover{background:var(--pink-bg);}
.page-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
.page-btn:disabled{opacity:.4;cursor:not-allowed;}

/* ── CHART ── */
.chart-bar-wrap{display:flex;flex-direction:column;gap:18px;}
.chart-row{display:flex;align-items:center;gap:14px;font-size:12px;padding:4px 0;}
.chart-label{width:120px;color:var(--text-muted);text-align:right;flex-shrink:0;font-size:11px;}
.chart-track{flex:1;background:var(--pink-bg);border-radius:5px;height:22px;overflow:hidden;}
.chart-fill{height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-light));border-radius:5px;display:flex;align-items:center;padding-left:8px;min-width:2px;transition:width 0.5s ease;}
.chart-fill span{font-size:9px;color:#fff;font-weight:400;white-space:nowrap;}
.chart-fill.green-fill{background:var(--green);}
.chart-fill.amber-fill{background:var(--amber);}

/* ── SECTION HEADER ── */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.section-title{font-size:17px;font-weight:700;color:var(--text-main);}

/* ── SKELETON ── */
.skeleton{background:linear-gradient(90deg,var(--pink-bg) 25%,var(--pink-soft) 50%,var(--pink-bg) 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:7px;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.skeleton-row{height:40px;margin-bottom:6px;}
.loading-spin{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,0.35);border-top-color:#fff;border-radius:50%;animation:spin 0.7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:44px 24px;color:var(--text-muted);}
.empty-icon{font-size:44px;margin-bottom:12px;opacity:0.4;}
.empty-title{font-size:14px;font-weight:600;margin-bottom:4px;color:var(--text-sub);}

/* ── TOAST ── */
#toast{position:fixed;bottom:24px;right:24px;background:var(--primary-dark);color:#fff;padding:11px 20px;border-radius:10px;font-size:13px;font-weight:500;z-index:999;transform:translateY(80px);opacity:0;transition:all 0.3s;box-shadow:var(--shadow-md);max-width:320px;}
#toast.show{transform:translateY(0);opacity:1;}
#toast.error{background:#a93030;}
#toast.success{background:#3a7a55;}

.stock-low{color:#a93030;font-weight:700;}
.stock-ok{color:var(--green);font-weight:600;}
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
.mt-14{margin-top:14px;}
.mb-14{margin-bottom:14px;}

/* ── PREORDER MODAL CART PICKER ── */
.po-modal{width:680px;max-width:97vw;}
.po-layout{display:grid;grid-template-columns:1fr 280px;gap:14px;min-height:280px;}
.po-existing-orders{display:flex;flex-direction:column;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;}
.po-existing-head{padding:10px 13px;background:var(--pink-bg);color:var(--text-sub);font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border);flex-shrink:0;}
.po-existing-list{flex:1;overflow-y:auto;max-height:260px;}
.po-existing-item{padding:9px 12px;border-bottom:1px solid var(--border);font-size:11.5px;}
.po-existing-item:last-child{border-bottom:none;}
.po-existing-ref{font-size:10.5px;font-weight:700;color:var(--primary);margin-bottom:2px;}
.po-existing-name{font-weight:600;color:var(--text-main);margin-bottom:1px;font-size:12px;}
.po-existing-meta{color:var(--text-muted);font-size:10.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px;}
.po-existing-items{margin-top:4px;display:flex;flex-direction:column;gap:2px;}
.po-existing-item-row{display:flex;align-items:center;justify-content:space-between;background:var(--pink-bg);border-radius:6px;padding:3px 8px;}
.po-existing-item-row span:first-child{font-size:11px;color:var(--text-sub);flex:1;}
.po-existing-item-row strong{font-size:11px;color:var(--primary-dark);white-space:nowrap;}
.po-products{display:flex;flex-direction:column;gap:0;overflow:hidden;border:1.5px solid var(--border);border-radius:12px;}
.po-prod-search{display:flex;align-items:center;gap:8px;padding:10px 13px;border-bottom:1px solid var(--border);background:var(--pink-bg);}
.po-prod-search i{color:var(--text-muted);font-size:12px;}
.po-prod-search input{border:none;background:transparent;font-family:inherit;font-size:12.5px;color:var(--text-main);flex:1;outline:none;padding:0;}
.po-prod-list{flex:1;overflow-y:auto;max-height:280px;}
.po-prod-item{display:flex;align-items:center;gap:10px;padding:9px 13px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .12s;}
.po-prod-item:last-child{border-bottom:none;}
.po-prod-item:hover{background:var(--pink-bg);}
.po-prod-item.in-cart{background:var(--pink-soft);}
.po-prod-thumb{width:38px;height:38px;border-radius:8px;background:var(--pink-bg);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;}
.po-prod-thumb img{width:100%;height:100%;object-fit:cover;}
.po-prod-thumb i{font-size:14px;color:var(--pink-border);}
.po-prod-info{flex:1;min-width:0;}
.po-prod-name{font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.po-prod-price{font-size:11.5px;color:var(--primary);font-weight:700;}
.po-prod-stock{font-size:10.5px;color:var(--text-muted);}
.po-add-btn{width:28px;height:28px;border:none;border-radius:7px;background:var(--primary-dark);color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.15s;}
.po-add-btn:hover{background:var(--primary);}
.po-add-btn:disabled{background:var(--border);color:var(--text-muted);cursor:not-allowed;}
.po-cart{display:flex;flex-direction:column;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;}
.po-cart-head{padding:10px 13px;background:var(--primary-dark);color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-shrink:0;}
.po-cart-items{flex:1;overflow-y:auto;max-height:320px;}
.po-cart-item{display:flex;align-items:center;gap:8px;padding:8px 11px;border-bottom:1px solid var(--border);}
.po-cart-item:last-child{border-bottom:none;}
.po-cart-item-name{flex:1;font-size:11.5px;font-weight:500;line-height:1.3;}
.po-qty-wrap{display:flex;flex-direction:column;align-items:center;gap:3px;min-width:72px;}
.po-qty-label{font-size:10px;color:var(--text-muted);display:flex;justify-content:space-between;width:100%;}
.po-qty-range{-webkit-appearance:none;appearance:none;width:100%;height:4px;border-radius:2px;background:var(--border);outline:none;cursor:pointer;}
.po-qty-range::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:14px;height:14px;border-radius:50%;background:var(--primary-dark);cursor:pointer;border:2px solid #fff;box-shadow:0 1px 4px rgba(194,59,94,.3);}
.po-qty-range::-moz-range-thumb{width:14px;height:14px;border-radius:50%;background:var(--primary-dark);cursor:pointer;border:2px solid #fff;}
.po-qty-val{font-size:12px;font-weight:700;color:var(--primary-dark);min-width:18px;text-align:center;}
.po-remove-btn{background:none;border:none;cursor:pointer;color:var(--pink-border);font-size:13px;padding:2px;display:flex;align-items:center;transition:.15s;}
.po-remove-btn:hover{color:var(--red-alert);}
.po-cart-foot{padding:10px 13px;border-top:1px solid var(--border);background:var(--pink-bg);flex-shrink:0;}
.po-cart-total{display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:var(--primary-dark);}
.po-cart-empty{text-align:center;padding:28px 16px;color:var(--text-muted);}
.po-cart-empty i{font-size:28px;color:var(--pink-border);margin-bottom:6px;}
/* orders timeline view */
.orders-view-toggle{display:flex;gap:6px;align-items:center;}
.view-btn{height:32px;padding:0 13px;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--text-sub);font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:inherit;transition:.15s;}
.view-btn.active{background:var(--primary-dark);color:#fff;border-color:var(--primary-dark);}
.orders-timeline{display:none;}
.orders-timeline.active{display:block;}
.orders-table-wrap{display:none;}
.orders-table-wrap.active{display:block;}
.tl-day{margin-bottom:22px;}
.tl-day-header{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.tl-day-label{font-size:13px;font-weight:700;color:var(--text-main);}
.tl-day-count{font-size:11px;color:var(--text-muted);background:var(--pink-bg);border:1px solid var(--border);border-radius:20px;padding:2px 9px;}
.tl-day-line{flex:1;height:1px;background:var(--border);}
.tl-lane{display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;}
.tl-card{flex-shrink:0;width:200px;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;background:#fff;cursor:pointer;transition:border-color .15s,box-shadow .15s;position:relative;}
.tl-card:hover{border-color:var(--primary);box-shadow:0 2px 10px rgba(194,59,94,.1);}
.tl-card.status-pending{border-left:4px solid var(--amber);}
.tl-card.status-in-progress{border-left:4px solid #1a56c4;}
.tl-card.status-ready{border-left:4px solid var(--green);}
.tl-card.status-completed{border-left:4px solid var(--text-muted);}
.tl-card.status-cancelled{border-left:4px solid var(--red-alert);opacity:.55;}
.tl-card-ref{font-size:10.5px;font-weight:700;color:var(--primary);margin-bottom:4px;letter-spacing:.03em;}
.tl-card-name{font-size:13px;font-weight:600;color:var(--text-main);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tl-card-meta{font-size:11px;color:var(--text-muted);margin-bottom:8px;}
.tl-card-footer{display:flex;align-items:center;justify-content:space-between;}
.tl-card-total{font-size:13px;font-weight:700;color:var(--primary-dark);}
.tl-type-badge{font-size:9.5px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--pink-soft);color:var(--primary);}
.tl-type-badge.walkin{background:var(--pink-bg);color:var(--text-muted);}
.tl-status-dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;}
.tl-status-dot.pending{background:var(--amber);}
.tl-status-dot.in-progress{background:#1a56c4;}
.tl-status-dot.ready{background:var(--green);}
.tl-status-dot.completed{background:var(--text-muted);}
.tl-status-dot.cancelled{background:var(--red-alert);}

/* ── ORDER ITEMS BAR (in orders table) ── */
.order-items-bars{display:flex;flex-direction:column;gap:4px;min-width:140px;max-width:200px;}
.oib-row{display:flex;align-items:center;gap:5px;}
.oib-name{font-size:10.5px;color:var(--text-sub);width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex-shrink:0;}
.oib-track{flex:1;height:6px;background:var(--pink-bg);border-radius:3px;overflow:hidden;min-width:40px;}
.oib-fill{height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-light));border-radius:3px;transition:width .3s ease;}
.oib-qty{font-size:10px;font-weight:700;color:var(--primary-dark);width:24px;text-align:right;flex-shrink:0;}

/* ── MULTI DATE PICKER ── */
.date-filter-wrap{position:relative;display:inline-flex;align-items:center;gap:6px;}
.multi-date-btn{height:36px;padding:0 13px;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--text-main);font-size:12px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:6px;font-family:inherit;transition:.15s;white-space:nowrap;min-width:130px;}
.multi-date-btn:hover{border-color:var(--primary);background:var(--pink-bg);}
.multi-date-btn.has-dates{border-color:var(--primary);background:var(--pink-soft);color:var(--primary-dark);}
.multi-date-btn .mdb-count{background:var(--primary);color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700;}
.date-picker-dropdown{display:none;position:absolute;top:calc(100% + 6px);left:0;background:#fff;border:1.5px solid var(--border);border-radius:14px;box-shadow:var(--shadow-md);z-index:200;min-width:300px;padding:16px;}
.date-picker-dropdown.open{display:block;}
.dpd-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.dpd-title{font-size:13px;font-weight:700;color:var(--text-main);}
.dpd-clear{font-size:11px;color:var(--primary);cursor:pointer;font-weight:600;background:none;border:none;font-family:inherit;padding:2px 6px;}
.dpd-input-row{display:flex;gap:8px;align-items:center;margin-bottom:10px;}
.dpd-input-row input[type=date]{flex:1;height:34px;padding:0 10px;font-size:12px;}
.dpd-sep{font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;}
.dpd-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
.dpd-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;background:var(--pink-soft);border:1px solid var(--pink-border);font-size:11px;font-weight:600;color:var(--primary-dark);}
.dpd-chip-del{background:none;border:none;cursor:pointer;color:var(--primary);font-size:11px;padding:0;line-height:1;display:flex;align-items:center;}
.dpd-presets{display:flex;gap:5px;flex-wrap:wrap;}
.dpd-preset{height:28px;padding:0 10px;border-radius:6px;border:1.5px solid var(--border);background:#fff;font-size:11px;font-weight:600;cursor:pointer;color:var(--text-sub);font-family:inherit;transition:.12s;}
.dpd-preset:hover{background:var(--pink-bg);border-color:var(--primary);color:var(--primary-dark);}

/* ── PO CART QTY CTRL (replacing slider) ── */
.po-qty-ctrl{display:flex;align-items:center;gap:6px;}
.po-qty-ctrl .pqc-btn{width:26px;height:26px;border-radius:6px;border:1.5px solid var(--border);background:#fff;cursor:pointer;font-size:14px;font-weight:700;color:var(--primary-dark);display:flex;align-items:center;justify-content:center;transition:.12s;}
.po-qty-ctrl .pqc-btn:hover{background:var(--pink-bg);border-color:var(--primary);}
.po-qty-ctrl .pqc-val{font-size:13px;font-weight:700;color:var(--text-main);min-width:24px;text-align:center;}

/* ── EXPENSE ITEM DROPDOWN ── */
.exp-item-select-wrap{position:relative;}
.exp-item-select-wrap .item-sub{font-size:10.5px;color:var(--text-muted);margin-top:2px;}

/* ── PRODUCTION PRODUCT DROPDOWN ── */
.batch-prod-info{font-size:11px;color:var(--text-muted);margin-top:3px;padding:5px 10px;background:var(--pink-bg);border-radius:6px;display:none;}
.batch-prod-info.show{display:block;}

/* ── MULTIBAR CHART ── */
.mbc-wrap{overflow-x:auto;padding-bottom:8px;}
.mbc-chart{display:flex;flex-direction:column;gap:10px;min-width:400px;}
.mbc-row{display:flex;align-items:center;gap:10px;font-size:12px;}
.mbc-label{width:130px;color:var(--text-sub);font-size:11px;font-weight:500;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right;}
.mbc-bars{flex:1;display:flex;flex-direction:column;gap:3px;}
.mbc-bar-row{display:flex;align-items:center;gap:6px;}
.mbc-track{flex:1;background:var(--pink-bg);border-radius:4px;height:16px;overflow:hidden;min-width:60px;}
.mbc-fill-rev{height:100%;background:linear-gradient(90deg,var(--primary-dark),var(--primary-light));border-radius:4px;display:flex;align-items:center;padding:0 6px;min-width:4px;transition:width 0.6s cubic-bezier(.4,0,.2,1);}
.mbc-fill-qty{height:100%;background:linear-gradient(90deg,var(--green),#7ec9a5);border-radius:4px;display:flex;align-items:center;padding:0 6px;min-width:4px;transition:width 0.6s cubic-bezier(.4,0,.2,1);}
.mbc-fill-rev span,.mbc-fill-qty span{font-size:9px;color:#fff;font-weight:600;white-space:nowrap;overflow:hidden;}
.mbc-val{font-size:10px;font-weight:700;white-space:nowrap;min-width:52px;}
.mbc-val.rev{color:var(--primary-dark);}
.mbc-val.qty{color:var(--green);}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-wordmark">Mocardi</div>
    <div class="logo-tagline">Bakery Management System</div>
  </div>
  <nav class="sidebar-nav">
    <div class="db-status checking" id="db-status">
      <div class="db-dot"></div><span id="db-status-text">Connecting…</span>
    </div>
    <div class="nav-section">Overview</div>
    <div class="nav-item active" onclick="nav('dashboard',this)">
      <i class="fa-solid fa-house nav-icon"></i>Dashboard
    </div>
    <div class="nav-section">Penjualan</div>
    <div class="nav-item" onclick="nav('pos',this)">
      <i class="fa-solid fa-cart-shopping nav-icon"></i>Point of Sale
    </div>
    <div class="nav-item" onclick="nav('orders',this)">
      <i class="fa-solid fa-file-lines nav-icon"></i>Pesanan
    </div>
    <div class="nav-item" onclick="nav('customers',this)">
      <i class="fa-solid fa-users nav-icon"></i>Pelanggan
    </div>
    <div class="nav-section">Operasional</div>
    <div class="nav-item" onclick="nav('inventory',this)">
      <i class="fa-solid fa-box-archive nav-icon"></i>Inventori
    </div>
    <div class="nav-item" onclick="nav('recipes',this)">
      <i class="fa-solid fa-book-open nav-icon"></i>Resep
    </div>
    <div class="nav-item" onclick="nav('production',this)">
      <i class="fa-solid fa-fire-burner nav-icon"></i>Produksi
    </div>
    <div class="nav-section">Keuangan</div>
    <div class="nav-item" onclick="nav('expenses',this)">
      <i class="fa-solid fa-wallet nav-icon"></i>Pengeluaran
    </div>
    <div class="nav-section">Sistem</div>
    <div class="nav-item" onclick="nav('settings',this)">
      <i class="fa-solid fa-gear nav-icon"></i>Sistem
    </div>
  </nav>
  <div class="sidebar-footer">
    <div class="user-badge">
      <div class="user-avatar" id="user-avatar-initials">MC</div>
      <div>
        <div class="user-name" id="sidebar-bakery-name">Mocardi</div>
        <div class="user-role" id="sidebar-owner-name">Home Baker · Owner</div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">
  <div class="topbar">
    <div class="page-title" id="page-title">Dashboard</div>
    <div class="topbar-right">
      <div class="date-chip">
        <i class="fa-regular fa-calendar" style="color:var(--primary-dark);"></i>
        <span id="date-chip"></span>
      </div>
      <button class="btn btn-primary" onclick="nav('pos',document.querySelectorAll('.nav-item')[1])">
        + Transaksi Baru
      </button>
    </div>
  </div>

  <!-- DASHBOARD -->
  <div class="page active" id="page-dashboard">
    <div class="dash-hero">
      <div>
        <div class="dash-hero-title">Selamat Datang di Mocardi</div>
        <div class="dash-hero-sub">Ringkasan operasional bakery anda hari ini</div>
      </div>
    </div>
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon-wrap red"><i class="fa-solid fa-sack-dollar" style="color:var(--primary-dark);"></i></div>
        <div>
          <div class="stat-label">Pendapatan Hari Ini</div>
          <div class="stat-value" id="dash-revenue">—</div>
          <div class="stat-sub" id="dash-rev-sub">Memuat…</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon-wrap green"><i class="fa-solid fa-clipboard-list" style="color:var(--primary-dark);"></i></div>
        <div>
          <div class="stat-label">Item Terjual Hari Ini</div>
          <div class="stat-value" id="dash-items">—</div>
          <div class="stat-sub">Semua produk</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon-wrap amber"><i class="fa-solid fa-clock" style="color:var(--primary-dark);"></i></div>
        <div>
          <div class="stat-label">Pesanan Pending</div>
          <div class="stat-value" id="dash-orders">—</div>
          <div class="stat-sub">Pre-order yang harus disiapkan</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon-wrap blue"><i class="fa-solid fa-cube" style="color:var(--primary-dark);"></i></div>
        <div>
          <div class="stat-label">Stok Menipis</div>
          <div class="stat-value" id="dash-alerts">—</div>
          <div class="stat-sub">Bahan perlu restok</div>
        </div>
      </div>
    </div>
    <div class="grid-2 mb-16" style="margin-top:16px">
      <div class="card">
        <div class="card-title">Stok Bahan Menipis</div>
        <div id="dash-low-stock"><div class="skeleton skeleton-row"></div></div>
      </div>
      <div class="card">
        <div class="card-title">Produksi Hari Ini</div>
        <div id="dash-production"><div class="skeleton skeleton-row"></div></div>
      </div>
    </div>
    <!-- Ringkasan keuangan + filter periode -->
    <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:14px">
      <select id="report-period" onchange="renderReports()" style="width:auto"><option value="month">Bulan Ini</option><option value="week">7 Hari Ini</option><option value="year">Tahun Ini</option></select>
      <button class="btn btn-outline btn-sm" onclick="exportReport()"><i class="fa-solid fa-download"></i> Ekspor CSV</button>
    </div>
    <div class="stats-grid mb-16">
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-sack-dollar" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Total Pendapatan</div><div class="stat-value" id="rep-revenue">—</div><div class="stat-sub">Dari semua penjualan</div></div></div>
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-receipt" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Total Pengeluaran</div><div class="stat-value" id="rep-cogs">—</div><div class="stat-sub">Semua biaya</div></div></div>
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-chart-line" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Keuntungan Bersih</div><div class="stat-value" id="rep-profit">—</div><div class="stat-sub">Pendapatan - Pengeluaran</div></div></div>
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-percent" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Margin</div><div class="stat-value" id="rep-margin">—</div><div class="stat-sub">Persentase keuntungan</div></div></div>
    </div>
    <div class="card mb-16">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <div class="card-title" style="margin-bottom:0">Performa Produk — Pendapatan &amp; Qty Terjual</div>
        <div style="display:flex;gap:12px;align-items:center;font-size:11px">
          <span style="display:flex;align-items:center;gap:5px"><span style="width:12px;height:12px;border-radius:3px;background:var(--primary-dark);display:inline-block"></span>Pendapatan</span>
          <span style="display:flex;align-items:center;gap:5px"><span style="width:12px;height:12px;border-radius:3px;background:var(--green);display:inline-block"></span>Qty Terjual</span>
        </div>
      </div>
      <div id="multibar-chart" style="overflow-x:auto">
        <div class="skeleton skeleton-row" style="height:180px"></div>
      </div>
    </div>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div class="card-title" style="margin-bottom:0">Semua Transaksi</div>
        <div style="font-size:11px;color:var(--text-muted)" id="txn-count"></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Tanggal</th><th>No. Pesanan</th><th>Pelanggan</th><th>Jenis</th><th>Item</th><th>Total</th><th>Dp</th><th>Status</th></tr></thead>
          <tbody id="rep-transactions"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- POS -->
  <div class="page" id="page-pos">
    <div class="pos-layout">
      <div class="pos-products">
        <div class="pos-search-wrap">
          <div class="pos-search-input">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Cari produk..." id="pos-search-input" oninput="filterPOSTiles()">
          </div>
        </div>
        <div class="pos-cats" id="pos-cats"></div>
        <div class="pos-products-scroll">
          <div class="pos-grid" id="pos-grid">
            <div style="grid-column:1/-1;text-align:center;padding:50px;color:var(--text-muted)">
              Memuat produk…
            </div>
          </div>
        </div>
      </div>
      <div class="pos-cart">
        <div class="cart-header">
          <div class="cart-header-title"><i class="fa-solid fa-cart-shopping"></i> Keranjang</div>
          <button class="cart-clear-btn" onclick="clearCart()">Hapus</button>
        </div>
        <div class="cart-items" id="cart-items">
          <div class="cart-empty">
            <div><i class="fa-solid fa-cart-shopping"></i></div>
            <div style="font-size:12px;margin-top:8px;color:var(--text-muted)">Pilih produk untuk mulai</div>
          </div>
        </div>
        <div class="cart-footer">
          <div class="cart-row"><span>Subtotal</span><span id="cart-sub">Rp 0</span></div>
          <div class="cart-row" id="disc-row" style="display:none"><span>Diskon</span><span id="cart-disc" style="color:var(--green)">- Rp 0</span></div>
          <div class="cart-row"><span>Pajak (<span id="cart-tax-pct">10</span>%)</span><span id="cart-tax">Rp 0</span></div>
          <div class="cart-total-row"><span>Total</span><span id="cart-total">Rp 0</span></div>
          <div class="cart-customer">
            <input type="text" placeholder="Nama pelanggan (opsional)" id="pos-customer-name" list="customer-dl">
            <datalist id="customer-dl"></datalist>
          </div>
          <div class="cart-actions">
            <button class="btn btn-outline" style="flex:1" onclick="applyDiscount()"><i class="fa-solid fa-tag"></i> Diskon</button>
            <button class="btn btn-primary" style="flex:2" id="btn-checkout" onclick="checkout()"><i class="fa-solid fa-credit-card"></i> Bayar</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ORDERS -->
  <div class="page" id="page-orders">
    <div class="section-header">
      <div class="section-title">Pesanan & Pre-order</div>
      <div style="display:flex;gap:8px;align-items:center">
        <div class="orders-view-toggle">
          <button class="view-btn" id="btn-view-timeline" onclick="setOrderView('timeline')"><i class="fa-solid fa-calendar-days"></i> Timeline</button>
          <button class="view-btn active" id="btn-view-table" onclick="setOrderView('table')"><i class="fa-solid fa-table-list"></i> Tabel</button>
        </div>
        <button class="btn btn-primary" onclick="openNewOrderModal()">+ Pre-order Baru</button>
      </div>
    </div>
    <div class="card">
      <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
        <select id="order-filter" onchange="renderOrders(true)" style="width:auto"><option value="">Semua Status</option><option value="pending">Pending</option><option value="in-progress">Diproses</option><option value="ready">Siap</option><option value="completed">Selesai</option><option value="cancelled">Dibatalkan</option></select>
        <select id="order-type-filter" onchange="renderOrders(true)" style="width:auto"><option value="">Semua Jenis</option><option value="walkin">Walk-in</option><option value="preorder">Pre-order</option></select>
        <!-- Multi Date Picker -->
        <div class="date-filter-wrap" id="orders-date-wrap">
          <button class="multi-date-btn" id="orders-mdb-btn" onclick="toggleDatePicker('orders')">
            <i class="fa-regular fa-calendar"></i>
            <span id="orders-mdb-label">Pilih Tanggal</span>
            <span class="mdb-count" id="orders-mdb-count" style="display:none">0</span>
          </button>
          <button class="btn btn-primary btn-sm" id="orders-mdb-apply" onclick="applyDatePicker('orders')" style="height:36px;flex-shrink:0"><i class="fa-solid fa-check"></i> Terapkan</button>
          <div class="date-picker-dropdown" id="orders-date-picker">
            <div class="dpd-header">
              <span class="dpd-title"><i class="fa-solid fa-calendar-days" style="color:var(--primary);margin-right:5px"></i>Filter Tanggal</span>
              <button class="dpd-clear" onclick="clearDatePicker('orders')">Hapus semua</button>
            </div>
            <div class="dpd-input-row">
              <input type="date" id="orders-dpd-from" placeholder="Dari">
              <span class="dpd-sep">—</span>
              <input type="date" id="orders-dpd-to" placeholder="Sampai">
              <button class="btn btn-primary btn-sm" onclick="addDateRange('orders')" style="flex-shrink:0;height:34px">+ Rentang</button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px">Atau pilih tanggal spesifik:</div>
            <div class="dpd-input-row">
              <input type="date" id="orders-dpd-single">
              <button class="btn btn-outline btn-sm" onclick="addSingleDate('orders')" style="flex-shrink:0;height:34px">+ Tambah</button>
            </div>
            <div class="dpd-chips" id="orders-date-chips"></div>
            <div class="dpd-presets">
              <button class="dpd-preset" onclick="setDatePreset('orders','today')">Hari ini</button>
              <button class="dpd-preset" onclick="setDatePreset('orders','yesterday')">Kemarin</button>
              <button class="dpd-preset" onclick="setDatePreset('orders','week')">7 Hari</button>
              <button class="dpd-preset" onclick="setDatePreset('orders','month')">Bulan Ini</button>
            </div>
          </div>
        </div>
        <div class="pos-search-input" style="flex:1;min-width:150px">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" placeholder="Cari pelanggan…" id="order-search" oninput="renderOrders(true)">
        </div>
      </div>
      <!-- Timeline view -->
      <div class="orders-timeline" id="orders-timeline"></div>
      <!-- Table view -->
      <div class="orders-table-wrap active" id="orders-table-wrap">
        <div class="table-wrap"><table><thead><tr><th>No. Pesanan</th><th>Pelanggan</th><th>Jenis</th><th>Item Pesanan</th><th>Ambil</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody id="orders-tbody"><tr><td colspan="8" style="text-align:center;padding:28px"><div class="skeleton skeleton-row"></div></td></tr></tbody></table></div>
        <div id="orders-pagination"></div>
      </div>
    </div>
  </div>

  <!-- CUSTOMERS -->
  <div class="page" id="page-customers">
    <div class="section-header"><div class="section-title">Buku Pelanggan</div><button class="btn btn-primary" onclick="openModal('modal-new-customer')">+ Tambah Pelanggan</button></div>
    <div class="grid-2 mb-16" style="grid-template-columns:1fr 2fr">
      <div>
        <div class="stats-grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
          <div class="stat-card" style="padding:14px 16px">
            <div><div class="stat-label">Total</div><div class="stat-value" style="font-size:22px" id="cust-total">—</div></div>
          </div>
          <div class="stat-card" style="padding:14px 16px">
            <div><div class="stat-label">Langganan</div><div class="stat-value" style="font-size:22px" id="cust-regulars">—</div></div>
          </div>
        </div>
        <div class="card"><div class="card-title">Tingkat Loyalitas</div><div id="loyalty-chart"></div></div>
      </div>
      <div class="card">
        <div class="pos-search-wrap" style="padding:0;margin-bottom:14px;border:none;">
          <div class="pos-search-input">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input 
              type="text" 
              placeholder="Cari pelanggan..." 
              id="cust-search" 
              oninput="renderCustomers(true)"
            >
          </div>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Nama</th><th>Telepon</th><th>Pesanan</th><th>Tier</th><th>Aksi</th></tr></thead><tbody id="customers-tbody"></tbody></table></div>
        <div id="customers-pagination"></div>
      </div>
    </div>
  </div>

  <!-- INVENTORY -->
  <div class="page" id="page-inventory">
    <div class="section-header"><div class="section-title">Inventori Bahan</div><button class="btn btn-primary" onclick="openModal('modal-new-ingredient')">+ Tambah Bahan</button></div>
    <div class="card">
      <div class="pos-search-input" style="margin-bottom:16px;">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input
          type="text"
          id="inventory-search"
          placeholder="Cari bahan..."
          oninput="renderInventory()"
        >
      </div>
    <div class="table-wrap"><table><thead><tr><th>Bahan</th><th>Kategori</th><th>Stok</th><th>Satuan</th><th>Min. Stok</th><th>Harga/Unit</th><th>Status</th><th>Aksi</th></tr></thead><tbody id="inventory-tbody"></tbody></table></div></div>
    <div id="inventory-pagination"></div>
  </div>

  <!-- RECIPES -->
  <div class="page" id="page-recipes">
    <div class="section-header">
      <div class="section-title">Buku Resep Mocardi</div>
      <button class="btn btn-primary" onclick="openNewRecipeModal()">+ Resep Baru</button>
    </div>
    <div class="recipes-grid" id="recipes-grid"></div>
  </div>

  <!-- PRODUCTION -->
  <div class="page" id="page-production">
    <div class="section-header"><div class="section-title">Rencana Produksi</div><button class="btn btn-primary" onclick="openNewBatchModal()">+ Catat Batch</button></div>
    <div class="grid-2 mb-16">
      <div class="card"><div class="card-title">Rencana Panggang Hari Ini</div><div id="today-plan"></div></div>
      <div class="card"><div class="card-title">Log Produksi</div><div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Produk</th><th>Batch</th><th>Jml Produksi</th><th>Baker</th><th>Status</th><th>Aksi</th></tr></thead><tbody id="prod-log-tbody"></tbody></table></div></div>
    </div>
  </div>

  <!-- REPORTS (removed as standalone page — merged into Dashboard) -->
  <div class="page" id="page-reports" style="display:none!important"></div>

  <!-- EXPENSES -->
  <div class="page" id="page-expenses">
    <div class="section-header"><div class="section-title">Pencatat Pengeluaran</div><button class="btn btn-primary" onclick="openNewExpenseModal()">+ Catat Pengeluaran</button></div>
    <div class="stats-grid mb-16" style="grid-template-columns:repeat(3,1fr)">
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-wallet" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Bulan Ini</div><div class="stat-value" id="exp-month">—</div><div class="stat-sub">Total pengeluaran</div></div></div>
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-wheat-awn" style="color:var(--primary-dark);"></i></div><div><div class="stat-label">Bahan Baku</div><div class="stat-value" id="exp-ingredients">—</div><div class="stat-sub">Biaya bahan</div></div></div>
      <div class="stat-card"><div class="stat-icon-wrap"><i class="fa-solid fa-boxes-stacked" style="color:var(--primary-dark)"></i></div><div><div class="stat-label">Lainnya</div><div class="stat-value" id="exp-other">—</div><div class="stat-sub">Utilitas, kemasan, dll.</div></div></div>
    </div>
    <div class="card"><div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Kategori</th><th>Deskripsi</th><th>Jumlah</th><th>Aksi</th></tr></thead><tbody id="expenses-tbody"></tbody></table></div></div>
  </div>

  <!-- SETTINGS -->
  <div class="page" id="page-settings">
    <div class="section-title" style="margin-bottom:20px">Pengaturan Mocardi</div>
    <div class="grid-2" style="grid-template-columns:2fr 1fr">
      <div>
        <div class="card mb-14">
          <div class="card-title">Profil Bisnis</div>
          <div class="form-group"><label>Nama Bakery</label><input id="set-name"></div>
          <div class="form-row"><div class="form-group"><label>Nama Pemilik</label><input id="set-owner"></div><div class="form-group"><label>Telepon</label><input id="set-phone"></div></div>
          <div class="form-group"><label>Alamat</label><input id="set-addr"></div>
          <div class="form-row">
            <div class="form-group"><label>Tarif Pajak (%)</label><input id="set-tax" type="number"></div>
            <div class="form-group"><label>Mata Uang</label><select id="set-currency"><option>IDR (Rp)</option><option>USD ($)</option><option>MYR (RM)</option></select></div>
          </div>
          <button class="btn btn-primary" onclick="saveSettings()">Simpan Pengaturan</button>
        </div>
        <div class="card">
          <div class="card-title">Footer Struk</div>
          <div class="form-group"><label>Pesan terima kasih</label><textarea id="set-receipt-msg" rows="3"></textarea></div>
          <div class="form-group"><label>Info kontak / sosial media</label><input id="set-social"></div>
          <button class="btn btn-primary" onclick="saveSettings()">Simpan</button>
        </div>
      </div>
      <div>
        <div class="card">
          <div class="card-title">Katalog Produk</div>
          <div class="table-wrap"><table><thead><tr><th>Produk</th><th>Harga</th><th>Stok</th><th>Edit</th></tr></thead><tbody id="settings-products-tbody"></tbody></table></div>
          <button class="btn btn-outline btn-sm mt-14" onclick="openModal('modal-new-product')">+ Tambah Produk</button>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- ════════════════════ MODALS ════════════════════ -->

<!-- Checkout -->
<div class="modal-overlay" id="modal-checkout">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-checkout')">✕</button>
    <div class="modal-title">Selesaikan Pembayaran</div>
    <div class="receipt" id="checkout-receipt"></div>
    <div class="form-group mt-14"><label>Metode Pembayaran</label>
      <input type="text" id="payment-method" value="Cash" readonly>
    </div>
    <div id="cash-section">
      <div class="form-row">
        <div class="form-group"><label>Uang Diterima (Rp)</label><input type="number" id="cash-received" oninput="calcChange()" placeholder="0"></div>
        <div class="form-group"><label>Kembalian</label><input type="text" id="cash-change" readonly placeholder="Rp 0" style="background:var(--pink-bg)"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-checkout')">Batal</button>
      <button class="btn btn-green" id="btn-confirm-pay" onclick="confirmPayment()">✓ Konfirmasi Pembayaran</button>
    </div>
  </div>
</div>

<!-- Receipt -->
<div class="modal-overlay" id="modal-receipt">
  <div class="modal" style="width:380px">
    <button class="modal-close" onclick="closeModal('modal-receipt')">✕</button>
    <div class="modal-title" style="text-align:center">🧾 Struk Mocardi</div>
    <div class="receipt" id="final-receipt"></div>
    <div class="modal-footer" style="justify-content:center">
      <button class="btn btn-outline" onclick="window.print()">🖨 Cetak</button>
      <button class="btn btn-primary" onclick="closeModal('modal-receipt');clearCart()">Selesai</button>
    </div>
  </div>
</div>

<!-- New Order (Preorder) — dropdown picker + range sliders -->
<div class="modal-overlay" id="modal-new-order">
  <div class="modal po-modal">
    <button class="modal-close" onclick="closeModal('modal-new-order')">✕</button>
    <div class="modal-title">🛒 Pre-order Baru</div>
    <!-- Customer & pickup info -->
    <div class="form-row" style="margin-bottom:14px">
      <div class="form-group" style="margin-bottom:0"><label>Nama Pelanggan *</label><input id="ord-customer" list="customer-dl2" placeholder="Ketik atau pilih pelanggan…"><datalist id="customer-dl2"></datalist></div>
      <div class="form-group" style="margin-bottom:0"><label>Telepon</label><input id="ord-phone" placeholder="+62…"></div>
    </div>
    <div class="form-row" style="margin-bottom:14px">
      <div class="form-group" style="margin-bottom:0"><label>Tanggal Ambil *</label><input type="date" id="ord-date"></div>
      <div class="form-group" style="margin-bottom:0"><label>Jam Ambil</label><input type="time" id="ord-time" value="10:00"></div>
    </div>
    <!-- Dropdown add product -->
    <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label>Tambah Produk</label>
        <select id="ord-prod-select" style="font-size:13px;">
          <option value="">— Pilih produk —</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" onclick="poAddFromDropdown()" style="flex-shrink:0;height:38px">+ Tambah</button>
    </div>
    <!-- Cart -->
    <div class="po-layout" style="grid-template-columns:1fr">
      <!-- New order cart — full width -->
      <div class="po-cart">
        <div class="po-cart-head"><i class="fa-solid fa-basket-shopping"></i> Item Pesanan <span id="po-cart-count" style="margin-left:auto;background:rgba(255,255,255,.2);border-radius:20px;padding:1px 8px;font-size:11px">0</span></div>
        <div class="po-cart-items" id="po-cart-items">
          <div class="po-cart-empty"><i class="fa-solid fa-basket-shopping"></i><div style="font-size:12px;margin-top:4px">Belum ada item</div></div>
        </div>
        <div class="po-cart-foot">
          <div class="po-cart-total"><span>Total</span><span id="po-cart-total">Rp 0</span></div>
        </div>
      </div>
    </div>
    <!-- Deposit + notes -->
    <div class="form-row" style="margin-top:14px">
      <div class="form-group" style="margin-bottom:0"><label>DP / Deposit (Rp)</label><input type="number" id="ord-deposit" placeholder="0"></div>
      <div class="form-group" style="margin-bottom:0"><label>Catatan</label><input id="ord-notes" placeholder="Alergi, permintaan khusus…"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-order')">Batal</button>
      <button class="btn btn-primary" onclick="saveOrder()"><i class="fa-solid fa-check"></i> Simpan Pesanan</button>
    </div>
  </div>
</div>

<!-- New Customer -->
<div class="modal-overlay" id="modal-new-customer">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-customer')">✕</button>
    <div class="modal-title">Tambah Pelanggan</div>
    <div class="form-row">
      <div class="form-group"><label>Nama Lengkap *</label><input id="cust-name"></div>
      <div class="form-group"><label>Telepon *</label><input id="cust-phone" placeholder="+62…"></div>
    </div>
    <div class="form-group"><label>Instagram / Sosial Media</label><input id="cust-ig" placeholder="@username"></div>
    <div class="form-group"><label>Tanggal Lahir (opsional)</label><input type="date" id="cust-bday"></div>
    <div class="form-group"><label>Alergi / Catatan</label><textarea id="cust-notes" rows="2"></textarea></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-customer')">Batal</button>
      <button class="btn btn-primary" onclick="saveCustomer()">Simpan</button>
    </div>
  </div>
</div>

<!-- New Ingredient -->
<div class="modal-overlay" id="modal-new-ingredient">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-ingredient')">✕</button>
    <div class="modal-title">Tambah Bahan</div>
    <div class="form-row">
      <div class="form-group"><label>Nama Bahan *</label><input id="ing-name"></div>
      <div class="form-group"><label>Kategori</label>
        <select id="ing-cat"><option value="1">Tepung & Biji-bijian</option><option value="2">Susu & Dairy</option><option value="3">Pemanis</option><option value="4">Lemak & Minyak</option><option value="5">Telur</option><option value="6">Perisa & Bumbu</option><option value="7">Topping</option><option value="8">Kemasan</option><option value="9">Lain-lain</option></select>
      </div>
    </div>
    <div class="form-row-3">
      <div class="form-group"><label>Stok *</label><input type="number" id="ing-stock" placeholder="0"></div>
      <div class="form-group"><label>Satuan</label><select id="ing-unit"><option>g</option><option>kg</option><option>ml</option><option>L</option><option>pcs</option><option>sachet</option></select></div>
      <div class="form-group"><label>Min. Stok</label><input type="number" id="ing-min" placeholder="0"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Harga/Unit (Rp)</label><input type="number" id="ing-cost" placeholder="0"></div>
      <div class="form-group"><label>Supplier</label><input id="ing-supplier" placeholder="Toko ABC"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-ingredient')">Batal</button>
      <button class="btn btn-primary" onclick="saveIngredient()">Simpan</button>
    </div>
  </div>
</div>

<!-- New Recipe -->
<div class="modal-overlay" id="modal-new-recipe">
  <div class="modal" style="width:640px;max-width:97vw">
    <button class="modal-close" onclick="closeModal('modal-new-recipe')">✕</button>
    <div class="modal-title">Resep Baru</div>
    <div class="form-row">
      <div class="form-group"><label>Nama Resep *</label><input id="rec-name"></div>
      <div class="form-group"><label>Hasil (pcs)</label><input type="number" id="rec-yield" value="12"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Waktu Persiapan (mnt)</label><input type="number" id="rec-prep" value="30"></div>
      <div class="form-group"><label>Waktu Panggang (mnt)</label><input type="number" id="rec-bake" value="25"></div>
    </div>
    <div class="form-group"><label>Kategori</label>
      <select id="rec-cat"><option value="1">Roti</option><option value="2">Pastri</option><option value="3">Kue</option><option value="4">Cookies</option><option value="5">Gurih</option></select>
    </div>
    <!-- Structured ingredients linked to inventory -->
    <div class="form-group">
      <label>Bahan-bahan <span style="font-size:10px;color:var(--text-muted);font-weight:400">(terhubung ke inventori untuk pengurangan otomatis)</span></label>
      <div id="rec-ing-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px"></div>
      <div style="display:flex;gap:8px;align-items:flex-end">
        <div style="flex:2"><select id="rec-ing-item" style="font-size:12.5px"><option value="">— Pilih bahan dari inventori —</option></select></div>
        <div style="flex:1"><input type="number" id="rec-ing-qty" placeholder="Jumlah" min="0.01" step="0.01" style="font-size:12.5px"></div>
        <div style="flex:0.7"><select id="rec-ing-unit" style="font-size:12.5px"><option>g</option><option>kg</option><option>ml</option><option>L</option><option>pcs</option><option>sachet</option></select></div>
        <button class="btn btn-outline btn-sm" onclick="recAddIngredient()" style="flex-shrink:0;height:38px;white-space:nowrap">+ Tambah</button>
      </div>
    </div>
    <div class="form-group"><label>Cara Membuat</label><textarea id="rec-instructions" rows="3" placeholder="1. Campur tepung dan ragi…&#10;2. Uleni 10 menit…"></textarea></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-recipe')">Batal</button>
      <button class="btn btn-primary" onclick="saveRecipe()">Simpan Resep</button>
    </div>
  </div>
</div>

<!-- New Batch -->
<div class="modal-overlay" id="modal-new-batch">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-batch')">✕</button>
    <div class="modal-title">Catat Batch Produksi</div>
    <div class="form-group">
      <label>Produk / Menu *</label>
      <select id="batch-product-select" onchange="onBatchProductChange(this)" style="font-size:13px">
        <option value="">— Pilih produk —</option>
      </select>
      <div class="batch-prod-info" id="batch-prod-info"></div>
      <input type="hidden" id="batch-recipe-id" value="">
      <input type="hidden" id="batch-yield-qty" value="0">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Jumlah Batch</label>
        <input type="number" id="batch-count" value="1" min="1" step="1" oninput="onBatchCountChange()">
        <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px" id="batch-count-hint">—</div>
      </div>
      <div class="form-group">
        <label>Jumlah Produksi (pcs) <span style="font-size:10px;color:var(--text-muted);font-weight:400">dapat diubah</span></label>
        <input type="number" id="batch-qty" value="0" min="1" step="1">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Tanggal</label><input type="date" id="batch-date"></div>
      <div class="form-group"><label>Baker</label><input id="batch-baker"></div>
    </div>
    <div class="form-group"><label>Catatan</label><textarea id="batch-notes" rows="2" placeholder="Catatan kualitas…"></textarea></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-batch')">Batal</button>
      <button class="btn btn-primary" onclick="saveBatch()">Catat Batch</button>
    </div>
  </div>
</div>

<!-- New Expense -->
<div class="modal-overlay" id="modal-new-expense">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-expense')">✕</button>
    <div class="modal-title">Catat Pengeluaran</div>
    <div class="form-row">
      <div class="form-group"><label>Kategori</label>
        <select id="exp-cat" onchange="onExpCatChange()" style="font-size:13px">
          <option value="Ingredients">Ingredients (Bahan Baku)</option>
          <option value="Packaging">Packaging (Kemasan)</option>
          <option value="Utilities">Utilities (Utilitas)</option>
          <option value="Equipment">Equipment (Peralatan)</option>
          <option value="Marketing">Marketing</option>
          <option value="Other">Other (Lainnya)</option>
        </select>
      </div>
      <div class="form-group"><label>Tanggal</label><input type="date" id="exp-date"></div>
    </div>
    <!-- Inventory item dropdown (shown for Ingredients & Packaging) -->
    <div class="form-group" id="exp-item-group">
      <label>Item Dibeli <span style="font-size:10px;color:var(--text-muted)">(dari inventori)</span></label>
      <select id="exp-item-select" onchange="onExpItemSelect()" style="font-size:13px">
        <option value="">— Pilih item dari inventori —</option>
      </select>
      <div class="exp-item-select-wrap"><div class="item-sub" id="exp-item-sub"></div></div>
    </div>
    <div class="form-group"><label>Deskripsi *</label><input id="exp-desc" placeholder="cth: Tepung terigu 25kg dari Toko Makmur"></div>
    <div class="form-group"><label>Jumlah (Rp) *</label><input type="number" id="exp-amount" placeholder="0"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-expense')">Batal</button>
      <button class="btn btn-primary" onclick="saveExpense()">Simpan</button>
    </div>
  </div>
</div>

<!-- New Product -->
<div class="modal-overlay" id="modal-new-product">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-product')">✕</button>
    <div class="modal-title">Tambah Produk Mocardi</div>
    <div class="form-row">
      <div class="form-group"><label>Nama Produk *</label><input id="prod-name"></div>
      <div class="form-group"><label>Foto Produk</label><input id="prod-image" placeholder="images/pictures.png"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Harga (Rp) *</label><input type="number" id="prod-price"></div>
      <div class="form-group"><label>Kategori</label>
        <select id="prod-cat"><option value="1">Roti</option><option value="2">Pastri</option><option value="3">Kue</option><option value="4">Cookies</option><option value="5">Gurih</option><option value="6">Minuman</option><option value="7">Seasonal</option></select>
      </div>
    </div>
    <div class="form-group"><label>Stok Awal</label><input type="number" id="prod-stock" value="0"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-new-product')">Batal</button>
      <button class="btn btn-primary" onclick="saveProduct()">Tambah Produk</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// API_BASE points to this same file. Requests with ?resource=... are handled as JSON API.
const API_BASE = <?php echo json_encode($_SERVER['PHP_SELF'] ?? basename(__FILE__)); ?>;
// Server-supplied ISO date (YYYY-MM-DD). Used by today() so client and server agree on timezone.
const _phpToday = <?php echo json_encode(phpToday()); ?>;

let cart = [], cartDiscount = 0, taxRate = 10;
let settingsCache = {}, allProducts = [], allCustomers = [];
let posFilterCat = 'All';
let checkoutPayload = null;
let inventoryPage = 1;
const inventoryPerPage = 10;

async function api(resource, { method='GET', id=null, action=null, body=null, extra='' } = {}) {
  let url = `${API_BASE}?resource=${resource}`;
  if (id)     url += `&id=${id}`;
  if (action) url += `&action=${action}`;
  if (extra)  url += `&${extra}`;
  const opts = { method, headers:{ 'Content-Type':'application/json' } };
  if (body)   opts.body = JSON.stringify(body);
  const res  = await fetch(url, opts);
  const json = await res.json();
  if (json.status === 'error') throw new Error(json.message);
  if (!json.data && json.status !== 'ok' && json.status !== 'success') throw new Error('Unknown error');
  return json;
}

function rp(n){ return 'Rp '+Math.round(n||0).toLocaleString('id-ID'); }
// today() — always returns the server-supplied ISO date (timezone-safe).
function today(){ return _phpToday; }
function fmtDate(d){ if(!d) return '—'; return new Date(d).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}); }
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

let _toastT;
function showToast(msg, type=''){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show' + (type?' '+type:'');
  clearTimeout(_toastT);
  _toastT = setTimeout(()=>t.className='',3200);
}
function setBtn(id, loading, label){
  const b = document.getElementById(id); if(!b) return;
  b.disabled = loading;
  b.innerHTML = loading ? `<span class="loading-spin"></span> ${label}` : label;
}
function skRows(n=3){
  return Array(n).fill(`<tr><td colspan="10"><div class="skeleton skeleton-row" style="margin:4px 0"></div></td></tr>`).join('');
}

async function checkDB(){
  const el=document.getElementById('db-status'), tx=document.getElementById('db-status-text');
  try { await api('settings'); el.className='db-status connected'; tx.textContent='Terhubung ke database'; }
  catch(e){ el.className='db-status disconnected'; tx.textContent='DB tidak terhubung'; showToast('⚠ Tidak dapat terhubung ke database','error'); }
}

const pageTitles = { dashboard:'Dashboard', pos:'Point of Sale', orders:'Pesanan & Pre-order', customers:'Buku Pelanggan', inventory:'Inventori Bahan', recipes:'Buku Resep', production:'Produksi', expenses:'Pengeluaran', settings:'Pengaturan' };
function nav(page, el){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+page).classList.add('active');
  if(el) el.classList.add('active');
  document.getElementById('page-title').textContent = pageTitles[page]||page;
  const renderMap = { dashboard:renderDashboard, pos:renderPOS, orders:renderOrders, customers:renderCustomers, inventory:renderInventory, recipes:renderRecipes, production:renderProduction, expenses:renderExpenses, settings:renderSettings };
  if(renderMap[page]) renderMap[page]();
}

// ── DASHBOARD ──────────────────────────────────────────────
async function renderDashboard(){
  try {
    const j = await api('dashboard');
    const d = j.data;
    document.getElementById('dash-revenue').textContent = rp(d.today_revenue);
    document.getElementById('dash-rev-sub').textContent = d.today_orders+' transaksi hari ini';
    document.getElementById('dash-items').textContent   = d.today_items;
    document.getElementById('dash-orders').textContent  = d.pending_orders;
    document.getElementById('dash-alerts').textContent  = d.low_stock_count;

    document.getElementById('dash-low-stock').innerHTML = d.low_stock_items.length
      ? d.low_stock_items.map(i=>`
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
          <div style="font-size:13px;font-weight:500">${i.name}</div>
          <span class="badge badge-red">${i.stock} ${i.unit} tersisa</span>
        </div>`).join('')
      : '<div style="color:var(--green);font-size:13px;padding:12px 0;font-weight:500"><i class="fa-solid fa-circle-check"></i> Semua bahan stok aman</div>';

    document.getElementById('dash-production').innerHTML = d.today_production.length
      ? d.today_production.map(p=>`
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:var(--pink-bg);border-radius:10px;margin-bottom:8px;border:1px solid var(--border)">
          <div><div style="font-weight:600;font-size:13px">${p.product_name}</div><div style="font-size:11px;color:var(--text-muted)">Baker: ${p.baker}</div></div>
          <div style="text-align:right"><div style="font-weight:700;font-size:16px;color:var(--primary)">${p.qty_produced} pcs</div><span class="badge badge-${p.status==='completed'?'green':'amber'}">${p.status}</span></div>
        </div>`).join('')
      : '<div style="color:var(--text-muted);font-size:13px;padding:12px 0">Belum ada batch hari ini</div>';

    renderReports();
  } catch(e){ showToast('Gagal memuat dashboard: '+e.message,'error'); }
}

// ── POS ────────────────────────────────────────────────────
async function renderPOS(){
  try {
    const j = await api('products');
    allProducts = j.data;
    const cats = ['All',...new Set(allProducts.map(p=>p.category_name))];
    document.getElementById('pos-cats').innerHTML = cats.map(c=>
      `<button class="cat-pill ${c===posFilterCat?'active':''}" onclick="setPOSCat('${c}')">${c==='All'?'Semua':c}</button>`).join('');
    filterPOSTiles();
    const cj = await api('customers');
    allCustomers = cj.data;
    const opts = allCustomers.map(c=>`<option value="${c.name}">`).join('');
    document.getElementById('customer-dl').innerHTML  = opts;
    document.getElementById('customer-dl2').innerHTML = opts;
  } catch(e){
    document.getElementById('pos-grid').innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:50px;color:var(--text-muted)">Gagal memuat produk: ${e.message}</div>`;
  }
}
function setPOSCat(c){ posFilterCat=c; renderPOS(); }
function filterPOSTiles(){
  const q=(document.getElementById('pos-search-input')||{}).value?.toLowerCase()||'';
  const f=allProducts.filter(p=>(posFilterCat==='All'||p.category_name===posFilterCat)&&p.name.toLowerCase().includes(q));
  if(!f.length){
    document.getElementById('pos-grid').innerHTML='<div style="grid-column:1/-1;text-align:center;padding:50px;color:var(--text-muted)">Produk tidak ditemukan</div>';
    return;
  }
  document.getElementById('pos-grid').innerHTML = f.map(p=>{
    const imgHTML = p.image_url
      ? '<img src="'+p.image_url+'" alt="'+p.name+'" loading="lazy">'
      : '<div class="no-img"><i class="fa-solid fa-image"></i></div>';
    const stockText = p.stock>0 ? p.stock+' tersisa' : 'Habis';
    const clickFn = p.stock>0 ? 'addToCart('+p.id+')' : '';
    return '<div class="product-tile '+(p.stock<=0?'out-of-stock':'')+'" onclick="'+clickFn+'">'
      +'<div class="tile-img">'+imgHTML+'</div>'
      +'<div class="tile-body">'
      +'<div class="tile-name">'+p.name+'</div>'
      +'<div class="tile-price">'+rp(p.price)+'</div>'
      +'<div class="tile-stock">'+stockText+'</div>'
      +'</div>'
      +'</div>';
  }).join('');
}
function addToCart(id){
  const prod=allProducts.find(p=>p.id==id);
  if(!prod||prod.stock<=0){ showToast('Stok habis!','error'); return; }
  const item=cart.find(i=>i.id==id);
  if(item){ if(item.qty>=prod.stock){ showToast('Stok tidak cukup!','error'); return; } item.qty++; }
  else cart.push({id:prod.id,name:prod.name,emoji:prod.emoji,image_url:prod.image_url||null,price:parseFloat(prod.price),qty:1});
  renderCart();
}
function changeQty(id,delta){
  const item=cart.find(i=>i.id==id); if(!item) return;
  item.qty+=delta;
  if(item.qty<=0) cart=cart.filter(i=>i.id!=id);
  renderCart();
}
function removeFromCart(id){ cart=cart.filter(i=>i.id!=id); renderCart(); }
function clearCart(){ cart=[]; cartDiscount=0; renderCart(); }
function renderCart(){
  const sub=cart.reduce((s,i)=>s+i.price*i.qty,0);
  const disc=cartDiscount, tax=Math.round((sub-disc)*(taxRate/100)), total=sub-disc+tax;
  document.getElementById('cart-sub').textContent=rp(sub);
  document.getElementById('cart-tax').textContent=rp(tax);
  document.getElementById('cart-total').textContent=rp(total);
  const dr=document.getElementById('disc-row');
  if(disc>0){dr.style.display='flex';document.getElementById('cart-disc').textContent='- '+rp(disc);}
  else dr.style.display='none';
  document.getElementById('cart-items').innerHTML = cart.length
    ? cart.map(i=>`
      <div class="cart-item">
        <div class="cart-item-img">
          ${i.image_url?`<img src="${i.image_url}" alt="${i.name}">`:`<span style="font-size:18px">${i.emoji}</span>`}
        </div>
        <div class="cart-item-info">
        <div class="cart-item-name">
          ${i.name}
        </div>
        <div class="qty-ctrl">
          <button class="qty-btn" onclick="changeQty(${i.id},-1)">−</button>
          <span class="qty-num">${i.qty}</span>
          <button class="qty-btn" onclick="changeQty(${i.id},1)">+</button>
        </div>
      </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
          <div class="cart-item-price">${rp(i.price*i.qty)}</div>
          <button onclick="removeFromCart(${i.id})" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:11px;padding:0;display:flex;align-items:center;gap:3px;font-family:inherit;transition:.12s" onmouseover="this.style.color='var(--red-alert)'" onmouseout="this.style.color='var(--text-muted)'"><i class='fa-solid fa-trash-can' style='font-size:9px'></i> hapus</button>
        </div>
      </div>`).join('')
    : `<div class="cart-empty"><i class="fa-solid fa-cart-shopping"></i><div style="font-size:12px;margin-top:8px">Pilih produk untuk mulai</div></div>`;
}
function applyDiscount(){
  const d=prompt('Masukkan jumlah diskon (Rp):');
  if(d&&!isNaN(d)&&parseInt(d)>=0){ cartDiscount=parseInt(d); renderCart(); showToast('Diskon diterapkan: '+rp(parseInt(d)),'success'); }
}
function toggleCashSection(){
  document.getElementById('cash-section').style.display=document.getElementById('payment-method').value==='Cash'?'block':'none';
}
function calcChange(){
  const paid=parseInt(document.getElementById('cash-received').value)||0;
  const total=checkoutPayload?checkoutPayload.total:0;
  const change=paid-total;
  document.getElementById('cash-change').value=change>=0?rp(change):'Kurang';
}
function buildReceiptHTML(cust,items,sub,disc,tax,total,method,s){
  const now=new Date(), ds=now.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}), ts=now.toTimeString().substr(0,5);
  return `<div class="receipt-title">${s.bakery_name||'Mocardi'}</div>
    <div class="receipt-sub">${s.address||''}</div>
    <hr class="receipt-divider">
    <div class="receipt-row"><span>${ds} ${ts}</span><span>${cust}</span></div>
    ${method?`<div class="receipt-row"><span>Pembayaran</span><span>${method}</span></div>`:''}
    <hr class="receipt-divider">
    ${items.map(i=>`<div class="receipt-row"><span>${i.emoji?i.emoji+' ':''}${i.name||i.item_name||''} x${i.qty}</span><span>${rp((i.price||i.unit_price||0)*i.qty)}</span></div>`).join('')}
    <hr class="receipt-divider">
    <div class="receipt-row"><span>Subtotal</span><span>${rp(sub)}</span></div>
    ${disc>0?`<div class="receipt-row"><span>Diskon</span><span>- ${rp(disc)}</span></div>`:''}
    <div class="receipt-row"><span>Pajak (${taxRate}%)</span><span>${rp(tax)}</span></div>
    <hr class="receipt-divider">
    <div class="receipt-row receipt-total"><span>TOTAL</span><span>${rp(total)}</span></div>
    <hr class="receipt-divider">
    <div style="text-align:center;font-size:11px;margin-top:10px;font-style:italic">${s.receipt_message||''}</div>
    <div style="text-align:center;font-size:10.5px;color:var(--text-muted);margin-top:4px">${s.social_info||''}</div>`;
}
function checkout(){
  if(!cart.length){ showToast('Keranjang kosong!','error'); return; }
  const sub=cart.reduce((s,i)=>s+i.price*i.qty,0), disc=cartDiscount;
  const tax=Math.round((sub-disc)*(taxRate/100)), total=sub-disc+tax;
  const cust=document.getElementById('pos-customer-name').value||'Walk-in';
  checkoutPayload={customer:cust,items:[...cart],subtotal:sub,discount:disc,tax_amount:tax,total};
  document.getElementById('checkout-receipt').innerHTML=buildReceiptHTML(cust,cart,sub,disc,tax,total,'',settingsCache);
  document.getElementById('cash-received').value='';
  document.getElementById('cash-change').value='';
  openModal('modal-checkout');
}
async function confirmPayment(){
  if(!checkoutPayload) return;
  const method=document.getElementById('payment-method').value;
  if(method==='Cash'&&(parseInt(document.getElementById('cash-received').value)||0)<checkoutPayload.total){ showToast('Uang tidak cukup!','error'); return; }
  setBtn('btn-confirm-pay',true,'Memproses…');
  try {
    const cust=allCustomers.find(c=>c.name===checkoutPayload.customer);
    await api('orders',{method:'POST',body:{
      customer_id:cust?cust.id:null, guest_name:cust?'':checkoutPayload.customer,
      type:'walkin', subtotal:checkoutPayload.subtotal, discount:checkoutPayload.discount,
      tax_amount:checkoutPayload.tax_amount, total:checkoutPayload.total,
      deposit:checkoutPayload.total, status:'completed', payment_method:method,
      items:checkoutPayload.items.map(i=>({product_id:i.id,item_name:i.name,emoji:i.emoji,qty:i.qty,unit_price:i.price,line_total:i.price*i.qty}))
    }});
    await renderPOS();
    document.getElementById('final-receipt').innerHTML=buildReceiptHTML(checkoutPayload.customer,checkoutPayload.items,checkoutPayload.subtotal,checkoutPayload.discount,checkoutPayload.tax_amount,checkoutPayload.total,method,settingsCache);
    closeModal('modal-checkout'); openModal('modal-receipt');
    document.getElementById('pos-customer-name').value='';
    showToast('Pembayaran berhasil! '+rp(checkoutPayload.total),'success');
    renderDashboard();
  } catch(e){ showToast('Gagal menyimpan: '+e.message,'error'); }
  finally { setBtn('btn-confirm-pay',false,'✓ Konfirmasi Pembayaran'); }
}

// ── MULTI DATE PICKER SYSTEM ─────────────────────────────
const _datePickers = {}; // namespace → {dates:[]}
function _dpNs(ns){ if(!_datePickers[ns]) _datePickers[ns]={dates:[]}; return _datePickers[ns]; }
function toggleDatePicker(ns){
  const dp=document.getElementById(ns+'-date-picker');
  const allDps=document.querySelectorAll('.date-picker-dropdown');
  allDps.forEach(d=>{ if(d!==dp) d.classList.remove('open'); });
  dp.classList.toggle('open');
}
function _closeDatePicker(ns){ document.getElementById(ns+'-date-picker').classList.remove('open'); }
function addSingleDate(ns){
  const input=document.getElementById(ns+'-dpd-single');
  const val=input.value; if(!val) return;
  const state=_dpNs(ns);
  if(!state.dates.includes(val)){ state.dates.push(val); state.dates.sort(); }
  input.value='';
  _renderDateChips(ns);
}
function addDateRange(ns){
  const from=document.getElementById(ns+'-dpd-from').value;
  const to=document.getElementById(ns+'-dpd-to').value;
  if(!from||!to){ showToast('Pilih tanggal dari dan sampai!','error'); return; }
  const state=_dpNs(ns);
  const d=new Date(from); const end=new Date(to);
  if(d>end){ showToast('Tanggal dari harus sebelum tanggal sampai','error'); return; }
  while(d<=end){
    const s=d.toISOString().substr(0,10);
    if(!state.dates.includes(s)) state.dates.push(s);
    d.setDate(d.getDate()+1);
  }
  state.dates.sort();
  document.getElementById(ns+'-dpd-from').value='';
  document.getElementById(ns+'-dpd-to').value='';
  _renderDateChips(ns);
}
function removeDateChip(ns,date){
  const state=_dpNs(ns);
  state.dates=state.dates.filter(d=>d!==date);
  _renderDateChips(ns);
}
function clearDatePicker(ns){
  _dpNs(ns).dates=[];
  _renderDateChips(ns);
  _updateDateBtn(ns);
}
function setDatePreset(ns,preset){
  const state=_dpNs(ns); state.dates=[];
  const t=new Date(_phpToday);
  if(preset==='today'){ state.dates=[_phpToday]; }
  else if(preset==='yesterday'){ const y=new Date(t); y.setDate(y.getDate()-1); state.dates=[y.toISOString().substr(0,10)]; }
  else if(preset==='week'){ for(let i=6;i>=0;i--){ const d=new Date(t); d.setDate(d.getDate()-i); state.dates.push(d.toISOString().substr(0,10)); } }
  else if(preset==='month'){
    const m=_phpToday.substr(0,7);
    const daysInMonth=new Date(t.getFullYear(),t.getMonth()+1,0).getDate();
    for(let i=1;i<=Math.min(daysInMonth,t.getDate());i++){ state.dates.push(m+'-'+(i<10?'0':'')+i); }
  }
  _renderDateChips(ns);
}
function _renderDateChips(ns){
  const state=_dpNs(ns);
  const container=document.getElementById(ns+'-date-chips');
  if(!container) return;
  container.innerHTML=state.dates.map(d=>`
    <div class="dpd-chip">
      <span>${fmtDate(d)}</span>
      <button class="dpd-chip-del" onclick="removeDateChip('${ns}','${d}')">×</button>
    </div>`).join('');
}
function applyDatePicker(ns){
  _updateDateBtn(ns);
  _closeDatePicker(ns);
  // Trigger re-render: reset to page 1 (result set size just changed) and refresh whichever orders view - table/timeline - is active
  if(ns==='orders') renderOrders(true);
}
function _updateDateBtn(ns){
  const state=_dpNs(ns);
  const btn=document.getElementById(ns+'-mdb-btn');
  const lbl=document.getElementById(ns+'-mdb-label');
  const count=document.getElementById(ns+'-mdb-count');
  if(!btn) return;
  if(!state.dates.length){
    btn.classList.remove('has-dates');
    lbl.textContent='Pilih Tanggal';
    count.style.display='none';
  } else if(state.dates.length===1){
    btn.classList.add('has-dates');
    lbl.textContent=fmtDate(state.dates[0]);
    count.style.display='none';
  } else {
    btn.classList.add('has-dates');
    lbl.textContent=fmtDate(state.dates[0])+' …';
    count.style.display='inline';
    count.textContent=state.dates.length;
  }
}
// Close date picker on outside click
document.addEventListener('click',function(e){
  document.querySelectorAll('.date-picker-dropdown.open').forEach(dp=>{
    if(!dp.closest('.date-filter-wrap').contains(e.target)) dp.classList.remove('open');
  });
});

// ── ORDERS ─────────────────────────────────────────────────
function setOrderView(view){
  const tl=document.getElementById('orders-timeline');
  const tb=document.getElementById('orders-table-wrap');
  const btnTl=document.getElementById('btn-view-timeline');
  const btnTb=document.getElementById('btn-view-table');
  if(view==='timeline'){
    tl.classList.add('active'); tb.classList.remove('active');
    btnTl.classList.add('active'); btnTb.classList.remove('active');
    renderOrdersTimeline();
  } else {
    tb.classList.add('active'); tl.classList.remove('active');
    btnTb.classList.add('active'); btnTl.classList.remove('active');
    renderOrders();
  }
}
async function renderOrdersTimeline(){
  const tl=document.getElementById('orders-timeline');
  tl.innerHTML='<div style="padding:20px;color:var(--text-muted);text-align:center">Memuat…</div>';
  try {
    const status=document.getElementById('order-filter').value;
    const typeFilter=document.getElementById('order-type-filter').value;
    const q=document.getElementById('order-search').value;
    const selectedDates=_dpNs('orders').dates;
    let extra='limit=200&';
    if(status) extra+=`status=${status}&`;
    if(typeFilter) extra+=`type=${typeFilter}&`;
    if(q) extra+=`q=${encodeURIComponent(q)}&`;
    // If single date selected, pass as filter; multiple dates handled client-side
    if(selectedDates.length===1) extra+=`date=${selectedDates[0]}&`;
    const j=await api('orders',{extra});
    let orders=j.data;
    // Filter by multiple dates client-side
    if(selectedDates.length>1){
      orders=orders.filter(o=>{
        const d=o.pickup_date||o.created_at?.substr(0,10);
        return selectedDates.includes(d);
      });
    }
    if(!orders.length){
      tl.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">Tidak ada pesanan ditemukan</div>';
      return;
    }
    // Group by pickup_date (or created_at for walk-ins)
    const groups={};
    orders.forEach(o=>{
      const key=o.pickup_date||o.created_at?.substr(0,10)||'—';
      if(!groups[key]) groups[key]=[];
      groups[key].push(o);
    });
    const sortedKeys=Object.keys(groups).sort();
    tl.innerHTML=sortedKeys.map(day=>{
      const dayOrders=groups[day];
      const cards=dayOrders.map(o=>`
        <div class="tl-card status-${o.status}">
          <div class="tl-status-dot ${o.status}"></div>
          <div class="tl-card-ref">${o.order_ref}</div>
          <div class="tl-card-name">${o.customer_name}</div>
          <div class="tl-card-meta">${o.pickup_time?o.pickup_time.substr(0,5):'—'}</div>
          <div class="tl-card-footer">
            <span class="tl-card-total">${rp(o.total)}</span>
            <span class="tl-type-badge ${o.type==='walkin'?'walkin':''}">${o.type==='walkin'?'Walk-in':'Pre-order'}</span>
          </div>
        </div>`).join('');
      return `<div class="tl-day">
        <div class="tl-day-header">
          <span class="tl-day-label">${fmtDate(day)}</span>
          <span class="tl-day-count">${dayOrders.length} pesanan</span>
          <div class="tl-day-line"></div>
        </div>
        <div class="tl-lane">${cards}</div>
      </div>`;
    }).join('');
  } catch(e){ tl.innerHTML=`<div style="padding:20px;color:var(--primary)">Error: ${e.message}</div>`; }
}
let _ordersDebounce;
let ordersPage = 1;
const ordersPerPage = 15;
function renderOrders(resetPage){
  if(resetPage) ordersPage=1;
  clearTimeout(_ordersDebounce);
  _ordersDebounce=setTimeout(_renderActiveOrdersView,120);
}
function _renderActiveOrdersView(){
  // Status/type/search/date filters should live-update whichever orders view is on screen
  const tlActive=document.getElementById('orders-timeline').classList.contains('active');
  if(tlActive) renderOrdersTimeline(); else _doRenderOrders();
}
function changeOrdersPage(page){ ordersPage=page; _doRenderOrders(); }
async function _doRenderOrders(){
  document.getElementById('orders-tbody').innerHTML=skRows(4);
  try {
    const status=document.getElementById('order-filter').value;
    const selectedDates=_dpNs('orders').dates;
    const q=document.getElementById('order-search').value;
    const typeFilter=document.getElementById('order-type-filter').value;
    let extra='limit=200&';
    if(status) extra+=`status=${status}&`;
    if(selectedDates.length===1) extra+=`date=${selectedDates[0]}&`;
    if(q)      extra+=`q=${encodeURIComponent(q)}&`;
    if(typeFilter) extra+=`type=${typeFilter}&`;
    const j=await api('orders',{extra});
    let orders=j.data;
    // Multi-date filter client-side
    if(selectedDates.length>1){
      orders=orders.filter(o=>{
        const d=o.pickup_date||o.created_at?.substr(0,10);
        return selectedDates.includes(d);
      });
    }
    // Pagination
    const totalOrders=orders.length;
    const totalPages=Math.ceil(totalOrders/ordersPerPage);
    const pageOrders=orders.slice((ordersPage-1)*ordersPerPage, ordersPage*ordersPerPage);
    // Fetch items detail for current page
    const detailed=await Promise.all(pageOrders.map(o=>
      api('orders',{id:o.id}).then(r=>({...o,items:r.data.items||[]})).catch(()=>({...o,items:[]}))
    ));
    // Render pagination
    let pageButtons='';
    for(let p=1;p<=totalPages;p++){
      if(p===1||p===totalPages||Math.abs(p-ordersPage)<=1){
        pageButtons+=`<button class="page-btn ${p===ordersPage?'active':''}" onclick="changeOrdersPage(${p})">${p}</button>`;
      } else if(Math.abs(p-ordersPage)===2){
        pageButtons+='<span style="color:var(--text-muted);padding:0 4px">…</span>';
      }
    }
    document.getElementById('orders-pagination').innerHTML=totalPages>1?`<div class="pagination"><button class="page-btn" ${ordersPage===1?'disabled':''} onclick="changeOrdersPage(${ordersPage-1})">←</button>${pageButtons}<button class="page-btn" ${ordersPage===totalPages?'disabled':''} onclick="changeOrdersPage(${ordersPage+1})">→</button><span style="color:var(--text-muted);font-size:11px;margin-left:8px">${totalOrders} pesanan</span></div>`:'';
    document.getElementById('orders-tbody').innerHTML = detailed.length
      ? detailed.map(o=>{
          const maxQty=o.items.reduce((m,i)=>Math.max(m,parseInt(i.qty)||1),1);
          const itemsHTML=o.items.length
            ? `<div class="order-items-bars">${o.items.map(i=>{
                const qty=parseInt(i.qty)||1;
                const pct=Math.max(10,Math.round((qty/Math.max(maxQty,1))*100));
                return `<div class="oib-row">
                  <span class="oib-name">${i.item_name}</span>
                  <div class="oib-track"><div class="oib-fill" style="width:${pct}%"></div></div>
                  <span class="oib-qty">×${qty}</span>
                </div>`;
              }).join('')}</div>`
            : `<span style="color:var(--text-muted);font-size:11px">—</span>`;
          return `<tr>
            <td><span style="font-weight:700;color:var(--primary)">${o.order_ref}</span></td>
            <td><div style="font-weight:600">${o.customer_name}</div><div style="font-size:11px;color:var(--text-muted)">${o.customer_phone||''}</div></td>
            <td><span class="badge badge-${o.type==='walkin'?'muted':'pink'}">${o.type==='walkin'?'Walk-in':'Pre-order'}</span></td>
            <td>${itemsHTML}</td>
            <td>${o.pickup_date?fmtDate(o.pickup_date):'—'}${o.pickup_time?`<br><span style="font-size:11px;color:var(--text-muted)">${o.pickup_time.substr(0,5)}</span>`:''}</td>
            <td><div style="font-weight:700">${rp(o.total)}</div><div style="font-size:11px;color:var(--green)">DP: ${rp(o.deposit)}</div></td>
            <td><span class="badge badge-${o.status==='pending'?'amber':o.status==='in-progress'?'blue':o.status==='ready'?'green':o.status==='completed'?'muted':'red'}">${o.status}</span></td>
            <td><select onchange="updateOrderStatus(${o.id},this.value)" style="font-size:12px;width:auto;padding:4px 7px">
              <option ${o.status==='pending'?'selected':''} value="pending">Pending</option>
              <option ${o.status==='in-progress'?'selected':''} value="in-progress">Diproses</option>
              <option ${o.status==='ready'?'selected':''} value="ready">Siap</option>
              <option ${o.status==='completed'?'selected':''} value="completed">Selesai</option>
              <option ${o.status==='cancelled'?'selected':''} value="cancelled">Batal</option>
            </select></td>
          </tr>`;
        }).join('')
      : `<tr><td colspan="8" style="text-align:center;padding:36px;color:var(--text-muted)">Tidak ada pesanan ditemukan</td></tr>`;
  } catch(e){ document.getElementById('orders-tbody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--primary)">Error: ${e.message}</td></tr>`; }
}
async function updateOrderStatus(id,status){
  try {
    await api('orders',{method:'PATCH',id,action:'status',body:{status}});
    showToast('Status diperbarui → '+status,'success');
    _doRenderOrders(); renderDashboard();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

// ── PREORDER MODAL ─────────────────────────────────────────
let poCart = [];

function openNewOrderModal(){
  // Reset form fields
  document.getElementById('ord-customer').value='';
  document.getElementById('ord-phone').value='';
  document.getElementById('ord-time').value='10:00';
  document.getElementById('ord-deposit').value='';
  document.getElementById('ord-notes').value='';
  document.getElementById('ord-date').value=today();

  // Reset cart
  poCart=[];
  renderPOCart();

  // Populate product dropdown
  const sel=document.getElementById('ord-prod-select');
  function fillDropdown(products){
    sel.innerHTML='<option value="">— Pilih produk —</option>'
      +products.filter(p=>p.stock>0).map(p=>
        `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}">${p.name} — ${rp(p.price)} (stok: ${p.stock})</option>`
      ).join('');
  }
  if(allProducts.length){
    fillDropdown(allProducts);
  } else {
    api('products').then(j=>{ allProducts=j.data; fillDropdown(j.data); }).catch(()=>{});
  }

  // Open the modal
  document.getElementById('modal-new-order').classList.add('open');
}
async function loadExistingOrders(){
  const el=document.getElementById('po-existing-list');
  if(!el) return;
  try {
    // Fetch pending + in-progress + ready orders
    const [pen,inp,rdy]=await Promise.all([
      api('orders',{extra:'status=pending&limit=20'}),
      api('orders',{extra:'status=in-progress&limit=10'}),
      api('orders',{extra:'status=ready&limit=10'}),
    ]);
    const all=[...pen.data,...inp.data,...rdy.data];
    // Sort by pickup_date
    all.sort((a,b)=>(a.pickup_date||'9999')>(b.pickup_date||'9999')?1:-1);
    if(!all.length){
      el.innerHTML='<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px">Tidak ada pesanan aktif</div>';
      return;
    }
    // Fetch items for each (up to 15)
    const detailed=await Promise.all(all.slice(0,15).map(o=>
      api('orders',{id:o.id}).then(r=>({...o,items:r.data.items||[]})).catch(()=>({...o,items:[]}))
    ));
    el.innerHTML=detailed.map(o=>{
      const statusColor=o.status==='pending'?'var(--amber)':o.status==='in-progress'?'#1a56c4':'var(--green)';
      const itemsHTML=o.items.length
        ?o.items.map(i=>`<div class="po-existing-item-row"><span>${i.item_name}</span><strong>×${i.qty}</strong></div>`).join('')
        :'<div class="po-existing-item-row"><span style="color:var(--text-muted)">Tidak ada item detail</span></div>';
      return `<div class="po-existing-item">
        <div class="po-existing-ref">${o.order_ref} <span style="font-size:9.5px;padding:1px 7px;border-radius:10px;background:${statusColor}22;color:${statusColor};font-weight:700">${o.status}</span></div>
        <div class="po-existing-name">${o.customer_name}</div>
        <div class="po-existing-meta">
          <span><i class="fa-solid fa-calendar fa-xs"></i> ${o.pickup_date?fmtDate(o.pickup_date):'—'}</span>
          <span class="badge badge-${o.type==='walkin'?'muted':'pink'}" style="font-size:9px">${o.type==='walkin'?'Walk-in':'Pre-order'}</span>
        </div>
        <div class="po-existing-items">${itemsHTML}</div>
      </div>`;
    }).join('');
  } catch(e){ el.innerHTML=`<div style="padding:16px;color:var(--primary);font-size:12px">Gagal memuat: ${e.message}</div>`; }
}
function poAddFromDropdown(){
  const sel=document.getElementById('ord-prod-select');
  if(!sel||!sel.value){ showToast('Pilih produk terlebih dahulu!','error'); return; }
  const opt=sel.options[sel.selectedIndex];
  const id=parseInt(sel.value);
  const price=parseFloat(opt.dataset.price)||0;
  const stock=parseInt(opt.dataset.stock)||1;
  const prod=allProducts.find(p=>p.id==id);
  if(!prod){ showToast('Produk tidak ditemukan','error'); return; }
  const existing=poCart.find(i=>i.id==id);
  if(existing){
    if(existing.qty>=stock){ showToast('Stok tidak mencukupi!','error'); return; }
    existing.qty=Math.min(existing.qty+1,stock);
  } else {
    poCart.push({id,name:prod.name,image_url:prod.image_url||'',price,qty:1,maxQty:stock});
  }
  sel.value='';
  renderPOCart();
}
function poSetQty(id, val){
  const item=poCart.find(i=>i.id==id);
  if(!item) return;
  const qty=Math.max(1,Math.min(parseInt(val)||1,item.maxQty));
  item.qty=qty;
  // Update the display label
  const lbl=document.getElementById('po-qty-val-'+id);
  if(lbl) lbl.textContent=qty;
  renderPOCartTotals();
}
function poRemoveItem(id){
  poCart=poCart.filter(i=>i.id!=id);
  renderPOCart();
}
function renderPOCartTotals(){
  const total=poCart.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('po-cart-total').textContent=rp(total);
  document.getElementById('po-cart-count').textContent=poCart.length;
}
function renderPOCart(){
  const el=document.getElementById('po-cart-items');
  if(!el) return;
  if(!poCart.length){
    el.innerHTML='<div class="po-cart-empty"><i class="fa-solid fa-basket-shopping"></i><div style="font-size:12px;margin-top:4px">Belum ada item</div></div>';
  } else {
    el.innerHTML=poCart.map(i=>`
      <div class="po-cart-item">
        <div class="po-cart-item-name">${i.name}<div style="font-size:11px;color:var(--text-muted)">${rp(i.price)} /pcs</div></div>
        <div class="po-qty-ctrl">
          <button class="pqc-btn" onclick="poAdjQty(${i.id},-1)">−</button>
          <span class="pqc-val" id="po-qty-val-${i.id}">${i.qty}</span>
          <button class="pqc-btn" onclick="poAdjQty(${i.id},1)">+</button>
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--primary-dark);min-width:70px;text-align:right">${rp(i.price*i.qty)}</div>
        <button class="po-remove-btn" onclick="poRemoveItem(${i.id})"><i class="fa-solid fa-xmark"></i></button>
      </div>`).join('');
  }
  renderPOCartTotals();
}
function poAdjQty(id, delta){
  const item=poCart.find(i=>i.id==id);
  if(!item) return;
  item.qty=Math.max(1,Math.min(item.qty+delta,item.maxQty||999));
  const lbl=document.getElementById('po-qty-val-'+id);
  if(lbl) lbl.textContent=item.qty;
  renderPOCartTotals();
}
async function saveOrder(){
  const customer=document.getElementById('ord-customer').value.trim();
  const date=document.getElementById('ord-date').value;
  if(!customer||!date){ showToast('Isi nama pelanggan dan tanggal ambil!','error'); return; }
  if(!poCart.length){ showToast('Tambahkan minimal 1 produk ke pesanan!','error'); return; }
  try {
    const cust=allCustomers.find(c=>c.name===customer);
    const subtotal=poCart.reduce((s,i)=>s+i.price*i.qty,0);
    const tax=Math.round(subtotal*(taxRate/100));
    const total=subtotal+tax;
    const deposit=parseFloat(document.getElementById('ord-deposit').value)||0;
    await api('orders',{method:'POST',body:{
      customer_id:cust?cust.id:null, guest_name:cust?'':customer,
      guest_phone:document.getElementById('ord-phone').value,
      type:'preorder', pickup_date:date, pickup_time:document.getElementById('ord-time').value,
      notes:document.getElementById('ord-notes').value||'',
      subtotal, discount:0, tax_amount:tax, total, deposit, status:'pending',
      items:poCart.map(i=>({product_id:i.id,item_name:i.name,image_url:i.image_url||'',qty:i.qty,unit_price:i.price,line_total:i.price*i.qty}))
    }});
    poCart=[];
    closeModal('modal-new-order'); showToast('Pre-order berhasil dibuat!','success'); renderOrders(); renderDashboard();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── CUSTOMERS ──────────────────────────────────────────────
let customersPage=1;
const customersPerPage=12;
function changeCustomersPage(page){ customersPage=page; renderCustomers(); }
async function renderCustomers(resetPage){
  if(resetPage) customersPage=1;
  try {
    const q=document.getElementById('cust-search').value;
    const j=await api('customers',{extra:q?`q=${encodeURIComponent(q)}`:''});
    const cs=j.data; allCustomers=cs;
    document.getElementById('cust-total').textContent=cs.length;
    document.getElementById('cust-regulars').textContent=cs.filter(c=>c.total_orders>=5).length;
    document.getElementById('loyalty-chart').innerHTML=[
      {label:'VIP (15+ pesanan)',cls:'',count:cs.filter(c=>c.total_orders>=15).length},
      {label:'Reguler (5–14)',cls:'green-fill',count:cs.filter(c=>c.total_orders>=5&&c.total_orders<15).length},
      {label:'Baru (1–4)',cls:'amber-fill',count:cs.filter(c=>c.total_orders<5).length},
    ].map(t=>`<div class="chart-row" style="margin-bottom:6px">
      <div class="chart-label" style="width:110px">${t.label}</div>
      <div class="chart-track"><div class="chart-fill ${t.cls}" style="width:${cs.length?Math.max(6,(t.count/cs.length)*100):0}%"><span>${t.count}</span></div></div>
    </div>`).join('');
    document.getElementById('customers-tbody').innerHTML = cs.length
      ? cs.map(c=>`<tr>
          <td><div style="font-weight:600">${c.name}</div>${c.instagram?`<div style="font-size:11px;color:var(--text-muted)">${c.instagram}</div>`:''}</td>
          <td style="font-size:12px">${c.phone}</td>
          <td style="text-align:center;font-weight:600">${c.total_orders}</td>
          <td><span class="badge ${c.total_orders>=15?'badge-red':c.total_orders>=5?'badge-green':'badge-muted'}">${c.total_orders>=15?'VIP':c.total_orders>=5?'Reguler':'Baru'}</span></td>
          <td><button class="btn btn-danger btn-sm" onclick="deleteCustomer(${c.id})">Hapus</button></td>
        </tr>`).join('')
      : `<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted)"><div>Belum ada pelanggan</div><div style="font-size:11px;margin-top:6px">Tambahkan pelanggan pertama dengan tombol + di atas</div></td></tr>`;
    // Pagination
    const totalCustomers=cs.length;
    const totalCustPages=Math.ceil(totalCustomers/customersPerPage);
    const startC=(customersPage-1)*customersPerPage;
    const pageCs=cs.slice(startC,startC+customersPerPage);
    // Re-render tbody with paginated data
    document.getElementById('customers-tbody').innerHTML = pageCs.length
      ? pageCs.map(c=>`<tr>
          <td><div style="font-weight:600">${c.name}</div>${c.instagram?`<div style="font-size:11px;color:var(--text-muted)">${c.instagram}</div>`:''}</td>
          <td style="font-size:12px">${c.phone}</td>
          <td style="text-align:center;font-weight:600">${c.total_orders}</td>
          <td><span class="badge ${c.total_orders>=15?'badge-red':c.total_orders>=5?'badge-green':'badge-muted'}">${c.total_orders>=15?'VIP':c.total_orders>=5?'Reguler':'Baru'}</span></td>
          <td><button class="btn btn-danger btn-sm" onclick="deleteCustomer(${c.id})">Hapus</button></td>
        </tr>`).join('')
      : `<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted)"><div>Belum ada pelanggan</div><div style="font-size:11px;margin-top:6px">Tambahkan pelanggan pertama dengan tombol + di atas</div></td></tr>`;
    let custPageButtons='';
    for(let p=1;p<=totalCustPages;p++){
      if(p===1||p===totalCustPages||Math.abs(p-customersPage)<=1) custPageButtons+=`<button class="page-btn ${p===customersPage?'active':''}" onclick="changeCustomersPage(${p})">${p}</button>`;
      else if(Math.abs(p-customersPage)===2) custPageButtons+='<span style="color:var(--text-muted);padding:0 4px">…</span>';
    }
    const custPagEl=document.getElementById('customers-pagination');
    if(custPagEl) custPagEl.innerHTML=totalCustPages>1?`<div class="pagination"><button class="page-btn" ${customersPage===1?'disabled':''} onclick="changeCustomersPage(${customersPage-1})">←</button>${custPageButtons}<button class="page-btn" ${customersPage===totalCustPages?'disabled':''} onclick="changeCustomersPage(${customersPage+1})">→</button><span style="color:var(--text-muted);font-size:11px;margin-left:8px">${totalCustomers} pelanggan</span></div>`:'';
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function saveCustomer(){
  const name=document.getElementById('cust-name').value.trim();
  const phone=document.getElementById('cust-phone').value.trim();
  if(!name||!phone){ showToast('Nama dan telepon wajib diisi!','error'); return; }
  try {
    await api('customers',{method:'POST',body:{name,phone,instagram:document.getElementById('cust-ig').value,birthday:document.getElementById('cust-bday').value||null,allergies:'',notes:document.getElementById('cust-notes').value}});
    closeModal('modal-new-customer'); showToast(name+' berhasil ditambahkan!','success'); renderCustomers();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function deleteCustomer(id){
  if(!confirm('Hapus pelanggan ini dari database?')) return;
  try { await api('customers',{method:'DELETE',id}); showToast('Pelanggan dihapus','success'); renderCustomers(); }
  catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── INVENTORY ──────────────────────────────────────────────
async function renderInventory(){
  document.getElementById('inventory-tbody').innerHTML=skRows(5);
  try {
    const j=await api('inventory');
    const keyword = document
      .getElementById('inventory-search')
      ?.value.toLowerCase() || '';

    const filtered = j.data.filter(i =>
      i.name.toLowerCase().includes(keyword) ||
      i.category_name.toLowerCase().includes(keyword)
    );
    const totalPages = Math.ceil(filtered.length / inventoryPerPage);

    const start = (inventoryPage - 1) * inventoryPerPage;
    const end = start + inventoryPerPage;

    const paginated = filtered.slice(start, end);
    document.getElementById('inventory-tbody').innerHTML = filtered.length
     ? paginated.map(i=>{
          const low=parseFloat(i.stock)<parseFloat(i.min_stock);
          return `<tr>
            <td><span style="font-weight:600">${i.name}</span></td>
            <td><span class="badge badge-pink">${i.category_name}</span></td>
            <td class="${low?'stock-low':'stock-ok'}">${parseFloat(i.stock).toLocaleString('id-ID')}</td>
            <td>${i.unit}</td>
            <td style="color:var(--text-muted)">${i.min_stock}</td>
            <td>${rp(i.cost_per_unit)}</td>
            <td><span class="badge ${low?'badge-red':'badge-green'}">${low?'⚠ Menipis':'✓ Aman'}</span></td>
            <td><button class="btn btn-outline btn-sm" onclick="restockIng(${i.id},'${i.name.replace(/'/g,"\\'")}',${i.stock},'${i.unit}')">+ Restok</button></td>
          </tr>`;}).join('')
      : `<tr><td colspan="8" style="text-align:center;padding:36px;color:var(--text-muted)">Belum ada bahan</td></tr>`;
      let pageButtons = '';

      for(let p=1; p<=totalPages; p++){
        pageButtons += `
          <button
            class="page-btn ${p===inventoryPage?'active':''}"
            onclick="changeInventoryPage(${p})">
            ${p}
          </button>
        `;
      }

      document.getElementById('inventory-pagination').innerHTML = `
        <div class="pagination">

          <button
            class="page-btn"
            ${inventoryPage===1?'disabled':''}
            onclick="changeInventoryPage(${inventoryPage-1})">
            ←
          </button>

          ${pageButtons}

          <button
            class="page-btn"
            ${inventoryPage===totalPages?'disabled':''}
            onclick="changeInventoryPage(${inventoryPage+1})">
            →
          </button>

        </div>
      `;
  } catch(e){ document.getElementById('inventory-tbody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--primary)">Error: ${e.message}</td></tr>`; }
}
function changeInventoryPage(page){
  inventoryPage = page;
  renderInventory();
}
async function restockIng(id,name,current,unit){
  const amt=prompt(`Restok "${name}"\nStok saat ini: ${current} ${unit}\nTambahkan berapa ${unit}?`);
  if(!amt||isNaN(amt)||parseInt(amt)<=0) return;
  try {
    const r=await api('inventory',{method:'PATCH',id,action:'restock',body:{qty:parseInt(amt),cost_total:0}});
    showToast(`${name} direstok! Sekarang ${r.data.new_stock} ${unit}`,'success'); renderInventory(); renderDashboard();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}
async function saveIngredient(){
  const name=document.getElementById('ing-name').value.trim();
  if(!name){ showToast('Nama bahan wajib diisi!','error'); return; }
  try {
    await api('inventory',{method:'POST',body:{name,category_id:parseInt(document.getElementById('ing-cat').value),stock:parseFloat(document.getElementById('ing-stock').value)||0,unit:document.getElementById('ing-unit').value,min_stock:parseFloat(document.getElementById('ing-min').value)||0,cost_per_unit:parseFloat(document.getElementById('ing-cost').value)||0,supplier:document.getElementById('ing-supplier').value}});
    closeModal('modal-new-ingredient'); showToast(name+' ditambahkan ke inventori!','success'); renderInventory(); renderDashboard();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── RECIPES ────────────────────────────────────────────────
async function renderRecipes(){
  document.getElementById('recipes-grid').innerHTML='<div style="grid-column:1/-1;text-align:center;padding:50px;color:var(--text-muted)">Memuat resep…</div>';
  try {
    const j=await api('recipes');
    // Fetch full details with ingredients for each recipe
    const [detailed, productsJ]=await Promise.all([
      Promise.all(j.data.map(r=>api('recipes',{id:r.id}).then(x=>x.data).catch(()=>r))),
      api('products',{extra:'all=1'}).catch(()=>({data:[]}))
    ]);
    const products = productsJ.data||[];
    document.getElementById('recipes-grid').innerHTML = detailed.length
      ? detailed.map(r=>{
          const instructions = r.instructions||'';
          const lines = instructions.split('\n').filter(l=>l.trim()).slice(0,5);
          const isNumbered = lines.some(l=>/^\d+\./.test(l.trim()));
          const stepsHTML = lines.map((l,idx)=>`<li>${isNumbered?l.replace(/^\d+\.\s*/,''):l}</li>`).join('');
          // Match product image: prefer product_id linkage, fallback to name match
          let imgUrl = r.image_url||'';
          if(!imgUrl && r.product_id){
            const matched=products.find(p=>p.id==r.product_id);
            if(matched&&matched.image_url) imgUrl=matched.image_url;
          }
          if(!imgUrl){
            // Fuzzy name match: recipe name contains product name or vice versa
            const rname=(r.name||'').toLowerCase();
            const matched=products.find(p=>{
              const pname=(p.name||'').toLowerCase();
              return pname&&(rname.includes(pname)||pname.includes(rname)||rname.split(' ').some(w=>w.length>3&&pname.includes(w)));
            });
            if(matched&&matched.image_url) imgUrl=matched.image_url;
          }
          return `<div class="recipe-card">
            <div class="recipe-img">
              ${imgUrl
                ? `<img src="${imgUrl}" alt="${r.name}" loading="lazy">`
                : `<div class="no-img-placeholder"><i class="fa-solid fa-bread-slice"></i></div>`}
            </div>
            <div class="recipe-body">
              <div class="recipe-name">${r.name}</div>
              <div class="recipe-meta">
                <div class="recipe-meta-item"><i class="fa-regular fa-clock"></i><span><strong>${r.prep_minutes} menit</strong><br><span style="color:var(--text-muted);font-size:10px">Persiapan</span></span></div>
                <div class="recipe-meta-item"><i class="fa-solid fa-fire-burner"></i><span><strong>${r.bake_minutes} menit</strong><br><span style="color:var(--text-muted);font-size:10px">Panggang</span></span></div>
                <div class="recipe-meta-item"><i class="fa-solid fa-box"></i><span><strong>${r.yield_qty} pcs</strong><br><span style="color:var(--text-muted);font-size:10px">Hasil</span></span></div>
              </div>
              ${r.ingredients&&r.ingredients.length?`<div class="recipe-cara" style="margin-bottom:10px">
                <div class="recipe-cara-title"><i class="fa-solid fa-wheat-awn" style="margin-right:4px"></i>Bahan-bahan (${r.ingredients.length} item)</div>
                <div style="display:flex;flex-direction:column;gap:3px">${r.ingredients.map(ing=>`<div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 4px"><span style="color:var(--text-sub)">${ing.ingredient_name}</span><strong style="color:var(--primary-dark)">${parseFloat(ing.quantity)} ${ing.unit}</strong></div>`).join('')}</div>
              </div>`:''}
          ${lines.length?`<div class="recipe-cara">
                <div class="recipe-cara-title">Cara Membuat</div>
                <ol>${stepsHTML}</ol>
              </div>`:''}
              <div class="recipe-footer">
                <button class="btn btn-danger btn-sm" onclick="deleteRecipe(${r.id})"><i class="fa-solid fa-trash"></i> Hapus</button>
              </div>
            </div>
          </div>`;
        }).join('')
      : `<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">📖</div><div class="empty-title">Belum ada resep</div><div>Tambahkan resep pertama Mocardi Anda</div></div>`;
  } catch(e){ document.getElementById('recipes-grid').innerHTML=`<div style="grid-column:1/-1;color:var(--primary);padding:24px">Error: ${e.message}</div>`; }
}
// Recipe ingredient builder
let recIngList = [];
async function openNewRecipeModal(){
  recIngList = [];
  document.getElementById('rec-name').value='';
  document.getElementById('rec-yield').value=12;
  document.getElementById('rec-prep').value=30;
  document.getElementById('rec-bake').value=25;
  document.getElementById('rec-instructions').value='';
  document.getElementById('rec-ing-list').innerHTML='';
  document.getElementById('rec-ing-qty').value='';
  // Load inventory items for dropdown
  const sel=document.getElementById('rec-ing-item');
  sel.innerHTML='<option value="">— Pilih bahan dari inventori —</option>';
  try {
    const inv=await api('inventory');
    const groups={};
    inv.data.forEach(i=>{ if(!groups[i.category_name]) groups[i.category_name]=[]; groups[i.category_name].push(i); });
    Object.entries(groups).sort((a,b)=>a[0].localeCompare(b[0])).forEach(([grp,items])=>{
      const og=document.createElement('optgroup'); og.label=grp;
      items.forEach(i=>{ const o=document.createElement('option'); o.value=i.id; o.textContent=i.name+' ('+parseFloat(i.stock).toLocaleString('id-ID')+' '+i.unit+')'; o.dataset.unit=i.unit; o.dataset.name=i.name; og.appendChild(o); });
      sel.appendChild(og);
    });
  } catch(e){}
  openModal('modal-new-recipe');
}
function recAddIngredient(){
  const sel=document.getElementById('rec-ing-item');
  const qty=parseFloat(document.getElementById('rec-ing-qty').value)||0;
  const unit=document.getElementById('rec-ing-unit').value;
  if(!sel.value||!qty){ showToast('Pilih bahan dan isi jumlah!','error'); return; }
  const opt=sel.options[sel.selectedIndex];
  const existing=recIngList.find(i=>i.inventory_id==sel.value);
  if(existing){ existing.quantity=qty; existing.unit=unit; }
  else recIngList.push({inventory_id:parseInt(sel.value),name:opt.dataset.name||opt.text,quantity:qty,unit});
  sel.value=''; document.getElementById('rec-ing-qty').value='';
  renderRecIngList();
}
function recRemoveIng(invId){ recIngList=recIngList.filter(i=>i.inventory_id!=invId); renderRecIngList(); }
function renderRecIngList(){
  const el=document.getElementById('rec-ing-list');
  if(!el) return;
  el.innerHTML=recIngList.map(i=>`
    <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--pink-bg);border-radius:8px;border:1px solid var(--border)">
      <i class="fa-solid fa-wheat-awn" style="color:var(--primary);font-size:11px"></i>
      <span style="flex:1;font-size:12px;font-weight:500">${i.name}</span>
      <span style="font-size:12px;color:var(--primary-dark);font-weight:600">${i.quantity} ${i.unit}</span>
      <button onclick="recRemoveIng(${i.inventory_id})" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px 4px;font-size:12px" title="Hapus"><i class="fa-solid fa-xmark"></i></button>
    </div>`).join('');
}
async function saveRecipe(){
  const name=document.getElementById('rec-name').value.trim();
  if(!name){ showToast('Nama resep wajib diisi!','error'); return; }
  try {
    await api('recipes',{method:'POST',body:{name,category_id:parseInt(document.getElementById('rec-cat').value),yield_qty:parseInt(document.getElementById('rec-yield').value)||1,yield_unit:'pcs',prep_minutes:parseInt(document.getElementById('rec-prep').value)||0,bake_minutes:parseInt(document.getElementById('rec-bake').value)||0,instructions:document.getElementById('rec-instructions').value,notes:'',ingredients:recIngList}});
    closeModal('modal-new-recipe'); recIngList=[]; showToast(name+' berhasil disimpan!','success'); renderRecipes();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function deleteRecipe(id){
  if(!confirm('Hapus resep ini?')) return;
  try { await api('recipes',{method:'DELETE',id}); showToast('Resep dihapus'); renderRecipes(); }
  catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── PRODUCTION ─────────────────────────────────────────────
async function renderProduction(){
  try {
    const j=await api('production');
    const all=j.data, todayP=all.filter(p=>p.batch_date===today());
    document.getElementById('today-plan').innerHTML = todayP.length
      ? todayP.map(p=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:11px;background:var(--pink-bg);border-radius:10px;margin-bottom:9px;border:1px solid var(--border)">
          <div><div style="font-weight:600">${p.product_name}</div><div style="font-size:12px;color:var(--text-muted)">Baker: ${p.baker}</div></div>
          <div style="text-align:right"><div style="font-weight:700;font-size:18px;color:var(--primary)">${p.qty_produced}</div><span class="badge badge-${p.status==='completed'?'green':'amber'}">${p.status}</span></div>
        </div>`).join('')
      : `<div class="empty-state" style="padding:30px"><div class="empty-title">Belum ada batch hari ini</div></div>`;
    document.getElementById('prod-log-tbody').innerHTML = all.length
      ? all.map(p=>`<tr>
          <td style="font-size:12px">${fmtDate(p.batch_date)}</td>
          <td style="font-weight:500">${p.product_name}</td>
          <td style="font-weight:700;color:var(--primary-dark);text-align:center">${p.batches_count||'—'}</td>
          <td style="font-weight:700;color:var(--primary)">${p.qty_produced} pcs</td>
          <td style="font-size:12px;color:var(--text-muted)">${p.baker}</td>
          <td><span class="badge badge-${p.status==='completed'?'green':p.status==='in-progress'?'amber':'muted'}">${p.status}</span></td>
          <td>
            <div style="display:flex;gap:5px">
              <button class="btn btn-green btn-sm" onclick="completeBatch(${p.id})" ${p.status==='completed'?'disabled':''}>✓</button>
              <button class="btn btn-danger btn-sm" onclick="deleteBatch(${p.id})">Del</button>
            </div>
            </td>
        </tr>`).join('')
      : `<tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted)">Belum ada data produksi</td></tr>`;
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function saveBatch(){
  const sel=document.getElementById('batch-product-select');
  const product=sel.options[sel.selectedIndex]?.text?.split(' — ')[0]?.trim()||'';
  const productId=parseInt(sel.value)||null;
  if(!product||!sel.value){ showToast('Pilih produk terlebih dahulu!','error'); return; }
  const batchesCount=parseInt(document.getElementById('batch-count').value)||1;
  const qtyProduced=parseInt(document.getElementById('batch-qty').value)||0;
  if(qtyProduced<1){ showToast('Jumlah produksi harus lebih dari 0!','error'); return; }
  try {
    const r=await api('production',{method:'POST',body:{
      product_name:product,
      product_id:productId,
      recipe_id:parseInt(document.getElementById('batch-recipe-id')?.value)||null,
      batches_count:batchesCount,
      qty_produced:qtyProduced,
      baker:document.getElementById('batch-baker').value,
      batch_date:document.getElementById('batch-date').value||today(),
      status:'in-progress',
      notes:document.getElementById('batch-notes').value
    }});
    const deducted=r.data?.inventory_deducted||[];
    const added=r.data?.stock_added||0;
    let msg='Batch dicatat!';
    if(added) msg+=` Stok +${added} pcs.`;
    if(deducted.length) msg+=' Inventori dikurangi: '+deducted.map(d=>d.name+' −'+d.qty+' '+d.unit).join(', ');
    showToast(msg,'success');
    closeModal('modal-new-batch'); renderProduction(); renderDashboard(); if(typeof renderInventory==='function') renderInventory();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
function onBatchCountChange(){
  const yieldQty=parseInt(document.getElementById('batch-yield-qty')?.value)||0;
  const batches=parseInt(document.getElementById('batch-count')?.value)||1;
  const hint=document.getElementById('batch-count-hint');
  if(yieldQty>0){
    document.getElementById('batch-qty').value=batches*yieldQty;
    if(hint) hint.textContent=`${batches} batch × ${yieldQty} pcs/batch = ${batches*yieldQty} pcs`;
  } else {
    if(hint) hint.textContent='Pilih produk dengan resep untuk auto-hitung';
  }
}
async function onBatchProductChange(sel){
  const info=document.getElementById('batch-prod-info');
  const recipeInput=document.getElementById('batch-recipe-id');
  const yieldInput=document.getElementById('batch-yield-qty');
  if(!sel.value){
    info.className='batch-prod-info';
    if(recipeInput) recipeInput.value='';
    if(yieldInput) yieldInput.value=0;
    document.getElementById('batch-count-hint').textContent='—';
    return;
  }
  const opt=sel.options[sel.selectedIndex];
  const price=opt.dataset.price?rp(opt.dataset.price):'—';
  const cat=opt.dataset.cat||'';
  const productId=parseInt(sel.value);
  info.className='batch-prod-info show';
  info.innerHTML=`<i class="fa-solid fa-tag" style="color:var(--primary);margin-right:4px"></i>${cat?`<strong>${cat}</strong> · `:''}Harga jual: ${price} <span style="color:var(--text-muted);margin-left:6px"><i class="fa-solid fa-spinner fa-spin"></i> Mencari resep…</span>`;
  if(recipeInput) recipeInput.value='';
  if(yieldInput) yieldInput.value=0;
  try {
    const rj=await api('recipes');
    const recipe=rj.data.find(r=>r.product_id==productId);
    if(recipe){
      if(recipeInput) recipeInput.value=recipe.id;
      const fullR=await api('recipes',{id:recipe.id});
      const yieldQty=parseInt(fullR.data.yield_qty)||1;
      if(yieldInput) yieldInput.value=yieldQty;
      const ings=fullR.data.ingredients||[];
      const ingHTML=ings.length
        ?`<div style="margin-top:5px;font-size:10.5px;color:var(--text-sub)">Bahan per batch: ${ings.map(i=>`${i.ingredient_name} ${parseFloat(i.quantity)}${i.unit}`).join(' · ')}</div>`
        :'';
      info.innerHTML=`<i class="fa-solid fa-book-open" style="color:var(--primary);margin-right:4px"></i>Resep: <strong>${recipe.name}</strong> · <strong>${yieldQty} pcs</strong>/batch · Harga jual: ${price}${ingHTML}`;
      // Auto-fill qty based on current batch count
      onBatchCountChange();
    } else {
      if(yieldInput) yieldInput.value=0;
      info.innerHTML=`<i class="fa-solid fa-tag" style="color:var(--primary);margin-right:4px"></i>${cat?`<strong>${cat}</strong> · `:''}Harga jual: ${price} <span style="color:var(--amber);margin-left:6px"><i class="fa-solid fa-exclamation-circle"></i> Tidak ada resep terhubung — inventori tidak akan dikurangi</span>`;
      document.getElementById('batch-count-hint').textContent='Tidak ada resep — isi jumlah produksi manual';
    }
  } catch(e){
    info.innerHTML=`<i class="fa-solid fa-tag" style="color:var(--primary);margin-right:4px"></i>${cat?`<strong>${cat}</strong> · `:''}Harga jual: ${price}`;
  }
}
async function openNewBatchModal(){
  document.getElementById('batch-date').value=today();
  document.getElementById('batch-count').value=1;
  document.getElementById('batch-qty').value=0;
  document.getElementById('batch-baker').value='';
  document.getElementById('batch-notes').value='';
  document.getElementById('batch-recipe-id').value='';
  document.getElementById('batch-yield-qty').value=0;
  document.getElementById('batch-count-hint').textContent='—';
  document.getElementById('batch-prod-info').className='batch-prod-info';
  // Populate dropdown from products
  const sel=document.getElementById('batch-product-select');
  sel.innerHTML='<option value="">— Pilih produk —</option>';
  try {
    const j=allProducts.length?{data:allProducts}:await api('products',{extra:'all=1'});
    if(!allProducts.length) allProducts=j.data;
    j.data.forEach(p=>{
      const o=document.createElement('option');
      o.value=p.id; o.textContent=`${p.name} — ${rp(p.price)}`;
      o.dataset.price=p.price; o.dataset.cat=p.category_name||'';
      sel.appendChild(o);
    });
  } catch(e){}
  openModal('modal-new-batch');
}
async function completeBatch(id){
  try {
    const j=await api('production',{id}); const b=j.data;
    await api('production',{method:'PUT',id,body:{...b,status:'completed'}});
    showToast('Batch selesai!','success'); renderProduction(); renderDashboard();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function deleteBatch(id){
  if(!confirm('Hapus catatan batch ini?')) return;
  try { await api('production',{method:'DELETE',id}); showToast('Batch dihapus'); renderProduction(); }
  catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── REPORTS ────────────────────────────────────────────────
async function renderReports(){
  try {
    const period=document.getElementById('report-period').value;
    const [summ,top,orders]=await Promise.all([
      api('reports',{action:'summary',extra:`period=${period}`}),
      api('reports',{action:'top_products'}),
      api('orders',{extra:'limit=200'})
    ]);
    const s=summ.data;
    document.getElementById('rep-revenue').textContent=rp(s.total_revenue);
    document.getElementById('rep-cogs').textContent=rp(s.total_expenses);
    document.getElementById('rep-profit').textContent=rp(s.gross_profit);
    document.getElementById('rep-margin').textContent=s.margin_pct+'%';

    // ── Multi-bar chart: Pendapatan & Qty Terjual per produk ──
    const prods=top.data.slice(0,10);
    const maxRev=prods[0]?parseFloat(prods[0].total_revenue):1;
    const maxQty=Math.max(...prods.map(p=>parseFloat(p.total_qty)||0),1);
    const mbcEl=document.getElementById('multibar-chart');
    if(prods.length){
      mbcEl.innerHTML=`<div class="mbc-wrap"><div class="mbc-chart">`
        +prods.map(p=>{
          const revPct=Math.max(3,Math.round((parseFloat(p.total_revenue)/maxRev)*100));
          const qtyPct=Math.max(3,Math.round((parseFloat(p.total_qty)/maxQty)*100));
          const label=p.item_name.split(' ').slice(0,3).join(' ');
          return `<div class="mbc-row">
            <div class="mbc-label" title="${p.item_name}">${label}</div>
            <div class="mbc-bars">
              <div class="mbc-bar-row">
                <div class="mbc-track"><div class="mbc-fill-rev" style="width:${revPct}%"><span>${revPct>20?rp(p.total_revenue):''}</span></div></div>
                <span class="mbc-val rev">${rp(p.total_revenue)}</span>
              </div>
              <div class="mbc-bar-row">
                <div class="mbc-track"><div class="mbc-fill-qty" style="width:${qtyPct}%"><span>${qtyPct>20?p.total_qty+' pcs':''}</span></div></div>
                <span class="mbc-val qty">${p.total_qty} pcs</span>
              </div>
            </div>
          </div>`;
        }).join('')
        +`</div></div>`;
    } else {
      mbcEl.innerHTML='<div style="color:var(--text-muted);font-size:13px;padding:28px;text-align:center">Belum ada data penjualan</div>';
    }

    // ── Tabel transaksi gabungan (walkin + preorder) ──
    const allOrders=orders.data;
    document.getElementById('txn-count').textContent=allOrders.length+' transaksi';
    document.getElementById('rep-transactions').innerHTML = allOrders.length
      ? allOrders.map(o=>`<tr>
          <td style="font-size:11px">${fmtDate(o.created_at)}</td>
          <td><span style="font-weight:700;color:var(--primary);font-size:12px">${o.order_ref}</span></td>
          <td><div style="font-weight:600;font-size:12px">${o.customer_name}</div><div style="font-size:10px;color:var(--text-muted)">${o.customer_phone||''}</div></td>
          <td><span class="badge badge-${o.type==='walkin'?'muted':'pink'}">${o.type==='walkin'?'Walk-in':'Pre-order'}</span></td>
          <td style="font-size:11px;color:var(--text-sub);max-width:220px">${o.items_summary||'—'}</td>
          <td style="font-weight:700">${rp(o.total)}</td>
          <td style="font-size:11px;color:var(--green);font-weight:600">${rp(o.deposit)}</td>
          <td><span class="badge badge-${o.status==='pending'?'amber':o.status==='in-progress'?'blue':o.status==='ready'?'green':o.status==='completed'?'muted':'red'}">${o.status}</span></td>
        </tr>`).join('')
      : `<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--text-muted)">Belum ada transaksi</td></tr>`;
  } catch(e){ showToast('Error laporan: '+e.message,'error'); }
}
async function exportReport(){
  try {
    const period=document.getElementById('report-period').value;
    const [summj,topj,ordersj,expj]=await Promise.all([
      api('reports',{action:'summary',extra:`period=${period}`}),
      api('reports',{action:'top_products'}),
      api('orders',{extra:'limit=500'}),
      api('expenses',{extra:`month=${today().substr(0,7)}`}),
    ]);
    const s=summj.data;
    const rows=[];
    // ─ Header info
    rows.push(['=== LAPORAN KEUANGAN MOCARDI ===','','','','','','','','']);
    rows.push(['Periode',period==='month'?'Bulan Ini':period==='week'?'7 Hari Ini':'Tahun Ini','','','','','','','']);
    rows.push(['Tanggal Ekspor',today(),'','','','','','','']);
    rows.push(['','','','','','','','','']);
    // ─ Ringkasan keuangan
    rows.push(['=== RINGKASAN KEUANGAN ===','','','','','','','','']);
    rows.push(['Total Pendapatan','Total Pengeluaran','Keuntungan Bersih','Margin (%)']);
    rows.push([s.total_revenue,s.total_expenses,s.gross_profit,s.margin_pct+'%']);
    rows.push(['','','','','','','','','']);
    // ─ Semua transaksi + pesanan
    rows.push(['=== TRANSAKSI & PESANAN ===','','','','','','','','']);
    rows.push(['No. Pesanan','Pelanggan','Telepon','Jenis','Tanggal Buat','Tgl Ambil','Subtotal','Diskon','Pajak','Total','DP','Status','Metode Bayar']);
    ordersj.data.forEach(o=>rows.push([o.order_ref,o.customer_name,o.customer_phone||'',o.type,o.created_at,o.pickup_date||'',o.subtotal||'',o.discount||0,o.tax_amount||0,o.total,o.deposit||0,o.status,o.payment_method||'']));
    rows.push(['','','','','','','','','']);
    // ─ Top produk
    rows.push(['=== TOP PRODUK ===','','','','','','','','']);
    rows.push(['Nama Produk','Total Qty Terjual','Total Pendapatan']);
    topj.data.forEach(p=>rows.push([p.item_name,p.total_qty,p.total_revenue]));
    rows.push(['','','','','','','','','']);
    // ─ Pengeluaran
    rows.push(['=== PENGELUARAN ===','','','','','','','','']);
    rows.push(['Tanggal','Kategori','Deskripsi','Jumlah']);
    expj.data.forEach(e=>rows.push([e.expense_date,e.category,e.description,e.amount]));

    const csv=rows.map(r=>r.map(v=>`"${String(v||'').replace(/"/g,'""')}"`).join(',')).join('\n');
    const bom='\uFEFF';
    const a=document.createElement('a');
    a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(bom+csv);
    a.download=`mocardi-laporan-${period}-${today()}.csv`; a.click();
    showToast('Laporan lengkap diekspor!','success');
  } catch(e){ showToast('Gagal ekspor: '+e.message,'error'); }
}

// ── EXPENSES ───────────────────────────────────────────────
async function renderExpenses(){
  try {
    const month=today().substr(0,7);
    const j=await api('expenses',{extra:`month=${month}`});
    const exps=j.data;
    const total=exps.reduce((s,e)=>s+parseFloat(e.amount),0);
    const ings=exps.filter(e=>e.category==='Ingredients').reduce((s,e)=>s+parseFloat(e.amount),0);
    document.getElementById('exp-month').textContent=rp(total);
    document.getElementById('exp-ingredients').textContent=rp(ings);
    document.getElementById('exp-other').textContent=rp(total-ings);
    document.getElementById('expenses-tbody').innerHTML = exps.length
      ? exps.map(e=>`<tr>
          <td style="font-size:12px">${fmtDate(e.expense_date)}</td>
          <td><span class="badge badge-pink">${e.category}</span></td>
          <td>${e.description}</td>
          <td style="font-weight:700">${rp(e.amount)}</td>
          <td><button class="btn btn-danger btn-sm" onclick="deleteExpense(${e.id})">Del</button></td>
        </tr>`).join('')
      : `<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted)">Belum ada pengeluaran bulan ini</td></tr>`;
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function saveExpense(){
  const desc=document.getElementById('exp-desc').value.trim();
  const amount=parseFloat(document.getElementById('exp-amount').value)||0;
  if(!desc||!amount){ showToast('Isi semua kolom!','error'); return; }
  try {
    await api('expenses',{method:'POST',body:{category:document.getElementById('exp-cat').value,description:desc,amount,expense_date:document.getElementById('exp-date').value||today()}});
    closeModal('modal-new-expense'); showToast('Pengeluaran dicatat!','success'); renderExpenses();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}

// Cache for inventory items by category
let _inventoryCache = null;
async function getInventoryCache(){
  if(_inventoryCache) return _inventoryCache;
  try { const j=await api('inventory'); _inventoryCache=j.data; return _inventoryCache; }
  catch(e){ return []; }
}
async function onExpCatChange(){
  const cat=document.getElementById('exp-cat').value;
  const group=document.getElementById('exp-item-group');
  const sel=document.getElementById('exp-item-select');
  const sub=document.getElementById('exp-item-sub');
  // Show dropdown for inventory-related categories
  const showDropdown=['Ingredients','Packaging'].includes(cat);
  group.style.display=showDropdown?'block':'none';
  if(!showDropdown){ document.getElementById('exp-desc').value=''; return; }
  // Map category name to inventory category
  const invCatMap={'Ingredients':['Tepung','Susu','Pemanis','Lemak','Telur','Perisa','Topping','Lain'],'Packaging':['Kemasan']};
  sel.innerHTML='<option value="">— Pilih item dari inventori —</option>';
  sub.textContent='';
  try {
    const items=await getInventoryCache();
    const filtered=cat==='Packaging'
      ? items.filter(i=>i.category_name==='Kemasan')
      : cat==='Ingredients'
      ? items.filter(i=>i.category_name!=='Kemasan')
      : items;
    // Group by category
    const groups={};
    filtered.forEach(i=>{ if(!groups[i.category_name]) groups[i.category_name]=[]; groups[i.category_name].push(i); });
    Object.entries(groups).sort((a,b)=>a[0].localeCompare(b[0])).forEach(([grp,itms])=>{
      const og=document.createElement('optgroup'); og.label=grp;
      itms.forEach(i=>{ const o=document.createElement('option'); o.value=i.id; o.textContent=`${i.name} (${parseFloat(i.stock).toLocaleString('id-ID')} ${i.unit})`; o.dataset.name=i.name; o.dataset.unit=i.unit; o.dataset.stock=i.stock; o.dataset.cpu=i.cost_per_unit||0; og.appendChild(o); });
      sel.appendChild(og);
    });
    if(!filtered.length) sel.innerHTML='<option value="">Tidak ada item di kategori ini</option>';
  } catch(e){}
}
function onExpItemSelect(){
  const sel=document.getElementById('exp-item-select');
  const sub=document.getElementById('exp-item-sub');
  const desc=document.getElementById('exp-item-desc')||document.getElementById('exp-desc');
  if(!sel.value){ sub.textContent=''; return; }
  const opt=sel.options[sel.selectedIndex];
  const name=opt.dataset.name||''; const unit=opt.dataset.unit||''; const cpu=parseFloat(opt.dataset.cpu)||0;
  sub.innerHTML=`<i class="fa-solid fa-info-circle" style="color:var(--primary);margin-right:3px"></i>Harga/unit: <strong>${rp(cpu)}</strong> per ${unit}`;
  // Auto-fill description
  const descEl=document.getElementById('exp-desc');
  if(descEl&&!descEl.value) descEl.value=name;
}
async function openNewExpenseModal(){
  document.getElementById('exp-date').value=today();
  document.getElementById('exp-desc').value='';
  document.getElementById('exp-amount').value='';
  document.getElementById('exp-item-sub').textContent='';
  document.getElementById('exp-cat').value='Ingredients';
  await onExpCatChange();
  openModal('modal-new-expense');
}
async function deleteExpense(id){
  try { await api('expenses',{method:'DELETE',id}); showToast('Pengeluaran dihapus'); renderExpenses(); }
  catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── SETTINGS ───────────────────────────────────────────────
async function renderSettings(){
  try {
    const j=await api('settings'); const s=j.data; settingsCache=s;
    document.getElementById('set-name').value=s.bakery_name||'';
    document.getElementById('set-owner').value=s.owner_name||'';
    document.getElementById('set-phone').value=s.phone||'';
    document.getElementById('set-addr').value=s.address||'';
    document.getElementById('set-tax').value=s.tax_rate||10;
    document.getElementById('set-receipt-msg').value=s.receipt_message||'';
    document.getElementById('set-social').value=s.social_info||'';
    taxRate=parseFloat(s.tax_rate)||10;
    document.getElementById('cart-tax-pct').textContent=taxRate;
    document.getElementById('sidebar-bakery-name').textContent=s.bakery_name||'Mocardi';
    document.getElementById('sidebar-owner-name').textContent=(s.owner_name||'Owner')+' · Home Baker';
    const initials=((s.bakery_name||'MC').split(' ').map(w=>w[0]).join('').substr(0,2)).toUpperCase();
    document.getElementById('user-avatar-initials').textContent=initials;
    const pj=await api('products',{extra:'all=1'});
    document.getElementById('settings-products-tbody').innerHTML=pj.data.map(p=>`<tr>
      <td>
        <div class="settings-product">
          <div class="settings-product-img">
            <img src="${p.image_url}" alt="${p.name}">
          </div>

          <div>
            ${p.name}
            ${!p.is_active
              ? '<span class="badge badge-red" style="font-size:9px;margin-left:6px">nonaktif</span>'
              : ''
            }
          </div>
        </div>
      </td>
      <td>${rp(p.price)}</td><td>${p.stock}</td>
      <td><button class="btn btn-outline btn-sm" onclick="editProductPrice(${p.id},'${p.name.replace(/'/g,"\\'")}',${p.price})">Edit</button></td>
    </tr>`).join('');
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function saveSettings(){
  try {
    await api('settings',{method:'PUT',body:{bakery_name:document.getElementById('set-name').value,owner_name:document.getElementById('set-owner').value,phone:document.getElementById('set-phone').value,address:document.getElementById('set-addr').value,tax_rate:parseFloat(document.getElementById('set-tax').value)||10,currency:'IDR',receipt_message:document.getElementById('set-receipt-msg').value,social_info:document.getElementById('set-social').value}});
    showToast('Pengaturan disimpan!','success'); renderSettings();
  } catch(e){ showToast('Gagal menyimpan: '+e.message,'error'); }
}
async function editProductPrice(id,name,currentPrice){
  const price=prompt(`Edit harga "${name}":\nHarga saat ini: ${rp(currentPrice)}\nHarga baru (Rp):`);
  if(!price||isNaN(price)) return;
  try {
    const j=await api('products',{id}); const p=j.data;
    await api('products',{method:'PUT',id,body:{...p,price:parseInt(price),category_id:p.category_id}});
    showToast(name+' harga diperbarui!','success'); renderSettings();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}
async function saveProduct(){
  const name=document.getElementById('prod-name').value.trim();
  const price=parseInt(document.getElementById('prod-price').value)||0;
  if(!name||!price){ showToast('Nama dan harga wajib diisi!','error'); return; }
  try {
    await api('products',{method:'POST',body:{name,image_url:document.getElementById('prod-image').value,price,category_id:parseInt(document.getElementById('prod-cat').value),stock:parseInt(document.getElementById('prod-stock').value)||0}});
    closeModal('modal-new-product'); showToast(name+' ditambahkan!','success'); renderSettings();
    allProducts=(await api('products')).data; filterPOSTiles();
  } catch(e){ showToast('Error: '+e.message,'error'); }
}

// ── INIT ───────────────────────────────────────────────────
// Date label rendered server-side for instant display (no flash of "…" text).
document.getElementById('date-chip').textContent = <?php echo json_encode(phpTodayLabel()); ?>;
// Pre-fill any date inputs that haven't been given a value.
document.querySelectorAll('input[type=date]').forEach(el=>{ if(!el.value) el.value=today(); });

(async()=>{
  await checkDB();
  try {
    const j=await api('settings'); settingsCache=j.data;
    taxRate=parseFloat(j.data.tax_rate)||10;
    document.getElementById('cart-tax-pct').textContent=taxRate;
    document.getElementById('sidebar-bakery-name').textContent=j.data.bakery_name||'Mocardi';
    document.getElementById('sidebar-owner-name').textContent=(j.data.owner_name||'Owner')+' · Home Baker';
    const initials=((j.data.bakery_name||'MC').split(' ').map(w=>w[0]).join('').substr(0,2)).toUpperCase();
    document.getElementById('user-avatar-initials').textContent=initials;
  } catch(_){}
  renderDashboard();
  renderPOS();
})();
</script>
</body>
</html>
