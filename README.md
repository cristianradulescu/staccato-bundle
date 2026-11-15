# Staccato

[![CI](https://github.com/cristianradulescu/staccato-bundle/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/cristianradulescu/staccato-bundle/actions/workflows/ci.yml)

Staccato is a Symfony bundle that turns Markdown content into a static site: pages, a paginated homepage, an RSS feed, and a sitemap, all rendered with Twig.

This repository is the **engine** — a reusable `cristianradulescu/staccato-bundle` package. It ships default templates and styling so you can focus on writing content instead of building a layout; it is meant to be required into a Symfony application (or a dedicated Staccato project skeleton, once published) that supplies content.

## Requirements

- PHP 8.5+ with the `dom` extension (the `php8.5-xml` package on Debian/Ubuntu)
- A Symfony 8.1+ application with `bin/console` to install the bundle into (e.g. one created from `symfony/skeleton`)

`symfony/twig-bundle` is installed as a dependency of the bundle: it renders the default templates and makes them overridable (see "Customizing templates" below).

## Quick start: a new blog

`bin/create-blog.sh` creates a Symfony application with Staccato installed, a sample article (plus a draft) and an About page, then builds it:

```bash
bin/create-blog.sh --title "My Blog" --base-url https://blog.example.com my-blog
cd my-blog
php -S localhost:8000 -t build
```

It checks for PHP 8.5+ and Composer first. The generated app requires PHP 8.5+, has no web front controller (`public/`, `src/Controller/` and the skeleton's `templates/base.html.twig` are removed, since a static site only needs the console), and comes with empty `assets/` and `static/` directories and a GitHub Pages workflow in `.github/workflows/pages.yml` (delete it if you host elsewhere). Options: `--bundle-path <path>` installs the bundle from a local checkout instead of Packagist, and `--no-build` skips the initial build. Run it with `--help` to see all options.

## Installation (into a consuming Symfony app)

```bash
composer require cristianradulescu/staccato-bundle
```

Register the bundles (if not done automatically by Symfony Flex):

```php
// config/bundles.php
return [
    // ...
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Staccato\StaccatoBundle::class => ['all' => true],
];
```

Configure it under its own `staccato` key, e.g. `config/packages/staccato.yaml`:

```yaml
staccato:
    title: My Site
    base_url: https://example.com
    description: A simple static site generated with Staccato.
    articles_per_page: 10
    footer_text: Built with Staccato
```

If you create this file after `composer require`, run `php bin/console cache:clear` once: Composer's `cache:clear` auto-script built the `dev` container before the file existed, and that container does not notice new files in `config/packages/`, so until then `site:build` in `dev` uses the default configuration (a site titled `Staccato`), however often you edit the file.

The consuming application only needs to provide:

- `content/` — Markdown source files

Everything else — layout, homepage pagination, 404 page, RSS/sitemap markup, and a default stylesheet — is shipped by the bundle and works out of the box. Optionally, the consuming app can also provide:

- `assets/` — static files copied as-is into the build output (images, extra scripts, etc.)

Then build the site with the bundle's console command:

```bash
php bin/console site:build
```

## Configuration reference

All keys are optional and live under the `staccato` root key:

| Key | Type | Default |
| --- | --- | --- |
| `title` | string | `Staccato` |
| `base_url` | absolute URL | `http://localhost` |
| `description` | string | `Static site generated with Staccato.` |
| `language` | language tag (e.g. `en`, `pt-BR`) | `en` |
| `articles_per_page` | positive integer | `10` |
| `footer_text` | string | `Built with Staccato` |
| `include_default_styles` | boolean | `true` |
| `stylesheets` | array<string> | `[]` |
| `highlighter_css` | string | `null` |
| `content_dir` | relative path | `content` |
| `build_dir` | relative path | `build` |
| `assets_dir` | relative path | `assets` |
| `static_dir` | relative path | `static` |

`base_url` is the absolute URL the site is published at, including any sub-path (see "Hosting under a sub-path"); canonical links, `og:url`, `rss.xml` and `sitemap.xml` are built from it. To use a different one per environment, e.g. a local preview URL for `site:build` and the deployed URL for `site:build --env=prod`, override it in a `when@prod` block:

```yaml
# config/packages/staccato.yaml
staccato:
    base_url: http://localhost:8000

when@prod:
    staccato:
        base_url: https://user.github.io/my-repo
```

A `staccato:` key in `config/packages/prod/staccato.yaml` works the same way. `base_url: '%env(SITE_URL)%'` is not supported: `base_url` is validated when the container is compiled, while `%env()%` values are only read at runtime, so Symfony rejects it with `The path "staccato.base_url" cannot contain an environment variable when empty values are not allowed by definition and are validated.`

`content_dir`, `build_dir`, `assets_dir` and `static_dir` are relative to your project root, and none of their segments may be empty or end in `.` or a space. That rules out `.` and `..`, and names such as `content.`, which Windows reads as `content` because it drops trailing periods and spaces; dots and spaces elsewhere are fine (`site/.output`, `v1.2`). Because the build directory is removed on every clean build, `build_dir` may not equal, contain, or sit inside `content_dir`, `assets_dir` or `static_dir`. Whatever `assets_dir` is called, its files are published under `build/assets/`, so links like `/assets/logo.png` keep working. Files in `static_dir` are published at the root of the build directory instead — see "Output layout".

The rest of this README uses the default names (`content/`, `build/`, `assets/`).

## Customizing templates

Staccato ships default templates under the `@Staccato` Twig namespace: `base.html.twig`, `page.html.twig`, `home.html.twig`, `404.html.twig`, `redirect.html.twig`, `rss.xml.twig`, `sitemap.xml.twig`.

To override any single one of them, place a file at the matching path in your own app — Symfony's standard bundle template override convention:

```
templates/bundles/StaccatoBundle/page.html.twig
```

This file takes priority over the bundle's own `page.html.twig`, while every other default template keeps working unchanged. Override `base.html.twig` to change the overall layout, or override individual page templates to change just their content structure.

To add something to the layout (analytics, a favicon, navigation links, a linked footer), override `base.html.twig` by extending the bundle's own version and filling in only the blocks you need, so later changes to the default layout still reach your site:

```twig
{# templates/bundles/StaccatoBundle/base.html.twig #}
{% extends '@!Staccato/base.html.twig' %}

{% block head_extra %}
    <script async src="https://analytics.example/script.js"></script>
{% endblock %}

{% block nav %}
    <nav class="site-nav" aria-label="Main">
        <a href="{{ staccato_path('/about/') }}">About</a>
    </nav>
{% endblock %}

{% block footer_content %}
    <p>Built with <a href="https://github.com/cristianradulescu/staccato-bundle">Staccato</a>.</p>
{% endblock %}
```

`@!Staccato` refers to the bundle's original template, bypassing your override. Layout blocks in `base.html.twig`:

| Block | Default | Use it for |
|---|---|---|
| `head_extra` | empty, at the end of `<head>` | analytics, favicon, extra meta tags or scripts |
| `header` | the whole `<header>` element | replacing the header entirely |
| `site_title` | the site title link, inside the header | a custom title, logo or icon |
| `nav` | empty, after the site title | navigation links (`<nav class="site-nav">` is styled by the default theme) |
| `footer` | the whole `<footer>` element | replacing the footer entirely |
| `footer_content` | `footer_text` in a `<p>`, inside the footer | footer markup with links |

Helpers available in every template:

- `staccato_path(path)` — a site-relative link or asset path (`{{ staccato_path(article.urlPath) }}`, `{{ staccato_path('/assets/logo.png') }}`). Use it for every internal `href`/`src` so links keep working when the site is served from a sub-path — see "Hosting under a sub-path" below.
- `staccato_url(path)` — the absolute URL under `base_url` (`{{ staccato_url(page.urlPath) }}`), for canonical links, feeds and social metadata.
- `staccato` — the site configuration (`{{ staccato.title }}`, `{{ staccato.description }}`, `{{ staccato.language }}`, `{{ staccato.baseUrl }}`, ...).

The default `base.html.twig` renders `<html lang>` from `language`, a `<meta name="description">`, an RSS `<link rel="alternate">`, and exposes two blocks for page-specific head metadata: `description` (defaults to the site description; pages use their excerpt) and `canonical` (pages and homepage listings render an absolute `<link rel="canonical">`).

It also renders social metadata for link previews: `og:site_name`, `og:type`, `og:title`, `og:description` (same as the `description` block), `og:url` and a `twitter:card` of type `summary`. Pages use their own title and URL; dated pages are typed `article` and get an `article:published_time`. The values come from the `og_type`, `og_title` and `og_meta` (page-specific tags such as `og:url`) blocks; override `social_meta` to replace the whole set, e.g. to add an `og:image`.

Besides the `staccato` global, each template receives these variables, which overrides can rely on:

| Template | Variables |
|---|---|
| `page.html.twig` | `page`, and the layout variables |
| `home.html.twig` | `home`, and the layout variables |
| `404.html.twig` | the layout variables |
| `redirect.html.twig` | `page`, the page an alias redirects to; no layout variables. It reads only `page.title` and `page.urlPath`, so a `SiteBuiltEvent` listener can render it with an array of just these two keys for redirects that are not page aliases (see "Adding files after a build"). |
| `rss.xml.twig` | `articles` (the pages from `content/articles/`, newest first), `siteTitle`, `siteDescription`, `baseUrl` (`base_url` without a trailing slash), `generatedAt` (`\DateTimeImmutable`, the build time) |
| `sitemap.xml.twig` | `urls` (site-relative paths of the homepage listings and every page; the feed, aliases, `404.html` and static files are not listed), `baseUrl` |

The layout variables, which the default `base.html.twig` reads: `siteTitle` (`title`), `footerText` (`footer_text`), `includeDefaultStyles` (`include_default_styles`), `includeHighlighterStyles` (whether `highlighter_css` is set) and `stylesheets` (the file names of the `stylesheets`, published under `/assets/staccato/`).

| Object | Properties |
|---|---|
| `page`, items of `articles` | `title`, `urlPath` (e.g. `/docs/setup/`), `publishedAt` (`\DateTimeImmutable` from `date`, or `null`), `excerpt`, `html` (the rendered Markdown; print it with the `raw` filter), `meta` (see "Writing content"), `slug` (e.g. `docs/setup`), `aliases` (URL paths), `draft`, `sourcePath` (relative to `content/`, e.g. `docs/setup.md`) |
| `home` | `articles` (the listing items of this page), `currentPage`, `totalPages`, `previousPageUrl` and `nextPageUrl` (site-relative paths, `null` on the first and the last page), `urlPath` (`/`, `/page/2/`, ...) |
| items of `home.articles` | `title`, `urlPath`, `publishedAt`, `excerpt`, `meta`, as on `page` |

## Hosting under a sub-path

If the site is served from a sub-path rather than a domain root — e.g. a GitHub Pages project site at `https://user.github.io/my-repo/` — include that path in `base_url`:

```yaml
staccato:
    base_url: https://user.github.io/my-repo
```

The default templates prefix every internal link and stylesheet with it (`/my-repo/about/`, `/my-repo/assets/staccato/staccato.css`), and RSS/sitemap URLs include it as part of `base_url`. The `build/` layout itself does not change: deploy its contents to the sub-path.

Two things to keep in mind:

- Links written by hand inside Markdown content are not rewritten. Use relative links (`../other-article/`) or include the sub-path yourself.
- With a sub-path `base_url`, previewing `build/` from a local web server's root breaks links. Serve it under the same sub-path, or set the sub-path `base_url` only for the environment you deploy, in a `when@prod` block (see "Configuration reference").

## Styling and themes

The bundle ships a small, dependency-free default theme (no Node/Tailwind build step). On every build it is copied to `build/assets/staccato/staccato.css` and linked from the default `base.html.twig`, so a new site is styled out of the box.

Three options control the stylesheets, independently of each other:

- `include_default_styles` (default `true`) — copy and link the bundled `staccato.css`.
- `stylesheets` — your own CSS files (paths absolute or relative to your project root). Each is copied to `build/assets/staccato/<file name>` and linked after `staccato.css` and the highlighter stylesheet, so it can override them. File names must be unique and must not be `staccato.css` or `highlighter.css`.
- `highlighter_css` — a syntax-highlighting theme for code blocks (path absolute or relative to your project root, e.g. one of the themes shipped in `vendor/tempest/highlight/src/Themes/Css/`). It is copied to `build/assets/staccato/highlighter.css` and linked; when unset, nothing is copied or linked.

Tweak the default theme by adding your own stylesheet on top of it:

```yaml
staccato:
    stylesheets: ['themes/tweaks.css']
```

Replace the default theme with your own, with no template override needed:

```yaml
staccato:
    include_default_styles: false
    stylesheets: ['themes/my-theme.css']
    highlighter_css: 'vendor/tempest/highlight/src/Themes/Css/github-light.css'
```

For full control over the markup, override `base.html.twig` as described in "Customizing templates" above.

## Writing content

Supported Markdown front matter keys:

- `title` (string)
- `slug` (string)
- `date` (a parseable date such as `2021-08-27`, quoted or not, but not a bare number such as `2021`; without a UTC offset it is read in PHP's default time zone)
- `excerpt` (string)
- `draft` (boolean)
- `aliases` (list of URL paths; see "Redirects" below)

Any other key is passed through untouched to templates as `page.meta` (and `article.meta` for items in the homepage listing), so you can add your own:

```markdown
---
title: Hello
author: Jane Doe
tags: [php, symfony]
---
```

```twig
{% if page.meta.author is defined %}<p class="byline">By {{ page.meta.author }}</p>{% endif %}
{% for tag in page.meta.tags ?? [] %}<span class="tag">{{ tag }}</span>{% endfor %}
```

Values keep their YAML types; an unquoted date such as `updated: 2026-04-01` becomes a Unix timestamp, read in PHP's default time zone like `date`, so Twig's `date` filter shows the day as written.

Coming from Jekyll, Hugo or Jigsaw? There `description` usually feeds the `<meta name="description">` and the post summary; in Staccato that is `excerpt`. A `description` key is only available as `page.meta.description`.

Notes:

- Missing `title`/`slug` are derived from the source path: `content/about.md` -> `/about/`, `content/docs/setup.md` -> `/docs/setup/`. Files in `content/articles/` drop the `articles/` prefix (`content/articles/hello.md` -> `/hello/`).
- To give a directory its own page, add a sibling file: `content/docs.md` -> `/docs/`, next to `content/docs/setup.md` -> `/docs/setup/`.
- `index.md` files are not supported and fail the build with a hint on how to rename them. The homepage `/` is always generated from `content/articles/`, so a `slug` resolving to `/` fails the build too.
- Homepage listing only includes files from `content/articles/`.

### Redirects

When a page moves — renamed, or migrated from another generator with different URLs — list its old URLs under `aliases`:

```markdown
---
title: GitHub pages with Jigsaw
aliases: ['/2022-08-20-Github-pages-with-Jigsaw/', /blog/github-pages.html]
---
```

Each alias gets a small redirect page (`redirect.html.twig`: a meta refresh to the page, a canonical link to it and `noindex`, so search engines index only the new URL). Aliases are not in the sitemap, and aliases of drafts left out of a build are not written either.

- An alias is a URL path starting with `/`. Unlike slugs it keeps its case and may contain dots, so old mixed-case URLs survive a migration. Between the slashes it may use letters, digits, `.`, `_`, `~` and `-`, but no segment may end in `.`: Windows drops a trailing dot, so `/a./` would be written over the page `/a/` there.
- A path ending in `.html` is written as that file (`/blog/github-pages.html` -> `build/blog/github-pages.html`); anything else is directory-style like page URLs (`/Old-Post` -> `build/Old-Post/index.html`).
- An alias may not point at `/`, at another page, a homepage listing page, a generated file, anything under `/assets/`, another alias or a static file. Nor may it need one of these files as a directory, or be a file one of them needs as a directory: `/rss.xml` would be written to `build/rss.xml/index.html`, inside the feed, and `/old.html` and `/old.html/page/` cannot both be aliases. Conflicts fail the build before the build directory is cleaned.
- On a case-insensitive filesystem (macOS and Windows defaults), an alias that differs from its page's URL only by case is the same file as the page; the build fails rather than replace the page with a redirect to itself. Build on Linux or in CI to keep such aliases.

Old URLs that are not a page's, such as a previous generator's pagination, can get the same redirect pages from a listener: see "Adding files after a build".

Markdown is rendered with [`tempest/markdown`](https://github.com/tempestphp/markdown). Fenced code blocks with a language (```` ```php ````) are syntax-highlighted — pick a theme with `highlighter_css` — and headings get `id` attributes, so `#anchor` links work. When `excerpt` is missing, it is derived from the first ~220 characters of the page's text.

## `site:build` command

Options:

- `--clean` (default) cleans `build/` before generation; `--no-clean` keeps existing output.
- `--use-fixtures` generates 35 Faker-based demo articles instead of reading `content/`. It needs the optional `fakerphp/faker` package: `composer require --dev fakerphp/faker`.
- `--drafts` includes pages with `draft: true` and `--no-drafts` leaves them out, in any environment. Without either, the environment decides (see below).

Environment behavior:

- Without `--drafts` or `--no-drafts`, drafts are left out in `prod` and included in every other environment (`dev`, `test`, `staging`, ...).
- In `prod`, Symfony runs with debug off and does not check its cache for changes: after a first build, changes to templates (including overrides in `templates/bundles/StaccatoBundle/`) and to configuration such as `config/packages/staccato.yaml` are not picked up until you run `php bin/console cache:clear --env=prod`. Content is read on every build either way. To preview the site without drafts while you edit templates or configuration, build in `dev` with `--no-drafts`.

```bash
# Local preview without drafts; picks up template and configuration changes
php bin/console site:build --no-drafts

# Production build, e.g. in CI from a fresh checkout
php bin/console site:build --env=prod
```

## Output layout

Directory-style URLs are used:

- `/` -> `build/index.html`
- `/about/` -> `build/about/index.html`
- `/docs/getting-started/` -> `build/docs/getting-started/index.html`

Assets are mirrored from `assets/` to `build/assets/`. The default stylesheet (unless disabled) is copied to `build/assets/staccato/staccato.css`. Special generated files: `build/404.html`, `build/rss.xml`, `build/sitemap.xml`.

Files that must live at the site root — `favicon.ico`, `robots.txt`, `CNAME`, `.well-known/…` — go in `static/` (or whatever `static_dir` is set to), which is copied to `build/` as-is, subdirectories and dotfiles included (version-control directories such as `.git/` are skipped). A static file may not replace or nest inside generated output: `index.html`, `404.html`, `rss.xml`, `sitemap.xml`, anything under `assets/`, and every page and pagination path are reserved. A conflict fails the build before the build directory is cleaned.

The default is `static/` rather than `public/` on purpose: a Symfony app's `public/` holds its front controller (`index.php`), which must not end up in a static site.


## Adding files after a build

When a build has written every page, feed, asset and static file, `SiteBuilder` dispatches a `Staccato\Event\SiteBuiltEvent`. Listen to it to add your own files to the build — redirects, a search index, an extra feed — in PHP instead of a shell step after `site:build`:

```php
// src/EventListener/SearchIndexListener.php
namespace App\EventListener;

use Staccato\Event\SiteBuiltEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;

#[AsEventListener]
final class SearchIndexListener
{
    public function __invoke(SiteBuiltEvent $event): void
    {
        $index = array_map(
            static fn ($page): array => ['title' => $page->title, 'url' => $page->urlPath, 'excerpt' => $page->excerpt],
            $event->pages,
        );

        (new Filesystem())->dumpFile($event->buildDirectory.'/search.json', json_encode($index, JSON_THROW_ON_ERROR));
    }
}
```

The event carries `buildDirectory` (absolute path) and `pages` (every page written by this build, drafts only when they were included). Inject `Staccato\Model\SiteConfig` into the listener if you need the site configuration. The event is not dispatched when the build fails. An exception thrown by a listener fails `site:build`, but the files written so far stay in the build directory. Unlike static files, files written by listeners are not checked for conflicts, so a listener can deliberately replace a generated file.

To write redirects that are not page aliases, such as the pagination URLs of a previous generator, render `@Staccato/redirect.html.twig` with a `page` of just a `title` and the `urlPath` to redirect to:

```php
// src/EventListener/OldPaginationRedirectListener.php
namespace App\EventListener;

use Staccato\Event\SiteBuiltEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

#[AsEventListener]
final readonly class OldPaginationRedirectListener
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(SiteBuiltEvent $event): void
    {
        // The old site paginated at /blog/2/, /blog/3/, ...; Staccato writes /page/2/, /page/3/, ...
        for ($number = 2; is_file($event->buildDirectory.'/page/'.$number.'/index.html'); ++$number) {
            $html = $this->twig->render('@Staccato/redirect.html.twig', [
                'page' => ['title' => 'Page '.$number, 'urlPath' => '/page/'.$number.'/'],
            ]);

            (new Filesystem())->dumpFile($event->buildDirectory.'/blog/'.$number.'/index.html', $html);
        }
    }
}
```

## Homepage and pagination

- Homepage lists only files from `content/articles/`, ordered by `date` (newest first).
- Pagination uses `articles_per_page` (default `10`).
- Generated listing routes: `/` (page 1), `/page/2/`, `/page/3/`, ...

## Developing this bundle

- `composer install`
- `composer cs:check` / `composer cs:fix`
- `composer stan`
- `composer test`
- `composer qa` (runs all of the above)

The same checks run in CI (`.github/workflows/ci.yml`) on every push to `main` and every pull request, and the tests also run against the lowest dependency versions `composer.json` allows.

Tests boot a minimal Symfony test kernel (`tests/Fixtures/TestKernel.php`) registering `FrameworkBundle` + `TwigBundle` + `StaccatoBundle` to exercise the bundle's configuration and DI wiring and to render the default templates end-to-end, alongside plain unit tests for the content-loading and build services.

### Trying a working copy in a site

To see your changes in a real site, create one that installs the bundle from your checkout (run from the checkout's root; the site goes next to it):

```bash
bin/create-blog.sh --bundle-path . ../my-blog
```

Composer installs the bundle from a path repository by symlinking `vendor/cristianradulescu/staccato-bundle` to the checkout, so edits to the checkout are live in the site: rerun `php bin/console site:build` there to see them, with no reinstall or cache clear. Only a change to the bundle's `composer.json`, such as a new dependency, needs `composer update cristianradulescu/staccato-bundle` in the site. `site:build --env=prod` does not pick up edits to the bundle's templates or configuration until you run `php bin/console cache:clear --env=prod` (see "`site:build` command").

To link the checkout into an existing app instead, run in the app's root:

```bash
composer config repositories.staccato '{"type": "path", "url": "/path/to/staccato-bundle"}'
composer require 'cristianradulescu/staccato-bundle:*@dev'
```

`*@dev` is needed because the checkout's version is its branch name (`dev-<branch>`) and the path repository takes priority over Packagist. Then configure and build the site as in "Installation" above; a `config/packages/staccato.yaml` you create after the `composer require` needs one `php bin/console cache:clear` (see the note there).
