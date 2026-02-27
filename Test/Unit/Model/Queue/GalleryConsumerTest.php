<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Queue;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use Nacento\Connector\Model\Data\ImageEntryFactory;
use Nacento\Connector\Model\GalleryProcessor;
use Nacento\Connector\Model\Queue\FailureClassifier;
use Nacento\Connector\Model\Queue\GalleryConsumer;
use Nacento\Connector\Model\Queue\OperationStatusUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GalleryConsumerTest extends TestCase
{
    public function testThrowsWhenOperationStatusPersistenceErrorsAfterSuccessfulProcessing(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => [],
            'request_id' => 'req-1',
        ]);
        $normalizer->method('normalizeList')->willReturn([]);
        $processor->expects(self::once())->method('create')->with('SKU-1', [])->willReturn(true);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_ERROR);

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":[]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist operation status');

        $consumer->process($operation);
    }

    public function testDoesNotThrowWhenOperationStatusRowIsMissing(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => [],
        ]);
        $normalizer->method('normalizeList')->willReturn([]);
        $processor->expects(self::once())->method('create')->with('SKU-1', [])->willReturn(true);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_NOT_FOUND);
        $logger->expects(self::once())->method('error');

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":[]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $consumer->process($operation);

        self::assertTrue(true);
    }

    public function testThrowsForRetriableProcessingFailure(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => [],
        ]);
        $normalizer->method('normalizeList')->willReturn([]);

        $processorException = new \RuntimeException('DB timeout');
        $processor->expects(self::once())->method('create')->willThrowException($processorException);
        $failureClassifier->method('isRetriable')->willReturn(true);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_UPDATED);

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":[]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retriable processing failure');

        $consumer->process($operation);
    }

    public function testRetriableProcessingFailureIsDroppedWhenStatusRowIsMissing(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => [],
        ]);
        $normalizer->method('normalizeList')->willReturn([]);

        $processor->expects(self::once())->method('create')->willThrowException(new \RuntimeException('Temporary DB outage'));
        $failureClassifier->method('isRetriable')->willReturn(true);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_NOT_FOUND);
        $logger->expects(self::once())->method('error');

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":[]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $consumer->process($operation);

        self::assertTrue(true);
    }

    public function testThrowsWhenNonRetriableFailureCannotPersistStatus(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => [],
        ]);
        $normalizer->method('normalizeList')->willReturn([]);

        $processor->expects(self::once())->method('create')->willThrowException(new \RuntimeException('File not found'));
        $failureClassifier->method('isRetriable')->willReturn(false);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_ERROR);

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":[]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist operation status');

        $consumer->process($operation);
    }

    public function testMarksMalformedImageRowsAsNonRetriableFailure(): void
    {
        $processor = $this->createMock(GalleryProcessor::class);
        $imageEntryFactory = $this->createMock(ImageEntryFactory::class);
        $normalizer = $this->createMock(ImagePayloadNormalizer::class);
        $failureClassifier = $this->createMock(FailureClassifier::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $statusUpdater = $this->createMock(OperationStatusUpdater::class);

        $serializer->method('unserialize')->willReturn([
            'sku' => 'SKU-1',
            'images' => ['bad-row'],
        ]);
        $normalizer->method('normalizeList')->willReturn([]);
        $processor->expects(self::never())->method('create');
        $failureClassifier->method('isRetriable')->willReturn(false);
        $statusUpdater->expects(self::once())->method('update')->willReturn(OperationStatusUpdater::RESULT_UPDATED);

        $operation = $this->createMock(AsyncOperationInterface::class);
        $operation->method('getSerializedData')->willReturn('{"sku":"SKU-1","images":["bad-row"]}');
        $operation->method('getId')->willReturn(42);
        $operation->method('getBulkUuid')->willReturn('bulk-1');

        $consumer = new GalleryConsumer(
            $processor,
            $imageEntryFactory,
            $normalizer,
            $failureClassifier,
            $serializer,
            $logger,
            $statusUpdater
        );

        $consumer->process($operation);
        self::assertTrue(true);
    }
}
