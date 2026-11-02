# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm, -cli a -composer

Spolecny zaklad PHP pro webove projekty, platformy `linux/amd64` a `linux/arm64`. Varianty:

- `-fpm` - web (php-fpm). Tagy `8.4-fpm` / `8.5-fpm` a pripnute `8.4.27-5-fpm` /
  `8.5.12-5-fpm`.
- `-cli` - consumery a dalsi procesy bez webu (supervisor apod.), bez php-fpm. Tagy
  `8.4-cli` / `8.5-cli` a pripnute `8.4.27-5-cli` / `8.5.12-5-cli`. Obsahem stejne jako `-fpm`.
- `-composer` - `-cli` + composer, git, unzip, jednorazovy kontejner pro `composer install`
  (viz Composer nize). Tagy `8.4-composer` / `8.5-composer` a pripnute `8.4.27-5-composer` /
  `8.5.12-5-composer`.

Doporuceny je pripnuty tag (reprodukovatelny build): `<verze PHP>-<revize>-<varianta>`. Revize (`REVISION` ve workflow) se zvysuje pri
zmene image, ktera muze rozbit projekt (odebrany nastroj, jine chovani) - tydenni rebuild
s bezpecnostnimi opravami ji nemeni. Starsi revize se uz nesestavuji, zustanou zmrazene,
dokud projekt neprejde na novou.

Obsahuje:

- bez composeru, gitu a unzipu (od revize 5, ma je jen `-composer`) - viz Composer nize
- `eoh`, `cachetool`
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
FROM appsdevteam/php:8.5.12-5-fpm
RUN install-php-extensions gd imagick
```

Web i consumery ze stejneho Dockerfile projektu - varianta jako build arg, compose ji
u consumeru nastavi na `cli`:

```dockerfile
ARG VARIANT=fpm
FROM appsdevteam/php:8.5.12-5-${VARIANT}
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Rozsireni jde take odvodit z `ext-*` zavislosti v composer.json projektu, aby je
programator nemusel vyjmenovavat na dvou mistech:

```dockerfile
FROM appsdevteam/php:8.5.12-5-fpm
COPY composer.json ./
RUN install-composer-extensions composer.json
```

Bez prepinace se cte jen `require` (produkcni image). `--dev` prida i `require-dev`,
obdobne jako `composer install` vs. `composer install --no-dev`:

```dockerfile
RUN install-composer-extensions --dev composer.json
```

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Pool (vc. `pm.status_path = /status`) a ini si
drzi projekt. Vychozi hodnoty image jsou proto v `/usr/local/etc/php/php.ini` (mimo
mount) - projekt je muze ve svem `conf.d` prepsat. Pri prekrytem `conf.d` je potreba
rozsireni zapnout v ini projektu (`extension=intl.so` ...); na PHP 8.4 navic `zend_extension=opcache.so` - od 8.5 je
opcache zakompilovana natvrdo.

## Composer

Runtime varianty composer neobsahuji - bezicimu webu ani consumerum neni k nicemu. Projekt
ho ma v samostatnem stage stejneho Dockerfile (jednorazovy kontejner pro `composer install`)
z varianty `-composer`. Ta ma navic jen composer, git (VCS repozitare, verze root balicku),
unzip, `safe.directory '*'` (repo casto patri jinemu UID) a `ENTRYPOINT ["composer"]`.

Rozsireni projektu si composer stage doinstaluje stejne jako runtime - `composer install`
kontroluje `ext-*` proti PHP, ve kterem bezi. `--ignore-platform-req=ext-*` neni nahrada:
composer pak ignorovana rozsireni vynecha i z runtime kontroly `vendor/composer/platform_check.php`.
Rozsireni nad ramec base image se tak kompiluji dvakrat (runtime a composer stage).

```dockerfile
ARG DEV=0

FROM appsdevteam/php:8.5.12-5-fpm AS base
ARG DEV
COPY composer.json ./
RUN install-composer-extensions $([ "$DEV" = "1" ] && echo --dev) composer.json

FROM appsdevteam/php:8.5.12-5-composer AS composer
ARG DEV
COPY composer.json ./
RUN install-composer-extensions $([ "$DEV" = "1" ] && echo --dev) composer.json

FROM base AS fpm
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
  -t appsdevteam/php:8.5.12-5-fpm -t appsdevteam/php:8.5-fpm base
```

Varianta `-composer` = `--build-arg VARIANT=cli --target composer`.
