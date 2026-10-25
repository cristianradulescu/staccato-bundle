# Changelog

All notable changes to this project are documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [Unreleased]

## [0.2.0] - 2026-10-25

### Added
- Layout blocks in the default `base.html.twig`: `head_extra`, `header`, `site_title`, `nav`, `footer` and `footer_content`. Extend `@!Staccato/base.html.twig` and fill in only the blocks you need instead of copying the whole layout. The default theme styles a `<nav class="site-nav">` in the header.
- Social metadata in the default templates: `og:site_name`, `og:type`, `og:title`, `og:description`, `og:url`, `twitter:card`, and `article:published_time` for dated pages. Customizable through the `social_meta`, `og_type`, `og_title` and `og_meta` blocks.
- `static_dir` configuration (default `static/`): its files are copied to the root of the build directory as-is, for `favicon.ico`, `robots.txt`, `CNAME` or `.well-known/`. Static files that would replace or nest inside generated output fail the build before the build directory is cleaned.
- `page.meta` (and `meta` on homepage listing items): every front matter key other than the ones Staccato interprets, passed through untouched, so templates can use `author`, `tags` and similar keys.
- `aliases` front matter: old URLs of a page, each written as a redirect page (`redirect.html.twig`: meta refresh, canonical link, `noindex`). Aliases keep their case and may end in `.html`, so URLs from a previous generator keep working. They are validated against every other output before the build directory is cleaned.
- `Staccato\Event\SiteBuiltEvent`, dispatched after a successful build with the build directory and the built pages, to add files such as redirects, a search index or extra feeds from a listener.
- `site:build` reports the number of static files copied and redirects generated.
- `bin/create-blog.sh` writes a GitHub Pages deployment workflow (`.github/workflows/pages.yml`) and creates a `static/` directory.

### Changed
- `bin/create-blog.sh` requires PHP 8.5 in the generated `composer.json` and removes the web front controller (`public/`, `src/Controller/`, the skeleton's `templates/base.html.twig` and the `assets:install` auto-script), which a static site does not need.
- `SiteBuilder` takes an event dispatcher as a new constructor argument; `symfony/event-dispatcher` is now a direct dependency. Applications using the autowired service are not affected.
- The header container of the default `base.html.twig` has an extra `site-header-inner` class, laid out as a flex row.

## [0.1.0] - 2026-10-24

### Added
- Initial release of `cristianradulescu/staccato-bundle`, a Symfony bundle that turns Markdown content into a static site.
- `Staccato\StaccatoBundle` (`AbstractBundle`) exposing a validated `staccato:` configuration tree: `title`, `base_url`, `description`, `language`, `articles_per_page`, `footer_text`, `include_default_styles`, `stylesheets`, `highlighter_css`, `content_dir`, `build_dir`, `assets_dir`.
- Default templates (`base.html.twig`, `page.html.twig`, `home.html.twig`, `404.html.twig`, `rss.xml.twig`, `sitemap.xml.twig`) shipped under the `@Staccato` Twig namespace, so a site works out of the box with no templates of its own; any single template can be overridden via `templates/bundles/StaccatoBundle/<file>.twig` in the consuming app.
- SEO basics in the default templates: `<html lang>`, meta description (page excerpt or site description), absolute canonical links for pages and paginated home pages, and an RSS alternate link.
- Twig helpers for templates: `staccato_path()` for internal links and assets, `staccato_url()` for absolute URLs, and a `staccato` global exposing the site configuration.
- Sub-path hosting (e.g. GitHub Pages project sites): the path of `base_url` is applied to every internal link and stylesheet.
- A default, dependency-free theme (`public/css/staccato.css`), copied to `build/assets/staccato/staccato.css` on every build. `include_default_styles` toggles it; your own `stylesheets` and a `highlighter_css` code theme are copied and linked independently, so the theme can be extended or replaced through config alone.
- `site:build` console command with `--clean`/`--no-clean` and `--use-fixtures` options, environment-aware draft handling (`dev` includes drafts, `prod` excludes them), and the full exception on `-v`.
- Markdown content loading (`ContentLoader`) with YAML front matter (`title`, `slug`, `date`, `excerpt`, `draft`; tolerant of CRLF line endings, a UTF-8 BOM and trailing whitespace on delimiters), HTML conversion via `tempest/markdown`, syntax highlighting via `tempest/highlight`, and plain-text excerpts derived from the rendered body.
- Directory-style URL/output path resolution (`OutputPathResolver`), with `content/docs.md` mapping to `/docs/`. `index.md` files and slugs resolving to `/` are rejected, since `/` is the generated homepage.
- Build safety: configuration, content, duplicate output paths and pagination conflicts are validated before the build directory is cleaned, so a failing build keeps the previous output.
- Paginated homepage generation from `content/articles/` (`articles_per_page`), plus `404.html`, `rss.xml`, and `sitemap.xml` generation, and static asset mirroring into `build/assets/`.
- Optional Faker-based fixture content generation (`FixtureContentGenerator`) for demoing a site without committing real content; requires the suggested `fakerphp/faker` package.
- `bin/create-blog.sh`, a helper script that creates a new Symfony application with Staccato, sample content and a first build.
- Quality tooling: PHP CS Fixer, PHPStan (level 7), PHPUnit, and a `composer qa` quality gate.

[Unreleased]: https://github.com/cristianradulescu/staccato-bundle/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/cristianradulescu/staccato-bundle/releases/tag/v0.2.0
[0.1.0]: https://github.com/cristianradulescu/staccato-bundle/releases/tag/v0.1.0
