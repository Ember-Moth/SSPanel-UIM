<?php

declare(strict_types=1);

namespace App\Controllers\WebAPI;

use App\Controllers\BaseController;
use App\Models\Config;
use App\Models\DetectLog;
use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\OnlineLog;
use App\Models\User;
use App\Services\DynamicRate;
use App\Utils\ResponseHelper;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function count;
use function date;
use function is_array;
use function json_decode;
use function time;

final class UserController extends BaseController
{
    /**
     * GET /mod_mu/users
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node_id = $request->getQueryParam('node_id');
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

        $node->update(['node_heartbeat' => time()]);

        if (
            $node->node_bandwidth_limit !== 0 &&
            $node->node_bandwidth_limit <= $node->node_bandwidth
        ) {
            return ResponseHelper::json($response, [
                'code' => 403,
                'msg' => 'Node out of bandwidth.',
                'data' => null
            ]);
        }

        $users_raw = (new User())
            ->where('is_banned', 0)
            ->where('class_expire', '>', date('Y-m-d H:i:s'))
            ->where(function ($query) use ($node): void {
                $query->where('class', '>=', $node->node_class)
                    ->where(function ($query) use ($node): void {
                        if ($node->node_group !== 0) {
                            $query->where('node_group', $node->node_group);
                        }
                    });
            })
            ->orWhere('is_admin', 1)
            ->get([
                'id',
                'uuid',
                'node_speedlimit',
                'node_iplimit',
                'u',
                'd',
                'transfer_enable',
            ]);

        $users = [];

        foreach ($users_raw as $u) {
            if ($u->transfer_enable <= $u->u + $u->d) {
                if ($_ENV['keep_connect']) {
                    $u->node_speedlimit = 1;
                } else {
                    continue;
                }
            }

            if (
                $u->node_iplimit !== 0 &&
                $u->node_iplimit < (new OnlineLog())
                ->where('user_id', $u->id)
                ->where('last_time', '>', time() - 90)
                ->count()
            ) {
                continue;
            }

            $users[] = [
                'id'           => $u->id,
                'uuid'         => $u->uuid,
                'speed_limit'  => $u->node_speedlimit,
                'device_limit' => $u->node_iplimit,
            ];
        }

        $result = [
            'code' => 200,
            'msg'  => 'success',
            'data' => [
                'users' => $users,
            ],
        ];

        return ResponseHelper::json($response, $result);
    }

    /**
     * POST /mod_mu/users/traffic
     */
    public function addTraffic(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $body = json_decode($request->getBody()->__toString());

        if (! $body || ! is_array($body->traffic)) {
            return ResponseHelper::json($response, [
                'code' => 400,
                'msg' => 'Invalid data.',
                'data' => null
            ]);
        }

        $trafficData = $body->traffic;
        $node_id = $request->getQueryParam('node_id');
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

        $rate = 1;

        if ($node->is_dynamic_rate) {
            $dynamic_rate_config = json_decode($node->dynamic_rate_config);
            $dynamic_rate_type = match ($node->dynamic_rate_type) {
                1 => 'linear',
                default => 'logistic',
            };
            $rate = DynamicRate::getRateByTime(
                (float) ($dynamic_rate_config?->max_rate ?? 1),
                (int) ($dynamic_rate_config?->max_rate_time ?? 1),
                (float) ($dynamic_rate_config?->min_rate ?? 1),
                (int) ($dynamic_rate_config?->min_rate_time ?? 0),
                (int) date('H'),
                $dynamic_rate_type
            );
        } else {
            $rate = $node->traffic_rate;
        }

        $sum = 0;
        $is_traffic_log = Config::obtain('traffic_log');

        foreach ($trafficData as $log) {
            $uid = $log?->uid;
            $upload = $log?->upload;
            $download = $log?->download;

            if ($uid) {
                $billedUpload = $upload * $rate;
                $billedDownload = $download * $rate;

                $user = (new User())->find($uid);
                if ($user) {
                    $user->update([
                        'last_use_time' => time(),
                        'u' => $user->u + $billedUpload,
                        'd' => $user->d + $billedDownload,
                        'transfer_total' => $user->transfer_total + $upload + $download,
                        'transfer_today' => $user->transfer_today + $billedUpload + $billedDownload,
                    ]);
                }
            }

            if ($is_traffic_log) {
                (new HourlyUsage())->add((int) $uid, (int) ($upload + $download));
            }

            $sum += $upload + $download;
        }

        $node->update([
            'node_bandwidth' => $node->node_bandwidth + $sum,
            'online_user' => count($trafficData),
        ]);

        return ResponseHelper::json($response, [
            'code' => 200,
            'msg' => 'success',
            'data' => null
        ]);
    }

    /**
     * POST /mod_mu/users/aliveip
     */
    public function addAliveIp(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $body = json_decode($request->getBody()->__toString());

        if (! $body || ! is_array($body->users)) {
            return ResponseHelper::json($response, [
                'code' => 400,
                'msg'  => 'Invalid data.',
                'data' => null
            ]);
        }

        $users = $body->users;
        $node_id = $request->getQueryParam('node_id');
        $node = (new Node())->find($node_id);

        if ($node === null) {
            return ResponseHelper::json($response, [
                'code' => 404,
                'msg'  => 'Node not found.',
                'data' => null
            ]);
        }

        if ($node->type === 0) {
            return ResponseHelper::json($response, [
                'code' => 403,
                'msg'  => 'Node is not enabled.',
                'data' => null
            ]);
        }

        foreach ($users as $log) {
            $uid = (int) $log?->uid;
            $ip = (string) $log?->ip;

            if (Tools::isIPv4($ip)) {
                $ip = '::ffff:' . $ip;
            } elseif (! Tools::isIPv6($ip)) {
                continue;
            }

            (new OnlineLog())->upsert(
                [
                    'user_id' => $uid,
                    'ip' => $ip,
                    'node_id' => $node_id,
                    'first_time' => time(),
                    'last_time' => time(),
                ],
                ['user_id', 'ip'],
                ['node_id', 'last_time']
            );
        }

        return ResponseHelper::json($response, [
            'code' => 200,
            'msg'  => 'success',
            'data' => null
        ]);
    }

    /**
     * POST /mod_mu/users/detectlog
     */
    public function addDetectLog(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $data = json_decode($request->getBody()->__toString());

        if (! $data || ! is_array($data->data)) {
            return ResponseHelper::json($response, [
                'code' => 400,
                'msg' => 'Invalid data.',
                'data' => null
            ]);
        }

        $data = $data->data;
        $node_id = $request->getQueryParam('node_id');
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

        foreach ($data as $log) {
            $list_id = (int) $log?->list_id;
            $user_id = (int) $log?->user_id;

            (new DetectLog())->insert([
                'user_id' => $user_id,
                'list_id' => $list_id,
                'node_id' => $node_id,
                'datetime' => time(),
            ]);
        }

        return ResponseHelper::json($response, [
            'code' => 200,
            'msg' => 'success',
            'data' => null
        ]);
    }
}
