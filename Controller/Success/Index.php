<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Success;

use GuzzleHttp\Client;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends \Magento\Framework\App\Action\Action
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
        \Magento\Checkout\Model\Session $checkoutSession
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
        parent::__construct($context);
    }
    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');
        $ivyOrderId = $this->getRequest()->getParam('order-id');

        $data = [
            'id' => $magentoOrderId
        ];
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

        $response = $client->post('order/details', $options);

        if ($response->getStatusCode() === 200) {
            $arrData = $this->json->unserialize((string)$response->getBody());
        }
        
        // Save info in db
        $ivyModel = $this->ivy->create();
        $ivyModel->load($magentoOrderId,'magento_order_id');
        $ivyModel->setIvyOrderId($ivyOrderId);
        $ivyModel->save();

        $quote = $this->checkoutSession->getQuote();
        if($quote->getBillingAddress()->getFirstname())
        {
            $this->onePage->saveOrder();
        }
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
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/success');
        return $resultRedirect;
    }
}
