<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_i18n.php
 * ROLE: I18N-хелпер модуля promobot.
 * CONTAINS:
 *  - promobot_t($key, $replace) — получить строку перевода с подстановками.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('promobot_t')) {
  /**
   * promobot_t()
   * Возвращает локализованную строку модуля.
   * Если перевод не найден, вернёт сам ключ.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function promobot_t(string $key, array $replace = []): string
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