<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Indexer;

use JALabs\SymSearch\Model\Config;
use Magento\Catalog\Model\Product;

/** Builds the text that gets embedded for a product, from configured attributes. */
class TextBuilder
{
    private const MAX_CHARS = 4000;

    public function __construct(private readonly Config $config)
    {
    }

    public function build(Product $product): string
    {
        $parts = [];
        foreach ($this->config->getEmbedAttributes() as $code) {
            $value = null;
            $attribute = $product->getResource()->getAttribute($code);
            if ($attribute && $attribute->usesSource()) {
                $value = $product->getAttributeText($code);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
            }
            if (!$value) {
                $value = $product->getData($code);
            }
            if (!is_scalar($value)) {
                continue;
            }
            $clean = trim((string)preg_replace('~\s+~u', ' ', strip_tags(html_entity_decode((string)$value))));
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }

        return mb_substr(implode("\n", $parts), 0, self::MAX_CHARS);
    }
}
