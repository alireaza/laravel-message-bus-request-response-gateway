<?php

namespace AliReaza\Laravel\MessageBus\RequestResponseGateway\Controllers;

use AliReaza\Laravel\MessageBus\Kafka\Events\MessageCreated;
use AliReaza\MessageBus\Message;
use AliReaza\MessageBus\MessageInterface;
use Exception;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Redis;
use Redis as PHPRedis;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestController extends BaseController
{
    protected array $config = [];

    protected ?Filesystem $file_system = null;

    public function __invoke(Request $request, ?string $correlation_id = null): Response
    {
        $this->initConfig();

        $config = $this->getConfig();

        $message = $this->requestToMessage($request);

        MessageCreated::dispatch($message);

        $response_timeout = $this->getResponseTimeout($request);

        $response_message = null;

        if (is_null($correlation_id)) {
            if (!is_null($response_timeout)) {
                $correlation_id = $message->getCorrelationId();

                $request_cache_prefix = $config['request']['cache']['prefix'];
                $request_cache_expire = $config['request']['cache']['expire_sec'];

                Redis::set($request_cache_prefix . $correlation_id, null, 'EX', $request_cache_expire);

                $response_message = $this->getMessageFromRedis($correlation_id, $response_timeout);
            }
        } else {
            if (is_null($response_timeout)) {
                $response_timeout = $config['request']['response']['timeout_sec'];
            }

            $response_message = $this->getMessageFromRedis($correlation_id, $response_timeout);

            if (is_null($response_message)) {
                return $this->responseNotFound();
            }
        }

        if (is_null($response_message)) {
            $response_message = $message;
        }

        return $this->getResponseForMessage($response_message);
    }

    protected function initConfig(): void
    {
        $config = config('request-response-with-message-bus');

        $this->setConfig($config);
    }

    protected function getConfig(): array
    {
        return $this->config;
    }

    protected function setConfig(array $config): void
    {
        $this->config = $config;
    }

    protected function requestToMessage(Request $request): MessageInterface
    {
        $config = $this->getConfig();

        $content = $this->requestToJson($request);

        $request_message_name = $config['request']['message']['name'];

        return new Message(content: $content, name: $request_message_name);
    }

    protected function requestToJson(Request $request): string
    {
        $array['client'] = $request->getClientIps();
        $array['uri'] = $request->getUri();
        $array['method'] = $request->getRealMethod();
        $array['header'] = $request->headers->all();
        $array['query'] = $request->query->all();
        $array['request'] = $request->request->all();
        $array['cookies'] = $request->cookies->all();
        $files = $request->files->all();
        $array['files'] = $this->filesHandler($files);

        $json = json_encode($array);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->exception('json_encode error: ' . json_last_error_msg());
        }

        return $json ?? '';
    }

    protected function filesHandler(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        $_files = [];

        foreach ($files as $key => $file) {
            $_files[$key] = is_array($file) ? $this->filesHandler($file) : $this->fileHandler($file);
        }

        return $_files;
    }

    protected function fileHandler(UploadedFile $file): array
    {
        $this->initFileSystem();

        $storage_directory = $this->getStorageDirectory();

        $hash = hash_file('sha256', $file->getPathname());
        $name = $file->getClientOriginalName();
        $mime = $file->getMimeType();
        $size = $file->getSize();

        $path = $storage_directory . $hash;

        if ($this->file_system->exists($path)) {
            $this->file_system->remove($file->getRealPath());
        } else {
            $this->file_system->rename($file->getRealPath(), $path);
        }

        return [
            'hash' => $hash,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    protected function initFileSystem(): void
    {
        if (is_null($this->file_system)) {
            $this->file_system = new Filesystem();
        }
    }

    protected function getStorageDirectory(): string
    {
        $config = $this->getConfig();

        $storage = $config['request']['files']['storage'];

        if (is_null($storage)) {
            $storage = storage_path('requests' . DIRECTORY_SEPARATOR);
        }

        return $storage;
    }

    protected function exception(string $message): void
    {
        throw new Exception($message);
    }

    protected function getResponseTimeout(Request $request): ?int
    {
        $response_timeout = null;

        $config = $this->getConfig();

        if ($config['request']['response']['enable']) {
            $response_timeout_param_name = $config['request']['response']['timeout_param_name'];
            $response_timeout = $config['request']['response']['timeout_sec'];

            if ($request->query->has($response_timeout_param_name)) {
                $value = $request->query->get($response_timeout_param_name);

                if ($value === 'false' || $value === '0') {
                    return null;
                }

                $response_timeout_max_sec = $config['request']['response']['timeout_max_sec'];

                if ((int)$value > 0) {
                    if ((int)$value > $response_timeout_max_sec) {
                        return (int)$response_timeout_max_sec;
                    }

                    return (int)$value;
                }
            }
        }

        return $response_timeout;
    }

    protected function getMessageFromRedis(string $correlation_id, int $timeout): ?MessageInterface
    {
        $config = $this->getConfig();

        $request_cache_prefix = $config['request']['cache']['prefix'];
        $response_cache_prefix = $config['request']['response']['cache']['prefix'];

        if (Redis::exists($request_cache_prefix . $correlation_id)) {
            $redis_client = Redis::client();
            $redis_client->setOption(PHPRedis::OPT_READ_TIMEOUT, '0.85');

            for ($t = 0; $t < $timeout; $t++) {
                $value = null;

                try {
                    Redis::subscribe([$response_cache_prefix . $correlation_id], function (string $message) use (&$value): void {
                        $value = $message;

                        Redis::close();
                    });
                } catch (Exception) {
                    if (is_null($value)) {
                        $time = 0;
                        $time_step = 1000000 / 256; // = Approximately 4 milliseconds

                        while (!Redis::exists($response_cache_prefix . $correlation_id) && $time <= ($timeout * 100000)) {
                            $time += $time_step;

                            usleep($time_step);
                        }

                        if (Redis::exists($response_cache_prefix . $correlation_id)) {
                            $value = Redis::get($response_cache_prefix . $correlation_id);
                        }
                    }
                }

                if (!empty($value)) {
                    break;
                }
            }

            if (empty($value)) return null;

            $array = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->exception('json_decode error: ' . json_last_error_msg());
            }

            return new Message(
                content: $array['content'],
                causation_id: $array['causation_id'],
                correlation_id: $array['correlation_id'],
                name: $array['name'],
                timestamp: $array['timestamp'],
                message_id: $array['message_id']
            );
        }

        return null;
    }

    protected function responseNotFound(): Response
    {
        $response = $this->getResponse();
        $response->setContent(null);
        $response->setStatusCode(Response::HTTP_NOT_FOUND);

        return $response;
    }

    protected function getResponse(): Response
    {
        return new JsonResponse();
    }

    protected function getResponseForMessage(MessageInterface $message): Response
    {
        $correlation_id = $message->getCorrelationId();

        $config = $this->getConfig();

        $response_cache_prefix = $config['request']['response']['cache']['prefix'];

        $request_message_name = $config['request']['message']['name'];
        $response_message_name = $config['request']['response']['message']['name'];

        $response = $this->getResponse();
        $response->setContent(null);
        $response->setStatusCode(Response::HTTP_NO_CONTENT);
        $response->headers->set('X-Correlation-ID', $correlation_id);
        $response->headers->set('Expires', Redis::ttl($response_cache_prefix . $correlation_id));

        switch ($message->getName()) {
            case $request_message_name:
                $data = [
                    'correlation_id' => $correlation_id,
                    'url' => route('correlation', ['correlation_id' => $correlation_id]),
                ];

                $response->setData($data);
                $response->setStatusCode(Response::HTTP_ACCEPTED);

                break;

            case $response_message_name:
                $content = json_decode($message->getContent(), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->exception('json_decode error: ' . json_last_error_msg());
                }

                $response->setStatusCode(Response::HTTP_OK);
                $response->setData($content);

                if (array_key_exists('status', $content) && array_key_exists('content', $content)) {
                    try {
                        $data = $content['content'];
                        $status = $content['status'];

                        $response->setData($data);
                        $response->setStatusCode($status);
                    } catch (Exception) {
                        $response->setContent(null);
                        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Message');
                    }
                }

                break;
        }

        return $response;
    }

    public function file(Request $request, string $hash, string $name): Response
    {
        $this->initFileSystem();

        $storage_directory = $this->getStorageDirectory();

        $path = $storage_directory . $hash;

        if ($this->file_system->exists($path)) {
            $file = new File($path);

            $stream = fopen($path, 'rb');

            $callback = function () use ($stream): void {
                while (ob_get_level() > 0) ob_end_flush();

                fpassthru($stream);
            };

            $response = $this->getStream($callback);

            $response->headers->set('Content-Type', $file->getMimeType());

            if ($request->query->has('download')) {
                $disposition = true ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;
                $content_disposition = $response->headers->makeDisposition($disposition, $name);
                $response->headers->set('Content-Disposition', $content_disposition);
            }

            return $response;
        }

        return $this->responseNotFound();
    }

    protected function getStream(callable $callback): Response
    {
        return new StreamedResponse($callback, Response::HTTP_OK);
    }
}
