#!/usr/bin/env bash

#
# SmartSearch test runner.
#
# Runs unit tests, functional tests, phpstan, and cgl inside a Docker container
# using the same PHP images as the TYPO3 Core CI.
#
# Usage:
#   Build/Scripts/runTests.sh                  # Run unit tests (default)
#   Build/Scripts/runTests.sh -s unit          # Run unit tests
#   Build/Scripts/runTests.sh -s phpstan       # Run static analysis
#   Build/Scripts/runTests.sh -s cgl           # Run coding standards check
#   Build/Scripts/runTests.sh -s cgl -fix      # Run coding standards check and fix them automatically
#   Build/Scripts/runTests.sh -x               # Enable Xdebug
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Defaults
PHP_VERSION="8.4"
TEST_SUITE="unit"
EXTRA_ARGS=""
XDEBUG=""
CI=${CI:-false}

# Image base — matches TYPO3 Core CI images
IMAGE_PREFIX="ghcr.io/typo3/core-testing-php"
IMAGE_TAG="latest"

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -s <suite>    Test suite: unit (default), functional, phpstan, cgl, lint
    -p <version>  PHP version: 8.4 (default)
    -x            Enable Xdebug
    -h            Show this help

Examples:
    $(basename "$0")                           Run unit tests
    $(basename "$0") -s phpstan                Run PHPStan
    $(basename "$0") -- --filter BudgetService Run specific test
EOF
    exit 0
}

while getopts "s:p:xh" opt; do
    case ${opt} in
        s) TEST_SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        x) XDEBUG="-e XDEBUG_MODE=debug -e XDEBUG_CONFIG=client_host=host.docker.internal" ;;
        h) usage ;;
        *) usage ;;
    esac
done
shift $((OPTIND - 1))
EXTRA_ARGS="$*"

PHP_IMAGE="${IMAGE_PREFIX}$(echo "${PHP_VERSION}" | tr -d '.'):${IMAGE_TAG}"

# Ensure .Build/vendor exists (composer install)
if [ ! -d "${ROOT_DIR}/.Build/vendor" ]; then
    echo "Running composer install..."
    docker run --rm \
        -v "${ROOT_DIR}:/app" \
        -w /app \
        "${PHP_IMAGE}" \
        composer install --no-progress --no-interaction --no-scripts 2>&1
fi

EXIT_CODE=0

case ${TEST_SUITE} in
    unit)
        echo "Running unit tests with PHP ${PHP_VERSION}..."
        set +e
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            ${XDEBUG} \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/phpunit -c Build/phpunit/UnitTests.xml ${EXTRA_ARGS}
        EXIT_CODE=$?
        set -e
        ;;
    phpstan)
        echo "Running PHPStan with PHP ${PHP_VERSION}..."
        set +e
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
        EXIT_CODE=$?
        set -e
        ;;
    cgl)
        echo "Running coding standards check..."
        set +e
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/php-cs-fixer fix --dry-run --diff
        EXIT_CODE=$?
        set -e
        ;;
    cgl-fix)
        echo "Running coding standards check..."
        set +e
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/php-cs-fixer fix --diff
        EXIT_CODE=$?
        set -e
        ;;
    lint)
        echo "Linting PHP files..."
        set +e
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            bash -c 'find Classes Tests -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null'
        EXIT_CODE=$?
        set -e
        ;;
    *)
        echo "Unknown suite: ${TEST_SUITE}"
        usage
        ;;
esac

echo ""
if [ ${EXIT_CODE} -eq 0 ]; then
    echo "✓ ${TEST_SUITE} passed"
else
    echo "✗ ${TEST_SUITE} failed (exit ${EXIT_CODE})"
fi
exit ${EXIT_CODE}