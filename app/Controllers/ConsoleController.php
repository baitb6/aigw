<?php
/**
 * 站点入口
 *  - GET /         → 渲染站点首页（public/home.html）
 *  - GET /console  → 渲染用户端控制台外壳（public/index.html）
 * 控制台页面的实际内容与数据全部由 /api/user/* 提供。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class ConsoleController
{
    /** GET /console —— 用户端控制台外壳 */
    public function index(Request $request): Response
    {
        return $this->serve('/public/index.html', '控制台入口文件缺失：public/index.html');
    }

    /** GET / —— 站点首页（营销落地页） */
    public function home(Request $request): Response
    {
        return $this->serve('/public/home.html', '首页文件缺失：public/home.html');
    }

    /** GET /status —— 公开状态页（数据由 /v1/status 提供） */
    public function status(Request $request): Response
    {
        return $this->serve('/public/status.html', '状态页文件缺失：public/status.html');
    }

    /** 读取并返回静态 HTML（禁用缓存，便于随版本更新） */
    private function serve(string $relative, string $missingMessage): Response
    {
        $file = AIGW_ROOT . $relative;

        if (!is_file($file)) {
            return Response::text($missingMessage, 500);
        }

        $html = (string) file_get_contents($file);

        return Response::html($html)->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }
}