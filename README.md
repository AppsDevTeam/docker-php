# docker-php

## appsdevteam/php:&lt;verze&gt;-fpm

Spolecny zaklad php-fpm pro webove projekty. Tagy `8.4-fpm` / `8.5-fpm` a pripnute
`8.4.27-2-fpm` / `8.5.12-2-fpm` (doporuceno - reprodukovatelny build), platformy
`linux/amd64` a `linux/arm64`.

Pripnuty tag je `<verze PHP>-<revize>-fpm`. Revize (`REVISION` ve workflow) se zvysuje pri
zmene image, ktera muze rozbit projekt (odebrany nastroj, jine chovani) - tydenni rebuild
s bezpecnostnimi opravami ji nemeni. Starsi revize se uz nesestavuji, zustanou zmrazene,
dokud projekt neprejde na novou. Revize 1 jsou tagy bez cisla (`8.4.27-fpm`, ty jeste
obsahovaly adt/sendmail), revize 2 nahradila adt/sendmail za dma.

Obsahuje:

- composer (instalator overeny proti podpisu), git, unzip
- `eoh`, `cachetool`
- `dma` jako `sendmail` - `mail()` doruci primo na MX prijemce, bez API a klice. Jen na
  chybove e-maily (adt/error-logger), bezna posta projektu patri do `adt/mailer`.
  Projekt `sendmail_path` nenastavuje (vychozi `/usr/sbin/sendmail -t -i` je dma)
- rozsireni `intl`, `opcache`, `pdo_mysql`, `sockets`, `sodium`, `zip`
- `/usr/local/lib/monitoring/opcache-status.php` - stav opcache pro monitoring
  (`monitoring-agent_phpfpm` v server-management-scripts)
- bez gcc/make a hlavicek (`$PHPIZE_DEPS` odstraneny), s aktualnimi bezpecnostnimi
  opravami Debianu (rebuild kazdy tyden)

Pouziti v projektu:

```dockerfile
FROM appsdevteam/php:8.5.12-2-fpm
RUN install-php-extensions gd imagick
```

`install-php-extensions` je wrapper: build zavislosti si sam doinstaluje a po kompilaci
odstrani, `apt-get purge $PHPIZE_DEPS` uz projekt delat nemusi.

Do image NEPATRI konfigurace v `/usr/local/etc/php/conf.d` a `/usr/local/etc/php-fpm.d` -
projekty oba adresare prekryvaji mountem. Pool (vc. `pm.status_path = /status`) a ini si
drzi projekt. Pri prekrytem `conf.d` je potreba rozsireni zapnout v ini projektu
(`extension=intl.so` ...); na PHP 8.4 navic `zend_extension=opcache.so` - od 8.5 je
opcache zakompilovana natvrdo.

Build a push dela GitHub Actions (`.github/workflows/fpm.yml`). `docker build` sestavi
jen image pro aktualni architekturu stroje - pro skutecny multiplatformni build
(`linux/amd64` + `linux/arm64`, jako dela CI) je potreba `docker buildx` s `--platform`
a vysledek rovnou pushnout (multi-arch manifest nejde ulozit lokalne, jen pres registry).
Build pro amd64 na Apple Silicon bezi pres QEMU emulaci a je o dost pomalejsi nez
nativni build.

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
  --build-arg PHP_VERSION=8.5.12 --push \
  -t appsdevteam/php:8.5.12-2-fpm -t appsdevteam/php:8.5-fpm fpm
```

## appsdevteam/php:7.1, 8.4 (CLI)

Docker PHP CLI image including following libraries:

- composer
- git
- zip

and following extensions:

- ssh2

Example of running:

```
docker run -it --rm  -e "COMPOSER_HOME=/usr/src/myapp/.composer" -v "$PWD":/usr/src/myapp -w /usr/src/myapp/ appsdevteam/php:7.1 composer "$@"
```
