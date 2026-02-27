<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Bulk;

use Nacento\Connector\Model\Bulk\RequestValidator;
use PHPUnit\Framework\TestCase;

class RequestValidatorTest extends TestCase
{
    public function testValidImagePayloadPassesValidation(): void
    {
        $validator = new RequestValidator();

        $error = $validator->validateImage([
            'file_path' => '/a/b.jpg',
            'label' => 'Alt text',
            'position' => 3,
            'roles' => ['base', 'thumbnail'],
        ]);

        self::assertNull($error);
    }

    public function testIgnoresUnknownRole(): void
    {
        $validator = new RequestValidator();

        $error = $validator->validateImage([
            'file_path' => '/a/b.jpg',
            'roles' => ['hero'],
        ]);

        self::assertNull($error);
    }

    public function testRejectsOutOfRangePosition(): void
    {
        $validator = new RequestValidator();

        $error = $validator->validateImage([
            'file_path' => '/a/b.jpg',
            'position' => 100001,
        ]);

        self::assertSame('position is out of allowed range', $error);
    }

    public function testRejectsTooLongLabel(): void
    {
        $validator = new RequestValidator();
        $label = str_repeat('a', 256);

        $error = $validator->validateImage([
            'file_path' => '/a/b.jpg',
            'label' => $label,
        ]);

        self::assertSame('label is too long', $error);
    }
}
