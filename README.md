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
