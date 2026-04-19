<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\EmailQueue;
use App\Services\DB;
use App\Services\Mail;
use App\Utils\Tools;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use function array_map;
use function count;
use function json_decode;
use function time;
use const PHP_EOL;

final class EmailQueueService
{
    public function processEmailQueue(): void
    {
        if ((new EmailQueue())->count() === 0) {
            echo Tools::toDateTime(time()) . ' 邮件队列为空' . PHP_EOL;

            return;
        }

        $timestamp = time();

        while (true) {
            if (time() - $timestamp > 299) {
                echo Tools::toDateTime(time()) . '邮件队列处理超时，已跳过' . PHP_EOL;
                break;
            }

            DB::beginTransaction();
            $emailQueuesRaw = DB::select('SELECT * FROM email_queue LIMIT 1 FOR UPDATE SKIP LOCKED');

            if (count($emailQueuesRaw) === 0) {
                DB::commit();
                break;
            }

            $emailQueues = array_map(static function ($value) {
                return (array) $value;
            }, $emailQueuesRaw);

            $emailQueue = $emailQueues[0];

            echo '发送邮件至 ' . $emailQueue['to_email'] . PHP_EOL;

            DB::delete('DELETE FROM email_queue WHERE id = ?', [$emailQueue['id']]);

            if (Tools::isEmail($emailQueue['to_email'])) {
                try {
                    Mail::send(
                        $emailQueue['to_email'],
                        $emailQueue['subject'],
                        $emailQueue['template'],
                        json_decode($emailQueue['array'])
                    );
                } catch (Exception | ClientExceptionInterface $e) {
                    echo $e->getMessage();
                }
            } else {
                echo $emailQueue['to_email'] . ' 邮箱格式错误，已跳过' . PHP_EOL;
            }

            DB::commit();
        }

        echo Tools::toDateTime(time()) . ' 邮件队列处理完成' . PHP_EOL;
    }
}
