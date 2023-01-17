<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Order;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Store\Model\StoreManagerInterface;

class Complete extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $scopeConfig;
    protected $quoteFactory;
    protected $quoteRepository;
    protected $regionFactory;
    protected $cartTotalRepository;
    protected $quoteManagement;
    protected $storeManager;
    private Logger $logger;

    /**
     * @param Context $context
     * @param Config $config
     * @param Json $json
     * @param JsonFactory $jsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param QuoteFactory $quoteFactory
     * @param QuoteRepository $quoteRepository
     * @param RegionFactory $regionFactory
     * @param CartTotalRepository $cartTotalRepository
     * @param CartManagementInterface $quoteManagement
     * @param StoreManagerInterface $storeManager
     * @param Logger $logger
     */
    public function __construct(
        Context                 $context,
        Config                  $config,
        Json                    $json,
        JsonFactory             $jsonFactory,
        ScopeConfigInterface    $scopeConfig,
        QuoteFactory            $quoteFactory,
        QuoteRepository         $quoteRepository,
        RegionFactory           $regionFactory,
        CartTotalRepository     $cartTotalRepository,
        CartManagementInterface $quoteManagement,
        StoreManagerInterface   $storeManager,
        Logger                  $logger
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->regionFactory = $regionFactory;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->quoteManagement = $quoteManagement;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $quoteReservedId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());
        $this->logger->debugApiAction($this, $quoteReservedId, 'Got API customer data', $customerData);

        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $redirectUrl = $frontendUrl.'ivypayment/complete/success';

        $quote = $this->quoteFactory->create()->load($quoteReservedId,'reserved_order_id');
        $quote = $this->quoteRepository->get($quote->getId());

        $this->logger->debugApiAction($this, $quoteReservedId, 'Quote billing address',
            $quote->getBillingAddress()->getData()
        );

        if(!$quote->getBillingAddress()->getFirstname())
        {
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
            $quote->setCustomerFirstname($quote->getBillingAddress()->getFirstname());
            $quote->setCustomerLastname($quote->getBillingAddress()->getLastname());

            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod($customerData['shippingMethod']['reference']);
            $quote->setPaymentMethod('ivy');
            $quote->save();
            $quote->getPayment()->importData(['method' => 'ivy']);
            $quote->collectTotals()->save();
        } else {
            $quote->setCustomerFirstname($quote->getBillingAddress()->getFirstname());
            $quote->setCustomerLastname($quote->getBillingAddress()->getLastname());
            $quote->save();
        }

        $this->logger->debugApiAction($this, $quoteReservedId, 'Quote',
            $quote->getData()
        );

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
            json_encode($data, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE),
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
