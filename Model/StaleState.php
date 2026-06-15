<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Tracks whether the embedding settings have drifted from what was last embedded.
 * "Signature" = a hash of every setting that changes the produced embedding.
 */
class StaleState
{
    private const XML_EMBEDDED_SIGNATURE = 'symsearch/state/embedded_signature';

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function currentSignature(): string
    {
        return sha1(implode('|', [
            $this->config->getProviderCode(),
            $this->config->getModel(),
            (string)$this->config->getDimensions(),
            implode(',', $this->config->getEmbedAttributes()),
        ]));
    }

    public function getEmbeddedSignature(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_EMBEDDED_SIGNATURE);
    }

    public function markSynced(): void
    {
        $this->configWriter->save(self::XML_EMBEDDED_SIGNATURE, $this->currentSignature());
    }

    public function markSyncedIfUnset(): void
    {
        if ($this->getEmbeddedSignature() === '') {
            $this->markSynced();
        }
    }

    /** Stale only when a baseline exists AND it no longer matches current settings. */
    public function isStale(): bool
    {
        $stored = $this->getEmbeddedSignature();
        return $stored !== '' && $stored !== $this->currentSignature();
    }
}
