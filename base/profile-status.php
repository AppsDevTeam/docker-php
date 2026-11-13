<?php
// Souhrn profilovani pozadavku (profile.php) pro monitoring-agent_phpfpm (phpfpm.py ho vola
// pres FastCGI na <ip kontejneru>:9000, stejne jako opcache-status.php). Secte citace
// vsech workeru z /tmp/php-profile.d/*.json a vrati JSON:
//
//   {"available": true, "enabled": true, "rate": 0.05, "expires": 1763200000,
//     "endpoints": {"/patient/{id}": {"requests": 12, "ms": 1234.5,
//       "groups": {"db": 300.1, "doctrine": 400.2, ...}}, ...}}
//
// `enabled` = soubor /tmp/php-profile existuje a nevyprsel (stejna logika jako v profile.php:
// nejpozdeji 24 h od zapsani, driv podle vyprseni v souboru), `expires` = kdy profilovani
// skonci samo. Citace jen rostou (Prometheus counter) - do restartu kontejneru nebo smazani
// /tmp/php-profile.d. Soucast image appsdevteam/php:*-fpm, cesta ZAMERNE mimo document root.

header('Content-Type: application/json');

$rate = @file_get_contents('/tmp/php-profile');
$expires = null;
if ($rate !== false) {
	[$rate, $expires] = array_pad(preg_split('~\s+~', trim($rate)), 2, null);
	$expires = min(
		(int) @filemtime('/tmp/php-profile') + 24 * 3600,
		$expires === null ? PHP_INT_MAX : (int) $expires,
	);
}
$enabled = $rate !== false && time() <= $expires;
$endpoints = [];
foreach (glob('/tmp/php-profile.d/*.json') ?: [] as $file) {
	$stats = json_decode((string) @file_get_contents($file), true);
	foreach ($stats['endpoints'] ?? [] as $endpoint => $entry) {
		$sum = &$endpoints[$endpoint];
		$sum['requests'] = ($sum['requests'] ?? 0) + ($entry['requests'] ?? 0);
		$sum['ms'] = ($sum['ms'] ?? 0) + ($entry['ms'] ?? 0);
		foreach ($entry['groups'] ?? [] as $group => $ms) {
			$sum['groups'][$group] = ($sum['groups'][$group] ?? 0) + $ms;
		}
		unset($sum);
	}
}

echo json_encode([
	'available' => function_exists('xhprof_enable'),
	'enabled' => $enabled,
	'rate' => $enabled ? (float) $rate : null,
	'expires' => $enabled ? $expires : null,
	'endpoints' => $endpoints,
]);
