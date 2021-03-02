<?php

namespace Flowpack\JsonApi\Error;

use Flowpack\JsonApi\Error\Traits\ExceptionHandlerTrait;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\ProductionExceptionHandler;

/**
 * Class JsonApiExceptionHandler
 * @package Flowpack\JsonApi\Error
 * @Flow\Scope("singleton")
 */
class JsonApiExceptionHandler extends ProductionExceptionHandler
{
    use ExceptionHandlerTrait;

    /**
     * @inheritDoc
     */
    protected function echoExceptionWeb($exception)
    {
        $objectManager = Bootstrap::$staticObjectManager;
        $configuration = $objectManager->get(ConfigurationManager::class);
        $baseUrl = $configuration->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flowpack.JsonApi.endpoints.api.baseUrl');
        $version = $configuration->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flowpack.JsonApi.endpoints.api.version');

        if (substr($_SERVER['REQUEST_URI'], 0, strlen(sprintf('/%s/%s', $baseUrl, $version))) === sprintf('/%s/%s', $baseUrl, $version)) {
            $this->handleJsonApiException($exception);
            return;
        }

        parent::echoExceptionWeb($exception);
    }
}
