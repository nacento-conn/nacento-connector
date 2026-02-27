<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;
use Magento\AsynchronousOperations\Model\OperationFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use Nacento\Connector\Model\Bulk\OperationKeyFactory;
use Nacento\Connector\Model\Bulk\RequestValidator;
use Nacento\Connector\Model\BulkGalleryAsyncManagement;
use Nacento\Connector\Model\Data\BulkItem;
use Nacento\Connector\Model\Data\BulkRequest;
use Nacento\Connector\Model\Data\ImageEntry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BulkGalleryAsyncManagementTest extends TestCase
{
    public function testRejectsInvalidItemsAndDedupesValidSkuForScheduling(): void
    {
        $bulkManagement = $this->createMock(BulkManagementInterface::class);
        $opFactory = $this->createMock(OperationFactory::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $responseFactory = $this->createMock(AsyncResponseInterfaceFactory::class);
        $itemStatusFactory = $this->createMock(ItemStatusInterfaceFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userContext->method('getUserId')->willReturn(5);

        $capturedCreates = [];
        $opFactory->method('create')->willReturnCallback(function (array $data) use (&$capturedCreates) {
            $capturedCreates[] = $data;
            return new \stdClass();
        });

        $serializer->method('serialize')->willReturnCallback(static function (array $payload): string {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        });

        $bulkManagement->expects(self::once())
            ->method('scheduleBulk')
            ->with(
                self::isType('string'),
                self::callback(static fn(array $operations): bool => count($operations) === 1),
                'Nacento gallery bulk',
                5
            );

        $response = $this->createMock(AsyncResponseInterface::class);
        $response->method('setBulkUuid')->willReturnSelf();
        $response->method('setRequestItems')->willReturnSelf();
        $response->expects(self::once())->method('setErrors')->with(true)->willReturnSelf();
        $responseFactory->method('create')->willReturn($response);

        $itemStatusFactory->method('create')->willReturnCallback(function () {
            $status = $this->createMock(ItemStatusInterface::class);
            $status->method('setId')->willReturnSelf();
            $status->method('setDataHash')->willReturnSelf();
            $status->method('setStatus')->willReturnSelf();
            $status->method('setErrorMessage')->willReturnSelf();
            return $status;
        });

        $service = new BulkGalleryAsyncManagement(
            $bulkManagement,
            $opFactory,
            $serializer,
            $userContext,
            $responseFactory,
            $itemStatusFactory,
            $logger,
            new ImagePayloadNormalizer($logger),
            new RequestValidator(),
            new OperationKeyFactory()
        );

        $img = new ImageEntry(['data' => [
            'file_path' => '/a/b.jpg',
            'label' => '',
            'roles' => ['base'],
            'position' => 1,
            'disabled' => false,
            'ignored' => 'x',
        ]]);
        $valid1 = new BulkItem(['data' => ['sku' => 'SKU-1', 'images' => [$img]]]);
        $valid2 = new BulkItem(['data' => ['sku' => 'SKU-1', 'images' => [[
            'file_path' => '/c/d.jpg',
            'label' => 'second',
            'roles' => ['thumbnail'],
        ]]]]);
        $invalid = new BulkItem(['data' => ['sku' => '   ', 'images' => []]]);
        $request = new BulkRequest(['data' => [
            'request_id' => 'req-1',
            'items' => [$valid1, $invalid, $valid2],
        ]]);

        $service->submit($request);

        self::assertCount(1, $capturedCreates);
        $operationData = $capturedCreates[0]['data'];
        self::assertArrayHasKey('operation_key', $operationData);

        $payload = json_decode((string)$operationData['serialized_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SKU-1', $payload['sku']);
        self::assertSame('req-1', $payload['request_id']);
        self::assertSame('/c/d.jpg', $payload['images'][0]['file_path']);
        self::assertSame(['thumbnail'], $payload['images'][0]['roles']);
        self::assertArrayNotHasKey('ignored', $payload['images'][0]);
    }

    public function testMarksValidItemsRejectedWhenSchedulingFails(): void
    {
        $bulkManagement = $this->createMock(BulkManagementInterface::class);
        $opFactory = $this->createMock(OperationFactory::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $responseFactory = $this->createMock(AsyncResponseInterfaceFactory::class);
        $itemStatusFactory = $this->createMock(ItemStatusInterfaceFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userContext->method('getUserId')->willReturn(7);

        $opFactory->method('create')->willReturn(new \stdClass());
        $serializer->method('serialize')->willReturn('{}');
        $bulkManagement->method('scheduleBulk')->willThrowException(new \RuntimeException('AMQP down'));

        $response = $this->createMock(AsyncResponseInterface::class);
        $response->method('setBulkUuid')->willReturnSelf();
        $response->method('setRequestItems')->willReturnSelf();
        $response->expects(self::once())->method('setErrors')->with(true)->willReturnSelf();
        $responseFactory->method('create')->willReturn($response);

        $statuses = [];
        $itemStatusFactory->method('create')->willReturnCallback(function () use (&$statuses) {
            $status = $this->createMock(ItemStatusInterface::class);
            $capture = ['status' => null, 'error' => null];
            $status->method('setId')->willReturnSelf();
            $status->method('setDataHash')->willReturnSelf();
            $status->method('setStatus')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['status'] = $value;
                return $status;
            });
            $status->method('setErrorMessage')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['error'] = $value;
                return $status;
            });
            $statuses[] = &$capture;
            return $status;
        });

        $service = new BulkGalleryAsyncManagement(
            $bulkManagement,
            $opFactory,
            $serializer,
            $userContext,
            $responseFactory,
            $itemStatusFactory,
            $logger,
            new ImagePayloadNormalizer($logger),
            new RequestValidator(),
            new OperationKeyFactory()
        );

        $request = new BulkRequest(['data' => [
            'request_id' => 'req-2',
            'items' => [
                new BulkItem(['data' => [
                    'sku' => 'SKU-1',
                    'images' => [[
                        'file_path' => '/a/b.jpg',
                        'roles' => ['base'],
                    ]],
                ]]),
            ],
        ]]);

        $service->submit($request);

        self::assertCount(1, $statuses);
        self::assertSame(ItemStatusInterface::STATUS_REJECTED, $statuses[0]['status']);
        self::assertSame('Failed to schedule bulk operations', $statuses[0]['error']);
    }

    public function testRejectsInvalidItemShapeAndStillSchedulesValidOnes(): void
    {
        $bulkManagement = $this->createMock(BulkManagementInterface::class);
        $opFactory = $this->createMock(OperationFactory::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $responseFactory = $this->createMock(AsyncResponseInterfaceFactory::class);
        $itemStatusFactory = $this->createMock(ItemStatusInterfaceFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userContext->method('getUserId')->willReturn(3);
        $opFactory->method('create')->willReturn(new \stdClass());
        $serializer->method('serialize')->willReturn('{}');
        $bulkManagement->expects(self::once())->method('scheduleBulk');

        $response = $this->createMock(AsyncResponseInterface::class);
        $response->method('setBulkUuid')->willReturnSelf();
        $response->method('setRequestItems')->willReturnSelf();
        $response->expects(self::once())->method('setErrors')->with(true)->willReturnSelf();
        $responseFactory->method('create')->willReturn($response);

        $statuses = [];
        $itemStatusFactory->method('create')->willReturnCallback(function () use (&$statuses) {
            $status = $this->createMock(ItemStatusInterface::class);
            $capture = ['status' => null, 'error' => null];
            $status->method('setId')->willReturnSelf();
            $status->method('setDataHash')->willReturnSelf();
            $status->method('setStatus')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['status'] = $value;
                return $status;
            });
            $status->method('setErrorMessage')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['error'] = $value;
                return $status;
            });
            $statuses[] = &$capture;
            return $status;
        });

        $service = new BulkGalleryAsyncManagement(
            $bulkManagement,
            $opFactory,
            $serializer,
            $userContext,
            $responseFactory,
            $itemStatusFactory,
            $logger,
            new ImagePayloadNormalizer($logger),
            new RequestValidator(),
            new OperationKeyFactory()
        );

        $dtoItem = new BulkItem(['data' => [
            'sku' => 'SKU-DTO',
            'images' => [['file_path' => '/a/b.jpg']],
        ]]);
        $dataObjectItem = new DataObject([
            'sku' => 'SKU-DO',
            'images' => [['file_path' => '/c/d.jpg']],
        ]);

        $request = new BulkRequest(['data' => [
            'items' => [$dtoItem, 'bad-item', $dataObjectItem],
        ]]);

        $service->submit($request);

        self::assertCount(3, $statuses);
        $acceptedCount = 0;
        $rejectedErrors = [];
        foreach ($statuses as $status) {
            if ($status['status'] === ItemStatusInterface::STATUS_ACCEPTED) {
                $acceptedCount++;
                continue;
            }

            if ($status['status'] === ItemStatusInterface::STATUS_REJECTED) {
                $rejectedErrors[] = $status['error'];
            }
        }

        self::assertSame(2, $acceptedCount);
        self::assertSame(['Invalid item payload'], $rejectedErrors);
    }

    public function testRejectsItemsWithMissingImages(): void
    {
        $bulkManagement = $this->createMock(BulkManagementInterface::class);
        $opFactory = $this->createMock(OperationFactory::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $responseFactory = $this->createMock(AsyncResponseInterfaceFactory::class);
        $itemStatusFactory = $this->createMock(ItemStatusInterfaceFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $bulkManagement->expects(self::never())->method('scheduleBulk');

        $response = $this->createMock(AsyncResponseInterface::class);
        $response->method('setBulkUuid')->willReturnSelf();
        $response->method('setRequestItems')->willReturnSelf();
        $response->expects(self::once())->method('setErrors')->with(true)->willReturnSelf();
        $responseFactory->method('create')->willReturn($response);

        $statuses = [];
        $itemStatusFactory->method('create')->willReturnCallback(function () use (&$statuses) {
            $status = $this->createMock(ItemStatusInterface::class);
            $capture = ['status' => null, 'error' => null];
            $status->method('setId')->willReturnSelf();
            $status->method('setDataHash')->willReturnSelf();
            $status->method('setStatus')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['status'] = $value;
                return $status;
            });
            $status->method('setErrorMessage')->willReturnCallback(function (string $value) use (&$capture, $status) {
                $capture['error'] = $value;
                return $status;
            });
            $statuses[] = &$capture;
            return $status;
        });

        $service = new BulkGalleryAsyncManagement(
            $bulkManagement,
            $opFactory,
            $serializer,
            $userContext,
            $responseFactory,
            $itemStatusFactory,
            $logger,
            new ImagePayloadNormalizer($logger),
            new RequestValidator(),
            new OperationKeyFactory()
        );

        $request = new BulkRequest(['data' => [
            'items' => [
                new BulkItem(['data' => [
                    'sku' => 'SKU-1',
                    'images' => [],
                ]]),
            ],
        ]]);

        $service->submit($request);

        self::assertCount(1, $statuses);
        self::assertSame(ItemStatusInterface::STATUS_REJECTED, $statuses[0]['status']);
        self::assertSame('Missing images', $statuses[0]['error']);
    }
}
