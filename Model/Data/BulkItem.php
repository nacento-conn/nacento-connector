<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkItemInterface;

/**
 * Data model for a single item in a bulk request.
 * @see \Nacento\Connector\Api\Data\BulkItemInterface
 */
class BulkItem extends DataObject implements BulkItemInterface
{
    /**
     * {@inheritdoc}
     */
    public function getSku(): string
    {
        return (string)$this->getData('sku');
    }

    /**
     * {@inheritdoc}
     */
    public function setSku(string $sku): self
    {
        return $this->setData('sku', $sku);
    }

    /**
     * {@inheritdoc}
     */
    public function getImages(): array
    {
        $images = $this->getData('images');
        return is_array($images) ? $images : [];
    }

    /**
     * {@inheritdoc}
     */
    public function setImages(array $images): self
    {
        return $this->setData('images', $images);
    }
}
