# Staccato

Staccato is a Symfony bundle that turns Markdown content into a static site: pages, a paginated homepage, an RSS feed, and a sitemap, all rendered with Twig.

This repository is the **engine** — a reusable `cristianradulescu/staccato-bundle` package. It ships default templates and styling so you can focus on writing content instead of building a layout; it is meant to be required into a Symfony application (or a dedicated Staccato project skeleton, once published) that supplies content.

## Requirements

- PHP 8.5+
- A Symfony 8 application to install the bundle into (with `symfony/twig-bundle`, so the bundle's default templates are discovered and overridable — see "Customizing templates" below)

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
| `articles_per_page` | positive integer | `10` |
| `footer_text` | string | `Built with Staccato` |
| `include_default_styles` | boolean | `true` |
| `stylesheets` | array<string> | `[]` |
| `highlighter_css` | string | `null` |
| `content_dir` | relative path | `content` |
| `build_dir` | relative path | `build` |
| `assets_dir` | relative path | `assets` |

`content_dir`, `build_dir` and `assets_dir` are relative to your project root and may not contain `.` or `..` segments. Because the build directory is removed on every clean build, `build_dir` may not equal, contain, or sit inside `content_dir` or `assets_dir`. Whatever `assets_dir` is called, its files are published under `build/assets/`, so links like `/assets/logo.png` keep working.

The rest of this README uses the default names (`content/`, `build/`, `assets/`).

## Customizing templates

Staccato ships default templates under the `@Staccato` Twig namespace: `base.html.twig`, `page.html.twig`, `home.html.twig`, `404.html.twig`, `rss.xml.twig`, `sitemap.xml.twig`.

To override any single one of them, place a file at the matching path in your own app — Symfony's standard bundle template override convention:

```
templates/bundles/StaccatoBundle/page.html.twig
```

This file takes priority over the bundle's own `page.html.twig`, while every other default template keeps working unchanged. Override `base.html.twig` to change the overall layout, or override individual page templates to change just their content structure.

In your own templates, wrap internal links and asset paths in `staccato_path()` (e.g. `{{ staccato_path(article.urlPath) }}`, `{{ staccato_path('/assets/logo.png') }}`) so they keep working when the site is served from a sub-path — see "Hosting under a sub-path" below.

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

## Defaul## Styling and themes

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

g content

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

Assets are mirrored from `assets/` to `build/assets/`. The default stylesheet (unless disabled) is copied to `build/assets/staccato/staccato.css`. Special generated files: `build/404.html`, `build/rss.xml`, `build/sitemap.xml`.

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
