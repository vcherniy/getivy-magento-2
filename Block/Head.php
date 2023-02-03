<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Page\Config;

class Head extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        Config $pageConfig,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareLayout()
    {
        if ((int)$this->_scopeConfig->getValue('payment/ivy/sandbox')) {
            $this->pageConfig->addRemotePageAsset(
                'https://cdn.sand.getivy.de/banner.js',
                'js'
            );
            $this->pageConfig->addRemotePageAsset(
                'https://cdn.sand.getivy.de/button.js',
                'js'
            );
        } else {
            $this->pageConfig->addRemotePageAsset(
                'https://cdn.getivy.de/banner.js',
                'js'
            );
            $this->pageConfig->addRemotePageAsset(
                'https://cdn.getivy.de/button.js',
                'js'
            );
        }
        return parent::_prepareLayout();
    }
}
