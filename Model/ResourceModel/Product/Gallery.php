<?php

/**
 * Copyright © Nacento
 */

declare(strict_types=1);

namespace Nacento\Connector\Model\ResourceModel\Product;

use Magento\Framework\Exception\LocalizedException;

/**
 * Custom Product Gallery Resource Model On steroids.
 */
class Gallery extends \Magento\Catalog\Model\ResourceModel\Product\Gallery
{
    /**
     * Checks if an image with a specific file path already exists for a given product.
     * If found, it returns its primary identifiers from the gallery tables.
     *
     * @param int $productId The ID of the product entity.
     * @param int $attributeId The ID of the media_gallery attribute.
     * @param string $filePath The file path of the image to check.
     * @return array|null An array ['value_id' => int, 'record_id' => int, 's3_etag' => ?string] or null if not found.
     * @throws LocalizedException
     */
    public function getExistingImage(int $productId, int $attributeId, string $filePath): ?array
    {
        $connection = $this->getConnection();
        $linkTable = $this->getTable('catalog_product_entity_media_gallery_value_to_entity');
        $valueTable = $this->getTable('catalog_product_entity_media_gallery_value');
        $metaTable  = $this->getTable('nacento_media_gallery_meta');

        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id'])
            ->join(['link' => $linkTable], 'main_table.value_id = link.value_id', [])
            ->join(
                ['value' => $valueTable],
                'main_table.value_id = value.value_id AND value.entity_id = link.entity_id AND value.store_id = 0',
                ['record_id', 'label', 'position', 'disabled']
            )
            ->joinLeft(['meta' => $metaTable], 'value.record_id = meta.record_id', ['s3_etag' => 's3_etag'])
            ->where('link.entity_id = ?', $productId)
            ->where('main_table.attribute_id = ?', $attributeId)
            ->where('main_table.value = ?', $filePath);

        $result = $connection->fetchRow($select);
        return $result ?: null;
    }

    /**
     * Batch version of getExistingImage - fetches all images for a product at once.
     * This eliminates the N+1 query problem when processing multiple images.
     *
     * @param int $productId The ID of the product entity.
     * @param int $attributeId The ID of the media_gallery attribute.
     * @param array $filePaths Array of file paths to check.
     * @return array Associative array indexed by file_path with comparison fields.
     * @throws LocalizedException
     */
    public function getExistingImages(int $productId, int $attributeId, array $filePaths): array
    {
        if (empty($filePaths)) {
            return [];
        }

        $connection = $this->getConnection();
        $linkTable = $this->getTable('catalog_product_entity_media_gallery_value_to_entity');
        $valueTable = $this->getTable('catalog_product_entity_media_gallery_value');
        $metaTable  = $this->getTable('nacento_media_gallery_meta');

        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id', 'value'])
            ->join(['link' => $linkTable], 'main_table.value_id = link.value_id', [])
            ->join(
                ['value' => $valueTable],
                'main_table.value_id = value.value_id AND value.entity_id = link.entity_id AND value.store_id = 0',
                ['record_id', 'label', 'position', 'disabled']
            )
            ->joinLeft(['meta' => $metaTable], 'value.record_id = meta.record_id', ['s3_etag' => 's3_etag'])
            ->where('link.entity_id = ?', $productId)
            ->where('main_table.attribute_id = ?', $attributeId)
            ->where('main_table.value IN (?)', $filePaths);

        $rows = $connection->fetchAll($select);

        // Index by file path for easy lookup
        $result = [];
        foreach ($rows as $row) {
            $result[$row['value']] = $row;
        }

        return $result;
    }

    /**
     * Returns global media-gallery value IDs indexed by file path, regardless of product linkage.
     *
     * @param int $attributeId
     * @param array<int,string> $filePaths
     * @return array<string,int>
     */
    public function getGlobalValueIdsByPaths(int $attributeId, array $filePaths): array
    {
        if (empty($filePaths)) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id', 'value'])
            ->where('main_table.attribute_id = ?', $attributeId)
            ->where('main_table.value IN (?)', $filePaths);

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['value']] = (int)$row['value_id'];
        }

        return $result;
    }

    /**
     * Removes duplicated media-gallery links for a product when the same image exists
     * under canonical and legacy value variants (e.g. "/a/b.jpg" and "a/b.jpg").
     *
     * Keeps one value_id per canonical path and removes extra value rows/links for the product.
     *
     * @param int $productId
     * @param int $attributeId
     * @param array<int,string> $canonicalPaths
     * @return int Number of per-product value rows removed.
     */
    public function dedupePathVariantsForProduct(int $productId, int $attributeId, array $canonicalPaths): int
    {
        if ($canonicalPaths === []) {
            return 0;
        }

        $connection = $this->getConnection();
        $linkTable = $this->getTable('catalog_product_entity_media_gallery_value_to_entity');
        $valueTable = $this->getTable('catalog_product_entity_media_gallery_value');

        $variantsToLookup = [];
        foreach ($canonicalPaths as $canonicalPath) {
            $canonicalPath = $this->normalizeGalleryPath((string)$canonicalPath);
            if ($canonicalPath === '') {
                continue;
            }
            $variantsToLookup[$canonicalPath] = true;
            $legacyPath = ltrim($canonicalPath, '/');
            if ($legacyPath !== '' && $legacyPath !== $canonicalPath) {
                $variantsToLookup[$legacyPath] = true;
            }
        }

        if ($variantsToLookup === []) {
            return 0;
        }

        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id', 'value'])
            ->join(['link' => $linkTable], 'main_table.value_id = link.value_id', [])
            ->where('link.entity_id = ?', $productId)
            ->where('main_table.attribute_id = ?', $attributeId)
            ->where('main_table.value IN (?)', array_keys($variantsToLookup));

        $rows = $connection->fetchAll($select);
        if ($rows === []) {
            return 0;
        }

        $groupedByCanonical = [];
        foreach ($rows as $row) {
            $value = (string)($row['value'] ?? '');
            $canonical = $this->normalizeGalleryPath($value);
            if ($canonical === '') {
                continue;
            }
            if (!isset($groupedByCanonical[$canonical])) {
                $groupedByCanonical[$canonical] = [];
            }
            $groupedByCanonical[$canonical][] = [
                'value_id' => (int)$row['value_id'],
                'value' => $value,
            ];
        }

        $valueIdsToRemove = [];
        foreach ($groupedByCanonical as $canonical => $group) {
            if (count($group) <= 1) {
                continue;
            }

            $valueIdToKeep = 0;
            foreach ($group as $candidate) {
                if ((string)$candidate['value'] === $canonical) {
                    $valueIdToKeep = (int)$candidate['value_id'];
                    break;
                }
            }
            if ($valueIdToKeep === 0) {
                $valueIdToKeep = (int)$group[0]['value_id'];
            }

            foreach ($group as $candidate) {
                $candidateValueId = (int)$candidate['value_id'];
                if ($candidateValueId !== $valueIdToKeep) {
                    $valueIdsToRemove[$candidateValueId] = true;
                }
            }
        }

        if ($valueIdsToRemove === []) {
            return 0;
        }

        $removeIds = array_map('intval', array_keys($valueIdsToRemove));
        $deletedRows = $connection->delete(
            $valueTable,
            [
                'entity_id = ?' => $productId,
                'value_id IN (?)' => $removeIds,
            ]
        );

        $connection->delete(
            $linkTable,
            [
                'entity_id = ?' => $productId,
                'value_id IN (?)' => $removeIds,
            ]
        );

        return (int)$deletedRows;
    }

    /**
     * Inserts a new record into the main gallery table (`catalog_product_entity_media_gallery`).
     * This record links the attribute ID to the image file path.
     *
     * @param array<string, mixed> $data The data to be inserted.
     * @return int The ID of the newly inserted row (value_id).
     * @throws LocalizedException
     */
    public function insertNewRecord(array $data): int
    {
        /** @var \Magento\Framework\DB\Adapter\Pdo\Mysql $connection */
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $connection->insert($table, $data);
        return (int)$connection->lastInsertId($table);
    }

    /**
     * Atomically inserts a media gallery value row or returns the existing value_id if it already exists.
     *
     * Uses MySQL LAST_INSERT_ID trick to avoid race conditions on unique (attribute_id, value).
     */
    public function insertOrGetValueId(int $attributeId, string $filePath): int
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $quotedTable = $connection->quoteIdentifier($table);

        $sql = sprintf(
            'INSERT INTO %s (attribute_id, media_type, value) VALUES (?, ?, ?) ' .
            'ON DUPLICATE KEY UPDATE value_id = LAST_INSERT_ID(value_id)',
            $quotedTable
        );

        $connection->query($sql, [$attributeId, 'image', $filePath]);

        return (int)$connection->lastInsertId($table);
    }

    /**
     * Inserts or updates a gallery value record using an `INSERT ... ON DUPLICATE KEY UPDATE` statement.
     * This is the primary method for saving per-store metadata like label, position, and disabled status.
     * MySQL uses the unique key (composed of `value_id`, `store_id`, `entity_id`) to determine whether to
     * perform an INSERT or an UPDATE on the specified fields.
     *
     * @param array<string, mixed> $data The full data for the row, including unique key fields.
     * @throws LocalizedException
     */
    public function saveValueRecord(array $data): void
    {
        // fields to be updated if the row already exists.
        $updateFields = ['label', 'position', 'disabled'];

        // pass ALL the data,
        // including the fields that form the unique key (value_id, store_id, entity_id).
        // MySQL will use this key to determine whether to INSERT or UPDATE.
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value'),
            $data,
            $updateFields
        );
    }

    /**
     * Ensures a link exists between a media gallery value (`value_id`) and a product entity (`entity_id`).
     * It uses `INSERT ON DUPLICATE KEY UPDATE` to prevent errors if the link already exists.
     *
     * @param int $valueId The ID of the media gallery entry.
     * @param int $entityId The ID of the product entity.
     * @throws LocalizedException
     */
    public function createLink(int $valueId, int $entityId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value_to_entity'),
            ['value_id' => $valueId, 'entity_id' => $entityId],
            ['entity_id'] // Field to update (none in reality, but required for the syntax).
        );
    }

    /**
     * Fetches record_id for a specific (value_id, entity_id, store_id) row.
     */
    public function getValueRecordId(int $valueId, int $entityId, int $storeId = 0): ?int
    {
        $connection = $this->getConnection();
        $table = $this->getTable('catalog_product_entity_media_gallery_value');

        $select = $connection->select()
            ->from($table, ['record_id'])
            ->where('value_id = ?', $valueId)
            ->where('entity_id = ?', $entityId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $recordId = $connection->fetchOne($select);

        return $recordId !== false ? (int)$recordId : null;
    }

    /**
     * Saves or updates metadata in the custom `nacento_media_gallery_meta` table.
     * This is used to store supplementary information, such as an S3 ETag for the image file.
     *
     * @param int $recordId The gallery value's record_id.
     * @param string|null $etag The ETag value to save.
     */
    public function saveMetaRecord(int $recordId, ?string $etag): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('nacento_media_gallery_meta'),
            ['record_id' => $recordId, 's3_etag' => $etag],
            ['s3_etag'] // And an 'updated_at' if you have it with on_update="true" in the db schema.
        );
    }

    /**
     * Updates an existing gallery value record (e.g., label, position) identified by its unique `record_id`.
     *
     * @param int $recordId The unique ID of the value record to update.
     * @param array<string, mixed> $data The data to be updated.
     */
    public function updateValueRecord(int $recordId, array $data): void
    {
        $this->getConnection()->update(
            $this->getTable('catalog_product_entity_media_gallery_value'),
            $data,
            ['record_id = ?' => $recordId]
        );
    }

    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->getConnection()->rollBack();
    }

    /**
     * Removes all gallery entries for a product whose file path is NOT in $keepPaths.
     * This implements "replace" semantics: after syncing the current payload, orphaned
     * entries (old images no longer in Akeneo) are deleted from the gallery tables.
     *
     * Both the canonical (/a/b.jpg) and legacy (a/b.jpg) forms of each keep-path are
     * accepted, so entries stored under either variant are preserved.
     *
     * @param int $productId
     * @param int $attributeId
     * @param array<int,string> $keepPaths  All file paths present in the incoming payload
     *                                       (canonical form, with leading slash).
     * @return int Number of per-product value rows deleted.
     */
    public function purgeOrphanedEntries(int $productId, int $attributeId, array $keepPaths): int
    {
        // Safety guard: never wipe everything if the payload was empty.
        if (empty($keepPaths)) {
            return 0;
        }

        $connection = $this->getConnection();
        $linkTable  = $this->getTable('catalog_product_entity_media_gallery_value_to_entity');
        $valueTable = $this->getTable('catalog_product_entity_media_gallery_value');

        // Build a lookup set that accepts both /a/b.jpg and a/b.jpg for every keep-path.
        $keepSet = [];
        foreach ($keepPaths as $path) {
            $canonical  = $this->normalizeGalleryPath((string)$path);
            $legacy     = ltrim($canonical, '/');
            if ($canonical !== '') {
                $keepSet[$canonical] = true;
            }
            if ($legacy !== '') {
                $keepSet[$legacy] = true;
            }
        }

        // Fetch all value_ids currently linked to this product.
        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id', 'value'])
            ->join(['link' => $linkTable], 'main_table.value_id = link.value_id', [])
            ->where('link.entity_id = ?', $productId)
            ->where('main_table.attribute_id = ?', $attributeId);

        $rows = $connection->fetchAll($select);
        if (empty($rows)) {
            return 0;
        }

        $valueIdsToRemove = [];
        foreach ($rows as $row) {
            $path = (string)($row['value'] ?? '');
            if (!isset($keepSet[$path])) {
                $valueIdsToRemove[] = (int)$row['value_id'];
            }
        }

        if (empty($valueIdsToRemove)) {
            return 0;
        }

        $deleted = (int)$connection->delete(
            $valueTable,
            [
                'entity_id = ?'    => $productId,
                'value_id IN (?)'  => $valueIdsToRemove,
            ]
        );

        $connection->delete(
            $linkTable,
            [
                'entity_id = ?'    => $productId,
                'value_id IN (?)'  => $valueIdsToRemove,
            ]
        );

        return $deleted;
    }

    private function normalizeGalleryPath(string $filePath): string
    {
        $trimmed = trim($filePath);
        $trimmed = str_replace('\\', '/', $trimmed);
        $trimmed = preg_replace('#/+#', '/', $trimmed) ?? '';
        $trimmed = trim($trimmed, '/');

        return $trimmed === '' ? '' : '/' . $trimmed;
    }
}
