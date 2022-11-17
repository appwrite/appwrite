> Está indo para nuvem! 🌩 ☂️
> A nuvem do Appwrite estará disponível em breve! Você pode saber mais sobre nossa próxima solução hospedada e se inscrever para obter créditos gratuitos em: https://appwrite.io/cloud

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Uma solução de back-end completa para o seu aplicativo [Flutter / Vue / Angular / React / iOS / Android / *ANY OTHER*] </b>
    <br />
    <br />
</p>


<!-- [![Build Status](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Build Status](https://img.shields.io/github/workflow/status/appwrite/appwrite/Tests?label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Translate](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

English | [简体中文](README-CN.md)

[**Appwrite Versão 2.0 está disponível! Veja mais sobre!**](https://medium.com/appwrite-io/announcing-console-2-0-2e0e96891cb0?source=friends_link&sk=7a82b4069778e3adc165dc026e960fe1)

Appwrite é um servidor de back-end de ponta a ponta para aplicativos Web, móveis, nativos ou de back-end empacotados como um conjunto de Docker<nobr> microsserviços. Appwrite abstrai a complexidade e a repetitividade necessárias para criar uma API de back-end moderna do zero e permite que você crie aplicativos seguros mais rapidamente.

Usando Appwrite, você pode integrar facilmente seu aplicativo com autenticação de usuário e vários métodos de login, um banco de dados para armazenar e consultar dados de usuários e equipes, armazenamento e gerenciamento de arquivos, manipulação de imagens, Cloud Functions e[mais funções](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Encontre mais em: [https://appwrite.io](https://appwrite.io)

Índice:

- [Instalação](#Instalação)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Atualize de uma versão mais antiga](#atualize-de-uma-versão-mais-antiga)
- [Primeiros passos](#primeiros-passos)
  - [Funções](#funções)
  - [SDKs](#sdks)
    - [Cliente](#client)
    - [Server](#server)
    - [Comunidade](#community)
- [Arquitetura](#architecture)
- [Contribuição](#contributing)
- [Segurança](#security)
- [Nos siga](#follow-us)
- [Licença](#license)

## Instalação

O servidor de back-end do Appwrite  é projetado para rodar em um ambiente de contêiner. Executar seu servidor é tão fácil quanto executar um comando de seu terminal. Você pode executar o Appwrite em seu host local usando docker-compose ou em qualquer outra ferramenta de orquestração de contêiner, como Kubernetes, Docker Swarm ou Rancher.

A maneira mais fácil de executar seu servidor do Appwrite é executando nosso arquivo docker-compose. Antes de executar o comando de instalação, certifique-se de ter
[Docker](https://www.docker.com/products/docker-desktop) instalado em sua máquina:

### Unix
  
```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.1.0
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.1.0
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.1.0
```

Uma vez que a instalação do Docker estiver completa, vá para http://localhost para acessar o console do Appwrite a partir do seu navegador. Por favor note que em non-Linux hosts nativos, o servidor pode demorar alguns minutos para começar após a instalação.

Para produção avançada e instalação personalizada, verifique nossos documentos do Docker [environment variables](https://appwrite.io/docs/environment-variables). Você também pode usar nosso [docker-compose.yml](https://appwrite.io/install/compose) público e [.env](https://appwrite.io/install/env) arquivos para configurar manualmente um ambiente.

### Atualize de uma versão mais antiga

Se você está atualizando seu servidor do Appwrite de outra versão, voce deveria usar a ferramenta de migração do Appwrite assim que sua configuração estiver concluída. Para obter mais informações sobre isso, consulte o [Installation Docs](https://appwrite.io/docs/installation).

## Configurações com um clique

Além de executar o Appwrite localmente, você também pode lançar o Appwrite usando uma configuração pré-definida. Isso permite que você comece a trabalhar com o Appwrite rapidamente sem a instalação do Docker na sua máquina local.

Escolha um dos provedores abaixo:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="DigitalOcean Logo" />
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo" />
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Primeiros passos

Começando com o Appwrite é fácil como criar um novo projeto, escolhendo sua plataforma, e integrando o SDK em seu código. Você pode facilmente começar com sua plataforma de escolha lendo um de nossos tutorial de inicio.

- [Primeiros passos para Web](https://appwrite.io/docs/getting-started-for-web)
- [Primeiros passos para Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Primeiros passos para Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Primeiros passos para Android](https://appwrite.io/docs/getting-started-for-android)
- [Primeiros passos para Server](https://appwrite.io/docs/getting-started-for-server)
- [Primeiros passos para CLI](https://appwrite.io/docs/command-line)

### Funções

- [**Account**](https://appwrite.io/docs/client/account) - Manage current user authentication and account. Track and manage the user sessions, devices, sign-in methods, and security logs.
- [**Users**](https://appwrite.io/docs/server/users) - Manage and list all project users when building backend integrations with Server SDKs.
- [**Teams**](https://appwrite.io/docs/client/teams) - Manage and group users in teams. Manage memberships, invites, and user roles within a team.
- [**Databases**](https://appwrite.io/docs/client/databases) - Manage databases, collections and documents. Read, create, update, and delete documents and filter lists of document collections using advanced filters.
- [**Storage**](https://appwrite.io/docs/client/storage) - Manage storage files. Read, create, delete, and preview files. Manipulate the preview of your files to fit your app perfectly. All files are scanned by ClamAV and stored in a secure and encrypted way.
- [**Functions**](https://appwrite.io/docs/server/functions) - Customize your Appwrite server by executing your custom code in a secure, isolated environment. You can trigger your code on any Appwrite system event, manually or using a CRON schedule.
- [**Realtime**](https://appwrite.io/docs/realtime) - Listen to real-time events for any of your Appwrite services including users, storage, functions, databases and more.
- [**Locale**](https://appwrite.io/docs/client/locale) - Track your user's location, and manage your app locale-based data.
- [**Avatars**](https://appwrite.io/docs/client/avatars) - Manage your users' avatars, countries' flags, browser icons, credit card symbols, and generate QR codes.

Para uma documentação completa do API, visite [https://appwrite.io/docs](https://appwrite.io/docs). Para mais tutoriais, novidades e anúncios acesse nosso [blog](https://medium.com/appwrite-io) e [Servidor do Discord](https://discord.gg/GSeTUeA).

### SDKs

Below is a list of currently supported platforms and languages. If you wish to help us add support to your platform of choice, you can go over to our [SDK Generator](https://github.com/appwrite/sdk-generator) project and view our [contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Client

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Maintained by the Appwrite Team)

#### Server

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Maintained by the Appwrite Team)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Maintained by the Appwrite Team)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Maintained by the Appwrite Team)

#### Community

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Maintained by [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Maintained by [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Architecture

![Appwrite Architecture](docs/specs/overview.drawio.svg)

Appwrite uses a microservices architecture that was designed for easy scaling and delegation of responsibilities. In addition, Appwrite supports multiple APIs (REST, WebSocket, and GraphQL-soon) to allow you to interact with your resources by leveraging your existing knowledge and protocols of choice.

The Appwrite API layer was designed to be extremely fast by leveraging in-memory caching and delegating any heavy-lifting tasks to the Appwrite background workers. The background workers also allow you to precisely control your compute capacity and costs using a message queue to handle the load. You can learn more about our architecture in the [contribution guide](CONTRIBUTING.md#architecture-1).

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Security

For security issues, kindly email us at [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue on GitHub.

## Follow Us

Join our growing community around the world! See our official [Blog](https://medium.com/appwrite-io). Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/), [Dev Community](https://dev.to/appwrite) or join our live [Discord server](https://discord.gg/GSeTUeA) for more help, ideas, and discussions.

## License

This repository is available under the [BSD 3-Clause License](./LICENSE).
