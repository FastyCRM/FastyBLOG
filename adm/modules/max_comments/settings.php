<?php
/**
 * FILE: /adm/modules/max_comments/settings.php
 * ROLE: Паспорт модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

const MAX_COMMENTS_MODULE_CODE = 'max_comments';

const MAX_COMMENTS_TABLE_SETTINGS = 'max_comments_settings';
const MAX_COMMENTS_TABLE_CHANNELS = 'max_comments_channels';
const MAX_COMMENTS_TABLE_PROCESSED = 'max_comments_processed';

const MAX_COMMENTS_ALLOWED_DO = [
  'save',
  'probe',
  'poll_now',
  'test_button',
  'test_read',
  'test_updates',
];
