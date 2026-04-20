# AGENTS.md

## Project Snapshot
- Symfony 8 bundle library (PHP >= 8.4) using Composer, package name `cristianradulescu/staccato-bundle`.
- This repo is the **engine only**: no `content/`, `templates/`, `assets/`, or app kernel live here. Those belong to whatever Symfony application requires this bundle (a dedicated skeleton project is planned but not yet published).
- Static-site generator uses Symfony-horizontal structure (`src/Model/`, `src/Service/`, `src/Command/`), plus `src/StaccatoBundle.php` (extends `AbstractBundle`).
- Core build flow: `ContentLoader` -> `SiteBuilder` -> `SiteBuildCommand`, with `SiteConfig` built directly by `StaccatoBundle::loadExtension()` from the `staccato` config tree, and optional fixtures from `FixtureContentGenerator`.

## Canonical Commands
- Install dependencies: `composer install`
- Run code style check: `composer cs:check`
- Run code style fix: `composer cs:fix`
- Run static analysis: `composer stan`
- Run tests: `composer test`
- Run full quality gate: `composer qa`

There is no `bin/console`/`site:build` to run directly in this repo — `site:build` is a service consumers get by requiring the bundle into their own app.

## What Exists / What Doesn't
- No GitHub Pages workflow here anymore (that was app/deployment concern, moved out with the rest of the site-hosting bits).
- No controllers, no HTTP entrypoint, no app `Kernel.php` — this is a library, not a runnable app.
- `tests/Fixtures/TestKernel.php` boots a minimal `FrameworkBundle` + `TwigBundle` + `StaccatoBundle` kernel purely to test the bundle's DI wiring (`tests/StaccatoBundleTest.php`).

## Codebase Boundaries
- Root namespace is `Staccato\` mapped to `src/` (`composer.json`).
- Bundle-internal services are wired via `config/services.yaml`, imported by `StaccatoBundle::loadExtension()` — this is the bundle's own config, not an app's `config/services.yaml`.
- `src/Model/` contains plain DTOs and is excluded from service autoregistration.
- Consumers configure the bundle under the `staccato:` root key (e.g. `config/packages/staccato.yaml` in their app), validated by `StaccatoBundle::configure()`.

## Repo-specific Gotchas
- `var/` is gitignored; do not treat it as source.
- All path-resolving services (`SiteBuilder`, `ContentLoader`, `FixtureContentGenerator`) resolve paths relative to `%kernel.project_dir%` of whatever app boots them — never CWD-relative. Keep this invariant when touching path logic.
- `SiteConfig` is registered directly as a service by `StaccatoBundle::loadExtension()`; there is no runtime YAML-file reading anymore.

## Content Conventions
- Supported front matter keys are `title`, `slug`, `date`, `excerpt`, `draft`. This is engine behavior (`ContentLoader`), independent of which app's `content/` directory is being read.
- Homepage listing pulls only from a source path starting with `articles/`.

## Editing Guidance
- Prefer adding engine logic under `src/` as services/commands (autowiring and autoconfigure are enabled via `config/services.yaml`).
- When adding new bundle configuration, update both `StaccatoBundle::configure()` (schema/defaults/validation) and `StaccatoBundle::loadExtension()` (how it's consumed), plus `tests/StaccatoBundleTest.php`.
- When documenting or automating commands, verify against actual Composer scripts; this repo has no `bin/console`.
