<?php

namespace Flowpack\JsonApi\Error\Traits;

use Flowpack\JsonApi\Exception;
use Flowpack\JsonApi\Exception\InvalidJsonException;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\WithHttpStatusInterface;
use Neos\Flow\Http\Helper\ResponseInformationHelper;

trait ExceptionHandlerTrait
{
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
        } elseif ($exception instanceof \InvalidArgumentException) {
            $statusCode = '422';
            preg_match_all('/"(.*?)"/', $exception->getMessage(), $matches);
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = 'Malformed object';
            $errorObject['detail'] = 'An invalid attribute has been passed.';
        } else {
            $statusCode = ($exception instanceof WithHttpStatusInterface) ? $exception->getStatusCode() : 500;
            $errorObject['status'] = $statusCode;
            $errorObject['title'] = sprintf('Unhandled exception of `%s`.', get_class($exception));
            $errorObject['detail'] = $exception->getMessage();
        }

        $statusMessage = ResponseInformationHelper::getStatusMessageByCode($statusCode);

        if (!headers_sent()) {
            header(sprintf('HTTP/1.1 %s %s', $statusCode, $statusMessage));

            $objectManager = Bootstrap::$staticObjectManager;
            $configuration = $objectManager->get(ConfigurationManager::class);
            $headers = $configuration->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flowpack.JsonApi.response.headers');

            foreach ($headers as $header => $value) {
                header(sprintf('%s: %s', $header, $value));
            }
        }

        echo json_encode(['errors' => [$errorObject]]);
    }
}
