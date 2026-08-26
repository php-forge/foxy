<?php

declare(strict_types=1);

namespace Foxy\Audit;

interface AuditableAssetManagerInterface
{
    public function audit(bool $noDev): AuditProcessResult;
}
