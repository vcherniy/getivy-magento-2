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
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
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

    /**
     * @param Context $context
     * @param OrderFactory $order
     * @param Config $config
     * @param Json $json
     * @param RefundInvoiceInterface $refund
     * @param OrderManagementInterface $orderManagement
     * @param Logger $logger
     * @param InvoiceHelper $invoiceHelper
     */
    public function __construct(
        Context                  $context,
        OrderFactory             $order,
        Config                   $config,
        Json                     $json,
        RefundInvoiceInterface   $refund,
        OrderManagementInterface $orderManagement,
        Logger                   $logger,
        InvoiceHelper            $invoiceHelper
    ) {
        $this->config = $config;
        $this->order = $order;
        $this->json = $json;
        $this->refund = $refund;
        $this->orderManagement = $orderManagement;
        $this->logger = $logger;
        $this->invoiceHelper = $invoiceHelper;
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
        $this->logger->debugApiAction($this, $magentoOrderId, 'Order', $orderdetails->getData());

        if($arrData['type'] === 'order_updated' || $arrData['type'] === 'order_created')
        {
            switch ($arrData['payload']['status']) {
                case 'canceled':
                    $isIvy = $this->isIvyPayment($orderdetails->getPayment());
                    if ($isIvy === true && $orderdetails->canInvoice()) {
                        $this->orderManagement->cancel($orderId);
                    }
                    break;
                case 'waiting_for_payment':
                case 'paid':
                    if ($orderdetails->canInvoice()) {
                        $this->createInvoice($arrData);
                    } else{
                        $this->setOrderStatus($arrData,'processing');
                    }
                    break;
                case 'refunded':
                    $this->orderRefund($arrData);
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

    private function orderRefund($arrData)
    {
        $magentoOrderId = $arrData['payload']['referenceId'];
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);

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

        $invoices = $orderdetails->getInvoiceCollection();

        foreach ($invoices as $invoice) {

            if ($this->isIvyPayment($invoice->getOrder()->getPayment())) {
                $invoiceId = $invoice->getId();
    
                if ($invoiceId) {
                    $this->refund->execute($invoiceId,[],true);
                }
            }
        }
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

    private function createInvoice($arrData)
    {
        $magentoOrderId = $arrData['payload']['referenceId'];
        $ivyOrderId = $arrData['payload']['id'];
        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);

        $this->invoiceHelper->createInvoice($orderdetails, $ivyOrderId);
    }

    private function isIvyPayment($payment) {
        $code = $payment->MethodInstance->getCode();
        return $code === 'ivy';
    }
}
