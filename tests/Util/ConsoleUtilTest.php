<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Util;

use Composer\Config;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Foxy\Util\ConsoleUtil;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for console util.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class ConsoleUtilTest extends \PHPUnit\Framework\TestCase
{
    public function testGetInput(): void
    {
        $input = new ArgvInput();
        $output = new NullOutput();
        $helperSet = new HelperSet();
        $io = new ConsoleIO($input, $output, $helperSet);

        $this->assertSame($input, ConsoleUtil::getInput($io));
    }

    public function testGetInputWithoutValidInput(): void
    {
        /** @var IOInterface $io */
        $io = $this->createMock(IOInterface::class);

        $this->assertInstanceOf(ArgvInput::class, ConsoleUtil::getInput($io));
    }

    public static function getPreferredInstallOptionsData(): array
    {
        return [
            [false, false, 'auto', false],
            [false, true, 'auto', true],
            [true, false, 'source', false],
            [false, true, 'dist', false],
        ];
    }

    /**
     * @dataProvider getPreferredInstallOptionsData
     */
    public function testGetPreferredInstallOptions(
        bool $expectedPreferSource,
        bool $expectedPreferDist,
        string $preferedInstall,
        bool $inputPrefer
    ): void {
        $config = $this->createMock(Config::class);
        $input = $this->createMock(InputInterface::class);

        $config->expects($this->once())->method('get')->with('preferred-install')->willReturn($preferedInstall);

        if ($inputPrefer) {
            $input->expects($this->atLeastOnce())
                ->method('getOption')
                ->willReturnCallback(static fn ($option) => !('prefer-source' === $option))
            ;
        }

        $res = ConsoleUtil::getPreferredInstallOptions($config, $input);

        $this->assertEquals([$expectedPreferSource, $expectedPreferDist], $res);
    }
}
