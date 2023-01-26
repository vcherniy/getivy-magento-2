<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;

class Index extends Action
{
    protected $resultRedirectFactory;
    protected $jsonFactory;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $config;
    protected $onePage;
    protected $ivy;
    protected $cartTotalRepository;
    protected $logger;
    protected $errorResolver;
    protected $apiHelper;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param RedirectFactory $resultRedirectFactory
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param Config $config
     * @param Onepage $onePage
     * @param IvyFactory $ivy
     * @param CartTotalRepository $cartTotalRepository
     * @param Logger $logger
     * @param ErrorResolver $errorResolver
     * @param ApiHelper $apiHelper
     */
    public function __construct(
        Context                 $context,
        JsonFactory             $jsonFactory,
        RedirectFactory         $resultRedirectFactory,
        Session                 $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Config                  $config,
        Onepage                 $onePage,
        IvyFactory              $ivy,
        CartTotalRepository     $cartTotalRepository,
        Logger                  $logger,
        ErrorResolver           $errorResolver,
        ApiHelper               $apiHelper
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->ivy = $ivy;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->logger = $logger;
        $this->errorResolver = $errorResolver;
        $this->apiHelper = $apiHelper;
        parent::__construct($context);
    }
    public function execute()
    {
        $express = $this->getRequest()->getParam('express');
        $ivyModel = $this->ivy->create();

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $orderId = $quote->getReservedOrderId();

        $quote->collectTotals();
        
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

        $responseData = $this->apiHelper->requestApi($this, 'checkout/session/create', $data, $orderId,
            function ($exception) use ($quote) {
                $this->errorResolver->tryResolveException($quote, $exception);
            }
        );

        if ($responseData) {

            $ivyModel->setIvyCheckoutSession($responseData['id']);
            $ivyModel->setIvyRedirectUrl($responseData['redirectUrl']);
            $ivyModel->save();

            return $this->jsonFactory->create()->setData(['redirectUrl'=> $responseData['redirectUrl']]);
        }
    }

    private function getLineItem($quote)
    {
        $ivyLineItems = array();
        foreach ($quote->getAllVisibleItems() as $lineItem) {
            $ivyLineItems[] = [
                'name'          => $lineItem->getName(),
                'referenceId'   => $lineItem->getSku(),
                'singleNet'     => $lineItem->getBasePrice(),
                'singleVat'     => $lineItem->getBaseTaxAmount() ?: 0,
                'amount'        => $lineItem->getBaseRowTotalInclTax() ?: 0,
                'quantity'      => $lineItem->getQty(),
                'image'         => '',
            ];
        }

        $totals = $this->cartTotalRepository->get($quote->getId());
        $discountAmount = $totals->getDiscountAmount();
        if ($discountAmount < 0) {
            $ivyLineItems[] = [
                'name'      => 'Discount',
                'singleNet' => $discountAmount,
                'singleVat' => 0,
                'amount'    => $discountAmount
            ];
        }

        return $ivyLineItems;
    }

    private function getPrice($quote, $express)
    {
        $shippingTotal = $quote->getShippingAddress() ? $quote->getShippingAddress()->getShippingAmount() : 0;
        $shippingVat = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseShippingTaxAmount() : 0;
        $shippingNet = $shippingTotal - $shippingVat;

        $total = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseGrandTotal() : 0;
        $vat = $quote->getShippingAddress() ? $quote->getShippingAddress()->getBaseTaxAmount() : 0;
        $totalNet = $total - $vat;
        
        $currency = $quote->getBaseCurrencyCode();

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
        $countryId = $quote->getShippingAddress()->getCountryId();
        $shippingMethod = array();
        $shippingLine = [
            'price'     => $quote->getBaseShippingAmount() ?: 0,
            'name'      => $quote->getShippingAddress()->getShippingMethod(),
            'countries' => [$countryId]
        ];

        $shippingMethod[] = $shippingLine;

        return $shippingMethod;
    }

    private function getBillingAddress($quote): array
    {
        return [
            'firstName' => $quote->getBillingAddress()->getFirstname(),
            'LastName'  => $quote->getBillingAddress()->getLastname(),
            'line1'     => $quote->getBillingAddress()->getStreet()[0],
            'city'      => $quote->getBillingAddress()->getCity(),
            'zipCode'   => $quote->getBillingAddress()->getPostcode(),
            'country'   => $quote->getBillingAddress()->getCountryId(),
        ];
    }

    private function getPluginVersion(): string {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        return 'm2-'.$composerJson['version'];
    }
}
