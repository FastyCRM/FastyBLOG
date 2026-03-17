<?php
/**
 * FILE: /modules/modules/assets/php/main.php
 * ROLE: Приемщик do
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$do = $_GET['do'] ?? 'view';

$allowed = ['view', 'toggle', 'update_icon'];
if (!in_array($do, $allowed, true)) {
    http_response_code(400);
    exit('Invalid action');
}

require __DIR__ . "/modules_{$do}.php";
