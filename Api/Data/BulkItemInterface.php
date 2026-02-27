<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents a single item within a bulk request.
 *
 * This interface defines a data structure for an item that includes a product
 * SKU and its corresponding image gallery.
 *
 * @api
 */
interface BulkItemInterface
{
    /**
     * Retrieves the product SKU to be processed.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Sets the product SKU.
     *
     * @param string $sku The product SKU.
     * @return $this
     */
    public function setSku(string $sku);

    /**
     * Retrieves the image gallery associated with the SKU.
     *
     * Returns an array of image entries, where each entry implements
     * the ImageEntryInterface.
     *
     * @return \Nacento\Connector\Api\Data\ImageEntryInterface[]
     */
    public function getImages(): array;

    /**
     * Sets the image gallery for the SKU.
     *
     * @param \Nacento\Connector\Api\Data\ImageEntryInterface[] $images An array of image entry objects.
     * @return $this
     */
    public function setImages(array $images);
}