<?php
/**
 * FILE: /core/market_url.php
 * ROLE: URL helpers for short-link resolving and query cleanup.
 */

declare(strict_types=1);

if (!function_exists('market_url_extract_first')) {
  /**
   * market_url_extract_first()
   * Extracts first http/https URL from text.
   */
  function market_url_extract_first(string $text): ?string
  {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('~https?://[^\s<>"\'\)]+~iu', $text, $m)) {
      return null;
    }

    $url = trim((string)($m[0] ?? ''));
    return $url !== '' ? $url : null;
  }

  /**
   * market_url_resolve()
   * Resolves redirects and returns final URL.
   */
  function market_url_resolve(string $url, int $timeout = 10): ?string
  {
    $url = trim($url);
    if ($url === '') return null;

    if (!preg_match('~^https?://~i', $url)) {
      $url = 'https://' . ltrim($url, '/');
    }

    if (!function_exists('curl_init')) {
      return $url;
    }

    $resolved = market_url_resolve_curl($url, $timeout, true);
    if ($resolved !== null) return $resolved;

    $resolved = market_url_resolve_curl($url, $timeout, false);
    return $resolved ?? $url;
  }

  /**
   * market_url_cleanup()
   * Removes selected query keys from URL.
   *
   * @param array<int,string> $dropKeys
   */
  function market_url_cleanup(string $url, array $dropKeys = []): ?string
  {
    $url = trim($url);
    if ($url === '') return null;

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return null;
    }

    $queryMap = [];
    if (!empty($parts['query'])) {
      parse_str((string)$parts['query'], $queryMap);
    }

    $drop = [];
    foreach ($dropKeys as $key) {
      $key = strtolower(trim((string)$key));
      if ($key !== '') $drop[$key] = true;
    }

    if ($drop) {
      foreach (array_keys($queryMap) as $k) {
        if (isset($drop[strtolower((string)$k)])) {
          unset($queryMap[$k]);
        }
      }
    }

    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = (string)($parts['path'] ?? '');
    $fragment = isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

    $query = '';
    if ($queryMap) {
      $query = '?' . http_build_query($queryMap, '', '&', PHP_QUERY_RFC3986);
    }

    return $scheme . '://' . $host . $port . $path . $query . $fragment;
  }

  /**
   * market_url_extract_slug()
   * Extracts market slug from URL path.
   */
  function market_url_extract_slug(string $url): ?string
  {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '') return null;

    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    if (!$parts) return null;

    $last = end($parts);
    if ($last !== false && ctype_digit((string)$last) && count($parts) >= 2) {
      return (string)$parts[count($parts) - 2];
    }

    foreach ($parts as $part) {
      if (strpos((string)$part, 'product--') === 0) {
        return substr((string)$part, strlen('product--'));
      }
    }

    return $last !== false ? (string)$last : null;
  }

  /**
   * market_url_resolve_curl()
   * Internal cURL resolver.
   */
  function market_url_resolve_curl(string $url, int $timeout, bool $headOnly): ?string
  {
    $ch = curl_init($url);
    if (!$ch) return null;

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => 'Mozilla/5.0',
      CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Connection: keep-alive',
      ],
      CURLOPT_NOBODY => $headOnly,
    ]);

    curl_exec($ch);
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($effective === '' || $code < 200 || $code >= 400) {
      return null;
    }

    return $effective;
  }
}

