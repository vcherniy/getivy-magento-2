<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Webhook;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Debug;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $order;
    protected $json;
    protected $refund;
    protected $orderManagement;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;
    private Debug $debug;

    /**
     * @param Context $context
     * @param OrderFactory $order
     * @param Config $config
     * @param Json $json
     * @param RefundInvoiceInterface $refund
     * @param OrderManagementInterface $orderManagement
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Transaction $transaction
     * @param Debug $debug
     */
    public function __construct(
        Context                  $context,
        OrderFactory             $order,
        Config                   $config,
        Json                     $json,
        RefundInvoiceInterface   $refund,
        OrderManagementInterface $orderManagement,
        InvoiceService           $invoiceService,
        InvoiceSender            $invoiceSender,
        Transaction              $transaction,
        Debug                    $debug
    ) {
        $this->config = $config;
        $this->order = $order;
        $this->json = $json;
        $this->refund = $refund;
        $this->orderManagement = $orderManagement;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->debug = $debug;
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

        $this->debug->log(
            '[IvyPayment] Get Webhook Order Details:',
            $orderdetails
        );

        if($arrData['type'] === 'order_updated' || $arrData['type'] === 'order_created')
        {
            if($arrData['payload']['paymentStatus'] === 'failed' || $arrData['payload']['paymentStatus'] === 'canceled' || $arrData['payload']['status'] === 'failed' || $arrData['payload']['status'] === 'canceled')
            {
                if ($orderdetails->canInvoice()) {
                    $this->orderManagement->cancel($orderId);
                }
                else{
                    $this->orderRefund($arrData);
                }
            }
            elseif($arrData['payload']['status'] === 'authorised')
            {
                if ($orderdetails->canInvoice()) {
                    $this->createInvoice($arrData);
                }
                else{
                    $this->setOrderStatus($arrData,'payment_authorised');
                }
            }
            elseif($arrData['payload']['status'] === 'paid')
            {
                if ($orderdetails->canInvoice()) {
                    $this->createInvoice($arrData);
                }
                else{
                    $this->setOrderStatus($arrData,'processing');
                }
            }
            elseif($arrData['payload']['status'] === 'processing')
            {
                if ($orderdetails->canInvoice()) {
                    $this->createInvoice($arrData);
                }
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

    private function orderRefund($arrData)
    {
        $magentoOrderId = $arrData['payload']['referenceId'];
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);

        foreach ($orderdetails->getInvoiceCollection() as $invoice)
        {
            $invoiceId = $invoice->getId();
        }

        $this->refund->execute($invoiceId,[],true);
    }

    private function setOrderStatus($arrData,$status)
    {
        $magentoOrderId = $arrData['payload']['referenceId'];
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);

        if($orderdetails->getState() === 'processing')
        {
            $orderdetails->setStatus($status);
            $orderdetails->save();
        }
    }

    private function  createInvoice($arrData)
    {
        $magentoOrderId = $arrData['payload']['referenceId'];
        $ivyOrderId = $arrData['payload']['id'];
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);

        if ($orderdetails->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($orderdetails);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            $invoice->save();
            $transactionSave = $this->transaction->addObject(
                $invoice
            )->addObject(
                $invoice->getOrder()
            );
            $transactionSave->save();
            $this->invoiceSender->send($invoice);

            $orderdetails->save();
        }

        foreach ($orderdetails->getInvoiceCollection() as $invoice)
        {
            $invoice->setTransactionId($ivyOrderId);
            $invoice->save();
        }

        if($orderdetails->getState() === 'processing')
        {
            $orderdetails->setStatus('payment_authorised');
            $orderdetails->save();
        }
    }
}
