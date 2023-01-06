<?php

namespace AliReaza\Laravel\MessageBus\RequestResponseGateway\Handlers;

use AliReaza\MessageBus\HandlerInterface;
use AliReaza\MessageBus\MessageInterface;
use Illuminate\Support\Facades\Redis;

class StoreGatewayResponseMessageToRedisHandler implements HandlerInterface
{
    private string $request_cache_prefix;
    private string $request_cache_expire;
    private string $response_cache_prefix;
    private string $response_cache_expire;

    public function __construct()
    {
        $config = config('request-response-with-message-bus');

        $this->request_cache_prefix = $config['request']['cache']['prefix'];
        $this->request_cache_expire = $config['request']['cache']['expire_sec'];

        $this->response_cache_prefix = $config['request']['response']['cache']['prefix'];
        $this->response_cache_expire = $config['request']['response']['cache']['expire_sec'];
    }

    public function __invoke(MessageInterface $message): void
    {
        $correlation_id = $message->getCorrelationId();

        Redis::expire($this->request_cache_prefix . $correlation_id, $this->request_cache_expire);

        Redis::set($this->response_cache_prefix . $correlation_id, (string)$message, 'EX', $this->response_cache_expire);

        Redis::publish($this->response_cache_prefix . $correlation_id, (string)$message);
    }
}
