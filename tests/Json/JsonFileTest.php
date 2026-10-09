<?php

declare(strict_types=1);

namespace Foxy\Tests\Json;

use Exception;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Json\JsonFile;
use Foxy\Tests\Support\JsonFixture;
use PHPForge\Support\LineEndingNormalizer;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem;
use UnexpectedValueException;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_get_contents;
use function sprintf;

use const DIRECTORY_SEPARATOR;

final class JsonFileTest extends TestCase
{
    use JsonFixture;

    private string|null $cwd = '';
    private string|null $oldCwd = '';
    private Filesystem|null $sfs = null;

    /**
     * @throws Exception
     */
    public function testEncodeAndWriteEmptyManifestAsObject(): void
    {
        self::assertSame(
            '{}',
            JsonFile::encode([]),
            'Encoding an empty array should result in an empty JSON object.',
        );

        $jsonFile = new JsonFile('./empty-package.json');

        $jsonFile->write([]);

        self::assertSame(
            "{}\n",
            file_get_contents('./empty-package.json'),
            'The empty package JSON file should contain an empty JSON object followed by a newline.',
        );
    }

    public function testEncodeUsesCustomOptionsWithoutReformatting(): void
    {
        self::assertSame(
            '{"url":"https://example.com"}',
            JsonFile::encode(['url' => 'https://example.com'], JSON_UNESCAPED_SLASHES),
            'Encoding with custom options should not reformat the JSON.',
        );
    }

    public function testEncodeUsesDefaultOptions(): void
    {
        self::assertSame(
            self::fixtureWithoutFinalNewline('encoded-default-four-space.json'),
            JsonFile::encode(['html' => '<tag>', 'url' => 'https://example.com/é']),
            'Encoding with default options should use four-space indentation without reformatting.',
        );
    }

    public function testGetArrayKeysThrowsWhenFileCannotBeRead(): void
    {
        $filename = './package.json';

        file_put_contents($filename, '{}');

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before attempting to read it.',
        );

        MockerState::addCondition(
            'Foxy\\Json',
            'file_get_contents',
            [$filename, false, null, 0, null],
            false,
        );

        $jsonFile = new JsonFile($filename);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::JSON_FILE_UNREADABLE->getMessage($jsonFile->getPath()),
        );

        $jsonFile->getArrayKeys();
    }

    public function testGetArrayKeysWithExistingFile(): void
    {
        $expected = ['contributors'];

        $content = self::fixture('package-two-space.json');

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before attempting to read it.',
        );

        $jsonFile = new JsonFile($filename);

        self::assertSame(
            $expected,
            $jsonFile->getArrayKeys(),
            'The array keys should match the expected keys.',
        );
    }

    public function testGetArrayKeysWithoutFile(): void
    {
        $filename = './package.json';

        $jsonFile = new JsonFile($filename);

        self::assertSame(
            [],
            $jsonFile->getArrayKeys(),
            'The array keys should be empty when the file does not exist.',
        );
    }

    public function testGetIndentWithExistingFile(): void
    {
        $content = self::fixture('name-two-space.json');

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before attempting to read it.',
        );

        $jsonFile = new JsonFile($filename);

        self::assertSame(
            2,
            $jsonFile->getIndent(),
            'The indent should match the expected value.',
        );
    }

    public function testGetIndentWithoutFile(): void
    {
        $filename = './package.json';

        $jsonFile = new JsonFile($filename);

        self::assertSame(
            4,
            $jsonFile->getIndent(),
            'The indent should default to four spaces when the file does not exist.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testReadCachesOriginalCollectionRepresentations(): void
    {
        file_put_contents('./package.json', '{"metadata":{}}');

        $jsonFile = new JsonFile('./package.json');

        $data = $jsonFile->read();

        file_put_contents('./package.json', '{"metadata":[]}');

        $jsonFile->write($data);

        self::assertStringContainsString(
            '"metadata": {}',
            (string) file_get_contents('./package.json'),
        );
    }

    public function testWriteClearsEncodingStateAfterFailure(): void
    {
        file_put_contents('./not-a-directory', 'blocked');

        $jsonFile = new JsonFile('./not-a-directory/package.json');

        try {
            $jsonFile->write(['custom' => []]);
            self::fail('Expected writing below a file path to fail.');
        } catch (UnexpectedValueException) {
        }

        self::assertStringContainsString(
            '"custom": {}',
            JsonFile::encode(['custom' => []]),
            'The encoding state should be cleared after a failed write attempt.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWriteForcesFourSpacesIndentWithExistingTwoSpaceFile(): void
    {
        $expected = self::fixture('name-private-four-space.json');
        $content = self::fixture('name-two-space.json');

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before attempting to read it.',
        );

        $jsonFile = new JsonFile($filename);

        $data = $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist after writing.',
        );

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
            'The content of the package JSON file should match the expected content after writing.',
        );
    }

    /**
     * @throws Exception
     */
    public function testWritePreservesAllPackageMapKeysAsObjects(): void
    {
        $mapKeys = [
            'dependencies',
            'devDependencies',
            'optionalDependencies',
            'overrides',
            'peerDependencies',
            'peerDependenciesMeta',
            'resolutions',
        ];
        $data = array_fill_keys($mapKeys, []);

        $jsonFile = new JsonFile('./package.json');

        $jsonFile->write($data);

        $content = (string) file_get_contents('./package.json');

        foreach ($mapKeys as $key) {
            self::assertStringContainsString(
                sprintf('"%s": {}', $key),
                $content,
                "The content of the package JSON file should contain the key: {$key}",
            );
        }
    }

    /**
     * @throws Exception
     */
    public function testWritePreservesCustomMapWithoutPriorRead(): void
    {
        file_put_contents('./package.json', '{"metadata":{}}');

        $jsonFile = new JsonFile('./package.json');

        $jsonFile->write(['metadata' => []]);

        self::assertStringContainsString(
            '"metadata": {}',
            (string) file_get_contents('./package.json'),
            'The content of the package JSON file should contain the metadata key.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWritePreservesNestedEmptyArraysWithoutSpaces(): void
    {
        $content = '{"name":"test","workspaces":[],"overrides":{"pkg":{"files":[]}},"dependencies":{}}';
        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before attempting to read it.',
        );

        $jsonFile = new JsonFile($filename);

        $data = $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist after writing.',
        );

        $content = file_get_contents($filename);

        self::assertStringContainsString(
            '"workspaces": []',
            $content,
            'The content of the package JSON file should contain the workspaces key.',
        );
        self::assertStringContainsString(
            '"files": []',
            $content,
            'The content of the package JSON file should contain the files key.',
        );
        self::assertStringContainsString(
            '"dependencies": {}',
            $content,
            'The content of the package JSON file should contain the dependencies key.',
        );
        self::assertMatchesRegularExpression(
            '/^ {4}"dependencies": \{\}/m',
            $content,
            'The content of the package JSON file should have the dependencies key indented with four spaces.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWritePreservesNewNestedEmptyArray(): void
    {
        file_put_contents('./package.json', '{"dependencies":{}}');

        $jsonFile = new JsonFile('./package.json');

        $data = $jsonFile->read();

        $data['metadata'] = ['files' => []];

        $jsonFile->write($data);

        $content = (string) file_get_contents('./package.json');

        self::assertStringContainsString(
            '"dependencies": {}',
            $content,
            'The content of the package JSON file should contain the dependencies key.',
        );
        self::assertStringContainsString(
            '"files": []',
            $content,
            'The content of the package JSON file should contain the files key.',
        );
    }

    /**
     * @throws Exception
     */
    public function testWriteUsesDefaultOptions(): void
    {
        $jsonFile = new JsonFile('./package.json');
        $jsonFile->write(['html' => '<tag>']);

        self::assertStringContainsString(
            '"html": "<tag>"',
            (string) file_get_contents('./package.json'),
            'The content of the package JSON file should contain the html key.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWriteWithExistingFile(): void
    {
        $expected = self::fixture('package-private-four-space.json');
        $content = self::fixture('package-two-space.json');

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist before writing to it.',
        );

        $jsonFile = new JsonFile($filename);

        $data = $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist after writing to it.',
        );

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
            'The content of the package JSON file should match the expected content.',
        );
    }

    /**
     * @throws Exception
     */
    public function testWriteWithoutFile(): void
    {
        $expected = self::fixture('name-four-space.json');

        $filename = './package.json';
        $data = ['name' => 'test'];

        $jsonFile = new JsonFile($filename);

        $jsonFile->write($data);

        self::assertFileExists(
            $filename,
            'The package JSON file should exist after writing to it.',
        );

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
            'The content of the package JSON file should match the expected content.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_json_file_test_', true);
        $this->sfs = new Filesystem();
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->sfs = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }
}
