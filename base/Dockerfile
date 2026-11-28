# Spolecny zaklad PHP pro webove projekty, aby se bezpecnostni opravy nemusely zanaset do
# kazdeho repozitare zvlast. Tri varianty ze stejneho Dockerfile:
# - appsdevteam/php:<verze>-<revize>-fpm - web (php-fpm), stage app s VARIANT=fpm
# - appsdevteam/php:<verze>-<revize>-cli - consumery a dalsi procesy bez webu (supervisor
#   apod.), bez php-fpm, stage app s VARIANT=cli
# - appsdevteam/php:<verze>-<revize>-supervisor - consumery pod supervisorem, stage supervisor
#   (cli + supervisor)
# - appsdevteam/php:<verze>-<revize>-composer - jednorazovy kontejner pro `composer install`,
#   stage composer (cli + composer, git, unzip)
#
# Verze PHP a varianta se predavaji z workflow (.github/workflows/base.yml), verze je
# pripnuta na patch kvuli reprodukovatelnosti. Stage se vybira --target (bez nej vznikne
# posledni = composer). Lokalne:
#   docker build --build-arg PHP_VERSION=8.5.13 --build-arg VARIANT=cli --target app base
#
# Rozsireni podle composer.json a composer.lock projektu instaluje Dockerfile projektu
# ve dvou stagich pres install-composer-extensions --list / --install (viz stage app
# a README) - oba soubory musi mit v build contextu.
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

# Reset opcache PHP-FPM po deployi (`cachetool opcache:reset` v makefile projektu).
# Stahovane nastroje jsou pinovane na verzi a SHA256 - podstrceny soubor (prevzaty ucet, upstream)
# build zastavi misto toho, aby se dostal do image vsech projektu. Update = nova verze i SHA256.
ARG CACHETOOL_VERSION=9.2.1
ARG CACHETOOL_SHA256=099ce804cf12849dbc32bb2523d782acba676e6afddcf0a32c2cb398f5a21d16
RUN curl --fail -sSL https://gordalina.github.io/cachetool/downloads/cachetool-${CACHETOOL_VERSION}.phar -o /usr/local/bin/cachetool \
&& echo "${CACHETOOL_SHA256}  /usr/local/bin/cachetool" | sha256sum -c - \
&& chmod +x /usr/local/bin/cachetool

# Vychozi ini hodnoty (velikosti bufferu opcache) - viz komentar v souboru.
COPY php.ini /usr/local/etc/php/php.ini

# SBOM se samotnym PHP: base image ho kompiluje ze zdrojaku, v databazi baliku neni a trivy ani
# jiny skener o nem bez tohohle souboru nevi. Purl typu bitnami schvalne - CVE PHP trivy paruje
# jen proti Bitnami vulndb (generic purl nenajde nic). Verze z bezici binarky, ne z ARG.
RUN mkdir -p /usr/local/share/sbom \
&& php -r 'echo json_encode(["bomFormat" => "CycloneDX", "specVersion" => "1.5", "version" => 1, "components" => [["type" => "application", "name" => "php", "version" => PHP_VERSION, "purl" => "pkg:bitnami/php@" . PHP_VERSION, "bom-ref" => "pkg:bitnami/php@" . PHP_VERSION]]], JSON_UNESCAPED_SLASHES);' > /usr/local/share/sbom/php.cdx.json

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

# Profilovani pozadavku (xhprof) zapinatelne za behu souborem /tmp/php-profile - viz
# profile.php a profile-status.php (souhrn pro monitoring). auto_prepend_file jen ve variante
# -fpm (docker-fpm.ini ma jen ona; -cli a -supervisor prepend nemaji) - CLI procesy v -fpm
# image (cron, consumery) skript nactou, ale ten v CLI hned skonci. Ini je v base.d (viz nize),
# takze ho mount conf.d projektu neprekryje a ini projektu ho muze prepsat.
COPY profile.php profile-status.php /usr/local/lib/monitoring/
RUN if [ -f /usr/local/etc/php/conf.d/docker-fpm.ini ]; then \
		printf '\n; profilovani pozadavku za behu (profile.php v image appsdevteam/php)\nauto_prepend_file=/usr/local/lib/monitoring/profile.php\n' >> /usr/local/etc/php/conf.d/docker-fpm.ini; \
	fi

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
ARG INSTALL_PHP_EXTENSIONS_VERSION=2.13.2
ARG INSTALL_PHP_EXTENSIONS_SHA256=27fa8d80bd4e22feb9ddcadff1ca40c8f3e8ea942eb2dd8f446ed1afd2f2f0ab
RUN curl --fail -sSL https://github.com/mlocati/docker-php-extension-installer/releases/download/${INSTALL_PHP_EXTENSIONS_VERSION}/install-php-extensions -o /usr/local/lib/install-php-extensions \
&& echo "${INSTALL_PHP_EXTENSIONS_SHA256}  /usr/local/lib/install-php-extensions" | sha256sum -c - \
&& chmod +x /usr/local/lib/install-php-extensions
# spustitelny je uz v gitu - COPY prava zachova (--chmod by vyzadoval BuildKit)
COPY install-php-extensions /usr/local/bin/install-php-extensions
# Pres wrapper i tady: $PHPIZE_DEPS, ktere base image nechava nainstalovane pro potomky, po sobe
# odstrani. Jinak by v image zbyvalo gcc/make (pouzitelne po pripadnem pruniku) a hlavicky
# linux-libc-dev, ktere generuji CVE hlaseni proti kernelu, jenz v kontejneru vubec nebezi
# (dodava ho host). Potomek si je wrapperem zase doinstaluje.
# xhprof: profilovani pozadavku za behu (profile.php), vypnute nic nestoji.
RUN install-php-extensions intl opcache pdo_mysql sockets sodium xhprof zip

# Precte ext-* z composer.json a composer.lock projektu a nainstaluje je - viz komentar ve
# skriptu. Vola ho Dockerfile projektu (--list v prvni stagi, --install ve druhe).
COPY install-composer-extensions /usr/local/bin/install-composer-extensions

# Bezpecnostni aktualizace VSECH balicku. Tag base image je pripnuty, ale Debian vydava
# opravy prubezne a upstream php image se rebuilduje pomaleji - workflow proto image
# rebuilduje i tydne. MUSI byt az za install-php-extensions, jinak by se netykalo knihoven
# rozsireni. `upgrade` misto `dist-upgrade` - nikdy nepridava nove balicky, takze nezvrati
# purge vyse.
RUN apt-get update && apt-get upgrade -y \
&& rm -rf /var/lib/apt/lists/*


# Varianty -fpm a -cli: runtime + healthcheck. Rozsireni projektu (ext-* z composer.json
# a composer.lock) si instaluje Dockerfile projektu ve dvou stagich pres
# install-composer-extensions --list / --install (viz README) - do revize 11 to delal ONBUILD
# hned za FROM, jenze COPY composer.lock zahodil vrstvu s kompilaci rozsireni pri kazde
# zmene locku (bump libovolne knihovny = imagick se kompiloval znovu, minuty v kazdem jobu CI).
# Dve stage to resi: seznam rozsireni jde dal pres COPY --from, a ta je v cache podle obsahu.
FROM runtime AS app
# Healthcheck php-fpm + watchdog: zaseknuty FPM (2 min bez odpovedi) restartuje - viz
# komentar ve skriptu. Jen tady: -supervisor a -composer FPM nemaji, v -cli kontrola
# FPM mastera nenajde a projde. Projekt muze prepsat v compose (healthcheck:) nebo
# watchdog vypnout env PHP_FPM_WATCHDOG=0.
# spustitelny je uz v gitu - COPY prava zachova (--chmod by vyzadoval BuildKit)
COPY php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
COPY healthcheck.php /usr/local/lib/monitoring/healthcheck.php
HEALTHCHECK --interval=10s --timeout=8s --start-period=30s --retries=3 CMD ["php-fpm-healthcheck"]


# Varianta -supervisor: runtime (s VARIANT=cli) + supervisor pro consumery. Supervisor je python
# program, takze si pritahne i python3 - proto neni v -cli a -fpm, ktere ho nepotrebuji.
# supervisord.conf pro neroot ucet (od revize 13), programy si projekt mountuje do
# /etc/supervisor/conf.d.
FROM runtime AS supervisor
RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
&& rm -rf /var/lib/apt/lists/*
COPY supervisord.conf /etc/supervisor/supervisord.conf
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]


# Varianta -composer: runtime (s VARIANT=cli) + composer. Jeden image pro vsechny projekty -
# rozsireni projektu v nem nejsou, proto composer ignoruje ext-* pozadavky
# (COMPOSER_IGNORE_PLATFORM_REQ nize). Ze ma runtime vsechna potrebna rozsireni, zarucuje
# install-composer-extensions v Dockerfile projektu (composer.lock) - viz README.
FROM runtime AS composer

# git kvuli VCS repozitarum v composer.json a verzi root balicku, unzip pro rozbalovani archivu.
# Konkretni verze a digest - plovouci 2-bin by kazdy build dal jiny composer (a kompromitovany tag ho podstrcil)
COPY --from=composer/composer:2.11.2-bin@sha256:269d47ef954e254227bccb35e3e40f642756abee44dded405e1fbb6f5e52af0c /composer /usr/local/bin/composer
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
