<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\DB;
use App\Services\OrderActivation;
use App\Services\Reward;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;
use function bccomp;
use function bcsub;
use function get_called_class;
use function in_array;
use function json_decode;
use function time;

abstract class Base
{
    protected AntiXSS $antiXss;

    abstract public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface;

    abstract public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface;

    /**
     * 支付网关的 codeName
     */
    abstract public static function _name(): string;

    /**
     * 是否启用支付网关
     */
    abstract public static function _enable(): bool;

    /**
     * 显示给用户的名称
     */
    abstract public static function _readableName(): string;

    public function getReturnHTML(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write('ok');
    }

    abstract public static function getPurchaseHTML(): string;

    public function postPayment(string $trade_no): void
    {
        $paylist = (new Paylist())->where('tradeno', $trade_no)->first();

        if ($paylist === null) {
            return;
        }

        $invoice = (new Invoice())->where('id', $paylist->invoice_id)->first();

        if ($invoice === null) {
            return;
        }

        $user = (new User())->find($paylist->userid);

        if ($user === null) {
            return;
        }

        try {
            DB::beginTransaction();

            if ($paylist->status === 0) {
                $paylist->datetime = time();
                $paylist->status = 1;
                $paylist->save();
            }

            if (($invoice->status === 'unpaid' || $invoice->status === 'partially_paid') &&
                bccomp((string) $paylist->total, (string) $invoice->price, 2) >= 0
            ) {
                $invoice->status = 'paid_gateway';
                $invoice->update_time = time();
                $invoice->pay_time = time();
                $invoice->save();
            }

            if (bccomp((string) $paylist->total, (string) $invoice->price, 2) > 0) {
                $money_before = $user->money;
                $overpaid = (float) bcsub((string) $paylist->total, (string) $invoice->price, 2);
                $user->money += $overpaid;
                $user->save();
                (new UserMoneyLog())->add(
                    $user->id,
                    $money_before,
                    $user->money,
                    $overpaid,
                    '超额支付账单 #' . $invoice->id
                );
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return;
        }

        // 支付成功后立即尝试激活订单
        $order = (new Order())->where('id', $invoice->order_id)->first();
        if ($order !== null && $order->status === 'pending_payment') {
            $order->status = 'pending_activation';
            $order->update_time = time();
            $order->save();
            // 尝试立即激活订单
            OrderActivation::activateOrder($order);
        }

        if ($user->ref_by > 0 && Config::obtain('invite_mode') === 'reward') {
            Reward::issuePaybackReward($user->id, $user->ref_by, $invoice->price, $paylist->invoice_id);
        }
    }

    public static function generateGuid(): string
    {
        return Tools::genRandomChar();
    }

    protected static function getCallbackUrl(): string
    {
        return $_ENV['baseUrl'] . '/payment/notify/' . get_called_class()::_name();
    }

    protected static function getUserReturnUrl(): string
    {
        return $_ENV['baseUrl'] . '/user/payment/return/' . get_called_class()::_name();
    }

    protected static function getActiveGateway(string $key): bool
    {
        $payment_gateways = (new Config())->where('item', 'payment_gateway')->first();
        $active_gateways = json_decode($payment_gateways->value);

        if (in_array($key, $active_gateways)) {
            return true;
        }

        return false;
    }
}
