# Run `just` with no arguments to list recipes. Most recipes shell out to
# Docker so contributors without ext-mbstring/ext-xml/ext-dom/ext-xmlwriter
# on their host PHP can still run the toolchain.

image := "laravel-loom-dev:latest"
docker_run := "docker run --rm -v $(pwd):/app " + image

# Default recipe: list every available task.
default:
    @just --list

# Build the Docker development image.
build:
    docker build -t {{image}} .

# Install composer dependencies inside the container.
install:
    {{docker_run}} composer install --no-progress

# Run the full check sequence: PHPStan + Pint --test + Pest.
check: phpstan pint-check test

# Run PHPStan at level 8.
phpstan:
    {{docker_run}} vendor/bin/phpstan analyse --memory-limit=512M

# Apply Pint formatting.
pint:
    {{docker_run}} vendor/bin/pint

# Verify Pint formatting without applying it.
pint-check:
    {{docker_run}} vendor/bin/pint --test

# Run the Pest test suite.
test *args:
    {{docker_run}} vendor/bin/pest {{args}}

# Run Pest with coverage, printing per-file percentages.
coverage:
    {{docker_run}} vendor/bin/pest --coverage --min=0

# Run a single test file or filter (e.g. `just test-filter EventScanner`).
test-filter filter:
    {{docker_run}} vendor/bin/pest --filter={{filter}}

# Open an interactive shell in the container.
shell:
    docker run --rm -it -v $(pwd):/app {{image}} sh

# Serve the docs site locally with live reload at http://localhost:8000.
docs-serve:
    docker run --rm -it -p 8000:8000 -v $(pwd):/docs squidfunk/mkdocs-material serve --dev-addr 0.0.0.0:8000

# Build the docs site into ./site (strict: fails on broken links / nav).
docs-build:
    docker run --rm -v $(pwd):/docs squidfunk/mkdocs-material build --strict

# Publish the docs to the gh-pages branch. Builds in Docker, stages the branch
# locally, then pushes with your own git credentials — no GitHub Actions, no
# CI minutes. Run it whenever you want the published site refreshed.
docs-deploy:
    docker run --rm -v $(pwd):/docs squidfunk/mkdocs-material build --strict
    docker run --rm -v $(pwd):/docs --entrypoint ghp-import squidfunk/mkdocs-material -n -f -m "docs: deploy site" -b gh-pages site
    git push --force origin gh-pages

# Scan an arbitrary Laravel app and print stats. Pass an absolute path.
#   just scan /path/to/laravel/app
scan target:
    docker run --rm \
        -v $(pwd):/app \
        -v {{target}}:/target:ro \
        {{image}} \
        php -r "require '/app/vendor/autoload.php'; \
            \$b = new Lucasp\Loom\Index\IndexBuilder(); \
            \$b->register(new Lucasp\Loom\Scanners\EventScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\ListenerScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\ObserverScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\DispatchScanner()); \
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
    @docker run --rm \
        -v $(pwd):/app \
        -v {{target}}:/target:ro \
        {{image}} \
        php -r "require '/app/vendor/autoload.php'; \
            \$b = new Lucasp\Loom\Index\IndexBuilder(); \
            \$b->register(new Lucasp\Loom\Scanners\EventScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\ListenerScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\ObserverScanner()); \
            \$b->register(new Lucasp\Loom\Scanners\DispatchScanner()); \
            echo json_encode(\$b->build('/target', 'unknown')->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);"
