<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Magento\Framework\App\DeploymentConfig;
use Nacento\Connector\Model\Storage\KeyResolver; // <-- usa el KeyResolver
use Psr\Log\LoggerInterface;

/**
 * Lightweight S3 HEAD client to fetch object ETags using Magento's remote_storage config.
 */
class S3HeadClient
{
    private ?S3Client $client = null;
    private ?string $bucket = null;

    public function __construct(
        private DeploymentConfig $deployConfig,
        private LoggerInterface $logger,
        private KeyResolver $keyResolver
    ) {}

    /**
     * Returns the ETag for a given path/URL. The input may be:
     *  - Logical Media Path (e.g. "catalog/product/a/b/img.jpg")
     *  - Full URL (https://...), s3://..., or paths with/without "media/" or "pub/media/"
     */
    public function getEtag(string $pathOrUrl): ?string
    {
        // 1) Normalize to LMP and build the S3 object key ("media/<lmp>")
        $lmp = $this->keyResolver->toLmp($pathOrUrl);
        if ($lmp === '') {
            $this->logger->debug('[NacentoConnector][S3Head] empty input after normalization, skipping');
            return null;
        }
        $key = $this->keyResolver->lmpToObjectKey($lmp);

        try {
            // 2) Lazy init S3 client + bucket
            $client = $this->getClient();
            $bucket = $this->getBucket();

            if (!$client || !$bucket) {
                $this->logger->debug('[NacentoConnector][S3Head] Client or bucket not available, aborting');
                return null;
            }

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD start | bucket=%s lmp=%s key=%s',
                $bucket,
                $lmp,
                $key
            ));

            // 3) HEAD object
            $res  = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            $etag = $res['ETag'] ?? null;

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD ok | ETag=%s LastModified=%s ContentLength=%s',
                is_string($etag) ? $etag : 'NULL',
                isset($res['LastModified']) ? (string)$res['LastModified'] : 'NULL',
                (string)($res['ContentLength'] ?? 'NULL')
            ));

            // 4) Normalize quotes around ETag
            return is_string($etag) ? trim($etag, '"') : null;

        } catch (AwsException $e) {
            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] AWS error | code=%s msg=%s status=%s requestId=%s',
                (string)$e->getAwsErrorCode(),
                $e->getAwsErrorMessage() ?: $e->getMessage(),
                (string)($e->getStatusCode() ?? 'NA'),
                (string)($e->getAwsRequestId() ?? 'NA')
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('[NacentoConnector][S3Head] headObject failed: ' . $e->getMessage());
        }

        return null;
    }

    // ------------------------ helpers ------------------------

    private function getClient(): ?S3Client
    {
        if ($this->client) {
            return $this->client;
        }

        [$driver, $conf] = $this->readRemoteStorageConfig();

        $this->logger->debug(sprintf(
            '[NacentoConnector][S3Head] Config | driver=%s endpoint=%s region=%s pathStyle=%s bucket=%s hasKey=%s hasSecret=%s',
            $driver ?? 'NULL',
            (string)($conf['endpoint'] ?? 'NULL'),
            (string)($conf['region'] ?? 'NULL'),
            !empty($conf['use_path_style_endpoint']) ? 'true' : 'false',
            (string)($conf['bucket'] ?? 'NULL'),
            isset($conf['credentials']['key']) ? 'yes' : 'no',
            isset($conf['credentials']['secret']) ? 'yes' : 'no'
        ));

        if (
            $driver !== 'aws-s3' ||
            empty($conf['endpoint']) ||
            empty($conf['credentials']['key']) ||
            empty($conf['credentials']['secret'])
        ) {
            $this->logger->debug('[NacentoConnector][S3Head] Incomplete remote_storage config.');
            return null;
        }

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => (string)($conf['region'] ?? 'auto'),
            'endpoint'                => (string)$conf['endpoint'],
            'use_path_style_endpoint' => (bool)($conf['use_path_style_endpoint'] ?? true),
            'credentials'             => [
                'key'    => (string)$conf['credentials']['key'],
                'secret' => (string)$conf['credentials']['secret'],
            ],
        ]);

        return $this->client;
    }

    private function getBucket(): ?string
    {
        if ($this->bucket !== null) {
            return $this->bucket;
        }
        [, $conf]   = $this->readRemoteStorageConfig();
        $this->bucket = isset($conf['bucket']) ? (string)$conf['bucket'] : null;
        return $this->bucket;
    }

    /**
     * Read & normalize env.php 'remote_storage' config.
     * Returns [driverName, configArray].
     */
    private function readRemoteStorageConfig(): array
    {
        $rs     = $this->deployConfig->get('remote_storage');
        $driver = is_array($rs) ? ($rs['driver'] ?? null) : null;

        $conf = [];
        if (is_array($rs)) {
            if (isset($rs['config']) && is_array($rs['config'])) {
                $conf = $rs['config'];
            } elseif (isset($rs['aws_s3']) && is_array($rs['aws_s3'])) {
                $conf = $rs['aws_s3']; // older/custom layouts
            }
        }
        if (!isset($conf['credentials']) || !is_array($conf['credentials'])) {
            $conf['credentials'] = [];
        }

        return [$driver, $conf];
    }
}
