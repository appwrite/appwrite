> Vai ficar nublado! 🌩 ☂️
> A Appwrite Cloud está chegando! Você pode saber mais sobre nossa próxima solução hospedada e se inscrever para créditos gratuitos em: https://appwrite.io/cloud

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Uma solução de back-end completa para o seu aplicativo [Flutter / Vue / Angular / React / iOS / Android / *ANY OTHER*]</b>
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

[English](README.md) | [简体中文](README-CN.md) | Brazilian Portuguese

[**Appwrite 1.0 foi lançado! Saiba o que há de novo!**](https://appwrite.io/1.0)

Appwrite é um servidor de back-end de ponta a ponta para aplicativos Web, Mobile, Nativos ou Back-end empacotados como um conjunto de microsserviços Docker<nobr>. O Appwrite abstrai a complexidade e a repetitividade necessárias para criar uma API de back-end moderna do zero e permite que você crie aplicativos seguros mais rapidamente.

Usando o Appwrite, você pode integrar facilmente seu aplicativo com autenticação de usuário e vários métodos de login, um banco de dados para armazenar e consultar usuários e dados de equipe, armazenamento e gerenciamento de arquivos, manipulação de imagens, Cloud Functions e [mais serviços](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Saiba mais em: [https://appwrite.io](https://appwrite.io)

Índice:

- [Instalação](#installation)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Atualizar de uma versão mais antiga](#upgrade-from-an-older-version)
- [Começando](#getting-started)
  - [Serviços](#services)
  - [SDKs](#sdks)
    - [Cliente](#client)
    - [Servidor](#server)
    - [Comunidade](#community)
- [Arquitetura](#architecture)
- [Contribuindo](#contributing)
- [Segurança](#security)
- [Siga-nos](#follow-us)
- [Licença](#license)

## Instalação

O servidor de back-end Appwrite foi projetado para ser executado em um ambiente de contêiner. Executar seu servidor é tão fácil quanto executar um comando do seu terminal. Você pode executar o Appwrite em seu localhost usando docker-compose ou em qualquer outra ferramenta de orquestração de contêiner, como Kubernetes, Docker Swarm ou Rancher.

A maneira mais fácil de começar a executar seu servidor Appwrite é executando nosso arquivo docker-compose. Antes de executar o comando de instalação, certifique-se de ter o [Docker](https://www.docker.com/products/docker-desktop) instalado em sua máquina:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.0.2
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.0.2
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.0.2
```

Depois que a instalação do Docker for concluída, vá para http://localhost para acessar o console do Appwrite em seu navegador. Observe que em hosts nativos não Linux, o servidor pode levar alguns minutos para iniciar após a conclusão da instalação.

Para produção avançada e instalação personalizada, confira nossa documentação [variáveis de ambiente](https://appwrite.io/docs/environment-variables) do Docker. Você também pode usar nossos arquivos públicos [docker-compose.yml](https://appwrite.io/install/compose) e [.env](https://appwrite.io/install/env) para configurar manualmente um ambiente.

### Atualizar de uma versão mais antiga

Se você estiver atualizando seu servidor Appwrite de uma versão mais antiga, deverá usar a ferramenta de migração Appwrite assim que a configuração for concluída. Para obter mais informações sobre isso, confira os [documentos de instalação](https://appwrite.io/docs/installation).

## Configurações de um clique

Além de executar o Appwrite localmente, você também pode iniciar o Appwrite usando uma configuração pré-configurada. Isso permite que você comece a usar o Appwrite rapidamente sem instalar o Docker em sua máquina local.

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

## Começando

Começar com o Appwrite é tão fácil quanto criar um novo projeto, escolher sua plataforma e integrar seu SDK em seu código. Você pode começar facilmente com sua plataforma de escolha lendo um de nossos tutoriais de introdução.

- [Começando para Web](https://appwrite.io/docs/getting-started-for-web)
- [Começando para Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Começando para Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Começando para Android](https://appwrite.io/docs/getting-started-for-android)
- [Começando para Server](https://appwrite.io/docs/getting-started-for-server)
- [Começando para CLI](https://appwrite.io/docs/command-line)

### Serviços

- [**Conta**](https://appwrite.io/docs/client/account) - Gerenciar a autenticação e a conta do usuário atual. Acompanhe e gerencie as sessões do usuário, dispositivos, métodos de login e logs de segurança.
- [**Usuários**](https://appwrite.io/docs/server/users) - Gerencie e liste todos os usuários do projeto quando estiver no modo admin.
- [**Equipes**](https://appwrite.io/docs/client/teams) - Gerenciar e agrupar usuários em equipes. Gerencie associações, convites e funções de usuário em uma equipe.
- [**Bancos de dados**](https://appwrite.io/docs/client/databases) - Gerenciar bancos de dados, coleções e documentos. Leia, crie, atualize e exclua documentos e filtre listas de coleções de documentos usando filtros avançados.
- [**Armazenamento**](https://appwrite.io/docs/client/storage) - Gerenciar arquivos de armazenamento. Leia, crie, exclua e visualize arquivos. Manipule a visualização de seus arquivos para se adequar perfeitamente ao seu aplicativo. Todos os arquivos são verificados pelo ClamAV e armazenados de forma segura e criptografada.
- [**Funções**](https://appwrite.io/docs/server/functions) - Personalize seu servidor Appwrite executando seu código personalizado em um ambiente seguro e isolado. Você pode acionar seu código em qualquer evento do sistema Appwrite, manualmente ou usando uma programação CRON.
- [**Tempo real**](https://appwrite.io/docs/realtime) - Visualize eventos em tempo real para qualquer um dos seus serviços Appwrite, incluindo usuários, armazenamento, funções, bancos de dados e muito mais.
- [**Local**](https://appwrite.io/docs/client/locale) - Rastreie a localização do seu usuário e gerencie os dados baseados na localidade do seu aplicativo.
- [**Avatares**](https://appwrite.io/docs/client/avatars) - Gerencie os avatares de seus usuários, bandeiras de países, ícones do navegador, símbolos de cartão de crédito e gere códigos QR.

Para obter a documentação completa da API, visite [https://appwrite.io/docs](https://appwrite.io/docs). Para mais tutoriais, notícias e anúncios, confira nosso [blog](https://medium.com/appwrite-io) e [servidor no Discord](https://discord.gg/GSeTUeA).

### SDKs

Abaixo está uma lista das atuais plataformas e linguagens suportadas. Se você deseja nos ajudar em adicionar suporte para a plataforma de sua escolha, acesse nosso projeto [Gerador SDK](https://github.com/appwrite/sdk-generator) e veja nosso [guia de contribuição](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Mantido pela equipe do Appwrite)

#### Servidor

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantido pela equipe do Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Mantido pela equipe do Appwrite)

#### Comunidade

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Mantido por [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Mantido por [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Procurando por mais SDKs? - Ajude-nos contribuindo com uma pull request para o nosso [Gerador SDK](https://github.com/appwrite/sdk-generator)!

## Arquitetura

![Arquitetura Appwrite](docs/specs/overview.drawio.svg)

O Appwrite usa uma arquitetura de microsserviços projetada para facilitar o dimensionamento e a delegação de responsabilidades. Além disso, o Appwrite suporta várias APIs (REST, WebSocket e GraphQL-soon) para permitir que você interaja com seus recursos, aproveitando seu conhecimento existente e protocolos de sua escolha.

A camada da API Appwrite foi projetada para ser extremamente rápida, aproveitando o cache na memória e delegando quaisquer tarefas pesadas aos trabalhadores em segundo plano do Appwrite. Os trabalhadores em segundo plano também permitem que você controle com precisão sua capacidade de computação e custos usando uma fila de mensagens para lidar com a carga. Você pode saber mais sobre nossa arquitetura no [guia de contribuição](CONTRIBUTING.md#architecture-1).

## Contribuindo

Todas as contribuições de código - incluindo aquelas de pessoas com acesso de confirmação (commit access) - devem passar por uma pull request e ser aprovadas por um desenvolvedor principal antes de serem mescladas. Isso é para garantir uma revisão adequada de todo o código.

Nós realmente ❤️ pull requests! Se você deseja ajudar, saiba mais sobre como contribuir para este projeto no [guia de contribuição](CONTRIBUTING.md).

## Segurança

Para problemas de segurança, por favor envie-nos um e-mail para [security@appwrite.io](mailto:security@appwrite.io) em vez de postar um problema público no GitHub.

## Siga-nos

Junte-se à nossa crescente comunidade em todo o mundo! Veja nosso [Blog](https://medium.com/appwrite-io) oficial. Siga-nos no [Twitter](https://twitter.com/appwrite), [Página do Facebook](https://www.facebook.com/appwrite.io), [Grupo do Facebook](https://www.facebook.com/groups/appwrite.developers/) , [Comunidade de Desenvolvedores](https://dev.to/appwrite) ou participe do nosso [Servidor no Discord](https://discord.gg/GSeTUeA) ao vivo para mais ajudas, ideias e discussões.

## Licença

Este repositório está disponível sob a [BSD 3-Clause License](./LICENSE).
