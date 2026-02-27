<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Storage;

use Nacento\Connector\Model\Storage\KeyResolver;
use PHPUnit\Framework\TestCase;

class KeyResolverTest extends TestCase
{
    public function testToLmpStripsUrlQueryAndFragment(): void
    {
        $resolver = new KeyResolver();

        $lmp = $resolver->toLmp('https://cdn.example.com/media/catalog/product/a/b/test.jpg?X-Amz-Signature=abc#frag');

        self::assertSame('a/b/test.jpg', $lmp);
    }

    public function testToLmpSupportsMediaPrefixedPathsWithQuery(): void
    {
        $resolver = new KeyResolver();

        $lmp = $resolver->toLmp('media/catalog/product/0/1/test.jpg?v=2');

        self::assertSame('0/1/test.jpg', $lmp);
    }
}

