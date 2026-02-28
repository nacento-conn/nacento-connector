<?php

declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use Nacento\Connector\Model\Data\ImageEntryFactory;
use Nacento\Connector\Model\GalleryProcessor;
use Psr\Log\LoggerInterface;

class GalleryConsumer
{
    public function __construct(
        private readonly GalleryProcessor $processor,
        private readonly ImageEntryFactory $imageEntryFactory,
        private readonly ImagePayloadNormalizer $imagePayloadNormalizer,
        private readonly FailureClassifier $failureClassifier,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly OperationStatusUpdater $operationStatusUpdater
    ) {}

    public function process(AsyncOperationInterface $operation): void
    {
        $status    = OperationInterface::STATUS_TYPE_COMPLETE;
        $errorCode = null;
        $message   = null;
        $sku       = '';
        $requestId = null;
        $processingException = null;
        $processingRetriable = false;
        $operationIdentity = (string)($operation->getId() ?? '');
        $operationKey = method_exists($operation, 'getOperationKey')
            ? (string)($operation->getOperationKey() ?? '')
            : trim($operationIdentity);
        $statusUpdateResult = OperationStatusUpdater::RESULT_UPDATED;

        $dataJson = (string)($operation->getSerializedData() ?? '');

        try {
            $decoded = $dataJson !== '' ? $this->serializer->unserialize($dataJson) : [];
            if (!is_array($decoded)) {
                throw new \RuntimeException('Message payload is not an object/array');
            }
            $data = $decoded;

            $sku = trim((string)($data['sku'] ?? ''));
            if (isset($data['images']) && !is_array($data['images'])) {
                throw new \RuntimeException('images must be an array in the message payload');
            }
            $images = (array)($data['images'] ?? []);
            $requestId = isset($data['request_id']) ? (string)$data['request_id'] : null;

            if ($sku === '') {
                throw new \RuntimeException('SKU is empty in the message payload');
            }

            $entries = $this->normalizeImages($images);
            if ($images !== [] && $entries === []) {
                throw new \RuntimeException('No valid image entries after payload normalization');
            }
            $this->processor->create($sku, $entries);

            $this->logger->debug('[NacentoConnector][GalleryConsumer] Operation processed', [
                'operation_id' => (string)$operation->getId(),
                'operation_key' => $operationKey,
                'bulk_uuid' => (string)$operation->getBulkUuid(),
                'sku' => $sku,
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            $processingException = $e;
            $processingRetriable = $this->failureClassifier->isRetriable($e);
            $status = $processingRetriable
                ? OperationInterface::STATUS_TYPE_RETRIABLY_FAILED
                : OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
            $errorCode = is_numeric($e->getCode()) ? (int)$e->getCode() : null;
            $message   = $e->getMessage();

            $this->logger->error('[NacentoConnector][GalleryConsumer] Operation failed', [
                'operation_id' => (string)$operation->getId(),
                'operation_key' => $operationKey,
                'bulk_uuid' => (string)$operation->getBulkUuid(),
                'sku' => $sku,
                'request_id' => $requestId,
                'retriable' => $status === OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
                'exception' => $e,
            ]);
        } finally {
            $statusUpdateResult = $this->operationStatusUpdater->update(
                (string)$operation->getBulkUuid(),
                (int)$operation->getId(),
                $operationKey !== '' ? $operationKey : null,
                $status,
                $errorCode,
                $message
            );
        }

        if ($statusUpdateResult === OperationStatusUpdater::RESULT_NOT_FOUND) {
            $this->logger->error('[NacentoConnector][GalleryConsumer] Operation status row not found', [
                'operation_id' => (string)$operation->getId(),
                'operation_key' => $operationKey,
                'bulk_uuid' => (string)$operation->getBulkUuid(),
                'sku' => $sku,
                'had_processing_exception' => $processingException !== null,
                'processing_retriable' => $processingRetriable,
            ]);
            if ($processingException !== null && $processingRetriable) {
                throw new \RuntimeException(
                    sprintf(
                        'Retriable processing failure with missing operation status row for bulk="%s" operation_id="%s" operation_key="%s"',
                        (string)$operation->getBulkUuid(),
                        (string)$operation->getId(),
                        $operationKey
                    ),
                    0,
                    $processingException
                );
            }
            return;
        }

        if ($processingException !== null) {
            if ($processingRetriable) {
                throw new \RuntimeException(
                    sprintf(
                        'Retriable processing failure for bulk="%s" operation_id="%s" operation_key="%s"',
                        (string)$operation->getBulkUuid(),
                        (string)$operation->getId(),
                        $operationKey
                    ),
                    0,
                    $processingException
                );
            }

            if ($statusUpdateResult === OperationStatusUpdater::RESULT_ERROR) {
                throw new \RuntimeException(
                    sprintf(
                        'Failed to persist operation status for bulk="%s" operation_id="%s" operation_key="%s"',
                        (string)$operation->getBulkUuid(),
                        (string)$operation->getId(),
                        $operationKey
                    ),
                    0,
                    $processingException
                );
            }

            return;
        }

        if ($statusUpdateResult === OperationStatusUpdater::RESULT_ERROR) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to persist operation status for bulk="%s" operation_id="%s" operation_key="%s"',
                    (string)$operation->getBulkUuid(),
                    (string)$operation->getId(),
                    $operationKey
                ),
                0,
                $processingException
            );
        }
    }

    private function normalizeImages(array $images): array
    {
        $out = [];
        foreach ($this->imagePayloadNormalizer->normalizeList($images) as $row) {
            $out[] = $this->imageEntryFactory->create(['data' => $row]);
        }

        return $out;
    }
}
