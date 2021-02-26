<?php

namespace Flowpack\JsonApi\Exception;

use Neos\Flow\Annotations as Flow;

/**
 * Class UnprocessableEntityException
 * @package Flowpack\JsonApi\Exception
 * @Flow\Scope("singleton")
 * @api
 */
class UnprocessableEntityException extends \Flowpack\JsonApi\Exception
{
    /**
     * @var integer
     */
    protected $statusCode = 422;
}
