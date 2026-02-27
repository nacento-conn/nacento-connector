<?php

declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;

use Magento\AsynchronousOperations\Model\OperationFactory;
use Magento\Framework\DataObject;

use Nacento\Connector\Api\BulkGalleryAsyncManagementInterface;
use Nacento\Connector\Api\Data\BulkItemInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use Nacento\Connector\Model\Bulk\OperationKeyFactory;
use Nacento\Connector\Model\Bulk\RequestValidator;

/**
 * Asynchronous planner for publishing gallery processing batches.
 *
 * This class is responsible for:
 * - Deduplicating incoming items by SKU (last-wins).
 * - Converting image data objects (DTOs) into plain arrays before serialization.
 * - Scheduling the bulk operation and returning an AsyncResponse with the bulk_uuid and item statuses.
 */
class BulkGalleryAsyncManagement implements BulkGalleryAsyncManagementInterface
{
    /**
     * @param BulkManagementInterface $bulkManagement Core Magento service for scheduling bulk operations.
     * @param OperationFactory $operationFactory Factory to create individual operation objects for the queue.
     * @param SerializerInterface $serializer Handles serialization of the payload for the message queue.
     * @param UserContextInterface $userContext Provides the ID of the user initiating the request.
     * @param AsyncResponseInterfaceFactory $asyncResponseFactory Factory to create the final asynchronous response.
     * @param ItemStatusInterfaceFactory $itemStatusFactory Factory to create status objects for each item in the request.
     * @param LoggerInterface $logger For logging warnings or errors.
     */
    public function __construct(
        private readonly BulkManagementInterface $bulkManagement,
        private readonly OperationFactory $operationFactory,
        private readonly SerializerInterface $serializer,
        private readonly UserContextInterface $userContext,
        private readonly AsyncResponseInterfaceFactory $asyncResponseFactory,
        private readonly ItemStatusInterfaceFactory $itemStatusFactory,
        private readonly LoggerInterface $logger,
        private readonly ImagePayloadNormalizer $imagePayloadNormalizer,
        private readonly RequestValidator $requestValidator,
        private readonly OperationKeyFactory $operationKeyFactory
    ) {}

    /**
     * Schedules the bulk processing and returns an AsyncResponse with the bulk_uuid.
     *
     * @param BulkRequestInterface $request The incoming bulk request.
     * @return AsyncResponseInterface
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface
    {
        $bulkUuid = $this->uuidV4();
        $userId   = (int)($this->userContext->getUserId() ?? 0);
        $desc     = 'Nacento gallery bulk';
        $requestId = $this->requestValidator->normalizeRequestId($request->getRequestId());

        $operations = [];
        $statuses   = [];
        $seq        = 1;
        $hasErrors = false;
        $validBySku = [];
        $validStatusQueue = [];
        $invalidCount = 0;
        $rejectedCount = 0;
        $dedupedCount = 0;
        $totalCount = 0;
        $queuedCount = 0;

        foreach ((array)($request->getItems() ?? []) as $item) {
            $totalCount++;
            $rawItem = $this->extractItemPayload($item);
            if ($rawItem === null) {
                $statuses[] = $this->makeStatus($seq++, '', ItemStatusInterface::STATUS_REJECTED, 'Invalid item payload');
                $invalidCount++;
                $rejectedCount++;
                $hasErrors = true;
                continue;
            }

            $sku = $this->requestValidator->normalizeSku($rawItem['sku']);

            if ($sku === '') {
                $statuses[] = $this->makeStatus($seq++, '', ItemStatusInterface::STATUS_REJECTED, 'Missing SKU');
                $invalidCount++;
                $rejectedCount++;
                $hasErrors = true;
                continue;
            }

            $imagesPayload = $this->imagePayloadNormalizer->normalizeList($rawItem['images']);
            if ($imagesPayload === []) {
                $statuses[] = $this->makeStatus($seq++, $sku, ItemStatusInterface::STATUS_REJECTED, 'Missing images');
                $invalidCount++;
                $rejectedCount++;
                $hasErrors = true;
                continue;
            }
            $rejection = $this->firstImageRejection($imagesPayload);
            if ($rejection !== null) {
                $statuses[] = $this->makeStatus($seq++, $sku, ItemStatusInterface::STATUS_REJECTED, $rejection);
                $invalidCount++;
                $rejectedCount++;
                $hasErrors = true;
                continue;
            }

            if (isset($validBySku[$sku])) {
                $dedupedCount++;
            }
            $validBySku[$sku] = [
                'sku' => $sku,
                'images' => $imagesPayload,
            ];
        }

        foreach (array_values($validBySku) as $itemPayload) {
            $sku = $itemPayload['sku'];
            $payload = [
                'sku' => $sku,
                'images' => $itemPayload['images'],
            ];
            if ($requestId !== null) {
                $payload['request_id'] = $requestId;
            }

            $statusId = $seq++;
            try {
                $operationKey = $this->operationKeyFactory->make($bulkUuid, $sku);
                $serializedPayload = $this->serializer->serialize($payload);

                $operations[] = $this->operationFactory->create([
                    'data' => [
                        'bulk_uuid' => $bulkUuid,
                        'topic_name' => 'nacento.gallery.process',
                        'serialized_data' => $serializedPayload,
                        'status' => OperationInterface::STATUS_TYPE_OPEN,
                        'operation_key' => $operationKey,
                    ],
                ]);
                $validStatusQueue[] = ['id' => $statusId, 'sku' => $sku];
                $queuedCount++;
            } catch (\Throwable $e) {
                $hasErrors = true;
                $statuses[] = $this->makeStatus(
                    $statusId,
                    $sku,
                    ItemStatusInterface::STATUS_REJECTED,
                    'Failed to build queued operation'
                );
                $rejectedCount++;
                $this->logger->error('[NacentoConnector][BulkPlanner] Failed to build operation payload', [
                    'bulk_uuid' => $bulkUuid,
                    'request_id' => $requestId,
                    'sku' => $sku,
                    'exception' => $e,
                ]);
            }
        }

        $scheduleSucceeded = false;
        $scheduleError = null;
        try {
            if (!empty($operations)) {
                $this->bulkManagement->scheduleBulk($bulkUuid, $operations, $desc, $userId);
                $scheduleSucceeded = true;
            } else {
                $this->logger->warning('[NacentoConnector][BulkPlanner] No operations were scheduled', [
                    'bulk_uuid' => $bulkUuid,
                    'request_id' => $requestId,
                ]);
            }
        } catch (\Throwable $e) {
            $hasErrors = true;
            $scheduleError = 'Failed to schedule bulk operations';
            $this->logger->error('[NacentoConnector][BulkPlanner] Failed to schedule bulk', [
                'bulk_uuid' => $bulkUuid,
                'request_id' => $requestId,
                'exception' => $e,
            ]);
        }

        if ($scheduleSucceeded) {
            foreach ($validStatusQueue as $queuedItem) {
                $statuses[] = $this->makeStatus(
                    (int)$queuedItem['id'],
                    (string)$queuedItem['sku'],
                    ItemStatusInterface::STATUS_ACCEPTED
                );
            }
        } elseif (!empty($validStatusQueue)) {
            $hasErrors = true;
            foreach ($validStatusQueue as $queuedItem) {
                $statuses[] = $this->makeStatus(
                    (int)$queuedItem['id'],
                    (string)$queuedItem['sku'],
                    ItemStatusInterface::STATUS_REJECTED,
                    $scheduleError ?? 'Bulk scheduling failed'
                );
                $rejectedCount++;
            }
        }

        $this->logger->info('[NacentoConnector][BulkPlanner] Planned bulk request', [
            'bulk_uuid' => $bulkUuid,
            'request_id' => $requestId,
            'total' => $totalCount,
            'valid' => count($validBySku),
            'queued' => $queuedCount,
            'deduped' => $dedupedCount,
            'rejected' => $rejectedCount,
        ]);

        $resp = $this->asyncResponseFactory->create();
        $resp->setBulkUuid($bulkUuid);
        $resp->setRequestItems($statuses);
        $resp->setErrors($hasErrors);

        return $resp;
    }

    /**
     * @param array<int,array{file_path:string,label:string,disabled:bool,position:int,roles:array<int,string>}> $images
     */
    private function firstImageRejection(array $images): ?string
    {
        foreach ($images as $image) {
            $error = $this->requestValidator->validateImage($image);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Helper method to create a simple ItemStatus object.
     */
    private function makeStatus(int $id, string $sku, string $status, ?string $msg = null): ItemStatusInterface
    {
        $st = $this->itemStatusFactory->create();
        $st->setId($id);
        // A stable hash based on SKU (change if you need to include more fields).
        $st->setDataHash(md5($sku !== '' ? $sku : ('#' . $id)));
        $st->setStatus($status);
        if ($msg) {
            $st->setErrorMessage($msg);
        }
        return $st;
    }

    /**
     * @param mixed $item
     * @return array{sku:string,images:array<int,mixed>}|null
     */
    private function extractItemPayload(mixed $item): ?array
    {
        if ($item instanceof BulkItemInterface) {
            return [
                'sku' => (string)$item->getSku(),
                'images' => (array)($item->getImages() ?? []),
            ];
        }

        if ($item instanceof DataObject) {
            /** @var array<string,mixed> $data */
            $data = (array)$item->getData();
            return [
                'sku' => (string)($data['sku'] ?? ''),
                'images' => isset($data['images']) && is_array($data['images']) ? $data['images'] : [],
            ];
        }

        if (is_array($item)) {
            return [
                'sku' => (string)($item['sku'] ?? ''),
                'images' => isset($item['images']) && is_array($item['images']) ? $item['images'] : [],
            ];
        }

        return null;
    }

    /**
     * Generates a RFC-4122 compliant version 4 UUID (8-4-4-4-12 format).
     */
    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // set version to 0100 (v4)
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // set variant to 10xx (RFC 4122)
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
