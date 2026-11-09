/* ============================================================
   供应商品牌图标
   ------------------------------------------------------------
   每个内置供应商使用其自有官方品牌图标（本地静态资源）。
   路径：/assets/img/providers/<provider_key>.<ext>
   自定义 / 未知供应商回退到通用立方体图标（内联 SVG）。
   ============================================================ */
(function (global) {
    'use strict';

    function svg(body) {
        return 'data:image/svg+xml,' + encodeURIComponent(
            "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'>" + body + '<\/svg>'
        );
    }

    function bg(color) {
        return "<rect width='48' height='48' rx='12' fill='" + color + "'/>";
    }

    /* 通用图标（未知供应商回退）：立方体 */
    var MARK_CUBE = "<path d='M24 12l10 6v12l-10 6-10-6V18z' fill='none' stroke='#fff'" +
        " stroke-width='3' stroke-linejoin='round'/>" +
        "<path d='M24 12v24M14 18l10 6 10-6' fill='none' stroke='#fff' stroke-opacity='.6'" +
        " stroke-width='2.2' stroke-linejoin='round'/>";

    var DEFAULT_LOGO = svg(bg('#64748B') + MARK_CUBE);

    var BASE = '/assets/img/providers/';

    /* 资源版本号：官方 logo 更新后 +1，用于规避浏览器对图片的强缓存 */
    var VER = '3';

    /* provider_key → 官方 Logo 静态资源文件名 */
    var BRANDS = {
        trae:           'trae.svg',
        trae_intl:      'trae.svg',
        doubao:         'doubao.svg',
        loomy:          'loomy.png',
        codebuddy:      'codebuddy.svg',
        codebuddy_intl: 'codebuddy_intl.svg',
        antigravity:    'antigravity.png',
        zcode:          'zcode.svg',
        zcode_intl:     'zcode.svg',
        qoder:          'qoder.svg',
        qoder_intl:     'qoder.svg',
        xiaohuanxiong:  'xiaohuanxiong.png'
    };

    var LOGOS = {};
    Object.keys(BRANDS).forEach(function (key) {
        LOGOS[key] = BASE + BRANDS[key] + '?v=' + VER;
    });

    global.AigwLogos = {
        MAP: LOGOS,
        DEFAULT: DEFAULT_LOGO,
        /** 按 provider_key 取品牌图标；未知回退通用图标 */
        of: function (key) {
            var k = String(key || '').trim();
            if (LOGOS[k]) return LOGOS[k];
            if (k.indexOf('trae') === 0) return LOGOS.trae;
            if (k.indexOf('codebuddy') === 0) return LOGOS.codebuddy;
            if (k.indexOf('doubao') === 0) return LOGOS.doubao;
            if (k.indexOf('qoder') === 0) return LOGOS.qoder;
            return DEFAULT_LOGO;
        },
        has: function (key) { return !!LOGOS[String(key || '').trim()]; }
    };
})(window);