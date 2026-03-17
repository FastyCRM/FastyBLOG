<?php
/**
 * FILE: /adm/modules/modules/assets/php/modules_i18n.php
 * ROLE: Вспомогательная функция переводов модуля modules.
 * CONTAINS:
 *  - modules_t($key, $replace) — получить перевод и выполнить подстановки.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('modules_t')) {
  /**
   * modules_t()
   * Возвращает локализованную строку модуля modules.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function modules_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map — карта подстановок вида ['{name}' => 'CRM'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}
