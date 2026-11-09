<?php
/**
 * 模型目录同步服务 —— 「从上游拉模型并写进目录」的**单一真源**。
 *
 * 为什么要有这个类（2026-10-04 线上 524 血案）：
 *   同步要逐个供应商去**上游**拉目录，而线上站点前面挂着腾讯云 EdgeOne，
 *   「回源超时」默认仅 15 秒（实际观测约 50 秒就被掐）—— 只要整轮同步挂在
 *   一个 HTTP 请求里，就必然 524（源站还在跑，客户端已经拿到错误页）。
 *   所以同步改成「HTTP 只负责建后台任务 → bin/action-worker.php 在独立进程里跑」。
 *   这样一来，控制器（HTTP 侧）与 worker（CLI 侧）必须共用**同一份**同步逻辑，
 *   否则两边的作用域口径/文案/跳过规则会各写一套（本项目反复咬人的静默漂移）。
 *
 * 作用域（与仓库层一致）：
 *   · 管理员（scope=null）→ 全局「已启用」供应商，可用任意账号；
 *   · 普通用户（scope=uid）→ **本人有账号**且在全局启用的供应商，只用自己的账号。
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\Account;
use App\Provider\ProviderManager;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Support\Logger;

class ModelCatalogSync
{
    public function __construct(
        private ProviderManager $providerManager,
        private ProviderRepository $providerRepository,
        private AccountRepository $accountRepository,
        private ModelRepository $modelRepository,
    ) {}

    /**
     * 全量同步的候选供应商键。
     *
     *  · 管理员（scope=null）→ 全局「已启用」供应商；
     *  · 普通用户（scope=uid）→ 本人名下有账号（含停用/冷却）的供应商，
     *    且该供应商在全局是启用的（避免同步已停用的供应商）。
     *
     * @return string[]
     */
    public function syncableProviderKeys(?int $scope): array
    {
        $enabled = $this->providerRepository->enabledKeys();

        if ($scope === null) {
            return $enabled;
        }

        $mine = [];
        foreach ($this->accountRepository->all(null, $scope) as $account) {
            $mine[$account->providerKey] = true;
        }

        return array_values(array_filter($enabled, fn(string $k) => isset($mine[$k])));
    }

    /**
     * 解析「用哪个账号去拉这个供应商的模型」。
     *
     * ⚠️ 越权防线（本方法存在的唯一理由）：
     *   老代码写死 `usable($providerKey)[0]`，不带 `user_id` 限制 —— 普通用户点同步时，
     *   会拿**任意他人**的账号去拉上游，相当于用别人的凭据跑请求。这里强制把作用域
     *   传给 `usable()`：管理员（null）可用全局池；普通用户只取本人名下账号。
     *   显式指定 account_id 时同样校验归属，跨属主一律按「不存在」处理（防 IDOR）。
     *
     * @return array{0:?Account,1:string} [账号, 失败原因] —— 两者必有其一
     */
    public function resolveAccount(string $providerKey, ?int $scope, int $accountId = 0): array
    {
        if ($accountId > 0) {
            $account = $this->accountRepository->find($accountId);
            if ($account === null || $account->providerKey !== $providerKey) {
                return [null, '账号不存在'];
            }
            if ($scope !== null && $account->userId !== $scope) {
                return [null, '账号不存在'];
            }
            return [$account, ''];
        }

        $account = $this->accountRepository->usable($providerKey, 'balance', '', $scope)[0] ?? null;
        if ($account === null) {
            return [null, $scope === null
                ? '该供应商下没有可用账号，无法拉取模型'
                : '你名下该供应商没有可用账号，请先添加账号后再同步'];
        }

        return [$account, ''];
    }

    /**
     * 同步单个供应商：解析账号 → 拉上游目录 → 落库。
     *
     * @return array{ok:bool,provider:string,total:int,created:int,updated:int,message:string}
     */
    public function syncProvider(string $providerKey, ?int $scope, int $accountId = 0): array
    {
        $blank = ['ok' => false, 'provider' => $providerKey, 'total' => 0, 'created' => 0, 'updated' => 0];

        [$account, $error] = $this->resolveAccount($providerKey, $scope, $accountId);
        if ($account === null) {
            return $blank + ['message' => $error];
        }

        try {
            $catalog = $this->providerManager->get($providerKey)->modelCatalog($account);
        } catch (\Throwable $e) {
            return $blank + ['message' => '拉取模型失败：' . $e->getMessage()];
        }

        if (empty($catalog)) {
            return $blank + ['message' => '上游未返回任何模型'];
        }

        $result = $this->modelRepository->syncCatalog($providerKey, $catalog);

        Logger::info('模型目录已同步', [
            'provider' => $providerKey,
            'user_id'  => $scope ?? 0,
            'total'    => count($catalog),
            'created'  => $result['created'],
            'updated'  => $result['updated'],
        ]);

        return [
            'ok'       => true,
            'provider' => $providerKey,
            'total'    => count($catalog),
            'created'  => $result['created'],
            'updated'  => $result['updated'],
            'message'  => sprintf('拉取 %d 个模型，新增 %d 个，更新 %d 个', count($catalog), $result['created'], $result['updated']),
        ];
    }

    /**
     * 全量同步：逐个供应商尝试，返回汇总结果。
     *
     * @param  callable|null $onProgress function(string $message, array $progress): void
     *                                   每开始一个供应商前回调一次（后台任务用它写进度）
     * @return array{ok:bool,message:string,providers:int,models:int,skipped:list<string>,items:list<array<string,mixed>>}
     */
    public function syncAll(?int $scope, ?callable $onProgress = null): array
    {
        $keys = $this->syncableProviderKeys($scope);
        if ($keys === []) {
            return [
                'ok'        => false,
                'message'   => $scope === null ? '请先添加账号' : '你还没有任何账号，请先添加账号后再同步',
                'providers' => 0,
                'models'    => 0,
                'skipped'   => [],
                'items'     => [],
            ];
        }

        $total    = count($keys);
        $okCount  = 0;
        $modelCnt = 0;
        $skipped  = [];
        $items    = [];
        $index    = 0;

        foreach ($keys as $key) {
            $index++;
            if ($onProgress !== null) {
                $onProgress(
                    sprintf('正在同步 %d/%d：%s', $index, $total, $key),
                    ['index' => $index, 'total' => $total, 'provider' => $key]
                );
            }

            $item    = $this->syncProvider($key, $scope);
            $items[] = $item;

            if ($item['ok']) {
                $okCount++;
                $modelCnt += (int) $item['total'];
            } else {
                // 文案与旧实现保持一致（前端与管理端都在读这句）
                $skipped[] = $key . '（' . $this->brief($item['message']) . '）';
            }
        }

        $message = $okCount > 0
            ? sprintf('已同步 %d 个供应商，共 %d 个模型', $okCount, $modelCnt)
            : '没有可同步的供应商：' . ($skipped ? implode('；', $skipped) : '请先添加账号');

        if ($okCount > 0 && $skipped) {
            $message .= '；跳过：' . implode('；', $skipped);
        }

        Logger::info('模型目录全量同步', [
            'user_id'   => $scope ?? 0,
            'providers' => $okCount,
            'models'    => $modelCnt,
        ]);

        return [
            'ok'        => $okCount > 0,
            'message'   => $message,
            'providers' => $okCount,
            'models'    => $modelCnt,
            'skipped'   => $skipped,
            'items'     => $items,
        ];
    }

    /** 跳过原因里带上整段异常会撑爆一行文案，截断处理 */
    private function brief(string $message): string
    {
        return mb_strlen($message) > 60 ? mb_substr($message, 0, 60) . '…' : $message;
    }
}
