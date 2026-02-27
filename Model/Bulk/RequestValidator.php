<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

class RequestValidator
{
    private const MAX_FILE_PATH_LENGTH = 1024;
    private const MAX_LABEL_LENGTH = 255;
    private const MIN_POSITION = -100000;
    private const MAX_POSITION = 100000;

    public function normalizeRequestId(?string $requestId): ?string
    {
        if ($requestId === null) {
            return null;
        }

        $requestId = trim($requestId);
        return $requestId !== '' ? $requestId : null;
    }

    public function normalizeSku(string $sku): string
    {
        return trim($sku);
    }

    /**
     * @param array<string,mixed> $image
     */
    public function validateImage(array $image): ?string
    {
        $filePath = trim((string)($image['file_path'] ?? ''));
        if ($filePath === '') {
            return 'Missing file_path';
        }
        if (strlen($filePath) > self::MAX_FILE_PATH_LENGTH) {
            return 'file_path is too long';
        }

        $label = (string)($image['label'] ?? '');
        if (strlen($label) > self::MAX_LABEL_LENGTH) {
            return 'label is too long';
        }

        $position = (int)($image['position'] ?? 0);
        if ($position < self::MIN_POSITION || $position > self::MAX_POSITION) {
            return 'position is out of allowed range';
        }

        return null;
    }
}
