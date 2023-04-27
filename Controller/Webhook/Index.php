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
        OrderRepositoryInterface $orderRepository
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
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        $orderId = $orderdetails->getId();

        $this->logger->debugRequest($this, $magentoOrderId);
        $quoteId = $arrData['payload']['metadata']['quote_id'] ?? null;
        if ($quoteId && (int)$quoteId !== (int)$orderdetails->getQuoteId()) {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Incorrect quote id',
                ['magento' => $orderdetails->getQuoteId(), 'ivy' => $quoteId]
            );
            $orderdetails = $this->loadOrderByQuoteId($quoteId);
            if (!$orderdetails) {
                return false;
            }
        }

        // the webhook should not process the order if it made not via ivy
        $isIvy = $this->isIvyPayment($orderdetails->getPayment());
        if ($isIvy) {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Order', $orderdetails->getData());
        } else {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Incorrect order', $orderdetails->getData());
            return false;
        }

        if ($arrData['type'] === 'order_updated' || $arrData['type'] === 'order_created')
        {
            switch ($arrData['payload']['status']) {
                case 'canceled':
                    if ($orderdetails->canInvoice()) {
                        $this->orderManagement->cancel($orderId);
                    }
                    break;
                case 'waiting_for_payment':
                case 'paid':
                    if ($orderdetails->canInvoice()) {
                        $this->createInvoice($orderdetails, $arrData);
                    } else{
                        $this->setOrderStatus($orderdetails,'processing');
                    }
                    break;
                case 'refunded':
                    $this->orderRefund($orderdetails);
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
    {;
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
}
