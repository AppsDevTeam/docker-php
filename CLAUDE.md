# docker-php

Lokalni `docker build` sestavi image jen pro architekturu stroje (na Macu tedy jen
arm64), a proto je rychly. Skutecny multiplatformni build (`linux/amd64` +
`linux/arm64`, tak jak ho dela `.github/workflows/fpm.yml`) vyzaduje
`docker buildx build --platform linux/amd64,linux/arm64 ... --push` - multi-arch
manifest nejde ulozit lokalne, musi se rovnou pushnout do registry. Build pro amd64
na Apple Silicon bezi pres QEMU emulaci, je proto radove pomalejsi (klidne desitky
minut) nez nativni arm64 build.

Presny prikaz viz README.md.
