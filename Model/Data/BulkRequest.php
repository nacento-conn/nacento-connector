<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkRequestInterface;

/**
 * Data model for a bulk gallery request.
 * @see \Nacento\Connector\Api\Data\BulkRequestInterface
 */
class BulkRequest extends DataObject implements BulkRequestInterface
{
    /**
     * {@inheritdoc}
     */
    public function getRequestId(): ?string
    {
        $requestId = $this->getData('request_id');
        if ($requestId === null) {
            return null;
        }

        if (!is_scalar($requestId)) {
            return null;
        }

        return (string)$requestId;
    }

    /**
     * {@inheritdoc}
     */
    public function setRequestId(?string $requestId): self { return $this->setData('request_id', $requestId); }

    /**
     * {@inheritdoc}
     */
    public function getItems(): array
    {
        $items = $this->getData('items');
        return is_array($items) ? $items : [];
    }

    /**
     * {@inheritdoc}
     */
    public function setItems(array $items): self { return $this->setData('items', $items); }
}
