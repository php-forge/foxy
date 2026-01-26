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
    <strong>Foxy is a Composer plugin that aggregates asset dependencies from Composer packages into a single package.json and runs Bun, npm, Yarn, or pnpm while preserving the Composer state on failures.</strong>
</p>

## Features

<picture>
    <source media="(min-width: 768px)" srcset="./docs/svgs/features.svg">
    <img src="./docs/svgs/features-mobile.svg" alt="Feature Overview" style="width: 100%;">
</picture>

## Installation

```bash
composer require php-forge/foxy:^0.1
```

Manager can be `bun`, `npm`, `yarn` or `pnpm`. For default, `npm` is used.

```json
{
    "require": {
        "php-forge/foxy": "^0.1"
    },
    "config": {
        "foxy": {
            "manager": "bun"
        }
    }
}
```

## Quick start

### Standard PHP project (Yii2)

In a standard PHP application, keep a `package.json` file at the project root. Foxy will merge asset dependencies from
installed Composer packages and run the configured manager during Composer install and update.

Example (Yii2 app template):

[Yii2 app template](https://github.com/yiisoft/yii2-app-basic/tree/22)

```json
{
    "require": {
        "php-forge/foxy": "^0.1"
    },
    "config": {
        "foxy": {
            "manager": "npm"
        }
    }
}
```

### Drupal layout (package.json under web/)

In a typical Drupal proof-of-concept workflow, Composer stays at the repository root while frontend tooling and builds
live under `web/`.

Foxy lets you keep that layout while still aggregating asset dependencies and running npm in the correct directory, with
Composer state preserved if the install fails.

- Aggregates asset dependencies declared by Composer packages into a single npm install.
- Keeps asset tooling configuration consistent across local and CI environments.
- Restores Composer lock and PHP dependencies if npm exits with an error.
- Runs npm against the `web/` package.json without moving Composer files.

```json
{
    "config": {
        "foxy": {
            "manager": "npm",
            "root-package-json-dir": "web"
        }
    }
}
```

## Documentation

- 📚 [Guide](resources/doc/index.md)
- 💡 [Usage](resources/doc/usage.md)
- ⚙️ [Configuration](resources/doc/config.md)
- 📅 [Events](resources/doc/events.md)
- ❓ [FAQs](resources/doc/faqs.md)
- 🧪 [Testing Guide](docs/testing.md)
- 🛠️ [Development Guide](docs/development.md)

## Package information

[![PHP](https://img.shields.io/badge/%3E%3D8.1-777BB4.svg?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/releases/8.1/en.php)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-forge/foxy.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/php-forge/foxy)
[![Total Downloads](https://img.shields.io/packagist/dt/php-forge/foxy.svg?style=for-the-badge&logo=composer&logoColor=white&label=Downloads)](https://packagist.org/packages/php-forge/foxy)

## Quality code

[![Codecov](https://img.shields.io/codecov/c/github/php-forge/foxy.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage)](https://codecov.io/gh/php-forge/foxy)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%205-4F5D95.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.com/php-forge/foxy/actions/workflows/static.yml)
[![Super-Linter](https://img.shields.io/github/actions/workflow/status/php-forge/foxy/linter.yml?style=for-the-badge&label=Super-Linter&logo=github)](https://github.com/php-forge/foxy/actions/workflows/linter.yml)
[![Dependency Check](https://img.shields.io/github/actions/workflow/status/php-forge/foxy/dependency-check.yml?style=for-the-badge&label=Dependency%20Check&logo=github)](https://github.com/php-forge/foxy/actions/workflows/dependency-check.yml)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
