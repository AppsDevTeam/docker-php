# Spolecny zaklad PHP pro webove projekty, aby se bezpecnostni opravy nemusely zanaset do
# kazdeho repozitare zvlast. Dve varianty ze stejneho Dockerfile:
# - appsdevteam/php:<verze>-<revize>-fpm - web (php-fpm)
# - appsdevteam/php:<verze>-<revize>-cli - consumery a dalsi procesy bez webu (supervisor
#   apod.), bez php-fpm
#
# Verze PHP a varianta se predavaji z workflow (.github/workflows/base.yml), verze je
# pripnuta na patch kvuli reprodukovatelnosti. Lokalne:
#   docker build --build-arg PHP_VERSION=8.5.12 --build-arg VARIANT=cli base
#
# Co sem NEPATRI: konfigurace v /usr/local/etc/php/conf.d a /usr/local/etc/php-fpm.d.
# Projekty oba adresare v docker-compose.yml prekryvaji mountem, takze cokoli, co by sem
# image zapsal, v kontejneru stejne zmizi. Pool (vc. pm.status_path) a ini si dal drzi projekt.
# Vychozi ini hodnoty image jdou do /usr/local/etc/php/php.ini (viz php.ini vedle) - ten
# mount neprekryva a projekt je muze ve svem conf.d prepsat.
ARG PHP_VERSION
ARG VARIANT=fpm
FROM php:${PHP_VERSION}-${VARIANT}

# Runtime nastroje: git kvuli composeru (VCS repozitare v composer.json), unzip pro archivy.
# dma (/usr/sbin/sendmail) doruci e-mail z mail() primo na MX prijemce, bez API a klice. Je jen
# na systemovou postu - chyby z adt/error-logger na error@mail.appsdevteam.com. Bezna posta
# projektu jde pres adt/mailer (mailapi), na cizi schranky by prima posta neprosla antispamem.
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
	git unzip dma \
&& rm -rf /var/lib/apt/lists/*

# Projekty spousti PHP s read_only, no-new-privileges a neroot uzivatelem. Setgid mail se pak
# neuplatni, takze dma musi precist config bez nej (nic tajneho v nem neni, prihlasovaci udaje
# jsou v auth.conf) a spool musi lezet na zapisovatelnem /tmp (tmpfs). Fronta nepreziva
# restart kontejneru.
RUN sed -i 's|^#\?SPOOLDIR.*|SPOOLDIR /tmp|' /etc/dma/dma.conf \
&& grep -qx 'SPOOLDIR /tmp' /etc/dma/dma.conf \
&& chmod 0644 /etc/dma/dma.conf

# Composer installer se overuje proti oficialnimu podpisu (obrana proti MITM / podvrzeni installeru)
RUN curl --fail -sSL https://getcomposer.org/installer -o composer-setup.php \
&& EXPECTED="$(curl --fail -sSL https://composer.github.io/installer.sig)" \
&& ACTUAL="$(sha384sum composer-setup.php | cut -d' ' -f1)" \
&& [ "$EXPECTED" = "$ACTUAL" ] \
&& php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
&& rm composer-setup.php

# Helper pro otevirani souboru v editoru na hostovi (lokalni vyvoj). Netcat, ktery potrebuje,
# si doinstaluje projekt jen pro local (ARG DEV) - do produkcniho image nepatri.
RUN curl --fail -sOL https://git.appsdevteam.com/adt/docker-utils/raw/v1.0/bin/eoh && chmod +x eoh && mv eoh /usr/local/bin/eoh

# Reset opcache PHP-FPM po deployi (`cachetool opcache:reset` v makefile projektu).
RUN curl --fail -sOL https://gordalina.github.io/cachetool/downloads/cachetool-9.2.1.phar && chmod +x cachetool-9.2.1.phar && mv cachetool-9.2.1.phar /usr/local/bin/cachetool

# Vychozi ini hodnoty (velikosti bufferu opcache) - viz komentar v souboru.
COPY php.ini /usr/local/etc/php/php.ini

# Stav opcache pro monitoring (monitoring-agent_phpfpm v server-management-scripts ho vola
# pres FastCGI :9000). Mimo document root - nginx ho neserviruje.
COPY opcache-status.php /usr/local/lib/monitoring/opcache-status.php

# Spolecne minimum rozsireni. Projekt si dalsi doinstaluje sam: `RUN install-php-extensions gd`.
# Skutecny mlocati skript je v /usr/local/lib, v PATH je wrapper (viz install-php-extensions
# vedle), ktery kolem nej doinstaluje a zase odstrani $PHPIZE_DEPS.
RUN curl --fail -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/lib/install-php-extensions \
&& chmod +x /usr/local/lib/install-php-extensions \
&& /usr/local/lib/install-php-extensions intl opcache pdo_mysql sockets sodium zip
# spustitelny je uz v gitu - COPY prava zachova (--chmod by vyzadoval BuildKit)
COPY install-php-extensions /usr/local/bin/install-php-extensions

# Alternativa k rucnimu vyjmenovani rozsireni: precte ext-* z composer.json projektu
# a nainstaluje je - viz komentar ve skriptu.
COPY install-composer-extensions /usr/local/bin/install-composer-extensions

# $PHPIZE_DEPS nechava base image nainstalovane pro potomky, kteri kompiluji extensions.
# Wrapper si je v potomkovi doinstaluje sam, takze je tu odstranime: jinak v image zbyva
# gcc/make (pouzitelne po pripadnem pruniku) a hlavicky linux-libc-dev, ktere generuji CVE
# hlaseni proti kernelu, jenz v kontejneru vubec nebezi (dodava ho host).
# libc6-dev explicitne - v $PHPIZE_DEPS je jen virtualni nazev libc-dev, na ktery apt nesedne.
# linux-libc-dev explicitne - install-php-extensions ho oznaci jako manual, takze by ho
# --auto-remove nechal v image, i kdyz na nem uz nic nezavisi.
RUN apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev linux-libc-dev \
&& rm -rf /var/lib/apt/lists/*

# Bezpecnostni aktualizace VSECH balicku. Tag base image je pripnuty, ale Debian vydava
# opravy prubezne a upstream php image se rebuilduje pomaleji - workflow proto image
# rebuilduje i tydne. MUSI byt az za install-php-extensions, jinak by se netykalo knihoven
# rozsireni. `upgrade` misto `dist-upgrade` - nikdy nepridava nove balicky, takze nezvrati
# purge vyse.
RUN apt-get update && apt-get upgrade -y \
&& rm -rf /var/lib/apt/lists/*
