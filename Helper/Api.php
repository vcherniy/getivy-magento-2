<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Helper;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\Serializer\Json;

class Api extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $json;
    protected $config;
    protected $logger;
    protected $errorResolver;

    public function __construct(
        Json            $json,
        Config          $config,
        Logger          $logger,
        ErrorResolver   $resolver,
        Context $context
    ) {
        $this->json = $json;
        $this->config = $config;
        $this->logger = $logger;
        $this->errorResolver = $resolver;
        parent::__construct($context);
    }

    /**
     * @param Action|string $initiatorName
     * @param string $path
     * @param array $data
     * @param $orderId
     * @param callable $exceptionCallback
     * @return array
     */
    public function requestApi(
        $initiatorName,
        string $path,
        array $data,
        $orderId,
        callable $exceptionCallback
    ): array
    {
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

        $this->logger->debugApiAction($initiatorName, $orderId, 'Sent data', $data);

        try {
            $response = $client->post($path, $options);
        } catch (ClientException|ServerException $exception) {
            $response = $exception->getResponse();

            $exceptionCallback($exception);

            $errorData = $this->errorResolver->formatErrorData($exception);
            $this->logger->debugApiAction($initiatorName, $orderId, 'Got API response exception',
                [$errorData]
            );
            throw $exception;
        } finally {
            $this->logger->debugApiAction($initiatorName, $orderId, 'Got API response status', [$response->getStatusCode()]);
        }

        $data = [];
        if ($response->getStatusCode() === 200) {
            try {
                $data = (array)$this->json->unserialize((string)$response->getBody());
            } catch (\Exception $_) {
                // do nothing
            }

            $this->logger->debugApiAction($initiatorName, $orderId, 'Got API response', $data);
        }
        return $data;
    }
}
