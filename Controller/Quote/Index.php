<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Quote;

use Esparksinc\IvyPayment\Helper\Discount as DiscountHelper;
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
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Model\ShippingMethodManagement;
use Magento\Framework\Api\SearchCriteriaBuilder;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $scopeConfig;
    protected $quoteFactory;
    protected $quoteRepository;
    protected $regionFactory;
    protected $cartTotalRepository;
    protected $searchCriteriaBuilder;
    protected $logger;
    protected $shippingMethodManagement;
    protected $discountHelper;

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
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Logger $logger
     * @param ShippingMethodManagement $shippingMethodManagement
     * @param DiscountHelper $discountHelper
     */
    public function __construct(
        Context                  $context,
        Config                   $config,
        Json                     $json,
        JsonFactory              $jsonFactory,
        ScopeConfigInterface     $scopeConfig,
        QuoteFactory             $quoteFactory,
        QuoteRepository          $quoteRepository,
        RegionFactory            $regionFactory,
        CartTotalRepository      $cartTotalRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Logger                   $logger,
        ShippingMethodManagement $shippingMethodManagement,
        DiscountHelper           $discountHelper
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->regionFactory = $regionFactory;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->discountHelper = $discountHelper;
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $quoteReservedId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugRequest($this, $quoteReservedId);

        $searchCriteria = $this->searchCriteriaBuilder->addFilter('reserved_order_id', $quoteReservedId)->create();
        $quotes = $this->quoteRepository->getList($searchCriteria)->getItems();

        if (count($quotes) === 1) {
            $quote = array_values($quotes)[0];
        } else {
            $quote = $this->quoteFactory->create()->load($quoteReservedId, 'reserved_order_id');
        }

        $quote = $this->quoteRepository->get($quote->getId());

        if (!$quote->getCustomerId()) {
            $quote->setCustomerEmail($customerData['shopperEmail']);
            $quote->setCustomerIsGuest(true);
        }

        if (key_exists('discount', $customerData)) {
            $couponCode = $customerData['discount']['voucher'];
            $this->discountHelper->applyCouponCode($couponCode, $quote);
        }

        $data = [];

        if (key_exists('shipping', $customerData)) {
            $customerShippingData = $customerData['shipping']['shippingAddress'];

            $countryId = $customerShippingData['country'];
            $regionCode = $customerShippingData['region'];
            $region = $this->regionFactory->create()->loadByCode($regionCode, $countryId);

            $addressData = [
                'firstname'  => $customerShippingData['firstName'],
                'lastname'   => $customerShippingData['lastName'],
                'street'     => $customerShippingData['line1'],
                'city'       => $customerShippingData['city'],
                'country_id' => $customerShippingData['country'],
                'postcode'   => $customerShippingData['zipCode'],
                'telephone'  => $customerData['shopperPhone'],
                'region_id'  => $region->getId() ?: NULL,
                'region'     => $region->getName() ?: $regionCode
            ];

            $address = $quote->getShippingAddress();
            $address->addData($addressData);
            $address->setCollectShippingRates(true);
            $address->collectShippingRates();
            $address->save();

            $shippingMethods = [];

            if ($quote->isVirtual()) {
                // if quote is virtual and shippingMethods is empty, add free shipping with the name per mail as the carrier
                $shippingMethods[] = [
                    'price'     => 0,
                    'name'      => 'E-mail',
                    'countries' => [$customerShippingData['country']],
                    'reference' => 'email'
                ];
            } else {
                $estimatedMethods = $this->shippingMethodManagement->estimateByExtendedAddress($quote->getId(), $address);
                /** @var \Magento\Quote\Model\Cart\ShippingMethod $method */
                foreach ($estimatedMethods as $method) {
                    $code = $method->getCarrierCode();
                    $shippingMethods[] = [
                        'price'     => $method->getPriceInclTax(),
                        'name'      => $this->getCarrierName($code),
                        'countries' => [$customerShippingData['country']],
                        'reference' => $method->getCarrierCode() . '_' . $method->getMethodCode()
                    ];
                }
            }

            $data['shippingMethods'] = $shippingMethods;
        }

        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        //Get discount
        $discountAmount = $this->discountHelper->getDiscountAmount($quote);
        if ($discountAmount !== 0.0) {
            $totals = $this->cartTotalRepository->get($quote->getId());

            $shippingNet = $totals->getBaseShippingAmount();
            $shippingVat = $totals->getBaseShippingTaxAmount();
            $shippingTotal = $shippingNet + $shippingVat;

            $total = $totals->getBaseGrandTotal() - $shippingTotal;
            $vat = $totals->getBaseTaxAmount() - $shippingVat;
            $totalNet = $total - $vat;

            $data['discount'] = [
                'amount'    => abs($discountAmount)
            ];
            $data['price'] = [
                'totalNet'  => $totalNet,
                'vat'       => $vat,
                'total'     => $total
            ];
        }

        $this->logger->debugApiAction($this, $quoteReservedId, 'Quote', $quote->getData());
        $this->logger->debugApiAction($this, $quoteReservedId, 'Response', $data);

        $hash = hash_hmac(
            'sha256',
            json_encode($data, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE),
            $this->config->getWebhookSecret());
        header('X-Ivy-Signature: ' . $hash);
        return $this->jsonFactory->create()->setData($data);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if ($this->isValidRequest($request)) {
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

    public function getCarrierName($carrierCode)
    {
        if ($name = $this->scopeConfig->getValue(
            "carriers/{$carrierCode}/title",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )
        ) {
            return $name;
        }
        return $carrierCode;
    }
}
