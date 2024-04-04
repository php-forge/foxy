<p align="center"><a href="https://github.com/php-forge/foxy" target="_blank">
    <img src="https://github.com/php-forge/foxy/blob/main/resources/foxy.svg" width="260" alt="Foxy">
</a></p>

<p align="center">
    <a href="https://www.php.net/releases/8.1/en.php" target="_blank">
        <img src="https://img.shields.io/badge/PHP-%3E%3D8.1-787CB5" alt="php-version">
    </a>
    <a href="https://github.com/php-forge/foxy/actions/workflows/build.yml" target="_blank">
        <img src="https://github.com/php-forge/foxy/actions/workflows/build.yml/badge.svg" alt="PHPUnit">
    </a> 
    <a href="https://codecov.io/gh/php-forge/foxy" target="_blank">
        <img src="https://codecov.io/gh/php-forge/foxy/branch/main/graph/badge.svg?token=MF0XUGVLYC" alt="Codecov">
    </a>
    <a href="https://github.com/yii2-extensions/asset-bootstrap5/actions/workflows/static.yml" target="_blank">
        <img src="https://github.com/yii2-extensions/asset-bootstrap5/actions/workflows/static.yml/badge.svg" alt="PSalm">
    </a>      
    <a href="https://github.styleci.io/repos/745652761?branch=main" target="_blank">
        <img src="https://github.styleci.io/repos/745652761/shield?branch=main" alt="StyleCI">
    </a>  
</p>

Add to your `composer.json` file.

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

> **Important:**
>
> ⚠ This plugin is based on [Fxpio/Foxy](https://github.com/fxpio/foxy).
> 
> Updates:
>  - PHP version to `8.1` or higher.
>  - Composer version to `2.0` or higher.
>  - Composer api version to `2.0` or higher.
>  - Add support for [Bun](https://bun.sh/).
>  - Remove deprecated methods.
>  - Add static analysis with [Psalm](https://psalm.dev).
>  - Add code quality with [StyleCI](https://github.styleci.io).


Foxy is a Composer plugin to automate the validation, installation, updating and removing of PHP libraries
asset dependencies (javaScript, stylesheets, etc.) defined in the NPM `package.json` file of the project and
PHP libraries during the execution of Composer. It handles restoring the project state in case
[Bun](https://bun.sh/) or [NPM](https://www.npmjs.com) or [Yarn](https://yarnpkg.com) or [PNpM](https://PNpM.io) terminates with an error.

All features and tools are available: 

- [Babel](https://babeljs.io)
- [Bun](https://github.com/oven-sh/bun)
- [Grunt](https://gruntjs.com)
- [Gulp](https://gulpjs.com)
- [Npmrc](https://docs.npmjs.com/files/npmrc)
- [Less](http://lesscss.org)
- [Scss/Sass](http://sass-lang.com)
- [TypeScript](https://www.typescriptlang.org)
- [Yarnrc](https://yarnpkg.com/en/docs/yarnrc)
- [Webpack](https://webpack.js.org), ,

It is certain that each language has its own dependency management system, and that it is highly recommended to use each
package manager. NPM, Yarn or PNpM works very well when the asset dependencies are managed only in the PHP project, but
when you create PHP libraries that using assets, there is no way to automatically add asset dependencies, and most
importantly, no validation of versions can be done automatically. You must tell the developers the list of asset
dependencies that using by your PHP library, and you must ask him to add manually the asset dependencies to its asset
manager of his project.

However, another solution exist - what many projects propose - you must add the assets in the folder of the PHP library
(like `/assets`, `/Resources/public`). Of course, with this method, the code is duplicated, it pollutes the source code
of the PHP library, no version management/validation is possible, and it is even less possible, to use all tools such as
Babel, Scss, Less, etc ...

Foxy focuses solely on automation of the validation, addition, updating and deleting of the dependencies in the
definition file of the asset package, while restoring the project state, as well as PHP dependencies if Bun, NPM, Yarn
or PNpM terminates with an error.

#### It is Fast

Foxy retrieves the list of all Composer dependencies to inject the asset dependencies in the file `package.json`, and
leaves the execution of the analysis, validation and downloading of the libraries to Bun, NPM, Yarn or PNpM.

Therefore, no VCS Repository of Composer is used for analyzing the asset dependencies, and you keep the performance
of native package manager used.

#### It is Reliable

Foxy creates mock packages of the PHP libraries containing only the asset dependencies definition file in a local
directory, and associates these packages in the asset dependencies definition file of the project. Given that Foxy does
not manipulate any asset dependencies, and let alone the version constraints, this allows Bun, NPM, Yarn or PNpM to
solve the asset dependencies without any intermediary. Moreover, the entire validation with the lock file and
installation process is left to Bun, NPM, Yarn or PNpM.

#### It is Secure

Foxy restores the Composer lock file with all its PHP dependencies, as well as the asset dependencies definition file,
in the previous state if Bun, NPM, Yarn or PNpM ends with an error.

Features
--------

- Compatible with [Yii Assets](https://github.com/yiisoft/assets)
- Compatible with [Symfony Webpack Encore](http://symfony.com/doc/current/frontend.html)
  and [Laravel Mix](https://laravel.com/docs/master/mix)
- Works with Node.js and Bun, NPM, Yarn or PNpM
- Works with the asset dependencies defined in the `package.json` file for projects and PHP libraries
- Works with the installation in the dependencies of the project or libraries (not in global mode)
- Works with public or private repositories
- Works with all features of Composer, NPM, Yarn and PNpM
- Retains the native performance of Composer, NPM, Yarn and PNpM
- Restores previous versions of PHP dependencies and the lock file if NPM, Yarn or PNpM terminates with an error
- Validates the NPM, Yarn or PNpM version with a version range
- Configuration of the plugin per project, globally or with the environment variables:
  - Enable/disable the plugin
  - Choose the asset manager: NPM, Yarn or PNpM (`npm` is used by default)
  - Lock the version of the asset manager with the Composer version range
  - Define the custom path of binary of the asset manager
  - Enable/disable the fallback for the asset package file of the project
  - Enable/disable the fallback for the Composer lock file and its dependencies
  - Enable/disable the running of asset manager to keep only the manipulation of the asset package file
  - Override the install command options for the asset manager
  - Override the update command options for the asset manager
  - Define the custom path of the mock package of PHP library
  - Enable/disable manually the asset packages for the PHP libraries
- Works with the Composer commands:
  - `install`
  - `update`
  - `require`
  - `remove`

Documentation
-------------

- [Guide](resources/doc/index.md)
- [FAQs](resources/doc/faqs.md)
- [Release Notes](https://github.com/php-forge/foxy/releases)

Installation
------------

Installation instructions are located in [the guide](resources/doc/index.md).

License
-------

Foxy is released under the MIT license. See the complete license in:

[LICENSE](LICENSE)

Reporting an issue or a feature request
---------------------------------------

Issues and feature requests are tracked in the [Github issue tracker](https://github.com/php-forge/foxy/issues).
