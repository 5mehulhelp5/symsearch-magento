<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model;

use JALabs\SymSearch\Model\VectorCodec;
use PHPUnit\Framework\TestCase;

class VectorCodecTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $codec = new VectorCodec();
        $vector = [0.123, -0.5, 1.0, 0.0, 42.25];
        $decoded = $codec->decode($codec->encode($vector));
        $this->assertCount(5, $decoded);
        foreach ($vector as $i => $value) {
            $this->assertEqualsWithDelta($value, $decoded[$i], 0.0001);
        }
    }

    public function testEncodedSizeIsFourBytesPerFloat(): void
    {
        $codec = new VectorCodec();
        $this->assertSame(512 * 4, strlen($codec->encode(array_fill(0, 512, 0.5))));
    }
}
