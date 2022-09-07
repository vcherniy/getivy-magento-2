<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Plugin\Frontend\Magento\Checkout\CustomerData;

class Cart
{
    protected $checkoutSession;
    protected $config;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Esparksinc\IvyPayment\Model\Config $config
        ){
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
    }

    public function afterGetSectionData(
        \Magento\Checkout\CustomerData\Cart $subject,
        $result
    ) {
        $quote = $this->checkoutSession->getQuote();
        $currencyCode = $quote->getStore()->getCurrentCurrencyCode();
        $categoryCode = $this->config->getMcc();
        $locale = $this->config->getLocale();
        
        // Add express button if express checkout is enable
        if($this->config->isActive())
        {
            $ivyButton = '
            <button 
            class="ivy-checkout-button ivy-express-minicart-button" 
            data-cart-value="'.$result['subtotalAmount'].'"
            data-shop-category="'.$categoryCode.'"
            data-currency-code="'.$currencyCode.'"
            data-locale="'.$locale.'"
            style="visibility: hidden">
            </button>';
            $result['extra_actions'] = $ivyButton.$result['extra_actions'];
        }
        
        return $result;
    }
}