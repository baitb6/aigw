<?php
/**
 * 管理台 · 普通用户管理
 *  - 列表（关键词 / 状态筛选 + 分页）与统计
 *  - 详情（含归属账号数 / 调用数 / 用户 API Key）
 *  - 改资料、启用/禁用、重置 API Key、删除（级联清理归属账号与日志）
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\AuditRepository;
use App\Repositories\LogRepository;
use App\Repositories\PersonaRepository;
use App\Repositories\UserRepository;
use App\Support\Audit;
use App\Support\Logger;

class UserController
{
    public function __construct(
        private UserRepository $userRepository,
        private AccountRepository $accountRepository,
        private LogRepository $logRepository,
        private AuditRepository $auditRepository,
        private PersonaRepository $personaRepository,
    ) {}

    /** GET /api/admin/users */
    public function index(Request $request): Response
    {
        $filters = [
            'keyword' => (string) $request->query('keyword', ''),
            'status'  => strtoupper(trim((string) $request->query('status', ''))),
        ];

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $result = $this->userRepository->paginate($filters, $page, $perPage);

        $accountCounts = $this->accountRepository->userAccountCounts();
        $callCounts = $this->logRepository->userCallCounts();

        $result['items'] = array_map(function (array $row) use ($accountCounts, $callCounts): array {
            unset($row['password']);
            $id = (int) ($row['id'] ?? 0);
            $row['account_count'] = $accountCounts[$id] ?? 0;
            $row['call_count'] = $callCounts[$id] ?? 0;
            return $row;
        }, $result['items']);

        return Response::json([
            'ok'   => true,
            'data' => array_merge($result, [
                'summary' => $this->userRepository->statusCounts(),
            ]),
        ]);
    }

    /** GET /api/admin/users/{id} */
    public function show(Request $request): Response
    {
        $user = $this->findOrFail($request);
        if ($user instanceof Response) {
            return $user;
        }

        unset($user['password']);
        $id = (int) ($user['id'] ?? 0);

        $accountCounts = $this->accountRepository->userAccountCounts();
        $callCounts = $this->logRepository->userCallCounts();

        $user['account_count'] = $accountCounts[$id] ?? 0;
        $user['call_count'] = $callCounts[$id] ?? 0;

        return Response::json(['ok' => true, 'data' => $user]);
    }

    /** PUT /api/admin/users/{id} */
    public function update(Request $request): Response
    {
        $user = $this->findOrFail($request);
        if ($user instanceof Response) {
            return $user;
        }
        $id = (int) $user['id'];
        $payload = $request->json();

        if (array_key_exists('email', $payload)) {
            $email = trim((string) $payload['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->fail('邮箱格式不正确');
            }
            $email = mb_substr($email, 0, 128);
            if ($email !== '') {
                $existing = $this->userRepository->findByEmail($email);
                if ($existing !== null && (int) $existing['id'] !== $id) {
                    return $this->fail('该邮箱已被其他用户使用');
                }
            }
            $payload['email'] = $email;
        }

        $fields = [];
        if (array_key_exists('nickname', $payload)) {
            $fields['nickname'] = mb_substr(trim((string) $payload['nickname']), 0, 64);
        }
        if (array_key_exists('email', $payload)) {
            $fields['email'] = (string) $payload['email'];
        }
        if (array_key_exists('status', $payload)) {
            $fields['status'] = strtoupper((string) $payload['status']) === 'DISABLED' ? 'DISABLED' : 'ACTIVE';
        }

        try {
            $this->userRepository->updateAdmin($id, $fields);
        } catch (\Throwable $e) {
            Logger::warn('用户资料更新失败', ['user_id' => $id, 'error' => $e->getMessage()]);
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->fail('该邮箱已被其他用户使用');
            }
            return $this->fail('用户更新失败，请稍后重试', 500);
        }
        Logger::info('用户资料已更新', ['user_id' => $id, 'fields' => array_keys($fields)]);

        Audit::ok('user.update', [
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'     => '管理员修改用户资料：「' . ($user['nickname'] ?? '') . '」',
            'detail'      => [
                'fields' => $fields,
                'before' => [
                    'nickname' => (string) ($user['nickname'] ?? ''),
                    'email'    => (string) ($user['email'] ?? ''),
                    'status'   => (string) ($user['status'] ?? ''),
                ],
            ],
        ]);

        return Response::json(['ok' => true, 'message' => '用户已更新']);
    }

    /** POST /api/admin/users/{id}/toggle */
    public function toggle(Request $request): Response
    {
        $user = $this->findOrFail($request);
        if ($user instanceof Response) {
            return $user;
        }
        $id = (int) $user['id'];
        $current = strtoupper((string) ($user['status'] ?? 'ACTIVE')) === 'ACTIVE';
        $status = $current ? 'DISABLED' : 'ACTIVE';

        $this->userRepository->setStatus($id, $status);
        Logger::info('用户状态已切换', ['user_id' => $id, 'status' => $status]);

        Audit::ok('user.toggle', [
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'     => ($status === 'ACTIVE' ? '启用' : '禁用') . '用户「' . ($user['nickname'] ?? '') . '」',
            'detail'      => ['status' => $status],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $status === 'ACTIVE' ? '用户已启用' : '用户已禁用',
            'data'    => ['status' => $status],
        ]);
    }

    /** POST /api/admin/users/{id}/regenerate-key */
    public function regenerateKey(Request $request): Response
    {
        $user = $this->findOrFail($request);
        if ($user instanceof Response) {
            return $user;
        }
        $id = (int) $user['id'];
        $key = $this->userRepository->regenerateApiKey($id);
        Logger::info('用户 API Key 已重置', ['user_id' => $id]);

        Audit::ok('user.regenerate_key', [
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'     => '管理员重置用户 API Key：「' . ($user['nickname'] ?? '') . '」',
            'detail'      => ['key_head' => substr($key, 0, 8)],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '用户 API Key 已重置',
            'data'    => ['api_key' => $key],
        ]);
    }

    /** DELETE /api/admin/users/{id} */
    public function destroy(Request $request): Response
    {
        $user = $this->findOrFail($request);
        if ($user instanceof Response) {
            return $user;
        }
        $id = (int) $user['id'];

        $accounts = $this->accountRepository->deleteByUserId($id);
        $logs = $this->logRepository->deleteByUserId($id);
        // 审计同样级联清理，避免残留他人数据；删除动作本身在下面以 user_id=0 留档
        $audits = $this->auditRepository->deleteByUserId($id);
        // 用户级思维配置（平台开关 / 思维开关）也要清：这两张表按 user_id 挂在思维上，
        // 不删就是孤儿行 —— 主键回收后新用户可能"继承"前任的开关键位。
        $personaLinks = $this->personaRepository->deleteUserTargets($id)
            + $this->personaRepository->deleteUserEnables($id);
        $this->userRepository->delete($id);

        Logger::info('用户已删除', ['user_id' => $id, 'accounts' => $accounts, 'logs' => $logs]);

        Audit::ok('user.delete', [
            'user_id'     => 0,
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'     => sprintf(
                '删除用户「%s」（#%d），级联清理账号 %d 个、调用日志 %d 条、审计 %d 条、思维开关 %d 项',
                (string) ($user['nickname'] ?? ''),
                $id,
                $accounts,
                $logs,
                $audits,
                $personaLinks
            ),
            'detail'      => [
                'username' => (string) ($user['username'] ?? ''),
                'email'    => (string) ($user['email'] ?? ''),
                'accounts' => $accounts,
                'logs'     => $logs,
                'audits'   => $audits,
                'persona'  => $personaLinks,
            ],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => sprintf('用户已删除（级联清理账号 %d 个、日志 %d 条）', $accounts, $logs),
        ]);
    }

    private function findOrFail(Request $request): array|Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($id <= 0) {
            return $this->fail('无效的用户 ID', 404);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return $this->fail('用户不存在', 404);
        }

        return $user;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
