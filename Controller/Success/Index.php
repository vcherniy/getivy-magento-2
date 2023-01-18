<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Success;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Esparksinc\IvyPayment\Model\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Message as GuzzleMessage;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends Action
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
    private Logger $logger;

    /**
     * @param Context $context
     * @param RedirectFactory $resultRedirectFactory
     * @param OrderFactory $order
     * @param IvyFactory $ivy
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Transaction $transaction
     * @param Json $json
     * @param Config $config
     * @param Onepage $onePage
     * @param Session $checkoutSession
     * @param Logger $logger
     */
    public function __construct(
        Context         $context,
        RedirectFactory $resultRedirectFactory,
        OrderFactory    $order,
        IvyFactory      $ivy,
        InvoiceService  $invoiceService,
        InvoiceSender   $invoiceSender,
        Transaction     $transaction,
        Json            $json,
        Config          $config,
        Onepage         $onePage,
        Session         $checkoutSession,
        Logger          $logger
    ) {
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
        $this->logger = $logger;
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

        $this->logger->debugApiAction($this, $magentoOrderId, 'Sent data', $data);

        try {
            $response = $client->post('order/details', $options);
        } catch (ClientException|ServerException $exception) {
            $response = $exception->getResponse();
            $errorData = GuzzleMessage::bodySummary($response, 1000);
            $this->logger->debugApiAction($this, $magentoOrderId, 'Got API response exception',
                [$errorData]
            );
            throw $exception;
        } finally {
            $this->logger->debugApiAction($this, $magentoOrderId, 'Got API response status', [$response->getStatusCode()]);
        }

        if ($response->getStatusCode() === 200) {
            $arrData = $this->json->unserialize((string)$response->getBody());
            $this->logger->debugApiAction($this, $magentoOrderId, 'Got API response', $arrData);
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

        $this->logger->debugApiAction($this, $magentoOrderId, 'Order', $orderdetails->getData());

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
