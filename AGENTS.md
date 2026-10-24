# AGENTS.md

Guidance for coding agents (Claude Code, Codex, etc.) working in this repository. `CLAUDE.md` imports this file; edit this one.

## Project Snapshot
- Symfony 8 bundle library (PHP >= 8.5) using Composer, package name `cristianradulescu/staccato-bundle`.
- This repo is the **engine**: no `content/` or app kernel live here (those belong to whatever Symfony application requires this bundle), but it does ship default `templates/` and a default stylesheet (`public/css/staccato.css`) so consumers get a working site out of the box.
- Static-site generator uses Symfony-horizontal structure (`src/Model/`, `src/Service/`, `src/Command/`, `src/Twig/`), plus `src/StaccatoBundle.php` (extends `AbstractBundle`).
- Core build flow: `ContentLoader` -> `SiteBuilder` -> `SiteBuildCommand`, with `SiteConfig` built directly by `StaccatoBundle::loadExtension()` from the `staccato` config tree, and optional fixtures from `FixtureContentGenerator`.
- Markdown is rendered by `tempest/markdown` with `tempest/highlight` for fenced code blocks (both wired in `ContentLoader`).

## Canonical Commands
- Install dependencies: `composer install`
- Run code style check: `composer cs:check`
- Run code style fix: `composer cs:fix`
- Run static analysis: `composer stan`
- Run tests: `composer test`
- Run a single test: `vendor/bin/phpunit --filter testMethodName` or `vendor/bin/phpunit tests/Service/SiteBuilderTest.php`
- Run full quality gate (cs:check, stan, test in order): `composer qa`

There is no `bin/console`/`site:build` to run directly in this repo — `site:build` is a service consumers get by requiring the bundle into their own app.

## What Exists / What Doesn't
- No GitHub Pages workflow here anymore (that was app/deployment concern, moved out with the rest of the site-hosting bits).
- No controllers, no HTTP entrypoint, no app `Kernel.php` — this is a library, not a runnable app.
- `tests/Fixtures/TestKernel.php` boots a minimal `FrameworkBundle` + `TwigBundle` + `StaccatoBundle` kernel purely to test the bundle's DI wiring (`tests/StaccatoBundleTest.php`) and to render the default templates end-to-end (`tests/DefaultTemplatesTest.php`).

## Codebase Boundaries
- Root namespace is `Staccato\` mapped to `src/` (`composer.json`).
- Bundle-internal services are wired via `config/services.yaml`, imported by `StaccatoBundle::loadExtension()` — this is the bundle's own config, not an app's `config/services.yaml`.
- `src/Model/` contains plain DTOs and is excluded from service autoregistration.
- Consumers configure the bundle under the `staccato:` root key (e.g. `config/packages/staccato.yaml` in their app), validated by `StaccatoBundle::configure()`.
- Default templates live in `templates/` at the bundle root (sibling of `src/`), auto-registered by `TwigBundle` under the `@Staccato` namespace since `StaccatoBundle` extends `AbstractBundle` (whose `getPath()` resolves to the bundle root, not `src/`). Consumers override one template at a time via `templates/bundles/StaccatoBundle/<file>.twig` in their own app — standard Symfony convention, no extra code needed here.
- The default stylesheet lives in `public/css/staccato.css` at the bundle root; `StaccatoBundle::loadExtension()` exposes its directory as the `staccato.default_assets_dir` container parameter, consumed by `SiteBuilder` to copy it into `build/assets/staccato/staccato.css`. `include_default_styles` only controls that file: `stylesheets` and `highlighter_css` are copied and linked independently, so users can replace the theme through config alone.
- `src/Twig/StaccatoExtension.php` provides the template helpers: `staccato_path()` (site-relative link with the `base_url` sub-path), `staccato_url()` (absolute URL) and the `staccato` global (the `SiteConfig`).

## Repo-specific Gotchas
- `var/` is gitignored; do not treat it as source.
- All path-resolving services (`SiteBuilder`, `ContentLoader`, `FixtureContentGenerator`) resolve paths relative to `%kernel.project_dir%` of whatever app boots them — never CWD-relative. Keep this invariant when touching path logic.
- `SiteConfig` is registered directly as a service by `StaccatoBundle::loadExtension()`; there is no runtime YAML-file reading anymore. The content, build and assets directories come from `SiteConfig` (`content_dir`, `build_dir`, `assets_dir`), not from `SiteBuilder::build()` arguments.
- `SiteBuilder::build()` resolves stylesheets, loads content and runs every validation (duplicate paths, pagination conflicts) **before** removing the build directory, so a failing build keeps the previous output. Keep new validation ahead of the clean step.
- Internal links and asset paths in templates must go through the `staccato_path()` Twig function, which prefixes the base path of `base_url` so sub-path hosting works. Never hardcode root-relative `href="/..."` in templates.
- Prefer exposing new site-wide template data through the `staccato` Twig global over adding more `PageRenderer` render parameters.
- `PageRenderer`'s default template arguments are namespaced (`@Staccato/page.html.twig`, etc.); do not revert these to bare filenames or the bundle's own defaults stop resolving.
- `fakerphp/faker` is an optional (`require-dev` + `suggest`) dependency used only by `FixtureContentGenerator`, which checks for it at runtime. Do not use Faker anywhere else in `src/`.
- `TestKernel` uses a random cache directory per instance on purpose: with debug off, a reused cache dir silently serves a stale container.

## Content Conventions
- Supported front matter keys are `title`, `slug`, `date`, `excerpt`, `draft`. This is engine behavior (`ContentLoader`), independent of which app's `content/` directory is being read. Front matter tolerates CRLF line endings, a UTF-8 BOM and trailing whitespace on the `---` delimiters.
- Homepage listing pulls only from a source path starting with `articles/`; that prefix is dropped from article URLs.
- Missing `title`/`slug` are derived from the source path; `content/docs.md` is how a directory gets its own page (`/docs/`). `index.md` files are rejected by `ContentLoader`, and a slug resolving to `/` is rejected by `OutputPathResolver` — `/` is reserved for the generated homepage.
- Directory-style output URLs: `/` -> `build/index.html`, `/about/` -> `build/about/index.html`.
- `site:build` supports `--clean` (default; cleans the build directory first) / `--no-clean`, and `--use-fixtures` (35 Faker-based demo articles via `FixtureContentGenerator` instead of reading the content directory). In `dev` env drafts are included; in `prod` they're excluded. With `-v`, failures are rethrown so the Console shows the full exception.

## Editing Guidance
- Prefer adding engine logic under `src/` as services/commands (autowiring and autoconfigure are enabled via `config/services.yaml`).
- When adding new bundle configuration, update both `StaccatoBundle::configure()` (schema/defaults/validation) and `StaccatoBundle::loadExtension()` (how it's consumed), plus `SiteConfig`, `tests/StaccatoBundleTest.php` and the README configuration table.
- When documenting or automating commands, verify against actual Composer scripts; this repo has no `bin/console`.
