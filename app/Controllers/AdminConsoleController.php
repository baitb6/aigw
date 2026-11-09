<?php
/**
 * 管理后台入口
 * 直接吐出后台端 SPA 外壳（public/admin.html），与用户端（public/index.html）完全独立；
 * 页面的实际内容与数据全部由 /api/admin/* 提供。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class AdminConsoleController
{
    /** GET /admin —— 渲染管理后台外壳 */
    public function index(Request $request): Response
    {
        $file = AIGW_ROOT . '/public/admin.html';

        if (!is_file($file)) {
            return Response::text('管理后台入口文件缺失：public/admin.html', 500);
        }

        $html = (string) file_get_contents($file);

        return Response::html($html)->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }
}