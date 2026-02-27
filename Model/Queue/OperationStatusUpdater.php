<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class OperationStatusUpdater
{
    public const RESULT_UPDATED = 'updated';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_ERROR = 'error';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {}

    public function update(
        string $bulkUuid,
        int $operationId,
        ?string $operationKey,
        int $status,
        ?int $errorCode = null,
        ?string $message = null
    ): string {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('magento_operation');

            $bind = [
                'error_code' => $errorCode,
                'status' => $status,
                'result_message' => $message,
            ];

            $updated = 0;
            if ($operationKey !== null && $operationKey !== '') {
                $updated = $connection->update($table, $bind, [
                    'bulk_uuid = ?' => $bulkUuid,
                    'operation_key = ?' => $operationKey,
                ]);
                if ($updated === 0 && $this->existsByOperationKey($connection, $table, $bulkUuid, $operationKey)) {
                    // Row exists but no values changed.
                    return self::RESULT_UPDATED;
                }
            }

            if ($updated === 0) {
                $updated = $connection->update($table, $bind, [
                    'bulk_uuid = ?' => $bulkUuid,
                    'id = ?' => $operationId,
                ]);
                if ($updated === 0 && $this->existsById($connection, $table, $bulkUuid, $operationId)) {
                    // Row exists but no values changed.
                    return self::RESULT_UPDATED;
                }
            }

            if ($updated === 0) {
                $this->logger->warning(
                    sprintf('[NacentoConnector][OperationStatusUpdater] No rows updated for bulk=%s opId=%d opKey=%s', $bulkUuid, $operationId, (string)$operationKey)
                );
                return self::RESULT_NOT_FOUND;
            }

            return self::RESULT_UPDATED;
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            return self::RESULT_ERROR;
        }
    }

    private function existsByOperationKey(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        string $bulkUuid,
        string $operationKey
    ): bool {
        $select = $connection->select()
            ->from($table, ['id'])
            ->where('bulk_uuid = ?', $bulkUuid)
            ->where('operation_key = ?', $operationKey)
            ->limit(1);

        return $connection->fetchOne($select) !== false;
    }

    private function existsById(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        string $bulkUuid,
        int $operationId
    ): bool {
        $select = $connection->select()
            ->from($table, ['id'])
            ->where('bulk_uuid = ?', $bulkUuid)
            ->where('id = ?', $operationId)
            ->limit(1);

        return $connection->fetchOne($select) !== false;
    }
}
