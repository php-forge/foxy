<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Config;
use Composer\IO\{ConsoleIO, IOInterface};
use Foxy\Tests\Provider\ConsoleUtilProvider;
use Foxy\Util\ConsoleUtil;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\{ArgvInput, InputInterface};
use Symfony\Component\Console\Output\NullOutput;

/**
 * Unit tests for {@see ConsoleUtil} console input resolution and preferred install options.
 *
 * {@see ConsoleUtilProvider} for test case data providers.
 */
final class ConsoleUtilTest extends TestCase
{
    public function testGetInput(): void
    {
        $input = new ArgvInput();
        $output = new NullOutput();
        $helperSet = new HelperSet();
        $io = new ConsoleIO($input, $output, $helperSet);

        self::assertSame(
            $input,
            ConsoleUtil::getInput($io),
            'The resolved input instance does not match the expected one.',
        );
    }

    public function testGetInputWithoutValidInput(): void
    {
        $io = $this->createMock(IOInterface::class);

        self::assertInstanceOf(
            ArgvInput::class,
            ConsoleUtil::getInput($io),
            'The resolved input instance does not match the expected one.',
        );
    }

    #[DataProviderExternal(ConsoleUtilProvider::class, 'preferredInstallOptions')]
    public function testGetPreferredInstallOptions(
        bool $expectedPreferSource,
        bool $expectedPreferDist,
        string $preferredInstall,
        mixed $inputPrefer,
    ): void {
        $config = $this->createMock(Config::class);
        $input = $this->createMock(InputInterface::class);

        $config
            ->expects(self::once())
            ->method('get')
            ->with('preferred-install')
            ->willReturn($preferredInstall);

        if (is_array($inputPrefer)) {
            $input->expects(self::atLeastOnce())
                ->method('getOption')
                ->willReturnCallback(
                    static fn($option): mixed => 'prefer-source' === $option ? $inputPrefer[0] : $inputPrefer[1],
                )
            ;
        }

        $res = ConsoleUtil::getPreferredInstallOptions($config, $input);

        self::assertSame(
            [$expectedPreferSource, $expectedPreferDist],
            $res,
            'The resolved preferred install options do not match the expected ones.',
        );
    }
}
