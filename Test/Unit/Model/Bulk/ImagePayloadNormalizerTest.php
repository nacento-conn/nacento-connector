<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Bulk;

use Magento\Framework\DataObject;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImagePayloadNormalizerTest extends TestCase
{
    public function testNormalizeListCoercesAndFiltersFields(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $normalizer = new ImagePayloadNormalizer($logger);

        $rows = $normalizer->normalizeList([
            [
                'file_path' => ' /a/b.jpg ',
                'label' => '',
                'disabled' => 'yes',
                'position' => '7',
                'roles' => ['BASE', '', 'thumbnail', 'thumbnail', 123],
                'ignored' => 'x',
            ],
            new DataObject([
                'file_path' => '/x/y.jpg',
                'disabled' => 0,
                'position' => 'bad',
                'roles' => 'oops',
            ]),
        ]);

        self::assertSame('/a/b.jpg', $rows[0]['file_path']);
        self::assertSame('', $rows[0]['label']);
        self::assertTrue($rows[0]['disabled']);
        self::assertSame(7, $rows[0]['position']);
        self::assertSame(['base', 'thumbnail', '123'], $rows[0]['roles']);

        self::assertSame('/x/y.jpg', $rows[1]['file_path']);
        self::assertFalse($rows[1]['disabled']);
        self::assertSame(0, $rows[1]['position']);
        self::assertSame([], $rows[1]['roles']);
    }

    public function testUnexpectedPayloadIsSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $normalizer = new ImagePayloadNormalizer($logger);

        $rows = $normalizer->normalizeList(['bad-string']);

        self::assertSame([], $rows);
    }
}
