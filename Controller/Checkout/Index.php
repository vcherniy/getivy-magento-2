<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Debug;
use Esparksinc\IvyPayment\Model\IvyFactory;
use GuzzleHttp\Client;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;

class Index extends Action
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
    private Debug $debug;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param RedirectFactory $resultRedirectFactory
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param Json $json
     * @param Config $config
     * @param Onepage $onePage
     * @param IvyFactory $ivy
     * @param CartTotalRepository $cartTotalRepository
     * @param Debug $debug
     */
    public function __construct(
        Context                 $context,
        JsonFactory             $jsonFactory,
        RedirectFactory         $resultRedirectFactory,
        Session                 $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Json                    $json,
        Config                  $config,
        Onepage                 $onePage,
        IvyFactory              $ivy,
        CartTotalRepository     $cartTotalRepository,
        Debug                   $debug
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->json = $json;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->ivy = $ivy;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->debug = $debug;
        parent::__construct($context);
    }
    public function execute()
    {
        $express = $this->getRequest()->getParam('express');
        $ivyModel = $this->ivy->create();

        $quote = $this->checkoutSession->getQuote();
        
        if(!$quote->getReservedOrderId())
        {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $orderId = $quote->getReservedOrderId();
        $this->quoteRepository->save($quote);

        $price = $this->getPrice($quote, $express);

        $ivyLineItems = $this->getLineItem($quote);

        $shippingMethods = $quote->isVirtual() ? [] : $this->getShippingMethod($quote);

        $billingAddress = $this->getBillingAddress($quote);

        $mcc = $this->config->getMcc();

        $plugin = $this->getPluginVersion();

        if($express) {
            $phone = ['phone' => true];
            $data = [
                'express' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'required' => $phone,
                'plugin' => $plugin,
            ];
        } else {
            $prefill = ["email" => $quote->getBillingAddress()->getEmail()];
            $data = [
                'handshake' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'shippingMethods' =>  $shippingMethods,
                'billingAddress' => $billingAddress,
                'prefill' => $prefill,
                'plugin' => $plugin,
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

        $this->debug->log(
            '[IvyPayment] Get Checkout Status Code:',
            [$response->getStatusCode()]
        );

        if ($response->getStatusCode() === 200) {

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

    private function getPrice($quote, $express)
    {
        $shippingTotal = $quote->getShippingAddress() ? $quote->getShippingAddress()->getShippingAmount() : 0;
        $shippingVat = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseShippingTaxAmount() : 0;

        $shippingNet = $shippingTotal - $shippingVat;

        $vat = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseTaxAmount() : 0;
        $total = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseGrandTotal() : 0;
        $currency = $quote->getBaseCurrencyCode();
        $totalNet = $total - $vat;

        if ($express) {
            $total -= $shippingTotal;
            $total -= $shippingVat;
            $vat -= $shippingVat;
            $totalNet -= $shippingNet;
            $shippingTotal = 0;
            $shippingVat = 0;
            $shippingNet = 0;
        } 

        return [
            'totalNet' => $totalNet,
            'vat' => $vat,
            'shipping' => $shippingTotal,
            'total' => $total,
            'currency' => $currency,
        ];
    }

    private function getShippingMethod($quote): array
    {
        $shippingAmount = $quote->getBaseShippingAmount() ? $quote->getBaseShippingAmount() : 0;
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

    private function getBillingAddress($quote): array
    {
        return [
            'firstName' => $quote->getBillingAddress()->getFirstname(),
            'LastName' => $quote->getBillingAddress()->getLastname(),
            'line1' => $quote->getBillingAddress()->getStreet()[0],
            'city' => $quote->getBillingAddress()->getCity(),
            'zipCode' => $quote->getBillingAddress()->getPostcode(),
            'country' => $quote->getBillingAddress()->getCountryId(),
        ];
    }

    private function getPluginVersion(): string {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        return 'm2-'.$composerJson['version'];
    }
}
