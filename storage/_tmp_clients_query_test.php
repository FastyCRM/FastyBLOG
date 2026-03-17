<?php
define('ROOT_PATH', 'd:/OpenServer/domains/crm2026new');
require ROOT_PATH . '/core/bootstrap.php';
$pdo = db();
$tests = ['+79298', '79298', '2322', '2320', '232204445140', '23204445140'];
foreach ($tests as $q) {
  $like = '%' . $q . '%';
  $digits = preg_replace('~\D+~', '', $q) ?? '';
  $digitsLike = '%' . $digits . '%';
  $sql = "SELECT id, first_name, phone, inn FROM clients c";
  if ($q !== '') {
    $sql .= " WHERE (c.first_name LIKE :q OR c.last_name LIKE :q OR c.middle_name LIKE :q OR CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE :q OR c.phone LIKE :q OR c.inn LIKE :q OR c.email LIKE :q";
    if ($digits !== '') {
      $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') LIKE :q_digits OR c.inn LIKE :q_digits";
    }
    $sql .= ")";
  }
  $sql .= " ORDER BY c.id DESC LIMIT 10";
  $st = $pdo->prepare($sql);
  $params = [];
  if ($q !== '') {
    $params[':q'] = $like;
    if ($digits !== '') { $params[':q_digits'] = $digitsLike; }
  }
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo "Q={$q} => " . count($rows) . PHP_EOL;
  foreach ($rows as $r) {
    echo "  #{$r['id']} {$r['first_name']} | {$r['phone']} | {$r['inn']}" . PHP_EOL;
  }
}
