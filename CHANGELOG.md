# Changelog

All notable changes to this project are documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [Unreleased]

### Added
- Initial release of `cristianradulescu/staccato-bundle`, a Symfony bundle that turns Markdown content into a static site.
- `Staccato\StaccatoBundle` (`AbstractBundle`) exposing a `staccato:` configuration tree (`title`, `base_url`, `description`, `articles_per_page`, `footer_text`).
- `site:build` console command with `--clean`/`--no-clean` and `--use-fixtures` options, and environment-aware draft handling (`dev` includes drafts, `prod` excludes them).
- Markdown content loading (`ContentLoader`) with YAML front matter support (`title`, `slug`, `date`, `excerpt`, `draft`) and CommonMark-based HTML conversion.
- Directory-style URL/output path resolution (`OutputPathResolver`), duplicate output path detection, and static asset mirroring.
- Paginated homepage generation from `content/articles/` (`articles_per_page`), plus `404.html`, `rss.xml`, and `sitemap.xml` generation.
- Optional Faker-based fixture content generation (`FixtureContentGenerator`) for demoing a site without committing real content.
- Quality tooling: PHP CS Fixer, PHPStan (level 7), PHPUnit, and a `composer qa` quality gate.
