<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Quote;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $scopeConfig;
    protected $quoteFactory;
    protected $regionFactory;
    protected $cartTotalRepository;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Quote\Model\Cart\CartTotalRepository $cartTotalRepository
        ){
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteFactory = $quoteFactory;
        $this->regionFactory = $regionFactory;
        $this->cartTotalRepository = $cartTotalRepository;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $customerData = $this->json->unserialize((string)$request->getContent());

        if(key_exists('shipping',$customerData))
        {
            $countryId = $customerData['shipping']['shippingAddress']['country'];
            $regionCode = $customerData['shipping']['shippingAddress']['region'];
            $regionId = $this->regionFactory->create()->loadByCode($regionCode, $countryId)->getId();
            $regionName = $this->regionFactory->create()->loadByCode($regionCode, $countryId)->getName();
            
            $orderInfo =[
                'address' =>[
                    'firstname' => $customerData['shipping']['shippingAddress']['firstName'],
                    'lastname' => $customerData['shipping']['shippingAddress']['lastName'],
                    'street' => $customerData['shipping']['shippingAddress']['line1'],
                    'city' => $customerData['shipping']['shippingAddress']['city'],
                    'country_id' => $customerData['shipping']['shippingAddress']['country'],
                    'postcode' => $customerData['shipping']['shippingAddress']['zipCode'],
                    'telephone' => $customerData['shopperPhone'],
                    'region_id' => $regionId ? $regionId: NULL,
                    'region' => $regionName ? $regionName: $regionCode
                ],
            ];
        }
        
        $quoteReservedId = $request->getParam('reference');
        $quote = $this->quoteFactory->create()->load($quoteReservedId,'reserved_order_id');

        if($quote->getCustomerIsGuest())
        $quote->setCustomerEmail($customerData['shopperEmail']);

        if(key_exists('shipping',$customerData))
        {
            $quote->getShippingAddress()->addData($orderInfo['address']);
        }
        
        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true);
        $address->save();
        if(key_exists('discount',$customerData)){
            $couponCode = $customerData['discount']['voucher'];
            $quote->setCouponCode($couponCode)->collectTotals()->save();
        }
        $quote->save();

        if(key_exists('shipping',$customerData))
        {
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
        if($discountAmount < 0)
        {
            $totalNet = $quote->getBaseSubtotal()?$quote->getBaseSubtotal():0;
            $vat = $quote->getShippingAddress()->getBaseTaxAmount()?$quote->getShippingAddress()->getBaseTaxAmount():0;
            $total = $quote->getBaseGrandTotal()?$quote->getBaseGrandTotal():0;

            $discountAmount = abs($discountAmount);
            $discount = ['amount' => $discountAmount];
            $data['discount'] = $discount;
            $data['price'] = [
                'totalNet' => $totalNet,
                'vat' => $vat,
                'total' => $total
            ];
        }
        
        $hash = hash_hmac(
            'sha256',
            json_encode($data),
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
