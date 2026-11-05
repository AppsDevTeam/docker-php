# Spolecny zaklad PHP pro webove projekty, aby se bezpecnostni opravy nemusely zanaset do
# kazdeho repozitare zvlast. Tri varianty ze stejneho Dockerfile:
# - appsdevteam/php:<verze>-<revize>-fpm - web (php-fpm), stage runtime s VARIANT=fpm
# - appsdevteam/php:<verze>-<revize>-cli - consumery a dalsi procesy bez webu (supervisor
#   apod.), bez php-fpm, stage runtime s VARIANT=cli
# - appsdevteam/php:<verze>-<revize>-supervisor - consumery pod supervisorem, stage supervisor
#   (cli + supervisor)
# - appsdevteam/php:<verze>-<revize>-composer - jednorazovy kontejner pro `composer install`,
#   stage composer (cli + composer, git, unzip)
#
# Verze PHP a varianta se predavaji z workflow (.github/workflows/base.yml), verze je
# pripnuta na patch kvuli reprodukovatelnosti. Stage se vybira --target (bez nej vznikne
# posledni = composer). Lokalne:
#   docker build --build-arg PHP_VERSION=8.5.12 --build-arg VARIANT=cli --target runtime base
#
# Co sem NEPATRI: konfigurace v /usr/local/etc/php/conf.d a /usr/local/etc/php-fpm.d.
# Projekty oba adresare v docker-compose.yml prekryvaji mountem, takze cokoli, co by sem
# image zapsal, v kontejneru stejne zmizi. Pool (vc. pm.status_path) a ini si dal drzi projekt.
# Vychozi ini hodnoty image jdou do /usr/local/etc/php/php.ini (viz php.ini vedle) a ini
# rozsireni do /usr/local/etc/php/base.d (viz PHP_INI_SCAN_DIR nize) - ani jedno mount
# neprekryva a projekt je muze ve svem conf.d prepsat.
ARG PHP_VERSION
ARG VARIANT=fpm
FROM php:${PHP_VERSION}-${VARIANT} AS runtime

# Composer, git ani unzip tu nejsou - runtime je nepotrebuje a po pripadnem pruniku by jen
# pomahaly. Ma je jen varianta -composer (stage composer na konci).
# dma (/usr/sbin/sendmail) doruci e-mail z mail() primo na MX prijemce, bez API a klice. Je jen
# na systemovou postu - chyby z adt/error-logger na error@mail.appsdevteam.com. Bezna posta
# projektu jde pres adt/mailer (mailapi), na cizi schranky by prima posta neprosla antispamem.
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
	dma \
&& rm -rf /var/lib/apt/lists/*

# Projekty spousti PHP s read_only, no-new-privileges a neroot uzivatelem. Setgid mail se pak
# neuplatni, takze dma musi precist config bez nej (nic tajneho v nem neni, prihlasovaci udaje
# jsou v auth.conf) a spool musi lezet na zapisovatelnem /tmp (tmpfs). Fronta nepreziva
# restart kontejneru.
RUN sed -i 's|^#\?SPOOLDIR.*|SPOOLDIR /tmp|' /etc/dma/dma.conf \
&& grep -qx 'SPOOLDIR /tmp' /etc/dma/dma.conf \
&& chmod 0644 /etc/dma/dma.conf

# Helper pro otevirani souboru v editoru na hostovi (lokalni vyvoj) - viz komentar ve skriptu.
# spustitelny je uz v gitu - COPY prava zachova (--chmod by vyzadoval BuildKit)
COPY eoh /usr/local/bin/eoh

# Reset opcache PHP-FPM po deployi (`cachetool opcache:reset` v makefile projektu).
RUN curl --fail -sOL https://gordalina.github.io/cachetool/downloads/cachetool-9.2.1.phar && chmod +x cachetool-9.2.1.phar && mv cachetool-9.2.1.phar /usr/local/bin/cachetool

# Vychozi ini hodnoty (velikosti bufferu opcache) - viz komentar v souboru.
COPY php.ini /usr/local/etc/php/php.ini

# Pool podle stage: krome *.conf nacte php-fpm i php-fpm.d/default.${STAGE}.conf.template
# (konvence projektu, sablona z config/docker). Projekt tak nemusi pri startu kopirovat
# sablonu na *.conf a do mountu php-fpm.d nic nezapisuje. ${STAGE} rozvine php-fpm
# z prostredi kontejneru, bez STAGE (a v projektu bez sablony) se nenacte nic navic.
# Pool definovany dvakrat (projekt sablonu stale kopiruje) php-fpm slouci do jednoho.
# V -cli php-fpm neni.
RUN if [ -f /usr/local/etc/php-fpm.conf ]; then \
		sed -i 's|^include=/usr/local/etc/php-fpm.d/\*.conf$|&\ninclude=/usr/local/etc/php-fpm.d/default.${STAGE}.conf.template|' /usr/local/etc/php-fpm.conf \
		&& grep -qx 'include=/usr/local/etc/php-fpm.d/default.${STAGE}.conf.template' /usr/local/etc/php-fpm.conf; \
	fi

# Stav opcache pro monitoring (monitoring-agent_phpfpm v server-management-scripts ho vola
# pres FastCGI :9000). Mimo document root - nginx ho neserviruje.
COPY opcache-status.php /usr/local/lib/monitoring/opcache-status.php

# Ini z base image (zapnuti rozsireni, docker-fpm.ini) jsou v base.d, ne v conf.d - ten projekty
# prekryvaji mountem a musely by pak rozsireni zapinat znovu ve svem ini. PHP prohledava base.d
# pred conf.d, takze ini projektu ma posledni slovo. Projekt, ktery PHP_INI_SCAN_DIR nastavuje
# sam (napr. ini podle stage), musi base.d ponechat na zacatku.
ENV PHP_INI_SCAN_DIR=/usr/local/etc/php/base.d:/usr/local/etc/php/conf.d
RUN mkdir /usr/local/etc/php/base.d \
&& mv /usr/local/etc/php/conf.d/*.ini /usr/local/etc/php/base.d/

# Spolecne minimum rozsireni. Projekt si dalsi doinstaluje sam: `RUN install-php-extensions gd`.
# Skutecny mlocati skript je v /usr/local/lib, v PATH je wrapper (viz install-php-extensions
# vedle), ktery kolem nej doinstaluje a zase odstrani $PHPIZE_DEPS a ini novych rozsireni
# presune do base.d.
RUN curl --fail -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/lib/install-php-extensions \
&& chmod +x /usr/local/lib/install-php-extensions
# spustitelny je uz v gitu - COPY prava zachova (--chmod by vyzadoval BuildKit)
COPY install-php-extensions /usr/local/bin/install-php-extensions
# Pres wrapper i tady: $PHPIZE_DEPS, ktere base image nechava nainstalovane pro potomky, po sobe
# odstrani. Jinak by v image zbyvalo gcc/make (pouzitelne po pripadnem pruniku) a hlavicky
# linux-libc-dev, ktere generuji CVE hlaseni proti kernelu, jenz v kontejneru vubec nebezi
# (dodava ho host). Potomek si je wrapperem zase doinstaluje.
RUN install-php-extensions intl opcache pdo_mysql sockets sodium zip

# Alternativa k rucnimu vyjmenovani rozsireni: precte ext-* z composer.json projektu
# a nainstaluje je - viz komentar ve skriptu.
COPY install-composer-extensions /usr/local/bin/install-composer-extensions

# Bezpecnostni aktualizace VSECH balicku. Tag base image je pripnuty, ale Debian vydava
# opravy prubezne a upstream php image se rebuilduje pomaleji - workflow proto image
# rebuilduje i tydne. MUSI byt az za install-php-extensions, jinak by se netykalo knihoven
# rozsireni. `upgrade` misto `dist-upgrade` - nikdy nepridava nove balicky, takze nezvrati
# purge vyse.
RUN apt-get update && apt-get upgrade -y \
&& rm -rf /var/lib/apt/lists/*


# Varianta -supervisor: runtime (s VARIANT=cli) + supervisor pro consumery. Supervisor je python
# program, takze si pritahne i python3 - proto neni v -cli a -fpm, ktere ho nepotrebuji.
# Konfiguraci (/etc/supervisor) si mountuje projekt; CMD je jen vychozi, projekty ho casto
# prepisuji (vyber konfigurace podle stage).
FROM runtime AS supervisor
RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
&& rm -rf /var/lib/apt/lists/*
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]


# Varianta -composer: runtime (s VARIANT=cli) + composer. Jeden image pro vsechny projekty -
# rozsireni projektu v nem nejsou, proto composer ignoruje ext-* pozadavky
# (COMPOSER_IGNORE_PLATFORM_REQ nize). Ze ma runtime vsechna potrebna rozsireni, zarucuje
# install-composer-extensions nad composer.lock v runtime image projektu - viz README.
FROM runtime AS composer

# git kvuli VCS repozitarum v composer.json a verzi root balicku, unzip pro rozbalovani archivu.
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
&& rm -rf /var/lib/apt/lists/*

# Repozitar patri jinemu UID nez procesu composeru (v CI /builds patri rootu, na serveru
# release vlastni deploy uzivatel, kontejner bezi pod UID projektu) - git by pak odmitl cist
# metadata ("dubious ownership") a composer by nezjistil verzi root balicku.
RUN git config --system --add safe.directory '*'

# Jen ext-*, ne php: verzi PHP ma composer image stejnou jako runtime (stejny tag).
# Dan: composer ignorovana rozsireni vynecha i z vendor/composer/platform_check.php.
ENV COMPOSER_IGNORE_PLATFORM_REQ=ext-*
# Cache composeru v projektu (<projekt>/.composer, projekt si ji gitignoruje) - kontejner
# byva read_only a --rm, jinak by se cache stahovala pri kazdem behu znovu.
ENV COMPOSER_HOME=/var/www/html/.composer

WORKDIR /var/www/html
ENTRYPOINT ["composer"]
