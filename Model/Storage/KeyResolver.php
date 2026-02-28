<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Storage;

class KeyResolver
{
    /**
     * Converts arbitrary input (URL, s3://, with/without media prefixes)
     * to the catalog/product tail path, for example:
     *   "0/0/0/0/0000ea..._732451_4.jpg"
     *
     * Supports inputs like:
     *  - "pub/media/catalog/product/0/0/0/0/....jpg"
     *  - "media/catalog/product/0/0/0/0/....jpg"
     *  - "catalog/product/0/0/0/0/....jpg"
     *  - "0/0/0/0/....jpg"
     *  - http(s) URLs or s3://...
     */
    public function toLmp(string $input): string
    {
        $val = trim($input);
        // Drop schema/host when input is a URL.
        $val = preg_replace('#^https?://[^/]+/#i', '', $val);
        $val = preg_replace('#^s3://[^/]+/#i', '', $val);
        // Remove query string / fragment if present.
        $val = preg_replace('#[\\?\\#].*$#', '', (string)$val);
        $val = ltrim((string)$val, '/');

        // Normalize when prefixed with pub/media or media.
        if (str_starts_with($val, 'pub/media/')) {
            $val = substr($val, 10); // len('pub/media/') = 10
        } elseif (str_starts_with($val, 'media/')) {
            $val = substr($val, 6);  // len('media/') = 6
        }

        // If catalog/product is still present, strip it to keep only the tail.
        if (str_starts_with($val, 'catalog/product/')) {
            $val = substr($val, strlen('catalog/product/'));
        }

        // Collapse duplicate separators and strip leading slash.
        $val = preg_replace('#/+#', '/', (string)$val);
        $val = ltrim((string)$val, '/');

        return $val;
    }

    /**
     * Builds the S3 object key from the tail path:
     * always "media/catalog/product/<tail>".
     */
    public function lmpToObjectKey(string $lmp): string
    {
        $tail = ltrim($lmp, '/');
        return 'media/catalog/product/' . $tail;
    }
}
