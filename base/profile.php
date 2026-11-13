<?php
// Profilovani pozadavku php-fpm (xhprof) pro monitoring - auto_prepend_file z docker-fpm.ini
// image -fpm, bezi na zacatku KAZDEHO weboveho pozadavku (v CLI hned skonci). Vypnute nic
// nedela: jedna kontrola existence souboru (~us), zadne xhprof.
//
// Zapnuti za behu, bez restartu (i na produkci "na chvilku"):
//   docker exec <projekt>_php sh -c 'echo 0.05 > /tmp/php-profile'   # kazdy 20. pozadavek
//   docker exec <projekt>_php rm /tmp/php-profile                     # vypnout
// Soubor obsahuje podil profilovanych pozadavku (0 < podil <= 1) a volitelne cas vyprseni
// (unix timestamp, oddeleny mezerou; zapisuje ho `run.sh --php-profile` ze
// server-management-scripts). Vzorkovani: xhprof zpomali profilovany pozadavek o desitky
// procent, vzorek 5 % zateze zvedne o ~1-2 %. /tmp je u projektu tmpfs, restart kontejneru
// profilovani vypne sam.
//
// Profilovani skonci SAMO nejpozdeji 24 h od zapsani souboru, i kdyz
// ho nikdo nesmaze - zapomenute profilovani by jinak zvedalo zatez produkce navzdy. Kratsi
// vyprseni v souboru ma prednost. Po vyprseni soubor zustava (worker ho nesmi mazat - patri
// tomu, kdo ho zapsal), jen se ignoruje; nove zapsani (novy mtime) profilovani zase zapne.
//
// Vystup kazdeho profilovaneho pozadavku: souhrn casu po skupinach kodu (DB, Doctrine, Latte,
// Nette, autoload, ...) pridany k citacum endpointu v /tmp/php-profile.d/<pid>.json (per worker,
// bez zamku - kazdy worker pise jen svuj soubor). profile-status.php (volany monitoringem pres
// FastCGI) je secte a vrati jako JSON - Prometheus pak ukaze, ktere endpointy berou nejvic CPU
// a cim (php_profile_* v monitoring-agent_phpfpm, server-management-scripts).
//
// Endpoint = cesta s cisly nahrazenymi {id} + Nette signal (?do=), napr. "/patient/{id}",
// "/?do=shifts". Zadne parametry, zadna data uzivatele. Pocet ruznych endpointu je omezeny
// (PROFILE_MAX_ENDPOINTS), dalsi jdou do "other" - Prometheus neunese neomezene labely.
//
// Podrobny profil jednoho pozadavku (nejdrazsi funkce) pro rucni rozbor: hlavicka
// X-Profile: <nazev> zapise /tmp/php-profile-<nazev>.txt v kontejneru. Jen se zapnutym
// profilovanim (soubor /tmp/php-profile existuje) - kdokoli zvenku by jinak mohl serveru
// pridelavat praci.

// Skripty monitoringu v tomtez FPM (opcache-status.php, profile-status.php, healthcheck)
// se neprofiluji - nejsou to pozadavky aplikace
if (PHP_SAPI === 'cli' || !function_exists('xhprof_enable')
	|| str_starts_with($_SERVER['SCRIPT_FILENAME'] ?? '', '/usr/local/lib/monitoring/')) {
	return;
}

$__profileRate = @file_get_contents('/tmp/php-profile');
if ($__profileRate === false) {
	return;
}
// "<podil> [<vyprseni>]" - vyprselo = vypnuto (stejna logika v profile-status.php)
[$__profileRate, $__profileExpires] = array_pad(preg_split('~\s+~', trim($__profileRate)), 2, null);
$__profileExpires = min(
	(int) @filemtime('/tmp/php-profile') + 24 * 3600,
	$__profileExpires === null ? PHP_INT_MAX : (int) $__profileExpires,
);
if (time() > $__profileExpires) {
	return;
}
$__profileRate = (float) $__profileRate;
$__profileDetail = $_SERVER['HTTP_X_PROFILE'] ?? null;
if (!is_string($__profileDetail) || !preg_match('~^[a-z0-9-]{1,40}$~', $__profileDetail)) {
	$__profileDetail = null;
}
if ($__profileDetail === null && ($__profileRate <= 0 || mt_rand() / mt_getrandmax() >= $__profileRate)) {
	return;
}

xhprof_enable();
$__profileStart = hrtime(true);

register_shutdown_function(static function () use ($__profileDetail, $__profileStart): void {
	$data = xhprof_disable();
	$totalMs = (hrtime(true) - $__profileStart) / 1e6;

	// Vlastni (exclusive) cas funkce = cas jejich volani minus cas funkci, ktere volala
	$inclusive = [];
	$children = [];
	$calls = [];
	foreach ($data as $edge => $metrics) {
		[$parent, $child] = str_contains($edge, '==>') ? explode('==>', $edge, 2) : [null, $edge];
		$inclusive[$child] = ($inclusive[$child] ?? 0) + $metrics['wt'];
		$calls[$child] = ($calls[$child] ?? 0) + $metrics['ct'];
		if ($parent !== null) {
			$children[$parent] = ($children[$parent] ?? 0) + $metrics['wt'];
		}
	}
	$exclusive = [];
	foreach ($inclusive as $function => $wt) {
		$exclusive[$function] = max(0, $wt - ($children[$function] ?? 0));
	}

	// Skupina kodu podle namespace / tridy. Poradi zalezi: konkretnejsi driv.
	$group = static function (string $function): string {
		$function = preg_replace('~@\d+$~', '', $function);
		foreach ([
			'~^(PDO|PDOStatement|mysqli|mysqli_stmt|mysqli_result)(::|_)~' => 'db',
			'~^Doctrine\\\\~' => 'doctrine',
			'~^(Latte|Nette\\\\Bridges\\\\ApplicationLatte)\\\\~' => 'latte',
			'~^Nette\\\\DI\\\\~' => 'nette_di',
			'~^Nette\\\\~' => 'nette',
			'~^Composer\\\\Autoload|^\{closure:Composer|^include|^require|^load::~' => 'autoload',
			'~^(curl_|stream_socket|fsockopen|file_get_contents|fopen|fwrite|fread)~' => 'io',
			'~^App\\\\~' => 'app',
			'~^Tracy\\\\~' => 'tracy',
		] as $pattern => $name) {
			if (preg_match($pattern, $function)) {
				return $name;
			}
		}
		if (str_starts_with($function, 'ADT\\')) {
			return 'adt';
		}
		// vestavene funkce PHP (bez namespace) i ostatni knihovny
		return str_contains($function, '\\') ? 'vendor' : 'php';
	};
	$groups = [];
	foreach ($exclusive as $function => $wt) {
		$groups[$group($function)] = ($groups[$group($function)] ?? 0) + $wt / 1000;
	}

	// Endpoint bez parametru a bez dat uzivatele
	$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
	$path = preg_replace('~/\d+(?=/|$)~', '/{id}', $path) ?? $path;
	$path = preg_replace('~/[0-9a-f]{16,}(?=/|$)~i', '/{hash}', $path) ?? $path;
	$signal = $_GET['do'] ?? null;
	$endpoint = $path . (is_string($signal) && preg_match('~^[\w-]{1,60}$~', $signal) ? '?do=' . $signal : '');

	// Citace endpointu per worker (zadne zamky), profile-status.php secte vsechny
	$dir = '/tmp/php-profile.d';
	@mkdir($dir, 0777, true);
	$file = $dir . '/' . getmypid() . '.json';
	$stats = json_decode((string) @file_get_contents($file), true);
	if (!is_array($stats)) {
		$stats = ['endpoints' => []];
	}
	$max = (int) (getenv('PROFILE_MAX_ENDPOINTS') ?: 100);
	if (!isset($stats['endpoints'][$endpoint]) && count($stats['endpoints']) >= $max) {
		$endpoint = 'other';
	}
	$entry = &$stats['endpoints'][$endpoint];
	$entry['requests'] = ($entry['requests'] ?? 0) + 1;
	$entry['ms'] = ($entry['ms'] ?? 0) + $totalMs;
	foreach ($groups as $name => $ms) {
		$entry['groups'][$name] = ($entry['groups'][$name] ?? 0) + $ms;
	}
	unset($entry);
	// zapis pres docasny soubor, aby profile-status.php nikdy necetl pulku JSONu
	@file_put_contents($file . '.tmp', json_encode($stats));
	@rename($file . '.tmp', $file);

	if ($__profileDetail === null) {
		return;
	}
	arsort($groups);
	arsort($exclusive);
	$lines = [sprintf('%s  celkem %.1f ms', $endpoint, $totalMs), '', '== Vlastni cas po skupinach =='];
	foreach ($groups as $name => $ms) {
		$lines[] = sprintf('%8.1f ms  %s', $ms, $name);
	}
	$lines[] = '';
	$lines[] = '== Nejdrazsi funkce (vlastni cas) ==';
	foreach (array_slice($exclusive, 0, 40, true) as $function => $wt) {
		$lines[] = sprintf('%8.1f ms  %6dx  %s', $wt / 1000, $calls[$function] ?? 0, $function);
	}
	@file_put_contents('/tmp/php-profile-' . $__profileDetail . '.txt', implode("\n", $lines) . "\n");
});
