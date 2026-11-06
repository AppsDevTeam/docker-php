# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm, -cli, -supervisor a -composer

Spolecny zaklad PHP pro webove projekty, platformy `linux/amd64` a `linux/arm64`. Varianty:

- `-fpm` - web (php-fpm). Tagy `8.4-fpm` / `8.5-fpm`, plovouci podle revize `8.4.27-7-fpm` /
  `8.5.12-7-fpm`.
- `-cli` - konzolove procesy bez webu, bez php-fpm. Tagy `8.4-cli` / `8.5-cli`, plovouci
  podle revize `8.4.27-7-cli` / `8.5.12-7-cli`. Obsahem stejne jako `-fpm`.
- `-supervisor` - `-cli` + supervisor pro consumery (background queue apod.). Konfiguraci
  `/etc/supervisor` mountuje projekt, vychozi `CMD` je `supervisord -n`. Tagy
  `8.4-supervisor` / `8.5-supervisor`, plovouci podle revize `8.4.27-7-supervisor` /
  `8.5.12-7-supervisor`.
- `-composer` - `-cli` + composer, git, unzip, jednorazovy kontejner pro `composer install`
  (viz Composer nize). Tagy `8.4-composer` / `8.5-composer`, plovouci podle revize `8.4.27-7-composer` /
  `8.5.12-7-composer`.

Kazdy build ma navic neprepisovatelny tag s datem buildu (UTC):
`<verze PHP>-<revize>-<YYYYMMDD>-<varianta>`, napr. `8.4.27-7-20261106-fpm`. Ten je
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

Pouziti v projektu - rozsireni z `ext-*` projektu se nainstaluji sama (viz Rozsireni
projektu nize), `composer.json` a `composer.lock` musi byt v build contextu:

```dockerfile
FROM appsdevteam/php:8.5.12-7-20261106-fpm
```

Rozsireni mimo `ext-*` (napr. pro nastroj, ktery composer nezna) projekt doinstaluje sam:

```dockerfile
FROM appsdevteam/php:8.5.12-7-20261106-fpm
RUN install-php-extensions imagick
```

Web i consumery ze stejneho Dockerfile projektu - varianta jako build arg, compose ji
u consumeru nastavi na `supervisor`. Rozsireni projektu tak dostanou oba image stejna:

```dockerfile
ARG VARIANT=fpm
FROM appsdevteam/php:8.5.12-7-20261106-${VARIANT}
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Pool (vc. `pm.status_path = /status`) a ini si
drzi projekt. Vychozi hodnoty image jsou proto v `/usr/local/etc/php/php.ini` (mimo
mount) - projekt je muze ve svem `conf.d` prepsat.

### Rozsireni projektu (`ONBUILD`, od revize 7)

`-fpm`, `-cli` a `-supervisor` maji `ONBUILD`: pri buildu image projektu (hned za jeho `FROM`)
zkopiruji `composer.json` a `composer.lock` z build contextu a `install-composer-extensions`
nainstaluje vsechna `ext-*`, ktera vyzaduje projekt (composer.json) nebo kterykoli
nainstalovany balicek (composer.lock). Rozsireni, ktera PHP uz ma, se preskoci. Projekt je
tak nevyjmenovava na dvou mistech a na instalaci nemuze zapomenout.

- Oba soubory musi byt v build contextu, jinak build spadne (`"/composer.lock": not found`) -
  zamerne, kontrola rozsireni se nesmi tise vynechat. Context projektu byva samostatny
  adresar jen pro Dockerfile, soubory do nej projekt kopiruje pred buildem.
- Bez build argu se cte jen `require` / `packages` (produkcni image). Build arg `DEV=1`
  prida i `require-dev` / `packages-dev`, obdobne jako `composer install` vs.
  `composer install --no-dev`. Projekt si `ARG DEV` deklarovat nemusi, staci ho predat
  (`--build-arg DEV=1`, `build.args` v compose).
- `ONBUILD` se provede jen u prime potomka (image projektu), ne u image, ktery dedi z nej.
- Projekt, ktery instalaci jeste vola sam (`COPY composer.json composer.lock` +
  `RUN install-composer-extensions`, revize 6 a starsi), funguje dal - podruhe se nic
  neinstaluje. Radky muze smazat.
- `-composer` `ONBUILD` nema (jeden image pro vsechny projekty, nebuildi se).

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

Instalaci rozsireni dela `ONBUILD` (viz Rozsireni projektu vyse), Dockerfile projektu je jen
`FROM`:

```dockerfile
FROM appsdevteam/php:8.5.12-7-20261106-fpm
```

```yaml
  composer:
    image: appsdevteam/php:8.5.12-7-20261106-composer   # stejny tag jako runtime
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
  --build-arg PHP_VERSION=8.5.12 --build-arg VARIANT=fpm --target app --push \
  -t appsdevteam/php:8.5.12-7-fpm -t appsdevteam/php:8.5-fpm base
```

Varianta `-cli` = `--build-arg VARIANT=cli --target app`, `-supervisor` =
`--build-arg VARIANT=cli --target supervisor`, `-composer` =
`--build-arg VARIANT=cli --target composer`.
