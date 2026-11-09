<?php
/**
 * 模型档案服务（Operit ModelConfig 生态）
 *
 * 职责：连通性测试 —— 用档案里的 endpoint / api_key / model 发一次最小的
 * OpenAI 兼容请求（GET /models 或 POST /chat/completions），据结果判定可用性。
 *
 * 与网关账号池的区别：这是用户自建端点，直连测试；不走网关的账号调度。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ModelProfileRepository;
use App\Support\HttpClient;
use App\Support\Logger;
use App\Support\UrlGuard;

class ModelProfileService
{
    /** 连通测试超时（秒） */
    private const TEST_TIMEOUT = 20;

    public function __construct(private ModelProfileRepository $repository) {}

    /**
     * 测试档案连通性。
     *
     * @param array $profile hydrate 后的档案行
     * @return array{ok:bool, status:string, message:string, latency_ms:int, models?:array}
     */
    public function testConnection(array $profile): array
    {
        $endpoint = trim((string) ($profile['api_endpoint'] ?? ''));
        if ($endpoint === '') {
            return $this->fail($profile, '未配置 API 端点');
        }

        // 用户可控地址 —— 开启出站守卫（防 SSRF）
        try {
            UrlGuard::assertOutbound($endpoint);
        } catch (\Throwable $e) {
            return $this->fail($profile, $e->getMessage());
        }

        $apiKey = $this->repository->decryptKey($profile);
        $providerType = (string) ($profile['provider_type'] ?? 'openai');

        $modelsUrl = $this->modelsUrl($endpoint, $providerType);
        $start = now_ms();

        try {
            $http = (new HttpClient())
                ->timeout(self::TEST_TIMEOUT, 10)
                ->withOutboundGuard()
                ->withHeader('Accept', 'application/json');

            if ($apiKey !== '') {
                $http = $this->applyAuth($http, $providerType, $apiKey);
            }
            foreach ((array) ($profile['custom_headers'] ?? []) as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $http = $http->withHeader($name, $value);
                }
            }

            $response = $http->get($modelsUrl);
            $latency = now_ms() - $start;
            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                $body = $response->json();
                $models = [];
                foreach ((array) ($body['data'] ?? []) as $m) {
                    if (is_array($m) && isset($m['id'])) {
                        $models[] = (string) $m['id'];
                    }
                }
                $this->repository->recordTest((int) $profile['id'], 'OK', "HTTP {$status}");

                return [
                    'ok'         => true,
                    'status'     => 'OK',
                    'message'    => "连接成功（HTTP {$status}，{$latency}ms）",
                    'latency_ms' => $latency,
                    'models'     => $models,
                ];
            }

            // 401/403 也说明端点可达，只是密钥不对 —— 如实回传状态码
            $message = "HTTP {$status}";
            $bodyText = trim((string) $response->body());
            if ($bodyText !== '') {
                $message .= '：' . mb_substr($bodyText, 0, 300);
            }
            $this->repository->recordTest((int) $profile['id'], 'FAIL', $message);

            return [
                'ok'         => false,
                'status'     => 'FAIL',
                'message'    => $message,
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            Logger::warn('模型档案连通测试失败', ['id' => $profile['id'] ?? 0, 'error' => $e->getMessage()]);

            return $this->fail($profile, $e->getMessage());
        }
    }

    private function fail(array $profile, string $message): array
    {
        if (!empty($profile['id'])) {
            $this->repository->recordTest((int) $profile['id'], 'FAIL', $message);
        }

        return ['ok' => false, 'status' => 'FAIL', 'message' => $message, 'latency_ms' => 0];
    }

    /** 由 endpoint 拼出 models 列表地址 */
    private function modelsUrl(string $endpoint, string $providerType): string
    {
        $base = rtrim($endpoint, '/');
        // 已经是完整的 chat/completions 或 models 地址时，退回其 base
        $base = preg_replace('#/(chat/completions|messages|models)$#', '', $base) ?? $base;

        return match ($providerType) {
            'anthropic' => $base . '/models',
            'gemini'    => $base . '/models',
            default     => $base . '/models',
        };
    }

    /** 按 provider 类型加鉴权头 */
    private function applyAuth(HttpClient $http, string $providerType, string $apiKey): HttpClient
    {
        return match ($providerType) {
            'anthropic' => $http->withHeader('x-api-key', $apiKey)
                ->withHeader('anthropic-version', '2023-06-01'),
            'gemini'    => $http->withHeader('x-goog-api-key', $apiKey),
            default     => $http->withBearer($apiKey),
        };
    }
}
