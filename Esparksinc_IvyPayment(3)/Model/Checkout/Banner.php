<?php

namespace Esparksinc\IvyPayment\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;

class Banner implements ConfigProviderInterface
{
    protected $logo;
    protected $config;
    /**
     * Banner constructor.
     *
     */
    public function __construct(
        \Magento\Theme\Block\Html\Header\Logo $logo,
        \Esparksinc\IvyPayment\Model\Config $config
    )
    {        
        $this->logo = $logo;
        $this->config = $config;
    }

    public function getConfig()
    {
        $additionalVariables['logo'] = $this->logo->getLogoSrc();
        $additionalVariables['mcc'] = $this->config->getMcc();
        $additionalVariables['locale'] = $this->config->getLocale();
        $additionalVariables['is_active'] = $this->config->isActive()?true:false;
        return $additionalVariables;
    }
}