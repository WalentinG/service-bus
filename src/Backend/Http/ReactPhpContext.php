<?php

/**
 * CQRS/Event Sourcing framework
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @url     https://github.com/mmasiukevich
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\Framework\Backend\Http;

use Desperado\CQRS\Context\DeliveryContextInterface;
use Desperado\CQRS\Context\DeliveryOptions;
use Desperado\Domain\Environment\Environment;
use Desperado\Domain\Message\CommandInterface;
use Desperado\Domain\Message\EventInterface;
use Desperado\Domain\Message\MessageInterface;
use Desperado\Domain\MessageSerializer\MessageSerializerInterface;
use Desperado\Domain\ParameterBag;
use Desperado\Framework\Application\ApplicationLogger;
use Desperado\Infrastructure\Bridge\Publisher\PublisherInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Response;

/**
 * ReactPHP execution context
 */
class ReactPhpContext implements DeliveryContextInterface
{
    /**
     * Message serializer
     *
     * @var MessageSerializerInterface
     */
    private $serializer;

    /**
     * Publisher instance
     *
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * Entry point name
     *
     * @var string
     */
    private $entryPointName;

    /**
     * Routing key
     *
     * @var string
     */
    private $routingKey;

    /**
     * Response instance
     *
     * @var Response|null
     */
    private $response;

    /**
     * Application environment
     *
     * @var Environment
     */
    private $environment;

    /**
     * @param MessageSerializerInterface $serializer
     * @param PublisherInterface         $publisher
     * @param string                     $entryPointName
     * @param string                     $routingKey
     * @param Environment                $environment
     */
    public function __construct(
        MessageSerializerInterface $serializer,
        PublisherInterface $publisher,
        string $entryPointName,
        string $routingKey,
        Environment $environment
    )
    {
        $this->serializer = $serializer;
        $this->publisher = $publisher;
        $this->entryPointName = $entryPointName;
        $this->routingKey = $routingKey;
        $this->environment = $environment;
    }

    /**
     * Write response
     *
     * @param int         $httpCode
     * @param null|string $responseBody
     * @param array       $headers
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function writeResponse(
        int $httpCode,
        ?string $responseBody = null,
        array $headers = []
    ): void
    {
        if(null === $this->response)
        {
            $this->response = new Response(
                $httpCode,
                $headers,
                $responseBody
            );
        }
        else
        {
            throw new \LogicException('Response already created');
        }
    }

    /**
     * Get response instance
     *
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        if(null !== $this->response)
        {
            return $this->response;
        }

        throw new \LogicException('You must call the "sendResponse" context method within the query handler');
    }

    /**
     * @inheritdoc
     */
    public function delivery(MessageInterface $message, DeliveryOptions $deliveryOptions = null): void
    {
        $deliveryOptions = $deliveryOptions ?? new DeliveryOptions();

        $this->publishMessage($deliveryOptions, $message);
    }

    /**
     * @inheritdoc
     */
    public function send(CommandInterface $command, DeliveryOptions $deliveryOptions): void
    {
        $this->publishMessage($deliveryOptions, $command);
    }

    /**
     * @inheritdoc
     */
    public function publish(EventInterface $event, DeliveryOptions $deliveryOptions): void
    {
        $this->publishMessage($deliveryOptions, $event);
        $this->publishMessage(
            $deliveryOptions->changeDestination(
                \sprintf('%s.events', $this->entryPointName)
            ),
            $event
        );
    }

    /**
     * @inheritdoc
     */
    public function getMessageMetadata(): ParameterBag
    {
        return new ParameterBag();
    }

    /**
     * Send message to broker
     *
     * @param DeliveryOptions  $deliveryOptions
     * @param MessageInterface $message
     *
     * @return void
     */
    private function publishMessage(DeliveryOptions $deliveryOptions, MessageInterface $message): void
    {
        $destination = '' !== $deliveryOptions->getDestination()
            ? $deliveryOptions->getDestination()
            : $this->entryPointName;

        $serializedMessage = $this->serializer->serialize($message);

        $messageHeaders = $deliveryOptions->getHeaders();

        $messageHeaders->set('fromHost', \gethostname());
        $messageHeaders->set('daemon', 'reactPHP');

        if(true === $this->environment->isDebug())
        {
            ApplicationLogger::debug(
                'reactPHP',
                \sprintf(
                    '%s "%s" to "%s" exchange with routing key "%s". Message data: %s (with headers "%s")',
                    $message instanceof CommandInterface
                        ? 'Send message'
                        : 'Publish event',
                    \get_class($message),
                    $destination,
                    $this->routingKey,
                    $serializedMessage,
                    \urldecode(
                        \http_build_query(
                            $messageHeaders->all()
                        )
                    )
                )
            );
        }

        $this->publisher->publish($destination, $this->routingKey, $serializedMessage, $messageHeaders->all());
    }
}
