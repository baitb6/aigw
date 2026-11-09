# 第三方声明

> **文档导航**：[项目总览](README.md) · [安装](docs/installation.md) · [部署](docs/deployment.md) · [配置](docs/configuration.md) · [架构](docs/architecture.md) · [API](docs/api.md) · [供应商](docs/providers.md) · [开发](docs/development.md) · [发布说明](CHANGELOG.md) · [隐私说明](PRIVACY.md) · [许可证](LICENSE)

本文件列出本仓库中包含的第三方组件、商标素材及其许可条件，并说明
[AGPL-3.0](LICENSE) 的**授权范围**。

---

## 1. 许可证范围

本项目以 **GNU Affero General Public License v3.0（AGPL-3.0）** 授权，许可证全文见
[LICENSE](LICENSE)。

### 1.1 覆盖范围（AGPL-3.0）

以下内容均为本项目原创，按 AGPL-3.0 授权：

| 路径 | 内容 |
| --- | --- |
| `app/` | 控制器、服务、仓储、中间件、供应商适配器、核心容器与路由 |
| `bootstrap/`、`config/`、`routes/`、`bin/` | 启动、配置、路由、CLI 与 worker |
| `database/` | `schema.sql` 与安装脚本 |
| `public/`（除 `assets/vendor/`） | 入口、控制台 SPA 脚本与样式、图标、供应商 Logo 引用 |
| `deploy/` | Nginx / Apache / PHP-FPM / php.ini / crontab 模板 |
| `docs/`、根目录 Markdown | 文档 |
| 根目录脚本与配置 | `.htaccess`、`.env.example`、`.gitignore` 等 |

### 1.2 不覆盖范围

| 内容 | 许可 | 说明 |
| --- | --- | --- |
| `public/assets/vendor/vue.global.prod.js` | **MIT** | Vue.js 运行时，版权归 Vue 作者所有，见 §2 |
| `public/assets/img/providers/*` | **商标素材，非开源授权** | 供应商名称与 Logo 归各权利方所有，仅用于标识对应服务，见 §3 |
| PHP 扩展与系统库 | 各自许可 | 未随仓库分发，见 §4 |
| 依赖的操作系统组件 | 各自许可 | 如 Nginx、MySQL、PHP-FPM |

### 1.3 关键义务（AGPL-3.0 §13）

- 你可以在内部或对外提供服务的方式运行本软件，包括修改后运行。
- 若你**修改**本软件并通过**网络**向用户提供交互式服务，必须向这些用户提供
  获取对应**完整源码**的方式（通常是在页脚放置「源码」链接）。
- 分发时必须保留版权与许可声明，并以 AGPL-3.0 授权你的修改版本。
- 本软件**不提供任何担保**；作者不对使用后果承担责任。
- 未授予任何商标使用权（见 §3）。
- 本项目不附加 AGPL-3.0 之外的额外限制。

> [!NOTE]
> AGPL 的「网络使用」条款比 GPL 更进一步：**只要对外提供 Web 服务**就可能触发源码提供义务。
> 商业使用本身不被禁止，但必须履行上述义务。若无法接受，请勿使用本软件。

---

## 2. Vue.js

- 文件：`public/assets/vendor/vue.global.prod.js`（Vue 3 global production build）
- 用途：控制台 SPA 的响应式运行时
- 许可：MIT License
- 版权：Copyright (c) 2018-present, Yuxi (Evan) You and Vue contributors
- 项目主页：<https://github.com/vuejs/core>

```text
MIT License

Copyright (c) 2018-present, Yuxi (Evan) You and Vue contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

控制台的其余脚本（`public/assets/js/*`）、样式（`public/assets/css/app.css`）与图标
（`public/assets/js/icons.js`）均为本项目原创，不含第三方库。

---

## 3. 供应商名称与 Logo

`public/assets/img/providers/` 下的图标以及界面中出现的供应商名称，用于**标识**
对应的第三方 AI 服务，方便用户识别所接入的上游：

| 标识 | 对应服务 |
| --- | --- |
| Trae / trae_intl | Trae（国际版为 trae_intl） |
| WorkBuddy / codebuddy_intl | WorkBuddy / CodeBuddy |
| Antigravity | Google Antigravity |
| 豆包 | 豆包（Doubao） |
| ZCode / zcode_intl | ZCode |
| Qoder / qoder_intl | Qoder |
| 商汤小浣熊 | 商汤科技「小浣熊」 |
| Loomy | Loomy |

声明：

1. 上述名称、Logo 与商标归**各自权利方**所有，本项目**不主张**任何所有权。
2. 使用方式仅为**指示性使用**（说明兼容或可接入的服务），不代表任何形式的
   **合作、授权、背书或赞助关系**。
3. 这些素材**不在 AGPL-3.0 的授权范围内**，你分发衍生版本时应自行评估商标使用风险，
   或在必要时替换为自有素材。
4. 若你是权利方并认为使用不当，可通过 [反馈群](README.md#反馈与交流) 联系我们，
   我们会在核实后调整或移除相关素材。

---

## 4. PHP 扩展与系统组件

本项目**不使用 Composer**，运行时不下载任何 PHP 包。运行环境依赖以下系统组件，
它们**不随本仓库分发**，各自适用其原有许可：

| 组件 | 用途 | 许可（参考） |
| --- | --- | --- |
| PHP 8.2+ | 运行时 | PHP License 3.01 |
| OpenSSL 扩展 | AES-256-GCM 加解密、HTTPS | Apache License 2.0 |
| cURL 扩展 | 调用上游 API | curl License（MIT 类） |
| PDO_MySQL | 数据库访问 | PHP License / MySQL 客户端库许可 |
| mbstring | 多字节字符串处理 | PHP License（libmbfl 部分为 BSD 类） |
| GD | 验证码图片 | libgd（BSD 类） |
| Nginx / Apache | Web 服务器 | BSD 类 / Apache License 2.0 |
| MySQL 8.0 | 数据库 | GPL-2.0（社区版） |

字体使用系统字体栈，**未打包任何 Web 字体文件**。

---

## 5. 参考资料

文档中的协议与接口说明参考了以下公开资料，仅作描述用途，不包含其代码：

- OpenAI API 参考（Chat Completions / Responses / Models）
- 各供应商公开的登录与 API 文档

---

## 6. 免责声明

本软件按「现状」提供，不附带任何明示或暗示的担保。使用者应自行评估：
上游服务条款、账号使用规则、数据合规与所在司法辖区的法律要求。
因使用本软件产生的账号封禁、费用、数据损失或法律责任，由使用者自行承担。

---

## 相关文档

- [许可证全文（AGPL-3.0）](LICENSE)
- [隐私说明](PRIVACY.md)
- [发布说明](CHANGELOG.md)
- [供应商适配](docs/providers.md) —— 各上游的登录方式与能力
- [文档中心](docs/README.md)
