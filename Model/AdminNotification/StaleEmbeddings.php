<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\AdminNotification;

use JALabs\SymSearch\Model\StaleState;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

/** Persistent admin banner shown while embedding settings differ from the last embed. */
class StaleEmbeddings implements MessageInterface
{
    public function __construct(
        private readonly StaleState $staleState,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getIdentity(): string
    {
        return md5('JALabs_SymSearch::stale_embeddings');
    }

    public function isDisplayed(): bool
    {
        return $this->staleState->isStale();
    }

    public function getText(): string
    {
        $url = $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'symsearch']);
        return (string)__(
            'SymSearch: embedding settings changed since the last generation. '
            . 'Open <a href="%1">Semantic Search &rarr; Operations</a> and run "Force re-embed", '
            . 'then reindex the catalog search index once coverage recovers.',
            $url
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
