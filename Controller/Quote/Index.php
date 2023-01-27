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
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Model\ShippingMethodManagement;

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
    protected $logger;
    protected $shippingMethodManagement;
    protected $moduleManager;

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
     * @param ShippingMethodManagement $shippingMethodManagement
     * @param ModuleManager $moduleManager
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
        Logger                   $logger,
        ShippingMethodManagement $shippingMethodManagement,
        ModuleManager            $moduleManager
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
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->moduleManager = $moduleManager;
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $quoteReservedId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugRequest($this, $quoteReservedId);

        $quote = $this->quoteFactory->create()->load($quoteReservedId, 'reserved_order_id');
        $quote = $this->quoteRepository->get($quote->getId());

        if (!$quote->getCustomerId()) {
            $quote->setCustomerEmail($customerData['shopperEmail']);
            $quote->setCustomerIsGuest(true);
        }

        if (key_exists('discount', $customerData)) {
            $couponCode = $customerData['discount']['voucher'];
            $this->applyCouponCode($quote, $couponCode);
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
        $discountAmount = $this->getDiscountAmount($quote);
        if ($discountAmount !== 0) {
            $data['discount'] = [
                'amount'    => abs($discountAmount)
            ];
            $data['price'] = [
                'totalNet'  => $quote->getBaseSubtotal() ?: 0,
                'vat'       => $quote->getBaseTaxAmount() ?: 0,
                'total'     => $quote->getBaseGrandTotal() ?: 0
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

    protected function applyCouponCode(CartInterface $quote, string $couponCode)
    {
        $couponApplied = false;

        /*
         * The Amasty Gift Card module implements its own logic that is not compatible with the coupons' core logic.
         * We need work with the module directly
         */
        if ($this->moduleManager->isEnabled('Amasty_GiftCardAccount')) {
            $giftCardAccountManagement = $this->_objectManager->create(
                'Amasty\GiftCardAccount\Model\GiftCardAccount\GiftCardAccountManagement'
            );

            try {
                $giftCardAccountManagement->applyGiftCardToCart($quote->getId(), $couponCode);
                $couponApplied = true;
            } catch (CouldNotSaveException $exception) {
                // do nothing
            }
        }

        if (!$couponApplied) {
            $quote->setCouponCode($couponCode);
        }
    }

    protected function getDiscountAmount(CartInterface $quote)
    {
        if ($this->moduleManager->isEnabled('Amasty_GiftCardAccount')) {
            $discountAmount = $quote->getExtensionAttributes()
                ->getAmGiftcardQuote()
                ->getBaseGiftAmountUsed();
        } else {
            $totals = $this->cartTotalRepository->get($quote->getId());
            $discountAmount = $totals->getDiscountAmount();
        }
        return $discountAmount;
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
         return true;
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
