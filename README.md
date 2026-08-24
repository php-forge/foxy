<!-- markdownlint-disable MD041 -->
<p align="center">
    <a href="https://github.com/php-forge/support" target="_blank">
        <img src="https://avatars.githubusercontent.com/u/103309199?s=400&u=ca3561c692f53ed7eb290d3bb226a2828741606f&v=4" height="150px" alt="PHP Forge">
    </a>
    <h1 align="center">Foxy</h1>
    <br>
</p>
<!-- markdownlint-enable MD041 -->

<p align="center">
    <a href="https://github.com/php-forge/foxy/actions/workflows/build.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/foxy/build.yml?style=for-the-badge&label=PHPUnit&logo=github" alt="PHPUnit">
    </a>
    <a href="https://dashboard.stryker-mutator.io/reports/github.com/php-forge/foxy/main" target="_blank">
        <img src="https://img.shields.io/endpoint?style=for-the-badge&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fphp-forge%2Ffoxy%2Fmain" alt="Mutation Testing">
    </a>
    <a href="https://github.com/php-forge/foxy/actions/workflows/ecs.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/foxy/ecs.yml?style=for-the-badge&label=ECS&logo=github" alt="Easy Coding Standard">
    </a>
    <a href="https://github.com/php-forge/foxy/actions/workflows/dependency-check.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/foxy/dependency-check.yml?style=for-the-badge&label=Dependency%20Check&logo=github" alt="Dependency Check">
    </a>
</p>

<p align="center">
    <strong>Foxy is a Composer plugin that aggregates frontend dependencies declared by Composer packages into one package.json and delegates installation to Bun, npm, pnpm, or Yarn.</strong>
</p>

## Features

<picture>
    <source media="(min-width: 768px)" srcset="./docs/svgs/features.svg">
    <img src="./docs/svgs/features-mobile.svg" alt="Foxy features: dependency aggregation, native manager support and selection, flexible roots, protected mock directories, failure recovery, defensive I/O, and stable execution." style="width: 100%;">
</picture>

## Requirements

- PHP 8.3 or later.
- Composer 2.10.2 or later.
- One supported frontend manager: Bun, npm, pnpm, or Yarn.
- Node.js when using npm, pnpm, or Yarn.

## Installation

Authorize the Composer plugin and install Foxy 0.3:

```bash
composer config allow-plugins.php-forge/foxy true
composer require php-forge/foxy:^0.3
```

Selecting a manager explicitly is recommended for reproducible local and CI behavior:

```json
{
  "require": {
    "php-forge/foxy": "^0.3"
  },
  "config": {
    "allow-plugins": {
      "php-forge/foxy": true
    },
    "foxy": {
      "manager": "npm"
    }
  }
}
```

Valid manager values are `bun`, `npm`, `pnpm`, and `yarn`. When `manager` is omitted, Foxy first looks for one
recognized native lockfile and then checks available executables. Configure the manager explicitly when the project
contains lockfiles from more than one manager.

## Quick start

### Yii2 application template 22

The [Yii2 basic application template branch 22](https://github.com/yiisoft/yii2-app-basic/tree/22) requires PHP 8.3
and targets [Yii2 framework branch 22.0](https://github.com/yiisoft/yii2/tree/22.0). Create the tested development
version with:

```bash
composer create-project --prefer-dist yiisoft/yii2-app-basic basic 22.x-dev
```

The application keeps `package.json` at the project root and configures npm explicitly. Foxy merges frontend
dependencies from installed Composer packages and runs npm during Composer install and update operations.

For an existing Yii2 22 application, use the installation commands above and ensure its configuration contains:

```json
{
  "config": {
    "allow-plugins": {
      "php-forge/foxy": true
    },
    "foxy": {
      "manager": "npm"
    }
  }
}
```

### Project with package.json under web/

Composer may remain at the repository root while frontend tooling runs from `web/`:

```json
{
  "config": {
    "allow-plugins": {
      "php-forge/foxy": true
    },
    "foxy": {
      "manager": "npm",
      "root-package-json-dir": "web"
    }
  }
}
```

Foxy reads and writes `web/package.json` and runs the selected manager from `web/`. Relative paths are resolved from
the Composer project directory.

## Documentation

- [Getting started](docs/index.md)
- [Configuration reference](docs/config.md)
- [Usage guide](docs/usage.md)
- [Events reference](docs/events.md)
- [Frequently asked questions](docs/faqs.md)
- [Upgrade guide](UPGRADE.md)
- [Testing guide](docs/testing.md)

## Package information

[![PHP](https://img.shields.io/badge/%3E%3D8.3-777BB4.svg?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-forge/foxy.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/php-forge/foxy)
[![Total Downloads](https://img.shields.io/packagist/dt/php-forge/foxy.svg?style=for-the-badge&logo=composer&logoColor=white&label=Downloads)](https://packagist.org/packages/php-forge/foxy)

## Code quality

[![Codecov](https://img.shields.io/codecov/c/github/php-forge/foxy.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage)](https://codecov.io/gh/php-forge/foxy)
[![PHPStan Level 5](https://img.shields.io/badge/PHPStan-Level%205-4F5D95.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.com/php-forge/foxy/actions/workflows/static.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/php-forge/foxy/quality.yml?style=for-the-badge&label=Quality&logo=github)](https://github.com/php-forge/foxy/actions/workflows/quality.yml)
[![Dependency Check](https://img.shields.io/github/actions/workflow/status/php-forge/foxy/dependency-check.yml?style=for-the-badge&label=Dependency%20Check&logo=github)](https://github.com/php-forge/foxy/actions/workflows/dependency-check.yml)
[![Security](https://img.shields.io/github/actions/workflow/status/php-forge/foxy/security.yml?style=for-the-badge&label=Security&logo=github)](https://github.com/php-forge/foxy/actions/workflows/security.yml)

## Community

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
