# Run `just` with no arguments to list recipes. Most recipes shell out to
# Docker so contributors without ext-mbstring/ext-xml/ext-dom/ext-xmlwriter
# on their host PHP can still run the toolchain.

image := "laravel-loom-dev:latest"
# Containers run as the host user so nothing they write in the repo is root-owned.
as_host := "--user $(id -u):$(id -g) -e HOME=/tmp"
docker_run := "docker run --rm " + as_host + " -v $(pwd):/app " + image
# Zensical in a throwaway venv under /tmp, writable by the host user.
zensical := "python -m venv /tmp/venv && /tmp/venv/bin/pip install -q zensical==0.0.69 && /tmp/venv/bin/zensical"
# Images for `just demo-gif`; the Playwright image tag matches the pip package it installs.
playwright := "mcr.microsoft.com/playwright/python:v1.48.0-jammy"
ffmpeg := "mwader/static-ffmpeg:7.1"
docs_serve := "-p 8000:8000 " + as_host + " -v $(pwd):/docs -w /docs python:3.13-slim sh -c \"" + zensical + " serve --dev-addr 0.0.0.0:8000\""

# Default recipe: list every available task.
default:
    @just --list

# Build the Docker development image.
build:
    docker build -t {{image}} .

# Install composer dependencies inside the container.
install:
    {{docker_run}} composer install --no-progress

# The full gate, fail-fast: pint, phpstan, rector, pest, bench, generated docs, strict docs build.
verify: pint-check phpstan rector test (bench "--assert") docs-check docs-build

# Apply every automatic rewrite: rector first, then pint, then regenerate docs.
fix: rector-fix pint docs-generate

# Run PHPStan (level 8).
phpstan:
    {{docker_run}} vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# Rector dry run; fails on a diff.
rector:
    {{docker_run}} vendor/bin/rector process --dry-run --ansi

# Apply Rector rewrites (run pint after).
rector-fix:
    {{docker_run}} vendor/bin/rector process --ansi

# Apply Pint formatting.
pint:
    {{docker_run}} vendor/bin/pint

# Verify Pint formatting without applying it.
pint-check:
    {{docker_run}} vendor/bin/pint --test

# Run the Pest suite (extra args pass through).
test *args:
    {{docker_run}} vendor/bin/pest {{args}}

# Run Pest with coverage, printing per-file percentages.
coverage:
    {{docker_run}} vendor/bin/pest --coverage --min=0

# Run Pest with a name filter (e.g. `just test-filter EventScanner`).
test-filter filter:
    {{docker_run}} vendor/bin/pest --filter={{filter}}

# Run the benchmark suite (e.g. `just bench --json`, `just bench --assert`).
bench *args:
    {{docker_run}} php benchmarks/bench.php {{args}}

# Rewrite benchmarks/baseline.json after a legitimate count change.
bench-baseline:
    {{docker_run}} php benchmarks/bench.php --baseline

# Regenerate tests/Fixtures/mcp-golden.json; review the diff.
golden:
    docker run --rm {{as_host}} -e LOOM_UPDATE_GOLDEN=1 -v $(pwd):/app {{image}} vendor/bin/pest tests/Feature/Mcp/CharacterizationTest.php

# Open an interactive shell in the container.
shell:
    docker run --rm -it {{as_host}} -v $(pwd):/app {{image}} sh

# Regenerate the generated regions of the docs (between <!-- generated:NAME --> markers).
docs-generate:
    {{docker_run}} php tools/generate-reference.php

# Fail, writing nothing, when a generated region differs from its source.
docs-check:
    {{docker_run}} php tools/generate-reference.php --check

# Serve the docs site locally with live reload at http://localhost:8000.
docs-serve: docs-generate
    docker run --rm -it {{docs_serve}}

# Serve the docs in the background as container `loom-docs`; stop with `just docs-stop`.
docs-serve-bg: docs-generate
    docker run --rm -d --name loom-docs {{docs_serve}}

# Stop the background docs server.
docs-stop:
    docker stop loom-docs

# Strict site build from a scratch copy of docs/, overrides/, mkdocs.yml; the repo is mounted read-only.
docs-build: docs-check
    docker run --rm {{as_host}} -v $(pwd):/src:ro python:3.13-slim sh -c "mkdir /tmp/work && cp -r /src/docs /src/overrides /src/mkdocs.yml /tmp/work/ && cd /tmp/work && {{zensical}} build --clean --strict"

# Record docs/assets/demo.gif: headless Chromium clicks through the UI over the docs fixture app. Not run in CI.
demo-gif:
    #!/usr/bin/env bash
    set -euo pipefail
    frames=$(mktemp -d)
    trap 'docker stop loom-demo >/dev/null 2>&1 || true; rm -rf "$frames"' EXIT
    docker run --rm -d --name loom-demo {{as_host}} -v $(pwd):/app {{image}} php -S 0.0.0.0:8080 tools/demo-server.php >/dev/null
    docker run --rm --network container:loom-demo {{as_host}} -v $(pwd)/tools/Demo:/demo:ro -v "$frames":/out {{playwright}} \
        sh -c "python -m pip install -q --user --no-warn-script-location --disable-pip-version-check playwright==1.48.0 && python /demo/record.py http://127.0.0.1:8080 /out"
    docker run --rm {{as_host}} -v "$frames":/frames -w /frames {{ffmpeg}} -v error -y -f concat -i frames.txt \
        -vf "fps=10,split[a][b];[a]palettegen=max_colors=64:stats_mode=diff[p];[b][p]paletteuse=dither=none:diff_mode=rectangle" -loop 0 demo.gif
    mkdir -p docs/assets
    cp "$frames/demo.gif" docs/assets/demo.gif
    echo "docs/assets/demo.gif: $(du -k docs/assets/demo.gif | cut -f1) KB (budget enforced by tests/Docs/DemoAssetsTest.php)"

# Scan an arbitrary Laravel app and print stats. Pass an absolute path.
#   just scan /path/to/laravel/app
scan target:
    docker run --rm {{as_host}} \
        -v $(pwd):/app \
        -v {{target}}:/target:ro \
        {{image}} \
        php -r "require '/app/vendor/autoload.php'; \
            \$b = new Lucasp\Loom\Index\IndexBuilder(); \
            Lucasp\Loom\Scanners\DefaultScanners::registerOn(\$b); \
            \$start = microtime(true); \
            \$index = \$b->build('/target', 'unknown'); \
            \$ms = number_format((microtime(true) - \$start) * 1000, 0); \
            \$payload = \$index->toArray(); \
            \$errors = \$b->validate(\$payload); \
            echo 'scan: ' . \$ms . 'ms' . PHP_EOL; \
            echo 'validation: ' . (empty(\$errors) ? 'PASS' : 'FAIL') . PHP_EOL; \
            echo 'stats: ' . json_encode(\$payload['stats']) . PHP_EOL;"

# Scan an arbitrary Laravel app and write the full index to stdout.
#   just scan-json /path/to/laravel/app > index.json
scan-json target:
    @docker run --rm {{as_host}} \
        -v $(pwd):/app \
        -v {{target}}:/target:ro \
        {{image}} \
        php -r "require '/app/vendor/autoload.php'; \
            \$b = new Lucasp\Loom\Index\IndexBuilder(); \
            Lucasp\Loom\Scanners\DefaultScanners::registerOn(\$b); \
            echo json_encode(\$b->build('/target', 'unknown')->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);"
