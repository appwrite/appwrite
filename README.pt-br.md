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
    - [Cliente](#cliente)
    - [Servidor](#servidor)
    - [Comunidade](#comunidade)
- [Arquitetura](#arquitetura)
- [Contribuição](#contribuição)
- [Segurança](#segurança)
- [Nos siga](#nos-siga)
- [Licença](#licença)

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

- [**Conta**](https://appwrite.io/docs/client/account) - Gerencie a autenticação e a conta do usuário atual. Rastreie e gerencie as sessões do usuário, dispositivos, métodos de login e logs de segurança.
- [**Usuários**](https://appwrite.io/docs/server/users) - Gerencie e liste todos os usuários do projeto ao criar integrações de back-end com o servidor SDK.
- [**Grupos**](https://appwrite.io/docs/client/teams) - Gerenciar e agrupar usuários em equipes. Gerencie associações, convites e funções de usuário dentro de uma equipe.
- [**Banco de Dados**](https://appwrite.io/docs/client/databases) - Gerenciar bancos de dados, coleções e documentos. Leia, crie, atualize e exclua documentos e filtre listas de coleções de documentos usando filtros avançados.
- [**Armazenamento**](https://appwrite.io/docs/client/storage) - Gerenciar arquivos de armazenamento. Leia, crie, exclua e visualize arquivos. Manipule a pré-visualização de seus arquivos para caber perfeitamente em seu aplicativo. Todos os arquivos são escaneados pelo ClamAV e armazenados de forma segura e criptografada.
- [**Funções**](https://appwrite.io/docs/server/functions) - Personalize seu servidor Appwrite executando seu código personalizado em um ambiente seguro e isolado. Você pode acionar seu código em qualquer evento do sistema Appwrite, manualmente ou usando uma programação CRON.
- [**Ao Vivo**](https://appwrite.io/docs/realtime) - Veja eventos em tempo real para qualquer um dos seus serviços Appwrite, incluindo usuários, armazenamento, funções, bancos de dados e muito mais.
- [**Localização**](https://appwrite.io/docs/client/locale) - Rastreie a localização do seu usuário e gerencie os dados baseados na localidade do seu aplicativo.
- [**Avatares**](https://appwrite.io/docs/client/avatars) - Gerencie os avatares de seus usuários, bandeiras de países, ícones de navegador, símbolos de cartão de crédito e gere códigos QR.

Para uma documentação completa do API, visite [https://appwrite.io/docs](https://appwrite.io/docs). Para mais tutoriais, novidades e anúncios acesse nosso [blog](https://medium.com/appwrite-io) e [Servidor do Discord](https://discord.gg/GSeTUeA).

### SDKs

Abaixo está uma lista de plataformas e idiomas atualmente suportados. Se você deseja nos ajudar a adicionar suporte à sua plataforma de escolha, acesse nosso [Gerador SDK](https://github.com/appwrite/sdk-generator), projete e veja nosso [guia de contribuição](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Mantido pela Equipe Appwrite)

#### Servidor

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Mantido pela Equipe Appwrite)Mantido pela Equipe Appwrite
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantido pela Equipe Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Mantido pela Equipe Appwrite)

#### Comunidade

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Mantido por [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Mantido por [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Buscando por mais SDKs? - Ajude-nos contribuindo com um pull request para o nosso [Gerador SDK](https://github.com/appwrite/sdk-generator)!

## Arquitetura

![Arquitetura do Appwrite](docs/specs/overview.drawio.svg)

Appwrite usa uma arquitetura de microsserviços projetada para facilitar o dimensionamento e a delegação de responsabilidades. Além disso, o Appwrite oferece suporte a várias APIs (REST, WebSocket e GraphQL-em breve) para permitir que você interaja com seus recursos, aproveitando seu conhecimento existente e os protocolos de sua escolha.

A camada da API do Appwrite foi projetada para ser extremamente rápida, aproveitando o cache na memória e delegando qualquer tarefa pesada aos trabalhadores de segundo plano do Appwrite. Os trabalhadores em segundo plano também permitem que você controle com precisão sua capacidade de computação e custos usando uma fila de mensagens para lidar com a carga. Você pode aprender mais sobre nossa arquitetura no [guia de contribuição](CONTRIBUTING.md#architecture-1).

## Contribuição

Todas as contribuições de código - incluindo aqueles de pessoas que têm acesso confirmado - deve passar por um pull request e ser aprovado por um desenvolvedor principal antes de ser mesclado. Isso é para garantir uma revisão adequada de todo o código.

Nós realmente ❤️ pull requests! Se você deseja ajudar, você pode aprender como contribuir com esse projeto no [guia de contribuição](CONTRIBUTING.md).

## Segurança

Por questões de segurança, envie-nos um e-mail para [security@appwrite.io](mailto:security@appwrite.io) em vez de postar um problema público no GitHub.

## Nos Siga

Junte-se à nossa crescente comunidade em todo o mundo! Veja nosso [Blog](https://medium.com/appwrite-io) oficial. Nos siga no [Twitter](https://twitter.com/appwrite), [página do Facebook](https://www.facebook.com/appwrite.io), [grupo do Facebook](https://www.facebook.com/groups/appwrite.developers/), [Comunidade Desenvolvedora](https://dev.to/appwrite) ou se junte ao nosso [servidor do Discord](https://discord.gg/GSeTUeA) para mais ajuda, idéias e discussões.

## Licença

Este repositório está disponível sob a [Licença de 3 Cláusulas BSD](./LICENSE).
