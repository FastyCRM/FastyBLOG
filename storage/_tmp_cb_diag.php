<?php
error_reporting(E_ALL);
define('ROOT_PATH', 'd:/OpenServer/domains/FastyBLOG');
require ROOT_PATH . '/core/bootstrap.php';
$pdo = db();

echo "now=" . date('Y-m-d H:i:s') . PHP_EOL;

$tables = ['channel_bridge_jobs','channel_bridge_webhook_updates','channel_bridge_tg_posts'];
foreach ($tables as $t) {
  $st = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$t]);
  echo "table_{$t}=" . ((int)$st->fetchColumn() > 0 ? 'yes' : 'no') . PHP_EOL;
}

$st = $pdo->query("SELECT status, COUNT(*) cnt FROM channel_bridge_jobs GROUP BY status ORDER BY status");
echo "jobs_status:" . PHP_EOL;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  echo $r['status'] . '=' . $r['cnt'] . PHP_EOL;
}

$st = $pdo->query("SELECT id,job_type,job_key,status,attempts,available_at,locked_at,last_error,updated_at FROM channel_bridge_jobs ORDER BY id DESC LIMIT 10");
echo "jobs_last10:" . PHP_EOL;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$st = $pdo->query("SELECT id,update_id,source_chat_id,source_message_id,media_group_id,created_at FROM channel_bridge_webhook_updates ORDER BY id DESC LIMIT 10");
echo "webhook_updates_last10:" . PHP_EOL;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$st = $pdo->query("SELECT id,source_chat_id,source_message_id,media_group_id,last_seen_at,updated_at FROM channel_bridge_tg_posts ORDER BY id DESC LIMIT 10");
echo "tg_posts_last10:" . PHP_EOL;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$st = $pdo->query("SELECT NOW() db_now, (SELECT MAX(created_at) FROM channel_bridge_webhook_updates) webhook_last, (SELECT MAX(updated_at) FROM channel_bridge_jobs) jobs_last");
$one = $st->fetch(PDO::FETCH_ASSOC);
echo "summary=" . json_encode($one, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
