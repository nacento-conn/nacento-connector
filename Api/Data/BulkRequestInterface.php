<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents a bulk request for galleries.
 * @api
 */
interface BulkRequestInterface
{
    /**
     * Optional ID for idempotency or correlation purposes.
     *
     * @return string|null
     */
    public function getRequestId(): ?string;

    /**
     * Sets the optional request ID.
     *
     * @param string|null $requestId The request ID.
     * @return $this
     */
    public function setRequestId(?string $requestId);

    /**
     * Retrieves the items to be processed in this bulk request.
     *
     * @return \Nacento\Connector\Api\Data\BulkItemInterface[]
     */
    public function getItems(): array;

    /**
     * Sets the items for this bulk request.
     *
     * @param \Nacento\Connector\Api\Data\BulkItemInterface[] $items The array of items to process.
     * @return $this
     */
    public function setItems(array $items);
}