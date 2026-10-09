<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Config\ConfigTest} test cases.
 */
final class ConfigProvider
{
    /**
     * @return iterable<int, array{0: string, 1: list<int>, 2: list<int>, 3?: array<string, list<int>>}>
     */
    public static function arrayConfigValues(): iterable
    {
        yield ['foo', [], []];
        yield ['foo', [42], [42]];
        yield ['foo', [42], [], ['foo' => [42]]];
    }

    /**
     * @return iterable<int, array{0: string, 1: mixed, 2: mixed, 3?: string|null, 4?: array<string, string>}>
     */
    public static function configValues(): iterable
    {
        yield [
            'foo',
            42,
            42,
        ];
        yield [
            'bar',
            'foo',
            'empty',
        ];
        yield [
            'baz',
            false,
            true,
        ];
        yield [
            'test',
            0,
            0,
        ];
        yield [
            'manager-bar',
            23,
            0,
        ];
        yield [
            'manager-baz',
            0,
            0,
        ];
        yield [
            'global-composer-foo',
            90,
            0,
        ];
        yield [
            'global-composer-bar',
            70,
            0,
        ];
        yield [
            'global-config-foo',
            23,
            0,
        ];
        yield [
            'env-boolean',
            false,
            true,
            'FOXY__ENV_BOOLEAN=false',
        ];
        yield [
            'env-boolean-uppercase',
            true,
            false,
            'FOXY__ENV_BOOLEAN_UPPERCASE=TRUE',
        ];
        yield [
            'env-integer',
            -32,
            0,
            'FOXY__ENV_INTEGER=-32',
        ];
        yield [
            'env-invalid-integer',
            '--1',
            0,
            'FOXY__ENV_INVALID_INTEGER=--1',
        ];
        yield [
            'env-integer-suffix',
            '32px',
            0,
            'FOXY__ENV_INTEGER_SUFFIX=32px',
        ];
        yield [
            'env-json',
            ['foo' => 'bar'],
            [],
            'FOXY__ENV_JSON="{"foo": "bar"}"',
        ];
        yield [
            'env-json-array',
            [['foo' => 'bar']],
            [],
            'FOXY__ENV_JSON_ARRAY="[{"foo": "bar"}]"',
        ];
        yield [
            'env-json-multiple',
            ['foo' => 'bar', 'baz' => 'qux'],
            [],
            'FOXY__ENV_JSON_MULTIPLE={"foo":"bar","baz":"qux"}',
        ];
        yield [
            'env-string',
            'baz',
            'foo',
            'FOXY__ENV_STRING=baz',
        ];
        yield [
            'env-padded-string',
            'baz',
            'foo',
            'FOXY__ENV_PADDED_STRING=  baz  ',
        ];
        yield [
            'env-single-quoted-string',
            'baz',
            'foo',
            "FOXY__ENV_SINGLE_QUOTED_STRING='baz'",
        ];
        yield [
            'env-double-quoted-string',
            'baz',
            'foo',
            'FOXY__ENV_DOUBLE_QUOTED_STRING="baz"',
        ];
        yield [
            'test-p1',
            'def',
            'def',
            null,
            [],
        ];
        yield [
            'test-p1',
            'def',
            'def',
            null,
            ['test-p1' => 'ok'],
        ];
        yield [
            'test-p1',
            'ok',
            null,
            null,
            ['test-p1' => 'ok'],
        ];
    }

    /**
     * @return iterable<string, array{bool|int|string|null, bool}>
     */
    public static function enabledValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'integer one' => [1, true];
        yield 'string one' => ['1', true];
        yield 'boolean false' => [false, false];
        yield 'integer zero' => [0, false];
        yield 'string zero' => ['0', false];
        yield 'other integer' => [2, false];
        yield 'other string' => ['true', false];
        yield 'null' => [null, false];
    }
}
