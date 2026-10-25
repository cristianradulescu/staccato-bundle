#!/usr/bin/env bash
#
# Creates a new Symfony application with Staccato installed, sample content
# and a first build, ready to write a blog in.
#
# Usage: create-blog.sh [options] <directory>
# Run with --help for the list of options.

set -euo pipefail

title='My Blog'
base_url='http://localhost'
bundle_path=''
build=1
directory=''

usage() {
    cat <<'EOF'
Usage: create-blog.sh [options] <directory>

Creates a Symfony application with Staccato in <directory>, adds sample
content and builds the site into <directory>/build.

Options:
  --title <title>        Site title (default: "My Blog")
  --base-url <url>       Public URL of the site, used for RSS, sitemap and
                         canonical links (default: http://localhost)
  --bundle-path <path>   Install the bundle from a local checkout instead of
                         Packagist
  --no-build             Skip the initial site:build
  -h, --help             Show this help
EOF
}

fail() {
    echo "Error: $*" >&2
    exit 1
}

step() {
    printf '\n\033[1;32m==>\033[0m %s\n' "$*"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --title) [[ $# -ge 2 ]] || fail "--title needs a value"; title="$2"; shift 2 ;;
        --base-url) [[ $# -ge 2 ]] || fail "--base-url needs a value"; base_url="$2"; shift 2 ;;
        --bundle-path) [[ $# -ge 2 ]] || fail "--bundle-path needs a value"; bundle_path="$2"; shift 2 ;;
        --no-build) build=0; shift ;;
        -h|--help) usage; exit 0 ;;
        -*) fail "unknown option $1 (see --help)" ;;
        *) [[ -z "$directory" ]] || fail "only one directory can be given"; directory="$1"; shift ;;
    esac
done

[[ -n "$directory" ]] || { usage >&2; exit 1; }
[[ ! -e "$directory" ]] || fail "$directory already exists"

if [[ -n "$bundle_path" ]]; then
    [[ -f "$bundle_path/composer.json" ]] || fail "$bundle_path is not a Staccato checkout (no composer.json)"
    bundle_path="$(cd "$bundle_path" && pwd)"
fi

step "Checking requirements"
command -v php >/dev/null || fail "PHP is not installed (8.5 or newer is required)"
command -v composer >/dev/null || fail "Composer is not installed, see https://getcomposer.org/download/"
php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.5 or newer is required, found $(php -r 'echo PHP_VERSION;')"
for extension in ctype iconv; do
    php -r "exit(extension_loaded('$extension') ? 0 : 1);" || fail "the PHP $extension extension is required"
done

step "Creating a Symfony application in $directory"
composer create-project symfony/skeleton "$directory" '^8.1' --no-interaction
cd "$directory"
# The skeleton may allow an older PHP than the bundle needs; the next require updates the lock file.
composer require 'php:>=8.5' --no-update --no-interaction

step "Installing Staccato"
if [[ -n "$bundle_path" ]]; then
    composer config repositories.staccato "{\"type\": \"path\", \"url\": \"$bundle_path\"}"
    composer require 'cristianradulescu/staccato-bundle:*@dev' --no-interaction
else
    composer require cristianradulescu/staccato-bundle --no-interaction
fi

# Flex registers bundles on its own; this covers setups where it did not.
for bundle in 'Symfony\Bundle\TwigBundle\TwigBundle' 'Staccato\StaccatoBundle'; do
    if ! grep -qF "$bundle::class" config/bundles.php; then
        php -r '$file = "config/bundles.php"; file_put_contents($file, preg_replace("/^\];/m", "    $argv[1]::class => [\x27all\x27 => true],\n];", file_get_contents($file)));' "$bundle"
    fi
done

step "Removing the web front controller"
# A static site needs only the console: no front controller, controllers or public/ assets.
rm -rf public src/Controller templates/base.html.twig
php -r '$file = "composer.json"; $json = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR); unset($json["scripts"]["auto-scripts"]["assets:install %PUBLIC_DIR%"]); file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");'
composer update --lock --no-interaction --quiet

step "Writing configuration and sample content"
# Single-quoted YAML scalars only need their quotes doubled.
yaml_quote() {
    printf "'%s'" "${1//\'/\'\'}"
}

cat > config/packages/staccato.yaml <<EOF
staccato:
    title: $(yaml_quote "$title")
    base_url: $(yaml_quote "$base_url")
    description: $(yaml_quote "$title, built with Staccato.")
EOF

mkdir -p content/articles assets static

cat > content/articles/hello-world.md <<EOF
---
title: Hello, world
date: $(date +%Y-%m-%d)
---
This is the first article of your new blog. Every Markdown file in
\`content/articles/\` shows up on the homepage, newest first.

Code blocks are highlighted:

\`\`\`php
echo 'Hello, world!';
\`\`\`
EOF

cat > content/articles/draft-ideas.md <<EOF
---
title: Ideas for future articles
date: $(date +%Y-%m-%d)
draft: true
---
Drafts are included when building in the \`dev\` environment and left out
with \`--env=prod\`.
EOF

cat > content/about.md <<'EOF'
---
title: About
---
Pages outside `content/articles/` are published at their own URL
(this one at `/about/`) but are not listed on the homepage.
EOF

printf '\n/build/\n' >> .gitignore

mkdir -p .github/workflows
cat > .github/workflows/pages.yml <<'EOF'
# Builds the site and deploys it to GitHub Pages on every push to main.
# Enable it under Settings > Pages > Build and deployment > Source: GitHub Actions,
# and set staccato.base_url to the Pages URL of the repository.
name: Deploy to GitHub Pages

on:
  push:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: read
  pages: write
  id-token: write

concurrency:
  group: pages
  cancel-in-progress: true

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    env:
      APP_ENV: prod
    steps:
      - uses: actions/checkout@v5
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.5'
          tools: composer
      - run: composer install --no-dev --no-interaction --prefer-dist
      - run: php bin/console site:build --env=prod
      - uses: actions/configure-pages@v5
      - uses: actions/upload-pages-artifact@v4
        with:
          path: build
      - id: deployment
        uses: actions/deploy-pages@v4
EOF

if [[ "$build" -eq 1 ]]; then
    step "Building the site"
    php bin/console site:build --env=prod
fi

step "Done"
cat <<EOF
Your blog lives in $directory:

  content/articles/   your articles (Markdown with front matter)
  content/about.md    a standalone page
  assets/             files published under /assets/ (images, downloads)
  static/             files published at the site root (favicon.ico, robots.txt)
  config/packages/staccato.yaml
  .github/workflows/pages.yml   GitHub Pages deployment (delete it if you host elsewhere)

Rebuild after editing content:

  cd $directory
  php bin/console site:build --env=prod

Preview the result:

  php -S localhost:8000 -t build
EOF
