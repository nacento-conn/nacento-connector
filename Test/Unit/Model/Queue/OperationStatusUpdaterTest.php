<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Nacento\Connector\Model\Queue\OperationStatusUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OperationStatusUpdaterTest extends TestCase
{
    public function testUpdatesByOperationKeyAndFallsBackToId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturnOnConsecutiveCalls(0, 1);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with($select)
            ->willReturn(false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->with('magento_operation')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertSame(OperationStatusUpdater::RESULT_UPDATED, $updater->update('bulk-1', 10, 'op-key-1', 4, null, null));
    }

    public function testReturnsFalseAndLogsWhenExceptionOccurs(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willThrowException(new \RuntimeException('db down'));

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertSame(OperationStatusUpdater::RESULT_ERROR, $updater->update('bulk-1', 10, 'op-key-1', 4));
    }

    public function testReturnsNotFoundWhenNoRowsAreUpdated(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturn(0);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->with($select)
            ->willReturn(false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertSame(OperationStatusUpdater::RESULT_NOT_FOUND, $updater->update('bulk-1', 10, 'op-key-1', 4));
    }

    public function testReturnsUpdatedWhenRowExistsButNothingChanged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('update')
            ->willReturn(0);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with($select)
            ->willReturn('10');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertSame(OperationStatusUpdater::RESULT_UPDATED, $updater->update('bulk-1', 10, 'op-key-1', 4));
    }
}
