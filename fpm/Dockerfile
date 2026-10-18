# Spolecny zaklad php-fpm pro webove projekty (appsdevteam/php:<verze>-fpm). Drive si kazdy
# projekt skladal stejny Dockerfile sam (vychozi byl sandbox) a bezpecnostni opravy se musely
# rucne zanaset do kazdeho repozitare zvlast.
#
# Verze PHP se predava z workflow (.github/workflows/fpm.yml), kde je pripnuta na patch -
# stejne jako drive v projektech, kvuli reprodukovatelnosti. Lokalne:
#   docker build --build-arg PHP_VERSION=8.5.11 fpm
#
# Co sem NEPATRI: konfigurace v /usr/local/etc/php/conf.d a /usr/local/etc/php-fpm.d.
# Projekty oba adresare v docker-compose.yml prekryvaji mountem, takze cokoli, co by sem
# image zapsal, v kontejneru stejne zmizi. Pool (vc. pm.status_path) a ini si dal drzi projekt.
ARG PHP_VERSION
FROM php:${PHP_VERSION}-fpm

# Runtime nastroje: git kvuli composeru (VCS repozitare v composer.json), unzip pro archivy.
RUN apt-get update && apt-get install -y --no-install-recommends \
	git unzip \
&& rm -rf /var/lib/apt/lists/*

# Composer installer se overuje proti oficialnimu podpisu (obrana proti MITM / podvrzeni installeru)
RUN curl --fail -sSL https://getcomposer.org/installer -o composer-setup.php \
&& EXPECTED="$(curl --fail -sSL https://composer.github.io/installer.sig)" \
&& ACTUAL="$(sha384sum composer-setup.php | cut -d' ' -f1)" \
&& [ "$EXPECTED" = "$ACTUAL" ] \
&& php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
&& rm composer-setup.php

# v3.0 spousti workera ze sebe sama a nema zapeceny klic - `--key` v sendmail_path je
# povinny, nastavuje ho projekt v conf.d. Image je verejny na Docker Hubu: zadne klice sem.
RUN curl --fail -sOL https://git.appsdevteam.com/adt/sendmail/raw/v3.0/bin/sendmail && chmod +x sendmail && mv sendmail /usr/local/bin/sendmail

# Helper pro otevirani souboru v editoru na hostovi (lokalni vyvoj). Netcat, ktery potrebuje,
# si doinstaluje projekt jen pro local (ARG DEV) - do produkcniho image nepatri.
RUN curl --fail -sOL https://git.appsdevteam.com/adt/docker-utils/raw/v1.0/bin/eoh && chmod +x eoh && mv eoh /usr/local/bin/eoh

# Reset opcache PHP-FPM po deployi (`cachetool opcache:reset` v makefile projektu).
RUN curl --fail -sOL https://gordalina.github.io/cachetool/downloads/cachetool-9.2.1.phar && chmod +x cachetool-9.2.1.phar && mv cachetool-9.2.1.phar /usr/local/bin/cachetool

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
#
# V projektech tenhle krok padal na GitLab runneru #33 (hodiny pozadu, apt odmita podpis
# debian-security "z budoucnosti") - image se ale buildi v GitHub Actions, kde to neplati.
RUN apt-get update && apt-get upgrade -y \
&& rm -rf /var/lib/apt/lists/*
