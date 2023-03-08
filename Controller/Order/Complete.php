<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Order;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
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
use Magento\Quote\Model\Quote\TotalsCollector;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

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
    protected $searchCriteriaBuilder;
    protected $logger;
    protected $errorResolver;
    protected $totalsCollector;


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
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Logger $logger
     * @param ErrorResolver $errorResolver
     * @param TotalsCollector $totalsCollector
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
        SearchCriteriaBuilder   $searchCriteriaBuilder,
        Logger                  $logger,
        ErrorResolver           $errorResolver,
        TotalsCollector         $totalsCollector

    ) {
        $this->config = $config;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
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
        $this->errorResolver = $errorResolver;
        $this->totalsCollector = $totalsCollector;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $magentoOrderId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugRequest($this, $magentoOrderId);

        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $redirectUrl = $frontendUrl.'ivypayment/complete/success';

        $quoteId = $customerData['metadata']['quote_id'] ?? $this->getQuoteId($magentoOrderId);
        $quote = $this->quoteRepository->get($quoteId);

        $shippingAddress = $quote->getShippingAddress();
        if (!$quote->getBillingAddress()->getFirstname())
        {
            $customerBillingData = $customerData['billingAddress'];
            $billing = [
                'address' =>[
                    'firstname'  => $customerBillingData['firstName'],
                    'lastname'   => $customerBillingData['lastName'],
                    'street'     => $customerBillingData['line1'],
                    'city'       => $customerBillingData['city'],
                    'country_id' => $customerBillingData['country'],
                    'postcode'   => $customerBillingData['zipCode'],
                    'telephone'  => $shippingAddress->getTelephone()
                ]
            ];
            $quote->getBillingAddress()->addData($billing['address']);
        }

        $quote->setCustomerFirstname($quote->getBillingAddress()->getFirstname());
        $quote->setCustomerLastname($quote->getBillingAddress()->getLastname());

        if (!$quote->isVirtual()) {
            if (isset($customerData['shippingMethod']['reference'])) {
                $this->logger->debugApiAction($this, $magentoOrderId, 'Apply shipping method',
                    [$customerData['shippingMethod']['reference']]
                );

                $shippingAddress->setShippingMethod($customerData['shippingMethod']['reference']);
            }

            $shippingAddress->setCollectShippingRates(true);
            $this->totalsCollector->collectAddressTotals($quote, $shippingAddress);
            $shippingAddress->save();

            $this->logger->debugApiAction($this, $magentoOrderId, 'Shipping address', $shippingAddress->getData());
        }

        $quote->getPayment()->setMethod('ivy');
        $quote->collectTotals()->save();
        $quote = $this->quoteRepository->get($quote->getId());

        $this->logger->debugApiAction($this, $magentoOrderId, 'Quote', $quote->getData());

        $totals = $this->cartTotalRepository->get($quote->getId());
        $qouteGrandTotal = $totals->getBaseGrandTotal();
        $ivyTotal = $customerData['price']['total'];

        if ((int)ceil($qouteGrandTotal * 100) != (int)ceil($ivyTotal * 100)) {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Incorrect totals',
                ['magento' => $qouteGrandTotal, 'ivy' => $ivyTotal]
            );

            // return 400 status in this callback will cancel order id on the Ivy Payment Processor side
            $this->errorResolver->forceReserveOrderId($quote);
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData([]);
        }

        $this->quoteManagement->submit($quote);
        $data = [
            'redirectUrl' => $redirectUrl
        ];

        $this->logger->debugApiAction($this, $magentoOrderId, 'Response', $data);

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

    private function getQuoteId(string $reservedOrderId): int
    {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('reserved_order_id', $reservedOrderId)->create();
        $quotes = $this->quoteRepository->getList($searchCriteria)->getItems();

        if (count($quotes) === 1) {
            $quote = array_values($quotes)[0];
        } else {
            $quote = $this->quoteFactory->create()->load($reservedOrderId, 'reserved_order_id');
        }

        return $quote->getId();
    }
}
