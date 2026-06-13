<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model;

/** Packs float vectors to little-endian float32 blobs for MariaDB storage. */
class VectorCodec
{
    public function encode(array $vector): string
    {
        return pack('g*', ...array_map('floatval', $vector));
    }

    /** @return float[] */
    public function decode(string $blob): array
    {
        return array_values(unpack('g*', $blob) ?: []);
    }
}
