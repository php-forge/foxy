<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Config;
use Composer\IO\{ConsoleIO, IOInterface};
use Foxy\Util\ConsoleUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\{ArgvInput, InputInterface};
use Symfony\Component\Console\Output\NullOutput;

final class ConsoleUtilTest extends TestCase
{
    public static function getPreferredInstallOptionsData(): array
    {
        return [
            [false, false, 'auto', false],
            [false, true, 'auto', [false, true]],
            [true, false, 'source', false],
            [true, false, 'source', [false, null]],
            [false, true, 'dist', false],
            [true, false, 'auto', [1, 0]],
        ];
    }

    public function testGetInput(): void
    {
        $input = new ArgvInput();
        $output = new NullOutput();
        $helperSet = new HelperSet();
        $io = new ConsoleIO($input, $output, $helperSet);

        self::assertSame($input, ConsoleUtil::getInput($io));
    }

    public function testGetInputWithoutValidInput(): void
    {
        $io = $this->createMock(IOInterface::class);

        self::assertInstanceOf(ArgvInput::class, ConsoleUtil::getInput($io));
    }

    #[DataProvider('getPreferredInstallOptionsData')]
    public function testGetPreferredInstallOptions(
        bool $expectedPreferSource,
        bool $expectedPreferDist,
        string $preferredInstall,
        mixed $inputPrefer,
    ): void {
        $config = $this->createMock(Config::class);
        $input = $this->createMock(InputInterface::class);

        $config->expects(self::once())->method('get')->with('preferred-install')->willReturn($preferredInstall);

        if (is_array($inputPrefer)) {
            $input->expects(self::atLeastOnce())
                ->method('getOption')
                ->willReturnCallback(
                    static fn($option): mixed => 'prefer-source' === $option ? $inputPrefer[0] : $inputPrefer[1],
                )
            ;
        }

        $res = ConsoleUtil::getPreferredInstallOptions($config, $input);

        self::assertSame([$expectedPreferSource, $expectedPreferDist], $res);
    }
}
