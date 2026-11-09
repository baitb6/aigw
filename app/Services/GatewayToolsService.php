<?php
/**
 * 网关内置工具包（参考 Operit 的内置工具包 time / crossref / tavily / browser）
 *
 * 设计目标：把「纯 HTTP / 纯逻辑、不需要 Android」的工具下沉到 PHP 网关，
 * 客户端（Flutter）无需内置这些 SDK，Agent 循环直接经 `/api/user/tools/invoke`
 * 调用即可。这样：
 *   · API Key（如 TAVILY_API_KEY）留在服务端，不下发客户端；
 *   · 工具升级只改网关，App 无需发版；
 *   · 与 MCP 工具统一走「网关代理调用」范式。
 *
 * 已实现工具：
 *   - get_time      : 返回当前时间（本地/UTC/时间戳）
 *   - crossref_*    : Crossref 学术检索（DOI / 关键词 / 作者 / 标题 / ISSN）
 *   - bing_search   : Bing 联网检索（**免 Key**，直抓结果页并解析）
 *   - tavily_search : Tavily 联网检索（需 TAVILY_API_KEY，未配置时不下发）
 *   - fetch_url     : 抓取网页正文（去标签，需出站守卫）
 */

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpClient;

class GatewayToolsService
{
    /** 工具清单（OpenAI function schema） */
    public function schemas(): array
    {
        $tools = [
            $this->fn('get_time', '获取当前时间（包含本地时间、UTC 时间与时间戳）。', []),
            $this->fn('fetch_url', '抓取指定网页并返回纯文本正文（自动去 HTML 标签）。', [
                'url'      => ['type' => 'string', 'description' => '要抓取的网页地址（http/https）'],
                'max_chars' => ['type' => 'integer', 'description' => '最大返回字符数，默认 8000'],
            ], ['url']),
            $this->fn('crossref_search', '在 Crossref 学术数据库中检索文献（关键词 / 标题 / 作者 / DOI / ISSN 通用入口）。', [
                'query'      => ['type' => 'string', 'description' => '检索内容'],
                'field'      => ['type' => 'string', 'description' => '检索字段：keyword(默认)/title/author/doi/issn'],
                'rows'       => ['type' => 'integer', 'description' => '返回条数，默认 5，最大 20'],
            ], ['query']),
            $this->fn('bing_search', '使用内置 Bing 联网检索（无需 API Key），返回网页标题、摘要与链接。适合查询实时信息、新闻与资料。', [
                'query'       => ['type' => 'string', 'description' => '检索关键词（用关键词而非整句，可多次检索以扩大覆盖）'],
                'max_results' => ['type' => 'integer', 'description' => '返回条数，默认 5，最大 10'],
                'language'    => ['type' => 'string', 'description' => '结果语言偏好，默认 zh-CN,zh;q=0.9,en;q=0.8'],
            ], ['query']),
        ];

        // Tavily 需要服务端配置 API Key：未配置时**不下发**该工具，
        // 避免模型挑到一个必然失败的工具（旧行为是列出来、调用才报
        // 「网关未配置 TAVILY_API_KEY」）。未配置时的联网检索由免 Key 的
        // bing_search 兜底，「对话搜索」因此开箱即用。
        if (trim((string) env('TAVILY_API_KEY', '')) !== '') {
            $tools[] = $this->fn('tavily_search', '使用 Tavily 进行联网检索，返回相关网页摘要与链接（需网关配置 TAVILY_API_KEY）。', [
                'query'       => ['type' => 'string', 'description' => '检索问题'],
                'max_results' => ['type' => 'integer', 'description' => '返回条数，默认 5，最大 10'],
                'depth'       => ['type' => 'string', 'description' => '检索深度：basic(默认)/advanced'],
            ], ['query']);
        }

        return $tools;
    }

    /** 工具名清单 */
    public function names(): array
    {
        return array_map(static fn ($s) => $s['function']['name'], $this->schemas());
    }

    /**
     * 执行一个内置工具。
     *
     * @return array{ok:bool,result:string,is_error:bool}
     */
    public function invoke(string $name, array $args): array
    {
        try {
            $text = match ($name) {
                'get_time'        => $this->getTime(),
                'fetch_url'       => $this->fetchUrl((string) ($args['url'] ?? ''), (int) ($args['max_chars'] ?? 8000)),
                'crossref_search' => $this->crossref(
                    (string) ($args['query'] ?? ''),
                    (string) ($args['field'] ?? 'keyword'),
                    (int) ($args['rows'] ?? 5)
                ),
                'bing_search'     => $this->bing(
                    (string) ($args['query'] ?? ''),
                    (int) ($args['max_results'] ?? 5),
                    (string) ($args['language'] ?? '')
                ),
                'tavily_search'   => $this->tavily(
                    (string) ($args['query'] ?? ''),
                    (int) ($args['max_results'] ?? 5),
                    (string) ($args['depth'] ?? 'basic')
                ),
                default => throw new \RuntimeException("未知的内置工具：{$name}"),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'result' => $e->getMessage(), 'is_error' => true];
        }

        return ['ok' => true, 'result' => $text, 'is_error' => false];
    }

    // ── get_time ────────────────────────────────────────────────────
    private function getTime(): string
    {
        $now = new \DateTimeImmutable();
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return implode("\n", [
            'local: ' . $now->format('Y-m-d H:i:s') . ' (' . $now->getTimezone()->getName() . ')',
            'utc: ' . $utc->format('Y-m-d H:i:s'),
            'weekday: ' . $now->format('l'),
            'timestamp: ' . $now->getTimestamp() . '000',
        ]);
    }

    // ── fetch_url ───────────────────────────────────────────────────
    private function fetchUrl(string $url, int $maxChars): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('url 必须是 http/https 地址');
        }

        $maxChars = max(500, min(40000, $maxChars));

        // 用户可控地址 → 开启出站守卫（协议白名单 + 内网拒绝 + DNS 锁定）
        $response = (new HttpClient())
            ->withOutboundGuard()
            ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; AaEeGateway/1.0)')
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8')
            ->timeout(30, 15)
            ->withRedirects()
            ->get($url);

        if (!$response->ok()) {
            throw new \RuntimeException('抓取失败，HTTP ' . $response->status());
        }

        $text = $this->htmlToText($response->body());
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . "\n…（已截断）";
        }

        return $text === '' ? '（页面无可用文本内容）' : $text;
    }

    /** 极简 HTML → 纯文本（去脚本/样式/标签，收敛空白） */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('#<script[\s\S]*?</script>#i', ' ', $html) ?? $html;
        $text = preg_replace('#<style[\s\S]*?</style>#i', ' ', $text) ?? $text;
        $text = preg_replace('#<[^>]+>#', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    // ── crossref ────────────────────────────────────────────────────
    private function crossref(string $query, string $field, int $rows): string
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('query 不能为空');
        }
        $rows = max(1, min(20, $rows));

        $field = strtolower($field);
        $base = 'https://api.crossref.org';

        if ($field === 'doi') {
            $url = $base . '/works/' . rawurlencode($query);
        } elseif ($field === 'issn') {
            $url = $base . '/journals/' . rawurlencode($query) . '/works';
        } else {
            $param = match ($field) {
                'title'  => 'query.title',
                'author' => 'query.author',
                default  => 'query',
            };
            $url = $base . '/works?' . http_build_query([$param => $query, 'rows' => $rows]);
        }

        $response = (new HttpClient())
            ->withHeader('User-Agent', 'AaEeGateway/1.0 (mailto:support@example.com)')
            ->timeout(30, 15)
            ->get($url);

        if (!$response->ok()) {
            throw new \RuntimeException('Crossref 请求失败，HTTP ' . $response->status());
        }

        $json = $response->json();
        $items = $field === 'doi'
            ? [$json['message'] ?? []]
            : ($json['message']['items'] ?? []);

        if (!is_array($items) || $items === []) {
            return '未检索到文献';
        }

        $lines = [];
        foreach (array_slice($items, 0, $rows) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = $item['title'][0] ?? '';
            $doi = $item['DOI'] ?? '';
            $authors = [];
            foreach (($item['author'] ?? []) as $a) {
                if (is_array($a)) {
                    $authors[] = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
                }
                if (count($authors) >= 5) {
                    break;
                }
            }
            $year = $item['issued']['date-parts'][0][0] ?? '';
            $journal = $item['container-title'][0] ?? '';
            $cited = $item['is-referenced-by-count'] ?? 0;

            $lines[] = sprintf(
                "《%s》\n  DOI: %s\n  作者: %s\n  年份: %s | 期刊: %s | 被引: %s",
                $title,
                $doi,
                $authors === [] ? '—' : implode(', ', $authors),
                $year,
                $journal === '' ? '—' : $journal,
                $cited
            );
        }

        return implode("\n\n", $lines);
    }

    // ── bing（免 Key 联网检索）──────────────────────────────────────
    /**
     * 直抓 Bing 搜索结果页并解析（移植 Solab 的 `Bing (Local)`）。
     *
     * 与 Tavily 的根本差异：**不需要任何 API Key / 第三方服务订阅**，
     * 所以「对话搜索」开箱即用。代价是解析依赖 Bing 的结果页结构：
     *   · 桌面 Chrome 的 UA + Accept-Language 请求 `/search?q=`；
     *   · 结果条目锚点 `li.b_algo`，标题取 `h2`，摘要取 `b_caption p` / `b_algoSlug`；
     *   · 结果链接多为 `bing.com/ck/a?...&u=a1<base64url>` 跳转包装，需解包成真实直链。
     *
     * 结构变化 / 被反爬时**按失败处理**（抛异常 → is_error）：
     * 「解析不出来」不等于「网上没有相关信息」，不能把两者混为一谈。
     */
    private function bing(string $query, int $maxResults, string $language): string
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('query 不能为空');
        }

        $maxResults = max(1, min(10, $maxResults));
        $language = trim($language) !== '' ? trim($language) : 'zh-CN,zh;q=0.9,en;q=0.8';

        $response = (new HttpClient())
            ->withHeader(
                'User-Agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            )
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            ->withHeader('Accept-Language', $language)
            ->timeout(20, 10)
            ->withRedirects()
            ->get('https://www.bing.com/search?q=' . rawurlencode($query));

        if (!$response->ok()) {
            throw new \RuntimeException('Bing 检索失败，HTTP ' . $response->status());
        }

        $items = $this->parseBingResults($response->body(), $maxResults);
        if ($items === []) {
            throw new \RuntimeException('Bing 未返回可解析的结果条目（可能触发反爬或结果页结构已变化），请稍后重试');
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = implode("\n", array_filter([
                '• ' . $item['title'],
                $item['snippet'] === '' ? null : '  ' . $item['snippet'],
                '  ' . $item['url'],
            ], static fn ($line) => $line !== null && $line !== ''));
        }

        return implode("\n\n", $lines);
    }

    /**
     * 解析 Bing 结果页：以 `li.b_algo` 为分隔锚点切块，再在块内取标题/链接/摘要。
     *
     * 用「切块」而不是整体正则，是因为单条结果内部还可能嵌套列表
     * （`</li>` 不再唯一），按下一个 `b_algo` 起点截断更稳。
     *
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function parseBingResults(string $html, int $limit): array
    {
        $matches = [];
        if (!preg_match_all('/<li[^>]*\bclass="[^"]*\bb_algo\b[^"]*"[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $anchors = $matches[0];
        $items = [];

        foreach ($anchors as $index => $anchor) {
            $start = $anchor[1] + strlen($anchor[0]);
            $end = $anchors[$index + 1][1] ?? strlen($html);
            $block = substr($html, $start, $end - $start);

            if (!preg_match('#<h2[^>]*>([\s\S]*?)</h2>#i', $block, $titleMatch)) {
                continue;
            }
            if (!preg_match('#<a[^>]*href="([^"]*)"#i', $titleMatch[1], $linkMatch)) {
                continue;
            }

            $title = $this->plainText($titleMatch[1]);
            $url = html_entity_decode($linkMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title'   => $title,
                'url'     => $this->unwrapBingRedirect($url),
                'snippet' => $this->bingSnippet($block),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** 单条结果的摘要：优先 `b_caption` 内的 `<p>`，其次 `b_algoSlug`，最后块内首个 `<p>`。 */
    private function bingSnippet(string $block): string
    {
        if (preg_match('#<[^>]*\bclass="[^"]*\bb_caption\b[^"]*"[^>]*>[\s\S]*?<p[^>]*>([\s\S]*?)</p>#i', $block, $m)) {
            return $this->plainText($m[1]);
        }
        if (preg_match('#<[^>]*\bclass="[^"]*\bb_algoSlug\b[^"]*"[^>]*>([\s\S]*?)</(?:div|p|span)>#i', $block, $m)) {
            return $this->plainText($m[1]);
        }
        if (preg_match('#<p[^>]*>([\s\S]*?)</p>#i', $block, $m)) {
            return $this->plainText($m[1]);
        }

        return '';
    }

    /**
     * 解包 Bing 的点击跳转：`bing.com/ck/a?...&u=a1<base64url>` → 目标直链。
     *
     * `u` 参数带 `a1` 前缀，其余是 base64url 编码的原始 URL（base64url 会把
     * `+`/`/` 换成 `-`/`_` 并去掉 `=` 填充，解码前必须还原）。解不开时原样返回，
     * 保证至少不丢链接。
     */
    private function unwrapBingRedirect(string $url): string
    {
        if (!preg_match('#^https?://(?:[\w-]+\.)?bing\.com/ck/a#i', $url)) {
            return $url;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        $payload = (string) ($params['u'] ?? '');
        if ($payload === '') {
            return $url;
        }

        if (str_starts_with($payload, 'a1')) {
            $payload = substr($payload, 2);
        }
        $payload = strtr($payload, '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding > 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || !preg_match('#^https?://#i', $decoded)) {
            return $url;
        }

        return $decoded;
    }

    /** 去标签 → 解实体 → 收敛空白（用于标题与摘要）。 */
    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    // ── tavily ──────────────────────────────────────────────────────
    private function tavily(string $query, int $maxResults, string $depth): string
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('query 不能为空');
        }

        $apiKey = (string) env('TAVILY_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('网关未配置 TAVILY_API_KEY');
        }

        $maxResults = max(1, min(10, $maxResults));
        $depth = $depth === 'advanced' ? 'advanced' : 'basic';

        $response = (new HttpClient())
            ->withBearer($apiKey)
            ->timeout(40, 15)
            ->postJson('https://api.tavily.com/search', [
                'query'        => $query,
                'search_depth' => $depth,
                'max_results'  => $maxResults,
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException('Tavily 请求失败，HTTP ' . $response->status());
        }

        $json = $response->json();
        if (!empty($json['answer'])) {
            $head = "摘要：" . $json['answer'] . "\n\n";
        } else {
            $head = '';
        }

        $lines = [];
        foreach (($json['results'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $lines[] = sprintf(
                "• %s\n  %s\n  %s",
                (string) ($r['title'] ?? ''),
                trim((string) ($r['content'] ?? '')),
                (string) ($r['url'] ?? '')
            );
        }

        return $head . ($lines === [] ? '未检索到结果' : implode("\n\n", $lines));
    }

    // ── schema 组装 ─────────────────────────────────────────────────
    private function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $name,
                'description' => $description,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $properties === [] ? new \stdClass() : $properties,
                    'required'   => $required,
                ],
            ],
        ];
    }
}
