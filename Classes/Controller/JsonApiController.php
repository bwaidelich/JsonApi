<?php

namespace Flowpack\JsonApi\Controller;

use Flowpack\JsonApi\Adapter\DefaultAdapter;
use Flowpack\JsonApi\Contract\Object\ResourceObjectInterface;
use Flowpack\JsonApi\Exception;
use GuzzleHttp\Psr7\Uri;
use Neomerx\JsonApi\Schema\BaseSchema;
use Neomerx\JsonApi\Schema\Link;
use Neos\Flow\Annotations as Flow;
use Flowpack\JsonApi\Adapter\AbstractAdapter;
use Flowpack\JsonApi\Exception\ConfigurationException;
use Flowpack\JsonApi\Exception\RuntimeException;
use Flowpack\JsonApi\Mvc\Controller\EncodingParametersParser;
use Neomerx\JsonApi\Contracts\Encoder\EncoderInterface;
use Neomerx\JsonApi\Contracts\Factories\FactoryInterface;
use Flowpack\JsonApi\Mvc\ValidatedRequest;
use Flowpack\JsonApi\View\JsonApiView;
use Neos\Flow\Http\Component\SetHeaderComponent;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Controller\Argument;
use Neos\Flow\Mvc\Exception\InvalidArgumentTypeException;
use Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException;
use Neos\Flow\Mvc\RequestInterface;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Utility\Arrays;
use Neos\Utility\TypeHandling;

/**
 * Class JsonApiController
 * @package Flowpack\JsonApi\Controller
 * @Flow\Scope("singleton")
 */
class JsonApiController extends ActionController
{
    /**
     * @var string
     */
    protected $defaultViewObjectName = 'Flowpack\JsonApi\View\JsonApiView';

    /**
     * @var array
     */
    protected $supportedMediaTypes = array('application/vnd.api+json');

    /**
     * @var array
     * @Flow\InjectConfiguration(package="Flowpack.JsonApi", path="response.headers")
     */
    protected $responseHeaders;

    /**
     * @var array
     */
    protected $endpoint;

    /**
     * @var array
     */
    protected $resourceConfiguration;

    /**
     * Allowed methods default deny all
     * @var array
     */
    protected $allowedMethods = [];

    /**
     * @var JsonApiView
     */
    protected $view;

    /**
     * @var AbstractAdapter
     */
    protected $adapter;

    /**
     * @var EncoderInterface
     */
    protected $encoder;

    /**
     * @var object
     */
    protected $record;

    /**
     * @var FactoryInterface
     * @Flow\Inject(lazy=false)
     */
    protected $factory;

    /**
     * @var ValidatedRequest
     */
    protected $validatedRequest;

    /**
     * @var EncodingParametersParser
     */
    protected $encodedParameters;

    /**
     * Initialize Action
     */
    protected function initializeAction(): void
    {
        parent::initializeAction();
        $this->response->setContentType('application/vnd.api+json');
        foreach ($this->responseHeaders as $headerName => $headerValue) {
            $this->response->setComponentParameter(SetHeaderComponent::class, $headerName, $headerValue);
        }
    }

    /**
     * Initializes the controller
     *
     * This method should be called by the concrete processRequest() method.
     *
     * @param ActionRequest $request
     * @param ActionResponse $response
     * @throws UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Exception\NoSuchArgumentException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws RuntimeException
     * @throws ConfigurationException
     */
    protected function initializeController(ActionRequest $request, ActionResponse $response): void
    {
        parent::initializeController($request, $response);
        /** @var ActionRequest $request */
        if ($request->hasArgument('@endpoint' === false)) {
            throw new ConfigurationException('Endpoint should be set');
        }

        $this->endpoint = $request->getArgument('@endpoint');
        $availableResources = $this->endpoint['resources'];

        $resource = $request->getArgument('resource');
        if (!\array_key_exists($resource, $availableResources)) {
            $this->throwStatus(404);
        }

        $this->resourceConfiguration = $availableResources[$resource];

        if (isset($this->resourceConfiguration['allowedMethods'])) {
            $this->allowedMethods = $this->resourceConfiguration['allowedMethods'];
        }

        $this->validatedRequest = new ValidatedRequest($request);
        $this->encodedParameters = new EncodingParametersParser($request->getArguments());
        $this->registerAdapter($this->resourceConfiguration, $resource);

        $urlPrefix = $this->getUrlPrefix($request);
        $this->adapter->setEncoder($urlPrefix, $this->encodedParameters);
        $this->encoder = $this->adapter->getEncoder();
    }

    /**
     * Determines the action method and assures that the method exists.
     * @return string
     * @throws UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Exception\NoSuchArgumentException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    protected function resolveActionMethodName(): string
    {
        if ($this->validatedRequest->isOptions()) {
            return 'optionsAction';
        } elseif ($this->validatedRequest->isIndex()) {
            $this->assertAllowedMethod('list');
            return 'listAction';
        } elseif ($this->validatedRequest->isCreateResource() && in_array($this->validatedRequest->getResourceType(), [
                'nodes',
                'workspaces'
            ])) {
            $this->assertAllowedMethod('create');
            return 'createEventSourcingObjectAction';
        } elseif ($this->validatedRequest->isCreateResource()) {
            $this->assertAllowedMethod('create');
            return 'createAction';
        }

        $this->record = $this->adapter->find($this->request->getArgument('identifier'));
        if (!$this->record) {
            $this->throwStatus(404);
        }

        if ($this->validatedRequest->isReadResource()) {
            $this->assertAllowedMethod('read');
            return 'readAction';
        } elseif ($this->validatedRequest->isUpdateResource() && in_Array($this->validatedRequest->getResourceType(), [
                'nodes',
                'workspaces'
            ])) {
            $this->assertAllowedMethod('update');
            return 'updateEventSourcingObjectAction';
        } elseif ($this->validatedRequest->isUpdateResource()) {
            $this->assertAllowedMethod('update');
            return 'updateAction';
        } elseif ($this->validatedRequest->isDeleteResource()) {
            $this->assertAllowedMethod('delete');
            return 'deleteAction';
        }

        /** Relationships */
        if ($this->validatedRequest->isReadRelatedResource() || $this->validatedRequest->isReadRelationship()) {
            return 'relatedAction';
        } else {
//            $this->validatedRequest->modifyRelationship($record, $field, $request);
            return 'updateRelationshipAction';
        }
    }

    /**
     * Implementation of the arguments initialization in the action controller:
     * Automatically registers arguments of the current action
     *
     * Overwrite default behaviour
     *
     * @return void
     * @throws InvalidArgumentTypeException
     * @see initializeArguments()
     */
    protected function initializeActionMethodArguments(): void
    {
        $actionMethodParameters = static::getActionMethodParameters($this->objectManager);
        if (isset($actionMethodParameters[$this->actionMethodName])) {
            $methodParameters = $actionMethodParameters[$this->actionMethodName];
        } else {
            $methodParameters = [];
        }

        $this->arguments->removeAll();
        foreach ($methodParameters as $parameterName => $parameterInfo) {
            $dataType = null;
            if (isset($parameterInfo['type'])) {
                $dataType = $parameterInfo['type'];
            } elseif ($parameterInfo['array']) {
                $dataType = 'array';
            }
            if ($dataType === null) {
                throw new InvalidArgumentTypeException('The argument type for parameter $' . $parameterName . ' of method ' . \get_class($this) . '->' . $this->actionMethodName . '() could not be detected.', 1253175643);
            }
            $defaultValue = (isset($parameterInfo['defaultValue']) ? $parameterInfo['defaultValue'] : null);
            if ($parameterInfo['optional'] === true && $defaultValue === null) {
                $dataType = TypeHandling::stripNullableType($dataType);
            }

            // Custom behaviour to get passed validation
            if ($parameterName === 'resource') {
                $dataType = $this->adapter->getModel();
            }

            $this->arguments->addNewArgument($parameterName, $dataType, ($parameterInfo['optional'] === false), $defaultValue);
        }
    }

    /**
     * Overwrite default behaviour
     * @throws RuntimeException
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Exception\RequiredArgumentMissingException
     */
    protected function mapRequestArgumentsToControllerArguments(): void
    {
        if (!\in_array($this->request->getHttpRequest()->getMethod(), [
            'POST',
            'PUT',
            'PATCH'
        ])) {
            parent::mapRequestArgumentsToControllerArguments();
            return;
        }

        if (!\in_array($this->validatedRequest->getDocument()->getResource()->getType(), [
                'node',
                'workspaces'
            ]) && \in_array($this->request->getHttpRequest()->getMethod(), [
                'POST',
                'PUT',
                'PATCH'
            ])) {
            /** @var ResourceObjectInterface $resource */
            $resource = $this->validatedRequest->getDocument()->getResource();
            /** @var \Neos\Flow\Mvc\Controller\MvcPropertyMappingConfiguration $propertyMappingConfiguration */
            $propertyMappingConfiguration = $this->arguments['resource']->getPropertyMappingConfiguration();
            $this->adapter->setPropertyMappingConfiguration($propertyMappingConfiguration, $resource);

            /** @var Argument $argument */
            foreach ($this->arguments as $argument) {
                $argumentName = $argument->getName();
                if ($this->request->hasArgument($argumentName)) {
                    if ($resource->hasId()) {
                        $arguments = $this->adapter->hydrateAttributes($resource, $resource->getAttributes(), $resource->getId());
                    } else {
                        $arguments = $this->adapter->hydrateAttributes($resource, $resource->getAttributes());
                    }
                    $relationshipArguments = $this->adapter->hydrateRelations($resource, $resource->getRelationships());
                    $arguments = \array_merge($arguments, $relationshipArguments);

                    try {
                        $argument->setValue($arguments);
                    } catch (\Exception $e) {
//                     todo: handle validation error
                        throw $e;
                    }
                } elseif ($argument->isRequired()) {
                    throw new \Neos\Flow\Mvc\Exception\RequiredArgumentMissingException('Required argument "' . $argumentName . '" is not set.', 1298012500);
                }
            }
        }
    }

    /**
     * @param ViewInterface $view
     * @throws \Neos\Flow\Mvc\Exception\NoSuchArgumentException
     * @throws \Flowpack\JsonApi\Exception\ConfigurationException
     */
    protected function initializeView(ViewInterface $view): void
    {
        /** @var JsonApiView $view */
        parent::initializeView($view);
        $view->setResource($this->request->getArgument('resource'));
        $view->setEncoder($this->adapter->getEncoder());
        $view->setParameters($this->encodedParameters);
    }

    /**
     * @return void
     * @throws \Neos\Flow\Exception
     */
    public function listAction(): void
    {
        $isSubUrl = true;
        $hasMeta = false;

        $count = $this->adapter->count($this->encodedParameters);
        $arguments = $this->request->getHttpRequest()->getQueryParams();
        $pagination = $this->encodedParameters->getPagination();
        $data = $this->adapter->query($this->encodedParameters, $pagination);

        if ($arguments !== []) {
            $query = \http_build_query($arguments);
            $self = new Link($isSubUrl, \sprintf('/%s?%s', $this->adapter->getResource(), $query), $hasMeta);
        } else {
            $self = new Link($isSubUrl, \sprintf('/%s', $this->adapter->getResource()), $hasMeta);
        }

        $meta = [
            'total' => $count,
        ];

        $links = [
            Link::SELF => $self
        ];

        if ($count > $pagination->getLimit()) {
            $prev = $pagination->prev();
            if ($prev !== null) {
                $query = \http_build_query(Arrays::arrayMergeRecursiveOverrule($arguments, $prev));
                $links[Link::PREV] = new Link($isSubUrl, \sprintf('/%s?%s', $this->adapter->getResource(), $query), $hasMeta);
            }

            $next = $pagination->next($count);
            if ($next !== null) {
                $query = \http_build_query(Arrays::arrayMergeRecursiveOverrule($arguments, $next));
                $links[Link::NEXT] = new Link($isSubUrl, \sprintf('/%s?%s', $this->adapter->getResource(), $query), $hasMeta);
            }

            $first = $pagination->first();
            if ($first !== null) {
                $query = \http_build_query(Arrays::arrayMergeRecursiveOverrule($arguments, $first));
                $links[Link::FIRST] = new Link($isSubUrl, \sprintf('/%s?%s', $this->adapter->getResource(), $query), $hasMeta);
            }

            $last = $pagination->last($count);
            if ($last !== null) {
                $query = \http_build_query(Arrays::arrayMergeRecursiveOverrule($arguments, $last));
                $links[Link::LAST] = new Link($isSubUrl, \sprintf('/%s?%s', $this->adapter->getResource(), $query), $hasMeta);
            }

            $meta['size'] = count($data);
            $meta['offset'] = $pagination->getOffset();
            $meta['limit'] = $pagination->getLimit();
            $meta['current'] = $pagination->current();
        }
        $this->encoder->withLinks($links)->withMeta($meta);
        $this->view->setData($data);
    }

    /**
     * @param $resource
     * @throws RuntimeException
     * @throws \Neos\Flow\Http\Exception
     */
    public function createAction($resource): void
    {
        try {
            $data = $this->adapter->create($resource, $this->validatedRequest->getDocument()->getResource(), $this->encodedParameters);
        } catch (Exception\InvalidJsonException $e) {
            $this->response->setStatusCode(406);
            return;
        }
        $this->response->setStatusCode(201);
        $this->view->setData($data);
    }

    /**
     * @param $resource
     * @throws RuntimeException
     * @throws \Neos\Flow\Http\Exception
     * @Flow\IgnoreValidation ("$resource")
     */
    public function createEventSourcingObjectAction($resource): void
    {
        self::createAction($resource);
    }

    /**
     * @param string $identifier
     * @return void
     */
    public function readAction($identifier): void
    {
        $data = $this->adapter->read($identifier, $this->encodedParameters);

        $this->view->setData($data);
    }

    /**
     * @param $resource
     * @throws RuntimeException
     * @throws \Neos\Flow\Http\Exception
     */
    public function updateAction($resource): void
    {
        try {
            $data = $this->adapter->update($resource, $this->validatedRequest->getDocument()->getResource(), $this->encodedParameters);
        } catch (Exception\InvalidJsonException $e) {
            $this->response->setStatusCode(406);
            return;
        }
        $this->persistenceManager->persistAll();
        $this->response->setStatusCode(200);
        $this->view->setData($data);
    }

    /**
     * @param $resource
     * @throws RuntimeException
     * @throws \Neos\Flow\Http\Exception
     * @Flow\IgnoreValidation ("$resource")
     */
    public function updateEventSourcingObjectAction($resource): void
    {
        self::updateAction($resource);
    }

    /**
     * @return string
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    public function deleteAction(): string
    {
        $this->adapter->delete($this->record, $this->encodedParameters);
        $this->response->setStatusCode(204);
        return '';
    }

    /**
     * @param string $relationship
     * @throws RuntimeException
     * @throws UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    public function relatedAction(string $relationship): void
    {
        /** @var BaseSchema $schema */
        $schema = $this->getSchema($this->adapter->getResource());
        $relationships = $schema->getRelationships($this->record);
        if (!isset($relationships[$relationship])) {
            $this->throwStatus(404, \sprintf('Relationship "%s" not found', $relationship));
        }
        $this->view->setData($relationships[$relationship][BaseSchema::RELATIONSHIP_DATA]);
    }

    /**
     * To be implemented
     * @param string $relationship
     */
    public function updateRelationshipAction(string $relationship): string
    {
    }

    /**
     * @return string
     */
    public function optionsAction(): string
    {
        $allowed = $this->resourceConfiguration['allowedMethods'];

        $allowedMethods = array(
            'GET',
            'POST',
            'PATCH',
            'DELETE'
        );

        if (!\in_array('list', $allowed) && !\in_array('read', $allowed)) {
            unset($allowedMethods[0]);
        }

        if (!\in_array('create', $allowed)) {
            unset($allowedMethods[1]);
        }

        if (!\in_array('update', $allowed)) {
            unset($allowedMethods[2]);
        }

        if (!\in_array('delete', $allowed)) {
            unset($allowedMethods[3]);
        }

        $this->response->setComponentParameter(SetHeaderComponent::class, 'Access-Control-Allow-Methods', \implode(', ', \array_unique($allowedMethods)));
        $this->response->setComponentParameter(SetHeaderComponent::class, 'Access-Control-Max-Age', '3600');
        $this->response->setComponentParameter(SetHeaderComponent::class, 'Access-Control-Allow-Headers', 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
        $this->response->setStatusCode(204);
        return '';
    }

    /**
     * @return string
     * @throws \Neos\Flow\Mvc\Exception\ForwardException
     * @throws \Neos\Flow\Property\Exception\TargetNotFoundException
     * @todo resolve errors with Document error
     */
    public function errorAction()
    {
        $this->response->setStatusCode(422);
        $this->handleTargetNotFoundError();
        $this->response->setContent(\json_encode((object)$this->getFlattenedValidationErrorMessage()));
    }

    /**
     * Returns a json object containing all validation errors.
     *
     * @return array
     */
    protected function getFlattenedValidationErrorMessage(): array
    {
//        $errorCollection = new ErrorCollection();
        $errorCollection = [];
        foreach ($this->arguments->getValidationResults()->getFlattenedErrors() as $propertyPath => $errors) {
            foreach ($errors as $key => $error) {
                $properties = \explode('.', $propertyPath);
//                $errorObject = new Error($key, null, 422, null, $error->render(), $error->render(), $properties);

                $errorObject = [];
                $errorObject['status'] = '422';
                $errorObject['detail'] = $error->render();
                $errorObject['source']['pointer'] = '/data/attributes/' . \array_pop($properties);
                $errorCollection['errors'][] = $errorObject;

                // Should assign to document
//                $errorCollection->add($errorObject);
            }
        }
        return $errorCollection;
    }

    /**
     * @param string $endpoint
     * @param string $resource
     * @return void
     * @throws RuntimeException
     */
    protected function registerAdapter($configuration, $resource): void
    {
        if (isset($configuration['adapter'])) {
            $adapterClass = $configuration['adapter'];
            if ($this->objectManager->isRegistered($adapterClass)) {
                $this->adapter = new $adapterClass($configuration, $resource, $this->encodedParameters);
                return;
            }

            throw new RuntimeException(\sprintf('Adapter %s is not registered', $adapterClass));
        }

        $this->adapter = new DefaultAdapter($configuration, $resource, $this->encodedParameters);
    }

    /**
     * @param string $resource
     * @param string $relation
     * @return BaseSchema
     * @throws RuntimeException
     */
    protected function getSchema(string $resource, string $relation = ''): BaseSchema
    {
        if (isset($this->resourceConfiguration['related'])) {
            if ($relation !== '') {
                if (isset($this->resourceConfiguration['related'][$relation])) {
                    $schemaClass = \key($this->resourceConfiguration['related'][$relation]);
                    if ($this->objectManager->isRegistered($schemaClass)) {
                        return new $schemaClass();
                    }

                    throw new RuntimeException(\sprintf('Schema %s is not registered', $schemaClass));
                }

                throw new RuntimeException(\sprintf('Missing related definition for %s in `endpoints.resources.%s.related.%s` not registered!', $resource, $resource, $relation));
            }

            $schemaClass = $this->resourceConfiguration['schema'];
            if ($this->objectManager->isRegistered($schemaClass)) {
                return new $schemaClass();
            }

            throw new RuntimeException(\sprintf('Schema %s is not registered', $schemaClass));
        }

        throw new RuntimeException(\sprintf('Missing related definition for %s in `endpoints.resources.%s.related` not registered!', $resource, $resource));
    }

    /**
     * @param RequestInterface $request
     * @return string
     */
    protected function getUrlPrefix(RequestInterface $request): string
    {
        /** @var Uri $uri */
        $uri = $request->getMainRequest()->getHttpRequest()->getUri();

        $host = $uri->getScheme() . '://' . $uri->getHost();
        if (($port = $uri->getPort()) !== null) {
            $host .= ':' . $port . '/';
        }

        $suffix = isset($this->endpoint['baseUrl']) && isset($this->endpoint['version']) ? $this->endpoint['baseUrl'] . '/' . $this->endpoint['version'] : '/';

        return $host . $suffix;
    }

    /**
     * @param string $expected
     * @throws UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    protected function assertAllowedMethod(string $expected): void
    {
        if (!\in_array($expected, $this->allowedMethods)) {
            // throw new JsonApiException([], JsonApiException::HTTP_CODE_FORBIDDEN);
            $this->throwStatus(403);
        }
    }
}
