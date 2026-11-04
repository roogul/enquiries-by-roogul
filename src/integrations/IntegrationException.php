<?php

namespace roogul\enquiries\integrations;

use yii\base\Exception;

/**
 * Thrown when an integration fails. `$retryable` marks failures worth trying
 * again later (timeouts, rate limits, 5xx responses). Anything else, such as a
 * bad API key or a rejected payload, fails immediately.
 */
class IntegrationException extends Exception
{
    public function __construct(string $message, public bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getName(): string
    {
        return 'Integration error';
    }
}
