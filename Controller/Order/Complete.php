<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Order;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Debug;
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
    private Debug $debug;

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
     * @param Debug $debug
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
        Debug                   $debug
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
        $this->debug = $debug;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $customerData = $this->json->unserialize((string)$request->getContent());
        $this->debug->log(
            '[IvyPayment] Get Complete customerData:',
            $customerData
        );
        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $redirectUrl = $frontendUrl.'ivypayment/complete/success';

        $quoteReservedId = $request->getParam('reference');

        $searchCriteria = $this->criteriaBuilder->addFilter('reserved_order_id', $quoteReservedId)->create();
        $quotes = $this->quoteRepository->getList($searchCriteria)->getItems();

        if (count($quotes) === 1) {
            $quote = array_values($quotes)[0];
        } else {
            $quote = $this->quoteFactory->create()->load($quoteReservedId, 'reserved_order_id');
        }

        $quote = $this->quoteRepository->get($quote->getId());

        $this->debug->log(
            '[IvyPayment] Get Complete quote getBillingAddress:',
            [$quote->getBillingAddress()->getData()]
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


        $qouteGrandTotal = $quote->getGrandTotal();
        $ivyTotal = $customerData['price']['total'];

        if($qouteGrandTotal != $ivyTotal)
        {
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData([]);
        }

        $this->debug->log(
            '[IvyPayment] Get Complete quote:',
            $quote->getData()
        );

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
