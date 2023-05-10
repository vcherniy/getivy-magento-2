<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Order;

use Esparksinc\IvyPayment\Helper\Quote as QuoteHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\Logger;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\Quote\TotalsCollector;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Model\QuoteValidator;
use Magento\Store\Model\StoreManagerInterface;

class Complete extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $quoteRepository;
    protected $cartTotalRepository;
    protected $storeManager;
    protected $searchCriteriaBuilder;
    protected $logger;
    protected $errorResolver;
    protected $totalsCollector;
    protected $quoteHelper;
    protected $quoteValidator;

    /**
     * @param Context $context
     * @param Config $config
     * @param Json $json
     * @param JsonFactory $jsonFactory
     * @param QuoteRepository $quoteRepository
     * @param CartTotalRepository $cartTotalRepository
     * @param StoreManagerInterface $storeManager
     * @param Logger $logger
     * @param ErrorResolver $errorResolver
     * @param TotalsCollector $totalsCollector
     * @param QuoteHelper $quoteHelper
     * @param QuoteValidator $quoteValidator
     */
    public function __construct(
        Context                 $context,
        Config                  $config,
        Json                    $json,
        JsonFactory             $jsonFactory,
        QuoteRepository         $quoteRepository,
        CartTotalRepository     $cartTotalRepository,
        StoreManagerInterface   $storeManager,
        Logger                  $logger,
        ErrorResolver           $errorResolver,
        TotalsCollector         $totalsCollector,
        QuoteHelper             $quoteHelper,
        QuoteValidator          $quoteValidator
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->quoteRepository = $quoteRepository;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->errorResolver = $errorResolver;
        $this->totalsCollector = $totalsCollector;
        $this->quoteHelper = $quoteHelper;
        $this->quoteValidator = $quoteValidator;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        $magentoOrderId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugRequest($this, $magentoOrderId);

        $quoteId = $customerData['metadata']['quote_id'] ?? null;
        $quote = $this->quoteHelper->getQuote($magentoOrderId, $quoteId);

        if (!$quote->getCustomerId()) {
            $quote->setCustomerEmail($customerData['shopperEmail']);
            $quote->setCustomerIsGuest(true);
        }

        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();

        // customer could set another billing address in checkout or for other payment method before
        // we should update billing address from ivy in any case
        $customerBillingData = $customerData['billingAddress'] ?? [];
        if ($customerBillingData) {
            $billingAddressData = [
                'firstname'  => $customerBillingData['firstName'],
                'lastname'   => $customerBillingData['lastName'],
                'street'     => $customerBillingData['line1'],
                'city'       => $customerBillingData['city'],
                'country_id' => $customerBillingData['country'],
                'postcode'   => $customerBillingData['zipCode'],
                'telephone'  => $shippingAddress->getTelephone()
            ];
            $billingAddress->addData($billingAddressData);
            $billingAddress->save();
        }

        $quote->setCustomerFirstname($billingAddress->getFirstname());
        $quote->setCustomerLastname($billingAddress->getLastname());

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
            $quote->setShippingAddress($shippingAddress);

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

        try {
            $this->quoteValidator->validateBeforeSubmit($quote);
        } catch (ValidatorException $exception) {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Validator exception',
                ['message' => $exception->getMessage()]
            );

            // return 400 status in this callback will cancel order id on the Ivy Payment Processor side
            $this->errorResolver->forceReserveOrderId($quote);
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData([]);
        }

        $this->quoteRepository->save($quote);

        $frontendUrl = $this->storeManager->getStore()->getBaseUrl();
        $redirectUrl = $frontendUrl.'ivypayment/complete/success';

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
}
