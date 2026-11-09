<?php
/**
 * 额度信息
 */

declare(strict_types=1);

namespace App\Provider\Dto;

class CreditInfo
{
    public function __construct(
        public bool $known = false,
        public ?float $balance = null,
        public string $detail = '',
        /** @var array<int, array{name:string, group:string, limit:float, used:float, remain:float}> */
        public array $packs = [],
        public ?string $error = null,
        /** @var array<string, mixed> 可能更新的凭据字段 */
        public array $credentialPatch = [],
        /** @var array{total:float,used:float,remain:float}|null 用量汇总（总额/已用/剩余） */
        public ?array $usage = null,
        /** 积分最近到期时间（毫秒，0=未知/不过期） */
        public int $expireAt = 0,
    ) {}

    public static function unknown(string $detail = ''): self
    {
        return new self(known: false, detail: $detail);
    }

    public static function failure(string $error): self
    {
        return new self(known: false, error: $error);
    }
}