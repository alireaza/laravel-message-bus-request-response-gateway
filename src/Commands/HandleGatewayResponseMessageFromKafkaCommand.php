<?php

namespace AliReaza\Laravel\MessageBus\RequestResponseGateway\Commands;

use AliReaza\Laravel\MessageBus\RequestResponseGateway\Handlers\StoreGatewayResponseMessageToRedisHandler;
use AliReaza\Laravel\MessageBus\Kafka\Commands\KafkaMessagesHandlerCommand;
use AliReaza\MessageBus\Kafka\Helper as KafkaHelper;
use AliReaza\MessageBus\Message;
use AliReaza\MessageBus\MessageHandlerInterface;
use AliReaza\MessageBus\MessageInterface;

class HandleGatewayResponseMessageFromKafkaCommand extends KafkaMessagesHandlerCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'message-bus:kafka-handle-gateway-response-message';

    /**
     * Create a new console command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $description = 'Handle messages of the Topic "'. $this->getTopic() .'" from Apache Kafka';

        $this->setDescription($description);
    }

    protected function addInputTopic(): void
    {
    }

    protected function getTopic(): string
    {
        $config = config('request-response-with-message-bus');

        $topic = $config['request']['response']['message']['name'];

        return KafkaHelper::name($topic);
    }

    protected function handleMessage(MessageHandlerInterface $message_handler): void
    {
        $message = $this->getMessage();

        $handlers = $this->getHandlers($message);

        foreach ($handlers as $handler) {
            $message_handler->addHandler($message, $handler);
        }

        $message_handler->handle($message);
    }

    private function getMessage(): MessageInterface
    {
        $name = $this->getTopic();

        return new Message(name: $name);
    }

    protected function getHandlers(MessageInterface $message): iterable
    {
        if (empty($message->getName())) {
            return [];
        }

        return [new StoreGatewayResponseMessageToRedisHandler()];
    }
}
