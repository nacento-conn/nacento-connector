<?php
declare(strict_types=1);

namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\BulkRequestInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;

/**
 * Interface for managing asynchronous bulk gallery processing.
 * @api
 */
interface BulkGalleryAsyncManagementInterface
{
    /**
     * Submits a bulk gallery request for asynchronous processing.
     *
     * This method schedules the batch operation and returns an immediate acknowledgment,
     * which includes a bulk UUID for tracking purposes.
     *
     * @param BulkRequestInterface $request The bulk request containing all the items to be processed.
     * @return \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface The asynchronous operation response.
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface;
}