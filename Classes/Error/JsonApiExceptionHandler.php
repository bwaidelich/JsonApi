<?php

namespace Flowpack\JsonApi\Error;

use Flowpack\JsonApi\Exception;
use Flowpack\JsonApi\Exception\InvalidJsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\ProductionExceptionHandler;
use Neos\Flow\Error\WithHttpStatusInterface;
use Neos\Flow\Http\Helper\ResponseInformationHelper;

/**
 * Class JsonApiExceptionHandler
 * @package Flowpack\JsonApi\Error
 * @Flow\Scope("singleton")
 */
class JsonApiExceptionHandler extends ProductionExceptionHandler
{

    /**
     * @inheritDoc
     */
    protected function echoExceptionWeb($exception)
    {
        $objectManager = Bootstrap::$staticObjectManager;
        $configuration = $objectManager->get(ConfigurationManager::class);
        $baseUrl = $configuration->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flowpack.JsonApi.endpoints.api.baseUrl');
        $version = $configuration->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flowpack.JsonApi.endpoints.api.version');

        if (substr($_SERVER['REQUEST_URI'], 0, 7) === sprintf('/%s/%s', $baseUrl, $version)) {
            $this->handleJsonApiException($exception);
            return;
        }

        parent::echoExceptionWeb($exception);
    }

    /**
     * @param $exception
     */
    public function handleJsonApiException($exception): void
    {
        $errorObject = [];
        if (get_class($exception) === InvalidJsonException::class) {
            $statusCode = '400';
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = $exception->getMessage();
            $errorObject['detail'] = $exception->getJsonErrorMessage();
        } elseif ($exception instanceof Exception) {
            $statusCode = $exception->getStatusCode();
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = 'Unprocessable entity';
            $errorObject['detail'] = $exception->getMessage();
        } elseif (get_class($exception) === \Neos\Flow\Property\Exception::class) {
            $statusCode = '422';
            /** @var \Neos\Flow\Property\Exception $exception */
            preg_match_all('/"(.*?)"/', $exception->getMessage(), $matches);
            $object = explode('\\', $matches[1][0])[array_key_last(explode('\\', $matches[1][0]))];
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = sprintf('Malformed object `%s`', $object);
            $errorObject['detail'] = sprintf('Property `%s` is not a valid attribute.', $matches[1][1]);
        } else {
            $statusCode = ($exception instanceof WithHttpStatusInterface) ? $exception->getStatusCode() : 500;
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = sprintf('Unhandled exception of `%s`.', get_class($exception));
            $errorObject['detail'] = $exception->getMessage();
        }

        $statusMessage = ResponseInformationHelper::getStatusMessageByCode($statusCode);

        if (!headers_sent()) {
            header(sprintf('HTTP/1.1 %s %s', $statusCode, $statusMessage));
            header('Content-Type: application/vnd.api+json');
            header('Access-Control-Allow-Origin: *');
        }

        echo json_encode(['errors' => [$errorObject]]);
    }
}
