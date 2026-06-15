<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Panel extends Field
{
    protected $_template = 'JALabs_SymSearch::system/config/panel.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getStatusUrl(): string
    {
        return $this->getUrl('jalabs_symsearch/embedding/status');
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('jalabs_symsearch/embedding/generate');
    }

    public function getForceUrl(): string
    {
        return $this->getUrl('jalabs_symsearch/embedding/force');
    }

    public function getPipelineUrl(): string
    {
        return $this->getUrl('jalabs_symsearch/embedding/pipeline');
    }
}
