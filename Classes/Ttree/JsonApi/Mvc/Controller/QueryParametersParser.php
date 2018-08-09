<?php
namespace Ttree\JsonApi\Mvc\Controller;

use Neos\Flow\Mvc\RequestInterface;
use \Psr\Log\LoggerAwareTrait;
use \Psr\Log\LoggerAwareInterface;
use \Neomerx\JsonApi\Exceptions\JsonApiException as E;
use \Neomerx\JsonApi\Contracts\Http\HttpFactoryInterface;
use \Neomerx\JsonApi\Contracts\Encoder\Parameters\SortParameterInterface;
use \Neomerx\JsonApi\Contracts\Http\Query\QueryParametersParserInterface;

/**
 * Class QueryParametersParser
 * @package Ttree\JsonApi\Mvc\Controller
 */
class QueryParametersParser implements LoggerAwareInterface
{
    /** Parameter name */
    const PARAM_INCLUDE = 'include';

    /** Parameter name */
    const PARAM_FIELDS = 'fields';

    /** Parameter name */
    const PARAM_PAGE = 'page';

    /** Parameter name */
    const PARAM_FILTER = 'filter';

    /** Parameter name */
    const PARAM_SORT = 'sort';

    use LoggerAwareTrait;

    /**
     * @var HttpFactoryInterface
     */
    private $factory;

    /**
     * @param HttpFactoryInterface $factory
     */
    public function __construct(HttpFactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @inheritdoc
     */
    public function parse(RequestInterface $request)
    {
        $parameters = $request->getArguments();
        unset($parameters['resource'], $parameters['identifier'], $parameters['relationship']);

        return $this->factory->createQueryParameters(
            $this->getIncludePaths($parameters),
            $this->getFieldSets($parameters),
            $this->getSortParameters($parameters),
            $this->getPagingParameters($parameters),
            $this->getFilteringParameters($parameters),
            $this->getUnrecognizedParameters($parameters)
        );
    }

    /**
     * @param array $parameters
     *
     * @return array|null
     */
    private function getIncludePaths(array $parameters)
    {
        $paths  = $this->getStringParamOrNull($parameters, self::PARAM_INCLUDE);
        $result = empty($paths) === false ? explode(',', rtrim($paths, ',')) : null;

        return $result;
    }

    /**
     * @param array $parameters
     *
     * @return array|null
     */
    private function getFieldSets(array $parameters)
    {
        $result    = [];
        $fieldSets = $this->getParamOrNull($parameters, self::PARAM_FIELDS);
        if (empty($fieldSets) === false && is_array($fieldSets)) {
            foreach ($fieldSets as $type => $fields) {
                // We expect fields to be comma separated or empty strings. Multi-dimension arrays are not allowed.
                is_string($fields) ?: E::throwException(new E([], E::HTTP_CODE_BAD_REQUEST));
                $result[$type] = (empty($fields) === true ? [] : explode(',', $fields));
            }
        } else {
            $result = null;
        }

        return $result;
    }

    /**
     * @param array $parameters
     *
     * @return SortParameterInterface[]|null
     */
    protected function getSortParameters(array $parameters)
    {
        $sortParams = null;
        $sortParam  = $this->getStringParamOrNull($parameters, self::PARAM_SORT);
        if ($sortParam !== null) {
            foreach (explode(',', $sortParam) as $param) {
                $isDesc = false;
                empty($param) === false ?
                    $isDesc = ($param[0] === '-') : E::throwException(new E([], E::HTTP_CODE_BAD_REQUEST));
                $sortField = ltrim($param, '+-');
                empty($sortField) === false ?: E::throwException(new E([], E::HTTP_CODE_BAD_REQUEST));
                $sortParams[] = $this->factory->createSortParam($sortField, $isDesc === false);
            }
        }
        return $sortParams;
    }

    /**
     * @param array $parameters
     *
     * @return array|null
     */
    private function getPagingParameters(array $parameters)
    {
        return $this->getArrayParamOrNull($parameters, self::PARAM_PAGE);
    }

    /**
     * @param array $parameters
     *
     * @return array|null
     */
    private function getFilteringParameters(array $parameters)
    {
        return $this->getArrayParamOrNull($parameters, self::PARAM_FILTER);
    }

    /**
     * @param array $parameters
     *
     * @return array|null
     */
    private function getUnrecognizedParameters(array $parameters)
    {
        $supported = [
            self::PARAM_INCLUDE => 0,
            self::PARAM_FIELDS  => 0,
            self::PARAM_PAGE    => 0,
            self::PARAM_FILTER  => 0,
            self::PARAM_SORT    => 0,
        ];
        $unrecognized = array_diff_key($parameters, $supported);
        return empty($unrecognized) === true ? null : $unrecognized;
    }

    /**
     * @param array $parameters
     * @param string $name
     *
     * @return array|null
     */
    private function getArrayParamOrNull(array $parameters, $name)
    {
        $value = $this->getParamOrNull($parameters, $name);

        $isArrayOrNull = ($value === null || is_array($value) === true);
        $isArrayOrNull === true ?: E::throwException(new E([], E::HTTP_CODE_BAD_REQUEST));

        return $value;
    }

    /**
     * @param array $parameters
     * @param string $name
     *
     * @return string|null
     */
    private function getStringParamOrNull(array $parameters, $name)
    {
        $value = $this->getParamOrNull($parameters, $name);

        $isStringOrNull = ($value === null || is_string($value) === true);
        $isStringOrNull === true ?: E::throwException(new E([], E::HTTP_CODE_BAD_REQUEST));

        return $value;
    }

    /**
     * @param array  $parameters
     * @param string $name
     *
     * @return mixed
     */
    private function getParamOrNull(array $parameters, $name)
    {
        return isset($parameters[$name]) === true ? $parameters[$name] : null;
    }
}