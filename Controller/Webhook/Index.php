<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Webhook;

use Esparksinc\IvyPayment\Helper\Invoice as InvoiceHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Api\CartManagementInterface;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $order;
    protected $json;
    protected $refund;
    protected $orderManagement;
    protected $logger;
    protected $invoiceHelper;
    protected $searchCriteriaBuilder;
    protected $orderRepository;
    protected $quoteManagement;
    /**
     * @param Context $context
     * @param OrderFactory $order
     * @param Config $config
     * @param Json $json
     * @param RefundInvoiceInterface $refund
     * @param OrderManagementInterface $orderManagement
     * @param Logger $logger
     * @param InvoiceHelper $invoiceHelper
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param CartManagementInterface $quoteManagement
     * @param 
     */
    public function __construct(
        Context                  $context,
        OrderFactory             $order,
        Config                   $config,
        Json                     $json,
        RefundInvoiceInterface   $refund,
        OrderManagementInterface $orderManagement,
        Logger                   $logger,
        InvoiceHelper            $invoiceHelper,
        SearchCriteriaBuilder    $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        CartManagementInterface  $quoteManagement
    ) {
        $this->config = $config;
        $this->order = $order;
        $this->json = $json;
        $this->refund = $refund;
        $this->orderManagement = $orderManagement;
        $this->logger = $logger;
        $this->invoiceHelper = $invoiceHelper;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->quoteManagement = $quoteManagement;
        parent::__construct($context);
    }
    public function execute()
    {
        $request = $this->getRequest();
        if(!$this->isValidRequest($request))
        {
            return false;
        }

        $jsonContent = $request->getContent();
        $arrData = $this->json->unserialize((string)$jsonContent);

        $magentoOrderId = $arrData['payload']['referenceId'];
        $this->logger->debugRequest($this, $magentoOrderId);

        $quoteId = $arrData['payload']['metadata']['quote_id'] ?? null;
        if (!$quoteId) {
            $quoteId = $this->getQuoteId($magentoOrderId);
        }
        $quote = $this->quoteRepository->get($quoteId);

        if ($arrData['type'] === 'order_updated' || $arrData['type'] === 'order_created')
        {
            switch ($arrData['payload']['status']) {
                case 'canceled':
                    $quote->cancel();
                    break;
                case 'waiting_for_payment':
                case 'paid':
                    if (!$quoteId) {
                        $quoteId = $this->getQuoteId($magentoOrderId);
                    }
                    $quote = $this->quoteRepository->get($quoteId);
                    $newOrder = $this->createOrder($quote);

                    if ($newOrder->canInvoice()) {
                        $this->createInvoice($newOrder, $arrData);
                    } else{
                        $this->setOrderStatus($newOrder,'processing');
                    }
                    break;
            }
        }
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
        $hash = hash_hmac(
            'sha256',
            $request->getContent(),
            $this->config->getWebhookSecret());

        if ($request->getHeaders('x-ivy-signature')->getFieldValue() === $hash) {
            return true;
        }

        return false;
    }

    private function orderRefund(Order $orderdetails)
    {
        /*
         * If the order/complete callback was unsuccessful then the order with the reserved id may not have been created.
         * Ivy sends request to refund the order in this case. So we should check if we have anything to refund.
         */
        if (!$orderdetails->getId()) {
            return;
        }

        // STATUS_REFUNDED = 8
        if ($orderdetails->getStatusId() === 8) {
            return;
        }

        if (!$this->isIvyPayment($orderdetails->getPayment())) {
            return;
        }

        $invoices = $orderdetails->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            $invoiceId = $invoice->getId();
            if ($invoiceId) {
                $this->refund->execute($invoiceId,[],true);
            }
        }
    }

    private function setOrderStatus(Order $orderdetails, $status)
    {
        if($orderdetails->getState() === 'processing')
        {
            $orderdetails->setStatus($status);
            $orderdetails->save();
        }
    }

    private function createInvoice(Order $orderdetails, $arrData)
    {
        // dont invoice if invoice with ivy as payment already exists
        $invoices = $orderdetails->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            if ($invoice->getTransactionId() === $arrData['payload']['id']) {
                return;
            }
        }

        $ivyOrderId = $arrData['payload']['id'];
        $this->invoiceHelper->createInvoice($orderdetails, $ivyOrderId);
    }

    /**
     * @param Payment $payment
     * @return bool
     * @throws LocalizedException
     */
    private function isIvyPayment($payment)
    {
        $code = $payment->getMethodInstance()->getCode();
        return $code === 'ivy';
    }

    private function loadOrderByQuoteId($quoteId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('quote_id', $quoteId)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria);
        if ($orders->getTotalCount() > 0) {
            return array_values($orders->getItems())[0];
        } else {
            return false;
        }
    }

    private function createOrder($quote) {
        // check if order already exists for this quote
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('quote_id', $quote->getId())->create();
        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        if (count($orders) > 0) {
            return true;
        }

        try {
            return $this->quoteManagement->submit($quote);
        } catch (\Exception $exception) {
            $this->logger->debugApiAction($this, $quote->getId(), 'Quote submit error',
                [$exception->getMessage()]
            );

            // return 400 status in this response will trigger a the webhook to be sent again
            $this->errorResolver->forceReserveOrderId($quote);
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData([]);
        }
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
