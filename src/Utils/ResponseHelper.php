<?php

declare(strict_types=1);

namespace App\Utils;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use function hash;
use function json_encode;

final class ResponseHelper
{
    public static function success(Response $response, string $msg = ''): ResponseInterface
    {
        return $response->withJson([
            'ret' => 1,
            'msg' => $msg,
        ]);
    }

    public static function successWithData(Response $response, string $msg = '', array $data = []): ResponseInterface
    {
        return $response->withJson([
            'ret' => 1,
            'msg' => $msg,
            'data' => $data,
        ]);
    }

    /**
     * Build a JSON response with ETag header.
     *
     * **Note**: `RequestInterface` or `ResponseInterface` shouldn't be modified before/after calling this function.
     *
     * @param mixed $data
     */
    public static function successWithDataEtag(
        RequestInterface $request,
        ResponseInterface $response,
        array $data
    ): ResponseInterface {
        $etag = 'W/"' . hash('xxh64', (string) json_encode($data)) . '"';

        if ($etag === $request->getHeaderLine('If-None-Match')) {
            return $response->withStatus(304);
        }

        return $response->withHeader('ETag', $etag)->withJson([
            'ret' => 1,
            'data' => $data,
        ]);
    }

    public static function error(Response $response, string $msg = '', int $status = 200): ResponseInterface
    {
        if ($status < 400 && $status !== 200) {
            $status = 200;
        }

        return $response->withStatus($status)->withJson([
            'ret' => 0,
            'msg' => $msg,
        ]);
    }

    public static function errorWithData(Response $response, string $msg = '', array $data = [], int $status = 200): ResponseInterface
    {
        if ($status < 400 && $status !== 200) {
            $status = 200;
        }

        return $response->withStatus($status)->withJson([
            'ret' => 0,
            'msg' => $msg,
            'data' => $data,
        ]);
    }

    /**
     * Build a standard JSON response with {code, msg, data} format.
     *
     * @param Response $response
     * @param array $data Response data containing 'code', 'msg', and 'data' keys
     * @param int $status HTTP status code (optional, defaults to 200)
     * @return ResponseInterface
     */
    public static function json(Response $response, array $data, int $status = 200): ResponseInterface
    {
        return $response->withStatus($status)->withJson($data);
    }

    /**
     * Build a successful response with standard format.
     *
     * @param Response $response
     * @param string $msg Success message
     * @param mixed $data Response data (optional, defaults to null)
     * @param int $code Response code (optional, defaults to 200)
     * @return ResponseInterface
     */
    public static function apiSuccess(Response $response, string $msg = 'success', $data = null, int $code = 200): ResponseInterface
    {
        return $response->withJson([
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ]);
    }

    /**
     * Build an error response with standard format.
     *
     * @param Response $response
     * @param string $msg Error message
     * @param int $code Response code (optional, defaults to 400)
     * @param mixed $data Response data (optional, defaults to null)
     * @return ResponseInterface
     */
    public static function apiError(Response $response, string $msg, int $code = 400, $data = null): ResponseInterface
    {
        return $response->withJson([
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ]);
    }
}
