<?php
// Stav opcache pro monitoring-agent_phpfpm (phpfpm.py ho vola pres FastCGI na
// <ip kontejneru>:9000). Opcache zije ve sdilene pameti FPM mastera, takze
// opcache_get_status() musi bezet uvnitr FPM aplikace - zvenku se precist neda.
//
// Soucast image appsdevteam/php:*-fpm. Projekt na jinem image si ho prida sam
// (Dockerfile, stage s php-fpm):
//   COPY opcache-status.php /usr/local/lib/monitoring/opcache-status.php
// Cesta je ZAMERNE mimo document root: nginx ho neserviruje, dosazitelny je jen
// primo na FastCGI :9000 (jen z docker siti hosta). Aplikace bez souboru se
// nemeri (php_opcache_status_available 0), nic se nerozbije.
//
// Vraci jen agregaty (opcache_get_status(false) = bez seznamu skriptu).

header('Content-Type: application/json');

$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;

echo json_encode([
	'loaded' => extension_loaded('Zend OPcache'),
	// ini_get neomezuje opcache.restrict_api - odlisi "vypnuto" od "zakazane API"
	'enable' => (bool) ini_get('opcache.enable'),
	// null = opcache neni nactena, je vypnuta, nebo API blokuje opcache.restrict_api
	'status' => $status ?: null,
	'directives' => $config ? $config['directives'] : null,
]);
