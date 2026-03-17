<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_api_services.php
 * ROLE: requests API: services + specialists
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

/**
 * $pdo - db handle
 */
$pdo = db();

/**
 * $settings - module settings
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists - specialists mode
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots - time slots enabled
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * $services - services list
 */
$services = [];
/**
 * $specMap - service -> specialists map
 */
$specMap = [];

if ($useSpecialists) {
  
  $st = $pdo->query("SELECT id, name, duration_min FROM " . REQUESTS_SERVICES_TABLE . " WHERE status='active' ORDER BY name ASC");
  $services = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  if ($services) {
    foreach ($services as &$srv) {
      $dur = (int)($srv['duration_min'] ?? 0);
      if ($dur <= 0) $dur = 30;
      $srv['duration_min'] = $dur;
    }
    unset($srv);
  }

  
  $st2 = $pdo->prepare("\n    SELECT us.service_id, u.id, u.name\n    FROM " . REQUESTS_USER_SERVICES_TABLE . " us\n    JOIN users u ON u.id = us.user_id\n    JOIN user_roles ur ON ur.user_id = u.id\n    JOIN roles r ON r.id = ur.role_id\n    WHERE r.code = 'specialist' AND u.status = 'active'\n    ORDER BY u.name ASC\n  ");
  $st2->execute();
  
  $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    
    $sid = (int)($r['service_id'] ?? 0);
    if ($sid <= 0) continue;
    if (!isset($specMap[$sid])) $specMap[$sid] = [];
    $specMap[$sid][] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => (string)($r['name'] ?? ''),
    ];
  }
}

json_ok([
  'use_specialists' => $useSpecialists ? 1 : 0,
  'use_time_slots' => $useTimeSlots ? 1 : 0,
  'services' => $services,
  'spec_map' => $specMap,
]);
