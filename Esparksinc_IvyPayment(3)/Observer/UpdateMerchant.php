<?php

namespace Esparksinc\IvyPayment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
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

    public function __construct(
        RequestInterface $request, 
        WriterInterface $configWriter, 
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Theme\Block\Html\Header\Logo $logo,
        \Magento\Store\Model\App\Emulation $emulation
    )
    {
        $this->request = $request;
        $this->configWriter = $configWriter;
        $this->config = $config;
        $this->json = $json;
        $this->logo = $logo;
        $this->storeManager = $storeManager;
        $this->emulation = $emulation;
    }
    public function execute(EventObserver $observer)
    {
        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $successCallbackUrl = $frontendUrl.'ivypayment/success';
        $errorCallbackUrl = $frontendUrl.'ivypayment/fail';
        $quoteCallbackUrl = $frontendUrl.'ivypayment/quote';
        $webhookUrl = $frontendUrl.'ivypayment/webhook';
        $completeCallbackUrl = $frontendUrl.'ivypayment/order/complete';
        $this->emulation->startEnvironmentEmulation(null, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        $shopLogo = $this->logo->getLogoSrc();
        $this->emulation->stopEnvironmentEmulation();

        // Create data to set in api
        $data = [
            'successCallbackUrl' => $successCallbackUrl,
            'errorCallbackUrl' =>  $errorCallbackUrl,
            'quoteCallbackUrl' => $quoteCallbackUrl,
            'webhookUrl' => $webhookUrl,
            'completeCallbackUrl' => $completeCallbackUrl,
            'shopLogo' => $shopLogo
        ];

        $jsonContent = $this->json->serialize($data);

        //Authenticate API key
        $client = new Client([
            'base_uri' => $this->config->getApiUrl(),
            'headers' => [
                'X-Ivy-Api-Key' => $this->config->getApiKey(),
            ],
        ]);

        $headers['content-type'] = 'application/json';
        $options = [
            'headers' => $headers,
            'body' => $jsonContent,
        ];

        $response = $client->post('merchant/update', $options);
        
        return $this;
    }
}
?>