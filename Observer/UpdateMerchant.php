<?php

namespace Esparksinc\IvyPayment\Observer;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use GuzzleHttp\Client;

class UpdateMerchant implements ObserverInterface
{
    private $request;
    private $configWriter;
    protected $config;
    protected $json;
    protected $storeManager;
    protected $logo;
    protected $emulation;
    protected $scopeConfig;
    protected $urlBuilder;
    protected $apiHelper;

    public function __construct(
        RequestInterface $request,
        WriterInterface $configWriter,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Theme\Block\Html\Header\Logo $logo,
        \Magento\Store\Model\App\Emulation $emulation,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder,
        ApiHelper $apiHelper
    )
    {
        $this->request = $request;
        $this->configWriter = $configWriter;
        $this->config = $config;
        $this->json = $json;
        $this->logo = $logo;
        $this->storeManager = $storeManager;
        $this->emulation = $emulation;
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
        $this->apiHelper = $apiHelper;
    }
    public function execute(EventObserver $observer)
    {
        // Create data to set in api
        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $data = [
            'successCallbackUrl'    => $frontendUrl.'ivypayment/success',
            'errorCallbackUrl'      => $frontendUrl.'ivypayment/fail',
            'quoteCallbackUrl'      => $frontendUrl.'ivypayment/quote',
            'webhookUrl'            => $frontendUrl.'ivypayment/webhook',
            'completeCallbackUrl'   => $frontendUrl.'ivypayment/order/complete',
            'shopLogo'              => $this->getShopLogo()
        ];
        $this->apiHelper->requestApi('updateMerchant', 'merchant/update', $data);

        $appId = explode('.', $this->config->getApiKey());
        $this->configWriter->save('payment/ivy/app_id', $appId[0]);
        return $this;
    }

    /**
     * @return string
     */
    private function getShopLogo()
    {
        $this->emulation->startEnvironmentEmulation(null, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        $path = $this->scopeConfig->getValue(
            'design/header/logo_src',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($path) {
            $shopLogo = $this->urlBuilder
                    ->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA]) .'logo/'. $path;
        } else {
            $shopLogo = $this->logo->getLogoSrc();
        }
        $this->emulation->stopEnvironmentEmulation();
        return $shopLogo;
    }
}
