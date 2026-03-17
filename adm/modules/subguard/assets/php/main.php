<?php
/**
 * FILE: /modules/subguard/assets/php/main.php
 * ROLE: Module receiver (do router) - stub
 * CONNECTIONS:
 *  - none
 *
 * FLOW:
 *  /adm/index.php -> /adm/assets/php/main.php -> this file -> (no actions)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit;
}

// Only "view" exists, so nothing to route.
// If someone passes do=... -> just show view (or 400)
return;
