<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Config;
use App\Services\Detect;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class CronDetect extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronDetect - 处理检测相关定时任务
EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $minute = (int) date('i');

        // Detect GFW
        if (Config::obtain('enable_detect_gfw') && $minute === 0) {
            $detect = new Detect();
            $detect->gfw();
        }

        // Detect ban
        if (Config::obtain('enable_detect_ban') && $minute === 0) {
            $detect = new Detect();
            $detect->ban();
        }
    }
}