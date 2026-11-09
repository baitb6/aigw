<?php
/**
 * 自托管图形验证码（位图 PNG）
 * ------------------------------------------------------------
 * 历史坑（2026-10-02 修复）：
 *   旧实现用「纯文本拼 SVG」返回图片，SVG 里的 `<text>` 节点**直接带着明文答案**，
 *   攻击者只要 `GET /api/captcha` 再来一条正则 `/>([A-Z0-9])<\/text>/` 就能 100%
 *   还原答案 —— 图形验证码等于没有，注册接口可被脚本无脑刷。
 *   本类改为**服务端光栅化**：答案只留服务端，响应体是像素，零明文。
 *
 * 渲染优先级：
 *   1. GD 扩展（php_gd）—— 有 FreeType 时用 TTF 真字体 + 旋转扭曲；无 FreeType 时
 *      用内置点阵字体；两者都输出 PNG。
 *   2. 无 GD 时 —— 纯 PHP 手写 PNG 编码器 + 内置 5x7 点阵字库，逐像素画线/描点，
 *      保证「线上没装 gd 也不会退化成可正则的明文」。
 *
 * 安全设计：
 *   - 答案只写 session（响应体不含任何明文），记录过期时间
 *   - 校验成功后立即销毁（一次性），防重放
 *   - 校验用 hash_equals 常量时间比较
 *   - 干扰线 / 噪点 / 逐字符旋转 / 随机基线，抬高 OCR 成本
 *   - 取图与校验分别由 CaptchaGuard 做 IP 维度限流（见该类）
 */

declare(strict_types=1);

namespace App\Support;

use App\Middleware\AdminAuthMiddleware;
use App\Middleware\UserAuthMiddleware;

class Captcha
{
    public const SESSION_KEY = 'aigw_captcha';
    public const TTL = 300;

    /** 去掉了易混字符（0/O、1/I、2/Z），降低正常用户误判 */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 图像尺寸（CSS 会再放大显示，位图本身给足清晰度） */
    private const WIDTH = 160;
    private const HEIGHT = 52;

    /**
     * 生成一组新验证码：写入 session 并返回 PNG 二进制
     *
     * @return array{code:string, png:string}
     */
    public static function make(int $length = 4): array
    {
        self::bootSession();

        $length = max(3, min(6, $length));
        $code = self::randomCode($length);
        $_SESSION[self::SESSION_KEY] = [
            'code' => $code,
            'exp'  => time() + self::TTL,
        ];

        return ['code' => $code, 'png' => self::renderPng($code)];
    }

    /** 只生成图片（不关心明文，供控制器用） */
    public static function makePng(int $length = 4): string
    {
        return self::make($length)['png'];
    }

    /**
     * 兼容旧调用名：历史上返回 SVG 字符串，现改为返回 PNG 二进制。
     * 控制器已改走 makePng()，这里保留只为不打断外部脚本。
     */
    public static function makeSvg(int $length = 4): string
    {
        return self::makePng($length);
    }

    /**
     * 校验用户输入，一次性消费
     */
    public static function verify(?string $input): bool
    {
        self::bootSession();

        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($stored) || !isset($stored['code'], $stored['exp'])) {
            return false;
        }
        if ((int) $stored['exp'] < time()) {
            return false;
        }

        $input = strtoupper(trim((string) $input));
        if ($input === '') {
            return false;
        }

        return hash_equals(strtoupper((string) $stored['code']), $input);
    }

    private static function randomCode(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /* ── 渲染 ─────────────────────────────────────────────── */

    private static function renderPng(string $code): string
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $png = self::renderGd($code);
            if ($png !== null) {
                return $png;
            }
        }

        return self::renderPure($code);
    }

    /** GD 路径：真彩色 + 抗锯齿 + 可选 TTF 旋转 */
    private static function renderGd(string $code): ?string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        $img = @imagecreatetruecolor($w, $h);
        if ($img === false) {
            return null;
        }

        imageantialias($img, true);

        $bg = imagecolorallocate($img, 242, 244, 248);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // 轻柔渐变底纹 + 噪点：干扰基于「纯色背景」的阈值二值化
        for ($i = 0; $i < 220; $i++) {
            $c = imagecolorallocate($img, random_int(200, 238), random_int(200, 238), random_int(200, 238));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        // 干扰弧线（比直线更难被 Hough 变换剥离）
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($img, random_int(120, 190), random_int(120, 190), random_int(120, 190));
            $cx = random_int(-20, $w + 20);
            $cy = random_int(-20, $h + 20);
            $rw = random_int(intdiv($w, 3), $w);
            $rh = random_int(intdiv($h, 3), $h);
            $a1 = random_int(0, 360);
            imagearc($img, $cx, $cy, $rw, $rh, $a1, $a1 + random_int(60, 280), $c);
        }

        $len  = strlen($code);
        $slot = (int) floor(($w - 18) / max(1, $len));
        $font = self::fontFile();
        $useTtf = $font !== null && function_exists('imagettftext');

        for ($i = 0; $i < $len; $i++) {
            $ch   = $code[$i];
            $x    = 12 + $i * $slot;
            $y    = random_int((int) ($h * 0.60), (int) ($h * 0.82));
            $size = random_int(22, 28);
            $ang  = random_int(-26, 26);
            $c    = imagecolorallocate($img, random_int(20, 90), random_int(20, 90), random_int(20, 90));

            if ($useTtf) {
                @imagettftext($img, $size, $ang, $x, $y, $c, $font, $ch);
            } else {
                // 内置点阵字：字号放大 + 轻微抖动
                $px = random_int(16, 22);
                imagestring($img, 5, $x + random_int(-2, 2), $y - $px, $ch, $c);
            }
        }

        // 前景噪点覆盖字缝（放在字之后，避免破坏可读性）
        for ($i = 0; $i < 120; $i++) {
            $c = imagecolorallocate($img, random_int(140, 210), random_int(140, 210), random_int(140, 210));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        // 外框（轻微圆角观感由前端 CSS 负责，这里只描边）
        $border = imagecolorallocate($img, 210, 215, 224);
        imagerectangle($img, 0, 0, $w - 1, $h - 1, $border);

        ob_start();
        $ok = @imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return ($ok && $png !== '') ? $png : null;
    }

    /**
     * 找一个可用的 TTF。优先项目自带字体（可随包部署），再退系统字体。
     * 找不到就返回 null，走内置点阵。
     */
    private static function fontFile(): ?string
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }

        $candidates = [
            AIGW_ROOT . '/storage/fonts/captcha.ttf',
            AIGW_ROOT . '/storage/fonts/DejaVuSans-Bold.ttf',
        ];
        // 常见系统路径（Windows / Linux）
        foreach ([
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ] as $p) {
            $candidates[] = $p;
        }

        foreach ($candidates as $p) {
            if (is_file($p) && is_readable($p)) {
                return $cached = $p;
            }
        }

        return $cached = null;
    }

    /* ── 纯 PHP PNG 回退（无 GD 环境）─────────────────────── */

    /**
     * 手写 PNG：IHDR + IDAT(zlib) + IEND，8bit 灰度图。
     * 用内置 5x7 点阵字库逐字描点，外接干扰线，答案同样不出现在响应里。
     */
    private static function renderPure(string $code): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        // 画布：255 = 白底，0 = 黑字
        $px = array_fill(0, $w * $h, 255);

        $put = static function (int $x, int $y, int $v) use (&$px, $w, $h): void {
            if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
                $px[$y * $w + $x] = $v;
            }
        };

        // 底噪
        for ($i = 0; $i < 2600; $i++) {
            $put(random_int(0, $w - 1), random_int(0, $h - 1), random_int(215, 245));
        }
        // 干扰线
        for ($i = 0; $i < 6; $i++) {
            self::drawLine(
                $put,
                random_int(0, $w - 1),
                random_int(0, $h - 1),
                random_int(0, $w - 1),
                random_int(0, $h - 1),
                random_int(150, 195)
            );
        }

        $len  = strlen($code);
        $slot = (int) floor(($w - 18) / max(1, $len));

        for ($i = 0; $i < $len; $i++) {
            $ch = $code[$i];
            $bitmap = self::glyph($ch);
            if ($bitmap === null) {
                continue;
            }

            $scale  = random_int(4, 5);
            $ox     = 12 + $i * $slot + random_int(0, 3);
            $oy     = random_int(6, 14);

            foreach ($bitmap as $ry => $row) {
                $rowLen = strlen($row);
                for ($cxi = 0; $cxi < $rowLen; $cxi++) {
                    if ($row[$cxi] !== '1') {
                        continue;
                    }
                    $bx = $ox + $cxi * $scale;
                    $by = $oy + $ry * $scale;
                    for ($dy = 0; $dy < $scale; $dy++) {
                        for ($dx = 0; $dx < $scale; $dx++) {
                            $put($bx + $dx, $by + $dy, random_int(10, 60));
                        }
                    }
                }
            }
        }

        // 前噪
        for ($i = 0; $i < 1200; $i++) {
            $put(random_int(0, $w - 1), random_int(0, $h - 1), random_int(160, 215));
        }

        return self::encodePngGray($px, $w, $h);
    }

    /** @param callable(int,int,int):void $put */
    private static function drawLine(callable $put, int $x1, int $y1, int $x2, int $y2, int $v): void
    {
        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx - $dy;

        while (true) {
            $put($x1, $y1, $v);
            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 > -$dy) {
                $err -= $dy;
                $x1 += $sx;
            }
            if ($e2 < $dx) {
                $err += $dx;
                $y1 += $sy;
            }
        }
    }

    /**
     * 5x7 点阵字库（只覆盖 ALPHABET 里的字符）。
     * '1' = 落笔，'0' = 留白。
     *
     * @return string[]|null
     */
    private static function glyph(string $ch): ?array
    {
        static $font = [
            'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
            'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
            'C' => ['01110', '10001', '10000', '10000', '10000', '10001', '01110'],
            'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
            'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
            'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
            'G' => ['01110', '10001', '10000', '10111', '10001', '10001', '01111'],
            'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
            'J' => ['00111', '00010', '00010', '00010', '00010', '10010', '01100'],
            'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
            'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
            'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
            'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
            'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
            'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
            'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
            'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
            'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
            'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
            'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
            'W' => ['10001', '10001', '10001', '10101', '10101', '11011', '10001'],
            'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
            'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
            '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
            '3' => ['11111', '00010', '00100', '00010', '00001', '10001', '01110'],
            '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
            '5' => ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
            '6' => ['00110', '01000', '10000', '11110', '10001', '10001', '01110'],
            '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
            '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
            '9' => ['01110', '10001', '10001', '01111', '00001', '00010', '01100'],
        ];

        return $font[$ch] ?? null;
    }

    /**
     * 最小 PNG 编码器（8bit 灰度，filter=0）。
     * 纯 PHP，无任何扩展依赖。
     *
     * @param int[] $px 灰度像素，0-255
     */
    private static function encodePngGray(array $px, int $w, int $h): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\x00"; // 每行 filter type = None
            $offset = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $raw .= chr($px[$offset + $x] & 0xFF);
            }
        }

        $ihdr = pack('N2', $w, $h) . "\x08\x00\x00\x00\x00"; // 8bit, 灰度, 无隔行

        $signature = "\x89PNG\r\n\x1a\n";

        return $signature
            . self::pngChunk('IHDR', $ihdr)
            . self::pngChunk('IDAT', gzcompress($raw, 6))
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        $crc = crc32($type . $data);
        return pack('N', strlen($data)) . $type . $data . pack('N', $crc & 0xFFFFFFFF);
    }

    /**
     * 会话引导：两个域的中间件都会开 session，取其一即可。
     * CLI / 已输出响应体的场景下中间件会 no-op，此时直接使用 $_SESSION 数组语义。
     */
    private static function bootSession(): void
    {
        AdminAuthMiddleware::bootSession();
        UserAuthMiddleware::bootSession();
    }
}
