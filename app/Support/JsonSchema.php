<?php
/**
 * JSON Schema 归一化：修复「JSON 空对象 {} 被降级成空数组 []」导致的 Schema 非法。
 *
 * ── 为什么需要它 ──────────────────────────────────────────────
 * PHP 的 `json_decode($raw, true)` 会把 JSON 对象一律转成关联数组。
 * 这一步对「非空对象」无损，但对**空对象 `{}` 是有损的** —— `{}` 与 `[]` 都变成 `[]`。
 * 请求在网关侧经历「解码 → 再编码」之后，`"properties": {}` 就被写成 `"properties": []`。
 *
 * 而 JSON Schema 规定 `properties` / `patternProperties` / `$defs` 等关键字的值
 * **必须是对象（map）**，写成数组即非法。上游会直接拒绝：
 *
 *   tool call failed: tools.16.function.parameters/properties: invalid JSON Schema;
 *   check the schema keywords and references ...
 *
 * 典型触发场景：客户端携带的某个工具**没有参数**，其 `parameters.properties`
 * 天然是空对象。客户端（WorkBuddy / CodeBuddy）的工具集里恰好有这么一个工具时，
 * 整次对话必然失败 —— 与账号、模型、内容都无关。
 *
 * ── 修复策略 ──────────────────────────────────────────────────
 * 按 JSON Schema 规范区分三类关键字，只把「本该是对象」的空数组还原成 stdClass
 * （json_encode 时空对象输出 `{}`、空数组输出 `[]`），其余数组**原样保留**：
 *   - 映射型（map）：properties / patternProperties / definitions / $defs / dependentSchemas
 *   - 模式型（schema | bool）：additionalProperties / items / not / if / then / else / contains ...
 *   - 列表型（schema[]）：allOf / anyOf / oneOf / prefixItems
 * `required` / `enum` / `type` 等真正的数组关键字不在上述名单里，因此不受影响。
 *
 * ── 第二类故障：`parameters` 缺顶层 `type: object`（code 11129）────────────
 * 另一个**独立**的报送形态，症状与上面完全不同，别混为一谈：
 *
 *   {"code":11129,"msg":"invalid function call parameters"}
 *
 * 触发条件是 `function.parameters` 是对象模式却**没写顶层 `type`**。
 * 实测（WorkBuddy / copilot.tencent.com，2026-09-30）：
 *   · parameters={"properties":{"a":{"type":"string"}}}  → 11129 失败
 *   · parameters={}                                       → 11129 失败
 *   · parameters={"type":"object","properties":{}}        → 成功
 *   · parameters={"type":"object"}                        → 成功（properties 可省）
 * 即：上游只认「顶层显式 type:object」，缺了就整单拒收 —— 与内容、账号、模型无关。
 *
 * 该函数是幂等的：对已归一化的 Schema 再跑一次结果不变。
 */

declare(strict_types=1);

namespace App\Support;

final class JsonSchema
{
    /** 值必须是「对象（映射）」的关键字：空数组需还原为空对象 */
    private const MAP_KEYWORDS = [
        'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas',
    ];

    /** 值是「子模式或布尔」的关键字：空数组需还原为空对象（空模式等价于 {}） */
    private const SCHEMA_KEYWORDS = [
        'additionalProperties', 'additionalItems', 'unevaluatedProperties', 'unevaluatedItems',
        'propertyNames', 'not', 'if', 'then', 'else', 'contains', 'items',
    ];

    /** 值是「模式数组」的关键字 */
    private const LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * 归一化一份 JSON Schema。
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public static function normalize(array $schema): array
    {
        return self::walkSchema($schema);
    }

    /**
     * 归一化「工具参数」——可能是数组（已解码的 Schema），也可能是 JSON 字符串。
     * 字符串形态（部分上游要求 parameters 为字符串）会被解出、归一化后重新编码。
     *
     * 两道修复叠加（缺一即被上游拒单）：
     *   ① 空对象 `{}` 被 PHP 降级成 `[]` → 还原成 `{}`（否则 `properties:[]` 非法）；
     *   ② 顶层缺 `type` → 补 `type:object`（否则 WorkBuddy 回 11129
     *      `invalid function call parameters`）。
     */
    public static function normalizeParameters(mixed $parameters): mixed
    {
        // stdClass 形态：部分调用方（或 json_decode 不带 true）直接给对象
        if ($parameters instanceof \stdClass) {
            $asArray = json_decode(json_encode($parameters), true);
            $asArray = is_array($asArray) ? $asArray : [];
            return self::objectSchema($asArray === [] ? [] : self::normalize($asArray));
        }

        if (is_array($parameters)) {
            // 整个 parameters 是空对象 {} 时，解码后为 []；它是一份空模式，应还原为 {}
            if ($parameters === []) {
                return self::objectSchema([]);
            }
            return self::withObjectType(self::normalize($parameters));
        }

        if (is_string($parameters) && $parameters !== '') {
            $decoded = json_decode($parameters, true);
            if (!is_array($decoded)) {
                return $parameters;
            }
            $fixed = self::objectSchema($decoded === [] ? [] : self::normalize($decoded));
            // 空模式补完 type 后是 {"type":"object"} 这类对象，json_encode 自然出对象
            $encoded = json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? $parameters : $encoded;
        }

        return $parameters;
    }

    /**
     * 给「工具参数模式」补顶层 `type: object`。
     *
     * 上游（WorkBuddy / copilot.tencent.com）要求 function.parameters **必须显式**
     * 声明顶层 `type`，否则整单拒收：
     *   `{"code":11129,"msg":"invalid function call parameters"}`
     * 客户端常省略它（把 parameters 直接写成 `{"properties":{...}}`），或写成空对象。
     *
     * 函数参数的顶层本身就只能是对象 —— 补 `type:object` 是**语义无损**的保守修复：
     * 已有显式 type 的一律不动（含 `type:["object","null"]` 这种联合写法）。
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private static function withObjectType(array $schema): array
    {
        if (!array_key_exists('type', $schema)) {
            // 补在最前，便于人工比对上游报文
            return ['type' => 'object'] + $schema;
        }

        // type 是数组（如 ["object","null"]）且已含 object → 视为已声明，不动
        return $schema;
    }

    /**
     * 空模式 → 带 `type:object` 的对象模式。
     *
     * @param array<string,mixed> $schema
     */
    private static function objectSchema(array $schema): \stdClass
    {
        $schema = self::withObjectType($schema);
        return (object) $schema;
    }

    /**
     * 归一化一整份 tools 列表（就地处理 function.parameters）。
     *
     * @param array<int,mixed> $tools
     * @return array<int,mixed>
     */
    public static function normalizeTools(array $tools): array
    {
        foreach ($tools as $i => $tool) {
            if (!is_array($tool)) {
                continue;
            }

            // 常见形态：{type:"function", function:{name,parameters}}
            if (isset($tool['function']) && is_array($tool['function'])) {
                if (array_key_exists('parameters', $tool['function'])) {
                    $tool['function']['parameters'] = self::normalizeParameters($tool['function']['parameters']);
                }
                $tools[$i] = $tool;
                continue;
            }

            // 少数客户端把 parameters 放在第一层（老式 functions 风格）
            if (array_key_exists('parameters', $tool)) {
                $tool['parameters'] = self::normalizeParameters($tool['parameters']);
                $tools[$i] = $tool;
            }
        }

        return $tools;
    }

    /**
     * 递归遍历一份「模式」节点，逐关键字归一化。
     *
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function walkSchema(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = self::walkKeyword((string) $key, $value);
        }

        return $out;
    }

    private static function walkKeyword(string $key, mixed $value): mixed
    {
        // ① 映射型：值必须是对象；空数组 → 空对象；非空则逐个当作子模式继续下探
        if (in_array($key, self::MAP_KEYWORDS, true)) {
            if (!is_array($value)) {
                return $value;
            }
            if ($value === []) {
                return new \stdClass();
            }
            $map = [];
            foreach ($value as $k => $sub) {
                $map[$k] = self::childSchema($sub);
            }
            return $map;
        }

        // ② 模式型：值是子模式或布尔；空数组 → 空对象
        if (in_array($key, self::SCHEMA_KEYWORDS, true)) {
            if (!is_array($value)) {
                return $value; // 布尔（如 additionalProperties:false）
            }
            if ($value === []) {
                return new \stdClass();
            }
            // items 允许是「模式数组」（元组写法），逐项展开
            if ($key === 'items' && array_is_list($value)) {
                return array_map(static fn($sub) => self::childSchema($sub), $value);
            }
            return self::walkSchema($value);
        }

        // ③ 列表型：值是模式数组，逐项下探（空数组是合法的"无模式"，保持 []）
        if (in_array($key, self::LIST_KEYWORDS, true)) {
            if (!is_array($value)) {
                return $value;
            }
            return array_map(static fn($sub) => self::childSchema($sub), $value);
        }

        // ④ 其余关键字：可能仍内嵌模式（自定义扩展、组合关键字等），保守下探但不改变空数组语义
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::walkKeyword((string) $k, $v);
            }
            return $out;
        }

        return $value;
    }

    /**
     * 「子模式位置」的值归一化。
     *
     * 与 ④ 分支的区别就在这里：**这个函数只在"值本来就该是一份 schema"的位置调用**
     * （`properties` 的每个 value、`anyOf/oneOf/allOf` 的每个元素、`items` 元组的每项）。
     * 因此空数组在此处必然是「空 schema `{}` 被 PHP 降级」的产物，可以安全还原成对象；
     * 而 `required: []`、`enum: []` 这类真正的空数组走不到这里，语义不受影响。
     *
     * ── 为什么必须单独处理（2026-09-30 实测）────────────────────────
     * 只处理「关键字本身为空」是不够的。真实报错形态是**嵌套层级**上的空 schema：
     *
     *   tools.14.function.parameters/properties/account/anyOf/1/properties/provider:
     *     invalid JSON Schema; check the schema keywords and references
     *
     * 即 `properties.account.anyOf[1].properties.provider` 的值是 `{}`（表示"任意类型"），
     * 解码后成了 `[]`。旧版 `walkSchema` 在 MAP 分支里对子节点直接递归，
     * `walkSchema([])` 返回的仍是 `[]` → 转发给上游 `"provider":[]` → 依然非法 → 整单拒收。
     * 症状是「所有模型都报错」——因为问题出在客户端 tools 结构上，与模型/账号完全无关。
     */
    private static function childSchema(mixed $sub): mixed
    {
        if (!is_array($sub)) {
            return $sub; // 布尔 schema（true/false）或标量，原样
        }
        if ($sub === []) {
            return new \stdClass(); // 空模式 {} —— 唯一能把 {} 与 [] 区分回来的地方
        }
        return self::walkSchema($sub);
    }
}
