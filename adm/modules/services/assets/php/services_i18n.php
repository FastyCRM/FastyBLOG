<?php
/**
 * FILE: /adm/modules/services/assets/php/services_i18n.php
 * ROLE: Вспомогательные функции переводов модуля services.
 * CONTAINS:
 *  - services_t($key, $replace) - получить перевод и выполнить подстановки.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('services_t')) {
  /**
   * services_t()
   * Возвращает локализованную строку модуля services.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function services_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map - карта подстановок вида ['{id}' => '15'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}