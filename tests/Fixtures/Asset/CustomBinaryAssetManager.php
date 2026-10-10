<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Asset;

use Foxy\Asset\AbstractAssetManager;
use Foxy\Audit\AuditParserInterface;
use Foxy\Audit\Parser\NpmAuditParser;

final class CustomBinaryAssetManager extends AbstractAssetManager
{
    public function getAuditParser(): AuditParserInterface
    {
        return new NpmAuditParser();
    }

    public function getInstallCommandForTest(): string
    {
        return $this->getInstallCommand();
    }

    public function getLockPackageName(): string
    {
        return 'custom.lock';
    }

    public function getName(): string
    {
        return 'custom';
    }

    public function getUpdateCommandForTest(): string
    {
        return $this->getUpdateCommand();
    }

    public function getVersionCommandForTest(): string
    {
        return $this->getVersionCommand();
    }

    public function getVersionConstraint(): string
    {
        return '*';
    }

    protected function getAuditCommand(bool $noDev): string
    {
        return $this->buildUnconfiguredCommand('audit');
    }

    protected function getDefaultBinary(): string
    {
        return 'custom-bin';
    }
}
