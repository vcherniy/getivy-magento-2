<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\ViewModel;

class Banner extends \Magento\Framework\DataObject implements \Magento\Framework\View\Element\Block\ArgumentInterface
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

    /**
     * @return string
     */
    public function getLogo()
    {
        return $this->logo->getLogoSrc();
    }

    public function getMcc()
    {
        return  $this->config->getMcc();
    }

    public function getLocale()
    {
        return  $this->config->getLocale();
    }
}