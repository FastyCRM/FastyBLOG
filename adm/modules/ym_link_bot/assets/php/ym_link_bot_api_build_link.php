<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(dirname(__DIR__, 2));
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';
require_once $moduleRoot . '/assets/php/ym_link_bot_lib.php';

acl_guard(module_allowed_roles(ymlb_module_code()));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_405('Method Not Allowed');
}

$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '') json_err('csrf_required', 403);
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = ymlb_is_manage_role($roles);

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);
  ymlb_ensure_schema($pdo);

  $bindingId = (int)($_POST['binding_id'] ?? 0);
  $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
  $siteId = (int)($_POST['site_id'] ?? 0);

  if ($bindingId <= 0) {
    json_err('binding_required', 400);
  }
  if ($sourceUrl === '') {
    json_err('source_url_required', 400);
  }

  if (!$canManage && !ymlb_binding_is_owned_by_crm_user($pdo, $bindingId, $uid)) {
    json_err('forbidden', 403);
  }

  $binding = ymlb_binding_get($pdo, $bindingId);
  if (!$binding || (int)($binding['is_active'] ?? 0) !== 1) {
    json_err('binding_inactive', 400);
  }

  $settings = ymlb_settings_get($pdo);

  // url1: resolved long URL.
  $url1 = market_url_resolve($sourceUrl) ?: $sourceUrl;
  if (ymlb_is_market_short_url($sourceUrl) && ymlb_is_market_short_url($url1)) {
    json_err('Не удалось развернуть короткую ссылку. Введите длинную ссылку на товар.', 400);
  }

  // url2: pure product link without params.
  $url2 = ymlb_url_without_query($url1);
  if ($url2 === '') $url2 = $url1;

  // Fallback manual cleanup from url1 (legacy mechanism).
  $manualClean = market_url_cleanup($url1, ymlb_drop_query_keys()) ?: $url1;
  $forceHow = ymlb_should_keep_aprice($url1);
  $affiliateApiKey = trim((string)($settings['affiliate_api_key'] ?? ''));
  $linkStaticParams = (string)($settings['link_static_params'] ?? '');

  $product = ymlb_product_data(
    $url2,
    $affiliateApiKey,
    (int)($settings['geo_id'] ?? 213)
  );

  $productName = trim((string)($product['name'] ?? ''));
  if ($productName === '') $productName = 'Товар';
  $productPhoto = trim((string)($product['photo'] ?? ''));
  $productDescription = trim((string)($product['description'] ?? ''));

  $photoLocal = null;
  $photoPublic = null;
  $photoDay = null;
  if ($productPhoto !== '') {
    [$photoLocal, $photoPublic, $photoDay] = ymlb_save_photo($productPhoto);
    if ($photoDay !== null) {
      ymlb_photo_track($pdo, $bindingId, $productPhoto, $photoLocal, $photoPublic, $photoDay);
    }
  }

  $descriptionForReply = ymlb_clean_market_description(
    ymlb_strip_emoji(trim($productDescription))
  );
  $titleFromUrlForReply = ymlb_title_from_url($url2);
  if ($descriptionForReply !== '') {
    if (function_exists('mb_substr')) {
      $descriptionForReply = (string)mb_substr($descriptionForReply, 0, 700);
    } else {
      $descriptionForReply = substr($descriptionForReply, 0, 700);
    }
  }
  $replyText = ($descriptionForReply !== '' ? $descriptionForReply : $titleFromUrlForReply);
  $ordText = trim($replyText !== '' ? $replyText : $productName);
  if ($ordText === '') $ordText = 'Товар';
  if (function_exists('mb_substr')) {
    $ordText = (string)mb_substr($ordText, 0, 250);
  } else {
    $ordText = substr($ordText, 0, 250);
  }

  $sites = ymlb_sites_by_binding($pdo, $bindingId, true);
  if ($siteId > 0) {
    $sites = array_values(array_filter($sites, static function (array $row) use ($siteId): bool {
      return (int)($row['id'] ?? 0) === $siteId;
    }));
  }
  if (!$sites) {
    json_err('sites_empty', 400);
  }

  $oauthResolved = ymlb_binding_oauth_resolve($pdo, $binding);
  $oauth = trim((string)($oauthResolved['oauth_token'] ?? ''));
  $oauthLikelyExpired = ((int)($oauthResolved['oauth_likely_expired'] ?? 0) === 1);

  $vid = date('dmyHis');
  $ordEnabled = (defined('YM_LINK_BOT_ORD_ENABLED') ? (bool)YM_LINK_BOT_ORD_ENABLED : true);
  $links = [];

  ymlb_stage_log('api_build_link', 'info', [
    'stage' => 'start',
    'binding_id' => $bindingId,
    'source_url' => $sourceUrl,
    'url1_resolved' => $url1,
    'url2_clean' => $url2,
    'manual_clean_url' => $manualClean,
    'sites_count' => count($sites),
    'oauth_mode' => (string)($oauthResolved['oauth_mode'] ?? 'auto'),
    'oauth_ready' => ($oauth !== '' ? 1 : 0),
    'ord_enabled' => ($ordEnabled ? 1 : 0),
  ]);

  foreach ($sites as $site) {
    $clid = trim((string)($site['clid'] ?? ''));
    if ($clid === '') continue;

    $baseLink = $manualClean;
    $linkMode = 'fallback_manual';
    $partnerReason = '';
    if ($affiliateApiKey === '') {
      $partnerReason = 'affiliate_api_key_empty';
    } else {
      $partner = ymlb_affiliate_partner_link_create($url2, $affiliateApiKey, $clid);
      if (!is_array($partner)) {
        $partnerReason = 'partner_not_created';
      } else {
        $partnerUrl = trim((string)($partner['url'] ?? ''));
        if ($partnerUrl !== '' && ymlb_is_market_url($partnerUrl)) {
          $baseLink = $partnerUrl;
          $linkMode = 'partner_url3';
          $partnerReason = 'ok';
        } else {
          $partnerReason = 'partner_url_invalid';
        }
      }
    }

    ymlb_stage_log('api_build_link', ($linkMode === 'partner_url3' ? 'info' : 'warn'), [
      'stage' => 'partner_link',
      'binding_id' => $bindingId,
      'site_id' => (int)($site['id'] ?? 0),
      'clid' => $clid,
      'link_mode' => $linkMode,
      'reason' => $partnerReason,
      'url1_resolved' => $url1,
      'url2_clean' => $url2,
      'manual_clean_url' => $manualClean,
    ]);

    $erid = null;
    if ($ordEnabled) {
      $erid = ymlb_ord_create_erid(
        $clid,
        $vid,
        $oauth,
        $ordText,
        (string)($photoPublic ?: $productPhoto)
      );
      if (!$erid) {
        if ($oauthLikelyExpired) {
          json_err('Ошибка ERID: OAuth-ключ, вероятно, закончился. Обновите OAuth и повторите.', 400);
        }
        json_err('Ошибка ERID: не удалось получить ERID. Проверьте OAuth-ключ и повторите.', 400);
      }
    }

    if ($linkMode === 'partner_url3') {
      $final = $baseLink;
      if ($erid !== null && trim($erid) !== '') {
        $final = ymlb_url_with_params($final, ['erid' => trim($erid)]);
      }
    } else {
      $final = ymlb_build_final_link(
        $baseLink,
        $clid,
        $vid,
        $forceHow,
        $erid,
        $linkStaticParams
      );
    }

    $links[] = [
      'site_id' => (int)($site['id'] ?? 0),
      'site_name' => (string)($site['name'] ?? ''),
      'clid' => $clid,
      'vid' => $vid,
      'erid' => ($erid ?: ''),
      'link_mode' => $linkMode,
      'partner_reason' => $partnerReason,
      'url' => $final,
    ];
  }

  if (!$links) {
    json_err('links_not_built', 400);
  }

  ymlb_stage_log('api_build_link', 'info', [
    'stage' => 'done',
    'binding_id' => $bindingId,
    'links_count' => count($links),
    'reply_text_source' => ($descriptionForReply !== '' ? 'description' : ($titleFromUrlForReply !== '' ? 'url_title' : 'none')),
  ]);

  json_ok([
    'binding_id' => $bindingId,
    'source_url' => $sourceUrl,
    'resolved_url' => $url1,
    'clean_url' => $url2,
    'manual_clean_url' => $manualClean,
    'ord_enabled' => ($ordEnabled ? 1 : 0),
    'reply_text' => $replyText,
    'links' => $links,
  ]);
} catch (Throwable $e) {
  ymlb_stage_log('api_build_link', 'error', [
    'stage' => 'exception',
    'error' => $e->getMessage(),
  ]);
  json_err($e->getMessage(), 400);
}
