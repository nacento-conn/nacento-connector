<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

class OperationKeyFactory
{
    public function make(string $bulkUuid, string $sku): string
    {
        return substr(hash('sha256', $bulkUuid . '|' . $sku), 0, 64);
    }
}
