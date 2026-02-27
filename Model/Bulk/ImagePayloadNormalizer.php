<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\ImageEntryInterface;
use Psr\Log\LoggerInterface;

class ImagePayloadNormalizer
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<int, mixed> $images
     * @return array<int, array{file_path:string,label:string,disabled:bool,position:int,roles:array<int,string>}>
     */
    public function normalizeList(array $images): array
    {
        $out = [];

        foreach ($images as $idx => $image) {
            $row = $this->normalizeOne($image);
            if ($row === null) {
                $this->logger->warning(
                    sprintf('[NacentoConnector][ImagePayloadNormalizer] Unexpected image payload at index %d: %s', $idx, gettype($image))
                );
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param mixed $image
     * @return array{file_path:string,label:string,disabled:bool,position:int,roles:array<int,string>}|null
     */
    public function normalizeOne($image): ?array
    {
        if ($image instanceof ImageEntryInterface) {
            return [
                'file_path' => trim((string)$image->getFilePath()),
                'label' => (string)$image->getLabel(),
                'disabled' => (bool)$image->isDisabled(),
                'position' => (int)$image->getPosition(),
                'roles' => $this->normalizeRoles($image->getRoles()),
            ];
        }

        if ($image instanceof DataObject) {
            /** @var array<string,mixed> $data */
            $data = (array)$image->getData();
            return $this->normalizeArrayRow($data);
        }

        if (is_array($image)) {
            return $this->normalizeArrayRow($image);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{file_path:string,label:string,disabled:bool,position:int,roles:array<int,string>}
     */
    private function normalizeArrayRow(array $row): array
    {
        return [
            'file_path' => trim((string)($row['file_path'] ?? '')),
            'label' => (string)($row['label'] ?? ''),
            'disabled' => $this->toBool($row['disabled'] ?? false),
            'position' => $this->toInt($row['position'] ?? 0),
            'roles' => $this->normalizeRoles($row['roles'] ?? []),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeRoles($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($value as $role) {
            if (!is_scalar($role)) {
                continue;
            }
            $normalized = strtolower(trim((string)$role));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $out[] = $normalized;
        }
        return $out;
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $val = strtolower(trim($value));
            return in_array($val, ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($value);
    }

    /**
     * @param mixed $value
     */
    private function toInt($value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
