<?php
/**
 * FILE: /adm/modules/clients/settings.php
 * ROLE: Module constants for clients.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * CLIENTS_TABLE
 * Name of clients table.
 */
const CLIENTS_TABLE = 'clients';

/**
 * CLIENTS_REQUESTS_TABLE
 * Name of requests table for assignment-based client visibility.
 */
const CLIENTS_REQUESTS_TABLE = 'requests';

/**
 * CLIENTS_ALLOWED_DO
 * Allow-list of module actions.
 */
const CLIENTS_ALLOWED_DO = [
  'api_search',
  'modal_settings',
  'modal_add',
  'modal_update',
  'add',
  'update',
  'toggle',
  'reset_password',
  'clear',
];
