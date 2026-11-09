<?php
/**
 * 语音服务（STT / TTS 代理）
 *
 * 参考 Operit 的 SpeechService / VoiceService：
 *   - STT：OpenAI /audio/transcriptions（Multipart WAV 上传）
 *   - TTS：HTTP TTS，支持「响应流水线」——parse_json → pick(JSONPath) →
 *          http_get → base64_decode，把各家厂商响应统一转成音频字节
 *
 * 为什么放服务端：语音上游都要 API Key；集中在网关可**不向客户端下发任何 Key**，
 * 且便于统一限流、计费、脱敏日志。
 *
 * 上游配置来源：环境变量（AIGW_SPEECH_*），未配置时返回明确错误而非静默失败。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SpeechLogRepository;
use App\Support\HttpClient;
use App\Support\Logger;

class SpeechService
{
    private const TIMEOUT = 120;

    public function __construct(private SpeechLogRepository $logRepository) {}

    /**
     * 语音转文字（STT）。
     *
     * @param string $audio  音频二进制（建议 16kHz 单声道 WAV）
     * @param string $format 文件扩展名（wav/mp3/m4a）
     * @return array{ok:bool,text:string,message:string}
     */
    public function transcribe(?int $userId, string $audio, string $format = 'wav', string $language = ''): array
    {
        $started = now_ms();
        $endpoint = (string) env('AIGW_SPEECH_STT_ENDPOINT', '');
        $apiKey = (string) env('AIGW_SPEECH_STT_KEY', '');
        $model = (string) env('AIGW_SPEECH_STT_MODEL', 'whisper-1');

        if ($endpoint === '' || $apiKey === '') {
            return $this->speechFail($userId, 'stt', 'STT 服务未配置（AIGW_SPEECH_STT_ENDPOINT / _KEY）', $started, strlen($audio));
        }

        if ($audio === '') {
            return $this->speechFail($userId, 'stt', '音频内容为空', $started, 0);
        }

        try {
            // 组装 multipart/form-data（PHP curl 用 CURLFile 需要真实文件，这里手工拼装避免落盘）
            $boundary = '----aaee' . bin2hex(random_bytes(8));
            $body = $this->buildMultipart($boundary, [
                'model' => $model,
                'language' => $language,
            ], [
                'file' => ['filename' => 'audio.' . $format, 'content' => $audio, 'mime' => 'audio/' . $format],
            ]);

            $response = (new HttpClient())
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                ])
                ->timeout(self::TIMEOUT, 10)
                ->postRaw($endpoint, $body, []);

            if (!$response->ok()) {
                return $this->speechFail($userId, 'stt', '上游返回 HTTP ' . $response->status(), $started, strlen($audio));
            }

            $data = $response->json();
            $text = (string) ($data['text'] ?? '');

            $this->logRepository->record([
                'user_id'     => $userId ?? 0,
                'kind'        => 'stt',
                'provider'    => 'http',
                'model'       => $model,
                'bytes_in'    => strlen($audio),
                'text_len'    => mb_strlen($text),
                'duration_ms' => now_ms() - $started,
                'ok'          => true,
            ]);

            return ['ok' => true, 'text' => $text, 'message' => ''];
        } catch (\Throwable $e) {
            return $this->speechFail($userId, 'stt', $e->getMessage(), $started, strlen($audio));
        }
    }

    /**
     * 文字转语音（TTS）。
     *
     * 支持 Operit 的「响应流水线」：多数 HTTP TTS 返回的是 JSON（内含音频 URL 或 base64），
     * 需要二次取址/解码。响应格式由 AIGW_SPEECH_TTS_MODE 指定：
     *   binary      —— 响应体直接就是音频字节
     *   base64      —— 响应 JSON 里某字段是 base64 音频
     *   url         —— 响应 JSON 里某字段是音频 URL，需再拉一次
     *
     * @return array{ok:bool,audio:string,mime:string,message:string}
     */
    public function synthesize(?int $userId, string $text, string $voice = ''): array
    {
        $started = now_ms();
        $endpoint = (string) env('AIGW_SPEECH_TTS_ENDPOINT', '');
        $apiKey = (string) env('AIGW_SPEECH_TTS_KEY', '');
        $model = (string) env('AIGW_SPEECH_TTS_MODEL', '');
        $mode = (string) env('AIGW_SPEECH_TTS_MODE', 'binary');
        $field = (string) env('AIGW_SPEECH_TTS_FIELD', 'audio');

        if ($endpoint === '' || $apiKey === '') {
            return $this->audioFail($userId, 'TTS 服务未配置（AIGW_SPEECH_TTS_ENDPOINT / _KEY）', $started, $text);
        }

        if (trim($text) === '') {
            return $this->audioFail($userId, '待合成文本为空', $started, '');
        }

        try {
            $payload = array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
            ], static fn ($v) => $v !== '');

            $response = (new HttpClient())
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept'        => 'application/json, audio/*',
                ])
                ->timeout(self::TIMEOUT, 10)
                ->postJson($endpoint, $payload);

            if (!$response->ok()) {
                return $this->audioFail($userId, '上游返回 HTTP ' . $response->status(), $started, $text);
            }

            $audio = '';
            $mime = 'audio/mpeg';

            if ($mode === 'base64') {
                $data = $response->json();
                $encoded = (string) ($data[$field] ?? '');
                if ($encoded !== '') {
                    $audio = (string) base64_decode($encoded, true);
                }
            } elseif ($mode === 'url') {
                $data = $response->json();
                $url = (string) ($data[$field] ?? '');
                if ($url !== '') {
                    $audioResponse = (new HttpClient())
                        ->withOutboundGuard()
                        ->timeout(self::TIMEOUT, 10)
                        ->get($url);
                    if ($audioResponse->ok()) {
                        $audio = $audioResponse->body();
                        $mime = $audioResponse->header('content-type', 'audio/mpeg') ?? 'audio/mpeg';
                    }
                }
            } else {
                $audio = $response->body();
                $mime = $response->header('content-type', 'audio/mpeg') ?? 'audio/mpeg';
            }

            if ($audio === '') {
                return $this->audioFail($userId, '上游未返回音频内容', $started, $text);
            }

            $this->logRepository->record([
                'user_id'     => $userId ?? 0,
                'kind'        => 'tts',
                'provider'    => 'http',
                'model'       => $model,
                'bytes_out'   => strlen($audio),
                'text_len'    => mb_strlen($text),
                'duration_ms' => now_ms() - $started,
                'ok'          => true,
            ]);

            return ['ok' => true, 'audio' => $audio, 'mime' => $mime, 'message' => ''];
        } catch (\Throwable $e) {
            return $this->audioFail($userId, $e->getMessage(), $started, $text);
        }
    }

    /** 能力探测（供前端判断是否展示语音入口） */
    public function capabilities(): array
    {
        return [
            'stt' => (string) env('AIGW_SPEECH_STT_ENDPOINT', '') !== '',
            'tts' => (string) env('AIGW_SPEECH_TTS_ENDPOINT', '') !== '',
        ];
    }

    /** 手工构造 multipart/form-data（避免音频落盘） */
    private function buildMultipart(string $boundary, array $fields, array $files): string
    {
        $eol = "\r\n";
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }

        foreach ($files as $name => $file) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['filename'] . '"' . $eol;
            $body .= 'Content-Type: ' . $file['mime'] . $eol . $eol;
            $body .= $file['content'] . $eol;
        }

        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    private function speechFail(?int $userId, string $kind, string $message, int $started, int $bytesIn): array
    {
        $this->logRepository->record([
            'user_id'     => $userId ?? 0,
            'kind'        => $kind,
            'bytes_in'    => $bytesIn,
            'duration_ms' => now_ms() - $started,
            'ok'          => false,
            'message'     => $message,
        ]);
        Logger::warn('语音调用失败', ['kind' => $kind, 'error' => $message]);

        return ['ok' => false, 'text' => '', 'message' => $message];
    }

    private function audioFail(?int $userId, string $message, int $started, string $text): array
    {
        $this->logRepository->record([
            'user_id'     => $userId ?? 0,
            'kind'        => 'tts',
            'text_len'    => mb_strlen($text),
            'duration_ms' => now_ms() - $started,
            'ok'          => false,
            'message'     => $message,
        ]);
        Logger::warn('TTS 调用失败', ['error' => $message]);

        return ['ok' => false, 'audio' => '', 'mime' => '', 'message' => $message];
    }
}
