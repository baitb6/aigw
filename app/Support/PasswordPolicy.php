<?php
/**
 * 密码安全策略唯一真源
 * ----------------------------------------------------------------------------
 * 「改密码需邮箱验证码」「开放找回密码」这两个开关，**实际生效**要同时满足：
 *      开关本身为 true   AND   SMTP 配置可用（MailService::ready()）
 *
 * 为什么必须抽成单一真源：
 *   这两处判定此前在 UserAuthController（后端闸门）与 SettingsController（后台
 *   回显）里各写了一份。一旦有人只改一处 —— 比如把「&& ready()」从后台回显里
 *   拿掉 —— 就会出现经典静默漂移：
 *        后台开关显示「开」、数据库里也是 1、
 *        但用户端永远不被要求验证码（因为闸门里 ready()=false 短路）
 *        且**没有任何地方报错**，管理员以为功能已启用。
 *   本类把「开关值 / 邮件就绪 / 最终是否生效 / 缺了什么」四件事绑在一起，
 *   任何一方要判定，都只能问这里，不能再自己复刻一份。
 *
 * ⚠️ 不做 CLI 豁免：这是业务策略，不是人机闸门。
 *    若 CLI 恒返回 false，自检脚本会永远走「免验证」分支 → 邮箱验证码那段
 *    代码在测试里一次都跑不到 → 测试全绿、线上全拦。要关掉它请改配置。
 */

declare(strict_types=1);

namespace App\Support;

use App\Repositories\GatewayRepository;

final class PasswordPolicy
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private MailService $mailService,
    ) {}

    /**
     * 邮件通道是否就绪。
     *
     * ⚠️ 直接委托 MailService::ready()，**绝不在这里复刻一份判定** ——
     *    复刻就是又造了一个真源，日后一处改了另一处没改，还是静默漂移。
     */
    public function mailReady(): bool
    {
        return $this->mailService->ready();
    }

    /** 开关原始值（与数据库一致，不受邮件通道影响） */
    public function changeVerifySwitchedOn(): bool
    {
        return (bool) $this->gatewayRepository->get('password_change_verify', true);
    }

    public function resetSwitchedOn(): bool
    {
        return (bool) $this->gatewayRepository->get('password_reset_enabled', true);
    }

    /** 改密码**实际**是否要求邮箱验证码 */
    public function changeVerifyEffective(): bool
    {
        return $this->changeVerifySwitchedOn() && $this->mailReady();
    }

    /** 找回密码**实际**是否开放 */
    public function resetEffective(): bool
    {
        return $this->resetSwitchedOn() && $this->mailReady();
    }

    /**
     * 邮件通道的缺口清单 —— 用人类可读的中文描述「为什么 ready() 为 false」。
     * 后台据此把静默漂移变成显式告警，而不是让管理员对着一个「开着」的开关发懵。
     *
     * @return list<string>
     */
    public function mailGaps(): array
    {
        $s = $this->gatewayRepository->current();
        $gaps = [];

        if (!(bool) ($s['mail_enabled'] ?? false)) {
            $gaps[] = '未开启「开启邮件服务」';
        }
        if (trim((string) ($s['mail_host'] ?? '')) === '') {
            $gaps[] = '未填写 SMTP 服务器地址';
        }
        if ((int) ($s['mail_port'] ?? 0) <= 0) {
            $gaps[] = 'SMTP 端口无效';
        }

        return $gaps;
    }

    /**
     * 后台告警快照：把「开关开着但邮件不通 → 不生效」的情形一次讲清。
     *
     * @return array{
     *   mail_ready:bool,
     *   mail_gaps:list<string>,
     *   password_change_verify:bool,
     *   password_change_verify_effective:bool,
     *   password_reset_enabled:bool,
     *   password_reset_enabled_effective:bool,
     *   password_verify_warning:string
     * }
     */
    public function snapshot(): array
    {
        $ready   = $this->mailReady();
        $gaps    = $this->mailGaps();
        $chgOn   = $this->changeVerifySwitchedOn();
        $rstOn   = $this->resetSwitchedOn();
        $chgEff  = $chgOn && $ready;
        $rstEff  = $rstOn && $ready;

        return [
            'mail_ready'                       => $ready,
            'mail_gaps'                        => $gaps,
            'password_change_verify'           => $chgOn,
            'password_change_verify_effective' => $chgEff,
            'password_reset_enabled'           => $rstOn,
            'password_reset_enabled_effective' => $rstEff,
            'password_verify_warning'          => $this->warningText($ready, $gaps, $chgOn, $rstOn),
        ];
    }

    /**
     * 告警文案（空串 = 一切正常，无需提示）。
     *
     * 只描述「开关开着却不生效」——若开关本身就是关的，那是管理员的明确意图，
     * 不该再弹警告（否则告警会被当噪声忽略，真出问题时反而看不见）。
     */
    private function warningText(bool $ready, array $gaps, bool $chgOn, bool $rstOn): string
    {
        if ($ready) {
            return '';
        }

        $affected = [];
        if ($chgOn) {
            $affected[] = '修改密码';
        }
        if ($rstOn) {
            $affected[] = '找回密码';
        }
        if ($affected === []) {
            return '';
        }

        return sprintf(
            '「%s」的开关当前是开启的，但邮件服务未配置完整（%s），因此**实际不会要求邮箱验证码**。'
            . '请先补全 SMTP 配置并保存，否则这两处入口会以「免验证」方式放行。',
            implode('」「', $affected),
            implode('；', $gaps)
        );
    }
}
