# Contributing to calisero/calisero-symfony

Thank you for considering contributing to the Calisero Symfony bundle! This document explains how to set up your
environment, the standards we follow, and how to submit high-quality issues and pull requests.

## Philosophy & Scope

The bundle integrates the Calisero SMS API into Symfony applications:

- **Stay thin**: the HTTP client, the DTOs and the error mapping belong to the
  [Calisero PHP SDK](https://github.com/calisero/calisero-php); the bundle configures it and wires it into Symfony.
- **Feel native to Symfony**: semantic configuration, autowiring, the Notifier, events, console commands, constraints.
- **Stay in step with the other Calisero libraries**: the same features, names and behaviors as
  [`calisero/laravel-sms`](https://github.com/calisero/laravel-sms) where Symfony allows, the same User-Agent format.
- **Support the maintained Symfony versions** (6.4, 7.x, 8.x) on the supported PHP versions (8.2 to 8.5).

## Development Setup

The development environment runs in Docker (`automation/`): Docker is all you need on your machine, PHP, Composer
and the dependencies live in the container.

1. Fork the repository
2. Clone your fork: `git clone https://github.com/your-username/calisero-symfony.git`
3. Start the container and install the dependencies:
   ```bash
   make up        # Builds and starts the PHP container
   make install   # composer install, in the container
   ```
4. Create a new branch: `git checkout -b feature/your-feature-name`

`make help` lists every target. Without `make`, run the same commands through Docker Compose:

```bash
docker compose -f automation/local/docker-compose.yml --env-file automation/local/.env up -d --build
docker compose -f automation/local/docker-compose.yml --env-file automation/local/.env exec php composer install
docker compose -f automation/local/docker-compose.yml --env-file automation/local/.env exec php composer qa
```

`make shell` opens a shell in the container, where every `composer` script below works as is. Copy
`automation/local/.env.dist` to `automation/local/.env` (`make up` does it) to change the PHP version of the
container, or to set an API key for the demo application ([automation/README.md](automation/README.md)).

## Quality Gates

| Gate | Command | Notes |
|------|---------|-------|
| Everything | `composer qa` (`make qa`) | `composer validate --strict`, code style, PHPStan, tests: what CI runs |
| Code style | `composer cs:check` (`make lint`) | PHP-CS-Fixer, dry run |
| Fix the style | `composer cs:fix` (`make format`) | Review the diff |
| Static analysis | `composer stan` (`make stan`) | PHPStan level 9, with `phpstan-symfony`, on `src/`, `config/` and `tests/` |
| Tests | `composer test` (`make test`) | PHPUnit; `composer test-coverage` for the coverage report |
| Every version | `make test-matrix` | The tests on each supported pair of PHP and Symfony versions |
| Lowest versions | `make test-lowest` | The tests with the lowest dependencies, on PHP 8.2 |

When you change a dependency constraint, run `make test-matrix` and `make test-lowest`: CI resolves the dependencies
for each version, and no lock file is committed.

## Code Standards

- **PHP-CS-Fixer** with the `@Symfony` and `@Symfony:risky` rule sets, `declare(strict_types=1)` in every file
  (`.php-cs-fixer.dist.php`).
- **PHPStan level 9** with no baseline. Scope any `ignoreErrors` entry of `phpstan.neon.dist` to an identifier and a
  path, and say why: today they only cover what differs between Symfony versions.
- **100% test coverage** of `src/`: new code comes with its tests.
- **Configuration values may be environment variable placeholders**: never read a configuration value in
  `CaliseroSmsBundle::loadExtension()`, pass it to the services, which receive it resolved.
- **Classes are `final`** unless they are meant to be extended; `@internal` marks what is not part of the public API.
- Comments explain why, not what.

## Testing

- Write tests for all new functionality, in `tests/Unit` (plain objects), `tests/Integration` (through the test
  application) or `tests/Contract` (against the OpenAPI document)
- Ensure the tests pass on every supported version (`make test-matrix`)
- Use meaningful test names (`testItRefusesAnUnknownParameter`) and data providers with named cases
- Cover both success and error scenarios
- **Never hard-code a phone number with a live country code**: take it from `TestPhones` (`+999`, reserved by ITU-T)

No test may reach the network. [TESTING.md](TESTING.md) describes the suite and its helpers.

## Adding Features

| Area | Guidance |
|------|----------|
| Configuration option | `CaliseroSmsBundle::configure()` with an `info()`, passed to its service in `loadExtension()`; README's Configuration Reference, `examples/config/packages/calisero.yaml` |
| Service | `config/services.php`, with an id prefixed `calisero.` and an alias for autowiring when it is public API |
| Event | `src/Event/`, ending in `Event`, with getters; dispatched by its class name |
| Console command | `src/Command/`, `#[AsCommand]`, the `calisero:` prefix and the Laravel package's name and options |
| Constraint | `src/Validator/`, its validator next to it, its messages translated in `translations/validators.ro.xlf` |
| API change | Update the SDK first; replace `resources/openApi/api-v1.json` and let the contract tests show the gaps |
| Breaking change | Open an issue first: the bundle follows semantic versioning |

## Pull Request Process

1. Ensure all QA checks pass (`make qa`, and `make test-matrix` when it matters)
2. Update the README.md and the examples if the change is visible to users
3. Update CHANGELOG.md under `[Unreleased]`, following [Keep a Changelog](https://keepachangelog.com/)
4. Create a Pull Request with a clear title and description, and a reference to the related issues

## Releasing

1. Set the new version in `SmsClient::VERSION` (sent in the `User-Agent` header): a test checks that it matches the
   latest version of CHANGELOG.md
2. Move the changes from `[Unreleased]` to the new version in CHANGELOG.md
3. Merge, then tag the release (`v1.2.3`): Packagist publishes it

## Commit Messages

Use clear, descriptive commit messages ([Conventional Commits](https://www.conventionalcommits.org) preferred):

```
feat(notifier): send the message ID back on SentMessage
fix(webhook): refuse a token given as an array
docs: document framework.router.default_uri for workers
test: cover the daily limit refusal of the commands
```

## Reporting Issues

When reporting issues, please include:

- PHP, Symfony and bundle versions
- Minimal code example reproducing the issue
- Expected vs actual behavior
- Any error messages or stack traces, with the trace ID of the failed request (`getTraceId()`)

## Security

If you discover a security vulnerability, please send an email to support@calisero.ro instead of using the issue tracker.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
