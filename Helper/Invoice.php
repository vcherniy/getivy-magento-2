<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

class Invoice extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $order;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;

    public function __construct(
        OrderFactory    $order,
        InvoiceService  $invoiceService,
        Transaction     $transaction,
        InvoiceSender   $invoiceSender,
        Context         $context
    ) {
        $this->order = $order;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        parent::__construct($context);
    }

    /**
     * @param Order $order
     * @param string $transactionId
     * @return void
     * @throws LocalizedException
     */
    public function createInvoice(Order $order, string $transactionId)
    {
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
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

            $order->save();
        }

        foreach ($order->getInvoiceCollection() as $invoice) {
            $invoice->setTransactionId($transactionId);
            $invoice->save();
        }

        if ($order->getState() === 'processing') {
//            $order->setStatus('payment_authorised');
            $order->setStatus('processing');
            $order->save();
        }
    }
}
