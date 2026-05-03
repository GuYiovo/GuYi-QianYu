
<div align="center">

# GuYi Access
**极致详细的开源软件卡密授权与验证基建引擎**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![Security](https://img.shields.io/badge/Security-AES--256--GCM-success.svg)]()
[![Languages](https://img.shields.io/badge/Support-30%2B_Languages-ff69b4.svg)]()
[![Chat](https://img.shields.io/badge/QQ群-1077643184-0088ff.svg)](https://qm.qq.com/q/X3suYdjWAA)

无论您使用 **C++** 编写原生工具，使用 **Flutter** 开发多端应用，还是用 **易语言** 编写脚本。<br>
GuYi Access 都为您准备了极其详尽的、开箱即用的对接源码。

<br>

🌐 **[官方网站 - 全球高速线路 (推荐)](https://official.可爱.top/)**
🌍 **[官方网站 - 海外备用线路](https://guyiovo.github.io/GuYi-Access-wed/)**
🏠 **[作者 GuYi 个人主页](https://可爱.top/)**

👨‍💻 **联系作者:** QQ 156440000 ｜ ✉️ **Email:** karacsonyerik594@gmail.com

</div>

---

## 📋 目录

- [✨ 核心特性](#-核心特性)
- [🛠️ 部署与安装](#️-部署与安装)
- [🚀 快速开始](#-快速开始)
- [💻 支持的生态矩阵](#-支持的生态矩阵)
- [💬 社区与支持](#-社区与支持)
- [📄 开源协议](#-开源协议)

## ✨ 核心特性

- 🔒 **军事级通信加密**：全栈标配 `AES-256-GCM` 认证加密，彻底杜绝中间人抓包篡改（如伪造到期时间）。
- 🌍 **全语言制霸**：提供 C/C++、Go、Rust、C#、Java、Python、Node.js、易语言、PHP、Flutter、Vue 等 30+ 主流与冷门语言的现成对接代码。
- ⚡ **极简高效 API**：单端点（Single Endpoint）设计，一个 POST 请求搞定验证、激活、绑定与数据拉取。
- 🛡️ **企业级高可用**：内置请求并发限制（防 CC/高频防刷）、云端心跳保活、硬件特征（机器码）全局云黑与无缝系统迁移功能。

## 🛠️ 部署与安装

### 环境要求
- **PHP**: ≥ 7.2
- **数据库**: MySQL (需启用 PDO 扩展)
- **扩展支持**: 需启用 JSON 扩展

### 安装步骤
1. **上传源码**：将本项目的所有文件上传至您的服务器网站根目录。
2. **设置权限**：确保网站根目录以及目录下的 `config.php`（若存在）具有可写入权限。
3. **运行安装向导**：在浏览器中访问您的域名安装路径，例如 `http://您的域名/install.php`。
4. **配置数据库**：根据页面提示，输入您的 MySQL 数据库连接信息，并设置后台系统的管理员账号密码。
5. **完成安装**：安装成功后，系统会自动销毁 `install.php` 以确保安全。接着您可以前往后台配置应用与卡密。

## 🚀 快速开始

### 1. 获取应用 AppKey

在完成上方的**服务端部署**后，请访问您自行搭建的后台管理系统并登录。在后台创建对应的应用后，即可获取您的应用专属 `64位 AppKey`。

> ⚠️ **安全警告**：AppKey 不仅用于应用识别，更是 AES-256-GCM 的解密密钥，请务必在您的客户端代码中进行混淆或加壳保护！

### 2. 客户端对接

我们为所有常用语言提供了即插即用的加密通信代码。请前往 [官方网站 API 文档区](https://official.可爱.top/#docs) 右侧的**代码演示面板**，选择您正在使用的编程语言，一键复制核心验证逻辑。

#### 接口概览

```http
POST /Verifyfile/api.php
Content-Type: application/json

{
  "app_key": "您的 64位十六进制密钥",
  "card_code": "用户输入的卡密",
  "device_hash": "可选的硬件机器码"
}
```

**响应示例：**

```json
{
  "status": "success",
  "message": "验证成功",
  "data": {
    "expire_time": "2026-12-31 23:59:59",
    "variables": {
      "update_url": "https://...",
      "notice": "欢迎使用！"
    }
  }
}
```

## 💻 支持的生态矩阵

GuYi Access 的设计初衷是为了打破语言壁垒。目前代码演示已涵盖（但不限于）以下开发环境：

| 系统/原生层 | 后端/微服务 | 前端/移动端 | 脚本/辅助 |
|-------------|-------------|-------------|-----------|
| C / C++     | Go          | Flutter / Dart| 易语言    |
| Rust        | Node.js     | Vue.js / React| Python    |
| C# / .NET   | Java (Spring)| Swift (iOS)  | Lua       |
| VB.NET      | PHP         | Kotlin (Android)| Shell   |

## 💬 社区与支持

遇到对接问题？需要定制化功能？或者想获取最新版本的更新推送？欢迎加入我们的技术生态交流群：

- **官方技术交流群**：1077643184
- **一键加群链接**：[👉 点击这里加入 QQ 群](https://qm.qq.com/q/X3suYdjWAA)
- **获取最新动态**：访问 [GuYi 个人官网](https://可爱.top/)

## 📄 开源协议

本项目采用 MIT License 开源协议。

您可以自由地将 GuYi Access 用于个人或商业项目中。在使用过程中，保留原作者版权信息是对开源精神最大的支持。

---

**Made with ♥ for Developers by GuYi.**
```