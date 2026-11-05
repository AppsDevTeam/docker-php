# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm, -cli, -supervisor a -composer

Spolecny zaklad PHP pro webove projekty, platformy `linux/amd64` a `linux/arm64`. Varianty:

- `-fpm` - web (php-fpm). Tagy `8.4-fpm` / `8.5-fpm`, plovouci podle revize `8.4.27-6-fpm` /
  `8.5.12-6-fpm`.
- `-cli` - konzolove procesy bez webu, bez php-fpm. Tagy `8.4-cli` / `8.5-cli`, plovouci
  podle revize `8.4.27-6-cli` / `8.5.12-6-cli`. Obsahem stejne jako `-fpm`.
- `-supervisor` - `-cli` + supervisor pro consumery (background queue apod.). Konfiguraci
  `/etc/supervisor` mountuje projekt, vychozi `CMD` je `supervisord -n`. Tagy
  `8.4-supervisor` / `8.5-supervisor`, plovouci podle revize `8.4.27-6-supervisor` /
  `8.5.12-6-supervisor`.
- `-composer` - `-cli` + composer, git, unzip, jednorazovy kontejner pro `composer install`
  (viz Composer nize). Tagy `8.4-composer` / `8.5-composer`, plovouci podle revize `8.4.27-6-composer` /
  `8.5.12-6-composer`.

Kazdy build ma navic neprepisovatelny tag s datem buildu (UTC):
`<verze PHP>-<revize>-<YYYYMMDD>-<varianta>`, napr. `8.4.27-6-20261105-fpm`. Ten je
doporuceny - jen u nej je z nazvu jasne, co v image je, a dva buildy projektu (dva hosty,
CI a server) dostanou stejny obsah. `<verze PHP>-<revize>-<varianta>` se pri kazdem buildu
prepise (obsahuje vzdy posledni build), stejne jako `<minor>-<varianta>`. V jeden den vznika
nejvys jeden build kazde varianty - dalsi spusteni workflow ten den variantu, ktera uz datovy
tag ma, preskoci (datovy tag se neprepisuje).

Bezpecnostni opravy Debianu prinasi tydenni rebuild (pondeli). Projekt na datovem tagu je
dostane vedomym bumpem data - signalem je audit image (trivy apod.), ktery zacne hlasit
opravitelnou zranitelnost.

Revize (`REVISION` ve workflow) se zvysuje pri zmene image, ktera muze rozbit projekt
(odebrany nastroj, jine chovani) - tydenni rebuild s bezpecnostnimi opravami ji nemeni.
Starsi revize se uz nesestavuji, zustanou zmrazene, dokud projekt neprejde na novou.

Obsahuje:

- bez composeru, gitu a unzipu (od revize 5, ma je jen `-composer`) - viz Composer nize
- `eoh` (od revize 6 pres `/dev/tcp` bashe - projekt uz nedoinstalovava netcat) a `cachetool`
- `dma` jako `sendmail` - `mail()` doruci primo na MX prijemce, bez API a klice. Jen na
  chybove e-maily (adt/error-logger), bezna posta projektu patri do `adt/mailer`.
  Projekt `sendmail_path` nenastavuje (vychozi `/usr/sbin/sendmail -t -i` je dma)
  Spool je v `/tmp`, kontejner proto potrebuje zapisovatelny `/tmp` (u `read_only` tmpfs).
- rozsireni `intl`, `opcache`, `pdo_mysql`, `sockets`, `sodium`, `zip`
- `/usr/local/etc/php/php.ini` s vychozimi hodnotami: `opcache.interned_strings_buffer=32`
  a `opcache.memory_consumption=256` (PHP ma 8 a 128 MB, velkym aplikacim nestaci).
  Pamet opcache se zabira az pri zaplnovani, malym projektum vyssi limit nic nestoji.
  Dale `session.upload_progress.enabled=0` (od revize 4) - session lifecycle RCE,
  CVE-2026-109619. Projekt, ktery prubeh nahravani pouziva, si ho zapne ve svem `conf.d`.
- `/usr/local/lib/monitoring/opcache-status.php` - stav opcache pro monitoring
  (`monitoring-agent_phpfpm` v server-management-scripts)
- bez gcc/make a hlavicek (`$PHPIZE_DEPS` odstraneny), s aktualnimi bezpecnostnimi
  opravami Debianu (rebuild kazdy tyden)

Pouziti v projektu:

```dockerfile
FROM appsdevteam/php:8.5.12-6-20261105-fpm
RUN install-php-extensions gd imagick
```

Web i consumery ze stejneho Dockerfile projektu - varianta jako build arg, compose ji
u consumeru nastavi na `supervisor`. Rozsireni projektu tak dostanou oba image stejna:

```dockerfile
ARG VARIANT=fpm
FROM appsdevteam/php:8.5.12-6-20261105-${VARIANT}
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Rozsireni jde take odvodit z `ext-*` zavislosti projektu, aby je programator nemusel
vyjmenovavat na dvou mistech. Zdrojem je composer.json (rozsireni projektu) a composer.lock
(rozsireni, ktera vyzaduji nainstalovane balicky). Lock predavej vzdy, pokud projekt pouziva
variantu `-composer` - viz Composer nize. Rozsireni, ktera PHP uz ma, se preskoci.

```dockerfile
FROM appsdevteam/php:8.5.12-6-20261105-fpm
COPY composer.json composer.lock ./
RUN install-composer-extensions composer.json composer.lock
```

Bez prepinace se cte jen `require` / `packages` (produkcni image). `--dev` prida i
`require-dev` / `packages-dev`, obdobne jako `composer install` vs. `composer install --no-dev`:

```dockerfile
RUN install-composer-extensions --dev composer.json composer.lock
```

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Pool (vc. `pm.status_path = /status`) a ini si
drzi projekt. Vychozi hodnoty image jsou proto v `/usr/local/etc/php/php.ini` (mimo
mount) - projekt je muze ve svem `conf.d` prepsat.

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

```dockerfile
FROM appsdevteam/php:8.5.12-6-20261105-fpm
ARG DEV=0
COPY composer.json composer.lock ./
RUN install-composer-extensions $([ "$DEV" = "1" ] && echo --dev) composer.json composer.lock
```

```yaml
  composer:
    image: appsdevteam/php:8.5.12-6-20261105-composer   # stejny tag jako runtime
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
  --build-arg PHP_VERSION=8.5.12 --build-arg VARIANT=fpm --target runtime --push \
  -t appsdevteam/php:8.5.12-6-fpm -t appsdevteam/php:8.5-fpm base
```

Varianta `-supervisor` = `--build-arg VARIANT=cli --target supervisor`, `-composer` =
`--build-arg VARIANT=cli --target composer`.
