<?php
/**
 * FILE: /modules/subguard/index.php
 * ROLE: VIEW (intentionally empty / minimal)
 * CONNECTIONS:
 *  - none
 *
 * NOTES:
 *  - This module has no UI by design.
 *  - It exists only so you can enable/disable it per install via DB.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit;
}

echo '<div class="card"><div class="card__body">subguard: no UI</div></div>';
