<?php
/**
 * 用户端语音控制器（/api/user/speech/*）
 *
 * 参考 Operit 的 SpeechService / VoiceService：STT（语音转文字）与 TTS（文字转语音）。
 * 全部走服务端代理 —— 客户端**不接触任何上游 API Key**。
 *
 * 输入约定：
 *   - STT：可 `POST` 原始音频二进制（Content-Type: audio/*），或 JSON `{audio_base64}`；
 *         也接受 `{text}` 之外的 `format`/`language` 参数。
 *   - TTS：JSON `{text, voice}`。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\SpeechLogRepository;
use App\Services\SpeechService;
use App\Support\Tenant;

class SpeechController
{
    public function __construct(
        private SpeechService $service,
        private SpeechLogRepository $logRepository,
    ) {}

    /** GET /api/user/speech/capabilities —— 是否已配置 STT/TTS */
    public function capabilities(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => $this->service->capabilities(),
        ]);
    }

    /** POST /api/user/speech/stt —— 语音转文字 */
    public function stt(Request $request): Response
    {
        $contentType = strtolower((string) $request->header('content-type', ''));
        $audio = '';
        $format = (string) ($request->query('format', ''));
        $language = (string) $request->input('language', '');

        if (str_starts_with($contentType, 'application/json')) {
            $payload = $request->json();
            $encoded = (string) ($payload['audio_base64'] ?? '');
            if ($encoded !== '') {
                $decoded = base64_decode($encoded, true);
                $audio = $decoded === false ? '' : $decoded;
            }
            $format = (string) ($payload['format'] ?? $format);
            $language = (string) ($payload['language'] ?? $language);
        } else {
            // 原始音频二进制
            $audio = $request->rawBody();
            if ($format === '') {
                $format = $this->formatFromContentType($contentType);
            }
        }

        if ($audio === '') {
            return $this->fail('未收到音频内容');
        }

        // 上限保护（25MB，与 OpenAI 一致）
        if (strlen($audio) > 25 * 1024 * 1024) {
            return $this->fail('音频过大（上限 25MB）');
        }

        $result = $this->service->transcribe(Tenant::scope(), $audio, $format ?: 'wav', $language);

        if (!$result['ok']) {
            return $this->fail($result['message'], 502);
        }

        return Response::json(['ok' => true, 'data' => ['text' => $result['text']]]);
    }

    /** POST /api/user/speech/tts —— 文字转语音（返回音频二进制） */
    public function tts(Request $request): Response
    {
        $payload = $request->json();
        $text = trim((string) ($payload['text'] ?? ''));
        $voice = (string) ($payload['voice'] ?? '');

        if ($text === '') {
            return $this->fail('文本不能为空');
        }
        if (mb_strlen($text) > 5000) {
            return $this->fail('文本过长（上限 5000 字）');
        }

        $result = $this->service->synthesize(Tenant::scope(), $text, $voice);

        if (!$result['ok']) {
            return $this->fail($result['message'], 502);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'audio_base64' => base64_encode($result['audio']),
                'mime'         => $result['mime'],
                'bytes'        => strlen($result['audio']),
            ],
        ]);
    }

    /** GET /api/user/speech/logs —— 近期语音调用记录 */
    public function logs(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => ['items' => $this->logRepository->recent(Tenant::scope())],
        ]);
    }

    private function formatFromContentType(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'wav')  => 'wav',
            str_contains($contentType, 'mpeg'), str_contains($contentType, 'mp3') => 'mp3',
            str_contains($contentType, 'm4a')  => 'm4a',
            str_contains($contentType, 'webm') => 'webm',
            str_contains($contentType, 'ogg')  => 'ogg',
            default => 'wav',
        };
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
