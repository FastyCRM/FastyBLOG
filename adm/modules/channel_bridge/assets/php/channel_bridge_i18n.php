<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_i18n.php
 * ROLE: I18N-хелпер модуля channel_bridge.
 * CONTAINS:
 *  - channel_bridge_t($key, $replace) — получить строку перевода с подстановками.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('channel_bridge_t')) {
  /**
   * channel_bridge_t()
   * Возвращает локализованную строку модуля.
   * Если перевод не найден, вернёт сам ключ.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function channel_bridge_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map — карта подстановок вида ['{id}' => '12'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}

