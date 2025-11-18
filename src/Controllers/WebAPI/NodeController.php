<?php

declare(strict_types=1);

namespace App\Controllers\WebAPI;

use App\Controllers\BaseController;
use App\Models\Node;
use App\Utils\ResponseHelper;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_decode;
use const JSON_UNESCAPED_SLASHES;

final class NodeController extends BaseController
{
    /**
     * GET /v1/server/config (deprecated)
     * GET /v2/server/{server_id}
     * Query params: secret_key
     * Path params: server_id (v2 only)
     */
    public function getInfo(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 从路径参数获取 server_id (v2)，如果不存在则从 query 参数获取 (v1 兼容)
        $node_id = $args['server_id'] ?? $request->getQueryParam('server_id');
        $node = (new Node())->find($node_id);

        // 节点不存在
        if ($node === null) {
            return ResponseHelper::json($response, [
                'code' => 404,
                'msg' => 'Node not found.',
                'data' => null
            ]);
        }

        // 节点未启用
        if ($node->type === 0) {
            return ResponseHelper::json($response, [
                'code' => 403,
                'msg' => 'Node is not enabled.',
                'data' => null
            ]);
        }

        $protocols = json_decode($node->custom_config, true, JSON_UNESCAPED_SLASHES) ?? [];

        // 构建新的响应数据
        $data = [
            'traffic_report_threshold' => 0,
            'ip_strategy' => 'prefer_ipv4',
            'dns' => null,
            'block' => null,
            'outbound' => null,
            'protocols' => $protocols,
            'total' => 1
        ];

        return ResponseHelper::json($response, [
            'code' => 200,
            'msg' => 'success',
            'data' => $data
        ]);
    }
}
