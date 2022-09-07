<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Complete;

use GuzzleHttp\Client;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Success extends \Magento\Framework\App\Action\Action
{
    protected $resultRedirect;
    protected $order;
    protected $ivy;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;
    protected $config;
    protected $json;
    protected $onePage;
    protected $checkoutSession;
    protected $quoteRepository;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory,
        \Magento\Sales\Model\OrderFactory $order,
        \Esparksinc\IvyPayment\Model\IvyFactory $ivy,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Transaction $transaction,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Checkout\Model\Type\Onepage $onePage,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteRepository $quoteRepository
        ){
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->order = $order;
        $this->ivy = $ivy;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->json = $json;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        parent::__construct($context);
    }
    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');
        $ivyOrderId = $this->getRequest()->getParam('order-id');
        
        // Save info in db
        $ivyModel = $this->ivy->create();
        $ivyModel->load($magentoOrderId,'magento_order_id');
        $ivyModel->setIvyOrderId($ivyOrderId);
        $ivyModel->save();
        
        // $this->onePage->saveOrder();
        // $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        // if ($orderdetails->canInvoice()) {
        //     $invoice = $this->invoiceService->prepareInvoice($orderdetails);
        //     $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
        //     $invoice->register();
        //     $invoice->getOrder()->setIsInProcess(true);
        //     $invoice->save();
        //     $transactionSave = $this->transaction->addObject(
        //         $invoice
        //     )->addObject(
        //         $invoice->getOrder()
        //     );
        //     $transactionSave->save();
        //     $this->invoiceSender->send($invoice);
            
        //     $orderdetails->save();
        // }

        // foreach ($orderdetails->getInvoiceCollection() as $invoice)
        // {
        //     $invoice->setTransactionId($ivyOrderId);
        //     $invoice->save();
        // }

        // if($orderdetails->getState() === 'processing')
        // {
        //     $orderdetails->setStatus('payment_authorised');
        //     $orderdetails->save();
        // }

        $quote = $this->checkoutSession->getQuote();

        $this->quoteRepository->delete($quote);
        $this->checkoutSession->clearQuote();
        // TODO : this doesn't seem to work (especially for the minicart)
        // could be because we're ending up in a redirect
        // to be re-evaluated when we open up the configurable thank you page task.
        $this->checkoutSession->clearStorage();
        $this->checkoutSession->clearHelperData();

        if ($orderdetails) {
            $this->checkoutSession->setLastQuoteId($orderdetails->getQuoteId())
                ->setLastSuccessQuoteId($orderdetails->getQuoteId())
                ->setLastOrderId($orderdetails->getId())
                ->setLastRealOrderId($orderdetails->getIncrementId())
                ->setLastOrderStatus($orderdetails->getStatus());
        }
        
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/success', ['_secure' => true]);
        return $resultRedirect;
    }
}
