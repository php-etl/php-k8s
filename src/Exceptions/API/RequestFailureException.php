<?php

declare(strict_types=1);

namespace RenokiCo\PhpK8s\Exceptions\API;

use RenokiCo\PhpK8s\Exceptions\KubernetesAPIException;

final class RequestFailureException extends \RuntimeException implements KubernetesAPIException
{
}
