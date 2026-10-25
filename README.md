# Staccato

Staccato is a Symfony bundle that turns Markdown content into a static site: pages, a paginated homepage, an RSS feed, and a sitemap, all rendered with Twig.

This repository is the **engine** — a reusable `cristianradulescu/staccato-bundle` package. It ships default templates and styling so you can focus on writing content instead of building a layout; it is meant to be required into a Symfony application (or a dedicated Staccato project skeleton, once published) that supplies content.

## Requirements

- PHP 8.5+
- A Symfony 8.1+ application with `bin/console` to install the bundle into (e.g. one created from `symfony/skeleton`)

`symfony/twig-bundle` is installed as a dependency of the bundle: it renders the default templates and makes them overridable (see "Customizing templates" below).

## Quick start: a new blog

`bin/create-blog.sh` creates a Symfony application with Staccato installed, a sample article (plus a draft) and an About page, then builds it:

```bash
bin/create-blog.sh --title "My Blog" --base-url https://blog.example.com my-blog
cd my-blog
php -S localhost:8000 -t build
```

It checks for PHP 8.5+ and Composer first. Options: `--bundle-path <path>` installs the bundle from a local checkout instead of Packagist, and `--no-build` skips the initial build. Run it with `--help` to see all options.

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

`content_dir`, `build_dir`, `assets_dir` and `static_dir` are relative to your project root and may not contain `.` or `..` segments. Because the build directory is removed on every clean build, `build_dir` may not equal, contain, or sit inside `content_dir`, `assets_dir` or `static_dir`. Whatever `assets_dir` is called, its files are published under `build/assets/`, so links like `/assets/logo.png` keep working. Files in `static_dir` are published at the root of the build directory instead — see "Output layout".

The rest of this README uses the default names (`content/`, `build/`, `assets/`).

## Customizing templates

Staccato ships default templates under the `@Staccato` Twig namespace: `base.html.twig`, `page.html.twig`, `home.html.twig`, `404.html.twig`, `rss.xml.twig`, `sitemap.xml.twig`.

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

## Hosting under a sub-path

If the site is served from a sub-path rather than a domain root — e.g. a GitHub Pages project site at `https://user.github.io/my-repo/` — include that path in `base_url`:

```yaml
staccato:
    base_url: https://user.github.io/my-repo
```

The default templates prefix every internal link and stylesheet with it (`/my-repo/about/`, `/my-repo/assets/staccato/staccato.css`), and RSS/sitemap URLs include it as part of `base_url`. The `build/` layout itself does not change: deploy its contents to the sub-path.

Two things to keep in mind:

- Links written by hand inside Markdown content are not rewritten. Use relative links (`../other-article/`) or include the sub-path yourself.
- With a sub-path `base_url`, previewing `build/` from a local web server's root breaks links. Serve it under the same sub-path, or set the sub-path `base_url` only for the environment you deploy (e.g. in `config/packages/prod/staccato.yaml`).

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
- `date` (string; parseable date)
- `excerpt` (string)
- `draft` (boolean)

Notes:

- Missing `title`/`slug` are derived from the source path: `content/about.md` -> `/about/`, `content/docs/setup.md` -> `/docs/setup/`. Files in `content/articles/` drop the `articles/` prefix (`content/articles/hello.md` -> `/hello/`).
- To give a directory its own page, add a sibling file: `content/docs.md` -> `/docs/`, next to `content/docs/setup.md` -> `/docs/setup/`.
- `index.md` files are not supported and fail the build with a hint on how to rename them. The homepage `/` is always generated from `content/articles/`, so a `slug` resolving to `/` fails the build too.
- Homepage listing only includes files from `content/articles/`.

Markdown is rendered with [`tempest/markdown`](https://github.com/tempestphp/markdown). Fenced code blocks with a language (```` ```php ````) are syntax-highlighted — pick a theme with `highlighter_css` — and headings get `id` attributes, so `#anchor` links work. When `excerpt` is missing, it is derived from the first ~220 characters of the page's text.

## `site:build` command

Options:

- `--clean` (default) cleans `build/` before generation; `--no-clean` keeps existing output.
- `--use-fixtures` generates 35 Faker-based demo articles instead of reading `content/`. It needs the optional `fakerphp/faker` package: `composer require --dev fakerphp/faker`.

Environment behavior:

- In `dev`, drafts are included.
- In `prod`, drafts are excluded.

```bash
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

Tests boot a minimal Symfony test kernel (`tests/Fixtures/TestKernel.php`) registering `FrameworkBundle` + `TwigBundle` + `StaccatoBundle` to exercise the bundle's configuration and DI wiring and to render the default templates end-to-end, alongside plain unit tests for the content-loading and build services.
