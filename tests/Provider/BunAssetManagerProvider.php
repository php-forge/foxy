<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Exception\Message;

/**
 * Data provider for {@see \Foxy\Tests\Asset\BunAssetManagerTest} test cases.
 */
final class BunAssetManagerProvider
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function benignAuditConfigurations(): iterable
    {
        yield 'escaped non-scope npmrc value' => [
            '.npmrc',
            '"cache" = "C:\\npm-cache"' . "\nomit=dev\n",
            true,
        ];
        yield 'unrelated Bun install settings and table' => [
            'bunfig.toml',
            "[install]\nnotoptional = false\nnotinstall = { optional = false }\n"
            . "[other]\noptional = false\n",
            false,
        ];
        yield 'TOML comment containing an unmatched container' => [
            'bunfig.toml',
            "[install]\nnote = [\"\"] # [\noptional = true\n",
            false,
        ];
        yield 'escaped TOML string before an unmatched comment container' => [
            'bunfig.toml',
            "[install]\nnote = [\"escaped \\\" quote\"] # [\noptional = true\n",
            false,
        ];
        yield 'TOML string containing a comment character' => [
            'bunfig.toml',
            "[install]\nnote = [\"value#suffix\"]\noptional = true\n",
            false,
        ];
        yield 'unquoted npmrc key containing a backslash' => [
            '.npmrc',
            "unquoted\\key=value\n",
            false,
        ];
        yield 'unterminated TOML string outside a container' => [
            'bunfig.toml',
            "[install]\nnote = \"unterminated\noptional = true\n",
            false,
        ];
    }

    /**
     * @return iterable<string, array{string, string, bool, string}>
     */
    public static function restrictiveAuditConfigurations(): iterable
    {
        yield 'npmrc development omission for a full audit' => [
            '.npmrc',
            'omit=dev',
            false,
            'omit=dev',
        ];
        yield 'npmrc optional omission for a production audit' => [
            '.npmrc',
            'omit[]=optional',
            true,
            'omit=optional',
        ];
        yield 'dynamic npmrc omission' => [
            '.npmrc',
            'omit=${AUDIT_OMIT}',
            true,
            'dynamic omit=${AUDIT_OMIT}',
        ];
        yield 'BOM-prefixed npmrc omission' => [
            '.npmrc',
            "\xEF\xBB\xBFomit=peer",
            true,
            'omit=peer',
        ];
        yield 'double-quoted npmrc omission key' => [
            '.npmrc',
            '"omit"=optional',
            true,
            'omit=optional',
        ];
        yield 'single-quoted npmrc omission key' => [
            '.npmrc',
            "'omit'=peer",
            true,
            'omit=peer',
        ];
        yield 'quoted npmrc omission array key' => [
            '.npmrc',
            '"omit[]"=optional',
            true,
            'omit=optional',
        ];
        yield 'multiline uppercase npmrc omission' => [
            '.npmrc',
            "\n # comment\n ; comment\nstrict-ssl\nregistry=https://registry.npmjs.org/\n"
            . " OMIT = [\"OPTIONAL\"]\n",
            true,
            'omit=optional',
        ];
        yield 'padded dynamic npmrc omission' => [
            '.npmrc',
            'omit =  ${AUDIT_OMIT}  ',
            true,
            'dynamic omit=${AUDIT_OMIT}',
        ];
        yield 'bunfig production mode for a full audit' => [
            'bunfig.toml',
            "[install]\nproduction = true\n",
            false,
            'install.production=true',
        ];
        yield 'bunfig development exclusion for a full audit' => [
            'bunfig.toml',
            "[install]\ndev = false\n",
            false,
            'install.dev=false',
        ];
        yield 'dotted bunfig optional exclusion' => [
            'bunfig.toml',
            'install.optional = false',
            true,
            'install.optional=false',
        ];
        yield 'bunfig peer exclusion' => [
            'bunfig.toml',
            "[install]\npeer = false\n",
            true,
            'install.peer=false',
        ];
        yield 'BOM-prefixed bunfig optional exclusion' => [
            'bunfig.toml',
            "\xEF\xBB\xBF[install]\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'comment after bunfig install table' => [
            'bunfig.toml',
            "[install] # comment\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'quoted bunfig install table' => [
            'bunfig.toml',
            "[\"install\"]\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'double-quoted bunfig install table with comment' => [
            'bunfig.toml',
            "[\"install\"] # comment\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'single-quoted bunfig install table with comment' => [
            'bunfig.toml',
            "['install'] # comment\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'spaced bunfig install table' => [
            'bunfig.toml',
            "[ install ]\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'blank line before bunfig restriction' => [
            'bunfig.toml',
            "\n[install]\noptional = false\n",
            true,
            'install.optional=false',
        ];
        yield 'double-BOM-prefixed bunfig optional exclusion' => [
            'bunfig.toml',
            "\xEF\xBB\xBF\xEF\xBB\xBF[install]\noptional = false\n",
            true,
            'install.optional=false',
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unverifiableAuditConfigurations(): iterable
    {
        yield 'escaped table key' => [
            'bunfig.toml',
            "[\"in\\u0073tall\"]\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES->getMessage(),
        ];
        yield 'escaped dependency key' => [
            'bunfig.toml',
            "[install]\n\"option\\u0061l\" = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES->getMessage(),
        ];
        yield 'unquoted escaped dependency key' => [
            'bunfig.toml',
            "[install]\n\\optional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES->getMessage(),
        ];
        yield 'hex-escaped table and dependency keys' => [
            'bunfig.toml',
            "[\"in\\x73tall\"]\n\"option\\x61l\" = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES->getMessage(),
        ];
        yield 'single-line inline install table' => [
            'bunfig.toml',
            'install = { optional = false }',
            Message::ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE->getMessage(),
        ];
        yield 'multiline inline install table' => [
            'bunfig.toml',
            "install = {\n optional = false,\n}\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE->getMessage(),
        ];
        yield 'array install table' => [
            'bunfig.toml',
            "[[install]]\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_ARRAY_INSTALL_TABLE->getMessage(),
        ];
        yield 'multiline basic string in install table' => [
            'bunfig.toml',
            "[install]\nnote = \"\"\"\n[not-a-table]\n\"\"\"\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING->getMessage(),
        ];
        yield 'multiline literal string in install table' => [
            'bunfig.toml',
            "[install]\nnote = '''\n[not-a-table]\n'''\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING->getMessage(),
        ];
        yield 'multiline array in install table' => [
            'bunfig.toml',
            "[install]\nnote = [\n  [1],\n]\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'multiline array without assignment whitespace' => [
            'bunfig.toml',
            "[install]\nnote=[\n  1,\n]\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'multiline inline table without assignment whitespace' => [
            'bunfig.toml',
            "[install]\nnote={\n  value = true,\n}\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'mismatched container closer' => [
            'bunfig.toml',
            "[install]\nnote = [}\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'unexpected container closer' => [
            'bunfig.toml',
            "[install]\nnote = []]\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'unterminated string in an install container' => [
            'bunfig.toml',
            "[install]\nnote = [\"unterminated]\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'unterminated literal string in an install container' => [
            'bunfig.toml',
            "[install]\nnote = ['unterminated]\noptional = false\n",
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
        ];
        yield 'UTF-16LE bunfig' => [
            'bunfig.toml',
            "\xFF\xFE[\0i\0n\0s\0t\0a\0l\0l\0]\0\n\0o\0p\0t\0i\0o\0n\0a\0l\0=\0f\0a\0l\0s\0e\0",
            Message::ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED->getMessage(),
        ];
        yield 'UTF-16LE npmrc' => [
            '.npmrc',
            "\xFF\xFEo\0m\0i\0t\0=\0o\0p\0t\0i\0o\0n\0a\0l\0",
            Message::ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED->getMessage(),
        ];
        yield 'hex-escaped npmrc omit key' => [
            '.npmrc',
            '"om\\x69t"=optional',
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES->getMessage(),
        ];
        yield 'Unicode-escaped npmrc omit key' => [
            '.npmrc',
            '"om\\u0069t"=optional',
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES->getMessage(),
        ];
        yield 'single-quoted escaped npmrc omit key' => [
            '.npmrc',
            "'om\\u0069t'=optional",
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES->getMessage(),
        ];
        yield 'indented escaped npmrc omit key' => [
            '.npmrc',
            '  "om\\u0069t"=optional',
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES->getMessage(),
        ];
        yield 'hex-escaped npmrc omit value' => [
            '.npmrc',
            'omit="opti\\x6fnal"',
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES->getMessage(),
        ];
        yield 'Unicode-escaped npmrc omit value' => [
            '.npmrc',
            'omit="opti\\u006fnal"',
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES->getMessage(),
        ];
    }
}
