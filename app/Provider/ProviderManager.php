<?php
/**
 * 供应商注册中心：把 provider_key 映射到具体适配器实例
 */

declare(strict_types=1);

namespace App\Provider;

use App\Core\Container;
use App\Repositories\ProviderRepository;
use RuntimeException;

class ProviderManager
{
    /** @var array<string, class-string<Provider>> */
    private array $builtins = [
        'trae'            => Providers\TraeProvider::class,
        'trae_intl'       => Providers\TraeProvider::class,
        'loomy'           => Providers\LoomyProvider::class,
        'codebuddy'       => Providers\CodeBuddyProvider::class,
        'codebuddy_intl'  => Providers\CodeBuddyProvider::class,
        'antigravity'     => Providers\AntigravityProvider::class,
        'doubao'          => Providers\DoubaoProvider::class,
        'zcode'           => Providers\ZcodeProvider::class,
        'zcode_intl'      => Providers\ZcodeProvider::class,
        'qoder'           => Providers\QoderProvider::class,
        'qoder_intl'      => Providers\QoderProvider::class,
        'xiaohuanxiong'   => Providers\XiaohuanxiongProvider::class,
        'custom'          => Providers\CustomProvider::class,
        // Agent2API 网关桥接：`agent2api` 聚合全部模型，
        // `agent2api_<provider>` 拆分到网关内的单个 provider（见 bin/agent2api-sync.php）
        'agent2api'       => Providers\Agent2ApiProvider::class,
    ];

    /** @var array<string, Provider> 以 provider_key（含区域后缀）为键 */
    private array $instances = [];

    /**
     * 已构造的「原型」实例，以类名为键。
     *
     * ⚠️ 不要把原型直接交出去：同一适配器类被多个 key 复用时（trae / trae_intl、
     * codebuddy / codebuddy_intl、zcode / zcode_intl、qoder / qoder_intl），两个 key
     * 拿到的会是**同一个对象**，`withKey()` 后注入者覆盖前者 —— 表现为
     * 「国内版被当成国际版」（请求打去国际域、授权链接跳到国际站、模型读国际表）。
     * 故原型只用于克隆，真正下发的是克隆体。
     *
     * @var array<class-string<Provider>, Provider>
     */
    private array $prototypes = [];

    public function __construct(
        private Container $container,
        private ProviderRepository $providerRepository,
    ) {}

    /**
     * 获取适配器；未知的自定义实例回退到 CustomProvider 并按 key 取配置
     *
     * ⚠️ 区域隔离（务必保留）：Container::make() 是**按类名**缓存的单例。
     * 同一适配器类服务多个 provider_key（trae / trae_intl 等）时，若直接返回
     * `make()` 的结果，两个 key 会共享同一实例，`withKey()` 互相覆盖 ——
     * 国内 key 取到的其实是国际适配器。
     * 因此这里**不入容器缓存**：自己 new 出独立实例，按 key 注入后再缓存。
     */
    public function get(string $key): Provider
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $class = $this->builtins[$key] ?? null;

        // Agent2API 多实例：`agent2api_<provider>` 复用同一适配器，
        // 由 withKey() 注入真实 key、再按 key 读各自的 options（base_url / upstream_provider）。
        if ($class === null && self::isAgent2ApiKey($key)) {
            $class = Providers\Agent2ApiProvider::class;
        }

        if ($class === null) {
            // 允许动态注册的自定义供应商实例（key 形如 custom_xxx）
            $config = $this->providerRepository->find($key);
            if ($config === null) {
                throw new RuntimeException("未知供应商：{$key}");
            }
            $class = Providers\CustomProvider::class;
        }

        // 每个 key 独立实例：从「原型」克隆，避免与容器内的同名单例共享。
        // 克隆仅复制依赖引用（仓储/容器均为无状态单例），区域状态由 withKey 重设，
        // 故不会串味；且避免了每个 key 重复反射装配构造函数。
        $instance = isset($this->prototypes[$class])
            ? clone $this->prototypes[$class]
            : $this->prototypes[$class] = $this->container->make($class);

        // 同一适配器多 key 复用（如 trae / trae_intl、codebuddy / codebuddy_intl）：注入实际 key 以区分区域
        if (method_exists($instance, 'withKey')) {
            $instance->withKey($key);
        }

        return $this->instances[$key] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->builtins[$key]) || self::isAgent2ApiKey($key) || $this->providerRepository->exists($key);
    }

    /**
     * 是否为 Agent2API 桥接 key（`agent2api` 或 `agent2api_xxx`）
     */
    private static function isAgent2ApiKey(string $key): bool
    {
        return $key === 'agent2api' || str_starts_with($key, 'agent2api_');
    }

    /** @return string[] 内置供应商 key */
    public function builtinKeys(): array
    {
        return array_keys($this->builtins);
    }

    /**
     * 注册新的供应商类型（扩展用）
     */
    public function register(string $key, string $class): void
    {
        $this->builtins[$key] = $class;
        unset($this->instances[$key]);
        unset($this->prototypes[$class]);
    }

    /**
     * 遍历所有已启用的适配器
     *
     * @return array<string, Provider>
     */
    public function enabled(): array
    {
        $out = [];
        foreach ($this->providerRepository->enabledKeys() as $key) {
            try {
                $out[$key] = $this->get($key);
            } catch (\Throwable) {
                continue;
            }
        }
        return $out;
    }
}