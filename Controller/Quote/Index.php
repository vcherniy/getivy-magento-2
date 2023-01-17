<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Quote;

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
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;

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
     * @param Logger $logger
     */
    public function __construct(
        Context              $context,
        Config               $config,
        Json                 $json,
        JsonFactory          $jsonFactory,
        ScopeConfigInterface $scopeConfig,
        QuoteFactory         $quoteFactory,
        QuoteRepository      $quoteRepository,
        RegionFactory        $regionFactory,
        CartTotalRepository  $cartTotalRepository,
        Logger               $logger
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->regionFactory = $regionFactory;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $quoteReservedId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugApiAction($this, $quoteReservedId, 'Got API customer data', $customerData);

        if (key_exists('shipping', $customerData)) {
            $countryId = $customerData['shipping']['shippingAddress']['country'];
            $regionCode = $customerData['shipping']['shippingAddress']['region'];
            $regionId = $this->regionFactory->create()->loadByCode($regionCode, $countryId)->getId();
            $regionName = $this->regionFactory->create()->loadByCode($regionCode, $countryId)->getName();

            $orderInfo = [
                'address' => [
                    'firstname' => $customerData['shipping']['shippingAddress']['firstName'],
                    'lastname' => $customerData['shipping']['shippingAddress']['lastName'],
                    'street' => $customerData['shipping']['shippingAddress']['line1'],
                    'city' => $customerData['shipping']['shippingAddress']['city'],
                    'country_id' => $customerData['shipping']['shippingAddress']['country'],
                    'postcode' => $customerData['shipping']['shippingAddress']['zipCode'],
                    'telephone' => $customerData['shopperPhone'],
                    'region_id' => $regionId ? $regionId : NULL,
                    'region' => $regionName ? $regionName : $regionCode
                ],
            ];
        }

        $quote = $this->quoteFactory->create()->load($quoteReservedId, 'reserved_order_id');
        $quote = $this->quoteRepository->get($quote->getId());

        if (!$quote->getCustomerId()) {
            $quote->setCustomerEmail($customerData['shopperEmail']);
            $quote->setCustomerIsGuest(true);
        }

        if (key_exists('shipping', $customerData)) {
            $quote->getShippingAddress()->addData($orderInfo['address']);
        }

        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true);
        $address->save();
        if (key_exists('discount', $customerData)) {
            $couponCode = $customerData['discount']['voucher'];
            $quote->setCouponCode($couponCode)->collectTotals()->save();
        }
        $quote->save();

        $data = [];

        if (key_exists('shipping', $customerData)) {
            $shippingMethods = [];
            $address->collectShippingRates();
            $shippingRates = $address->getGroupedAllShippingRates();
            foreach ($shippingRates as $code => $carrierRates) {
                foreach ($carrierRates as $rate) {
                    $shippingMethods[] = [
                        'price' => $rate->getPrice(),
                        'name' => $this->getCarrierName($code),
                        'countries' => [$customerData['shipping']['shippingAddress']['country']],
                        'reference' => $rate->getCode()
                    ];
                }
            }
            $data['shippingMethods'] = $shippingMethods;
        }
        //Get discount
        $totals = $this->cartTotalRepository->get($quote->getId());
        $discountAmount = $totals->getDiscountAmount();
        if ($discountAmount < 0) {
            $totalNet = $quote->getBaseSubtotal() ? $quote->getBaseSubtotal() : 0;
            $vat = $quote->getShippingAddress()->getBaseTaxAmount() ? $quote->getShippingAddress()->getBaseTaxAmount() : 0;
            $total = $quote->getBaseGrandTotal() ? $quote->getBaseGrandTotal() : 0;

            $discountAmount = abs($discountAmount);
            $discount = ['amount' => $discountAmount];
            $data['discount'] = $discount;
            $data['price'] = [
                'totalNet' => $totalNet,
                'vat' => $vat,
                'total' => $total
            ];
        }

        $this->logger->debugApiAction($this, $quoteReservedId, 'Quote', $quote->getData());
        $this->logger->debugApiAction($this, $quoteReservedId, 'Sent data', $data);

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
