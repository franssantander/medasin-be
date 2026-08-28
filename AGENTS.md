# Repository Guidelines

## Project Structure & Module Organization

This is a Laravel 13 API targeting PHP 8.3. Application code lives in `app/`: controllers and form requests are grouped by feature under `app/Http`, business logic belongs in `app/Services`, and Eloquent models are in `app/Models`. Versioned API routes are split by domain in `routes/v1/` and included from `routes/api.php`. Database migrations, factories, and seeders live under `database/`. Frontend entry points are in `resources/`, while compiled/public assets are served from `public/`. Put PHPUnit tests in `tests/Feature/<Domain>/` for HTTP or integration behavior and `tests/Unit/` for isolated logic.

## Build, Test, and Development Commands

- `composer setup` installs PHP and JavaScript dependencies, creates `.env`, generates the app key, migrates the database, and builds assets.
- `composer dev` starts the local Laravel development processes configured by the framework.
- `npm run dev` runs Vite in watch mode; `npm run build` creates production assets.
- `composer test` clears cached configuration and runs the complete PHPUnit suite.
- `php artisan test --filter=StoreProjectTest` runs a focused test.
- `vendor/bin/pint` formats PHP code; use `vendor/bin/pint --test` to check formatting without changing files.

## Coding Style & Naming Conventions

Follow PSR-12 and Laravel conventions with four-space indentation for PHP. Use `StudlyCase` for classes and enums, `camelCase` for methods and variables, and `snake_case` for database columns. Name controllers, requests, services, and tests by responsibility, for example `ProjectController`, `StoreProjectRequest`, `ProjectService`, and `StoreProjectTest`. Keep validation in form requests and reusable domain operations in services rather than controllers.

## Testing Guidelines

Tests use PHPUnit 12 and an in-memory SQLite database configured in `phpunit.xml`. Add or update tests with every behavior change, covering successful responses, validation failures, authorization, and persistence effects. Test files must end in `Test.php`; keep each test focused on one observable behavior. No coverage minimum is currently enforced.

## Commit & Pull Request Guidelines

Recent history favors concise Conventional Commit subjects such as `feat(area): implement Area management`. Use an imperative summary with a relevant scope (`feat(auth):`, `fix(project):`, `test(area):`). Pull requests should explain the change and verification performed, link related issues, and call out migrations, API contract changes, or configuration updates. Include screenshots only for user-visible UI changes.

## Security & Configuration

Never commit `.env`, credentials, access tokens, or generated private keys. Document new environment variables in `.env.example`, and review authentication changes involving Passport, Sanctum, cookies, or refresh tokens carefully.
