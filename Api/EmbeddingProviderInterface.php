<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Api;

interface EmbeddingProviderInterface
{
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_QUERY    = 'query';

    public function getCode(): string;

    /**
     * @param string[] $texts
     * @return float[][] one vector per input text, same order
     * @throws \JALabs\SymSearch\Exception\ProviderException
     */
    public function embed(array $texts, int $timeoutMs = 30000, string $inputType = self::TYPE_DOCUMENT): array;
}
