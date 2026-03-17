<?php
define('ROOT_PATH', 'd:/OpenServer/domains/crm2026new');
require ROOT_PATH . '/core/bootstrap.php';
$pdo = db();
try {
  $rows = $pdo->query("SELECT id, email FROM clients ORDER BY id DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
  var_export($rows);
} catch (Throwable $e) {
  echo 'ERR: ' . $e->getMessage() . PHP_EOL;
}
