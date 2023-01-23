<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Success;

use Esparksinc\IvyPayment\Helper\Invoice as InvoiceHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\OrderFactory;

class Index extends Action
{
    protected $resultRedirect;
    protected $order;
    protected $ivy;
    protected $config;
    protected $json;
    protected $onePage;
    protected $checkoutSession;
    protected $logger;
    protected $errorResolver;
    protected $invoiceHelper;

    /**
     * @param Context $context
     * @param RedirectFactory $resultRedirectFactory
     * @param OrderFactory $order
     * @param IvyFactory $ivy
     * @param Json $json
     * @param Config $config
     * @param Onepage $onePage
     * @param Session $checkoutSession
     * @param Logger $logger
     * @param ErrorResolver $resolver
     * @param InvoiceHelper $invoiceHelper
     */
    public function __construct(
        Context         $context,
        RedirectFactory $resultRedirectFactory,
        OrderFactory    $order,
        IvyFactory      $ivy,
        Json            $json,
        Config          $config,
        Onepage         $onePage,
        Session         $checkoutSession,
        Logger          $logger,
        ErrorResolver   $resolver,
        InvoiceHelper   $invoiceHelper
    ) {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->order = $order;
        $this->ivy = $ivy;
        $this->json = $json;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->errorResolver = $resolver;
        $this->invoiceHelper = $invoiceHelper;
        parent::__construct($context);
    }
    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');
        $ivyOrderId = $this->getRequest()->getParam('order-id');

        $this->responseToPaymentSystem($magentoOrderId);

        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        $this->invoiceHelper->createInvoice($orderdetails, $ivyOrderId);

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

        $this->logger->debugApiAction($this, $magentoOrderId, 'Order', $orderdetails->getData());
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/success');
        return $resultRedirect;
    }

    /**
     * @param $magentoOrderId
     * @return void
     */
    protected function responseToPaymentSystem($magentoOrderId)
    {
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

            $quote = $this->checkoutSession->getQuote();
            $this->errorResolver->forceReserveOrderId($quote);

            $errorData = $this->errorResolver->formatErrorData($exception);
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
    }
}
