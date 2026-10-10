<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\Util\Filesystem;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{LockFile, ResolvedPackage};
use Foxy\Tests\Provider\LockFileProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Unit tests for {@see LockFile} reading, validation, and deterministic writing of `foxy.lock`.
 *
 * {@see LockFileProvider} for test case data providers.
 */
final class LockFileTest extends TestCase
{
    private const string BOOTSTRAP_INTEGRITY = 'sha512-Ab+/9w==';
    private const string BOOTSTRAP_URL = 'https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz';
    private const string LOCAL_NAME = '@composer-asset/acme--theme';
    private const string LOCAL_PATH = './vendor/php-forge/composer-asset/acme/theme';
    private const string POPPER_INTEGRITY = 'sha1-Cd9= sha512-Ef0+';
    private const string POPPER_URL = 'https://registry.npmjs.org/@popperjs/core/-/core-2.11.8.tgz';
    private const string README = <<<'JSON'
            "_readme": [
                "This file locks the frontend dependencies installed by the Foxy native manager.",
                "Do not edit it manually; composer install and composer update regenerate it."
            ],
        JSON;

    private string $path = '';
    private string $root = '';

    public function testExistsReflectsTheFile(): void
    {
        $lock = new LockFile($this->path);

        self::assertFalse(
            $lock->exists(),
            'A missing file must not exist.',
        );

        $lock->write([], []);

        self::assertTrue(
            $lock->exists(),
            'A written file must exist.',
        );
    }

    public function testReadReturnsNumericPackageNamesAsStrings(): void
    {
        $lock = new LockFile($this->path);

        $lock->write([], ['7' => ResolvedPackage::fromLocal('7', '1.0.0', './seven')]);

        self::assertSame(
            '7',
            $lock->read()['packages'][7]->name,
            'A numeric name must be read as a string.',
        );
    }

    public function testReadReturnsWrittenRequirementsAndPackages(): void
    {
        $lock = new LockFile($this->path);

        $lock->write($this->requirements(), $this->packages());

        $data = $lock->read();

        self::assertSame(
            [
                '' => [
                    'dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH, 'bootstrap' => '^5.3'],
                    'optionalDependencies' => [],
                ],
                self::LOCAL_NAME => [
                    'dependencies' => ['@popperjs/core' => '^2.11.8', 'bootstrap' => '^5.3.0'],
                    'optionalDependencies' => ['fsevents' => '^2.3'],
                ],
            ],
            $data['requirements'],
            'Requirements must be read back sorted, both kinds per source.',
        );
        self::assertEquals(
            [
                self::LOCAL_NAME => ResolvedPackage::fromLocal(self::LOCAL_NAME, '1.0.0', self::LOCAL_PATH),
                '@popperjs/core' => new ResolvedPackage(
                    '@popperjs/core',
                    '2.11.8',
                    self::POPPER_URL,
                    self::POPPER_INTEGRITY,
                ),
                'bootstrap' => new ResolvedPackage(
                    'bootstrap',
                    '5.3.8',
                    self::BOOTSTRAP_URL,
                    self::BOOTSTRAP_INTEGRITY,
                ),
            ],
            $data['packages'],
            'Packages must be read back in key order.',
        );
    }

    #[DataProviderExternal(LockFileProvider::class, 'malformedLocks')]
    public function testThrowRuntimeExceptionForMalformedLock(string $contents): void
    {
        file_put_contents($this->path, $contents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_LOCK_INVALID->getMessage($this->path),
        );

        (new LockFile($this->path))->read();
    }

    public function testWriteEncodesEmptyMapsAsObjects(): void
    {
        $lock = new LockFile($this->path);

        $lock->write([], []);

        self::assertSame(
            "{\n" . self::README . "\n    \"requirements\": {},\n    \"packages\": {}\n}\n",
            file_get_contents($this->path),
            'Empty top-level maps must be written as objects.',
        );

        $lock->write(['' => ['dependencies' => [], 'optionalDependencies' => []]], []);

        self::assertSame(
            "{\n" . self::README . "\n    \"requirements\": {\n        \"\": {\n            \"dependencies\": {},\n"
            . "            \"optionalDependencies\": {}\n        }\n    },\n    \"packages\": {}\n}\n",
            file_get_contents($this->path),
            'Empty requirement maps must be written as objects.',
        );
    }

    public function testWriteSortsKeysAsStrings(): void
    {
        $lock = new LockFile($this->path);

        $lock->write(
            ['' => ['dependencies' => ['9' => '^1.0', '10' => '^2.0'], 'optionalDependencies' => ['9' => '*', '10' => '*']]],
            [
                '9' => ResolvedPackage::fromLocal('9', '1.0.0', './nine'),
                '10' => ResolvedPackage::fromLocal('10', '2.0.0', './ten'),
            ],
        );

        $expected = <<<'JSON'
            {
                "_readme": [
                    "This file locks the frontend dependencies installed by the Foxy native manager.",
                    "Do not edit it manually; composer install and composer update regenerate it."
                ],
                "requirements": {
                    "": {
                        "dependencies": {
                            "10": "^2.0",
                            "9": "^1.0"
                        },
                        "optionalDependencies": {
                            "10": "*",
                            "9": "*"
                        }
                    }
                },
                "packages": {
                    "10": {
                        "version": "2.0.0",
                        "file": "./ten"
                    },
                    "9": {
                        "version": "1.0.0",
                        "file": "./nine"
                    }
                }
            }

            JSON;

        self::assertSame(
            $expected,
            file_get_contents($this->path),
            'Numeric names must sort as strings.',
        );
    }

    public function testWriteUsesTheDocumentedLayout(): void
    {
        (new LockFile($this->path))->write($this->requirements(), $this->packages());

        $expected = <<<'JSON'
            {
                "_readme": [
                    "This file locks the frontend dependencies installed by the Foxy native manager.",
                    "Do not edit it manually; composer install and composer update regenerate it."
                ],
                "requirements": {
                    "": {
                        "dependencies": {
                            "@composer-asset/acme--theme": "file:./vendor/php-forge/composer-asset/acme/theme",
                            "bootstrap": "^5.3"
                        },
                        "optionalDependencies": {}
                    },
                    "@composer-asset/acme--theme": {
                        "dependencies": {
                            "@popperjs/core": "^2.11.8",
                            "bootstrap": "^5.3.0"
                        },
                        "optionalDependencies": {
                            "fsevents": "^2.3"
                        }
                    }
                },
                "packages": {
                    "@composer-asset/acme--theme": {
                        "version": "1.0.0",
                        "file": "./vendor/php-forge/composer-asset/acme/theme"
                    },
                    "@popperjs/core": {
                        "version": "2.11.8",
                        "resolved": "https://registry.npmjs.org/@popperjs/core/-/core-2.11.8.tgz",
                        "integrity": "sha1-Cd9= sha512-Ef0+"
                    },
                    "bootstrap": {
                        "version": "5.3.8",
                        "resolved": "https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz",
                        "integrity": "sha512-Ab+/9w=="
                    }
                }
            }

            JSON;

        self::assertSame(
            $expected,
            file_get_contents($this->path),
            'Keys must be sorted, slashes unescaped, and the file must end with a newline.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/' . uniqid('foxy_lock_file_test_', true);
        $this->path = "{$this->root}/foxy.lock";

        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->removeDirectory($this->root);
    }

    /**
     * @return array<string, ResolvedPackage>
     */
    private function packages(): array
    {
        return [
            'bootstrap' => new ResolvedPackage('bootstrap', '5.3.8', self::BOOTSTRAP_URL, self::BOOTSTRAP_INTEGRITY),
            self::LOCAL_NAME => ResolvedPackage::fromLocal(self::LOCAL_NAME, '1.0.0', self::LOCAL_PATH),
            '@popperjs/core' => new ResolvedPackage(
                '@popperjs/core',
                '2.11.8',
                self::POPPER_URL,
                self::POPPER_INTEGRITY,
            ),
        ];
    }

    /**
     * @return array<string, array{dependencies: array<string, string>, optionalDependencies: array<string, string>}>
     */
    private function requirements(): array
    {
        return [
            self::LOCAL_NAME => [
                'dependencies' => ['bootstrap' => '^5.3.0', '@popperjs/core' => '^2.11.8'],
                'optionalDependencies' => ['fsevents' => '^2.3'],
            ],
            '' => [
                'dependencies' => ['bootstrap' => '^5.3', self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
                'optionalDependencies' => [],
            ],
        ];
    }
}
