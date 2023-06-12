<?php

namespace Esparksinc\IvyPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    const CONFIG_IVY_ACTIVE = 'payment/ivy/active';

    const CONFIG_IVY_APP_ID = 'payment/ivy/app_id';
    const CONFIG_IVY_MCC = 'payment/ivy/mcc';
    const CONFIG_IVY_SANDBOX = 'payment/ivy/sandbox';
    const CONFIG_IVY_API_KEY_SANDBOX = 'payment/ivy/sandbox_api_key';
    const CONFIG_IVY_API_KEY_LIVE = 'payment/ivy/live_api_key';
    const CONFIG_IVY_WEBHOOK_SANDBOX = 'payment/ivy/sandbox_webhook_secret';
    const CONFIG_IVY_WEBHOOK_LIVE = 'payment/ivy/webhook_secret';

    const CONFIG_IVY_MAP_WFP_STATUS = 'payment/ivy/map_waiting_for_payment_status';

    const IVY_API_URL_LIVE = 'https://api.getivy.de/api/service/';
    const IVY_API_URL_SANDBOX = 'https://api.sand.getivy.de/api/service/';

    const IVY_PRODUCT_THEME = 'payment/ivy/frontend_settings/product_theme';
    const IVY_CART_THEME = 'payment/ivy/frontend_settings/cart_theme';
    const IVY_MINICART_THEME = 'payment/ivy/frontend_settings/minicart_theme';
    

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    protected $_store;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        DirectoryList $directoryList,
        EncryptorInterface $encryptor,
        \Magento\Framework\Locale\Resolver $store
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->directoryList = $directoryList;
        $this->encryptor = $encryptor;
        $this->_store = $store;
    }

    /**
     * @param $field
     * @param null $storeId
     * @return mixed
     */
    private function getValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null)
    {
        return $this->getValue(self::CONFIG_IVY_ACTIVE, $storeId);
    }

    public function getAppId($storeId = null)
    {
        return $this->getValue(self::CONFIG_IVY_APP_ID, $storeId);
    }

    public function getMcc($storeId = null)
    {
        return $this->getValue(self::CONFIG_IVY_MCC, $storeId);
    }

    public function getSandbox($storeId = null)
    {
        return $this->getValue(self::CONFIG_IVY_SANDBOX, $storeId);
    }

    public function getMapWaitingForPaymentStatus($storeId = null)
    {
        return $this->getValue(self::CONFIG_IVY_MAP_WFP_STATUS, $storeId);
    }

    public function getLocale($storeId = null)
    {
        $currentStore = $this->_store->getLocale();
        if (strpos($currentStore, 'en_') !== false) {
            return 'en';
        }
        else
        {
            return 'de';
        }
    }

    public function getApiKey($storeId = null)
    {
        $sandbox = $this->getSandbox($storeId);
        switch ($sandbox) {
            case 0:
                return $this->getValue(self::CONFIG_IVY_API_KEY_LIVE, $storeId);
            case 1:
                return $this->getValue(self::CONFIG_IVY_API_KEY_SANDBOX, $storeId);
        }
    }

    public function getWebhookSecret($storeId = null)
    {
        $sandbox = $this->getSandbox($storeId);
        switch ($sandbox) {
            case 0:
                return $this->getValue(self::CONFIG_IVY_WEBHOOK_LIVE, $storeId);
            case 1:
                return $this->getValue(self::CONFIG_IVY_WEBHOOK_SANDBOX, $storeId);
        }
    }

    public function getApiUrl($storeId = null)
    {
        $sandbox = $this->getSandbox($storeId);
        switch ($sandbox) {
            case 0:
                return self::IVY_API_URL_LIVE;
            case 1:
                return self::IVY_API_URL_SANDBOX;
        }
    }

    public function getProductTheme($storeId = null)
    {
        $productTheme = $this->getValue(self::IVY_PRODUCT_THEME, $storeId);
        switch ($productTheme) {
            case 0:
                return "dark";
            case 1:
                return "light";
        }
    }

    public function getCartTheme($storeId = null)
    {
        $productTheme = $this->getValue(self::IVY_CART_THEME, $storeId);
        switch ($productTheme) {
            case 0:
                return "dark";
            case 1:
                return "light";
        }
    }

    public function getMinicartTheme($storeId = null)
    {
        $productTheme = $this->getValue(self::IVY_MINICART_THEME, $storeId);
        switch ($productTheme) {
            case 0:
                return "dark";
            case 1:
                return "light";
        }
    }
}
