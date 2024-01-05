<?php

namespace RenokiCo\PhpK8s\Traits\Cluster;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use RenokiCo\PhpK8s\Exceptions;
use RenokiCo\PhpK8s\ResourcesList;

trait MakesHttpCalls
{
    /**
     * Get the callable URL for a specific path.
     *
     * @param  string  $path
     * @param  array  $query
     * @return string
     */
    public function getCallableUrl(string $path, array $query = ['pretty' => 1])
    {
        /**
         * Replace any name[<number>]=value occurences with name=value
         * to support argv input.
         */
        $query = urldecode(preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', http_build_query($query)));

        return "{$this->url}{$path}?{$query}";
    }

    /**
     * Get the Guzzle Client to perform requests on.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        $options = [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            RequestOptions::VERIFY => true,
        ];

        if (is_bool($this->verify) || is_string($this->verify)) {
            $options[RequestOptions::VERIFY] = $this->verify;
        }

        if ($this->token) {
            $options[RequestOptions::HEADERS]['authorization'] = "Bearer {$this->token}";
        }

        if ($this->auth) {
            $options[RequestOptions::AUTH] = $this->auth;
        }

        if ($this->cert) {
            $options[RequestOptions::CERT] = $this->cert;
        }

        if ($this->sslKey) {
            $options[RequestOptions::SSL_KEY] = $this->sslKey;
        }

        return new Client($options);
    }

    /**
     * Make a HTTP call to a given path with a method and payload.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  string  $payload
     * @param  array  $query
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws \RenokiCo\PhpK8s\Exceptions\KubernetesAPIException
     */
    public function call(
        string $method,
        string $path,
        string $payload = '',
        array $query = ['pretty' => 1],
        array $options = []
    ): \Psr\Http\Message\ResponseInterface {
        if ($payload) {
            $options[RequestOptions::BODY] = $payload;
        }

        try {
            $response = $this->getClient()->request($method, $this->getCallableUrl($path, $query), $options);
        } catch (InvalidArgumentException $exception) {
            throw new Exceptions\API\InvalidArgumentException('You provided an invalid argument to the API client: '.$exception->getMessage(), previous: $exception);
        } catch (TooManyRedirectsException $exception) {
            throw new Exceptions\API\TooManyRedirectsException('The cluster is responding with too many redirect responses. Please check the networking configuration.', previous: $exception);
        } catch (ConnectException $exception) {
            throw new Exceptions\API\ClusterNotReachableException('The cluster could not be reached.', 0, $exception);
        } catch (ServerException $exception) {
            $errorPayload = \json_decode((string) $exception->getResponse()->getBody(), true);
            // @todo handle 5xx responses properly
            throw new Exceptions\API\UnexpectedResponseException(
                $exception->getMessage(),
                $exception->getResponse()->getStatusCode(),
                $errorPayload,
                $exception,
            );
        } catch (ClientException $exception) {
            $errorPayload = \json_decode((string) $exception->getResponse()->getBody(), true);

            switch ($exception->getCode()) {
                case 400:
                    throw new Exceptions\API\BadResponseException('The server did not accept your request. You may check your cluster\'s API version compatibility.', $errorPayload, $exception);
                case 401:
                    throw new Exceptions\API\NotAuthorizedException('You are not authorized to access this resource with the current context.', $errorPayload, $exception);
                case 403:
                    throw new Exceptions\API\NotAuthenticatedException('You are not authenticated to the cluster.', $errorPayload, $exception);
                case 404:
                    throw new Exceptions\API\ResourceNotFoundException('The resource you are trying to access does not exist.', $errorPayload, $exception);
                case 405:
                    throw new Exceptions\API\MethodNotAllowedException('The operation is not allowed on this resource.', $errorPayload, $exception);
                case 409:
                    throw new Exceptions\API\ConflictException('A resource with the same name or identifier already exists. You should prefer updating this resource.', $errorPayload, $exception);
                case 413:
                    throw new Exceptions\API\ContentTooLargeException('The request payload you sent is too large, the cluster API responded it could not process the request due to this reason.', $errorPayload, $exception);
                case 414:
                    throw new Exceptions\API\URITooLongException('The request URI you sent is too long, the cluster API responded it could not process the request due to this reason.', $errorPayload, $exception);
                case 429:
                    throw new Exceptions\API\TooManyRequestsException('You have sent too many requests to the cluster API. Please lower your API request rate.', $errorPayload, $exception);
                default:
                    throw new Exceptions\API\UnexpectedResponseException(
                        $exception->getMessage(),
                        $exception->getResponse()->getStatusCode(),
                        $errorPayload,
                        $exception,
                    );
            }
        } catch (BadResponseException $exception) {
            $errorPayload = \json_decode((string) $exception->getResponse()->getBody(), true);

            throw new Exceptions\API\UnexpectedResponseException(
                $exception->getMessage(),
                $exception->getResponse()->getStatusCode(),
                $errorPayload,
                $exception,
            );
        } catch (RequestException $exception) {
            throw new Exceptions\API\RequestFailureException('The request to the cluster API failed to be constructed properly. You should contact your system administrator to investigate the origin of the error.', previous: $exception);
        } catch (TransferException $exception) {
            throw new Exceptions\API\ServiceUnavailableException('An error occurred on the networking side, we could not send your request to the cluster API. You should contact your system administrator to investigate the origin of the error.');
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('An unexpected error happened in the Guzzle HTTP client implementation.', previous: $exception);
        }

        return $response;
    }

    /**
     * Call the API with the specified method and path.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  string  $payload
     * @param  array  $query
     * @param  array  $options
     * @return mixed
     *
     * @throws \RenokiCo\PhpK8s\Exceptions\KubernetesAPIException
     */
    protected function makeRequest(
        string $method,
        string $path,
        string $payload = '',
        array $query = ['pretty' => 1],
        array $options = []
    ): mixed {
        $resourceClass = $this->resourceClass;

        $response = $this->call($method, $path, $payload, $query, $options);

        $json = @json_decode($response->getBody(), true);

        // If the output is not JSONable, return the response itself.
        // This can be encountered in case of a pod log request, for example,
        // where the data returned are just console logs.

        if (! $json) {
            return (string) $response->getBody();
        }

        // If the kind is a list, transform into a ResourcesList
        // collection of instances for the same class.

        if (isset($json['items'])) {
            $results = [];

            foreach ($json['items'] as $item) {
                $results[] = (new $resourceClass($this, $item))->synced();
            }

            return new ResourcesList($results);
        }

        // If the items does not exist, it means the Kind
        // is the same as the current class, so pass it
        // for the payload.

        return (new $resourceClass($this, $json))->synced();
    }
}
