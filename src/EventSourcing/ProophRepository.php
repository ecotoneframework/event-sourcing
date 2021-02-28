<?php

namespace Ecotone\EventSourcing;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOConnection;
use Ecotone\EventSourcing\StreamConfiguration\SingleStreamConfiguration;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\EventStream;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Ramsey\Uuid\Uuid;

class ProophRepository implements EventSourcedRepository
{
    private HeaderMapper $headerMapper;
    private array $handledAggregateClassNames;
    private array $aggregateClassToStreamName;
    private EventStoreProophIntegration $eventStore;

    public function __construct(EventStoreProophIntegration $eventStore, array $handledAggregateClassNames, HeaderMapper $headerMapper, array $aggregateClassStreamNames)
    {
        $this->eventStore = $eventStore;
        $this->headerMapper = $headerMapper;
        $this->handledAggregateClassNames = $handledAggregateClassNames;
        $this->aggregateClassToStreamName = $aggregateClassStreamNames;
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return in_array($aggregateClassName, $this->handledAggregateClassNames);
    }

    public function findBy(string $aggregateClassName, array $identifiers): EventStream
    {
        $aggregateId = reset($identifiers);
        $streamName = $this->getStreamName($aggregateClassName, $aggregateId);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            LazyProophEventStore::AGGREGATE_TYPE,
            Operator::EQUALS(),
            $aggregateClassName
        );
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            LazyProophEventStore::AGGREGATE_ID,
            Operator::EQUALS(),
            $aggregateId
        );

        try {
            $streamEvents = $this->eventStore->load($streamName, 1, null, $metadataMatcher);
        } catch (StreamNotFound) { return EventStream::createEmpty(); }

        $aggregateVersion = 0;
        if (!empty($streamEvents)) {
            $aggregateVersion = $streamEvents[array_key_last($streamEvents)]->getMetadata()[LazyProophEventStore::AGGREGATE_VERSION];
        }

        return EventStream::createWith($aggregateVersion, $streamEvents);
    }

    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $aggregateId = reset($identifiers);
        $streamName = $this->getStreamName($aggregateClassName, $aggregateId);

        $eventsWithMetadata = [];
        $eventsCount = count($events);
        for ($eventNumber = 1; $eventNumber <= $eventsCount; $eventNumber++) {
            $event = $events[$eventNumber - 1];
            $eventsWithMetadata[] = Event::create(
                $event,
                array_merge(
                    $this->headerMapper->mapFromMessageHeaders($metadata),
                    [
                        LazyProophEventStore::AGGREGATE_ID => $aggregateId,
                        LazyProophEventStore::AGGREGATE_TYPE => $aggregateClassName,
                        LazyProophEventStore::AGGREGATE_VERSION => $versionBeforeHandling + $eventNumber
                    ]
                )
            );
        }
        $this->eventStore->appendTo($streamName, $eventsWithMetadata);
    }

    private function getStreamName(string $aggregateClassName, mixed $aggregateId): StreamName
    {
        $prefix = $aggregateClassName;
        if (array_key_exists($aggregateClassName, $this->aggregateClassToStreamName)) {
            $prefix =  $this->aggregateClassToStreamName[$aggregateClassName];
        }

        return new StreamName($prefix . "-" . $aggregateId);
    }
}