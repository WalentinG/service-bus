<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Sagas;

use Desperado\ServiceBus\Common\Contract\Messages\Command;
use Desperado\ServiceBus\Common\Contract\Messages\Event;
use function Desperado\ServiceBus\Common\datetimeInstantiator;
use Desperado\ServiceBus\Sagas\Contract\SagaCreated;
use Desperado\ServiceBus\Sagas\Contract\SagaStatusChanged;
use Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed;
use Desperado\ServiceBus\Sagas\Exceptions\InvalidIdentifier;

/**
 * Base class for all sagas
 */
abstract class Saga
{
    /**
     * The prefix from which all names of methods-listeners of events should begin
     *
     * @var string
     */
    public const EVENT_APPLY_PREFIX = 'on';

    /**
     * Saga identifier
     *
     * @var SagaId
     */
    private $id;

    /**
     * List of events that should be published while saving
     *
     * @var \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Event>
     */
    private $events;

    /**
     * List of commands that should be fired while saving
     *
     * @var \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Command>
     */
    private $commands;

    /**
     * SagaStatus of the saga
     *
     * @var SagaStatus
     */
    private $status;

    /**
     * Date of saga creation
     *
     * @var \DateTimeImmutable
     */
    private $createdAt;

    /**
     * Date of saga closed
     *
     * @var \DateTimeImmutable|null
     */
    private $closedAt;

    /**
     * @noinspection PhpDocMissingThrowsInspection
     *
     * @param SagaId $id
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\InvalidIdentifier
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    final public function __construct(SagaId $id)
    {
        $this->assertSagaClassEqualsWithId($id);
        $this->clear();

        /** @var \DateTimeImmutable $currentDatetime */
        $currentDatetime = datetimeInstantiator('NOW');

        $this->id     = $id;
        $this->status = SagaStatus::created();

        /**
         * @noinspection UnusedConstructorDependenciesInspection
         *
         * @see          SagaProvider::doStore()
         */
        $this->createdAt = $currentDatetime;

        $this->raise(SagaCreated::create($id));
    }

    /**
     * Flush commands/events on wakeup
     *
     * @return void
     */
    final public function __wakeup(): void
    {
        $this->clear();
    }

    /**
     * Start saga flow
     *
     * @param Command $command
     *
     * @return void
     */
    abstract public function start(Command $command): void;

    /**
     * Receive saga id
     *
     * @return SagaId
     */
    final public function id(): SagaId
    {
        return $this->id;
    }

    /**
     * Receive saga status
     *
     * @return SagaStatus
     */
    final public function status(): SagaStatus
    {
        return $this->status;
    }

    /**
     * Raise (apply event)
     *
     * @param Event $event
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    final protected function raise(Event $event): void
    {
        $this->assertNotClosedSaga();
        $this->applyEvent($event);
        $this->events->attach($event);
    }

    /**
     * Fire command
     *
     * @param Command $command
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    final protected function fire(Command $command): void
    {
        $this->assertNotClosedSaga();

        $this->commands->attach($command);
    }

    /**
     * Change saga status to completed
     *
     * @see SagaStatus::STATUS_COMPLETED
     *
     * @param string|null $withReason
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    final protected function makeCompleted(string $withReason = null): void
    {
        $this->assertNotClosedSaga();

        $event = SagaStatusChanged::create(
            $this->id,
            $this->status,
            SagaStatus::completed(),
            $withReason
        );

        $this->events->attach($event);

        $this->status   = SagaStatus::completed();
        $this->closedAt = $event->datetime();
    }

    /**
     * Change saga status to failed
     *
     * @see SagaStatus::STATUS_FAILED
     *
     * @param string|null $withReason
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    final protected function makeFailed(string $withReason = null): void
    {
        $this->assertNotClosedSaga();

        $event = SagaStatusChanged::create(
            $this->id,
            $this->status,
            SagaStatus::failed(),
            $withReason
        );

        $this->events->attach($event);

        $this->status   = SagaStatus::failed();
        $this->closedAt = $event->datetime();
    }

    /**
     * Receive a list of commands that should be fired while saving
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @see          SagaProvider::doStore()
     *
     * @return \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Command>
     */
    private function firedCommands(): \SplObjectStorage
    {
        /** @var \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Command> $commands */
        $commands = clone $this->commands;

        $this->clearFiredCommands();

        return $commands;
    }

    /**
     * Receive a list of events that should be published while saving
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @see          SagaProvider::doStore()
     *
     * @return \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Event>
     */
    private function raisedEvents(): \SplObjectStorage
    {
        /** @var \SplObjectStorage<\Desperado\ServiceBus\Common\Contract\Messages\Event> $events */
        $events = clone $this->events;

        $this->clearRaisedEvents();

        return $events;
    }

    /**
     * Apply event
     *
     * @param Event $event
     *
     * @return void
     */
    private function applyEvent(Event $event): void
    {
        $eventListenerMethodName = self::createListenerName($event);

        /**
         * Call child class method
         *
         * @param Event $event
         *
         * @return void
         */
        $closure = function(Event $event) use ($eventListenerMethodName): void
        {
            if(true === \method_exists($this, $eventListenerMethodName))
            {
                $this->{$eventListenerMethodName}($event);
            }
        };

        $closure->call($this, $event);
    }

    /**
     * Create event listener name
     *
     * @param Event $event
     *
     * @return string
     */
    private static function createListenerName(Event $event): string
    {
        $eventListenerMethodNameParts = \explode('\\', \get_class($event));

        return \sprintf(
            '%s%s',
            self::EVENT_APPLY_PREFIX,
            \end($eventListenerMethodNameParts)
        );
    }

    /**
     * Clear raised events and fired commands
     *
     * @return void
     */
    private function clear(): void
    {
        $this->clearFiredCommands();
        $this->clearRaisedEvents();
    }

    /**
     * Clear raised events
     *
     * @return void
     */
    private function clearRaisedEvents(): void
    {
        $this->events = new \SplObjectStorage();
    }

    /**
     * Clear fired commands
     *
     * @return void
     */
    private function clearFiredCommands(): void
    {
        $this->commands = new \SplObjectStorage();
    }

    /**
     * Checking the possibility of changing the state of the saga
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\ChangeSagaStateFailed
     */
    private function assertNotClosedSaga(): void
    {
        if(false === $this->status->inProgress())
        {
            throw new ChangeSagaStateFailed('Changing the state of the saga is impossible: the saga is complete');
        }
    }

    /**
     * @param SagaId $id
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\Sagas\Exceptions\InvalidIdentifier
     */
    private function assertSagaClassEqualsWithId(SagaId $id): void
    {
        $currentSagaClass = \get_class($this);

        if($currentSagaClass !== $id->sagaClass())
        {
            throw new InvalidIdentifier(
                \sprintf(
                    'The class of the saga in the identifier ("%s") differs from the saga to which it was transmitted ("%s")',
                    $currentSagaClass,
                    $id->sagaClass()
                )
            );
        }
    }

    /**
     * Close clone method
     *
     * @codeCoverageIgnore
     */
    private function __clone()
    {

    }
}
