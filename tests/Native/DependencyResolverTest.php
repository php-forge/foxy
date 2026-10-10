<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\IO\IOInterface;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{DependencyResolver, PackageMetadata, Requirement, ResolvedPackage};
use Foxy\Tests\Fixtures\Native\InMemoryRegistry;
use Foxy\Tests\Provider\DependencyResolverProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function sprintf;

/**
 * Unit tests for {@see DependencyResolver} flat resolution, re-picks, constraint retraction, warnings, and pruning.
 *
 * {@see DependencyResolverProvider} for test case data providers.
 */
final class DependencyResolverTest extends TestCase
{
    private const string TARBALL = 'https://registry.test/%1$s/-/%1$s-%2$s.tgz';

    public function testResolveAcceptsLocalVersionWithoutRegistryRequest(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['foo' => '^1.0']]]));

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0')],
            $this->resolver($registry)->resolve([new Requirement('bar', '^1.0', '')], ['foo' => '1.0.0']),
            'The local package must stay out of the result.',
        );
        self::assertSame(
            ['bar'],
            $registry->requested,
            'The local package must not be fetched.',
        );
    }

    public function testResolveCascadesRetractionToDroppedPackages(): void
    {
        $registry = $this->cascadeRegistry()
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['b' => '*']]]));

        self::assertEquals(
            [
                'a' => self::package('a', '1.0.0'),
                'b' => self::package('b', '1.0.0'),
                'm' => self::package('m', '1.0.0'),
                'z' => self::package('z', '1.0.0'),
            ],
            $this->resolver($registry)->resolve([new Requirement('a', '*', ''), new Requirement('m', '*', '')]),
            'The `^2` recorded by the dropped x@2.0.0 must not block z 1.0.0.',
        );
    }

    public function testResolveDropsOptionalConstraintBlockingMandatoryRequirement(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('o', ['1.0.0' => ['optionalDependencies' => ['x' => '^2.0']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['x' => '^1.0']]]))
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('x', '"^2.0" from o@1.0.0, "^1.0" from m@1.0.0');

        self::assertEquals(
            [
                'm' => self::package('m', '1.0.0'),
                'o' => self::package('o', '1.0.0'),
                'x' => self::package('x', '1.0.0'),
            ],
            $this->resolver($registry, $this->optionalWarning('x', '^2.0', $conflict))->resolve(
                [new Requirement('o', '^1.0', ''), new Requirement('m', '^1.0', '')],
            ),
            'The mandatory range must win and the declaring package must stay.',
        );
    }

    public function testResolveDropsOptionalSubtreeBlockingLaterMandatoryRequirement(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '^2.0']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['bar' => '^1.0']]]))
            ->add(self::metadata('bar', ['1.0.0' => [], '2.0.0' => []]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('bar', '"^2.0" from foo@1.0.0, "^1.0" from m@1.0.0');

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0'), 'm' => self::package('m', '1.0.0')],
            $this->resolver($registry, $this->optionalWarning('foo', '*', $conflict))->resolve(
                [new Requirement('foo', '*', '', true), new Requirement('m', '*', '')],
            ),
            'The mandatory `^1.0` must replace the subtree selection.',
        );
    }

    public function testResolveDropsOptionalSubtreeConflictingWithMandatoryRequirement(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '^2.0']]]))
            ->add(self::metadata('bar', ['1.0.0' => [], '2.0.0' => []]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('bar', '"^1.0" from root, "^2.0" from foo@1.0.0');

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0')],
            $this->resolver($registry, $this->optionalWarning('foo', '*', $conflict))->resolve(
                [new Requirement('foo', '*', '', true), new Requirement('bar', '^1.0', '')],
            ),
            'The optional package must go and bar 1.0.0 must stay.',
        );
    }

    public function testResolveDropsOptionalSubtreeWithUnavailableDependency(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '^1.0', 'qux' => '^1.0']]]))
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['baz' => '^1.0']]]))
            ->add(self::metadata('qux', ['1.0.0' => []]))
            ->add(self::metadata('left', ['1.0.0' => []]));

        self::assertEquals(
            ['left' => self::package('left', '1.0.0')],
            $this->resolver(
                $registry,
                $this->optionalWarning('foo', '*', Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('baz', 'memory')),
            )->resolve([new Requirement('foo', '*', '', true), new Requirement('left', '*', '')]),
            'The whole optional subtree must be dropped.',
        );
    }

    public function testResolveDropsPackagesOnlyDiscardedVersionsRequired(): void
    {
        $registry = $this->repickRegistry();

        $packages = $this->resolver($registry)->resolve(
            [new Requirement('foo', '^1.0', ''), new Requirement('bar', '^1.0', '')],
        );

        self::assertEquals(
            ['bar' => self::package('bar', '1.1.0'), 'foo' => self::package('foo', '1.0.0')],
            $packages,
            'The lower foo must replace 1.1.0 and baz must be pruned.',
        );
        self::assertSame(
            ['foo', 'bar', 'baz'],
            $registry->requested,
            'Baz is fetched while foo 1.1.0 is selected.',
        );
    }

    public function testResolveFollowsDependencyCycles(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('alpha', ['1.0.0' => ['dependencies' => ['beta' => '^1.0']]]))
            ->add(self::metadata('beta', ['1.0.0' => ['dependencies' => ['alpha' => '^1.0']]]));

        self::assertEquals(
            ['alpha' => self::package('alpha', '1.0.0'), 'beta' => self::package('beta', '1.0.0')],
            $this->resolver($registry)->resolve([new Requirement('alpha', '^1.0', '')]),
            'Both members of the cycle must be selected once.',
        );
    }

    public function testResolveInstallsNonOptionalPeerDependencies(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'widgets',
                    [
                        '1.0.0' => [
                            'peerDependencies' => [
                                'popper' => '^2.0',
                                '42' => '^1.0',
                                'react' => '^18.0',
                                '1337' => '^1.0',
                            ],
                            'peerDependenciesMeta' => ['react' => ['optional' => true], '1337' => ['optional' => true]],
                        ],
                    ],
                ),
            )
            ->add(self::metadata('popper', ['2.0.0' => []]))
            ->add(self::metadata('42', ['1.0.0' => []]));

        $packages = $this->resolver($registry)->resolve([new Requirement('widgets', '^1.0', '')]);

        self::assertSame(
            [42, 'popper', 'widgets'],
            array_keys($packages),
            'Mandatory peers, numeric names included, must be installed; optional peers must not.',
        );
        self::assertSame(
            ['widgets', 'popper', '42'],
            $registry->requested,
            'Optional peers must never be fetched.',
        );
    }

    public function testResolveKeepsLowerVersionAfterConstraintsLoosen(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('a', ['1.0.0' => [], '2.0.0' => ['dependencies' => ['x' => '^1.0']]]))
            ->add(self::metadata('b', ['1.0.0' => ['dependencies' => ['a' => '^1.0']]]))
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]));

        self::assertEquals(
            [
                'a' => self::package('a', '1.0.0'),
                'b' => self::package('b', '1.0.0'),
                'x' => self::package('x', '1.0.0'),
            ],
            $this->resolver($registry)->resolve(
                [new Requirement('a', '*', ''), new Requirement('b', '*', ''), new Requirement('x', '*', '')],
            ),
            'The retracted `^1.0` must not upgrade x.',
        );
    }

    public function testResolveKeepsSelectionThatSatisfiesLaterConstraint(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'foo',
                    [
                        '1.0.0' => [],
                        '1.1.0' => ['deprecated' => 'use bar instead', 'dependencies' => ['qux' => '^1.0']],
                    ],
                ),
            )
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['foo' => '~1.1']]]))
            ->add(self::metadata('qux', ['1.0.0' => []]));

        $io = $this->createMock(IOInterface::class);
        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<warning>The package "foo" (1.1.0) is deprecated: use bar instead</warning>');

        $packages = (new DependencyResolver($registry, $io))->resolve(
            [new Requirement('foo', '^1.0', ''), new Requirement('bar', '^1.0', '')],
        );

        self::assertEquals(
            [
                'bar' => self::package('bar', '1.0.0'),
                'foo' => self::package('foo', '1.1.0'),
                'qux' => self::package('qux', '1.0.0'),
            ],
            $packages,
            'The satisfying selection must be kept.',
        );
        self::assertSame(
            ['foo', 'bar', 'qux'],
            $registry->requested,
            'Each package must be fetched once.',
        );
    }

    public function testResolveListsSatisfiedConstraintsInConflict(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => [], '1.1.0' => [], '2.0.0' => []]))
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['foo' => '1.x', 'baz' => '^1.0']]]))
            ->add(self::metadata('baz', ['1.0.0' => ['dependencies' => ['foo' => '^2.0']]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage(
                'foo',
                '"^1.0" from root, "1.x" from bar@1.0.0, "^2.0" from baz@1.0.0',
            ),
        );

        $this->resolver($registry)->resolve([new Requirement('foo', '^1.0', ''), new Requirement('bar', '^1.0', '')]);
    }

    public function testResolveRepicksLowerVersionForLaterConstraint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage(
                'foo',
                '"^1.0" from root, ">=1.1" from root, "<1.1" from bar@1.1.0',
            ),
        );

        $this->resolver($this->repickRegistry())->resolve(
            [
                new Requirement('foo', '^1.0', ''),
                new Requirement('bar', '^1.0', ''),
                new Requirement('foo', '>=1.1', ''),
            ],
        );
    }

    public function testResolveReselectsDroppedPackage(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('a', ['1.0.0' => [], '2.0.0' => ['dependencies' => ['x' => '^2.0']]]))
            ->add(self::metadata('b', ['1.0.0' => ['dependencies' => ['a' => '^1.0', 'c' => '*']]]))
            ->add(self::metadata('c', ['1.0.0' => ['dependencies' => ['x' => '^2.0']]]))
            ->add(self::metadata('x', ['2.0.0' => ['dependencies' => ['w' => '*']]]))
            ->add(self::metadata('w', ['1.0.0' => []]));

        self::assertEquals(
            [
                'a' => self::package('a', '1.0.0'),
                'b' => self::package('b', '1.0.0'),
                'c' => self::package('c', '1.0.0'),
                'w' => self::package('w', '1.0.0'),
                'x' => self::package('x', '2.0.0'),
            ],
            $this->resolver($registry)->resolve([new Requirement('a', '*', ''), new Requirement('b', '*', '')]),
            'The reselected x must bring its dependency back.',
        );
    }

    public function testResolveRetractsConstraintsOfAbandonedVersion(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'a',
                    [
                        '1.0.0' => ['dependencies' => ['x' => '^1.0']],
                        '2.0.0' => ['dependencies' => ['x' => '^2.0']],
                    ],
                ),
            )
            ->add(self::metadata('b', ['1.0.0' => ['dependencies' => ['a' => '^1.0']]]))
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]));

        self::assertEquals(
            [
                'a' => self::package('a', '1.0.0'),
                'b' => self::package('b', '1.0.0'),
                'x' => self::package('x', '1.0.0'),
            ],
            $this->resolver($registry)->resolve(
                [new Requirement('a', '^1.0 || ^2.0', ''), new Requirement('b', '*', '')],
            ),
            'The `^2.0` declared by the abandoned a@2.0.0 must not block x 1.0.0.',
        );
        self::assertSame(
            ['a', 'b', 'x'],
            $registry->requested,
            'Each package must be fetched once.',
        );
    }

    public function testResolveReturnsEmptyListWithoutRequirements(): void
    {
        self::assertSame(
            [],
            $this->resolver(new InMemoryRegistry())->resolve([]),
            'No requirement must select nothing.',
        );
    }

    public function testResolveSelectsDistTagVersions(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'foo',
                    ['1.0.0' => [], '1.1.0' => [], '2.0.0-beta.1' => []],
                    ['latest' => '1.0.0', 'next' => '2.0.0-beta.1'],
                ),
            )
            ->add(self::metadata('bar', ['1.0.0' => [], '1.1.0' => []], ['latest' => '1.0.0']));

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0'), 'foo' => self::package('foo', '2.0.0-beta.1')],
            $this->resolver($registry)->resolve(
                [new Requirement('foo', 'next', ''), new Requirement('bar', 'latest', '')],
            ),
            'Dist-tags must select the version they point to.',
        );
    }

    public function testResolveSelectsHighestVersionsTransitively(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'widgets',
                    [
                        '1.0.0' => [],
                        '1.2.0' => ['dependencies' => ['popper' => '^2.0', '7' => '^1.0']],
                        '1.1.0' => [],
                        '2.0.0' => [],
                    ],
                ),
            )
            ->add(self::metadata('popper', ['2.1.0' => ['dependencies' => ['7' => '1.x']], '2.0.0' => []]))
            ->add(self::metadata('7', ['1.0.0' => [], '1.5.0' => ['dependencies' => ['core' => '*']]]))
            ->add(self::metadata('core', ['3.0.0' => []]));

        $packages = $this->resolver($registry)->resolve([new Requirement('widgets', '^1.0', '')]);

        self::assertEquals(
            [
                '7' => self::package('7', '1.5.0'),
                'core' => self::package('core', '3.0.0'),
                'popper' => self::package('popper', '2.1.0'),
                'widgets' => self::package('widgets', '1.2.0'),
            ],
            $packages,
            'Highest satisfying versions must be selected and sorted by name.',
        );
        self::assertSame(
            ['widgets', 'popper', '7', 'core'],
            $registry->requested,
            'Metadata must be fetched once per name.',
        );
    }

    public function testResolveSkipsConflictingOptionalDependency(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => [], '1.1.0' => []]))
            ->add(self::metadata('bar', ['1.0.0' => ['optionalDependencies' => ['foo' => '^2.0']]]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('foo', '"^1.0" from root, "^2.0" from bar@1.0.0');

        $packages = $this->resolver($registry, $this->optionalWarning('foo', '^2.0', $conflict))->resolve(
            [new Requirement('foo', '^1.0', ''), new Requirement('bar', '^1.0', '')],
        );

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0'), 'foo' => self::package('foo', '1.1.0')],
            $packages,
            'The previous selection must be kept.',
        );
    }

    public function testResolveSkipsOptionalRequirementRejectingCurrentSelection(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]))
            ->add(self::metadata('z', ['1.0.0' => ['optionalDependencies' => ['x' => '^1.0']]]))
            ->add(self::metadata('zz', ['1.0.0' => ['dependencies' => ['x' => '^2.0']]]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('x', '"*" from root, "^1.0" from z@1.0.0');

        self::assertEquals(
            [
                'x' => self::package('x', '2.0.0'),
                'z' => self::package('z', '1.0.0'),
                'zz' => self::package('zz', '1.0.0'),
            ],
            $this->resolver($registry, $this->optionalWarning('x', '^1.0', $conflict))->resolve(
                [new Requirement('x', '*', ''), new Requirement('z', '*', ''), new Requirement('zz', '*', '')],
            ),
            'x 2.0.0 must stay selected for the mandatory `^2.0`.',
        );
    }

    public function testResolveSkipsOptionalRequirementRejectingLocalVersion(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('bar', ['1.0.0' => ['optionalDependencies' => ['foo' => '^2.0']]]));

        $conflict = Message::NATIVE_LOCAL_VERSION_CONFLICT->getMessage('foo', '1.0.0', '"^2.0" from bar@1.0.0');

        self::assertEquals(
            ['bar' => self::package('bar', '1.0.0')],
            $this->resolver($registry, $this->optionalWarning('foo', '^2.0', $conflict))->resolve(
                [new Requirement('bar', '^1.0', '')],
                ['foo' => '1.0.0'],
            ),
            'The declaring package must be installed.',
        );
    }

    public function testResolveSkipsRequirementsOfDroppedPackages(): void
    {
        self::assertEquals(
            [
                'a' => self::package('a', '1.0.0'),
                'b' => self::package('b', '1.0.0'),
                'z' => self::package('z', '1.0.0'),
            ],
            $this->resolver($this->cascadeRegistry())->resolve(
                [new Requirement('a', '*', ''), new Requirement('b', '*', '')],
            ),
            'The queued `z ^2` of the dropped x@2.0.0 must be ignored.',
        );
    }

    public function testResolveSkipsUnknownOptionalDependency(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'chokidar',
                    ['3.6.0' => ['optionalDependencies' => ['fsevents' => '^2.3', 'anymatch' => '^3.0']]],
                ),
            )
            ->add(self::metadata('anymatch', ['3.1.3' => []]));

        $packages = $this->resolver(
            $registry,
            $this->optionalWarning(
                'fsevents',
                '^2.3',
                Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('fsevents', 'memory'),
            ),
        )->resolve([new Requirement('chokidar', '^3.0', '')]);

        self::assertEquals(
            ['anymatch' => self::package('anymatch', '3.1.3'), 'chokidar' => self::package('chokidar', '3.6.0')],
            $packages,
            'The unknown optional dependency must be absent and the next one resolved.',
        );
    }

    public function testResolveSkipsUnsatisfiableOptionalDependencyWithoutSelection(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('chokidar', ['3.6.0' => ['optionalDependencies' => ['fsevents' => '^3.0']]]))
            ->add(self::metadata('fsevents', ['2.3.3' => []]));

        $conflict = Message::NATIVE_VERSION_CONFLICT->getMessage('fsevents', '"^3.0" from chokidar@3.6.0');

        self::assertEquals(
            ['chokidar' => self::package('chokidar', '3.6.0')],
            $this->resolver($registry, $this->optionalWarning('fsevents', '^3.0', $conflict))->resolve(
                [new Requirement('chokidar', '^3.0', '')],
            ),
            'No other fsevents version may be selected.',
        );
    }

    #[DataProviderExternal(DependencyResolverProvider::class, 'unsupportedSpecs')]
    public function testResolveSkipsUnsupportedOptionalSpec(string $spec): void
    {
        $registry = new InMemoryRegistry();

        $packages = $this->resolver(
            $registry,
            $this->optionalWarning('widgets', $spec, Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, 'widgets')),
        )->resolve([new Requirement('widgets', $spec, '', true)]);

        self::assertSame(
            [],
            $packages,
            'The optional requirement must be skipped.',
        );
        self::assertSame(
            [],
            $registry->requested,
            'The registry must not be queried.',
        );
    }

    public function testResolveTreatsDependencyAlsoListedAsOptionalAsOptional(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'widgets',
                    [
                        '1.0.0' => [
                            'dependencies' => ['fsevents' => '^2.3'],
                            'peerDependencies' => ['fsevents' => '^2.3'],
                            'optionalDependencies' => ['fsevents' => '^2.3'],
                        ],
                    ],
                ),
            );

        self::assertEquals(
            ['widgets' => self::package('widgets', '1.0.0')],
            $this->resolver(
                $registry,
                $this->optionalWarning(
                    'fsevents',
                    '^2.3',
                    Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('fsevents', 'memory'),
                ),
            )->resolve([new Requirement('widgets', '^1.0', '')]),
            'The missing package must be skipped, not fatal.',
        );
    }

    public function testResolveUsesOptionalSpecOverDependencySpec(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'widgets',
                    ['1.0.0' => ['dependencies' => ['x' => '^1.0'], 'optionalDependencies' => ['x' => '^2.0']]],
                ),
            )
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]));

        self::assertEquals(
            ['widgets' => self::package('widgets', '1.0.0'), 'x' => self::package('x', '2.0.0')],
            $this->resolver($registry)->resolve([new Requirement('widgets', '^1.0', '')]),
            'Only the optional `^2.0` may apply.',
        );
    }

    public function testResolveUsesSatisfiableOptionalDependency(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'chokidar',
                    ['3.6.0' => ['optionalDependencies' => ['fsevents' => '^2.3', '99' => '^1.0']]],
                ),
            )
            ->add(self::metadata('fsevents', ['2.3.3' => []]))
            ->add(self::metadata('99', ['1.0.0' => []]));

        self::assertEquals(
            [
                '99' => self::package('99', '1.0.0'),
                'chokidar' => self::package('chokidar', '3.6.0'),
                'fsevents' => self::package('fsevents', '2.3.3'),
            ],
            $this->resolver($registry)->resolve([new Requirement('chokidar', '^3.0', '')]),
            'Satisfiable optional dependencies must be installed.',
        );
    }

    public function testThrowRuntimeExceptionForDistTagOnLocalPackage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_RANGE_INVALID->getMessage('latest', 'foo'),
        );

        $this->resolver(new InMemoryRegistry())->resolve([new Requirement('foo', 'latest', '')], ['foo' => '1.0.0']);
    }

    public function testThrowRuntimeExceptionForUnknownDistTag(): void
    {
        $registry = (new InMemoryRegistry())->add(self::metadata('foo', ['1.0.0' => []], ['latest' => '1.0.0']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_RANGE_INVALID->getMessage('beta', 'foo'),
        );

        $this->resolver($registry)->resolve([new Requirement('foo', 'beta', '')]);
    }

    #[DataProviderExternal(DependencyResolverProvider::class, 'unsupportedSpecs')]
    public function testThrowRuntimeExceptionForUnsupportedSpec(string $spec): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, 'widgets'),
        );

        $this->resolver(new InMemoryRegistry())->resolve([new Requirement('widgets', $spec, '')]);
    }

    public function testThrowRuntimeExceptionWhenDroppedOptionalRootIsRequiredAgain(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'foo',
                    [
                        '1.0.0' => ['dependencies' => ['bar' => '^1.0']],
                        '2.0.0' => ['dependencies' => ['baz' => '^1.0']],
                    ],
                ),
            )
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['foo' => '^2.0']]]));

        $resolver = $this->resolver(
            $registry,
            $this->optionalWarning('foo', '^1.0', Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('bar', 'memory')),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('baz', 'memory'),
        );

        $resolver->resolve([new Requirement('foo', '^1.0', '', true), new Requirement('m', '*', '')]);
    }

    public function testThrowRuntimeExceptionWhenMandatoryConstraintsConflictWithoutOptionalOnes(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('o', ['1.0.0' => ['optionalDependencies' => ['x' => '^2.0']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['x' => '^3.0']]]))
            ->add(self::metadata('x', ['1.0.0' => [], '2.0.0' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage('x', '"^3.0" from m@1.0.0'),
        );

        $this->resolver($registry)->resolve([new Requirement('o', '^1.0', ''), new Requirement('m', '^1.0', '')]);
    }

    public function testThrowRuntimeExceptionWhenMandatoryRootSubtreeBlocksMandatoryRequirement(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '^2.0']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['bar' => '^1.0']]]))
            ->add(self::metadata('bar', ['1.0.0' => [], '2.0.0' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage('bar', '"^2.0" from foo@1.0.0, "^1.0" from m@1.0.0'),
        );

        $this->resolver($registry)->resolve(
            [new Requirement('foo', '*', '', true), new Requirement('m', '*', ''), new Requirement('foo', '*', '')],
        );
    }

    public function testThrowRuntimeExceptionWhenNoVersionSatisfiesEveryConstraint(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => [], '2.0.0' => []]))
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['foo' => '^1.0']]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage('foo', '"^2.0" from root, "^1.0" from bar@1.0.0'),
        );

        $this->resolver($registry)->resolve([new Requirement('foo', '^2.0', ''), new Requirement('bar', '^1.0', '')]);
    }

    public function testThrowRuntimeExceptionWhenOptionalSubtreeFailsUnderMandatoryRoot(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '^1.0']]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('bar', 'memory'),
        );

        $this->resolver($registry)->resolve(
            [new Requirement('foo', '*', '', true), new Requirement('foo', '^1.0', '')],
        );
    }

    public function testThrowRuntimeExceptionWhenOptionalSubtreeFailsUnderSharedPackage(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '*']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['bar' => '*']]]))
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['baz' => '^1.0']]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('baz', 'memory'),
        );

        $this->resolver($registry)->resolve([new Requirement('foo', '*', '', true), new Requirement('m', '*', '')]);
    }

    public function testThrowRuntimeExceptionWhenOrphanedSubtreeConstraintBlocksMandatoryRequirement(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => ['dependencies' => ['bar' => '*', 'x' => '*']]]))
            ->add(self::metadata('m', ['1.0.0' => ['dependencies' => ['bar' => '*', 'n' => '*']]]))
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['z' => '^2.0']]]))
            ->add(self::metadata('x', ['1.0.0' => ['dependencies' => ['qux' => '^1.0']]]))
            ->add(self::metadata('n', ['1.0.0' => ['dependencies' => ['z' => '^1.0']]]))
            ->add(self::metadata('z', ['1.0.0' => [], '2.0.0' => []]));

        $resolver = $this->resolver(
            $registry,
            $this->optionalWarning('foo', '*', Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('qux', 'memory')),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage('z', '"^2.0" from bar@1.0.0, "^1.0" from n@1.0.0'),
        );

        $resolver->resolve([new Requirement('foo', '*', '', true), new Requirement('m', '*', '')]);
    }

    public function testThrowRuntimeExceptionWhenPackageIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('foo', 'memory'),
        );

        $this->resolver(new InMemoryRegistry())->resolve([new Requirement('foo', '^1.0', '')]);
    }

    public function testThrowRuntimeExceptionWhenRangeRejectsLocalVersion(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(self::metadata('bar', ['1.0.0' => ['dependencies' => ['foo' => '^2.0']]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_LOCAL_VERSION_CONFLICT->getMessage('foo', '1.0.0', '"^2.0" from bar@1.0.0'),
        );

        $this->resolver($registry)->resolve([new Requirement('bar', '^1.0', '')], ['foo' => '1.0.0']);
    }

    public function testThrowRuntimeExceptionWhenRepickReturnsToAbandonedVersion(): void
    {
        $registry = (new InMemoryRegistry())
            ->add(
                self::metadata(
                    'alpha',
                    [
                        '1.0.0' => ['dependencies' => ['beta' => '2.0.0']],
                        '2.0.0' => ['dependencies' => ['beta' => '1.0.0']],
                    ],
                ),
            )
            ->add(
                self::metadata(
                    'beta',
                    [
                        '1.0.0' => ['dependencies' => ['alpha' => '1.0.0']],
                        '2.0.0' => ['dependencies' => ['alpha' => '2.0.0']],
                    ],
                ),
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_VERSION_CONFLICT->getMessage('beta', '"*" from root, "2.0.0" from alpha@1.0.0'),
        );

        $this->resolver($registry)->resolve([new Requirement('alpha', '*', ''), new Requirement('beta', '*', '')]);
    }

    /**
     * Returns a registry where only a@2.0.0 requires x, x@2.0.0 requires `z ^2.0`, and b@1.0.0 requires `a ^1.0` and
     * `z ^1.0`.
     */
    private function cascadeRegistry(): InMemoryRegistry
    {
        return (new InMemoryRegistry())
            ->add(self::metadata('a', ['1.0.0' => [], '2.0.0' => ['dependencies' => ['x' => '^2.0']]]))
            ->add(self::metadata('b', ['1.0.0' => ['dependencies' => ['a' => '^1.0', 'z' => '^1.0']]]))
            ->add(self::metadata('x', ['2.0.0' => ['dependencies' => ['z' => '^2.0']]]))
            ->add(self::metadata('z', ['1.0.0' => [], '2.0.0' => []]));
    }

    /**
     * @param array<string, array<string, mixed>> $versions Version entries without `dist`, keyed by version.
     * @param array<string, string> $distTags
     */
    private static function metadata(string $name, array $versions, array $distTags = []): PackageMetadata
    {
        $entries = [];

        foreach ($versions as $version => $entry) {
            $entries[$version] = $entry + [
                'dist' => ['tarball' => sprintf(self::TARBALL, $name, $version), 'integrity' => 'sha512-AAAA'],
            ];
        }

        return PackageMetadata::fromDocument($name, ['dist-tags' => $distTags, 'versions' => $entries]);
    }

    private function optionalWarning(string $name, string $spec, string $reason): IOInterface
    {
        $io = $this->createMock(IOInterface::class);
        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                sprintf('<warning>The optional dependency "%s" (%s) was skipped: %s</warning>', $name, $spec, $reason),
            );

        return $io;
    }

    private static function package(string $name, string $version): ResolvedPackage
    {
        return new ResolvedPackage($name, $version, sprintf(self::TARBALL, $name, $version), 'sha512-AAAA');
    }

    /**
     * Returns a registry where bar@1.1.0 requires `foo <1.1` and only foo@1.1.0 requires baz.
     */
    private function repickRegistry(): InMemoryRegistry
    {
        return (new InMemoryRegistry())
            ->add(self::metadata('foo', ['1.0.0' => [], '1.1.0' => ['dependencies' => ['baz' => '^1.0']]]))
            ->add(self::metadata('bar', ['1.0.0' => [], '1.1.0' => ['dependencies' => ['foo' => '<1.1']]]))
            ->add(self::metadata('baz', ['1.0.0' => []]));
    }

    private function resolver(InMemoryRegistry $registry, IOInterface|null $io = null): DependencyResolver
    {
        if (null === $io) {
            $io = $this->createMock(IOInterface::class);
            $io->expects(self::never())->method('writeError');
        }

        return new DependencyResolver($registry, $io);
    }
}
