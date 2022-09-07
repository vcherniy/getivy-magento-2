<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Order;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Complete extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $scopeConfig;
    protected $quoteFactory;
    protected $regionFactory;
    protected $cartTotalRepository;
    protected $quoteManagement;
    protected $storeManager;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Quote\Model\Cart\CartTotalRepository $cartTotalRepository,
        \Magento\Quote\Api\CartManagementInterface $quoteManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManager
        ){
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteFactory = $quoteFactory;
        $this->regionFactory = $regionFactory;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->quoteManagement = $quoteManagement;
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $customerData = $this->json->unserialize((string)$request->getContent());
        
        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $redirectUrl = $frontendUrl.'ivypayment/complete/success';

        $quoteReservedId = $request->getParam('reference');
        $quote = $this->quoteFactory->create()->load($quoteReservedId,'reserved_order_id');

        $shippingAddress = $quote->getShippingAddress();
        $telephone = $shippingAddress->getTelephone();
        $billing =[
            'address' =>[
                'firstname'    => $customerData['billingAddress']['firstName'],
                'lastname'     => $customerData['billingAddress']['lastName'],
                'street' => $customerData['billingAddress']['line1'],
                'city' => $customerData['billingAddress']['city'],
                'country_id' => $customerData['billingAddress']['country'],
                'postcode' => $customerData['billingAddress']['zipCode'],
                'telephone' => $telephone
            ]
        ];

        $quote->getBillingAddress()->addData($billing['address']);
        
        $shippingAddress->setCollectShippingRates(true)
                    ->collectShippingRates()
                    ->setShippingMethod($customerData['shippingMethod']['reference']);
        $quote->setPaymentMethod('ivy');
        $quote->save();
        $quote->getPayment()->importData(['method' => 'ivy']);
        $quote->collectTotals()->save();
        
        

        $qouteGrandTotal = $quote->getGrandTotal();
        $ivyTotal = $customerData['price']['total'];
        
        if($qouteGrandTotal != $ivyTotal)
        {
            return http_response_code(400);
        }
        
        $this->quoteManagement->submit($quote);
        $data = [
            'redirectUrl' => $redirectUrl
        ];
        
        $hash = hash_hmac(
            'sha256',
            json_encode($data,JSON_UNESCAPED_SLASHES),
            $this->config->getWebhookSecret());
        
        header('X-Ivy-Signature: '.$hash);
        return $this->jsonFactory->create()->setData($data);
    }

    public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }
        
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if($this->isValidRequest($request))
        {
            return true;
        }
        return false;
    }

    private function isValidRequest(RequestInterface $request)
    {
        // return true;
        $hash = hash_hmac(
            'sha256',
            $request->getContent(),
            $this->config->getWebhookSecret());
        if ($request->getHeaders('x-ivy-signature')->getFieldValue() === $hash) {
            return true;
        }

        return false;
    }
}
