<?php

namespace AliReaza\Laravel\MessageBus\RequestResponseGateway;

use Illuminate\Support\ServiceProvider;
use AliReaza\Laravel\MessageBus\Kafka\Events\MessageCreated;
use AliReaza\Laravel\MessageBus\Kafka\Listeners\DispatchMessageToKafka;
use AliReaza\Laravel\MessageBus\RequestResponseGateway\Commands\HandleGatewayResponseMessageFromKafkaCommand;

class RequestResponseGatewayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . DIRECTORY_SEPARATOR . 'config.php', 'request-response-with-message-bus');

        $this->loadRoutesFrom(__DIR__ . DIRECTORY_SEPARATOR . 'routes.php');

        $this->app['events']->listen(MessageCreated::class, DispatchMessageToKafka::class);

        $this->commands(HandleGatewayResponseMessageFromKafkaCommand::class);
    }
}
