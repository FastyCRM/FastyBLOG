<?php
define('ROOT_PATH', getcwd());
require_once ROOT_PATH . '/core/bootstrap.php';
$pdo = db();
$st = $pdo->query("SELECT id, created_at, platform, chat_id, message_text, send_status, error_text FROM promobot_logs ORDER BY id DESC LIMIT 20");
$rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($rows as $r) {
  $text = str_replace(["\n","\r"], ['\\n',''], (string)$r['message_text']);
  echo $r['id'] . " | " . $r['created_at'] . " | " . $r['platform'] . " | " . $r['chat_id'] . " | " . $r['send_status'] . " | " . $text . "\n";
}
?>
