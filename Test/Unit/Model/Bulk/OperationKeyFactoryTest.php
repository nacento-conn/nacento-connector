<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Bulk;

use Nacento\Connector\Model\Bulk\OperationKeyFactory;
use PHPUnit\Framework\TestCase;

class OperationKeyFactoryTest extends TestCase
{
    public function testCreatesDeterministicNumericOperationKey(): void
    {
        $factory = new OperationKeyFactory();

        $keyA = $factory->make('bulk-1', 'SKU-1');
        $keyB = $factory->make('bulk-1', 'SKU-1');
        $keyC = $factory->make('bulk-1', 'SKU-2');

        self::assertSame($keyA, $keyB);
        self::assertMatchesRegularExpression('/^[1-9][0-9]{0,9}$/', $keyA);
        self::assertNotSame($keyA, $keyC);
    }
}
