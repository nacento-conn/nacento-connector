<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

class OperationKeyFactory
{
    public function make(string $bulkUuid, string $sku): string
    {
        // Keep the key deterministic but compatible with int(10) columns used by older Magento schemas.
        $value = sprintf('%u', crc32($bulkUuid . '|' . $sku));
        return $value === '0' ? '1' : $value;
    }
}
