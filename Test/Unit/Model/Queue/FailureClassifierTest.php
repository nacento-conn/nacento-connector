<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Queue;

use Nacento\Connector\Model\Queue\FailureClassifier;
use PHPUnit\Framework\TestCase;

class FailureClassifierTest extends TestCase
{
    public function testDeadlockIsRetriable(): void
    {
        $classifier = new FailureClassifier();

        self::assertTrue($classifier->isRetriable(new \RuntimeException('SQLSTATE[40001]: deadlock found')));
    }

    public function testMissingFileIsNotRetriable(): void
    {
        $classifier = new FailureClassifier();

        self::assertFalse($classifier->isRetriable(new \RuntimeException('File does not exist')));
    }

    public function testPreviousExceptionCanTriggerRetriable(): void
    {
        $classifier = new FailureClassifier();
        $previous = new \RuntimeException('Service unavailable');
        $wrapped = new \RuntimeException('Top level failed', 0, $previous);

        self::assertTrue($classifier->isRetriable($wrapped));
    }
}
