<?php

declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Message as GuzzleMessage;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;

class ErrorResolver
{
    protected CartRepositoryInterface $quoteRepository;
    protected Json $json;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        Json                    $json
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->json = $json;
    }

    /**
     * Force reserve new order id because Magento will try send the same id in the next time
     * that will lead to the "order id must be unique" error on th Ivy side
     *
     * @param CartInterface $quote
     * @return bool
     */
    public function forceReserveOrderId(CartInterface $quote): bool
    {
        $quote->setReservedOrderId(null);
        $quote->reserveOrderId();
        $this->quoteRepository->save($quote);
        return true;
    }

    public function tryResolveException(CartInterface $quote, BadResponseException $exception): bool
    {
        $errorData = $this->formatErrorData($exception);
        if (is_array($errorData) && array_key_exists('devMessage', $errorData)) {
            $devMessage = $errorData['devMessage'];

            // looking for the message "Validation failed - Each Order must have its own unique referenceId"
            if (str_contains($devMessage, 'Each Order must have its own unique referenceId')) {
                $this->forceReserveOrderId($quote);
                return true;
            }
        }
        return false;
    }

    public function formatErrorData(BadResponseException $exception)
    {
        $response = $exception->getResponse();
        $errorData = GuzzleMessage::bodySummary($response, 10000);

        try {
            $result = (array)$this->json->unserialize($errorData);
            if ($errorData && !$result) {
                $result = $errorData;
            }
        } catch (\Exception $_) {
            $result = $errorData;
        }

        return $result;
    }
}
