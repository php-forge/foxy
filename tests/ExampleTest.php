<?php

declare(strict_types=1);

namespace Yii\Extension\Widget\Tests;

use PHPUnit\Framework\TestCase;
use Template\Example;

final class ExampleTest extends TestCase
{
    public function testExample(): void
    {
        $example = new Example();

        $this->assertTrue($example->getExample());
    }
}
