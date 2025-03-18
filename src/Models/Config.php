<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use function is_array;
use function json_decode;
use function json_encode;

/**
 * @property int    $id         配置ID
 * @property string $item       配置项
 * @property string $value      配置值
 * @property string $class      配置类别
 * @property string $is_public  是否为公共参数
 * @property string $type       配置值类型
 * @property string $default    默认值
 * @property string $mark       备注
 *
 * @mixin Builder
 */
final class Config extends Model
{
    protected $connection = 'default';
    protected $table = 'config';

    public static function obtain(string $item): bool|int|array|string|null
    {
        $config = (new self())->where('item', $item)->first();

        if (!$config) {
            return null;
        }

        return match ($config->type) {
            'bool' => (bool) $config->value,
            'int' => (int) $config->value,
            'array' => json_decode($config->value, true), // 确保返回数组
            default => (string) $config->value,
        };
    }

    public static function getClass(string $class): array
    {
        return (new self())->where('class', $class)->get()->mapWithKeys(function ($config) {
            return [$config->item => match ($config->type) {
                'bool' => (bool) $config->value,
                'int' => (int) $config->value,
                'array' => json_decode($config->value, true),
                default => (string) $config->value,
            }];
        })->toArray();
    }

    public static function getItemListByClass(string $class): array
    {
        return (new self())->where('class', $class)->pluck('item')->toArray();
    }

    public static function getPublicConfig(): array
    {
        return (new self())->where('is_public', '1')->get()->mapWithKeys(function ($config) {
            return [$config->item => match ($config->type) {
                'bool' => (bool) $config->value,
                'int' => (int) $config->value,
                'array' => json_decode($config->value, true),
                default => (string) $config->value,
            }];
        })->toArray();
    }

    public static function set(string $item, mixed $value): bool
    {
        $value = is_array($value) ? json_encode($value) : (string) $value;

        try {
            return (bool) (new self())->updateOrInsert(['item' => $item], ['value' => $value]);
        } catch (QueryException $e) {
            return false;
        }
    }
}
