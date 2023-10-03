> 素晴らしいニュースです！Appwrite Cloud がパブリックベータになりました！[cloud.appwrite.io](https://cloud.appwrite.io) からサインアップして、手間のかからないホスティング体験を。今すぐクラウドに参加しましょう！ ☁️🎉

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img src="./public/images/banner.png" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Appwrite はウェブ、モバイル、Flutter アプリケーションを開発するためのバックエンドプラットフォームです。オープンソースコミュニティと共に構築され、あなたが好きなコーディング言語での開発者体験のために最適化されています。</b>
    <br />
    <br />
</p>


<!-- [![Build Status](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![We're Hiring](https://img.shields.io/static/v1?label=We're&message=Hiring&color=blue&style=flat-square)](https://appwrite.io/company/careers)
[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Build Status](https://img.shields.io/github/actions/workflow/status/appwrite/appwrite/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Translate](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

[English](README.md) | [简体中文](README-CN.md) | 日本語

[**Appwrite Cloud パブリックベータを発表！今すぐご登録ください！**](https://cloud.appwrite.io)

Appwrite は、Docker <nobr> マイクロサービスのセットとしてパッケージ化された、ウェブ、モバイル、ネイティブ、またはバックエンドアプリケーションのためのエンドツーエンドのバックエンドサーバーです。Appwrite は、最新のバックエンド API をゼロから構築するために必要な複雑さや繰り返しを抽象化し、セキュアなアプリをより速く構築できるようにします。

Appwrite を使えば、ユーザー認証や複数のサインイン方法、ユーザーやチームのデータを保存・照会するためのデータベース、ストレージやファイル管理、画像操作、クラウド機能、[その他のサービス](https://appwrite.io/docs)とアプリを簡単に統合することができます。

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

詳しくは: [https://appwrite.io](https://appwrite.io)

目次:

- [インストール](#インストール)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [旧バージョンからのアップグレード](#旧バージョンからのアップグレード)
- [ワンクリックセットアップ](#ワンクリックセットアップ)
- [はじめに](#はじめに)
  - [サービス](#サービス)
  - [SDK](#sdk)
    - [クライアント](#クライアント)
    - [サーバー](#サーバー)
    - [コミュニティ](#コミュニティ)
- [アーキテクチャ](#アーキテクチャ)
- [コントリビュート](#コントリビュート)
- [セキュリティ](#セキュリティ)
- [フォローする](#フォローする)
- [ライセンス](#ライセンス)

## インストール

Appwrite はコンテナ環境で動作するように設計されています。サーバーの実行は、ターミナルからコマンドを実行するだけで簡単に行えます。docker-compose を使ってローカルホスト上で Appwrite を実行することも、Kubernetes、Docker Swarm、Rancher などのコンテナオーケストレーションツール上で実行することもできます。

Appwrite サーバの実行を開始する最も簡単な方法は、docker-compose ファイルを実行することです。インストールコマンドを実行する前に、あなたのマシンに [Docker](https://www.docker.com/products/docker-desktop) がインストールされていることを確認してください:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.4.3
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.4.3
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.4.3
```

Docker でのインストールが完了したら、ブラウザで http://localhost へ移動し、Appwrite コンソールにアクセスできます。非 Linux ネイティブホストでは、インストール完了後、サーバーの起動に数分かかる場合があることに注意してください。

高度なプロダクションやカスタムインストールについては、Docker [環境変数](https://appwrite.io/docs/environment-variables) ドキュメントをご覧ください。また、公開されている [docker-compose.yml](https://appwrite.io/install/compose) や [.env](https://appwrite.io/install/env) を使って手動で環境を設定することもできます。

### 旧バージョンからのアップグレード

Appwrite サーバーを古いバージョンからアップグレードする場合、セットアップが完了したら Appwrite マイグレーションツールを使用する必要があります。これに関する詳細は、[Installation Docs](https://appwrite.io/docs/installation) をご覧ください。

## ワンクリックセットアップ

Appwrite をローカルで実行するだけでなく、設定済みのセットアップを使用して Appwrite を起動することもできます。これにより、ローカルマシンに Docker をインストールすることなく、Appwrite を素早く立ち上げることができます。

以下のプロバイダから選択してください:

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
    <td align="center" width="100" height="100">
      <a href="https://www.linode.com/marketplace/apps/appwrite/appwrite/">
        <img width="50" height="39" src="public/images/integrations/akamai-logo.svg" alt="Akamai Logo" />
          <br /><sub><b>Akamai Compute</b></sub></a>
      </a>
    </td>
  </tr>
</table>

## はじめに

Appwrite を使い始めるのは、新しいプロジェクトを作成し、プラットフォームを選択し、SDK をコードに統合するのと同じくらい簡単です。私たちの Getting Started チュートリアルの一つを読めば、選択したプラットフォームで簡単に始めることができます。

- [Web ではじめる](https://appwrite.io/docs/getting-started-for-web)
- [Flutter ではじめる](https://appwrite.io/docs/getting-started-for-flutter)
- [Apple ではじめる](https://appwrite.io/docs/getting-started-for-apple)
- [Android ではじめる](https://appwrite.io/docs/getting-started-for-android)
- [Server ではじめる](https://appwrite.io/docs/getting-started-for-server)
- [CLI ではじめる](https://appwrite.io/docs/command-line)

### サービス

- [**Account**](https://appwrite.io/docs/references/cloud/client-web/account) - 現在のユーザー認証とアカウントを管理します。ユーザーセッション、デバイス、サインイン方法、セキュリティログを追跡・管理。
- [**Users**](https://appwrite.io/docs/server/users) - サーバーSDKを使用してバックエンド統合を構築する際に、すべてのプロジェクトユーザーを管理し、一覧表示します。
- [**Teams**](https://appwrite.io/docs/references/cloud/client-web/teams) - チーム内のユーザーを管理し、グループ化します。チーム内のメンバーシップ、招待、ユーザーの役割を管理します。
- [**Databases**](https://appwrite.io/docs/references/cloud/client-web/databases) - データベース、コレクション、ドキュメントの管理。ドキュメントの読み取り、作成、更新、削除、および高度なフィルタを使用してドキュメントコレクションのリストをフィルタリングします。
- [**Storage**](https://appwrite.io/docs/references/cloud/client-web/storage) - ストレージファイルの管理。ファイルの読み取り、作成、削除、プレビュー。ファイルのプレビューを操作して、アプリに完璧にフィットさせましょう。すべてのファイルは ClamAV によってスキャンされ、安全かつ暗号化された方法で保存されます。
- [**Functions**](https://appwrite.io/docs/server/functions) - セキュアで隔離された環境でカスタムコードを実行することにより、Appwrite サーバーをカスタマイズできます。手動または CRON スケジュールを使って、Appwrite のシステムイベントをトリガーすることができます。
- [**Realtime**](https://appwrite.io/docs/realtime) - ユーザー、ストレージ、ファンクション、データベースなど、Appwrite サービスのリアルタイムイベントをリッスンできます。
- [**Locale**](https://appwrite.io/docs/references/cloud/client-web/locale) - ユーザーの位置情報を追跡し、アプリの地域ベースのデータを管理します。
- [**Avatars**](https://appwrite.io/docs/references/cloud/client-web/avatars) - ユーザーのアバター、国旗、ブラウザのアイコン、クレジットカードのシンボルを管理できます。リンクやプレーンテキスト文字列から QR コードを生成します。

完全な API ドキュメントについては、[https://appwrite.io/docs](https://appwrite.io/docs) をご覧ください。その他のチュートリアル、ニュース、お知らせについては、[blog](https://medium.com/appwrite-io) と [Discord Server](https://discord.gg/GSeTUeA) をご覧ください。

### SDK

以下は現在サポートされているプラットフォームと言語のリストです。もし、あなたのプラットフォームへのサポート追加にご協力いただける場合は、[SDK Generator](https://github.com/appwrite/sdk-generator) プロジェクトにアクセスし、[contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md) をご覧ください。

#### クライアント

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **ベータ版** (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Appwrite チームによりメンテナンス)

#### サーバー

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **ベータ版** (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **ベータ版** (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **ベータ版** (Appwrite チームによりメンテナンス)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **実験的** (Appwrite チームによりメンテナンス)

#### コミュニティ

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) ([Michael Gangolf](https://github.com/m1ga/) によりメンテナンス)
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) ([fenix-hub @GodotNuts](https://github.com/fenix-hub) によりメンテナンス)

SDK をお探しですか？- 私たちの [SDK Generator](https://github.com/appwrite/sdk-generator) にプルリクエストを投稿して、私たちを助けてください！

## アーキテクチャ

![Appwrite Architecture](docs/specs/overview.drawio.svg)

Appwrite はマイクロサービスアーキテクチャを採用しており、スケーリングや責任の委譲が容易に行えるように設計されています。さらに、Appwrite は REST、WebSocket、GraphQL などの複数の API をサポートしており、既存の知識やプロトコルを活用してリソースとやり取りすることができます。

Appwrite API レイヤーは、インメモリキャッシングを活用し、負荷の高いタスクを Appwrite のバックグラウンドワーカーに委ねることで、非常に高速になるように設計されています。バックグラウンドワーカーはまた、負荷に対処するためのメッセージキューを使用して、計算容量とコストを正確に制御することを可能にします。私たちのアーキテクチャについては[コントリビューションガイド](CONTRIBUTING.md#architecture-1)を参照してください。

## コントリビュート

コミットアクセス権を持っている人を含め、すべてのコードコントリビューションはプルリクエストを経て、マージされる前にコア開発者の承認を得なければなりません。これはすべてのコードの適切なレビューを保証するためです。

私たちは本当にプルリクエストを ❤️ しています！このプロジェクトにコントリビュートしたい方は、[コントリビューションガイド](CONTRIBUTING.md)をご覧ください。

## セキュリティ

セキュリティ上の問題については、GitHub にパブリック issue を投稿する代わりに、[security@appwrite.io](mailto:security@appwrite.io) までメールでご連絡ください。

## フォローする

世界中に広がるコミュニティに参加しよう！オフィシャル[ブログ](https://medium.com/appwrite-io)をご覧ください。[Twitter](https://twitter.com/appwrite)、[Facebook ページ](https://www.facebook.com/appwrite.io)、[Facebook グループ](https://www.facebook.com/groups/appwrite.developers/)、[Dev Community](https://dev.to/appwrite)、またはライブの [Discord サーバー](https://discord.gg/GSeTUeA)で私たちをフォローし、より多くのヘルプ、アイディア、ディスカッションに参加してください。

## ライセンス

このリポジトリは [BSD 3-Clause License](./LICENSE) の下で利用可能です。
