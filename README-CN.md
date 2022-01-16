<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>[Flutter/Vue/Angular/React/iOS/Android/* 或其他 *] 应用的完整解决方案</b>
    <br />
    <br />
</p>

<!-- [![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=friendly&color=90a88b&style=flat-square)](https://hacktoberfest.appwrite.io) -->
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Docker 拉](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite)
[![构建状态](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite)
[![Twitter 帐户](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)
[![翻译](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md)
[![赃物店](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io)

[**Appwrite 0.12 已发布！了解新功能!**](https://dev.to/appwrite/its-here-announcing-the-release-of-appwrite-012-5c8b)

Appwrite 是一个端到端的后端服务器，用于打包为一组 Docker 微服务的 Web、移动、本机或后端应用程序。 Appwrite 抽象了从头开始构建现代后端 API 所需的复杂性和重复性，并允许您更快地构建安全的应用程序。

使用 Appwrite，您可以轻松地将您的应用与用户身份验证和多种登录方法、用于存储和查询用户和团队数据的数据库、存储和文件管理、图像处理、云功能和 [更多服务]（https：/ /appwrite.io/docs）。

![Appwrite](public/images/github.png)

了解更多信息： [https://appwrite.io](https://appwrite.io)

内容：

- [安装](#安装)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [从旧版本升级](#从旧版本升级)
- [入门](#入门)
  - [软件服务](#软件服务)
  - [开发套件](#开发套件)
    - [最终用户](#最终用户)
    - [服务器](#服务器)
    - [社区](#社区)
- [建筑学](#建筑学)
- [贡献](#贡献)
- [安全](#安全)
- [跟着我们](#跟着我们)
- [执照](#执照)
      
## 安装

Appwrite 后端服务器设计为在容器环境中运行。运行服务器就像从终端运行命令一样简单。您可以使用 docker-compose 在本地主机上运行 Appwrite，也可以在任何其他容器编排工具（如 Kubernetes、Docker Swarm 或 Rancher）上运行 Appwrite。

开始运行 Appwrite 服务器的最简单方法是运行我们的 docker-compose 文件。在运行安装命令之前，请确保您的机器上安装了 [Docker](https://www.docker.com/products/docker-desktop)：

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:0.12.1
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:0.12.1
```

#### PowerShell

```powershell
docker run -it --rm ,
    --volume /var/run/docker.sock:/var/run/docker.sock ,
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw ,
    --entrypoint="install" ,
    appwrite/appwrite:0.12.1
```

安装 Docker 后，转到 http://localhost 从浏览器访问 Appwrite 控制台。请注意，在非 Linux 本机主机上，安装完成后服务器可能需要几分钟才能启动。


对于高级生产和自定义安装，请查看我们的 Docker [环境变量](https://appwrite.io/docs/environment-variables) 文档。您还可以使用我们的公共 [docker-compose.yml](https://gist.github.com/eldadfux/977869ff6bdd7312adfd4e629ee15cc5#file-docker-compose-yml) 文件手动设置环境。

### 从旧版本升级

如果您从旧版本升级 Appwrite 服务器，则应在设置完成后使用 Appwrite 迁移工具。有关这方面的更多信息，请查看 [安装文档](https://appwrite.io/docs/installation)。

## 入门

开始使用 Appwrite 就像创建一个新项目、选择您的平台并将其 SDK 集成到您的代码中一样简单。通过阅读我们的入门教程之一，您可以轻松地开始使用您选择的平台。

* [开始使用 Web](https://appwrite.io/docs/getting-started-for-web)
* [开始使用 Flutter](https://appwrite.io/docs/getting-started-for-flutter)
* [开始使用 Apple](https://appwrite.io/docs/getting-started-for-apple)
* [开始使用 Android](https://appwrite.io/docs/getting-started-for-android)
* [开始使用 Server](https://appwrite.io/docs/getting-started-for-server)
* [开始使用 CLI](https://appwrite.io/docs/command-line)

### 软件服务

* [**帐户**](https://appwrite.io/docs/client/account) -管理当前用户身份验证和帐户。跟踪和管理用户会话、设备、登录方法和安全日志。
* [**用户**](https://appwrite.io/docs/server/users) - 以管理员模式管理和列出所有项目用户。
* [**团队**](https://appwrite.io/docs/client/teams) - 管理和分组团队中的用户。管理团队中的成员资格、邀请和用户角色。
* [**数据库**](https://appwrite.io/docs/client/database) - 管理数据库集合和文档。使用高级过滤器来读取、创建、更新和删除文档并过滤文档集合列表。
* [**贮存**](https://appwrite.io/docs/client/storage) - 管理存储文件。阅读、创建、删除和预览文件。操纵文件的预览以完全适合您的应用程序。所有文件都由 ClamAV 扫描并安全存储和加密。
* [**功能**](https://appwrite.io/docs/server/functions) - 通过在安全、隔离的环境中执行您的自定义代码来自定义您的 Appwrite 服务器。您可以手动或使用 CRON 计划在任何 Appwrite 系统事件上触发您的代码。
* [**语言环境**](https://appwrite.io/docs/client/locale) - 跟踪用户的位置并根据应用程序的区域管理数据。
* [**阿凡达**](https://appwrite.io/docs/client/avatars) -管理用户头像、国家标志、浏览器图标、信用卡符号，并生成二维码。 
如需完整的 API 文档，请访问 [https://appwrite.io/docs](https://appwrite.io/docs)。如需更多教程、新闻和公告，请查看我们的 [博客](https://medium.com/appwrite-io) 和 [Discord 服务器](https://discord.gg/GSeTUeA)。

### 开发套件

以下是当前支持的平台和语言列表。如果您想帮助我们为您选择的平台添加支持，您可以访问我们的 [SDK 生成器](https://github.com/appwrite/sdk-generator) 项目并查看我们的 [贡献指南]( https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md）。

#### 最终用户
* ✅  &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (由 Appwrite 团队维护)
* ✅  &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (由 Appwrite 团队维护)
* ✅  &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **贝塔** (由 Appwrite 团队维护)
* ✅  &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (由 Appwrite 团队维护)

#### 服务器
* ✅  &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (由 Appwrite 团队维护)
* ✅  &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (由 Appwr实验 团队维护)
* ✅  &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (由 Appwrite 团队维护)
* ✅  &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **贝塔** (由 Appwrite 团队维护)
* ✅  &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (由 Appwrite 团队维护)
* ✅  &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (由 Appwrite 团队维护)
* ✅  &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **贝塔** (由 Appwrite 团队维护)
* ✅  &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **贝塔** (由 Appwrite 团队维护)
* ✅  &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **实验** (由 Appwrite 团队维护)

#### 社区
* ✅  &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appw实验) (维护者 [Michael Gangolf](https://github.com/m1ga/))  
* ✅  &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sd实验) (维护者 [fenix-hub @GodotNuts](https://github.com/fenix-hub))  

寻找更多 SDK？ - 通过向我们的提供拉取请求来帮助我们 [SDK 生成器](https://github.com/appwrite/sdk-generator)!


## 建筑学

![Appwrite 应用架构](docs/specs/overview.drawio.svg)

Appwrite 使用旨在轻松扩展和委派职责的微服务架构。此外，Appwrite 支持多种 API（即将推出的 REST、WebSocket 和 GraphQL），允许您使用现有知识和选择的协议与资源进行交互。

Appwrite API 层旨在通过利用内存缓存并将任何繁重的工作委派给 Appwrite 后台工作人员来实现极快的速度。后台工作人员还允许您使用消息队列来处理负载，并精确控制计算能力和成本。您可以在 [贡献指南](CONTRIBUTING.md#architecture-1) 中了解有关我们架构的更多信息。

## 贡献

所有代码贡献 - 包括来自具有提交访问权限的人的贡献 - 必须通过拉取请求并在合并之前得到核心开发人员的批准。这是为了确保正确审查所有代码。

我们真的很喜欢拉请求！如果您愿意提供帮助，可以在 [贡献指南](CONTRIBUTING.md) 中了解有关如何为项目做出贡献的更多信息。

## 安全

对于安全问题，请发送电子邮件至 [security@appwrite.io](mailto:security@appwrite.io)，而不是在 GitHub 上发布公开问题。

## 跟着我们

加入我们在世界各地不断发展的社区！请参阅我们的官方 [博客](https://medium.com/appwrite-io)。在 [Twitter](https://twitter.com/appwrite)、[Facebook 页面](https://www.facebook.com/appwrite.io)、[Facebook 群组](https://www.facebook) 上关注我们在 .com/groups/appwrite.developers/)、[开发者社区](https://dev.to/appwrite) 或加入我们的实时 [Discord 服务器](https://discord.gg/GSeTUeA) 以获得更多帮助，想法和讨论。

## 执照

此存储库在 [BSD 3-Clause License](./LICENSE) 下可用。