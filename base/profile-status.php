<?php
// Souhrn profilovani pozadavku (profile.php) pro monitoring-agent_phpfpm (phpfpm.py ho vola
// pres FastCGI na <ip kontejneru>:9000, stejne jako opcache-status.php). Secte citace
// vsech workeru z /tmp/php-profile.d/*.json a vrati JSON:
//
//   {"enabled": true, "rate": 0.05, "endpoints": {"/patient/{id}": {"requests": 12,
//     "ms": 1234.5, "groups": {"db": 300.1, "doctrine": 400.2, ...}}, ...}}
//
// Citace jen rostou (Prometheus counter) - do restartu kontejneru nebo smazani
// /tmp/php-profile.d. Soucast image appsdevteam/php:*-fpm, cesta ZAMERNE mimo document root.

header('Content-Type: application/json');

$rate = @file_get_contents('/tmp/php-profile');
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
	'enabled' => $rate !== false,
	'rate' => $rate !== false ? (float) trim($rate) : null,
	'endpoints' => $endpoints,
]);
