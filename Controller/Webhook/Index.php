<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $order;
    protected $json;
    protected $refund;
    protected $orderManagement;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\OrderFactory $order,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Sales\Api\RefundInvoiceInterface $refund,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Transaction $transaction
        ){
        $this->config = $config;
        $this->order = $order;
        $this->json = $json;
        $this->refund = $refund;
        $this->orderManagement = $orderManagement;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
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
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
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
