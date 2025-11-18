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
     * GET /v1/server/config
     * Query params: protocol, server_id, secret_key
     */
    public function getInfo(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 从 query 参数获取 server_id（映射到原来的 id）
        $node_id = $request->getQueryParam('server_id');
        $node = (new Node())->find($node_id);

        if ($node === null) {
            return ResponseHelper::json($response, [
                'code' => 404,
                'msg' => 'Node not found.',
                'data' => null
            ]);
        }

        if ($node->type === 0) {
            return ResponseHelper::json($response, [
                'code' => 403,
                'msg' => 'Node is not enabled.',
                'data' => null
            ]);
        }

        // 获取协议名称并处理映射
        $protocol = strtolower($node->sort());
        if ($protocol === 'shadowsocks2022') {
            $protocol = 'shadowsocks';
        }

        // 构建响应数据
        $data = [
            'basic' => [
                'push_interval' => 95,
                'pull_interval' => 60
            ],
            'protocol' => $protocol,
            'config' => json_decode($node->custom_config, true, JSON_UNESCAPED_SLASHES)
        ];

        return ResponseHelper::json($response, [
            'code' => 200,
            'msg' => 'success',
            'data' => $data
        ]);
    }
}
