# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm, -cli, -supervisor a -composer

Spolecny zaklad PHP pro webove projekty, platformy `linux/amd64` a `linux/arm64`. Varianty:

- `-fpm` - web (php-fpm). Tagy `8.4-fpm` / `8.5-fpm`, plovouci podle revize `8.4.28-8-fpm` /
  `8.5.13-8-fpm`.
- `-cli` - konzolove procesy bez webu, bez php-fpm. Tagy `8.4-cli` / `8.5-cli`, plovouci
  podle revize `8.4.28-8-cli` / `8.5.13-8-cli`. Obsahem stejne jako `-fpm`.
- `-supervisor` - `-cli` + supervisor pro consumery (background queue apod.). Od revize 13
  s `supervisord.conf` pro neroot ucet (pidfile a socket v `/tmp`); programy projekt mountuje
  do `/etc/supervisor/conf.d` (`*.conf`), vychozi `CMD` je `supervisord -n`. Tagy
  `8.4-supervisor` / `8.5-supervisor`, plovouci podle revize `8.4.28-8-supervisor` /
  `8.5.13-8-supervisor`.
- `-composer` - `-cli` + composer, git, unzip, jednorazovy kontejner pro `composer install`
  (viz Composer nize). Tagy `8.4-composer` / `8.5-composer`, plovouci podle revize `8.4.28-8-composer` /
  `8.5.13-8-composer`.

Kazdy build ma navic neprepisovatelny tag s datem buildu (UTC):
`<verze PHP>-<revize>-<YYYYMMDD>-<varianta>`, napr. `8.4.28-8-20261109-fpm`. Ten je
doporuceny - jen u nej je z nazvu jasne, co v image je, a dva buildy projektu (dva hosty,
CI a server) dostanou stejny obsah. `<verze PHP>-<revize>-<varianta>` se pri kazdem buildu
prepise (obsahuje vzdy posledni build), stejne jako `<minor>-<varianta>`. V jeden den vznika
nejvys jeden build kazde varianty - dalsi spusteni workflow ten den variantu, ktera uz datovy
tag ma, preskoci (datovy tag se neprepisuje).

Bezpecnostni opravy Debianu prinasi tydenni rebuild (pondeli). Projekt na datovem tagu je
dostane vedomym bumpem data - signalem je audit image (trivy apod.), ktery zacne hlasit
opravitelnou zranitelnost. Plati i pro samotne PHP: image nese SBOM
`/usr/local/share/sbom/php.cdx.json` (`pkg:bitnami/php@<verze>`), takze `trivy image` hlasi CVE
PHP opravene v novejsim patchi - oprava je bump verze v matrixu workflow.

Revize (`REVISION` ve workflow) se zvysuje pri zmene image, ktera muze rozbit projekt
(odebrany nastroj, jine chovani) - tydenni rebuild s bezpecnostnimi opravami ji nemeni.
Starsi revize se uz nesestavuji, zustanou zmrazene, dokud projekt neprejde na novou.

Obsahuje:

- bez composeru, gitu a unzipu (od revize 5, ma je jen `-composer`) - viz Composer nize
- `cachetool` (`eoh` byl do revize 10 - protejsek `eohd` na hostu poslouchal na vsech rozhranich
  a prikazy poustel pres eval, adt/make 1.1 ho uz nespousti)
- `dma` jako `sendmail` - `mail()` doruci primo na MX prijemce, bez API a klice. Jen na
  chybove e-maily (adt/error-logger), bezna posta projektu patri do `adt/mailer`.
  Projekt `sendmail_path` nenastavuje (vychozi `/usr/sbin/sendmail -t -i` je dma)
  Spool je v `/tmp`, kontejner proto potrebuje zapisovatelny `/tmp` (u `read_only` tmpfs).
- rozsireni `intl`, `opcache`, `pdo_mysql`, `sockets`, `sodium`, `xhprof` (od revize 9, jen pro
  profilovani nize), `zip`
- `/usr/local/etc/php/php.ini` s vychozimi hodnotami: `opcache.interned_strings_buffer=32`
  a `opcache.memory_consumption=256` (PHP ma 8 a 128 MB, velkym aplikacim nestaci).
  Pamet opcache se zabira az pri zaplnovani, malym projektum vyssi limit nic nestoji.
  Dale `session.upload_progress.enabled=0` (od revize 4) - session lifecycle RCE,
  CVE-2026-109619. Projekt, ktery prubeh nahravani pouziva, si ho zapne ve svem `conf.d`.
  A `memory_hard_limit_percent=800` - prekroceni memory_limit uvnitr jedne interni funkce
  (PDO `fetchAll()` velkeho vysledku) PHP od 8.4.27/8.5.12 nad hard limitem (vychozi 2x)
  ukonci bez zalogovani chyby (`_exit(137)`, nginx 502), s 8x skonci beznou chybou
  "Allowed memory size", kterou zaloguje Tracy.
- `/usr/local/lib/monitoring/opcache-status.php` - stav opcache pro monitoring
  (`monitoring-agent_phpfpm` v server-management-scripts)
- profilovani pozadavku zapinatelne za behu (od revize 9) - viz Profilovani pozadavku nize
- `HEALTHCHECK` php-fpm s watchdogem, ktery zaseknuty FPM restartuje (od revize 8, jen
  `-fpm`) - viz Healthcheck a watchdog php-fpm nize
- bez gcc/make a hlavicek (`$PHPIZE_DEPS` odstraneny), s aktualnimi bezpecnostnimi
  opravami Debianu (rebuild kazdy tyden)

Pouziti v projektu - rozsireni z `ext-*` projektu (composer.json) a vsech balicku
(composer.lock) instaluje Dockerfile projektu ve dvou stagich (od revize 12, viz Rozsireni
projektu nize), `composer.json` a `composer.lock` musi byt v build contextu:

```dockerfile
ARG PHP_IMAGE
# seznam chybejicich rozsireni - samostatna stage, aby instalace nize zustala v cache,
# dokud se nezmeni seznam (ne pri kazde zmene composer.lock)
FROM appsdevteam/php:${PHP_IMAGE}-fpm AS extensions
ARG DEV=0
COPY composer.json composer.lock ./
RUN install-composer-extensions --list $([ "$DEV" = 1 ] && echo --dev) composer.json composer.lock > /extensions

FROM appsdevteam/php:${PHP_IMAGE}-fpm
COPY --from=extensions /extensions /tmp/extensions
RUN install-composer-extensions --install /tmp/extensions
```

Rozsireni mimo `ext-*` (napr. pro nastroj, ktery composer nezna) projekt doinstaluje sam:

```dockerfile
FROM appsdevteam/php:8.5.13-8-20261109-fpm
RUN install-php-extensions imagick
```

Web i consumery ze stejneho Dockerfile projektu - varianta jako build arg, compose ji
u consumeru nastavi na `supervisor`. Rozsireni projektu tak dostanou oba image stejna:

```dockerfile
ARG VARIANT=fpm
FROM appsdevteam/php:8.5.13-8-20261109-${VARIANT}
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Vychozi hodnoty image jsou proto mimo ne
(viz *Vychozi ini a pool [app]*) - projekt je muze ve svem `conf.d` / `php-fpm.d` prepsat.

### Vychozi ini a pool [app] (od revize 14)

Spolecne nastaveni vsech projektu je v image, projekt drzi jen to, cim se lisi:

- `/usr/local/etc/php/php.ini` - produkcni hodnoty: `display_errors=0`, `log_errors=1`,
  `expose_php=0`, `post_max_size` a `upload_max_filesize` 100M, `ignore_user_abort`, zlib komprese,
  `opcache.revalidate_freq=0` (drive `conf.d/0-default.ini` sablony sandbox)
- `/usr/local/etc/php/runtime.d/disable-functions.ini` - `disable_functions` (exec, proc_open ...)
  pro web i CLI vsech stagi. `PHP_INI_SCAN_DIR` je `base.d:runtime.d:conf.d`, takze lokalne
  a v CI ho projekt v `conf.d` vypne (`disable_functions=`, phpstan a codeception potrebuji
  proc_open). `install-php-extensions` ho pri buildu vynechava, `-composer` ho nema.
- `/usr/local/etc/php-fpm.base.conf` (jen `-fpm`, nacita se pred `php-fpm.d/*.conf`): `[global]`
  (log na stderr) a pool `[app]` na portu 9000 - `pm.max_requests`, `request_terminate_timeout`,
  `pm.status_path = /status`, `clear_env = no`, vystup workeru do `docker logs`. Velikost poolu
  a revalidace opcache z prostredi kontejneru (`docker/.env` projektu), vychozi hodnoty v image:

  | Promenna | Vychozi |
  |---|---|
  | `PHP_FPM_PM` | `dynamic` |
  | `PHP_FPM_MAX_CHILDREN` | `5` |
  | `PHP_FPM_START_SERVERS`, `PHP_FPM_MIN_SPARE_SERVERS`, `PHP_FPM_MAX_SPARE_SERVERS` | `2`, `1`, `3` |
  | `PHP_OPCACHE_VALIDATE_TIMESTAMPS` | `0` (lokalne a v CI `1`) |

Prechod na revizi 14: pool projektu jineho jmena nez `[app]` na portu 9000 s poolem image koliduje
a FPM nenastartuje - projekt ho smaze (velikost poolu pres promenne vyse), nebo prejmenuje na `[app]`
(slouci se, hodnoty projektu prepisou image). Projekt, ktery na serveru nekterou zakazanou funkci
potrebuje, ji povoli ve svem `conf.d`. Pooly oficialniho image (`[www]`) v revizi 14 nejsou.

### Rozsireni projektu (od revize 7, dve stage od revize 12)

`install-composer-extensions` nainstaluje vsechna `ext-*`, ktera vyzaduje projekt
(composer.json) nebo kterykoli nainstalovany balicek (composer.lock). Rozsireni, ktera PHP
uz ma, se preskoci. Projekt je tak nevyjmenovava na dvou mistech a na instalaci nemuze
zapomenout.

- Revize 7-11 to delaly `ONBUILD` hned za `FROM` projektu. Jenze `COPY composer.json
  composer.lock` zahodil vrstvu s kompilaci rozsireni pri kazde zmene locku - bump libovolne
  knihovny znamenal znovu kompilovat napr. imagick (3 minuty v kazdem jobu CI i na serveru).
- Od revize 12 `ONBUILD` neni a Dockerfile projektu ma dve stage (priklad vyse):
  `--list` v prvni vypise chybejici rozsireni (serazena, po radcich) do souboru, druha ho
  prevezme `COPY --from` a `--install` nainstaluje. `COPY --from` je v cache podle obsahu
  souboru, takze vrstva s instalaci se prestavi jen pri zmene seznamu rozsireni.
- Oba soubory musi byt v build contextu, jinak build spadne (`"/composer.lock": not found`) -
  zamerne, kontrola rozsireni se nesmi tise vynechat. Context projektu byva samostatny
  adresar jen pro Dockerfile, soubory do nej projekt kopiruje pred buildem (adt/make
  `_docker-common`, deploy recept adt/docker-deployer, adt/ci `.setup`).
- Bez `--dev` se cte jen `require` / `packages` (produkcni image). `--dev` prida i
  `require-dev` / `packages-dev`, obdobne jako `composer install` vs. `composer install
  --no-dev` - build arg `DEV=1` v prikladu (`--build-arg DEV=1`, `build.args` v compose).
- Projekt na revizi 11 a starsi s holym `FROM` (ONBUILD) funguje dal, dokud nepovysi
  `PHP_IMAGE`; s revizi 12 by bez dvou stagi rozsireni chybela a web spadl az za behu.
- `-composer` rozsireni projektu nema (jeden image pro vsechny projekty, nebuildi se).

### Ini rozsireni (`base.d`, od revize 6)

Ini, ktera zapinaji rozsireni (`docker-php-ext-*.ini`, i tech, ktera projekt doinstaluje pres
`install-php-extensions` / `install-composer-extensions`), a `docker-fpm.ini` jsou
v `/usr/local/etc/php/base.d`, ne v `conf.d`. Image nastavuje
`PHP_INI_SCAN_DIR=/usr/local/etc/php/base.d:/usr/local/etc/php/conf.d` - `base.d` se cte
prvni, ini projektu v `conf.d` ma posledni slovo. Projekt proto rozsireni ve svem ini
**nezapina** (`extension=intl.so`, `zend_extension=opcache.so` ...) - dvoji nacteni PHP
hlasi warningem `Module "intl" is already loaded`, na CLI i do vystupu prikazu.

Projekt, ktery `PHP_INI_SCAN_DIR` nastavuje sam (napr. ini jen pro aktualni stage), musi
`base.d` ponechat na zacatku:

```yaml
    environment:
      - PHP_INI_SCAN_DIR=/usr/local/etc/php/base.d:/usr/local/etc/php/conf.d:/usr/local/etc/php/stage/${STAGE}
```

`docker-php-ext-enable` volany primo (mimo wrapper) zapisuje do `conf.d` - pod mountem by ini
zmizelo, pouzivej `install-php-extensions`.

### Pool php-fpm podle stage (od revize 6)

Krome `php-fpm.d/*.conf` nacita `-fpm` i `php-fpm.d/default.${STAGE}.conf.template` - sablonu
poolu, kterou projekty generuji z `config/docker`. `STAGE` bere php-fpm z prostredi kontejneru
(`env_file`). Projekt tak sluzbe php nechava vychozi `CMD` (`php-fpm`) a pri startu nic
nekopiruje:

```yaml
  php:
    # bez `command: cp -f .../default.${STAGE}.conf.template .../default.${STAGE}.conf && exec php-fpm`
```

Projekt, ktery sablonu dal kopiruje, funguje taky - stejny pool nacteny dvakrat php-fpm slouci.

### Healthcheck a watchdog php-fpm (od revize 8)

`-fpm` (stage `app`) ma `HEALTHCHECK` (`php-fpm-healthcheck`, kazdych 10 s): FastCGI
pozadavek na prazdny skript `/usr/local/lib/monitoring/healthcheck.php` na kazdy TCP port,
na kterem FPM posloucha - vic poolu (`listen = 9000`, `listen = 9001`) se zkontroluje kazdy.
Odpoved znamena, ze nejaky worker pozadavek prijal a dokoncil. Na `/status` se kontrola
nepta - `pm.status_path` je v poolu projektu, ktery image nezapne (`php-fpm.d` prekryva
mount), skript v image je ve vsech projektech. Jakakoli odpoved je zivy pool.

Zaseknuty php-fpm (vsechny workery visi, pozadavky jen cekaji ve fronte) kontrola po
**2 minutach selhavani v rade** restartuje (pocita se cas, ne pocet kontrol - docker
spousti dalsi kontrolu az 10 s po dobehnuti predchozi a neuspesna trva az 5 s): masteru posle SIGTERM, kontejner skonci
a `restart: always` ho spusti znovu. Docker sam unhealthy kontejner nerestartuje (umi to
jen Swarm). Zasah je v `docker logs` (`php-fpm-healthcheck: ... restartuji php-fpm`),
restart v `RestartCount` kontejneru. Rozpracovane pozadavky restart ukonci - dlouhe
legitimni pozadavky (exporty) na vsech workerech soucasne by ho spustily taky.

Nastaveni (env kontejneru, `env_file`):

| Promenna | Vychozi | |
|---|---|---|
| `PHP_FPM_WATCHDOG` | `1` | `0` = jen hlasit unhealthy, nerestartovat |
| `PHP_FPM_WATCHDOG_AFTER` | `120` | po kolika sekundach selhavani v rade restartovat |
| `PHP_FPM_HEALTHCHECK_TIMEOUT` | `5` | limit odpovedi v sekundach |
| `PHP_FPM_HEALTHCHECK_ADDRESS` | porty FPM mastera | `host:port` carkou oddelene, misto automatickeho zjisteni |

Kontejner bez FPM mastera (`-cli`, `-fpm` image spoustejici jiny proces) se nekontroluje.
Jednotlive viseci pozadavky ma ukoncovat `request_terminate_timeout` v poolu projektu -
watchdog je pojistka pro pripad, kdy visi vsechny.

### Profilovani pozadavku (od revize 9)

Kazdy webovy pozadavek zacina `/usr/local/lib/monitoring/profile.php` (`auto_prepend_file`
v `base.d/docker-fpm.ini`, jen varianta `-fpm`; v CLI skript hned skonci). Dokud v kontejneru neexistuje `/tmp/php-profile`, skript
hned skonci (jedna kontrola existence souboru, bez merineho rozdilu proti image bez prependu).
Profilovani se zapina **za behu, bez restartu**, i na produkci na chvilku:

```bash
docker exec <projekt>_php sh -c 'echo 0.05 > /tmp/php-profile'   # kazdy 20. pozadavek
docker exec <projekt>_php rm /tmp/php-profile                     # vypnout
```

Hodnota je podil profilovanych pozadavku (0-1). xhprof zpomali profilovany pozadavek
o desitky procent, vzorek 5 % tedy zvedne zatez o ~1-2 %. `/tmp` je u projektu tmpfs, restart
kontejneru profilovani vypne sam.

**Profilovani skonci samo nejpozdeji za 24 h** od zapsani souboru (od revize 10), i kdyz ho
nikdo nesmaze - zapomenute profilovani by jinak zvedalo zatez produkce navzdy. Za podilem muze
byt mezerou oddeleny cas vyprseni (unix timestamp), kratsi ma prednost:
`echo "0.05 $(($(date +%s) + 3600))" > /tmp/php-profile` = hodina. Po vyprseni soubor zustava
a jen se ignoruje (`enabled: false` v `profile-status.php`), nove zapsani profilovani zase zapne.
Na serverech ADT to dela `./run.sh --php-profile <projekt> on [doba] [podil]` ze
server-management-scripts (doba nejvys 24 h, navic na hostu casovac, ktery soubor smaze).

Profilovany pozadavek pricte svuj cas k citacum **endpointu** (cesta s cisly nahrazenymi `{id}`,
dlouhymi hexa retezci `{hash}` a Nette signalem `?do=`, napr. `/patient/{id}`, `/?do=shifts`)
rozdeleny po **skupinach kodu**: `db` (PDO, mysqli), `doctrine`, `latte`, `nette_di`, `nette`,
`autoload`, `io` (curl, sockety, soubory), `app` (`App\`), `adt` (`ADT\`), `tracy`, `vendor`
(ostatni knihovny), `php` (vestavene funkce). Nejvys `PROFILE_MAX_ENDPOINTS` (env, vychozi 100)
ruznych endpointu, dalsi jdou do `other` - Prometheus neunese neomezene labely. Citace cte
`/usr/local/lib/monitoring/profile-status.php` (monitoring-agent_phpfpm pres FastCGI, stejne
jako opcache; vraci i `enabled`, `rate` a `expires`) a v Prometheu jsou jako `php_profile_*` -
ktere endpointy berou nejvic CPU a cim. Zadne parametry ani data uzivatelu se neukladaji.

Podrobny profil jednoho pozadavku (40 nejdrazsich funkci) pro rucni rozbor: hlavicka
`X-Profile: <nazev>` prepise `/tmp/php-profile-<nazev>.txt` v kontejneru - jen se zapnutym
profilovanim a jen soubor, ktery uz existuje. Nazev (`[a-z0-9-]`, 16-40 znaku) je tajemstvi
toho, kdo profilovani zapnul: zvenku tak nikdo nevynuti profilovani kazdeho pozadavku ani nezaplni
`/tmp` (tmpfs) soubory.

```bash
name=$(openssl rand -hex 8)
docker exec <projekt>_php touch /tmp/php-profile-$name.txt
curl -H "X-Profile: $name" https://<domena>/...
docker exec <projekt>_php cat /tmp/php-profile-$name.txt
```

Projekt s vlastnim `auto_prepend_file` (v `conf.d` nebo poolu) image prepise a profilovani nema.

## Composer

Runtime varianty composer neobsahuji - bezicimu webu ani consumerum neni k nicemu. Na
`composer install` je varianta `-composer`, jeden image pro vsechny projekty - projekt ho
nebuildi, jen pouziva (compose sluzba s `image:`, na serveru otagovany jako
`<projekt>_composer` pro `dr`). Ma navic composer, git (VCS repozitare, verze root
balicku), unzip, `safe.directory '*'` (repo casto patri jinemu UID),
`COMPOSER_HOME=/var/www/html/.composer` a `ENTRYPOINT ["composer"]`.

Rozsireni projektu v nem nejsou, proto ma nastaveno `COMPOSER_IGNORE_PLATFORM_REQ=ext-*`
(verzi PHP composer kontroluje dal - je stejna jako u runtime se stejnym tagem). Kontrolu
rozsireni nahrazuje runtime image projektu: `install-composer-extensions` nad composer.json
**a composer.lock** do nej nainstaluje vse, co projekt nebo kterykoli balicek vyzaduje. Bez
locku by rozsireni vyzadovane jen zavislosti chybelo a projevilo se az fatalem za behu.
Ignorovana rozsireni composer vynecha i z `vendor/composer/platform_check.php`.

Instalaci rozsireni dela Dockerfile projektu ve dvou stagich pres `install-composer-extensions`
(viz Rozsireni projektu vyse).

```yaml
  composer:
    image: appsdevteam/php:8.5.13-8-20261109-composer   # stejny tag jako runtime
    profiles: ["tools"]
    volumes:
      - ${PROJECT_ROOT}:/var/www/html
```

Build a push dela GitHub Actions (`.github/workflows/base.yml`). `docker build` sestavi
jen image pro aktualni architekturu stroje - pro skutecny multiplatformni build
(`linux/amd64` + `linux/arm64`, jako dela CI) je potreba `docker buildx` s `--platform`
a vysledek rovnou pushnout (multi-arch manifest nejde ulozit lokalne, jen pres registry).
Build pro amd64 na Apple Silicon bezi pres QEMU emulaci a je o dost pomalejsi nez
nativni build.

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
  --build-arg PHP_VERSION=8.5.13 --build-arg VARIANT=fpm --target app --push \
  -t appsdevteam/php:8.5.13-8-fpm -t appsdevteam/php:8.5-fpm base
```

Varianta `-cli` = `--build-arg VARIANT=cli --target app`, `-supervisor` =
`--build-arg VARIANT=cli --target supervisor`, `-composer` =
`--build-arg VARIANT=cli --target composer`.
