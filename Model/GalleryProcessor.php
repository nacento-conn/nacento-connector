<?php

/**
 * Copyright © Nacento
 */

declare(strict_types=1);

namespace Nacento\Connector\Model;


use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Nacento\Connector\Model\ResourceModel\Product\Gallery as CustomGalleryResourceModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Nacento\Connector\Model\Gallery\ManagedRoleSetProvider;
use Nacento\Connector\Model\Gallery\RoleMapper;
use Nacento\Connector\Model\S3HeadClient;

/**
 * The core service responsible for processing and persisting product gallery updates.
 * This class acts as the "executing arm" for gallery management.
 */
class GalleryProcessor
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly CustomGalleryResourceModel $galleryResourceModel,
        private readonly ProductAttributeRepositoryInterface $productAttributeRepository,
        private readonly ProductAction $productAction,
        private readonly MediaConfig $mediaConfig,
        private readonly S3HeadClient $s3Head,
        private readonly RoleMapper $roleMapper,
        private readonly ManagedRoleSetProvider $managedRoleSetProvider
    ) {}

    /**
     * Creates or updates gallery entries from PRE-EXISTING file paths within the /media directory,
     * and saves the S3 ETag to a custom metadata table.
     * {@inheritdoc}
     */
    public function create(string $sku, array $images): bool
    {
        $this->logger->debug('[NacentoConnector][GalleryProcessor] Starting SKU sync', [
            'sku' => $sku,
            'images_received' => count($images),
        ]);

        // Early exit if there are no images to process.
        if (empty($images)) {
            $this->logger->warning('[NacentoConnector][GalleryProcessor] The images array is empty. Nothing to do.', [
                'sku' => $sku,
            ]);
            return true;
        }

        try {
            $product          = $this->productRepository->get($sku);
            $galleryAttribute = $this->productAttributeRepository->get('media_gallery');
            $mediaDirectory   = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $rolesToUpdate    = [];
            $stats = [
                'inserted' => 0,
                'updated' => 0,
                'skipped_noop' => 0,
                'invalid' => 0,
            ];

            $mediaDirectoryWriter = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            /** @var \Magento\Framework\Filesystem\DriverInterface|\Magento\AwsS3\Driver\AwsS3 $mediaDriver */
            $mediaDriver = $mediaDirectoryWriter->getDriver();
            $isS3 = $mediaDriver instanceof \Magento\AwsS3\Driver\AwsS3;

            $norm = static function ($e) {
                return $e !== null ? trim((string)$e, '\"') : null;
            };

            $validImages = [];
            $filePaths = [];

            foreach ($images as $imageEntry) {
                $filePath = $this->normalizeGalleryValuePath($imageEntry->getFilePath() ?? '');

                if ($filePath === '') {
                    $stats['invalid']++;
                    $this->logger->error('[NacentoConnector][GalleryProcessor] Skipping image due to empty file_path', [
                        'sku' => $sku,
                    ]);
                    continue;
                }

                $fullPathForValidation = $this->mediaConfig->getMediaPath(ltrim($filePath, '/'));
                if (!$mediaDirectory->isExist($fullPathForValidation)) {
                    $stats['invalid']++;
                    $this->logger->error('[NacentoConnector][GalleryProcessor] Skipping image because file does not exist', [
                        'sku' => $sku,
                        'path' => $fullPathForValidation,
                    ]);
                    continue;
                }

                $validImages[$filePath] = $imageEntry;
                $filePaths[] = $filePath;
                $legacyPath = ltrim($filePath, '/');
                if ($legacyPath !== '' && $legacyPath !== $filePath) {
                    $filePaths[] = $legacyPath;
                }
            }

            if (empty($validImages)) {
                $this->logger->warning('[NacentoConnector][GalleryProcessor] No valid images after validation', [
                    'sku' => $sku,
                ]);
                return true;
            }

            $existingImages = $this->galleryResourceModel->getExistingImages(
                (int)$product->getId(),
                (int)$galleryAttribute->getAttributeId(),
                $filePaths
            );
            $globalValueIdsByPath = $this->galleryResourceModel->getGlobalValueIdsByPaths(
                (int)$galleryAttribute->getAttributeId(),
                $filePaths
            );

            $this->galleryResourceModel->beginTransaction();
            try {
                foreach ($validImages as $filePath => $imageEntry) {
                    $label    = $imageEntry->getLabel() ?? '';
                    $disabled = $imageEntry->isDisabled();
                    $position = $imageEntry->getPosition();
                    $roles    = $this->roleMapper->mapMany($imageEntry->getRoles() ?? []);

                    $currentEtagNorm = null;
                    if ($isS3) {
                        $relative = $this->mediaConfig->getMediaPath(ltrim($filePath, '/'));
                        $etag = $this->s3Head->getEtag($relative);
                        $currentEtagNorm = $etag ? $norm($etag) : null;
                    }

                    $existingImage = $this->resolveByPathVariants($existingImages, $filePath);
                    $savedEtagNorm = isset($existingImage['s3_etag']) ? $norm($existingImage['s3_etag']) : null;

                    $valueData = [
                        'entity_id' => (int)$product->getId(),
                        'label'     => $label,
                        'position'  => $position,
                        'disabled'  => (int)$disabled,
                        'store_id'  => 0,
                    ];

                    if ($existingImage && isset($existingImage['record_id'])) {
                        $recordId = (int)$existingImage['record_id'];
                        $metaChanged = $this->hasValueDataChanged($existingImage, $valueData);
                        $etagChanged = $currentEtagNorm !== null && $currentEtagNorm !== $savedEtagNorm;

                        if (!$metaChanged && !$etagChanged) {
                            $stats['skipped_noop']++;
                        } else {
                            if ($metaChanged) {
                                $this->galleryResourceModel->updateValueRecord($recordId, $valueData);
                            }
                            if ($etagChanged) {
                                $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);
                            }
                            $stats['updated']++;
                        }
                    } else {
                        $valueIdToUse = $this->resolveValueIdByPathVariants($globalValueIdsByPath, $filePath);
                        if ($valueIdToUse === 0) {
                            $valueIdToUse = $this->galleryResourceModel->insertOrGetValueId(
                                (int)$galleryAttribute->getAttributeId(),
                                $filePath
                            );
                            $globalValueIdsByPath[$filePath] = $valueIdToUse;
                        }

                        $this->galleryResourceModel->createLink($valueIdToUse, (int)$product->getId());
                        $valueData['value_id'] = $valueIdToUse;
                        $this->galleryResourceModel->saveValueRecord($valueData);
                        $recordId = (int)($this->galleryResourceModel->getValueRecordId(
                            $valueIdToUse,
                            (int)$product->getId(),
                            0
                        ) ?? 0);
                        if ($recordId === 0) {
                            throw new \RuntimeException(
                                sprintf('Failed to resolve media_gallery value record_id for SKU "%s" and path "%s"', $sku, $filePath)
                            );
                        }
                        $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);
                        $stats['inserted']++;
                    }

                    foreach ($roles as $role) {
                        $rolesToUpdate[$role] = $filePath;
                    }
                }

                if ($stats['invalid'] > 0) {
                    $this->logger->warning('[NacentoConnector][GalleryProcessor] Payload contains invalid images; synchronizing managed roles from valid entries only', [
                        'sku' => $sku,
                        'invalid' => $stats['invalid'],
                    ]);
                }

                $clearRoles = [];
                foreach ($this->managedRoleSetProvider->getManagedRoles() as $roleCode) {
                    $clearRoles[$roleCode] = 'no_selection';
                }

                $this->productAction->updateAttributes([(int)$product->getId()], $clearRoles, 0);
                if (!empty($rolesToUpdate)) {
                    $this->productAction->updateAttributes([(int)$product->getId()], $rolesToUpdate, 0);
                }

                $this->galleryResourceModel->commit();
            } catch (\Throwable $e) {
                $this->galleryResourceModel->rollBack();
                throw $e;
            }
            $this->logger->info('[NacentoConnector][GalleryProcessor] SKU sync completed', [
                'sku' => $sku,
                'inserted' => $stats['inserted'],
                'updated' => $stats['updated'],
                'skipped_noop' => $stats['skipped_noop'],
                'invalid' => $stats['invalid'],
                'roles_assigned' => count($rolesToUpdate),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical(
                '[NacentoConnector][GalleryProcessor] Critical exception while syncing SKU ' . $sku . ': ' . $e->getMessage(),
                ['exception' => $e, 'sku' => $sku]
            );
            throw new CouldNotSaveException(
                __("Failed to sync gallery for SKU %1. Please review logs.", $sku),
                $e
            );
        }

        return true;
    }

    /**
     * @param array<string,mixed> $existingImage
     * @param array<string,mixed> $valueData
     */
    private function hasValueDataChanged(array $existingImage, array $valueData): bool
    {
        return (string)($existingImage['label'] ?? '') !== (string)$valueData['label']
            || (int)($existingImage['position'] ?? 0) !== (int)$valueData['position']
            || (int)($existingImage['disabled'] ?? 0) !== (int)$valueData['disabled'];
    }

    private function normalizeGalleryValuePath(string $filePath): string
    {
        $trimmed = trim($filePath);
        $trimmed = str_replace('\\', '/', $trimmed);
        $trimmed = preg_replace('#/+#', '/', $trimmed) ?? '';
        $trimmed = trim($trimmed, '/');

        return $trimmed === '' ? '' : '/' . $trimmed;
    }

    /**
     * @param array<string,mixed> $rowsByPath
     * @return array<string,mixed>|null
     */
    private function resolveByPathVariants(array $rowsByPath, string $canonicalPath): ?array
    {
        if (isset($rowsByPath[$canonicalPath]) && is_array($rowsByPath[$canonicalPath])) {
            return $rowsByPath[$canonicalPath];
        }

        $legacyPath = ltrim($canonicalPath, '/');
        if ($legacyPath !== '' && isset($rowsByPath[$legacyPath]) && is_array($rowsByPath[$legacyPath])) {
            return $rowsByPath[$legacyPath];
        }

        return null;
    }

    /**
     * @param array<string,int> $valueIdsByPath
     */
    private function resolveValueIdByPathVariants(array $valueIdsByPath, string $canonicalPath): int
    {
        if (isset($valueIdsByPath[$canonicalPath])) {
            return (int)$valueIdsByPath[$canonicalPath];
        }

        $legacyPath = ltrim($canonicalPath, '/');
        if ($legacyPath !== '' && isset($valueIdsByPath[$legacyPath])) {
            return (int)$valueIdsByPath[$legacyPath];
        }

        return 0;
    }
}
