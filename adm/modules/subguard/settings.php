<?php
/**
 * FILE: /modules/subguard/settings.php
 * ROLE: Module passport (no UI, only enable/disable flag via DB)
 * CONNECTIONS:
 *  - none (read by CORE/modules loader if you use it)
 *
 * IMPORTANT:
 *  - This module exists only to be toggled in DB (modules.enabled).
 *  - UI is intentionally absent.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit;
}

return [
    'code' => 'subguard',
    'name' => 'Subscription Guard (stub)',
    'allowed_do' => ['view'], // no actions now
    'has_settings' => 0,
];
