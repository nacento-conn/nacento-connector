<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Data;

use Nacento\Connector\Model\Data\BulkItem;
use Nacento\Connector\Model\Data\BulkRequest;
use Nacento\Connector\Model\Data\ImageEntry;
use PHPUnit\Framework\TestCase;

class DtoTypeSafetyTest extends TestCase
{
    public function testBulkRequestCoercesInvalidRequestIdToNull(): void
    {
        $request = new BulkRequest(['data' => [
            'request_id' => ['unexpected'],
            'items' => 'bad-shape',
        ]]);

        self::assertNull($request->getRequestId());
        self::assertSame([], $request->getItems());
    }

    public function testBulkItemReturnsEmptyImagesWhenShapeIsInvalid(): void
    {
        $item = new BulkItem(['data' => [
            'images' => 'not-an-array',
        ]]);

        self::assertSame([], $item->getImages());
    }

    public function testImageEntryReturnsEmptyRolesWhenShapeIsInvalid(): void
    {
        $entry = new ImageEntry(['data' => [
            'roles' => 'base',
        ]]);

        self::assertSame([], $entry->getRoles());
    }
}

