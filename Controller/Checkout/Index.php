<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use GuzzleHttp\Client;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $resultRedirectFactory;
    protected $jsonFactory;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $config;
    protected $json;
    protected $onePage;
    protected $ivy;
    protected $cartTotalRepository;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Checkout\Model\Type\Onepage $onePage,
        \Esparksinc\IvyPayment\Model\IvyFactory $ivy,
        \Magento\Quote\Model\Cart\CartTotalRepository $cartTotalRepository
        ){
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->json = $json;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->ivy = $ivy;
        $this->cartTotalRepository = $cartTotalRepository;
        parent::__construct($context);
    }
    public function execute()
    {
        $express = 0;
        $express = $this->getRequest()->getParam('express');
        $ivyModel = $this->ivy->create();
        
        $quote = $this->checkoutSession->getQuote();
        if(!$quote->getReservedOrderId())
        {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $this->quoteRepository->save($quote);
        $orderId = $quote->getReservedOrderId();

        //Price
        $price = $this->getPrice($quote); 
        
        // Line Items
        $ivyLineItems = $this->getLineItem($quote);
        
        // Shipping Methods
        $shippingMethods = $this->getShippingMethod($quote);

        //billingAddress
        $billingAddress = $this->getBillingAddress($quote);

        $mcc = $this->config->getMcc();

        if($express)
        {
            $phone = ['phone' => true];
            $data = [
                'express' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'required' => $phone
            ];
        }
        else
        {
            $prefill = ["email" => $quote->getCustomerEmail()];
            $data = [
                'handshake' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'shippingMethods' =>  $shippingMethods,
                'billingAddress' => $billingAddress,
                'prefill' => $prefill,
            ];
        }
        
        $jsonContent = $this->json->serialize($data);
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

        $response = $client->post('checkout/session/create', $options);

        if ($response->getStatusCode() === 200) {
            //Order Place if not express
            // if(!$express)
            // $this->onePage->saveOrder();
            
            // Redirect to Ivy payment
            $arrData = $this->json->unserialize((string)$response->getBody());

            $ivyModel->setIvyCheckoutSession($arrData['id']);
            $ivyModel->setIvyRedirectUrl($arrData['redirectUrl']);
            $ivyModel->save();

            return $this->jsonFactory->create()->setData(['redirectUrl'=> $arrData['redirectUrl']]);
        }
    }

    private function getLineItem($quote)
    {
        $ivyLineItems = array();
        foreach ($quote->getAllVisibleItems() as $lineItem) {
            $lineItem = [
                'name' => $lineItem->getName(),
                'referenceId' => $lineItem->getSku(),
                'singleNet' => $lineItem->getBasePrice(),
                'singleVat' => $lineItem->getBaseTaxAmount()?$lineItem->getBaseTaxAmount():0,
                'amount' => $lineItem->getBaseRowTotalInclTax()?$lineItem->getBaseRowTotalInclTax():0,
                'quantity' => $lineItem->getQty(),
                'image' => '',
            ];

            $ivyLineItems[] = $lineItem;
        }

        $totals = $this->cartTotalRepository->get($quote->getId());
        $discountAmount = $totals->getDiscountAmount();
        if($discountAmount < 0)
        {
            $lineItem = [
                'name' => 'Discount',
                'singleNet' => $discountAmount,
                'singleVat' => 0,
                'amount' => $discountAmount
            ];

            $ivyLineItems[] = $lineItem;
        }
        
        return $ivyLineItems;
    }

    private function getPrice($quote)
    {
        //Price
        $totalNet = $quote->getBaseSubtotal()?$quote->getBaseSubtotal():0;
        $vat = $quote->getShippingAddress()->getBaseTaxAmount()?$quote->getShippingAddress()->getBaseTaxAmount():0;
        $shippingAmount = $quote->getBaseShippingAmount()?$quote->getBaseShippingAmount():0;
        $total = $quote->getBaseGrandTotal()?$quote->getBaseGrandTotal():0;
        $currency = $quote->getBaseCurrencyCode();

        $price = [
            'totalNet' => $totalNet,
            'vat' => $vat,
            'shipping' => $shippingAmount,
            'total' => $total,
            'currency' => $currency,
        ]; 

        return $price;
    }

    private function getShippingMethod($quote)
    {
        $shippingAmount = $quote->getBaseShippingAmount()?$quote->getBaseShippingAmount():0;
        $countryId[] = $quote->getShippingAddress()->getCountryId();
        $shippingMethod = array();
        $shippingLine = [
            'price' => $shippingAmount,
            'name' => $quote->getShippingAddress()->getShippingMethod(),
            'countries' => $countryId
        ];

        $shippingMethod[] = $shippingLine;

        return $shippingMethod;
    }

    private function getBillingAddress($quote)
    {
        $billingAddress = [
            'firstName' => $quote->getBillingAddress()->getFirstname(),
            'LastName' => $quote->getBillingAddress()->getLastname(),
            'line1' => $quote->getBillingAddress()->getStreet()[0],
            'city' => $quote->getBillingAddress()->getCity(),
            'zipCode' => $quote->getBillingAddress()->getPostcode(),
            'country' => $quote->getBillingAddress()->getCountryId(),
        ];

        return $billingAddress;
    }
}
