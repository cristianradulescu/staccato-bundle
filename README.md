# Staccato

Staccato is a Symfony bundle that turns Markdown content into a static site: pages, a paginated homepage, an RSS feed, and a sitemap, all rendered with Twig.

This repository is the **engine** — a reusable `cristianradulescu/staccato-bundle` package. It has no content, templates, or assets of its own; it is meant to be required into a Symfony application (or a dedicated Staccato project skeleton, once published) that supplies those.

## Requirements

- PHP 8.4+
- A Symfony 8 application to install the bundle into

## Installation (into a consuming Symfony app)

```bash
composer require cristianradulescu/staccato-bundle
```

Register the bundle (if not done automatically by Symfony Flex):

```php
// config/bundles.php
return [
    // ...
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

The consuming application is responsible for providing:

- `content/` — Markdown source files
- `templates/page.html.twig`, `home.html.twig`, `404.html.twig`, `rss.xml.twig`, `sitemap.xml.twig` — rendered via the app's own Twig environment
- `assets/` — static files copied as-is into the build output

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
| `articles_per_page` | positive integer | `10` |
| `footer_text` | string | `Built with Staccato` |

## Writing content

Supported Markdown front matter keys:

- `title` (string)
- `slug` (string)
- `date` (string; parseable date)
- `excerpt` (string)
- `draft` (boolean)

Notes:

- `index.md` maps to the directory root (`/` at root, `/docs/` for `content/docs/index.md`).
- Missing `title`/`slug` are derived from the source path.
- Homepage listing only includes files from `content/articles/`.

## `site:build` command

Options:

- `--clean` (default) cleans `build/` before generation; `--no-clean` keeps existing output.
- `--use-fixtures` generates 35 Faker-based demo articles instead of reading `content/`.

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

Assets are mirrored from `assets/` to `build/assets/`. Special generated files: `build/404.html`, `build/rss.xml`, `build/sitemap.xml`.

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

Tests boot a minimal Symfony test kernel (`tests/Fixtures/TestKernel.php`) registering `FrameworkBundle` + `StaccatoBundle` to exercise the bundle's DI wiring, alongside plain unit tests for the content-loading/build services.
