<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Gallery;

use Nacento\Connector\Model\Gallery\RoleMapper;
use PHPUnit\Framework\TestCase;

class RoleMapperTest extends TestCase
{
    public function testMapsAliasesAndFiltersUnsupported(): void
    {
        $mapper = new RoleMapper();

        $mapped = $mapper->mapMany(['BASE', 'small', 'thumbnail', 'unsupported', 'image', '']);

        self::assertSame(['image', 'small_image', 'thumbnail'], $mapped);
    }
}
