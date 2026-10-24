# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm a -cli

Spolecny zaklad PHP pro webove projekty, platformy `linux/amd64` a `linux/arm64`. Dve
varianty se stejnym obsahem:

- `-fpm` - web (php-fpm). Tagy `8.4-fpm` / `8.5-fpm` a pripnute `8.4.27-3-fpm` /
  `8.5.12-3-fpm`.
- `-cli` - consumery a dalsi procesy bez webu (supervisor apod.), bez php-fpm. Tagy
  `8.4-cli` / `8.5-cli` a pripnute `8.4.27-3-cli` / `8.5.12-3-cli`.

Doporuceny je pripnuty tag (reprodukovatelny build): `<verze PHP>-<revize>-<varianta>`. Revize (`REVISION` ve workflow) se zvysuje pri
zmene image, ktera muze rozbit projekt (odebrany nastroj, jine chovani) - tydenni rebuild
s bezpecnostnimi opravami ji nemeni. Starsi revize se uz nesestavuji, zustanou zmrazene,
dokud projekt neprejde na novou.

Obsahuje:

- composer (instalator overeny proti podpisu), git, unzip
- `eoh`, `cachetool`
- `dma` jako `sendmail` - `mail()` doruci primo na MX prijemce, bez API a klice. Jen na
  chybove e-maily (adt/error-logger), bezna posta projektu patri do `adt/mailer`.
  Projekt `sendmail_path` nenastavuje (vychozi `/usr/sbin/sendmail -t -i` je dma)
  Spool je v `/tmp`, kontejner proto potrebuje zapisovatelny `/tmp` (u `read_only` tmpfs).
- rozsireni `intl`, `opcache`, `pdo_mysql`, `sockets`, `sodium`, `zip`
- `/usr/local/lib/monitoring/opcache-status.php` - stav opcache pro monitoring
  (`monitoring-agent_phpfpm` v server-management-scripts)
- bez gcc/make a hlavicek (`$PHPIZE_DEPS` odstraneny), s aktualnimi bezpecnostnimi
  opravami Debianu (rebuild kazdy tyden)

Pouziti v projektu:

```dockerfile
FROM appsdevteam/php:8.5.12-3-fpm
RUN install-php-extensions gd imagick
```

Web i consumery ze stejneho Dockerfile projektu - varianta jako build arg, compose ji
u consumeru nastavi na `cli`:

```dockerfile
ARG VARIANT=fpm
FROM appsdevteam/php:8.5.12-3-${VARIANT}
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Rozsireni jde take odvodit z `ext-*` zavislosti v composer.json projektu, aby je
programator nemusel vyjmenovavat na dvou mistech:

```dockerfile
FROM appsdevteam/php:8.5.12-3-fpm
COPY composer.json ./
RUN install-composer-extensions
```

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Pool (vc. `pm.status_path = /status`) a ini si
drzi projekt. Pri prekrytem `conf.d` je potreba rozsireni zapnout v ini projektu
(`extension=intl.so` ...); na PHP 8.4 navic `zend_extension=opcache.so` - od 8.5 je
opcache zakompilovana natvrdo.

Build a push dela GitHub Actions (`.github/workflows/base.yml`). `docker build` sestavi
jen image pro aktualni architekturu stroje - pro skutecny multiplatformni build
(`linux/amd64` + `linux/arm64`, jako dela CI) je potreba `docker buildx` s `--platform`
a vysledek rovnou pushnout (multi-arch manifest nejde ulozit lokalne, jen pres registry).
Build pro amd64 na Apple Silicon bezi pres QEMU emulaci a je o dost pomalejsi nez
nativni build.

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
  --build-arg PHP_VERSION=8.5.12 --build-arg VARIANT=fpm --push \
  -t appsdevteam/php:8.5.12-3-fpm -t appsdevteam/php:8.5-fpm base
```
