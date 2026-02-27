# Nacento Connector

*A Magento 2.4.8 module to synchronize product image galleries from a PIM, using S3/R2 as the source of truth and optimizing performance through ETag change detection and asynchronous bulk processing.*

> **ALPHA – HIGHLY EXPERIMENTAL**  
> This project is **alpha-stage** and **not suitable for production**. Use at your own risk.  
> If you have questions, please open an **Issue** – but **do not expect quick responses**.

---

## What is this?

This is a custom Magento 2.4.8 module that exposes a Web API to **synchronize product image galleries** between a PIM (like Akeneo) and Magento, using an S3-compatible object storage (like Cloudflare R2) as the source of truth for the image files.

The primary goal is to **bypass Magento's native image processing and copying**, drastically reducing bandwidth, processing time, and accelerating gallery updates, especially for large catalogs.

## Evolution and Key Concepts

This module has evolved from a simple single-SKU endpoint into a more comprehensive bulk processing system focused on performance and flexibility.

1.  **Bulk Processing:** The module offers a bulk endpoint to process hundreds or thousands of SKUs in a single request.
2.  **Asynchronous Processing:** Requests are queued via Magento's Message Queue for background processing, which is ideal for very large workloads.
3.  **ETag Change Detection:** The module uses a lightweight S3 client to perform `HEAD` requests and retrieve the **ETag** of each image. This allows it to detect if a file's content has actually changed, avoiding unnecessary database writes and only updating metadata if the file itself is unchanged.

---

## Status

- **Stage:** Alpha (breaking changes can happen anytime)
- **Target Magento:** 2.4.8 (PHP 8.1/8.2/8.3)
- **License:** MIT

---

## Features

- **One REST Web API Endpoint:**
    - **Asynchronous bulk** processing via Magento's Message Queue.
- **Recent Robustness Improvements (internal, payload unchanged):**
    - Shared payload normalization and validation before queueing.
    - Stable `operation_key` generation for safer `magento_operation` status updates.
    - Transactional per-SKU gallery writes (best effort atomicity).
    - Retry classification (retriable vs non-retriable queue failures).
    - Payload-owned managed roles (clear then reassign from payload).
- **Performance Optimization:**
    - Skips Magento’s image processing for improved speed.
    - Uses a dedicated **S3/R2 client** for `HEAD` requests to check **ETags** and skips no-op writes when nothing changed.
- **Complete Gallery Management:**
    - Assigns **roles** (`base`, `small_image`, `thumbnail`, `swatch_image`), **labels**, **position**, and **disabled** status.
- **External Source of Truth:**
    - Works directly with file paths in S3/R2 storage, with no binary uploads to Magento.

---

## Compatibility (Tested)

This module has been **only tested** under the following environment:

- **Magento Open Source:** 2.4.8
- **PHP:** 8.3-fpm
- **Web Server:** nginx 1.24
- **Message Queue:** RabbitMQ 4.1
- **Cache:** Valkey 8.1
- **Database:** MariaDB 11.4
- **Search Engine:** OpenSearch 2.12

> ⚠️ Other versions of Magento, PHP, or related services **have not been tested**.  
> Use in different environments **at your own risk**, in fact, use it in general at your own risk.

---
## Prerequisite: Configure Remote Storage (S3/R2/MinIO)

For this module to work in its **optimized mode** (ETag change detection + external source-of-truth workflow), Magento should be configured to use an S3-compatible remote storage driver in `app/etc/env.php`.

Without remote storage, asynchronous bulk processing still works, but ETag-based no-op optimization is skipped.

Example (S3/MinIO/R2 compatible):

```php
'remote_storage' => [
    'driver' => 'aws-s3',
    'config' => [
        'bucket' => 'your-catalog-bucket',
        'region' => 'auto',
        'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'bucket_endpoint' => false,
        'credentials' => [
            'key' => 'your-access-key',
            'secret' => 'your-secret-key'
        ]
    ]
],
```

---

## S3 Bucket Layout & Paths (Required)

Magento’s S3 storage driver treats the **bucket root as `pub/media`**.
That means product images must live under:

```text
<bucket>/media/catalog/product/<files>
```

This module will not fight against this established behaviour so if you would like to use it, you should adapt your S3 configuration so both Magento and your PIM access the same path.

Akeneo S3 adapter based on Symfony Framework can be configured by setting a **prefix**:

```yaml
oneup_flysystem:
  adapters:
    catalog_storage_adapter:
      awss3v3:
        client: 'Aws\S3\S3Client'
        bucket: 'catalog'                 # your bucket name
        prefix: 'media/catalog/product'   # required for Magento compatibility
```

With this, Akeneo will write to:

```text
<bucket>/media/catalog/product/<files>
```

---

## Installation

Add the repository to your Magento project's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:xturavaina/nacento-connector.git" }
  ]
}
```

Then, install and enable the module:

```bash
composer require nacento/connector:*
bin/magento module:enable Nacento_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```



> ⚠️ ***IMPORTANT***: For the bulk async operations to work you should setup a consumer on your cron, supervisord or whatever you use to process queues.

A potential minimalist entry for supervisord will look like:


```yaml
[program:magento-consumer-nacento-gallery]
command=php bin/magento queue:consumers:start nacento.gallery.consumer 
directory=/var/www/html
autostart=true
autorestart=true
stdout_logfile=/var/log/magento-consumers/consumer_nacento_gallery.log
stderr_logfile=/var/log/magento-consumers/consumer_nacento_gallery.err
```

However, your environment and settings may differ. Adapt accordingly.

## Uninstallation

You can uninstall the module in two ways: removing only the code (leaving the database table intact) or performing a complete removal that also cleans up the database, the queue and the exchange in RabbitMQ

### Option 1: Remove Code Only (Standard Method)

This is the standard Magento process. It will remove the module's code, but the `nacento_media_gallery_meta` database table will be preserved in case you decide to reinstall the module later or you forgot to make a backup.

```bash
composer remove nacento/connector
bin/magento setup:upgrade
```

---

### Option 2: Complete Removal (Code + Database Table + RabbitMQ queue and Exchange)

> **Warning:** This process is irreversible and will permanently delete the `nacento_media_gallery_meta` table and all its data.

This repository currently **does not ship a custom uninstall script** for queue/exchange cleanup. You can still use Magento's `module:uninstall` command, but RabbitMQ queue/exchange cleanup should be treated as a **manual step** (see below).

```bash
bin/magento module:uninstall Nacento_Connector --remove-data
```

---

### Cleaning Up RabbitMQ (Manual Step)

If queue was not deleted, you can manually remove queues or exchanges from your RabbitMQ server. This must be done to ensure a completely clean environment.

Follow these steps after uninstalling the module:
```
1.  Log in to the RabbitMQ Management UI (typically at `http://your-server:15672`).
2.  Navigate to the **Queues** tab.
3.  Find and click on the queue named `nacento.gallery.process`.
4.  Scroll to the bottom of the page and click the **Delete** button.
5.  Navigate to the **Exchanges** tab.
6.  Find and click on the exchange named `nacento.gallery.process`.
7.  Scroll to the bottom of the page and click the **Delete** button.
```

---

## Message Queue & Consumers

This module uses a topic named **`nacento.gallery.process`** (publisher) and a consumer named **`nacento.gallery.consumer`** (listens to queue `nacento.gallery.process`).

Current module configuration pins the consumer to **AMQP** (`connection="amqp"` in `etc/queue_consumer.xml`), so RabbitMQ (or another AMQP broker) is required unless you customize this module.

Common commands:

see all consumers
```bash
bin/magento queue:consumers:list
```

start the connector consumer

```bash
bin/magento queue:consumers:start nacento.gallery.consumer -vvv
```

Publishing does not require a running consumer; messages will queue up and be processed when the consumer runs.

--- 
## API Endpoints

The module exposes a single endpoint for asynchronous bulk processing. Please check `etc/webapi.xml` for the definitive definitions.

### Bulk Processing (Asynchronous)

Submits a batch to Magento's message queue for background processing. The response is immediate and contains a `bulk_uuid` for tracking. This is the best option for large batches.

- **Endpoint:** `POST /rest/V1/nacento-connector/products/media/bulk/async`
- **Sample Payload:**

```json
{
  "request": {
    "request_id": "op-12345",
    "items": [
      {
        "sku": "SKU-001",
        "images": [{ "file_path": "...", "label": "...", "roles": ["base"] }]
      },
      {
        "sku": "SKU-002",
        "images": [{ "file_path": "...", "label": "...", "roles": ["base"] }]
      }
    ]
  }
}
```

- **Sample Response:**
```json
{
    "bulk_uuid": "f8d3c1a3-5b8e-4a9f-8c7e-1a2b3c4d5e6f",
    "request_items": [
        { "id": 1, "data_hash": "...", "status": "accepted", "error_message": null },
        { "id": 2, "data_hash": "...", "status": "accepted", "error_message": null }
    ],
    "errors": false
}
```

> Note: `request_items` are acknowledgment entries, not a strict mirror of input order/cardinality. With SKU deduplication (last-wins) and validation rejection, the number of returned items may differ from the number submitted.

### Behavior Notes (Current Implementation)

- **Payload is the public contract** and remains unchanged.
- **Bulk dedupe by SKU is last-wins** (only the last valid item for a SKU is queued).
- Invalid items (for example empty `sku`, empty `images`, or images without `file_path`) are **rejected in the async acknowledgment**, and `errors` may be `true`.
- `request_items[].data_hash` is a stable hash derived from SKU (or fallback item id when SKU is empty). It is intended for acknowledgment correlation, not as a full payload checksum/idempotency key.
- `request_id` is accepted and propagated for **logging/correlation** (not persisted idempotency yet).
- Gallery synchronization is **upsert-only**:
  - listed images are inserted/updated
  - images not listed are **not deleted**
- Incoming image `file_path` values are normalized to Magento gallery value format (leading slash), while legacy no-leading-slash rows are still recognized for lookup compatibility.
- Managed image roles are **payload-owned**:
  - when all payload images are valid, Magento role attributes (`image`, `small_image`, `thumbnail`, `swatch_image`) are cleared first
  - then reassigned from payload entries (last role assignment wins)
  - if any payload image is invalid for the SKU, managed role synchronization is skipped to avoid accidental role wipes
- Unknown image roles in payload are ignored.

---

## Caveats & Limitations

- Assumes your frontend/media layer can resolve and serve the stored media paths correctly (CORS, CDN, permissions are your responsibility).
- Some Magento features or 3rd-party modules may **expect images to exist physically in `pub/media`**. Validate compatibility.
- This module intentionally writes gallery rows **directly** (bypassing Magento's native image import/process pipeline). Validate compatibility with modules expecting native side effects.
- Error handling and retries are improved, but this is still **alpha-stage** software and needs operational monitoring.

---

## Roadmap (subject to change)

- [ ] Enhance the the bussiness logic, as of today, the bulk sync/async is invoking the single sku processing logic, LOL!
- [ ] Enhance the statistics returned in bulk processing results. (maybe improve integration with magento default uuid)
- [ ] Expand integration/E2E test coverage (queue + DB + S3 driver scenarios).

---

## Troubleshooting

- **“Data in topic must be of type OperationInterface”**  
  Your topic is typed (Async/Bulk). The module publishes a valid `OperationInterface`, so this should only happen if custom topology overrides were installed. Re-run `bin/magento setup:upgrade`.

- **No messages seen in RabbitMQ logs**  
  Magento validates message type & mapping **before** connecting to AMQP. Check your `etc/queue.xml`, `etc/queue_consumer.xml`, and `etc/queue_publisher.xml` configuration.

- **Messages are queued but `magento_operation` statuses do not change**  
  Common causes:
  1. Consumer is not running (`nacento.gallery.consumer`).
  2. Consumer processed the message but failed before status update (check Magento logs for `GalleryConsumer` / `GalleryProcessor` errors).
  3. Status update lookup failed (this module now updates by `operation_key` first, then falls back to `id`).
  4. If the operation row no longer exists, the consumer logs the condition and drops the message without retry by design (even if the processing error was retriable).

- **`magento_bulk`, `magento_operation`, and `magento_acknowledged_bulk` look inconsistent**
  
  This is often an **async lifecycle visibility** issue, not necessarily a queue-vs-cron problem.
  
  Typical async bulk lifecycle (what Magento writes):
  1. `magento_bulk`: one row per bulk request (`bulk_uuid`, metadata, description/user context).
  2. `magento_operation`: one row per queued item/SKU (serialized payload + status transitions).
  3. `magento_acknowledged_bulk`: admin/user acknowledgment tracking for bulk notifications (not required for queue processing itself, and it may remain empty).
  
  Important notes:
  - **Queue vs cron:** RabbitMQ consumers do the actual processing. Cron is only one way to start/manage consumers (`consumers_runner`). Running the consumer under `supervisord` is valid.
  - If the consumer is down, `magento_bulk` and `magento_operation` rows may exist while RabbitMQ still has pending messages.
  - If RabbitMQ is empty but operations remain open, inspect Magento logs and status update behavior.
  - `magento_acknowledged_bulk` is not the source of truth for whether your SKU/gallery processing completed.

- **Quick DB checks for async bulk debugging**
  
  Replace `<bulk_uuid>` with the UUID returned by the API:
  ```sql
  SELECT * FROM magento_bulk WHERE uuid = '<bulk_uuid>';
  
  SELECT id, bulk_uuid, topic_name, operation_key, status, error_code, result_message
  FROM magento_operation
  WHERE bulk_uuid = '<bulk_uuid>'
  ORDER BY id;
  
  SELECT * FROM magento_acknowledged_bulk WHERE bulk_uuid = '<bulk_uuid>';
  ```
  
  Also verify:
  - RabbitMQ queue depth for `nacento.gallery.process`
  - consumer process health for `bin/magento queue:consumers:start nacento.gallery.consumer`

## Recent Refactor Notes

The latest internal refactor preserves the external payload but changes internal behavior:

- Shared request/image normalization and validation before queueing.
- Stable `operation_key` generation for async operations.
- Module-local `magento_operation` status updater (no global override of Magento bulk operation services).
- Retriable vs non-retriable failure classification in the queue consumer.
- Per-SKU transactions and no-op skipping when ETag/metadata is unchanged.


---
## License

**MIT © Nacento**
