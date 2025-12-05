# Repository Guidelines

This repository hosts a MODX Revolution CMS project (v3.1.11) with Twig integration. Use PHP 8.4+ and MySQL 5.7+ when developing or running tests.

## Project Structure & Modules
- Core PHP sources live in `core/src` (autoloaded via PSR-4 `MODX\\`), with schemas in `core/model/schema`.
- Twig integration sits in `core/components/twig`; add Twig-focused tests in `core/components/twig/tests`.
- General tests reside in `_build/test` (PHPUnit config in `_build/test/phpunit.xml`).
- Frontend assets are under `assets` and `manager/assets`; setup assets in `setup/assets`.
- Entry points: `index.php` (web), `connectors/` (AJAX), and `manager/` (CMS backend).

## Build, Test, and Development Commands
- `composer install` — install PHP dependencies into `core/vendor`.
- `composer phpunit` — run the PHPUnit suite defined in `_build/test/phpunit.xml` (useful for twig tests too).
- `composer phpcs` / `composer phpcbf` — check/fix PHP style against `phpcs.xml`.
- `npm run js:lint` — lint JS in `manager/assets` and `setup/assets` using Airbnb base config.
- `composer parse-schema` — regenerate model classes from the XML schemas when schema changes are made.

## Coding Style & Naming Conventions
- PHP: 4-space indent, PSR-4 under `core/src`; prefer type hints and return types. Keep chunks/snippets self-contained and documented.
- JS: follow Airbnb style via ESLint; use semicolons and const/let over var.
- Templates: MODX templates remain valid without Twig syntax; Twig templates should use `.twig`-style syntax inside MODX template content or chunks.

## Testing Guidelines
- Use PHPUnit; place new unit tests in `_build/test` or Twig-specific tests in `core/components/twig/tests`. Name files `*Test.php`.
- Cover both MODX-only and Twig parsing paths (valid, invalid, and mixed MODX/Twig content). Include snippet/chunk interactions.
- Run `composer phpunit` before submitting; add fixtures under `_build/test` or component-specific fixtures as needed.

## Commit & Pull Request Guidelines
- Write imperative, concise commit messages (e.g., `Add twig chunk render test`). Reference related issues where possible.
- PRs should describe purpose, testing performed (`composer phpunit`, `npm run js:lint`), and any setup steps. Include screenshots only when UI changes occur.
- Keep changes scoped; include tests for new behavior and note any configuration assumptions (DB version, PHP version).

## Security & Configuration Tips
- Do not commit secrets; keep environment-specific settings in `config.core.php` and related config files outside version control.
- Respect minimum versions (PHP 8.4+, MySQL 5.7+) to avoid runtime differences; verify new dependencies are compatible with those baselines.
