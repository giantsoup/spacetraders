#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd -P)"
php_version="${PHP_SOURCE_VERSION:?PHP_SOURCE_VERSION is required}"
php_sha="${PHP_SOURCE_SHA256:?PHP_SOURCE_SHA256 is required}"
if [[ "$php_version" != "$(cat "$root/.php-version")" ]]; then
  echo 'PHP source version must match .php-version' >&2
  exit 1
fi
if [[ ! "$php_sha" =~ ^[[:xdigit:]]{64}$ ]]; then
  echo 'PHP_SOURCE_SHA256 must be a SHA-256 digest' >&2
  exit 1
fi

verify_archive() {
  local actual
  if command -v sha256sum >/dev/null 2>&1; then
    actual="$(sha256sum "$1")"
  else
    actual="$(shasum -a 256 "$1")"
  fi
  actual="${actual%% *}"
  if [[ "$actual" != "$php_sha" ]]; then
    echo 'PHP source checksum mismatch' >&2
    return 1
  fi
  echo 'PASS PHP source checksum'
}

if [[ "${1:-}" == --verify-only && "$#" == 2 ]]; then
  verify_archive "$2"
  exit 0
fi
if [[ "$#" != 0 ]]; then
  echo "Usage: bash $0 [--verify-only archive]" >&2
  exit 1
fi
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo 'Building PHP is restricted to GitHub Actions runners' >&2
  exit 1
fi
: "${RUNNER_TEMP:?}" "${RUNNER_OS:?}" "${GITHUB_ENV:?}" "${GITHUB_PATH:?}"
runner_temp="$(cd "$RUNNER_TEMP" && pwd -P)"
build_dir="$(mktemp -d "$runner_temp/php-cli-build.XXXXXX")"
prefix="$build_dir/prefix"
archive="$build_dir/php.tar.xz"
mkdir -p "$prefix/bin" "$prefix/etc/conf.d"

curl --fail --location --proto '=https' --proto-redir '=https' \
  --retry 3 --retry-all-errors --connect-timeout 30 --max-time 300 \
  "https://www.php.net/distributions/php-$php_version.tar.xz" -o "$archive"
verify_archive "$archive"

platform_args=(--with-iconv)
case "$RUNNER_OS" in
  Linux)
    sudo apt-get update
    sudo apt-get install -y --no-install-recommends \
      build-essential pkg-config ca-certificates xz-utils libxml2-dev \
      libsqlite3-dev libssl-dev libcurl4-openssl-dev zlib1g-dev libonig-dev
    build_jobs="$(getconf _NPROCESSORS_ONLN)"
    ;;
  macOS)
    export HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_INSTALL_CLEANUP=1
    export HOMEBREW_NO_INSTALL_UPGRADE=1 HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1
    if ! command -v pkg-config >/dev/null 2>&1; then
      brew install pkgconf
    fi
    brew install libxml2 sqlite openssl@3 curl oniguruma
    pkg_paths="$(brew --prefix openssl@3)/lib/pkgconfig"
    for dependency in curl libxml2 sqlite oniguruma; do
      pkg_paths="$pkg_paths:$(brew --prefix "$dependency")/lib/pkgconfig"
    done
    export PKG_CONFIG_PATH="$pkg_paths${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
    sdk="$(xcrun --show-sdk-path)"
    test -r "$sdk/usr/include/iconv.h"
    test -r "$sdk/usr/lib/libiconv.tbd"
    platform_args=("--with-iconv=$sdk/usr")
    export ZLIB_CFLAGS="-I$sdk/usr/include" ZLIB_LIBS="-L$sdk/usr/lib -lz"
    build_jobs="$(sysctl -n hw.logicalcpu)"
    ;;
  *) echo "Unsupported runner: $RUNNER_OS" >&2; exit 1 ;;
esac

tar -xJf "$archive" -C "$build_dir"
cd "$build_dir/php-$php_version"
./configure \
  "--prefix=$prefix" \
  "--with-config-file-path=$prefix/etc" \
  "--with-config-file-scan-dir=$prefix/etc/conf.d" \
  --disable-all --enable-cli --disable-cgi --disable-phpdbg \
  --without-pear --disable-opcache-jit \
  --with-libxml --enable-dom --enable-simplexml \
  --enable-xml --enable-xmlreader --enable-xmlwriter \
  --enable-ctype --enable-filter --enable-fileinfo \
  --enable-session --enable-tokenizer --enable-posix --enable-pcntl \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 \
  --enable-mysqlnd --with-pdo-mysql=mysqlnd \
  --enable-mbstring --enable-mbregex --enable-phar \
  --with-openssl --with-curl --with-zlib "${platform_args[@]}"
make -j"$build_jobs" sapi/cli/php
cp sapi/cli/php "$prefix/bin/php"
cp php.ini-production "$prefix/etc/php.ini"
printf '\ndate.timezone=UTC\nmemory_limit=-1\nopcache.enable_cli=0\n' >> "$prefix/etc/php.ini"
printf '%s\n' "$prefix/bin" >> "$GITHUB_PATH"
printf 'PHP_BIN=%s\nPHPRC=%s\nPHP_INI_SCAN_DIR=%s\nPHP_SOURCE_ARCHIVE=%s\n' \
  "$prefix/bin/php" "$prefix/etc" "$prefix/etc/conf.d" "$archive" >> "$GITHUB_ENV"
