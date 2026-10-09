<?php

declare(strict_types=1);

namespace Foxy\Tests\Exception;

use Foxy\Exception\Message;
use Foxy\Tests\Provider\MessageProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

/**
 * Unit tests for {@see Message} template rendering against hand-written literals for every case.
 *
 * {@see MessageProvider} for test case data providers.
 */
final class MessageTest extends TestCase
{
    public function testEveryCaseIsPinned(): void
    {
        $pinned = [];

        foreach (MessageProvider::cases() as [$case]) {
            $pinned[] = $case->name;
        }

        $declared = array_map(static fn(Message $message): string => $message->name, Message::cases());

        sort($pinned);
        sort($declared);

        self::assertSame(
            $declared,
            $pinned,
            'Every case needs exactly one provider row.',
        );
    }

    /**
     * @param list<int|string> $arguments
     */
    #[DataProviderExternal(MessageProvider::class, 'cases')]
    public function testGetMessageRendersTemplate(Message $case, array $arguments, string $expected): void
    {
        self::assertSame(
            $expected,
            $case->getMessage(...$arguments),
            'Rendered message must match the pinned literal.',
        );
    }
}
