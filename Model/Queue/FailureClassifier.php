<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

class FailureClassifier
{
    public function isRetriable(\Throwable $e): bool
    {
        foreach ($this->exceptionChain($e) as $current) {
            $message = strtolower($current->getMessage());

            if (
                str_contains($message, 'deadlock') ||
                str_contains($message, 'lock wait timeout') ||
                str_contains($message, 'server has gone away') ||
                str_contains($message, 'connection refused') ||
                str_contains($message, 'timed out') ||
                str_contains($message, 'timeout') ||
                str_contains($message, 'temporarily unavailable') ||
                str_contains($message, 'throttl') ||
                str_contains($message, 'too many requests') ||
                str_contains($message, 'service unavailable')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,\Throwable>
     */
    private function exceptionChain(\Throwable $e): array
    {
        $chain = [];
        $seen = [];

        while ($e !== null) {
            $oid = spl_object_id($e);
            if (isset($seen[$oid])) {
                break;
            }
            $seen[$oid] = true;
            $chain[] = $e;
            $e = $e->getPrevious();
        }

        return $chain;
    }
}
