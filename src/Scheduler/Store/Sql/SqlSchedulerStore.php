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

namespace Desperado\ServiceBus\Scheduler\Store\Sql;

use function Amp\asyncCall;
use Amp\Coroutine;
use Amp\Promise;
use function Desperado\ServiceBus\Common\datetimeToString;
use Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation;
use Desperado\ServiceBus\Scheduler\Data\ScheduledOperation;
use Desperado\ServiceBus\Scheduler\Exceptions\ScheduledOperationNotFound;
use Desperado\ServiceBus\Scheduler\ScheduledOperationId;
use Desperado\ServiceBus\Scheduler\Store\SchedulerStore;
use function Desperado\ServiceBus\Infrastructure\Storage\fetchOne;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\deleteQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\equalsCriteria;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\insertQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\selectQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\updateQuery;
use Desperado\ServiceBus\Infrastructure\Storage\StorageAdapter;
use Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter;

/**
 *
 */
final class SqlSchedulerStore implements SchedulerStore
{
    /**
     * @var StorageAdapter
     */
    private $adapter;

    /**
     * @param StorageAdapter $adapter
     */
    public function __construct(StorageAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @inheritDoc
     */
    public function add(ScheduledOperation $operation, callable $postAdd): \Generator
    {
        /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
        $transaction = yield $this->adapter->transaction();

        try
        {
            /**
             * @var \Latitude\QueryBuilder\Query\InsertQuery $insertQuery
             *
             * @psalm-suppress ImplicitToStringCast
             */
            $insertQuery = insertQuery('scheduler_registry', [
                'id'              => (string) $operation->id(),
                'processing_date' => datetimeToString($operation->date()),
                'command'         => \base64_encode(\serialize($operation->command())),
                'is_sent'         => (int) $operation->isSent()
            ]);

            /** @var \Latitude\QueryBuilder\Query $compiledQuery */
            $compiledQuery = $insertQuery->compile();

            /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
            $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

            unset($insertQuery, $compiledQuery, $resultSet);

            /** Receive next operation and notification about the scheduled job  */

            /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
            $nextOperation = yield new Coroutine(self::fetchNextOperation($transaction));

            asyncCall($postAdd, $operation, $nextOperation);

            yield $transaction->commit();

            unset($nextOperation);
        }
        catch(\Throwable $throwable)
        {
            yield $transaction->rollback();

            /** @noinspection PhpUnhandledExceptionInspection */
            throw $throwable;
        }
        finally
        {
            unset($transaction);
        }
    }

    /**
     * @inheritDoc
     */
    public function remove(ScheduledOperationId $id, callable $postRemove): \Generator
    {
        /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
        $transaction = yield $this->adapter->transaction();

        try
        {
            /**
             * @var \Latitude\QueryBuilder\Query\DeleteQuery $deleteQuery
             *
             * @psalm-suppress ImplicitToStringCast
             */
            $deleteQuery = deleteQuery('scheduler_registry')
                ->where(equalsCriteria('id', $id));

            /** @var \Latitude\QueryBuilder\Query $compiledQuery */
            $compiledQuery = $deleteQuery->compile();

            /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
            $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

            unset($deleteQuery, $compiledQuery, $resultSet);

            /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
            $nextOperation = yield new Coroutine(self::fetchNextOperation($transaction));

            asyncCall($postRemove, $nextOperation);

            yield $transaction->commit();

            unset($nextOperation);
        }
        catch(\Throwable $throwable)
        {
            yield $transaction->rollback();

            /** @noinspection PhpUnhandledExceptionInspection */
            throw $throwable;
        }
        finally
        {
            unset($transaction);
        }
    }

    /**
     * @inheritDoc
     */
    public function extract(ScheduledOperationId $id, callable $postExtract): \Generator
    {
        /** @var \Desperado\ServiceBus\Scheduler\Data\ScheduledOperation|null $operation */
        $operation = yield new Coroutine(self::doLoadOperation($this->adapter, $id));

        /** Scheduled operation not found */
        if(null === $operation)
        {
            throw new ScheduledOperationNotFound(
                \sprintf('Operation with ID "%s" not found', $id)
            );
        }

        /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
        $transaction = yield $this->adapter->transaction();

        try
        {
            /**
             * @var \Latitude\QueryBuilder\Query\DeleteQuery $deleteQuery
             *
             * @psalm-suppress ImplicitToStringCast
             */
            $deleteQuery = deleteQuery('scheduler_registry')
                ->where(equalsCriteria('id', $id));

            /** @var \Latitude\QueryBuilder\Query $compiledQuery */
            $compiledQuery = $deleteQuery->compile();

            /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
            $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

            unset($deleteQuery, $compiledQuery, $resultSet);

            /** @var \Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null $nextOperation */
            $nextOperation = yield new Coroutine(self::fetchNextOperation($transaction));

            asyncCall($postExtract, $operation, $nextOperation);

            yield $transaction->commit();

            unset($nextOperation);
        }
        catch(\Throwable $throwable)
        {
            yield $transaction->rollback();

            /** @noinspection PhpUnhandledExceptionInspection */
            throw $throwable;
        }
        finally
        {
            unset($transaction);
        }
    }

    /**
     * @param TransactionAdapter $transaction
     *
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Desperado\ServiceBus\Scheduler\Data\NextScheduledOperation|null>
     */
    private static function fetchNextOperation(TransactionAdapter $transaction): \Generator
    {
        /** @var \Latitude\QueryBuilder\Query\SelectQuery $selectQuery */
        $selectQuery = selectQuery('scheduler_registry')
            ->where(equalsCriteria('is_sent', 0))
            ->orderBy('processing_date', 'ASC')
            ->limit(1);

        /** @var \Latitude\QueryBuilder\Query $compiledQuery */
        $compiledQuery = $selectQuery->compile();

        /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
        $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

        /** @var array|null $result */
        $result = yield fetchOne($resultSet);

        unset($selectQuery, $compiledQuery, $resultSet);

        if(true === \is_array($result) && 0 !== \count($result))
        {
            /** Update barrier flag */

            /** @var \Latitude\QueryBuilder\Query\UpdateQuery $updateQuery */
            $updateQuery = updateQuery('scheduler_registry', ['is_sent' => 1])
                ->where(equalsCriteria('id', (string) $result['id']))
                ->andWhere(equalsCriteria('is_sent', 0));

            /** @var \Latitude\QueryBuilder\Query $compiledQuery */
            $compiledQuery = $updateQuery->compile();

            /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
            $resultSet    = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());
            $affectedRows = $resultSet->affectedRows();

            unset($updateQuery, $compiledQuery, $resultSet);

            if(0 !== $affectedRows)
            {
                return NextScheduledOperation::fromRow($result);
            }
        }
    }

    /**
     * @param StorageAdapter       $adapter
     * @param ScheduledOperationId $id
     *
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Desperado\ServiceBus\Scheduler\Data\ScheduledOperation|null>
     */
    private static function doLoadOperation(StorageAdapter $adapter, ScheduledOperationId $id): \Generator
    {
        $operation = null;

        /**
         * @var \Latitude\QueryBuilder\Query\SelectQuery $selectQuery
         *
         * @psalm-suppress ImplicitToStringCast
         */
        $selectQuery = selectQuery('scheduler_registry')
            ->where(equalsCriteria('id', $id));

        /** @var \Latitude\QueryBuilder\Query $compiledQuery */
        $compiledQuery = $selectQuery->compile();

        /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
        $resultSet = yield $adapter->execute($compiledQuery->sql(), $compiledQuery->params());

        /** @var array{processing_date:string, command:string, id:string, is_sent:bool}|null $result */
        $result = yield fetchOne($resultSet);

        unset($selectQuery, $compiledQuery, $resultSet);

        if(true === \is_array($result) && 0 !== \count($result))
        {
            $result['command'] = $adapter->unescapeBinary($result['command']);

            $operation = ScheduledOperation::restoreFromRow($result);
        }

        return $operation;
    }
}
