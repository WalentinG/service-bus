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

namespace Desperado\ServiceBus\EventSourcing\EventStreamStore\Exceptions;

use Desperado\ServiceBus\Common\Exceptions\ServiceBusExceptionMarker;
use Desperado\ServiceBus\EventSourcing\AggregateId;

/**
 * Attempt to add a stream with an existing identifier
 */
final class NonUniqueStreamId extends \RuntimeException implements ServiceBusExceptionMarker
{
    /**
     * @param AggregateId $id
     */
    public function __construct(AggregateId $id)
    {
        parent::__construct(
            \sprintf('attempt to add a stream with an existing identifier "%s:%s"', $id, \get_class($id))
        );
    }
}
