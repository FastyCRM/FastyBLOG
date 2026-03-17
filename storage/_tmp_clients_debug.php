<?php
define('ROOT_PATH', 'd:/OpenServer/domains/crm2026new');
require ROOT_PATH . '/core/bootstrap.php';
$pdo = db();
$rows = $pdo->query("SELECT id, first_name, last_name, phone, inn FROM clients ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
  echo (int)$r['id'] . " | " . ($r['first_name'] ?? '') . " | " . ($r['last_name'] ?? '') . " | " . ($r['phone'] ?? '') . " | " . ($r['inn'] ?? '') . PHP_EOL;
}
