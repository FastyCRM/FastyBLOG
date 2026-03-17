<?php
define('ROOT_PATH', 'd:/OpenServer/domains/crm2026new');
require ROOT_PATH . '/core/bootstrap.php';
$pdo = db();
$tests = ['+79298','79298','2322','2320'];
foreach ($tests as $q) {
  $digits = preg_replace('~\D+~', '', $q) ?? '';
  $sql = "SELECT c.id, c.first_name, c.phone, c.inn FROM clients c";
  $where = [];
  $params = [];
  $like = '%' . $q . '%';
  $where[] = "c.first_name LIKE ?"; $params[] = $like;
  $where[] = "c.last_name LIKE ?"; $params[] = $like;
  $where[] = "c.middle_name LIKE ?"; $params[] = $like;
  $where[] = "CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE ?"; $params[] = $like;
  $where[] = "c.email LIKE ?"; $params[] = $like;
  $where[] = "c.phone LIKE ?"; $params[] = $like;
  if ($digits !== '' && strlen($digits) >= 4) { $where[] = "c.inn LIKE ?"; $params[] = $digits . '%'; }
  if ($digits !== '' && strlen($digits) >= 5) {
    $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    $where[] = $phoneExpr . " LIKE ?"; $params[] = $digits . '%';
    if ($digits[0] === '7') { $where[] = $phoneExpr . " LIKE ?"; $params[] = ('8' . substr($digits,1)) . '%'; }
    elseif ($digits[0] === '8') { $where[] = $phoneExpr . " LIKE ?"; $params[] = ('7' . substr($digits,1)) . '%'; }
  }
  if ($where) $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
  $sql .= ' ORDER BY c.id DESC LIMIT 10';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo "Q={$q} => " . count($rows) . PHP_EOL;
  foreach ($rows as $r) { echo "  #{$r['id']} {$r['first_name']} | {$r['phone']} | {$r['inn']}" . PHP_EOL; }
}
