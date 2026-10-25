#!/usr/bin/env sh
# Runs the whole suite on every supported PHP line, each in a clean copy of the package:
#   tests/docker/run.sh            all versions
#   tests/docker/run.sh 7.4        one version
# Extra arguments after the versions go to PHPUnit (e.g. -- --testsuite unit).
set -e
cd "$(dirname "$0")"
root="$(cd ../.. && pwd)"

versions=""
while [ $# -gt 0 ] && [ "$1" != "--" ]; do versions="$versions $1"; shift; done
[ "$1" = "--" ] && shift
[ -n "$versions" ] || versions="7.4 8.1 8.4"

for version in $versions; do
    docker build -q --build-arg PHP_VERSION="$version" -t "dockaccess-altgen-php:$version" . >/dev/null
    echo "=== PHP $version"
    docker run --rm -e DOCKACCESS_ALT_NETWORK_TESTS -v "$root":/src:ro "dockaccess-altgen-php:$version" sh -c '
        set -e
        tar -C /src --exclude=./vendor --exclude=./composer.lock --exclude=./build -cf - . | tar -C /app -xf -
        composer install -q --no-progress --no-interaction
        vendor/bin/phpunit "$@"
    ' phpunit "$@"
done
