<?php

declare(strict_types=1);

namespace App\Models;

use App\Utils\Tools;
use Illuminate\Database\Query\Builder;

/**
 * @property int $id         记录ID
 * @property string $code       邀请码
 * @property int $user_id    用户ID
 *
 * @mixin Builder
 * @method where(string $string, int $id)
 * @method save()
 */
final class InviteCode extends Model
{
    protected $connection = 'default';
    protected $table = 'user_invite_code';
    private int $user_id;
    private string|false $code;

    public function add(int $user_id): string
    {
        $this->code = Tools::genRandomChar(10);
        $this->user_id = $user_id;
        $this->save();

        return $this->code;
    }
}
