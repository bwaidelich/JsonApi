<?php

namespace Ttree\JsonApi\Adapter;

//use CloudCreativity\LaravelJsonApi\Contracts\Adapter\HasManyAdapterInterface;
//use CloudCreativity\LaravelJsonApi\Contracts\Adapter\RelationshipAdapterInterface;
//use CloudCreativity\LaravelJsonApi\Contracts\Object\RelationshipInterface;
//use CloudCreativity\LaravelJsonApi\Contracts\Object\ResourceObjectInterface;
//use CloudCreativity\LaravelJsonApi\Contracts\Pagination\PageInterface;
//use CloudCreativity\LaravelJsonApi\Contracts\Pagination\PagingStrategyInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Utility\Arrays;
use Neomerx\JsonApi\Contracts\Encoder\EncoderInterface;
use Neomerx\JsonApi\Encoder\EncoderOptions;
use Neomerx\JsonApi\Contracts\Encoder\Parameters\EncodingParametersInterface;
use Neomerx\JsonApi\Contracts\Encoder\Parameters\SortParameterInterface;
use Ttree\JsonApi\Contract\Object\ResourceObjectInterface;
use Ttree\JsonApi\Contract\Object\RelationshipInterface;
use Ttree\JsonApi\Domain\Model\Concern\DeserializesAttributeTrait;
use Ttree\JsonApi\Domain\Repository\DefaultRepository;
use Ttree\JsonApi\Contract\Object\StandardObjectInterface;
use Ttree\JsonApi\Encoder\Encoder;
use Ttree\JsonApi\Exception;
use Ttree\JsonApi\Exception\RuntimeException;
use Ttree\JsonApi\Utility\StringUtility as Str;

/**
 * Class AbstractAdapter
 *
 * @package Ttree\JsonApi
 *
 * @api
 */
abstract class AbstractAdapter extends AbstractResourceAdapter
{
    use DeserializesAttributeTrait;

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @var string
     */
    protected $resource;

    /**
     * @var EncodingParametersInterface
     */
    protected $parameters;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject()
     * @var DefaultRepository
     */
    protected $repository;

    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="endpoints")
     */
    protected $settings;

    /**
     * @var array
     */
    protected $endPointSettings;

    /**
     * @var array
     */
    protected $configuration;

    /**
     * @var Object
     */
    protected $model;

    /**
     * @var PagingStrategyInterface|null
     */
    protected $paging;

    /**
     * The model key that is the primary key for the resource id.
     *
     * If empty, defaults to `Model::getKeyName()`.
     *
     * @var string|null
     */
    protected $primaryKey;

    /**
     * The filter param for a find-many request.
     *
     * If null, defaults to the JSON API keyword `id`.
     *
     * @var string|null
     */
    protected $findManyFilter = null;

    /**
     * The default pagination to use if no page parameters have been provided.
     *
     * If your resource must always be paginated, use this to return the default
     * pagination variables... e.g. `['number' => 1]` for page 1.
     *
     * If this property is null or an empty array, then no pagination will be
     * used if no page parameters have been provided (i.e. every resource
     * will be returned).
     *
     * @var array|null
     */
    protected $defaultPagination = null;

    /**
     * The model relationships to eager load on every query.
     *
     * @var string[]|null
     * @deprecated use `$defaultWith` instead.
     */
    protected $with = null;

    /**
     * A mapping of sort parameters to columns.
     *
     * Use this to map any parameters to columns where the two are not identical. E.g. if
     * your sort param is called `sort` but the column to use is `type`, then set this
     * property to `['sort' => 'type']`.
     *
     * @var array
     */
    protected $sortColumns = [];

    /**
     * @param string $endpoint
     * @param string $resource
     * @param EncodingParametersInterface $parameters
     */
    public function __construct($endpoint, $resource, EncodingParametersInterface $parameters)
    {
        $this->endpoint = $endpoint;
        $this->resource = $resource;
        $this->parameters = $parameters;
    }

    /**
     * @throws Exception
     */
    protected function initializeObject()
    {
        $this->initializeConfiguration();
        $this->registerRepository();
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function initializeConfiguration()
    {
        $this->endPointSettings = $this->settings[$this->endpoint];

        $configuration = Arrays::getValueByPath($this->endPointSettings, ['resources', $this->resource]);
        if (!\is_array($configuration)) {
            throw new Exception(\sprintf('Resource "%s" not configured', $this->resource), 1447947509);
        }
        $this->configuration = $configuration;
    }

    /**
     * @param string|null $urlPrefix
     * @param integer $depth
     * @return EncoderInterface
     */
    public function getEncoder($urlPrefix = null, $depth = 512)
    {
        return Encoder::instance($this->configuration['schemas'], new EncoderOptions(JSON_PRETTY_PRINT, $urlPrefix, $depth));
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return isset($this->endPointSettings['baseUrl']) && isset($this->endPointSettings['version']) ? $this->endPointSettings['baseUrl'] . '/' . $this->endPointSettings['version'] : '/';
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return object|JsonApiRepositoryInterface
     * @throws Exception
     */
    protected function registerRepository()
    {
        $repository = $this->objectManager->get('Ttree\JsonApi\Domain\Repository\DefaultRepository');
        if (isset($this->configuration['repository'])) {
            $repository = $this->objectManager->get($this->configuration['repository']);
        }

        if (!isset($this->configuration['entity'])) {
            throw new Exception(sprintf('Resource "%s" no "entity" configured', $this->resource), 1447947510);
        }

        $repository->setEntityClassName($this->configuration['entity']);
        $this->model = $repository->getEntityClassName();
        $this->repository = $repository;
    }

    /**
     * Apply the supplied filters to the builder instance.
     *
     * @param QueryInterface $query
     * @param array|null $filters
     * @return void
     */
    abstract protected function filter($query, $filters);

    /**
     * @param EncodingParametersInterface $parameters
     * @return mixed|PageInterface
     * @throws RuntimeException
     */
    public function query(EncodingParametersInterface $parameters)
    {
        $filters = $this->extractFilters($parameters);
        $query = $this->newQuery();

//        /** Apply eager loading */
//        $this->with($query, $this->extractIncludePaths($parameters));
//
//        /** Find by ids */
//        if ($this->isFindMany($filters)) {
//            return $this->findByIds($query, $filters);
//        }
//
        /** Filter and sort */
        $this->filter($query, $filters);

        $this->sort($query, $parameters->getSortParameters());

        /** Paginate results if needed. */
        $pagination = $this->extractPagination($parameters);

//        if (!$pagination->isEmpty() && !$this->hasPaging()) {
//            throw new RuntimeException('Paging parameters exist but paging is not supported.');
//        }

//        return $pagination->isEmpty() ?
//            $this->all($query) :
//            $this->paginate($query, $this->normalizeParameters($parameters, $pagination));
        return $this->all($query);
    }

    /**
     * @param EncodingParametersInterface $parameters
     * @return mixed
     * @throws RuntimeException
     */
    public function count(EncodingParametersInterface $parameters)
    {
        $filters = $this->extractFilters($parameters);
        $query = $this->newQuery();

        /** Filter and sort */
        $this->filter($query, $filters);

        return $this->countAll($query);
    }

    /**
     * @todo need to figure out what to do with this does not currently support default pagination as it causes a problem with polymorphic relations
     * Query the resource when it appears in a relation of a parent model.
     *
     * For example, a request to `/posts/1/comments` will invoke this method on the
     * comments adapter.
     *
     * @param Relations\BelongsToMany|Relations\HasMany|Relations\HasManyThrough $relation
     * @param EncodingParametersInterface $parameters
     * @return mixed
     */
    public function queryRelation($relation, EncodingParametersInterface $parameters)
    {
        $query = $relation->newQuery();

//        /** Apply eager loading */
//        $this->with($query, $this->extractIncludePaths($parameters));

        /** Filter and sort */
        $this->filter($query, $this->extractFilters($parameters));
        $this->sort($query, (array)$parameters->getSortParameters());

        /** Paginate results if needed. */
        $pagination = $parameters->getPaginationParameters();

//        if (!$pagination->isEmpty() && !$this->hasPaging()) {
//            throw new RuntimeException('Paging parameters exist but paging is not supported.');
//        }

        return $pagination->isEmpty() ?
            $this->all($query) :
            $this->paginate($query, $parameters);
    }

    /**
     * @inheritDoc
     */
    public function read($resourceId, EncodingParametersInterface $parameters)
    {
        if ($record = parent::read($resourceId, $parameters)) {
            $this->load($record, $parameters);
        }

        return $record;
    }

    /**
     * @inheritdoc
     */
    public function update($record, ResourceObjectInterface $resource, EncodingParametersInterface $parameters)
    {
        /** @var object $record */
        $record = parent::update($record, $resource, $parameters);

        $this->load($record, $parameters);

        return $record;
    }

    /**
     * @param $record
     * @param EncodingParametersInterface $params
     * @return bool|void
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    public function delete($record, EncodingParametersInterface $params)
    {
        $this->repository->remove($record);
    }

    /**
     * @inheritDoc
     */
    public function exists($resourceId)
    {
//        return $this->newQuery()->where($this->getQualifiedKeyName(), $resourceId)->exists();
    }

    /**
     * @inheritDoc
     */
    public function find($resourceId)
    {
        return $this->repository->findByIdentifier($resourceId);
    }

    /**
     * @inheritDoc
     */
    public function findMany(array $resourceIds)
    {
//        return $this->newQuery()->whereIn($this->getQualifiedKeyName(), $resourceIds)->get()->all();
    }

    /**
     * Get a new query builder.
     *
     * Child classes can overload this method if they want to modify the query instance that
     * is used for every query the adapter does.
     *
     * @return QueryInterface
     */
    protected function newQuery()
    {
        return $this->repository->createQuery();
    }

    /**
     * @todo Determine if necessary
     * Add eager loading to the query.
     *
     * @param QueryInterface $query
     * @param array $includePaths
     *      the paths for resources that will be included.
     * @return void
     */
    protected function with($query, $includePaths)
    {
        $query->with($this->getRelationshipPaths($includePaths));
    }

    /**
     * @todo eager load the includedpaths and relationship paths
     * Add eager loading to a record.
     *
     * @param $record
     * @param EncodingParametersInterface $parameters
     */
    protected function load($record, EncodingParametersInterface $parameters)
    {
//        $relationshipPaths = $this->getRelationshipPaths($this->extractIncludePaths($parameters));

//        /** Eager load anything that needs to be loaded. */
//        if (method_exists($record, 'loadMissing')) {
//            $record->loadMissing($relationshipPaths);
//        }
    }

    /**
     * @inheritDoc
     */
    protected function createRecord(ResourceObjectInterface $resource)
    {
        return new $this->model();
    }

    /**
     * @todo
     * @inheritDoc
     */
    protected function hydrateAttributes($record, StandardObjectInterface $attributes)
    {
        $data = [];
        foreach ($attributes as $attribute => $value) {
            /** Skip any JSON API fields that are not to be filled. */
            // TODO: Check if fields are prohibitated
//            if ($this->isNotFillable($field, $record)) {
//                continue;
//            }

            $property = $this->attributeToProperty($attribute);

            if (method_exists($record, $methodName ='set'. \ucfirst($property))) {
                $record->$methodName($this->deserializeAttribute($value, $property, $attributes));
            }
        }
    }

    /**
     * @todo
     * @inheritdoc
     */
    protected function fillRelationship(
        $record,
        $field,
        RelationshipInterface $relationship,
        EncodingParametersInterface $parameters
    )
    {
        $relation = $this->related($field);

        if (!$this->requiresPrimaryRecordPersistence($relation)) {
            $relation->update($record, $relationship, $parameters);
        }
    }

    /**
     * @todo
     * Hydrate related models after the primary record has been persisted.
     *
     * @param object $record
     * @param ResourceObjectInterface $resource
     * @param EncodingParametersInterface $parameters
     */
    protected function hydrateRelated(
        $record,
        ResourceObjectInterface $resource,
        EncodingParametersInterface $parameters
    )
    {
        $relationships = $resource->getRelationships();
        $changed = false;

        if ($relationships !== null) {
            foreach ($relationships->getAll() as $field => $value) {
//            /** Skip any fields that are not fillable. */
//            if ($this->isNotFillable($field, $record)) {
//                continue;
//            }

//            /** Skip any fields that are not relations */
//            if (!$this->isRelation($field)) {
//                continue;
//            }

//            $relation = $this->related($field);
//
//            if ($this->requiresPrimaryRecordPersistence($relation)) {
//                $relation->update($record, $relationships->getRelationship($field), $parameters);
//                $changed = true;
//            }
            }
        }


//        /** If there are changes, we need to refresh the model in-case the relationship has been cached. */
        if ($changed) {
            $this->persist($record);
        }
    }

    /**
     * @todo
     * Does the relationship need to be hydrated after the primary record has been persisted?
     *
     * @param RelationshipAdapterInterface $relation
     * @return bool
     */
    protected function requiresPrimaryRecordPersistence($relation)
    {
        return $relation instanceof HasManyAdapterInterface || $relation instanceof HasOne;
    }

    /**
     * @param $record
     * @return object
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    protected function persist($record)
    {
        if ($this->persistenceManager->isNewObject($record)) {
            $this->repository->add($record);
        } else {
            $this->repository->update($record);
        }

        return $record;
    }

    /**
     * @todo
     * @param QueryInterface $query
     * @param Collection $filters
     * @return mixed
     */
    protected function findByIds($query, $filters)
    {
        return $query
            ->whereIn($this->getQualifiedKeyName(), $this->extractIds($filters))
            ->get();
    }

    /**
     * Return the result for a search one query.
     *
     * @param QueryInterface $query
     * @return object
     */
    protected function first($query)
    {
        return $query->execute()->getFirst();
    }

    /**
     * Return the result for query that is not paginated.
     *
     * @param QueryInterface $query
     * @return mixed
     */
    protected function all($query)
    {
        return $query->execute();
    }

    /**
     * Return the count of the query
     * @param $query
     * @return mixed
     */
    protected function countAll($query)
    {
        return $query->execute()->count();
    }

    /**
     * @todo
     * Return the result for a paginated query.
     *
     * @param QueryInterface $query
     * @param EncodingParametersInterface $parameters
     * @return PageInterface
     */
    protected function paginate($query, EncodingParametersInterface $parameters)
    {
        return $this->paging->paginate($query, $parameters);
    }

    /**
     * @todo
     * Get the key that is used for the resource ID.
     *
     * @return string
     */
    protected function getKeyName()
    {
        return $this->primaryKey ?: $this->model->getKeyName();
    }

    /**
     * @todo
     * @return string
     */
    protected function getQualifiedKeyName()
    {
        return \sprintf('%s.%s', $this->model->getTable(), $this->getKeyName());
    }

    /**
     * @param EncodingParametersInterface $parameters
     * @return array
     */
    protected function extractIncludePaths(EncodingParametersInterface $parameters)
    {
        return $parameters->getIncludePaths();
    }

    /**
     * @param EncodingParametersInterface $parameters
     * @return array
     */
    protected function extractFilters(EncodingParametersInterface $parameters)
    {
        return $parameters->getFilteringParameters();
    }

    /**
     * @param EncodingParametersInterface $parameters
     * @return array
     */
    protected function extractPagination(EncodingParametersInterface $parameters)
    {
        $pagination = (array)$parameters->getPaginationParameters();

        return $pagination ?: $this->defaultPagination();
    }

    /**
     * @return array
     */
    protected function defaultPagination()
    {
        return (array)$this->defaultPagination;
    }

    /**
     * @return bool
     */
    protected function hasPaging()
    {
//        return $this->paging instanceof PagingStrategyInterface;
    }

    /**
     * Apply sort parameters to the query.
     *
     * @param QueryInterface $query
     * @param array $sortBy
     * @return void
     */
    protected function sort($query, $sortBy)
    {
        if (empty($sortBy)) {
            $this->defaultSort($query);
            return;
        }

        $ordering = [];
        /** @var SortParameterInterface $param */
        foreach ($sortBy as $param) {
            $ordering = \array_merge($ordering, $this->sortBy($query, $param));
        }

        $query->setOrderings($ordering);
    }

    /**
     * Apply a default sort order if the client has not requested any sort order.
     *
     * Child classes can override this method if they want to implement their
     * own default sort order.
     *
     * @param QueryInterface $query
     * @return void
     */
    protected function defaultSort($query)
    {
    }

    /**
     * @param QueryInterface $query
     * @param SortParameterInterface $param
     * @return array
     */
    protected function sortBy($query, SortParameterInterface $param)
    {
        $column = $this->getQualifiedSortColumn($query, $param->getField());

        return [$column => $param->isAscending() ? QueryInterface::ORDER_ASCENDING : QueryInterface::ORDER_DESCENDING];
    }

    /**
     * @param QueryInterface $query
     * @param string $field
     * @return string
     */
    protected function getQualifiedSortColumn($query, $field)
    {
        $key = $this->columnForField($field, $query->getType());

        return $key;
    }

    /**
     * @todo
     * Get the table column to use for the specified search field.
     *
     * @param string $field
     * @param string $entity
     * @return string
     */
    protected function columnForField($field, $entity)
    {
        /** If there is a custom mapping, return that */
        if (isset($this->sortColumns[$field])) {
            return $this->sortColumns[$field];
        }

//        if (\strpos($field, '.')) {
//            $relation = \strtok($field, '.');
//
//            if (\method_exists($entity, $relation)) {
//                /** @var Relations\Relation $relationShip */
//                $relationShip = $model->$relation();
//                $table = $relationShip->getRelated()->getTable();
//
//                if (($position = \strpos($field, '.')) !== false) {
//                    $tableWithField = $table . \substr($field, $position);
//                    return $model::$snakeAttributes ? Str::underscore($tableWithField) : Str::camelize($tableWithField);
//                }
//            }
//        }

        return Str::camelize($field);
    }
//
//    /**
//     * @todo
//     * @param string|null $modelKey
//     * @return BelongsTo
//     */
//    protected function belongsTo($modelKey = null)
//    {
//        return new BelongsTo($this->model, $modelKey ?: $this->guessRelation());
//    }
//
//    /**
//     * @todo
//     * @param string|null $modelKey
//     * @return HasOne
//     */
//    protected function hasOne($modelKey = null)
//    {
//        return new HasOne($this->model, $modelKey ?: $this->guessRelation());
//    }
//
//    /**
//     * @todo
//     * @param string|null $modelKey
//     * @return HasMany
//     */
//    protected function hasMany($modelKey = null)
//    {
//        return new HasMany($this->model, $modelKey ?: $this->guessRelation());
//    }
//
//    /**
//     * @todo
//     * @param string|null $modelKey
//     * @return HasManyThrough
//     */
//    protected function hasManyThrough($modelKey = null)
//    {
//        return new HasManyThrough($this->model, $modelKey ?: $this->guessRelation());
//    }
//
//    /**
//     * @todo
//     * @param HasManyAdapterInterface ...$adapters
//     * @return MorphHasMany
//     */
//    protected function morphMany(HasManyAdapterInterface ...$adapters)
//    {
//        return new MorphHasMany(...$adapters);
//    }
}
