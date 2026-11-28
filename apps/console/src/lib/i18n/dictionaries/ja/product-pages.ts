/**
 * Japanese translations for product pages.
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaProductPagesDictionary: Record<string, string> = {
  '13+ runtimes, your stack': '13以上のランタイム、お好みのスタック',
  'Appwrite DNS': 'Appwrite DNS',
  'Appwrite DNS docs': 'Appwrite DNS ドキュメント',
  'Appwrite Domains docs': 'Appwrite Domains ドキュメント',
  'Appwrite Network': 'Appwrite Network',
  'Appwrite Sites': 'Appwrite Sites',
  'Are database backups included?': 'データベースのバックアップは含まれますか?',
  'Are files private by default?': 'ファイルはデフォルトで非公開ですか?',
  'At-rest protection': '保存時保護',
  'Auth overview': '認証の概要',
  'Auth user delivery': '認証ユーザーへの配信',
  'AutoGravity does not crop this file. It returns a focal point that the preview crop should keep visible.':
    'AutoGravity はこのファイル自体をクロップしません。プレビューのクロップが見え続けるべき焦点を返します。',
  'AutoGravity focal point': 'AutoGravity の焦点',
  'Automatic crop gravity': '自動クロップ gravity',
  'Face bounding box, if confidence is high':
    '信頼度が高い場合の顔バウンディングボックス',
  'It asks AutoGravity to pick a crop focus. YuNet runs first and uses a face bounding box when confidence is high enough. Otherwise U²-Net saliency finds the strongest connected region and returns its weighted centroid as a normalized (x, y) point. The preview endpoint crops around that point. Fixed gravity values still work as before.':
    'AutoGravity にクロップの焦点を選ばせます。まず YuNet が動き、信頼度が十分なら顔のバウンディングボックスを使います。そうでなければ U²-Net の saliency が最も強い連結領域を見つけ、重み付き重心を正規化 (x, y) として返します。preview エンドポイントはその点を中心にクロップします。固定の gravity 値はこれまでどおり使えます。',
  'Normalized focal point for the crop': 'クロップ用の正規化焦点',
  'Pass gravity=auto on file preview and Appwrite picks a focal point from the image. YuNet looks for a face first. If none is confident enough, U²-Net saliency finds the strongest subject. The service returns a normalized (x, y) coordinate. Existing values like center and top-right stay unchanged.':
    'file preview で gravity=auto を渡すと、Appwrite が画像から焦点を選びます。YuNet が先に顔を探します。十分な確度がなければ、U²-Net の saliency が最も目立つ被写体を見つけます。サービスは正規化された (x, y) 座標を返します。center や top-right などの既存値はそのままです。',
  'Saliency map, then strongest connected region':
    'saliency マップ、その後に最も強い連結領域',
  'Square crop using gravity=auto, subject kept in view':
    'gravity=auto の正方形クロップ。被写体が残る',
  'Square crop using gravity=center, mostly empty field':
    'gravity=center の正方形クロップ。ほぼ空の草地',
  'The middle of the frame is empty grass.': 'フレーム中央は空の草地です。',
  'The same 400×400 request keeps the subject.':
    '同じ 400×400 のリクエストで被写体が残ります。',
  'Keeps the subject.': '被写体が残ります。',
  'Misses the subject.': '被写体が切れます。',
  'What does gravity=auto do on image previews?':
    'gravity=auto は画像プレビューで何をしますか?',
  'Wide source photograph with the subject on the left':
    '被写体が左にある横長の元写真',
  'YuNet looks for a face, then U²-Net saliency. AutoGravity returns a normalized (x, y) point. The uploaded file is not cropped.':
    'YuNet が顔を探し、次に U²-Net の saliency です。AutoGravity は正規化された (x, y) を返します。アップロードしたファイル自体はクロップされません。',
  'YuNet, then U²-Net. File is not cropped.':
    'YuNet、次に U²-Net。ファイルはクロップしません。',
  'Branch URLs': 'Branch URL',
  'Bring your own providers': '独自のプロバイダーを利用',
  'Broadcast messaging': 'ブロードキャストメッセージング',
  'Bucket encryption': 'バケット暗号化',
  'Bucket settings docs': 'バケット設定ドキュメント',
  'Bucket-based file management at scale':
    '大規模なバケットベースのファイル管理',
  'Build and deploy faster': 'より速くビルドとデプロイ',
  'Buy domains and manage DNS': 'ドメインの購入と DNS 管理',
  'CDN + TLS': 'CDN + TLS',
  'CDN delivery built in': 'CDN 配信を標準搭載',
  'CDN docs': 'CDN ドキュメント',
  'CDN overview': 'CDN の概要',
  Caching: 'キャッシュ',
  'Can Functions access other Appwrite services?':
    'Functions は他の Appwrite サービスにアクセスできますか?',
  'Can Functions respond to HTTP requests?':
    'Functions は HTTP リクエストに応答できますか?',
  'Can I buy a domain and manage DNS in Appwrite?':
    'Appwrite でドメインを購入し DNS を管理できますか?',
  'Can I deploy without connecting Git?': 'Git を接続せずにデプロイできますか?',
  'Can I develop Functions locally?': 'Functions をローカルで開発できますか?',
  'Can I encrypt files in Storage?': 'ストレージのファイルを暗号化できますか?',
  'Can I inspect site traffic and debug SSR output?':
    'サイトのトラフィックを確認し SSR 出力をデバッグできますか?',
  'Can I migrate from a legacy document database?':
    'レガシーのドキュメントデータベースから移行できますか?',
  'Can I migrate users from another auth provider?':
    '別の認証プロバイダーからユーザーを移行できますか?',
  'Can I schedule messages and track delivery?':
    'メッセージをスケジュールし配信を追跡できますか?',
  'Can I send messages from Functions or my backend?':
    'Functions やバックエンドからメッセージを送信できますか?',
  'Can I start from templates or quick-starts?':
    'テンプレートやクイックスタートから始められますか?',
  'Can I transform images without storing multiple copies?':
    '複数のコピーを保存せずに画像を変換できますか?',
  'Can I upload large files?': '大きなファイルをアップロードできますか?',
  'Can I use Auth without building a custom login UI?':
    'カスタムログイン UI を作らずに認証を使えますか?',
  'Can I use separate domains for staging and production?':
    'ステージングと本番で別々のドメインを使えますか?',
  'Can Sites connect to my Appwrite backend?':
    'サイトは Appwrite バックエンドに接続できますか?',
  'Channels in one API': '1つの API で複数チャネル',
  'Checking auth status': '認証ステータスの確認',
  'Choosing a message type': 'メッセージタイプの選択',
  'Compatible object access': '互換性のあるオブジェクトアクセス',
  'Compose and delivery logs': '作成と配信ログ',
  'Compose and schedule messages': 'メッセージの作成とスケジュール',
  'Compression and image format optimization': '圧縮と画像フォーマットの最適化',
  'Connect hostnames to your site': 'ホスト名をサイトに接続',
  'Database engines': 'データベースエンジン',
  'Databases overview': 'データベースの概要',
  'Delivered on Appwrite Network': 'Appwrite Network 経由で配信',
  'Delivery providers': '配信プロバイダー',
  'Deploy from CLI': 'CLI からデプロイ',
  'Deploy from Git': 'Git からデプロイ',
  'Deploy from Git docs': 'Git からのデプロイ ドキュメント',
  'Deploy from Git with auto-build on push':
    'push 時に自動ビルドする Git デプロイ',
  'Deploy from Git with preview URLs': 'プレビュー URL 付き Git デプロイ',
  'Deploy manually': '手動デプロイ',
  'Deploy the way your team works': 'チームのワークフローに合わせてデプロイ',
  'Deployment retention': 'デプロイの保持',
  'Deployments docs': 'デプロイ ドキュメント',
  'Develop and run functions locally': 'Functions をローカルで開発・実行',
  'Develop functions': 'Functions の開発',
  'Develop locally': 'ローカル開発',
  'Do I need separate vendor integrations for email, SMS, and push?':
    'メール、SMS、プッシュ用に別々のベンダー連携が必要ですか?',
  'Does Auth support social login and linked identities?':
    '認証は OAuth とリンク済み ID をサポートしていますか?',
  'Does Databases work with Auth permissions?':
    'データベースは認証の権限と連携しますか?',
  'Does Storage support file compression?':
    'ストレージはファイル圧縮をサポートしていますか?',
  'Does Storage support the S3 API?':
    'ストレージは S3 API をサポートしていますか?',
  'Domains docs': 'ドメイン ドキュメント',
  'Email and password login': 'メールとパスワードでのログイン',
  'Encryption at rest': '保存時暗号化',
  'Event triggers and scheduled executions':
    'イベントトリガーとスケジュール実行',
  'Execute functions': 'Functions の実行',
  'Execute functions docs': 'Functions 実行ドキュメント',
  'Executions and observability': '実行とオブザーバビリティ',
  'Executions docs': '実行ドキュメント',
  'Fast builds with built-in dependency cache':
    '組み込み依存関係キャッシュによる高速ビルド',
  'File tokens docs': 'ファイルトークン ドキュメント',
  'File tokens for expiring public links':
    '期限付き公開リンク用ファイルトークン',
  'Framework presets': 'Framework プリセット',
  'Framework quick-starts and templates':
    'Framework クイックスタートとテンプレート',
  Frameworks: 'Frameworks',
  'Function domains': 'Function ドメイン',
  'Function domains docs': 'Function ドメイン ドキュメント',
  'Function templates catalog': 'Function テンプレートカタログ',
  'Granular permissions at bucket and file level':
    'バケットとファイルレベルのきめ細かい権限',
  'HTTP endpoints via domains': 'ドメイン経由の HTTP エンドポイント',
  'How are passwords, sessions, and security policies configured?':
    'パスワード、セッション、セキュリティポリシーはどのように設定しますか?',
  'How are targets linked to Auth users?':
    'ターゲットは認証ユーザーとどのようにリンクされますか?',
  'How do I add multi-factor authentication?': '多要素認証を追加する方法は?',
  'How do I debug function executions?': 'Function 実行をデバッグする方法は?',
  'How do I deploy from Git?': 'Git からデプロイする方法は?',
  'How do file tokens work for public sharing?':
    '公開共有のファイルトークンはどのように機能しますか?',
  'How do instant rollbacks work?': '即時ロールバックはどのように機能しますか?',
  'How do preview deployments work?':
    'プレビューデプロイはどのように機能しますか?',
  'How do teams and multi-tenancy work?':
    'チームとマルチテナンシーはどのように機能しますか?',
  'How do topics and targets work together?':
    'トピックとターゲットはどのように連携しますか?',
  'How does Appwrite Sites keep builds and deployments efficient?':
    'Appwrite Sites はビルドとデプロイをどのように効率化していますか?',
  'How does Auth work with self-hosted Appwrite?':
    '認証は self-hosted Appwrite でどのように動作しますか?',
  'How does passwordless sign-in work?':
    'パスワードレスサインインはどのように機能しますか?',
  'How does the build cache work?': 'ビルドキャッシュはどのように機能しますか?',
  'How is Storage different from Databases?':
    'ストレージとデータベースの違いは?',
  'Image transform wizard and presets': '画像変換ウィザードとプリセット',
  'Image transforms docs': '画像変換ドキュメント',
  'In-console query editor': 'コンソール内クエリエディター',
  'Instant rollbacks': '即時ロールバック',
  'Instant rollbacks docs': '即時ロールバック ドキュメント',
  'Is CDN delivery included with Storage?':
    'ストレージに CDN 配信は含まれますか?',
  'Language runtimes': '言語ランタイム',
  'Local development docs': 'ローカル開発ドキュメント',
  'Local-first development': 'ローカルファースト開発',
  'Logs docs': 'ログ ドキュメント',
  'Logs, traffic, and usage insights':
    'ログ、トラフィック、使用状況のインサイト',
  'MFA docs': 'MFA ドキュメント',
  'Magic URL docs': 'Magic URL ドキュメント',
  'Memberships & roles': 'メンバーシップとロール',
  'Messages docs': 'メッセージ ドキュメント',
  'Messaging overview': 'メッセージングの概要',
  'Multi-tenancy': 'Multi-tenancy',
  'Multi-tenancy docs': 'Multi-tenancy ドキュメント',
  'Multi-tenancy with teams and roles': 'チームとロールによるマルチテナンシー',
  'OAuth 2 and social login': 'OAuth 2 と OAuth ログイン',
  'OAuth providers': 'OAuth プロバイダー',
  OAuth2: 'OAuth2',
  'OAuth2 docs': 'OAuth2 ドキュメント',
  'On-the-fly delivery': 'オンデマンド配信',
  'On-the-fly image transformations': 'オンデマンド画像変換',
  'Password hashing': 'パスワードハッシュ化',
  'Passwordless login': 'パスワードレスログイン',
  'Permission-aware tables': '権限対応テーブル',
  'Permissions docs': '権限ドキュメント',
  'Platform event hooks': 'プラットフォームイベントフック',
  'Presence for team collaboration': 'チームコラボレーション用 Presence',
  Presences: 'Presences',
  Previews: 'プレビュー',
  'Providers docs': 'プロバイダー ドキュメント',
  'Push to deploy': 'push でデプロイ',
  'Quick start': 'クイックスタート',
  'Recovery policies': 'リカバリーポリシー',
  Rendering: 'レンダリング',
  'Rendering docs': 'レンダリング ドキュメント',
  'Repository deploys': 'リポジトリデプロイ',
  Runtimes: 'Runtimes',
  'Runtimes docs': 'Runtimes ドキュメント',
  'S3-compatible object access': 'S3 互換オブジェクトアクセス',
  'SSR authentication': 'SSR 認証',
  'SSR docs': 'SSR ドキュメント',
  'SSR hosting': 'SSR ホスティング',
  'Schedule triggers': 'スケジュールトリガー',
  'Security policies you control': '自分で管理するセキュリティポリシー',
  'Self-hosting': 'Self-hosting',
  'Send SMS messages': 'SMS メッセージの送信',
  'Send email messages': 'メールメッセージの送信',
  'Send push notifications': 'プッシュ通知の送信',
  'Should I use TablesDB or PostgreSQL?':
    'TablesDB と PostgreSQL、どちらを使うべきですか?',
  'Sign-in methods': 'サインイン方法',
  'Site domains': 'サイトドメイン',
  'Site logs': 'サイトログ',
  'Sites overview': 'サイトの概要',
  'Static hosting': '静的ホスティング',
  'Storage docs': 'ストレージ ドキュメント',
  'Storage overview': 'ストレージの概要',
  'Storage permissions': 'ストレージ権限',
  'Targets docs': 'ターゲット ドキュメント',
  'Targets linked to Auth users': '認証ユーザーにリンクされたターゲット',
  'Team collaboration': 'チームコラボレーション',
  'Team invites': 'チーム招待',
  'Templates docs': 'テンプレート ドキュメント',
  'Topics docs': 'トピック ドキュメント',
  'Topics for group and broadcast messaging':
    'グループとブロードキャストメッセージング用トピック',
  'Two-factor auth': '二要素認証',
  'Unified email, SMS, and push API': 'メール、SMS、プッシュを統合した API',
  'Upload and download': 'アップロードとダウンロード',
  'Upload and download docs': 'アップロードとダウンロード ドキュメント',
  'What edge network and security features are included?':
    'どのエッジネットワークとセキュリティ機能が含まれますか?',
  'What is the difference between static and SSR hosting?':
    '静的ホスティングと SSR ホスティングの違いは?',
  'What is the difference between sync and async execution?':
    '同期実行と非同期実行の違いは?',
  'What team collaboration features can presences power?':
    'Presences でどのようなチームコラボレーション機能を実現できますか?',
  'When should I use email, SMS, or push?':
    'メール、SMS、プッシュはいつ使うべきですか?',
  'Which delivery providers are supported?':
    'どの配信プロバイダーがサポートされていますか?',
  'Which frameworks does Sites support?':
    'サイトはどの Framework をサポートしていますか?',
  'Which languages do Functions support?':
    'Functions はどの言語をサポートしていますか?',
  'gzip and zstd buckets': 'gzip と zstd バケット',
  'Add TOTP authenticator apps and recovery codes for sensitive accounts. Require MFA when users update credentials or access protected actions.':
    '機密性の高いアカウント向けに TOTP 認証アプリとリカバリーコードを追加できます。ユーザーが認証情報を更新したり保護されたアクションにアクセスしたりする際に MFA を必須にできます。',
  'Add secure authentication to your app with email, OAuth, SMS, magic URLs, MFA, teams, presences, and session management.':
    'メール、OAuth、SMS、Magic URL、MFA、チーム、Presences、セッション管理を使って、アプリに安全な認証を追加できます。',
  'Appwrite caches your package manager store between deployments, keyed automatically per function. pnpm, bun, npm, and yarn installs are faster on the next build with no extra configuration. If a cache restore fails, the build continues normally.':
    'Appwrite はデプロイ間でパッケージマネージャーのストアをキャッシュし、関数ごとに自動でキーを割り当てます。pnpm、bun、npm、yarn によるインストールは、追加設定なしで次回のビルドから高速化されます。キャッシュの復元に失敗した場合も、ビルドは通常どおり続行されます。',
  'Appwrite hashes passwords with Argon2, including salting and adjustable work factors. From Auth Policies and Settings, set minimum length, character requirements, password history, dictionary checks, and rules that block personal data in passwords. Email policies can block disposable, aliased, or free-provider addresses at sign-up. Session settings control duration, limits per user, and cookie behavior for web apps.':
    'Appwrite はソルト処理と調整可能なワークファクターを含め、Argon2 でパスワードをハッシュ化します。認証のポリシーと設定から、最小文字数、文字種の要件、パスワード履歴、辞書チェック、個人情報を含むパスワードを禁止するルールを設定できます。メールポリシーでは、サインアップ時に使い捨てアドレス、エイリアス、無料プロバイダーのアドレスをブロックできます。セッション設定では、Web アプリの有効期間、ユーザーごとの上限、Cookie の挙動を制御できます。',
  'Appwrite supports 13+ runtimes including Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, and Deno. Each runtime has multiple version tags so you can pin the environment that matches production.':
    'Appwrite は Node.js、Bun、Python、Go、Dart、PHP、Ruby、Rust、Deno など 13 以上のランタイムをサポートしています。各ランタイムには複数のバージョンタグがあり、本番環境に合わせて環境を固定できます。',
  'Appwrite supports both TablesDB and legacy Collections APIs. Docs cover migration paths and compatibility notes.':
    'Appwrite は TablesDB と従来の Collections API の両方をサポートしています。ドキュメントには移行方法と互換性に関する注意事項が記載されています。',
  'Auth is included in every Appwrite deployment. Self-hosted installations use the same Auth APIs, SDKs, OAuth providers, policies, and session behavior as Appwrite Cloud. Configure auth methods, password rules, and security policies from the Console the same way.':
    '認証はすべての Appwrite デプロイに含まれています。セルフホスト環境でも、Appwrite Cloud と同じ認証 API、SDK、OAuth プロバイダー、ポリシー、セッションの挙動を利用できます。認証方法、パスワードルール、セキュリティポリシーは同じ方法でコンソールから設定できます。',
  'Backup features depend on your plan and database engine. Cloud plans include backup options for supported engines.':
    'バックアップ機能はプランとデータベースエンジンによって異なります。Cloud プランには、対応エンジン向けのバックアップオプションが含まれます。',
  'Build transforms visually in the Console, preview results live, and save presets for reuse across your team. Ship optimized images without writing transformation code.':
    'コンソール上でトランスフォームを視覚的に作成し、結果をリアルタイムでプレビューして、チームで再利用できるプリセットとして保存できます。変換コードを書かずに、最適化された画像を配信できます。',
  'Build workers restore a dependency cache at the start of each deployment, so package installs on unchanged lockfiles finish in seconds instead of minutes. Path filters and root directory settings let Turborepo monorepos skip builds when unrelated packages change. Deployment retention automatically deletes inactive deployments after a period you choose, so preview builds do not pile up and consume storage. You can also tune build and runtime CPU and memory in site settings when compilation or SSR needs more headroom.':
    'ビルドワーカーは各デプロイの開始時に依存関係のキャッシュを復元するため、lockfile に変更がない場合のパッケージインストールは数分ではなく数秒で完了します。パスフィルターとルートディレクトリの設定により、Turborepo のモノレポで無関係なパッケージが変更されてもビルドをスキップできます。デプロイの保持設定は、選択した期間を過ぎた非アクティブなデプロイを自動的に削除するため、プレビュービルドが増え続けてストレージを圧迫することがありません。コンパイルや SSR により多くのリソースが必要な場合は、サイト設定でビルドおよびランタイムの CPU とメモリを調整することもできます。',
  'Code in Node, Bun, Python, Go, Rust, Dart, and more. Pin the version you ship with and deploy without relearning the platform.':
    'Node、Bun、Python、Go、Rust、Dart など、お好みの言語でコードを書けます。使用するバージョンを固定し、プラットフォームの使い方を新たに覚えることなくデプロイできます。',
  'Connect Appwrite Storage to rclone, Terraform, and custom pipelines with a project-scoped HTTPS endpoint and SigV4-compatible signing. Copy credentials from the Console Connect tab and keep your existing S3 workflows.':
    'プロジェクト単位の HTTPS エンドポイントと SigV4 互換の署名を使って、Appwrite Storage を rclone、Terraform、カスタムパイプラインに接続できます。コンソールの Connect タブから認証情報をコピーし、既存の S3 ワークフローをそのまま利用できます。',
  'Connect GitHub or Origin and ship on every push. Commits to your production branch build and auto-activate on your primary domain; other branches get preview links for org members to review before merge.':
    'GitHub または Origin を接続すれば、push するたびにリリースできます。本番ブランチへのコミットはビルドされ、メインドメインで自動的に有効化されます。それ以外のブランチには、マージ前に組織のメンバーがレビューできるプレビューリンクが発行されます。',
  'Connect a repository and deploy on every push, the same workflow as Sites. Set a production branch that auto-activates successful builds, filter by branch or path with glob patterns, and review preview deployments from pull requests.':
    'リポジトリを接続すれば、サイトと同じワークフローで push のたびにデプロイできます。成功したビルドを自動的に有効化する本番ブランチを設定し、glob パターンでブランチやパスによる絞り込みを行い、プルリクエストからのプレビューデプロイを確認できます。',
  'Connect a repository in the Console, set a production branch and root directory, then deploy on every push. Branch and path filters use glob patterns, matching the Sites Git workflow.':
    'コンソールでリポジトリを接続し、本番ブランチとルートディレクトリを設定すれば、push のたびにデプロイされます。ブランチとパスのフィルターには、サイトの Git ワークフローと同じ glob パターンを使用します。',
  'Connect the email, SMS, and push providers you already use. Add credentials once in the Console, pick a vendor per channel, and route every message through the stack you operate.':
    'すでに利用しているメール、SMS、push のプロバイダーを接続できます。コンソールで認証情報を一度追加し、チャネルごとにベンダーを選び、自分たちで運用するスタックですべてのメッセージを配信できます。',
  'Create a team for each customer, organization, or workspace in your app. Invite members by email, assign roles, and scope databases, storage buckets, functions, and other resources with team-based permissions. Teams give you tenant isolation without building custom RBAC, and membership privacy settings let you control whether member lists are visible to other users.':
    'アプリ内の顧客、組織、ワークスペースごとにチームを作成できます。メールでメンバーを招待し、ロールを割り当て、チーム単位の権限でデータベース、ストレージバケット、関数などのリソースを制御できます。チームを使えば、独自の RBAC を構築せずに顧客間の分離を実現でき、メンバーシップのプライバシー設定でメンバー一覧を他のユーザーに公開するかどうかも制御できます。',
  'Create topics from the Console Topics tab and subscribe targets for newsletters, product announcements, and security alerts. Broadcast to every subscriber in a topic or combine topics with direct targets when you need finer control.':
    'コンソールの Topics タブからトピックを作成し、ニュースレターや製品アナウンス、セキュリティ通知向けにターゲットを登録できます。トピック内のすべての購読者に一斉配信することも、より細かい制御が必要な場合はトピックと個別のターゲットを組み合わせることもできます。',
  'Deploy serverless Functions with isolated runtimes, schedules, and event triggers. Build backends without managing servers.':
    '分離されたランタイム、スケジュール、イベントトリガーを備えたサーバーレス Functions をデプロイできます。サーバーを管理せずにバックエンドを構築できます。',
  'Deploy static, SSR, and CSR web apps with Appwrite Sites. Git-based deploys, preview URLs, instant rollbacks, custom domains, and Appwrite backends.':
    'Appwrite Sites で静的サイト、SSR、CSR の Web アプリをデプロイできます。Git ベースのデプロイ、プレビュー URL、即時ロールバック、カスタムドメイン、Appwrite バックエンドに対応しています。',
  'Draft email, SMS, and push from the Console Messages tab with channel-specific fields, topic and target selection, and delivery logs. Schedule sends for later or fire transactional flows such as OTP verification and account alerts from Functions or your backend.':
    'コンソールの Messages タブから、チャネルごとの項目、トピックとターゲットの選択、配信ログを使ってメール、SMS、push の下書きを作成できます。送信を予約したり、Functions やバックエンドから OTP 認証やアカウント通知などのトランザクションフローを送信したりできます。',
  'Each Auth user can have multiple targets registered to your project. Verified emails from email/password, magic URL, and email OTP sign-up create email targets automatically. Verified phone numbers from SMS OTP sign-up create SMS targets. Push targets are added from your client app after the user grants notification permission. Inspect and manage targets from the Targets tab on user detail in Auth.':
    '各認証ユーザーは、プロジェクトに複数のターゲットを登録できます。メール/パスワード、Magic URL、Email OTP によるサインアップで確認済みのメールアドレスは、自動的にメールターゲットとして作成されます。SMS OTP サインアップで確認済みの電話番号は SMS ターゲットになります。push ターゲットは、ユーザーが通知の許可を付与した後にクライアントアプリから追加されます。ターゲットの確認や管理は、認証のユーザー詳細にある Targets タブから行えます。',
  'Each user can have email, phone, and push device targets registered to your project. Inspect and manage them from the Targets tab on user detail in Auth, then subscribe those targets to topics or address them directly in a message.':
    '各ユーザーは、プロジェクトにメール、電話、push デバイスのターゲットを登録できます。認証のユーザー詳細にある Targets タブから確認や管理を行い、それらのターゲットをトピックに登録するか、メッセージで直接指定できます。',
  'Email: Resend, SendGrid, Mailgun, Amazon SES, and SMTP. SMS: Twilio, Vonage, MSG91, Telesign, and Textmagic. Push: APNS and FCM. Configure multiple providers per channel and choose which one to use when sending. Discord and Slack chat integrations are coming soon.':
    'メール: Resend、SendGrid、Mailgun、Amazon SES、SMTP。SMS: Twilio、Vonage、MSG91、Telesign、Textmagic。push: APNS と FCM。チャネルごとに複数のプロバイダーを設定し、送信時にどれを使うか選択できます。Discord と Slack のチャット連携は近日公開予定です。',
  'Enable 30+ social providers from the Console Social providers tab. Users sign up in one click with GitHub, Google, Apple, and the identity providers your audience already uses.':
    'コンソールの OAuth プロバイダー タブから 30 以上の OAuth プロバイダーを有効化できます。ユーザーは GitHub、Google、Apple など、すでに使い慣れた ID プロバイダーでワンクリックでサインアップできます。',
  'Enable MFA in Auth settings, then let users enroll an authenticator app (TOTP) and download recovery codes. MFA adds a second step after the primary sign-in method. Require it for sensitive actions such as updating credentials or accessing protected resources. Users who lose their device can sign in with a recovery code instead of the TOTP.':
    '認証設定で MFA を有効にすると、ユーザーは認証アプリ (TOTP) を登録し、リカバリーコードをダウンロードできるようになります。MFA は主なサインイン方法の後に追加のステップを加えます。認証情報の更新や保護されたリソースへのアクセスなど、機密性の高い操作にはこれを必須にできます。デバイスを紛失したユーザーは、TOTP の代わりにリカバリーコードでサインインできます。',
  "Enable Magic URL, Email OTP, and Phone SMS from Auth settings in the Console. Magic URL sends a one-click sign-in link to the user's email. Email OTP delivers a time-limited code they enter in your app. Phone SMS verifies users through text messages without a password. You can offer passwordless methods alongside email and password, or disable password login entirely for a password-free experience.":
    'コンソールの認証設定から Magic URL、Email OTP、Phone SMS を有効化できます。Magic URL はユーザーのメールにワンクリックのサインインリンクを送信します。Email OTP はアプリ内で入力する期限付きコードを送ります。Phone SMS は SMS でパスワードなしにユーザーを確認します。パスワードレスの方法をメールとパスワードに加えて提供することも、パスワードログインを完全に無効にしてパスワード不要の体験にすることもできます。',
  'Every function gets a generated URL and optional custom domain for sync HTTP APIs and webhooks. Pass user sessions with the x-appwrite-user-jwt header so your function respects Auth permissions inside Server SDKs.':
    'すべての関数には、同期 HTTP API や Webhook 用に生成された URL とオプションのカスタムドメインが割り当てられます。x-appwrite-user-jwt ヘッダーでユーザーセッションを渡せば、Server SDK 内でも関数が認証の権限を尊重するようになります。',
  'Every run creates an execution you can inspect in the Console. Review status, trigger, method, path, and duration in the executions table, then open details for logs, errors, and headers. Request and response bodies are not stored by default. Use log() and error() for the audit trail you need.':
    '実行のたびに、コンソールで確認できる execution が作成されます。実行一覧でステータス、トリガー、メソッド、パス、所要時間を確認し、詳細を開いてログ、エラー、ヘッダーを確認できます。リクエストとレスポンスのボディはデフォルトでは保存されません。必要な監査記録には log() と error() を使用してください。',
  'Every site runs on Appwrite Network. Auth, Databases, and Storage stay in your project region while pages and assets reach users from the edge.':
    'すべてのサイトは Appwrite Network 上で動作します。認証、データベース、ストレージはプロジェクトのリージョンに保持されたまま、ページとアセットはエッジからユーザーに届けられます。',
  'Every storage file and transformed preview is served through Appwrite CDN with 120+ edge locations worldwide. Transformed images are cached in your project region first, so repeat requests skip re-processing and reach users faster.':
    'すべてのストレージファイルと変換後のプレビューは、世界 120 以上のエッジロケーションを持つ Appwrite CDN を通じて配信されます。変換後の画像はまずプロジェクトのリージョンでキャッシュされるため、繰り返しのリクエストでは再処理をスキップし、より速くユーザーに届きます。',
  'File tokens are secrets attached to a file that authorize preview, view, or download without session cookies. Create tokens from the Console or Server SDK, set an optional expiry, and share the URL with anyone. This avoids third-party cookie issues in embedded or cross-domain apps.':
    'ファイルトークンは、セッション Cookie なしでプレビュー、閲覧、ダウンロードを許可する、ファイルに紐づいたシークレットです。コンソールまたは Server SDK からトークンを作成し、任意の有効期限を設定して、誰とでも URL を共有できます。これにより、埋め込みアプリやクロスドメインアプリでのサードパーティ Cookie の問題を回避できます。',
  'Fine-tune Auth from the Console Policies and Settings tabs. Set session length and limits, password strength and history, email signup rules, membership privacy, and which auth methods are enabled for your project.':
    'コンソールの Policies タブと Settings タブから認証を細かく調整できます。セッションの長さと上限、パスワードの強度と履歴、メールでのサインアップルール、メンバーシップのプライバシー、プロジェクトで有効にする認証方法を設定できます。',
  'Host static sites, SPAs, and PWAs alongside server-rendered apps. Choose the rendering mode that fits your framework, from Vite and Astro to Next.js 16, Nuxt, SvelteKit, and TanStack Start.':
    '静的サイト、SPA、PWA をサーバーレンダリングされたアプリと並行してホストできます。Vite や Astro から Next.js 16、Nuxt、SvelteKit、TanStack Start まで、フレームワークに合ったレンダリングモードを選択できます。',
  'Instant rollbacks change which ready deployment is served to visitors. They do not delete, modify, or rebuild your code, so recovery is near-instant with zero downtime. Open your site Overview in the Console, click Instant Rollback, and promote a previous deployment.':
    '即時ロールバックは、訪問者に配信する準備完了済みのデプロイを切り替える機能です。コードの削除、変更、再ビルドは行わないため、ダウンタイムゼロでほぼ即座に復旧できます。コンソールでサイトの概要を開き、Instant Rollback をクリックして、以前のデプロイを昇格させてください。',
  'Integrate Storage with Auth users, teams, and roles. Set bucket-wide defaults and per-file rules from the Console Security tab so the right people can read, create, update, or delete files.':
    'ストレージを認証のユーザー、チーム、ロールと連携できます。コンソールの Security タブからバケット全体のデフォルトとファイルごとのルールを設定し、適切な相手だけがファイルの読み取り、作成、更新、削除を行えるようにできます。',
  'Lower storage costs, cut bandwidth, and speed up page loads without a separate media pipeline. Enable gzip or zstd per bucket to compress uploads automatically, then serve WebP, AVIF, and other modern formats from the preview endpoint. Keep one original upload and optimize file size every time you deliver it.':
    '別のメディアパイプラインを用意することなく、ストレージコストの削減、帯域幅の削減、ページ読み込みの高速化を実現できます。バケットごとに gzip または zstd を有効にしてアップロードを自動的に圧縮し、プレビューエンドポイントから WebP や AVIF などの最新フォーマットで配信できます。オリジナルのアップロードは 1 つだけ保持し、配信のたびにファイルサイズを最適化できます。',
  'Model each customer or workspace as a team with memberships, invites, and roles. Scope databases, storage, and other resources to the right tenant from the Console Users and Teams tabs, without building custom RBAC.':
    'メンバーシップ、招待、ロールを持つチームとして、顧客やワークスペースをそれぞれモデル化できます。独自の RBAC を構築することなく、コンソールの Users タブと Teams タブから、データベースやストレージなどのリソースを適切な組織に限定できます。',
  'No. You connect your own provider credentials once in the Console, then send on every channel through one Messaging API and SDK. Pick a vendor per channel (Resend for email, Twilio for SMS, FCM for push, and so on) without maintaining three separate integrations or delivery logs.':
    'いいえ、必要ありません。コンソールで自分のプロバイダーの認証情報を一度接続すれば、1 つの Messaging API と SDK からすべてのチャネルに送信できます。メールは Resend、SMS は Twilio、push は FCM のように、チャネルごとにベンダーを選べます。3 つの別々の連携や配信ログを維持する必要はありません。',
  'Open the Executions tab in the Console to review status, trigger, method, path, and duration for each run. Execution details include logs, errors, and headers. Request and response bodies are not logged by default for privacy. Use log() and error() in your handler for the output you want to retain.':
    'コンソールの Executions タブを開くと、実行ごとのステータス、トリガー、メソッド、パス、所要時間を確認できます。実行の詳細にはログ、エラー、ヘッダーが含まれます。プライバシーのため、リクエストとレスポンスのボディはデフォルトでは記録されません。残しておきたい出力は、ハンドラー内で log() と error() を使って記録してください。',
  'Organize uploads in isolated buckets with upload, download, list, and delete APIs. Browse files in the Console with search, pagination, and bulk operations.':
    'アップロード、ダウンロード、一覧取得、削除の API を備えた、独立したバケットでアップロードを整理できます。コンソールでは、検索やページネーション、一括操作を使ってファイルを閲覧できます。',
  'Point production at your live deployment, give staging branches their own domain, or configure redirects. Every site also gets a generated .appwrite.network URL for instant sharing.':
    '本番環境を公開中のデプロイに向けたり、ステージング用のブランチに専用ドメインを割り当てたり、リダイレクトを設定したりできます。すべてのサイトには、すぐに共有できる .appwrite.network の URL も自動生成されます。',
  'Presences show who is active right now: online, away, typing, or viewing a page or channel. Upsert records with status and metadata, then subscribe over Realtime for live updates. Use them for team rosters, shared doc viewers, chat typing indicators, and support queue availability.':
    'Presences は、オンライン、退席中、入力中、ページやチャンネルの閲覧中など、現在アクティブなユーザーを表示します。ステータスとメタデータを含むレコードを作成・更新し、Realtime で購読すればライブ更新を受け取れます。チームの在籍状況、共有ドキュメントの閲覧者、チャットの入力中表示、サポートキューの対応可否などに利用できます。',
  'Purchase domains in Appwrite and manage records with Appwrite DNS from the Console. TLS is issued automatically when you connect a hostname.':
    'Appwrite でドメインを購入し、コンソールの Appwrite DNS でレコードを管理できます。ホスト名を接続すると、TLS が自動的に発行されます。',
  'Push and SMS work well for time-sensitive alerts users see within minutes. Email suits rich HTML content like receipts, newsletters, and promotions. SMS reaches phones even without internet. Push drives re-engagement with deep links back into your app. Most production apps combine all three depending on urgency and content.':
    'push と SMS は、ユーザーが数分以内に目にする緊急性の高い通知に適しています。メールは、領収書やニュースレター、プロモーションのようなリッチな HTML コンテンツに向いています。SMS はインターネットがなくても電話に届きます。push はアプリ内へのディープリンクで再エンゲージメントを促します。多くの本番アプリは、緊急性と内容に応じて 3 つすべてを組み合わせて使用しています。',
  'Run functions asynchronously on platform events or on a cron schedule. Sync HTTP calls and SDK executions with async disabled return responses immediately but cap at 30 seconds. Events, cron jobs, and queued executions run in the background with your configured timeout, up to 15 minutes.':
    'プラットフォームのイベントまたは Cron スケジュールで、関数を非同期に実行できます。async を無効にした同期 HTTP 呼び出しや SDK からの実行は即座にレスポンスを返しますが、30 秒が上限です。イベント、Cron ジョブ、キューに入れられた実行はバックグラウンドで実行され、設定した Timeout (最大 15 分) が適用されます。',
  'Send email, SMS, and push notifications with Appwrite Messaging. Topics, targets, providers, and scheduling in one API.':
    'Appwrite Messaging でメール、SMS、push 通知を送信できます。トピック、ターゲット、プロバイダー、スケジューリングを 1 つの API で扱えます。',
  'Send on every channel from one Messaging service and SDK. Use createEmail, createSms, and createPush for transactional mail, OTP codes, and mobile alerts without wiring three separate vendor integrations.':
    '1 つの Messaging サービスと SDK からすべてのチャネルに送信できます。createEmail、createSms、createPush を使えば、3 つの別々のベンダー連携を組む必要なく、トランザクションメール、OTP コード、モバイル通知を送信できます。',
  'Share files with token-based preview, view, and download URLs that work without session cookies. Set an expiry date or keep links open-ended for external viewers.':
    'セッション Cookie なしで動作する、トークンベースのプレビュー、閲覧、ダウンロード URL でファイルを共有できます。有効期限を設定することも、外部の閲覧者向けに期限なしのリンクにすることもできます。',
  'Show who is online, on the same page, or typing in team chat. Presences sync status and metadata over Realtime so you can add collaboration cues to shared docs, dashboards, and support tools without building sockets.':
    '誰がオンラインか、同じページを見ているか、チームチャットで入力中かを表示できます。Presences は Realtime を通じてステータスとメタデータを同期するため、ソケットを自前で構築せずに、共有ドキュメントやダッシュボード、サポートツールにコラボレーションの表示を追加できます。',
  'Sites run on Appwrite Network with global CDN delivery, DDoS protection, Firewall, and TLS encryption. SSR workloads can execute closer to users at the edge while Auth, Databases, Storage, and other project services stay in your selected region.':
    'サイトは、グローバル CDN 配信、DDoS 保護、Firewall、TLS 暗号化を備えた Appwrite Network 上で動作します。SSR のワークロードはエッジでユーザーの近くで実行できる一方、認証、データベース、ストレージなどのプロジェクトサービスは選択したリージョンに残ります。',
  'Sites supports popular frameworks including Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, Remix, Angular, React, and more. Static hosting works with any framework that outputs HTML assets; SSR is available for supported server-rendered stacks. See the frameworks page for build settings per preset.':
    'サイトは、Next.js、Nuxt、SvelteKit、Astro、Vue、TanStack Start、Remix、Angular、React など、人気のフレームワークに対応しています。静的ホスティングは HTML アセットを出力するあらゆるフレームワークで利用でき、SSR は対応するサーバーレンダリングのスタックで利用できます。プリセットごとのビルド設定は frameworks ページをご覧ください。',
  'Spend less time waiting on builds and more time shipping updates. Cached dependencies speed up repeat deploys, path filters help monorepos skip unnecessary rebuilds, and deployment retention automatically removes old inactive deployments to save storage. Tune build and runtime CPU and memory when compilation or SSR needs more headroom.':
    'ビルドを待つ時間を減らし、更新のリリースに時間を使えます。キャッシュされた依存関係により繰り返しのデプロイが高速化され、パスフィルターによりモノレポで不要な再ビルドをスキップでき、デプロイの保持設定により古い非アクティブなデプロイが自動的に削除されてストレージを節約できます。コンパイルや SSR により多くのリソースが必要な場合は、ビルドおよびランタイムの CPU とメモリを調整できます。',
  'Start from official quick-starts or pick a template in the create wizard. Filter by framework and use case, connect GitHub or Origin, and deploy with build settings already tuned for Appwrite.':
    '公式のクイックスタートから始めるか、作成ウィザードでテンプレートを選べます。フレームワークとユースケースで絞り込み、GitHub または Origin を接続し、Appwrite 向けにあらかじめ調整されたビルド設定でデプロイできます。',
  'Start from the Console Templates tab with pre-built integrations for Stripe payments, OpenAI prompts, search sync, Discord bots, and more. Filter by use case or runtime and skip boilerplate when wiring new backend jobs.':
    'コンソールの Templates タブには、Stripe 決済、OpenAI プロンプト、検索の同期、Discord ボットなど、あらかじめ用意された連携が揃っています。ユースケースやランタイムで絞り込み、新しいバックエンド処理を組む際の定型コードを省略できます。',
  'Static and SPA hosting serves pre-built assets at the edge with fast cold starts. SSR runs your framework on each request, which suits dynamic or user-specific pages and gives you runtime access to environment variables. Many frameworks support both modes in the same app.':
    '静的・SPA ホスティングは、コールドスタートが速く、あらかじめビルドされたアセットをエッジから配信します。SSR はリクエストごとにフレームワークを実行するため、動的またはユーザーごとのページに適しており、ランタイムで環境変数にアクセスできます。多くのフレームワークは、同じアプリ内で両方のモードに対応しています。',
  'Storage is for binary files like images, videos, and PDFs. Databases store structured rows and fields. Most apps use both together: Storage for assets and Databases for metadata and relationships.':
    'ストレージは、画像や動画、PDF などのバイナリファイル向けです。データベースは構造化された行やフィールドを保存します。多くのアプリでは両方を組み合わせて使用し、アセットにはストレージ、メタデータや関連情報にはデータベースを利用します。',
  'Store and query structured data with TablesDB, native PostgreSQL, and MySQL. Permissions, relationships, vector search, and backups included.':
    'TablesDB、ネイティブの PostgreSQL、MySQL を使って構造化データを保存・クエリできます。権限、リレーション、ベクトル検索、バックアップが標準で含まれます。',
  'Store, manage, and deliver files with Appwrite Storage. Built-in CDN, regional caching, S3-compatible access, compression, encryption, on-the-fly transforms, file tokens, and secure downloads.':
    'Appwrite Storage でファイルの保存、管理、配信ができます。CDN 標準搭載、リージョンごとのキャッシュ、S3 互換アクセス、圧縮、暗号化、オンザフライ変換、ファイルトークン、安全なダウンロードに対応しています。',
  'Switch the active deployment with zero downtime and no rebuild. Pick any previous ready deployment from Overview in the Console and promote it in one click when you need to recover fast.':
    'ダウンタイムなし、再ビルドなしでアクティブなデプロイを切り替えられます。コンソールの Overview から、以前の準備完了済みのデプロイを選び、素早く復旧させたいときにワンクリックで昇格できます。',
  'Sync executions run over HTTP domains or the SDK with async set to false. Appwrite waits for your function and returns the response, with a 30 second hard limit. Async executions are queued for events, cron schedules, and SDK calls with async set to true. They run in the background and use your function timeout, up to 15 minutes.':
    '同期実行は、async を false にした HTTP ドメインまたは SDK 経由で実行されます。Appwrite は関数の完了を待ってレスポンスを返しますが、30 秒の上限があります。非同期実行は、イベントや Cron スケジュール、async を true にした SDK 呼び出しに対してキューに入れられます。バックグラウンドで実行され、関数の Timeout (最大 15 分) が適用されます。',
  'TablesDB is fastest to integrate with Appwrite SDKs and permissions. Choose PostgreSQL when you need advanced SQL, extensions like pgvector, or an existing SQL toolchain.':
    'TablesDB は、Appwrite SDK と権限との統合が最も簡単です。高度な SQL や pgvector などの拡張機能、既存の SQL ツールチェーンが必要な場合は PostgreSQL を選択してください。',
  'Targets are the ways a user can be reached: email addresses, phone numbers, and push device tokens. Subscribe targets to a topic to broadcast the same message to every subscriber, or address specific users and targets when you need private, one-to-one delivery. Topics fit newsletters and announcements; sensitive content like chat should go to individual targets.':
    'ターゲットとは、メールアドレス、電話番号、push デバイストークンなど、ユーザーに到達する手段のことです。トピックにターゲットを登録すれば、すべての購読者に同じメッセージを一斉配信できます。1 対 1 のプライベートな配信が必要な場合は、特定のユーザーやターゲットを直接指定できます。トピックはニュースレターやアナウンスに適しており、チャットのような機密性の高い内容は個別のターゲットに送るべきです。',
  'Track requests, bandwidth, builds, and compute over time with breakdowns by path, asset type, or region. Inspect individual requests with status, headers, and SSR console output in the same view.':
    'パス、アセットの種類、リージョンごとの内訳とともに、リクエスト、帯域幅、ビルド、コンピュートの推移を追跡できます。同じ画面から、個々のリクエストのステータス、ヘッダー、SSR のコンソール出力を確認できます。',
  'Turn on Magic URL, Email OTP, and Phone SMS from Auth settings. Ship secure sign-in without storing or resetting passwords.':
    '認証設定から Magic URL、Email OTP、Phone SMS を有効化できます。パスワードの保存やリセットを行うことなく、安全なサインインを提供できます。',
  'Turn on bucket encryption from Settings so new uploads are stored encrypted at rest. If files are exposed, encrypted objects stay unreadable without your project keys.':
    'Settings からバケットの暗号化を有効にすると、新しいアップロードは保存時に暗号化された状態で保存されます。万が一ファイルが流出しても、暗号化されたオブジェクトはプロジェクトの鍵がなければ読み取れません。',
  'Use Git for automatic builds on push, the Appwrite CLI in CI, or a manual tarball upload from the Console. Every path runs through the same build pipeline, logs, domains, and rollbacks.':
    'push 時の自動ビルドには Git を、CI では Appwrite CLI を、あるいはコンソールから手動で tarball をアップロードすることもできます。どの方法でも、同じビルドパイプライン、ログ、ドメイン、ロールバックの仕組みを利用できます。',
  'Use the Appwrite CLI and Docker to run functions on localhost with hot reload. Test with production-style headers, impersonate users, and deploy when you are ready.':
    'Appwrite CLI と Docker を使って、ホットリロード付きで関数をローカルホストで実行できます。本番相当のヘッダーでテストしたり、ユーザーになりすましたりでき、準備が整ったらデプロイできます。',
  'Use the preview endpoint to resize, crop, convert format, set quality, add borders, and rotate from a single upload. Pass gravity=auto and YuNet looks for a face first. If none is confident enough, U²-Net saliency returns a normalized (x, y) crop focus. Fixed gravity values still work.':
    'preview エンドポイントで、1 回のアップロードからリサイズ、クロップ、フォーマット変換、画質、枠線、回転ができます。gravity=auto を渡すと、YuNet が先に顔を探します。十分な確度がなければ、U²-Net の saliency が正規化された (x, y) のクロップ焦点を返します。固定の gravity 値はそのまま使えます。',
  'Use the preview endpoint to resize, crop, convert format, set quality, add borders, and rotate images on demand. No pre-processing pipeline or duplicate files.':
    'プレビューエンドポイントを使えば、画像のリサイズ、切り抜き、フォーマット変換、画質設定、枠線の追加、回転をオンデマンドで行えます。事前処理のパイプラインや重複ファイルは不要です。',
  'Verify sessions from Next.js, Nuxt, SvelteKit, and other server-rendered apps. Issue session cookies from your backend with dedicated guides and tutorials.':
    'Next.js、Nuxt、SvelteKit などのサーバーレンダリングアプリからセッションを検証できます。専用ガイドとチュートリアルに沿って、バックエンドからセッション Cookie を発行できます。',
  'When you push to a branch other than your production branch, Appwrite builds a deployment but does not activate it on your primary domain. Instead, a preview URL is generated for org members to review. Pull requests can also receive preview links and optional PR comments unless silent mode is enabled.':
    '本番ブランチ以外のブランチに push すると、Appwrite はデプロイをビルドしますが、メインドメインでは有効化しません。代わりに、組織のメンバーがレビューできるプレビュー URL が生成されます。サイレントモードが有効でない限り、プルリクエストにもプレビューリンクとオプションの PR コメントが付与されます。',
  'Yes. Add multiple domain rules on a site: point one hostname to the active production deployment, map another to a specific Git branch for staging, or configure redirects. Branch and commit preview URLs are also generated automatically for Git deployments.':
    'はい。1 つのサイトに複数のドメインルールを追加できます。あるホスト名をアクティブな本番デプロイに向けたり、別のホスト名をステージング用の特定の Git ブランチに割り当てたり、リダイレクトを設定したりできます。Git デプロイでは、ブランチやコミットごとのプレビュー URL も自動的に生成されます。',
  'Yes. Appwrite Auth is API-first, so you keep full control of the UI in your app and call the Account SDK for sign-up, login, sessions, and MFA. Quick starts cover React, Next.js, Vue, SvelteKit, Flutter, and other platforms. For server-rendered apps, verify sessions on your backend and issue HTTP-only cookies using the SSR guides.':
    'はい。Appwrite Auth は API ファーストなので、アプリの UI は完全に自分たちで制御しつつ、サインアップ、ログイン、セッション、MFA には Account SDK を呼び出すだけです。クイックスタートは React、Next.js、Vue、SvelteKit、Flutter などのプラットフォームに対応しています。サーバーレンダリングされるアプリでは、SSR ガイドに沿ってバックエンドでセッションを検証し、HTTP only な Cookie を発行できます。',
  'Yes. Appwrite Storage exposes a project-scoped HTTPS endpoint with SigV4-compatible signing. Copy the endpoint, access key, and secret from the Connect tab in your project to attach buckets to rclone, Terraform, or other S3 tooling without rebuilding upload pipelines.':
    'はい。Appwrite Storage は、SigV4 互換の署名を使ったプロジェクト単位の HTTPS エンドポイントを公開しています。プロジェクトの Connect タブからエンドポイント、アクセスキー、シークレットをコピーすれば、アップロードのパイプラインを作り直すことなく、バケットを rclone や Terraform、その他の S3 ツールに接続できます。',
  'Yes. Appwrite supports OAuth 2.0 with 30+ providers, including GitHub, Google, Apple, Discord, and Microsoft. Enable providers in the Console under Auth > Social providers, add your OAuth credentials and redirect URI, then start the flow from the Account SDK. Each OAuth sign-in creates an identity linked to the user account, so one person can connect multiple providers without duplicate accounts.':
    'はい。Appwrite は、GitHub、Google、Apple、Discord、Microsoft など 30 以上のプロバイダーで OAuth 2.0 をサポートしています。コンソールの 認証 > OAuth プロバイダー でプロバイダーを有効にし、OAuth の認証情報とリダイレクト URI を追加すれば、Account SDK からフローを開始できます。OAuth によるサインインのたびに、ユーザーアカウントに紐づく ID が作成されるため、1 人のユーザーが複数のプロバイダーを、アカウントを重複させることなく接続できます。',
  'Yes. Browse templates from Sites > Templates in the Console and filter by framework or use case. The create wizard walks you through GitHub or Origin setup, production branch, environment variables, and domain configuration. Official quick-starts cover Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, and more.':
    'はい。コンソールの サイト > Templates からテンプレートを閲覧し、フレームワークやユースケースで絞り込めます。作成ウィザードでは、GitHub または Origin の設定、本番ブランチ、環境変数、ドメインの設定を順を追って行えます。公式のクイックスタートは、Next.js、Nuxt、SvelteKit、Astro、Vue、TanStack Start などに対応しています。',
  'Yes. Buckets and files have no permissions by default, so access is denied until you grant read, create, update, or delete to users, teams, or roles. Enable file security on a bucket to set per-file permissions on top of bucket defaults.':
    'はい。バケットとファイルにはデフォルトで権限が設定されておらず、ユーザー、チーム、ロールに読み取り、作成、更新、削除の権限を付与するまでアクセスは拒否されます。バケットでファイルセキュリティを有効にすると、バケットのデフォルト設定に加えて、ファイルごとの権限を設定できます。',
  'Yes. Compose email, SMS, and push from the Console Messages tab or call createEmail, createSms, and createPush from the Server SDK. Send immediately, save as a draft, or pass scheduledAt for later delivery. Every message appears in the Messages tab with status (draft, scheduled, processing, failed, or success) and delivery timestamps.':
    'はい。コンソールの Messages タブからメール、SMS、push を作成するか、Server SDK の createEmail、createSms、createPush を呼び出せます。即座に送信することも、下書きとして保存することも、scheduledAt を指定して後で配信することもできます。すべてのメッセージは、ステータス (下書き、予約済み、処理中、失敗、成功) と配信日時とともに Messages タブに表示されます。',
  'Yes. Enable gzip or zstd compression per bucket from Settings. Compression applies to new uploads and helps reduce storage and bandwidth costs. Files larger than 20 MB skip compression even when enabled.':
    'はい。Settings からバケットごとに gzip または zstd の圧縮を有効にできます。圧縮は新しいアップロードに適用され、ストレージと帯域幅のコスト削減に役立ちます。20 MB を超えるファイルは、有効にしていても圧縮の対象外です。',
  'Yes. Every function gets a generated domain and you can add custom domains on Appwrite Cloud. Pass x-appwrite-user-jwt to authenticate users and respect Auth permissions inside your function.':
    'はい。すべての関数には生成されたドメインが割り当てられ、Appwrite Cloud ではカスタムドメインを追加することもできます。x-appwrite-user-jwt を渡せば、関数内でユーザーを認証し、認証の権限を尊重できます。',
  'Yes. Functions receive an ephemeral API key and run with project context. Configure scopes in Settings, then call Databases, Storage, Messaging, Auth, and other APIs from server SDKs inside your handler.':
    'はい。Functions は一時的な API キーを受け取り、プロジェクトのコンテキストで実行されます。Settings でスコープを設定すれば、ハンドラー内の server SDK からデータベース、ストレージ、Messaging、認証などの API を呼び出せます。',
  "Yes. Import users through the Console or the Users API with the Server SDK. For email and password accounts, create users with plain-text passwords or import existing password hashes when your provider uses a supported algorithm: Argon2, bcrypt, scrypt, scrypt-modified (Firebase), SHA, MD5, or PHPass. New passwords are stored with Argon2. Hashes imported from other algorithms are upgraded to Argon2 after the user's first successful sign-in.":
    'はい。コンソール、または Server SDK を使った Users API 経由でユーザーをインポートできます。メールとパスワードのアカウントでは、平文パスワードでユーザーを作成することも、Argon2、bcrypt、scrypt、scrypt-modified (Firebase)、SHA、MD5、PHPass のいずれかの対応アルゴリズムを使っている場合は既存のパスワードハッシュをインポートすることもできます。新しいパスワードは Argon2 で保存されます。他のアルゴリズムからインポートされたハッシュは、ユーザーが最初にサインインに成功した時点で Argon2 にアップグレードされます。',
  'Yes. Purchase domains from your organization Domains tab and manage records with Appwrite DNS in the same Console. Connect the domain to a site for automatic TLS, or use the generated .appwrite.network URL while you set up DNS. Apex domains can delegate to Appwrite nameservers; subdomains use CNAME records. Sites traffic is delivered through Appwrite Network with CDN, DDoS protection, and Firewall.':
    'はい。組織の Domains タブからドメインを購入し、同じコンソールの Appwrite DNS でレコードを管理できます。ドメインをサイトに接続すれば TLS が自動的に発行され、DNS を設定している間は生成された .appwrite.network の URL を利用できます。ルートドメインは Appwrite のネームサーバーに委任でき、サブドメインには CNAME レコードを使用します。サイトのトラフィックは、CDN、DDoS 保護、Firewall を備えた Appwrite Network を通じて配信されます。',
  'Yes. Push deployments with the Appwrite CLI from CI or your machine, or upload a .tar.gz archive from the Console for manual deploys. Git remains the recommended path for automatic builds on push and branch previews, but every deploy method uses the same build pipeline and settings.':
    'はい。CI や自分のマシンから Appwrite CLI でデプロイを push することも、コンソールから .tar.gz アーカイブをアップロードして手動でデプロイすることもできます。push 時の自動ビルドやブランチプレビューには引き続き Git を利用する方法を推奨していますが、どのデプロイ方法でも同じビルドパイプラインと設定が使われます。',
  'Yes. Request transformations through the preview endpoint to resize, crop, convert format, and adjust quality on the fly. Keep one original upload and let Appwrite generate variants on demand.':
    'はい。プレビューエンドポイントに対してリクエストすれば、リサイズ、切り抜き、フォーマット変換、画質調整をその場で行えます。オリジナルのアップロードは 1 つだけ保持し、バリアントは Appwrite にオンデマンドで生成させられます。',
  'Yes. Row and table permissions can reference users, teams, and roles from Appwrite Auth.':
    'はい。行やテーブルの権限では、Appwrite Auth のユーザー、チーム、ロールを参照できます。',
  'Yes. Sites deploy in the same project as Auth, Databases, Storage, Functions, and Messaging. Use environment variables for API keys and endpoints, then call Appwrite SDKs from your frontend or SSR routes without managing separate infrastructure.':
    'はい。サイトは、認証、データベース、ストレージ、Functions、Messaging と同じプロジェクトにデプロイされます。API キーやエンドポイントには環境変数を使い、別のインフラを管理することなく、フロントエンドや SSR のルートから Appwrite SDK を呼び出せます。',
  'Yes. Storage files and transformed previews are served through Appwrite CDN with 120+ edge locations. Transformed images are cached in your project region, so repeat requests skip re-processing before reaching the edge.':
    'はい。ストレージのファイルと変換後のプレビューは、120 以上のエッジロケーションを持つ Appwrite CDN を通じて配信されます。変換後の画像はプロジェクトのリージョンでキャッシュされるため、繰り返しのリクエストはエッジに届く前に再処理をスキップします。',
  'Yes. Storage supports chunked uploads for large files through the SDKs and Console. Configure maximum file size per bucket and use resumable uploads when transferring big assets.':
    'はい。ストレージは、SDK やコンソールから大きなファイルのチャンクアップロードに対応しています。バケットごとに最大ファイルサイズを設定し、大きなアセットを転送する際には再開可能なアップロードを利用できます。',
  'Yes. The Appwrite CLI runs your function in Docker on localhost with hot reload, the same runtime image as production, and optional user impersonation for Auth-aware testing.':
    'はい。Appwrite CLI は、本番と同じランタイムイメージを使い、ホットリロード付きで関数を Docker 上のローカルホストで実行します。認証を考慮したテストのために、ユーザーになりすますこともできます。',
  'Yes. Turn on encryption per bucket from Settings so new files are stored encrypted at rest. If files are leaked, encrypted objects cannot be read without your keys.':
    'はい。Settings からバケットごとに暗号化を有効にすると、新しいファイルは保存時に暗号化された状態で保存されます。万が一ファイルが流出しても、暗号化されたオブジェクトは鍵がなければ読み取れません。',
  'Yes. Usage charts show requests, bandwidth, builds, and compute over selectable ranges, with breakdowns to see where traffic comes from. The Logs tab records every request with status code, method, path, and duration. Open a log entry for request and response headers. For SSR sites, console.log and console.error output appears in response logs.':
    'はい。使用状況のグラフでは、選択した期間ごとにリクエスト、帯域幅、ビルド、コンピュートを確認でき、トラフィックの発生元も内訳で確認できます。Logs タブには、ステータスコード、メソッド、パス、所要時間とともにすべてのリクエストが記録されます。ログのエントリを開けば、リクエストとレスポンスのヘッダーを確認できます。SSR サイトでは、console.log と console.error の出力がレスポンスログに表示されます。',
  'Yes. Use the Server SDK from Functions, your API server, or any trusted backend with a project API key. This is the standard pattern for transactional flows such as OTP verification, password reset, order receipts, and inventory alerts triggered by platform events or custom logic.':
    'はい。Functions、自前の API サーバー、またはプロジェクトの API キーを持つ信頼できる任意のバックエンドから Server SDK を利用できます。これは、プラットフォームのイベントや独自のロジックによってトリガーされる OTP 認証、パスワードリセット、注文の受領確認、在庫アラートなどのトランザクションフローに使われる標準的なパターンです。',
  '2 filters': '2 件のフィルター',
  '3 rules': '3 件のルール',
  'Add read replicas on dedicated databases to absorb query load and improve failover resilience. Enable high availability when replica count is greater than zero, then tune sync mode and failover from Replication settings.':
    '専用データベースにリードレプリカを追加してクエリ負荷を分散し、フェイルオーバー耐性を高めます。レプリカ数が 0 より大きいときに high availability が有効になります。その後、Replication 設定で同期モードとフェイルオーバーを調整できます。',
  'Appwrite Databases include five engines in two categories. Appwrite DBs are TablesDB for relational-style tables and columns, DocumentsDB for flexible JSON documents, and VectorsDB for embeddings and similarity search. Native DBs are managed PostgreSQL and MySQL for teams that need full SQL compatibility, extensions, and portable schemas.':
    'Appwrite Databases は 2 つのカテゴリに分かれた 5 つのエンジンを含みます。Appwrite DBs は、リレーショナル風のテーブルとカラム向けの TablesDB、柔軟な JSON ドキュメント向けの DocumentsDB、埋め込みと類似検索向けの VectorsDB です。Native DBs は、フル SQL 互換性、拡張機能、移植可能なスキーマが必要なチーム向けのマネージド PostgreSQL と MySQL です。',
  'Appwrite DBs': 'Appwrite DBs',
  'Appwrite DBs cover tables, documents, and vectors. Native DBs bring managed PostgreSQL and MySQL when you need full SQL control. Pick the model that matches your data, then operate every engine from the same Console and project.':
    'Appwrite DBs はテーブル、ドキュメント、ベクトルをカバーします。Native DBs は、フル SQL 制御が必要なときにマネージド PostgreSQL と MySQL を提供します。データに合うモデルを選び、同じ Console とプロジェクトからすべてのエンジンを運用できます。',
  'Appwrite still supports legacy Collections APIs alongside TablesDB and DocumentsDB. Docs cover migration paths, compatibility notes, and how to move schemas and documents without disrupting clients.':
    'Appwrite は TablesDB と DocumentsDB に加えて、レガシーの Collections API も引き続きサポートします。ドキュメントでは移行パス、互換性の注意点、クライアントを止めずにスキーマとドキュメントを移す方法を案内しています。',
  'Are backups and PITR included?': 'バックアップと PITR は含まれますか?',
  'Auth linked': '認証連携済み',
  'Automate encrypted hot backups with policies, or create a manual backup when you need a snapshot now. Enable PITR on dedicated databases to restore to a specific moment after accidental deletes, failed migrations, or bad writes.':
    'ポリシーで暗号化されたホットバックアップを自動化するか、今すぐスナップショットが必要なときに手動バックアップを作成します。専用データベースで PITR を有効にすると、誤削除、失敗したマイグレーション、不正な書き込みのあとでも特定時点へ復元できます。',
  'Backups and point-in-time recovery':
    'バックアップとポイントインタイムリカバリ',
  'Backups docs': 'バックアップのドキュメント',
  'Can I query, relate, and bulk-update data from the SDKs?':
    'SDK からクエリ、リレーション、一括更新はできますか?',
  'Choose how this database is provisioned.':
    'このデータベースのプロビジョニング方法を選択します。',
  'Choose TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs, Console workflows, and Auth-aware permissions out of the box. Choose PostgreSQL or MySQL when you need advanced SQL, existing ORM tooling, extensions such as pgvector, or to run schemas you already operate elsewhere.':
    'Appwrite SDK、Console のワークフロー、認証連携の権限をすぐ使いたいときは TablesDB、DocumentsDB、VectorsDB を選びます。高度な SQL、既存の ORM、pgvector などの拡張、または別環境で運用中のスキーマが必要なときは PostgreSQL か MySQL を選びます。',
  'Commit multi-step writes atomically.':
    '複数ステップの書き込みをアトミックにコミットします。',
  'Compute model': 'コンピュートモデル',
  'Compute models': 'コンピュートモデル',
  'Connect with standard SQL clients, ORMs, and the in-console query editor. Keep portable schemas, use the extensions your stack needs, and manage roles, connections, and backups alongside your Appwrite project.':
    '標準の SQL クライアント、ORM、コンソール内クエリエディタで接続できます。移植可能なスキーマを保ち、スタックに必要な拡張を使い、ロール、接続、バックアップを Appwrite プロジェクトと一緒に管理します。',
  'Connections, schemas, and backups': '接続、スキーマ、バックアップ',
  'Create a database, choose your engine and compute model, and query your first data in minutes.':
    'データベースを作成し、エンジンとコンピュートモデルを選んで、数分で最初のデータをクエリできます。',
  'Databases docs': 'データベースのドキュメント',
  'Does Appwrite offer managed PostgreSQL?':
    'Appwrite はマネージド PostgreSQL を提供していますか?',
  'Managed PostgreSQL and databases for every model':
    'マネージド PostgreSQL とあらゆるモデル向けのデータベース',
  'Managed PostgreSQL and MySQL': 'マネージド PostgreSQL と MySQL',
  'Managed PostgreSQL hosting and app databases':
    'マネージド PostgreSQL ホスティングとアプリ向けデータベース',
  'Managed PostgreSQL hosting with full SQL, pgvector, and portable schemas for Prisma, Drizzle, and existing tooling.':
    'フル SQL、pgvector、Prisma や Drizzle などの既存ツール向けの移植可能なスキーマを備えたマネージド PostgreSQL ホスティング。',
  'Managed PostgreSQL hosting with pgvector, plus TablesDB, DocumentsDB, and VectorsDB. Dedicated compute, backups, replicas, and PITR.':
    'pgvector 対応のマネージド PostgreSQL ホスティングに加え、TablesDB、DocumentsDB、VectorsDB。専用コンピュート、バックアップ、レプリカ、PITR。',
  'PostgreSQL docs': 'PostgreSQL のドキュメント',
  'PostgreSQL quick start': 'PostgreSQL のクイックスタート',
  'Dedicated managed PostgreSQL and MySQL engines you connect to with standard SQL clients.':
    '標準の SQL クライアントで接続する専用マネージド PostgreSQL および MySQL エンジン。',
  'Hosted PostgreSQL and MySQL with standard clients and the in-console SQL editor. Use pgvector, PostGIS, portable schemas, and the ORMs you already run.':
    '標準クライアントとコンソール内 SQL エディタで使えるホスト型 PostgreSQL と MySQL。pgvector、PostGIS、移植可能なスキーマ、既存の ORM をそのまま使えます。',
  'Run managed PostgreSQL next to tables, documents, and vectors. Connect with Prisma or psql, install pgvector, and scale dedicated compute with backups, replicas, and PITR.':
    'テーブル、ドキュメント、ベクトルと並べてマネージド PostgreSQL を実行します。Prisma または psql で接続し、pgvector をインストールし、バックアップ、レプリカ、PITR 付きの専用コンピュートをスケールできます。',
  'Yes. Native PostgreSQL databases are dedicated, managed PostgreSQL instances in your project region. You connect with psql, Prisma, Drizzle, or any PostgreSQL driver over TLS. Appwrite provisions compute, backups, replicas, a connection pooler, and point-in-time recovery. PostgreSQL 19 is the default, with 17 and 18 also supported.':
    'はい。ネイティブ PostgreSQL データベースは、プロジェクトのリージョンにプロビジョニングされる専用のマネージド PostgreSQL インスタンスです。psql、Prisma、Drizzle、または任意の PostgreSQL ドライバーで TLS 経由で接続します。Appwrite がコンピュート、バックアップ、レプリカ、コネクションプーラー、ポイントインタイムリカバリを用意します。既定は PostgreSQL 19 で、17 と 18 もサポートされます。',
  'Databases for every data model': 'あらゆるデータモデル向けのデータベース',
  'Do Appwrite DBs integrate with Auth permissions?':
    'Appwrite DBs は認証の権限と連携しますか?',
  'Embeddings and similarity search for semantic retrieval and AI features.':
    'セマンティック検索と AI 機能向けの埋め込みと類似検索。',
  'Extensions, roles, SQL editor': '拡張機能、ロール、SQL エディタ',
  'Familiar MySQL compatibility for common relational apps and migrations.':
    '一般的なリレーショナルアプリとマイグレーション向けの馴染みある MySQL 互換性。',
  'Filter, order, and paginate from the SDKs and Console. Model related data with relationships, run multi-step writes in transactions, and use bulk operations when you need to update many rows or documents at once.':
    'SDK と Console からフィルター、並び替え、ページネーションができます。リレーションで関連データをモデル化し、トランザクションで複数ステップの書き込みを実行し、多数の行やドキュメントを一度に更新するときは一括操作を使います。',
  'Five engines, two categories': '5 つのエンジン、2 つのカテゴリ',
  'Five engines for tables, documents, vectors, and native SQL':
    'テーブル、ドキュメント、ベクトル、ネイティブ SQL 向けの 5 つのエンジン',
  'Flexible JSON documents with filters and full-text search for evolving schemas.':
    '変化するスキーマ向けに、フィルターと全文検索付きの柔軟な JSON ドキュメント。',
  'Full SQL, extensions, and portable schemas for relational workloads and existing tooling.':
    'リレーショナルワークロードと既存ツール向けのフル SQL、拡張機能、移植可能なスキーマ。',
  'HA enabled': 'HA 有効',
  'Hot backups with zero downtime and fast recovery.':
    'ダウンタイムなしのホットバックアップと迅速なリカバリ。',
  'How do replication and high availability work?':
    'レプリケーションと high availability はどのように動作しますか?',
  'Ideal for prototypes': 'プロトタイプに最適',
  'Instant provisioning': '即座のプロビジョニング',
  'Isolated compute with replicas, HA, and PITR.':
    'レプリカ、HA、PITR 付きの分離コンピュート。',
  'Isolated resources': '分離リソース',
  'Legacy collections': 'レガシー Collections',
  'Legacy documents': 'レガシー Documents',
  'Link related tables without custom joins.':
    'カスタム JOIN なしで関連テーブルをリンクします。',
  'Native DBs': 'Native DBs',
  'Native SQL for PostgreSQL and MySQL':
    'PostgreSQL と MySQL 向けのネイティブ SQL',
  'On dedicated databases you can add read replicas to scale query traffic and improve failover resilience. High availability is enabled when replica count is greater than zero. Configure sync mode and failover from the Console Replication settings for supported engines.':
    '専用データベースではリードレプリカを追加してクエリトラフィックをスケールし、フェイルオーバー耐性を高められます。レプリカ数が 0 より大きいときに high availability が有効になります。対応エンジンでは Console の Replication 設定から同期モードとフェイルオーバーを構成できます。',
  'Permissions wired to Auth': '認証と連携した権限',
  'Pick the engine that fits your workload, then scale it the same way. Appwrite Databases cover structured tables, documents, vectors, and native SQL, with serverless or dedicated compute, replication, backups, and point-in-time recovery.':
    'ワークロードに合うエンジンを選び、同じ方法でスケールします。Appwrite Databases は構造化テーブル、ドキュメント、ベクトル、ネイティブ SQL をカバーし、サーバーレスまたは専用コンピュート、レプリケーション、バックアップ、ポイントインタイムリカバリに対応します。',
  'Queries docs': 'クエリのドキュメント',
  'Queries, relationships, and transactions':
    'クエリ、リレーション、トランザクション',
  'Read replicas & HA': 'リードレプリカと HA',
  'Relational-style tables, columns, and indexes for structured data and complex queries.':
    '構造化データと複雑なクエリ向けのリレーショナル風テーブル、カラム、インデックス。',
  Relationships: 'リレーション',
  'Replication and high availability': 'レプリケーションと high availability',
  'Restore to a specific moment beyond the latest backup.':
    '最新バックアップより先の特定時点へ復元します。',
  'Row-level permissions': '行レベルの権限',
  'Scale query traffic and improve failover resilience.':
    'クエリトラフィックをスケールし、フェイルオーバー耐性を高めます。',
  'Scope access with Auth users, teams, and roles.':
    '認証ユーザー、チーム、ロールでアクセスをスコープします。',
  'Scope TablesDB and DocumentsDB access with users, teams, and roles from Appwrite Auth. Set rules at the table, collection, row, or document level so each tenant only sees their data.':
    'Appwrite Auth のユーザー、チーム、ロールで TablesDB と DocumentsDB へのアクセスをスコープします。テーブル、コレクション、行、ドキュメント単位でルールを設定し、各顧客が自分のデータだけを見られるようにします。',
  'Serverless databases run on a shared pool and are the fastest way to start. Billing is usage-based: there is no fixed compute fee, and you pay for storage plus reads and writes against your plan quota (then overage). Dedicated databases provision isolated compute for predictable performance, higher connection limits, and production options such as read replicas, high availability, and point-in-time recovery. Billing is a fixed monthly compute tier per database (from $10/mo), with reads and writes included in the tier. HA replicas and PITR are optional add-ons, and extra storage or bandwidth is billed as overage. You pick a specification when you create the database and can upgrade later.':
    'サーバーレスデータベースは共有プールで動作し、いちばん早く始められます。課金は従量制で、固定のコンピュート料金はなく、プラン枠内のストレージと読み書き（超過分は overage）に対して支払います。専用データベースは分離コンピュートを確保し、予測可能な性能、高い接続上限、リードレプリカ、high availability、ポイントインタイムリカバリなどの本番向けオプションを利用できます。課金はデータベースごとの固定月額コンピュート階層（$10/月〜）で、読み書きはその階層に含まれます。HA レプリカと PITR はオプションのアドオンで、追加のストレージや帯域は overage として課金されます。作成時に仕様を選び、後からアップグレードもできます。',
  'Serverless or dedicated compute': 'サーバーレスまたは専用コンピュート',
  'Shared compute pool': '共有コンピュートプール',
  'Shared pool. Fast to create, no capacity planning.':
    '共有プール。すばやく作成でき、キャパシティ計画は不要です。',
  'Start on a shared serverless pool when you want speed and simplicity. Move to dedicated specifications when you need isolated resources, higher connection limits, and production options like replicas and PITR. Choose at create time or upgrade later.':
    '速さとシンプルさを優先するときは共有サーバーレスプールから始めます。分離リソース、高い接続上限、レプリカや PITR などの本番オプションが必要になったら専用仕様へ移ります。作成時に選ぶか、後からアップグレードできます。',
  'Store and query data with TablesDB, DocumentsDB, VectorsDB, PostgreSQL, and MySQL. Choose serverless or dedicated, with replication, backups, and PITR.':
    'TablesDB、DocumentsDB、VectorsDB、PostgreSQL、MySQL でデータを保存・クエリできます。サーバーレスまたは専用を選び、レプリケーション、バックアップ、PITR を利用できます。',
  Transactions: 'トランザクション',
  'What database engines does Appwrite offer?':
    'Appwrite はどのデータベースエンジンを提供しますか?',
  'What is the difference between serverless and dedicated databases?':
    'サーバーレスデータベースと専用データベースの違いは何ですか?',
  'When should I use Appwrite DBs vs native PostgreSQL or MySQL?':
    'Appwrite DBs とネイティブの PostgreSQL / MySQL、どちらを使うべきですか?',
  'Yes on Appwrite Cloud for supported plans and engines. Create automated backup policies or run manual backups from the Backups tab. Point-in-time recovery (PITR) on dedicated databases lets you restore to a specific moment beyond the latest scheduled backup, which helps after accidental deletes, failed migrations, or bad writes.':
    '対応プランとエンジンでは Appwrite Cloud で利用できます。自動バックアップポリシーを作成するか、Backups タブから手動バックアップを実行できます。専用データベースのポイントインタイムリカバリ (PITR) では、最新のスケジュールバックアップより先の特定時点へ復元でき、誤削除、失敗したマイグレーション、不正な書き込みのあとに役立ちます。',
  'Yes. Appwrite DBs support filters, ordering, pagination, relationships, transactions, bulk operations, and geo queries through the SDKs and Console. Native PostgreSQL and MySQL databases support full SQL from the in-console editor and your existing SQL clients.':
    'はい。Appwrite DBs は SDK と Console 経由でフィルター、並び替え、ページネーション、リレーション、トランザクション、一括操作、地理クエリをサポートします。ネイティブの PostgreSQL と MySQL は、コンソール内エディタと既存の SQL クライアントからフル SQL を使えます。',
  'Yes. TablesDB and DocumentsDB permissions can reference users, teams, and roles from Appwrite Auth at the table, collection, row, and document level. Scope data per customer or workspace without building custom access control.':
    'はい。TablesDB と DocumentsDB の権限は、テーブル、コレクション、行、ドキュメント単位で Appwrite Auth のユーザー、チーム、ロールを参照できます。カスタムアクセス制御を作らずに、顧客やワークスペースごとにデータをスコープできます。',
  'Accepts writes and serves as the source of truth for replicas.':
    '書き込みを受け付け、レプリカの信頼できる情報源として機能します。',
  'Active Record and Data Mapper patterns for TypeScript.':
    'TypeScript 向けの Active Record と Data Mapper パターン。',
  'Connect with standard SQL clients and the in-console query editor. Keep portable schemas, use the extensions your stack needs, and manage roles, connections, and backups alongside your Appwrite project.':
    '標準の SQL クライアントとコンソール内クエリエディタで接続できます。移植可能なスキーマを保ち、スタックに必要な拡張を使い、ロール、接続、バックアップを Appwrite プロジェクトと一緒に管理します。',
  'Dedicated databases run behind a connection pooler with a primary for writes and read replicas for query scale and failover. Choose asynchronous, synchronous, or quorum sync mode, then promote a replica when you need to move write traffic.':
    '専用データベースはコネクションプーラーの背後で動作し、書き込み用のプライマリと、クエリのスケールとフェイルオーバー向けのリードレプリカを持ちます。Asynchronous、Synchronous、Quorum の同期モードを選び、書き込みトラフィックを移す必要があるときにレプリカを昇格できます。',
  'Lightweight TypeScript ORM with SQL-like query builder.':
    'SQL 風クエリビルダー付きの軽量 TypeScript ORM。',
  'Official PostgreSQL CLI for ad-hoc SQL and schema exploration.':
    'アドホック SQL とスキーマ探索向けの公式 PostgreSQL CLI。',
  'On serverless databases, replication and high availability are abstracted and managed by the platform, so you do not configure replicas or sync mode yourself. On dedicated databases, traffic can enter through a connection pooler such as PgDog or ProxySQL. A primary instance accepts reads and writes, and read replicas scale query traffic and improve failover resilience. High availability is enabled when replica count is greater than zero. Choose asynchronous, synchronous, or quorum sync mode, and promote a replica from the Console when you need to move write traffic.':
    'サーバーレスデータベースでは、レプリケーションと high availability はプラットフォーム側で抽象化・管理されるため、レプリカや同期モードを自分で設定する必要はありません。専用データベースでは、PgDog や ProxySQL などのコネクションプーラー経由でトラフィックを受け取れます。プライマリは読み書きを受け付け、リードレプリカはクエリトラフィックをスケールし、フェイルオーバー耐性を高めます。レプリカ数が 0 より大きいときに high availability が有効になります。Asynchronous、Synchronous、Quorum の同期モードを選び、書き込みトラフィックを移す必要があるときは Console からレプリカを昇格できます。',
  'Promise-based ORM for Node.js with multi-dialect support.':
    '複数ダイアレクト対応の Node.js 向け Promise ベース ORM。',
  'Proxy, primary, and read replicas for high availability.':
    'high availability 向けの Proxy、プライマリ、リードレプリカ。',
  'Python SQL toolkit and ORM for expressive queries.':
    '表現力のあるクエリ向けの Python SQL ツールキットと ORM。',
  'Routes client connections and pools traffic to the cluster.':
    'クライアント接続をルーティングし、クラスタへのトラフィックをプールします。',
  'Scale query traffic and stand ready for failover promotion.':
    'クエリトラフィックをスケールし、フェイルオーバー昇格に備えます。',
  'Type-safe schema and client for Node.js and TypeScript.':
    'Node.js と TypeScript 向けの型安全なスキーマとクライアント。',
  'Use the same connection strings with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your SQL toolchain. Copy ready-made snippets from the Console Connect tab and keep shipping with the stack your team already knows.':
    'Prisma、Drizzle、Sequelize、TypeORM、SQLAlchemy、psql など、既存の SQL ツールチェーンと同じ接続文字列を使えます。Console の Connect タブから用意済みスニペットをコピーして、チームがすでに知っているスタックのまま開発を続けられます。',
  'Works with your ORM and toolstack': 'お使いの ORM とツールスタックに対応',

  // Firewall product page
  '24h ago': '24時間前',
  '5 attributes': '5 属性',
  'A specific Function execution endpoint.':
    '特定の Function 実行エンドポイント。',
  'A specific Site deployment hostname.': '特定の Site デプロイホスト名。',
  'Actions docs': 'アクションのドキュメント',
  'All conditions must match (AND).':
    'すべての条件が一致する必要があります (AND)。',
  'Allow the request and skip later Firewall rules.':
    'リクエストを許可し、後続の Firewall ルールをスキップします。',
  'Apply policies to the project API, a specific Function, or a specific Site. Keep production APIs locked down while preview sites and health checks stay reachable.':
    'プロジェクト API、特定の Function、または特定の Site にポリシーを適用します。プレビューサイトとヘルスチェックは到達可能なまま、本番 API をロックダウンできます。',
  'Can I preview impact before enabling a rule?':
    'ルールを有効にする前に影響をプレビューできますか?',
  'Choose where the rule evaluates matching traffic.':
    '一致するトラフィックをルールが評価する対象を選びます。',
  'Condition matching': '条件マッチング',
  'Conditions docs': '条件のドキュメント',
  Continue: '続行',
  'Control traffic before it reaches your app':
    'アプリに届く前にトラフィックを制御',
  'Create your first deny, bypass, rate limit, or redirect rule from the Console and preview impact before you enable it.':
    'Console から最初の拒否、バイパス、レート制限、またはリダイレクトルールを作成し、有効化する前に影響をプレビューできます。',
  'Define project rules that match requests by IP, path, method, country, or user agent, then deny, bypass, rate limit, or redirect them before they hit your API, Functions, or Sites.':
    'IP、パス、メソッド、国、またはユーザーエージェントでリクエストに一致するプロジェクトルールを定義し、API、Functions、Sites に届く前に拒否、バイパス、レート制限、またはリダイレクトします。',
  'Deny account mutations': 'アカウント変更を拒否',
  'Deny, bypass, rate limit, or redirect':
    '拒否、バイパス、レート制限、リダイレクト',
  'Each matching rule applies one action: Deny returns 403, Bypass allows the request and skips later rules, Rate limit throttles per client IP with a 429 when over quota, and Redirect sends clients to another location with a 3xx status. There is no separate Allow action. Use Bypass to allowlist traffic that should skip later deny or rate limit rules.':
    '一致する各ルールは 1 つのアクションを適用します。Deny は 403 を返し、Bypass はリクエストを許可して後続ルールをスキップし、Rate limit はクライアント IP ごとに制限して超過時に 429 を返し、Redirect は 3xx で別の場所へ送ります。Allow アクションは別途ありません。後続の拒否やレート制限をスキップさせたいトラフィックには Bypass を使います。',
  'Every rule applies one action when conditions match. Deny abusive traffic with 403, bypass trusted clients past later rules, throttle per IP with rate limits, or redirect to maintenance and migration URLs.':
    '条件が一致すると、各ルールは 1 つのアクションを適用します。不正なトラフィックは 403 で拒否し、信頼できるクライアントは後続ルールをバイパスし、IP ごとにレート制限するか、メンテナンスや移行用 URL へリダイレクトします。',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Conditions can filter on IP, request path, HTTP method, country, or user agent. Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    '各ルールには少なくとも 1 つの条件が必要です。ルール上のすべての条件が一致する必要があります (AND)。条件は IP、リクエストパス、HTTP メソッド、国、ユーザーエージェントでフィルターできます。ルールは優先度 (小さい番号から) で評価され、最初に一致した有効なルールが結果を決めて評価を止めます。',
  'Filter by IP address, request path, HTTP method, country, or user agent. Combine conditions with AND so a rule only fires when every filter matches.':
    'IP アドレス、リクエストパス、HTTP メソッド、国、またはユーザーエージェントでフィルターします。条件を AND で組み合わせ、すべてのフィルターが一致したときだけルールが発火します。',
  'First match': '最初の一致',
  'Firewall is available on Appwrite Cloud. Rule limits depend on your organization plan. Disabled rules still count toward plan limits but are not evaluated.':
    'Firewall は Appwrite Cloud で利用できます。ルール上限は組織プランに依存します。無効なルールもプラン上限には含まれますが、評価されません。',
  'Firewall overview': 'Firewall の概要',
  'Firewall rules run on Appwrite Cloud before traffic reaches your project resources. Scope a rule to the project API, a specific Function, or a specific Site. Console traffic is never blocked, so you can keep managing rules even when deny or rate limit policies are active.':
    'Firewall ルールは、トラフィックがプロジェクトリソースに届く前に Appwrite Cloud で実行されます。ルールのスコープはプロジェクト API、特定の Function、または特定の Site に設定できます。Console のトラフィックはブロックされないため、拒否やレート制限ポリシーが有効でもルールを管理し続けられます。',
  'Functions and Sites scopes': 'Functions と Sites のスコープ',
  'How do conditions and priority work together?':
    '条件と優先度はどのように連携しますか?',
  'Impact before enable': '有効化前の影響',
  'Impact preview docs': '影響プレビューのドキュメント',
  'Is Firewall available on every plan?':
    'Firewall はすべてのプランで利用できますか?',
  'Last 24 hours': '過去 24 時間',
  'Lower numbers evaluate first. The first match stops the chain.':
    '小さい番号から評価されます。最初の一致でチェーンが止まります。',
  'Lower priority numbers evaluate first. Place bypass allowlists ahead of broader deny or rate limit rules so trusted traffic skips the rest of the chain.':
    '優先度の小さい番号から評価されます。信頼できるトラフィックが後続をスキップできるよう、広い拒否やレート制限ルールより前にバイパス許可リストを置きます。',
  'Match on the request attributes that matter':
    '重要なリクエスト属性で一致させる',
  Matched: '一致',
  'Matched requests over time': '時間経過での一致リクエスト',
  'Monitor denied, limited, and redirected traffic':
    '拒否、制限、リダイレクトされたトラフィックを監視',
  'Monitor docs': '監視のドキュメント',
  Now: '現在',
  'Office IP allowlist': 'オフィス IP 許可リスト',
  'One action per matching rule. Evaluation stops at the first match.':
    '一致するルールごとに 1 アクション。評価は最初の一致で停止します。',
  Passed: '通過',
  'Path starts with /v1/account': 'パスが /v1/account で始まる',
  'Plan limits': 'プラン上限',
  'Preview impact before you enable': '有効化する前に影響をプレビュー',
  'Priority decides the first match': '優先度が最初の一致を決める',
  'Priority docs': '優先度のドキュメント',
  'Project REST and GraphQL endpoints.':
    'プロジェクトの REST と GraphQL エンドポイント。',
  'Protect project APIs, Functions, and Sites with Appwrite Firewall. Create rules to deny, bypass, rate limit, or redirect matching traffic from the Console.':
    'Appwrite Firewall でプロジェクトの API、Functions、Sites を保護します。Console から一致するトラフィックを拒否、バイパス、レート制限、またはリダイレクトするルールを作成できます。',
  'Rate limit public API': '公開 API をレート制限',
  'Rate limited': 'レート制限済み',
  'Recent requests that would match these conditions.':
    'これらの条件に一致する直近のリクエスト。',
  'Reject matching requests before they reach your project.':
    'プロジェクトに届く前に一致するリクエストを拒否します。',
  'Resource scope': 'リソーススコープ',
  'Resource scopes': 'リソーススコープ',
  'Rule actions': 'ルールアクション',
  'Rule conditions': 'ルール条件',
  'Rule priority': 'ルール優先度',
  Rules: 'ルール',
  'Scope rules to API, Functions, or Sites':
    'ルールを API、Functions、Sites にスコープ',
  'Scopes docs': 'スコープのドキュメント',
  'Send matching clients to another location with a 3xx status.':
    '一致するクライアントを 3xx ステータスで別の場所へ送ります。',
  Skipped: 'スキップ済み',
  'Start protecting with Firewall': 'Firewall で保護を始める',
  'The Firewall page chart summarizes Passed request volume alongside Denied, Rate limited, Redirected, and Challenged series for the selected date range. Bypass matches and under-quota rate limit matches allow traffic without publishing a Firewall outcome metric. Use the overview with your rules list to verify policies after enablement.':
    'Firewall ページのチャートは、選択した期間の Passed リクエスト量と、Denied、Rate limited、Redirected、Challenged の系列をまとめます。Bypass の一致とクォータ未満のレート制限一致は、Firewall の結果メトリクスを出さずにトラフィックを許可します。有効化後は概要とルール一覧でポリシーを確認できます。',
  'Throttle matching requests that exceed a per-IP quota.':
    'IP ごとのクォータを超える一致リクエストを制限します。',
  'Track request volume alongside denied, rate-limited, redirected, and challenged outcomes on the Firewall page. Confirm policies after enablement without leaving the Console.':
    'Firewall ページでリクエスト量と、拒否、レート制限、リダイレクト、チャレンジの結果を追跡します。有効化後も Console を離れずにポリシーを確認できます。',
  'What can Firewall protect?': 'Firewall は何を保護できますか?',
  'What does traffic overview show?': 'トラフィック概要には何が表示されますか?',
  'Which actions can a rule take?': 'ルールはどのアクションを取れますか?',
  'While creating a rule, estimate how many recent requests would match your conditions for the selected scope and date range. Tighten filters before traffic is affected.':
    'ルール作成中に、選択したスコープと期間で直近リクエストが何件条件に一致するかを見積もります。トラフィックに影響する前にフィルターを絞り込めます。',
  'Yes. While creating a rule, the Console estimates how many recent usage events would match your current conditions for the selected resource scope and date range. Use that preview to tighten filters before you enable the rule, then confirm outcomes in traffic overview.':
    'はい。ルール作成中、Console は選択したリソーススコープと期間について、現在の条件に一致する直近の利用イベント数を見積もります。そのプレビューで有効化前にフィルターを絞り、トラフィック概要で結果を確認できます。',

  // Firewall product page updates
  '10+': '10+',
  'Act on automated traffic with score-based conditions.':
    'スコア条件で自動トラフィックに対応します。',
  ASN: 'ASN',
  'Bot score': 'ボットスコア',
  'Combine attributes with AND so a rule only fires when every filter matches.':
    '属性を AND で組み合わせ、すべてのフィルターが一致したときだけルールが発火します。',
  'Create your first deny, bypass, rate limit, redirect, or challenge rule from the Console and preview impact before you enable it.':
    'Console から最初の拒否、バイパス、レート制限、リダイレクト、または Challenge ルールを作成し、有効化する前に影響をプレビューできます。',
  'Define project rules that match rich request attributes, then deny, bypass, rate limit, redirect, or challenge traffic before it hits your API, Functions, or Sites.':
    '豊富なリクエスト属性に一致するプロジェクトルールを定義し、API、Functions、Sites に届く前に拒否、バイパス、レート制限、リダイレクト、または Challenge します。',
  'Deny, bypass, rate limit, redirect, or challenge':
    '拒否、バイパス、レート制限、リダイレクト、Challenge',
  'Each matching rule applies one action: Deny returns 403, Bypass allows the request and skips later rules, Rate limit throttles per client IP with a 429 when over quota, Redirect sends clients to another location with a 3xx status, and Challenge verifies suspicious clients before allowing them through. There is no separate Allow action. Use Bypass to allowlist traffic that should skip later deny, rate limit, or challenge rules.':
    '一致する各ルールは 1 つのアクションを適用します。Deny は 403 を返し、Bypass はリクエストを許可して後続ルールをスキップし、Rate limit はクライアント IP ごとに制限して超過時に 429 を返し、Redirect は 3xx で別の場所へ送り、Challenge は疑わしいクライアントを通す前に検証します。Allow アクションは別途ありません。後続の拒否、レート制限、Challenge をスキップさせたいトラフィックには Bypass を使います。',
  'Every rule applies one action when conditions match. Deny abusive traffic, bypass trusted clients, throttle per IP, redirect to maintenance URLs, or challenge suspicious requests before they continue.':
    '条件が一致すると、各ルールは 1 つのアクションを適用します。不正なトラフィックは拒否し、信頼できるクライアントはバイパスし、IP ごとに制限し、メンテナンス URL へリダイレクトするか、疑わしいリクエストを続行前に Challenge します。',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Conditions can filter on IP, request path, HTTP method, country, user agent, ASN, headers, query parameters, TLS fingerprints, bot score, and more. Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    '各ルールには少なくとも 1 つの条件が必要です。ルール上のすべての条件が一致する必要があります (AND)。条件は IP、リクエストパス、HTTP メソッド、国、ユーザーエージェント、ASN、ヘッダー、クエリパラメータ、TLS フィンガープリント、ボットスコアなどでフィルターできます。ルールは優先度 (小さい番号から) で評価され、最初に一致した有効なルールが結果を決めて評価を止めます。',
  'Filter by IP, path, method, country, user agent, ASN, headers, query parameters, TLS fingerprints, bot score, and more. Combine conditions with AND so a rule only fires when every filter matches.':
    'IP、パス、メソッド、国、ユーザーエージェント、ASN、ヘッダー、クエリパラメータ、TLS フィンガープリント、ボットスコアなどでフィルターします。条件を AND で組み合わせ、すべてのフィルターが一致したときだけルールが発火します。',
  'Filter on query keys and values without changing path rules.':
    'パスルールを変えずにクエリのキーと値でフィルターします。',
  'Geo allow or deny by resolved ISO country code.':
    '解決された ISO 国コードで許可または拒否します。',
  Header: 'ヘッダー',
  'Identify clients by TLS fingerprint when IPs rotate.':
    'IP が入れ替わるときも TLS フィンガープリントでクライアントを識別します。',
  'JA4 fingerprint': 'JA4 フィンガープリント',
  'Lower priority numbers evaluate first. Place bypass allowlists ahead of broader deny, rate limit, or challenge rules so trusted traffic skips the rest of the chain.':
    '優先度の小さい番号から評価されます。信頼できるトラフィックが後続をスキップできるよう、広い拒否、レート制限、Challenge ルールより前にバイパス許可リストを置きます。',
  'Match exact client IPs for allowlists and denylists.':
    '許可リストと拒否リスト向けに正確なクライアント IP を一致させます。',
  'Match on rich request attributes': '豊富なリクエスト属性で一致',
  'Match request headers for tokens, clients, or custom signals.':
    'トークン、クライアント、カスタムシグナル向けにリクエストヘッダーを一致させます。',
  'Match traffic by autonomous system for hosting and ISP ranges.':
    'ホスティングや ISP レンジ向けに自律システムでトラフィックを一致させます。',
  'Monitor denied, limited, redirected, and challenged traffic':
    '拒否、制限、リダイレクト、Challenge されたトラフィックを監視',
  'Present a challenge before allowing suspicious clients through.':
    '疑わしいクライアントを通す前に Challenge を提示します。',
  'Protect prefixes like /v1/account or sensitive routes.':
    '/v1/account のようなプレフィックスや機微なルートを保護します。',
  'Protect project APIs, Functions, and Sites with Appwrite Firewall. Create rules to deny, bypass, rate limit, redirect, or challenge matching traffic from the Console.':
    'Appwrite Firewall でプロジェクトの API、Functions、Sites を保護します。Console から一致するトラフィックを拒否、バイパス、レート制限、リダイレクト、または Challenge するルールを作成できます。',
  'Query parameter': 'クエリパラメータ',
  'Restrict mutating methods such as POST, PUT, and DELETE.':
    'POST、PUT、DELETE などの変更メソッドを制限します。',
  'See how many recent requests would match before you enable a rule.':
    'ルールを有効にする前に、直近リクエストが何件一致するかを確認できます。',
  'Filter bots, scripts, monitors, and known clients.':
    'ボット、スクリプト、モニター、既知のクライアントをフィルターします。',

  'Build rules from the request properties that matter to your app. Combine conditions so a rule only fires when every filter matches.':
    'アプリにとって重要なリクエスト属性からルールを組み立てます。条件を組み合わせ、すべてのフィルターが一致したときだけルールが発火します。',
  'Define project rules that match the request properties you care about, then deny, bypass, rate limit, redirect, or challenge traffic before it hits your API, Functions, or Sites.':
    '重視するリクエスト属性に一致するプロジェクトルールを定義し、API、Functions、Sites に届く前に拒否、バイパス、レート制限、リダイレクト、または Challenge します。',
  'Every condition on a rule must match before the action runs.':
    'アクションが実行される前に、ルール上のすべての条件が一致する必要があります。',
  'Every rule needs at least one condition. All conditions on a rule must match (AND). Rules evaluate by priority (lower numbers first). The first matching enabled rule decides the outcome and stops evaluation.':
    '各ルールには少なくとも 1 つの条件が必要です。ルール上のすべての条件が一致する必要があります (AND)。ルールは優先度 (小さい番号から) で評価され、最初に一致した有効なルールが結果を決めて評価を止めます。',
  'Match on request properties such as identity, location, path, and client signals.':
    '識別情報、位置、パス、クライアント信号などのリクエスト属性で一致させます。',
  'Estimate how much recent traffic a draft rule would affect, then refine conditions before you turn it on.':
    '下書きルールが直近トラフィックにどの程度影響するかを見積もり、有効化する前に条件を絞り込みます。',
  'See how much recent traffic a draft rule would affect.':
    '下書きルールが直近トラフィックにどの程度影響するかを確認できます。',
  Incoming: '受信',
  'Your app': 'アプリ',
  '3 online': '3人がオンライン',
  Event: 'イベント',
  'Your order has shipped': 'ご注文の商品を発送しました',
  'Track your delivery in the app.': 'アプリで配送状況を確認できます。',
  'Welcome to Acme': 'Acme へようこそ',
  'Cloning repository': 'リポジトリをクローン中',
  Building: 'ビルド中',
  Deploying: 'デプロイ中',
  Deny: '拒否',
  Bypass: 'バイパス',
  Runtime: 'ランタイム',
  Endpoint: 'Endpoint',
  Denied: '拒否',
  Redirected: 'リダイレクト',
  Draft: '下書き',
  'Edge SSR': 'エッジ SSR',
  'US East': '米国東部',
  'EU West': '欧州西部',
  'AP South': 'アジア太平洋南部',
  'Deploy from CI or your terminal with the Appwrite CLI and appwrite.config.json.':
    'Appwrite CLI と appwrite.config.json を使って、CI やターミナルからデプロイできます。',

  // Managed PostgreSQL product page
  'Managed PostgreSQL hosting': 'マネージド PostgreSQL ホスティング',
  'Managed PostgreSQL hosting on Appwrite. Every database is provisioned for your project with a TLS hostname, connection pooling, extensions like pgvector, backups, PITR, replicas, and branches.':
    'Appwrite のマネージド PostgreSQL ホスティング。すべてのデータベースは、TLS 対応のホスト名、コネクションプーリング、pgvector などの拡張機能、バックアップ、PITR、レプリカ、Branch とともにプロジェクト専用にプロビジョニングされます。',
  'Raw Postgres, fully managed': 'そのままの Postgres、フルマネージドで',
  'Appwrite runs the engine and you keep the SQL: connect with psql or any driver, bring your own ORM and migrations, and use the full PostgreSQL feature set with no Appwrite layer in between. Each database is provisioned in your project region with its own hostname, credentials, and TLS.':
    'エンジンの運用は Appwrite が担い、SQL はそのまま使えます。psql や任意のドライバーで接続し、好きな ORM とマイグレーションを持ち込み、Appwrite の層を挟まずに PostgreSQL のすべての機能を使えます。各データベースはプロジェクトのリージョンに、独自のホスト名、認証情報、TLS 付きでプロビジョニングされます。',
  'Appwrite runs the engine and you keep the SQL. Connect with psql or any driver, bring your own ORM and migrations, and get the full PostgreSQL feature set with nothing in between.':
    'エンジンの運用は Appwrite が担い、SQL はそのまま使えます。psql や任意のドライバーで接続し、好きな ORM とマイグレーションを持ち込み、間に何も挟まずに PostgreSQL のすべての機能を使えます。',
  'PostgreSQL 18 by default': '既定で PostgreSQL 18',
  '6 regions': '6 リージョン',
  'Every Appwrite Cloud region': 'すべての Appwrite Cloud リージョン',
  'High availability maximum': '高可用性の上限',
  'Restore to any moment': '任意の時点へ復元',
  'Smallest specification': '最小の仕様',
  'Start building on managed PostgreSQL':
    'マネージド PostgreSQL で開発を始める',
  'Create a database in your project region, copy the connection string, and run your first query in minutes.':
    'プロジェクトのリージョンにデータベースを作成し、接続文字列をコピーして、数分で最初のクエリを実行できます。',
  'Create a database in your project region, copy the connection string, and run your first query.':
    'プロジェクトのリージョンにデータベースを作成し、接続文字列をコピーして、最初のクエリを実行できます。',

  // Managed PostgreSQL FAQ
  'How is managed PostgreSQL different from TablesDB, DocumentsDB, and VectorsDB?':
    'マネージド PostgreSQL は TablesDB、DocumentsDB、VectorsDB とどう違いますか?',
  'A managed PostgreSQL database is the raw engine, provisioned for your project with its own compute, storage, networking, and credentials. You talk to it over the PostgreSQL wire protocol instead of an Appwrite SDK, so your schema, migrations, roles, and queries are standard PostgreSQL. Use TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs, platform permissions, and serverless scaling for app data instead.':
    'マネージド PostgreSQL データベースはエンジンそのもので、プロジェクト専用のコンピュート、ストレージ、ネットワーク、認証情報とともにプロビジョニングされます。Appwrite SDK ではなく PostgreSQL のワイヤープロトコルで通信するため、スキーマ、マイグレーション、ロール、クエリはすべて標準の PostgreSQL です。アプリのデータに Appwrite SDK、プラットフォームの権限、サーバーレスなスケーリングを使いたい場合は、代わりに TablesDB、DocumentsDB、VectorsDB を選びます。',
  'A managed PostgreSQL database is the raw engine with its own compute, storage, and credentials. You talk to it over the PostgreSQL wire protocol instead of an Appwrite SDK, so schema, migrations, roles, and queries are standard PostgreSQL. Use TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs and platform permissions for app data instead.':
    'マネージド PostgreSQL データベースはエンジンそのもので、専用のコンピュート、ストレージ、認証情報を持ちます。Appwrite SDK ではなく PostgreSQL のワイヤープロトコルで通信するため、スキーマ、マイグレーション、ロール、クエリはすべて標準の PostgreSQL です。アプリのデータに Appwrite SDK とプラットフォームの権限を使いたい場合は、代わりに TablesDB、DocumentsDB、VectorsDB を選びます。',
  'Which PostgreSQL versions can I run, and can I upgrade later?':
    'どの PostgreSQL バージョンを実行できますか。後からアップグレードできますか?',
  'New databases run PostgreSQL 18 by default, and you can create one on PostgreSQL 17 by passing a version at create time. The version can be upgraded later and upgrades run online: a second instance is provisioned on the new version, data streams over with logical replication, and traffic cuts over once replication catches up. Reads continue throughout, but the old instance is fenced read-only at the cutover and client connections are closed, so your application has to reconnect. Check that your installed extensions support the target version first.':
    '新しいデータベースは既定で PostgreSQL 18 で動作し、作成時にバージョンを指定すれば PostgreSQL 17 でも作成できます。バージョンは後からアップグレードでき、アップグレードはオンラインで実行されます。新しいバージョンで 2 台目のインスタンスがプロビジョニングされ、論理レプリケーションでデータが流れ込み、レプリケーションが追いついた時点でトラフィックが切り替わります。その間も読み取りは継続しますが、切り替え時に旧インスタンスは読み取り専用に固定され、クライアント接続は閉じられるため、アプリケーション側で再接続が必要です。まず、インストール済みの拡張機能が対象バージョンに対応しているか確認してください。',
  'New databases run PostgreSQL 19 by default, and you can pick PostgreSQL 17 or 18 at create time. Upgrades run online: a second instance is provisioned on the new version, data streams over with logical replication, and traffic cuts over once it catches up. Connections are closed at the cutover, so your application has to reconnect.':
    '新しいデータベースは既定で PostgreSQL 19 で動作し、作成時に PostgreSQL 17 または 18 を選ぶこともできます。アップグレードはオンラインで実行されます。新しいバージョンで 2 台目のインスタンスがプロビジョニングされ、論理レプリケーションでデータが流れ込み、追いついた時点でトラフィックが切り替わります。切り替え時に接続は閉じられるため、アプリケーション側で再接続が必要です。',
  'Which regions can I run a database in?':
    'どのリージョンでデータベースを実行できますか?',
  'Managed PostgreSQL is available in every Appwrite Cloud region: Frankfurt, New York, San Francisco, Singapore, Sydney, and Toronto. A database takes the region of the project that owns it, so there is no per-database region selector. Create your project close to your users first, then create the database. Each database gets a hostname in the form db-<hash>.<region>.appwrite.center and the data does not leave the region.':
    'マネージド PostgreSQL は、フランクフルト、ニューヨーク、サンフランシスコ、シンガポール、シドニー、トロントのすべての Appwrite Cloud リージョンで利用できます。データベースは所有するプロジェクトのリージョンを引き継ぐため、データベースごとのリージョン選択はありません。まずユーザーの近くにプロジェクトを作成し、そのうえでデータベースを作成してください。各データベースには db-<hash>.<region>.appwrite.center 形式のホスト名が割り当てられ、データがリージョンの外に出ることはありません。',
  'Every Appwrite Cloud region: Frankfurt, New York, San Francisco, Singapore, Sydney, and Toronto. A database takes the region of the project that owns it, so create your project close to your users first. Each database gets a hostname in the form db-<hash>.<region>.appwrite.center and the data does not leave the region.':
    'フランクフルト、ニューヨーク、サンフランシスコ、シンガポール、シドニー、トロントという、すべての Appwrite Cloud リージョンです。データベースは所有するプロジェクトのリージョンを引き継ぐため、まずユーザーの近くにプロジェクトを作成してください。各データベースには db-<hash>.<region>.appwrite.center 形式のホスト名が割り当てられ、データがリージョンの外に出ることはありません。',
  'How do specifications and pricing work?':
    '仕様と料金はどのように決まりますか?',
  'Each database runs against one compute specification that sets its CPU, memory, included storage, bandwidth, and connection limit. There are twelve tiers, from 1 core and 1 GB at $10 per month up to 32 cores and 256 GB, billed monthly and pro-rated by the hour the database is running. Storage and bandwidth beyond the tier allowance are billed as overage, and optional features are add-ons: each high availability replica costs the tier price again, and point-in-time recovery adds 20%. Managed databases need a paid plan with a payment method on the organization, and your plan decides which tiers you can pick.':
    '各データベースは 1 つのコンピュート仕様で動作し、その仕様が CPU、メモリ、含まれるストレージ、帯域幅、接続上限を決めます。階層は 12 種類あり、1 コア 1 GB の月額 $10 から 32 コア 256 GB までで、月額課金かつデータベースが稼働した時間で按分されます。階層の割当を超えるストレージと帯域幅は超過分として課金され、オプション機能はアドオンです。高可用性レプリカは 1 台ごとに階層と同額、ポイントインタイムリカバリは 20% 加算されます。マネージドデータベースには、組織に支払い方法が登録された有料プランが必要で、選べる階層はプランによって決まります。',
  'PostgreSQL uses the same dedicated compute tiers as pricing: reserved CPU, memory, and connections, from $10/mo per database. Reads and writes are included in the tier. High availability replicas are billed at the full compute tier price per replica, and point-in-time recovery is +20% of base. Extra storage and bandwidth are usage-based overage. Managed databases need a paid plan.':
    'PostgreSQL は料金ページと同じ専用コンピュートティアを使います。予約済みの CPU、メモリ、接続で、データベースあたり $10/月からです。読み取りと書き込みはティアに含まれます。高可用性レプリカはレプリカ 1 台あたりコンピュート階層のフル料金、ポイントインタイムリカバリは基本料金の +20% です。追加のストレージと帯域幅は使用量ベースの超過課金です。マネージドデータベースには有料プランが必要です。',
  'How many connections can I open, and when do I need the pooler?':
    '接続はいくつ開けますか。プーラーが必要になるのはどんなときですか?',
  'The connection limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers can exhaust that limit quickly. Point runtime traffic at the pooler on port 6432, same hostname and credentials, and many short-lived clients share a small pool of server connections. Keep migrations, schema changes, and long administrative sessions on the direct port 5432.':
    '接続上限は仕様によって決まり、最小の階層では 100、プラットフォーム全体の上限は 10,000 です。PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、サーバーレス関数や水平スケールするアプリサーバーは上限をすぐに使い切ることがあります。実行時のトラフィックは、同じホスト名と認証情報でポート 6432 のプーラーに向けてください。そうすれば、多数の短命なクライアントが少数のサーバー接続のプールを共有します。マイグレーション、スキーマ変更、長時間の管理用セッションは、直接接続のポート 5432 のままにします。',
  'The limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. Since PostgreSQL spends a backend process per connection, point runtime traffic at the pooler on port 6432, where many short-lived clients share a small pool. Keep migrations and long administrative sessions on the direct port 5432.':
    '接続上限は仕様によって決まり、最小の階層では 100、プラットフォーム全体の上限は 10,000 です。PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、実行時のトラフィックはポート 6432 のプーラーに向けてください。そこでは多数の短命なクライアントが少数のプールを共有します。マイグレーションや長時間の管理用セッションは、直接接続のポート 5432 のままにします。',
  'Which extensions can I install?': 'どの拡張機能をインストールできますか?',
  'The available list comes from what the engine reports for your PostgreSQL version, minus extensions Appwrite does not offer, so it reflects what this database can actually install. Common picks include pgvector for embeddings, PostGIS for geospatial data, pg_trgm for fuzzy search, pgcrypto, hstore, citext, and ltree. Installs and uninstalls are free, up to 50 extensions per database, and they are managed from the Console or with a Server SDK and an API key. Uninstalling always cascades, so anything that depends on the extension is dropped with it.':
    '利用できる一覧は、お使いの PostgreSQL バージョンでエンジンが報告する拡張機能から、Appwrite が提供していないものを除いたもので、そのデータベースに実際にインストールできる内容を反映します。よく選ばれるのは、埋め込み向けの pgvector、地理空間データ向けの PostGIS、あいまい検索向けの pg_trgm、そして pgcrypto、hstore、citext、ltree です。インストールとアンインストールは無料で、1 データベースあたり最大 50 個まで、Console または Server SDK と API キーから管理できます。アンインストールは常にカスケードするため、その拡張機能に依存するものも一緒に削除されます。',
  'The list comes from what the engine reports for your PostgreSQL version, so it reflects what this database can actually install: pgvector for embeddings, PostGIS for geospatial data, pg_trgm for fuzzy search, pgcrypto, hstore, citext, and ltree. Installs are free, up to 50 per database. Uninstalling always cascades, so anything depending on the extension is dropped with it.':
    '一覧は、お使いの PostgreSQL バージョンでエンジンが報告する内容に基づくため、そのデータベースに実際にインストールできるものを反映します。埋め込み向けの pgvector、地理空間データ向けの PostGIS、あいまい検索向けの pg_trgm、そして pgcrypto、hstore、citext、ltree などです。インストールは無料で、1 データベースあたり最大 50 個までです。アンインストールは常にカスケードするため、その拡張機能に依存するものも一緒に削除されます。',
  'What is the difference between backups and point-in-time recovery?':
    'バックアップとポイントインタイムリカバリの違いは何ですか?',
  'Backups are snapshots on a schedule. Every database is provisioned with a default policy, backups are stored off the instance, and you can add policies with their own cron schedule and retention or take a manual backup before a risky change. Point-in-time recovery is an add-on that continuously archives the write-ahead log, so you can restore to any moment inside a window of 1 to 35 days, for example the second before a bad migration. Both restores are in place: the database is unavailable while it restores and anything written after the target is discarded.':
    'バックアップはスケジュールに沿って取得するスナップショットです。すべてのデータベースには既定のポリシーが設定され、バックアップはインスタンスの外に保存されます。独自の cron スケジュールと保持期間を持つポリシーを追加したり、リスクのある変更の前に手動バックアップを取ることもできます。ポイントインタイムリカバリはアドオンで、write-ahead log を継続的にアーカイブするため、1 日から 35 日のウィンドウ内の任意の時点、たとえば不正なマイグレーションの直前へ復元できます。どちらの復元もその場で行われます。復元中はデータベースを利用できず、復元対象の時点より後に書き込まれた内容は破棄されます。',
  'Backups are snapshots on a schedule, stored off the instance, with your own cron and retention on top of the default policy. Point-in-time recovery is an add-on that archives the write-ahead log continuously, so you can restore to any moment in a window of 1 to 35 days. Both restore in place, so anything written after the target is discarded.':
    'バックアップはスケジュールに沿って取得するスナップショットで、インスタンスの外に保存されます。既定のポリシーに加えて、独自の cron スケジュールと保持期間を設定できます。ポイントインタイムリカバリはアドオンで、write-ahead log を継続的にアーカイブするため、1 日から 35 日のウィンドウ内の任意の時点へ復元できます。どちらの復元もその場で行われ、復元対象の時点より後に書き込まれた内容は破棄されます。',
  'How does high availability and failover work?':
    '高可用性とフェイルオーバーはどのように動作しますか?',
  'High availability adds up to five streaming replicas next to the primary, each a full copy on its own compute. Pick asynchronous replication for the fastest writes, or synchronous or quorum when you cannot lose acknowledged writes. Appwrite health-checks the primary continuously, and when it stops responding the most caught-up replica is promoted, the hostname is repointed, and the old primary is rebuilt as a replica. Your application reconnects to the same host and port, and a driver pool with retries usually recovers on its own.':
    '高可用性では、プライマリの隣に最大 5 台のストリーミングレプリカを追加できます。それぞれが独自のコンピュート上の完全なコピーです。書き込みを最速にしたいときは非同期レプリケーション、確認済みの書き込みを失えないときは同期またはクォーラムを選びます。Appwrite はプライマリを継続的にヘルスチェックし、応答しなくなると最も追随しているレプリカを昇格させ、ホスト名を付け替え、旧プライマリをレプリカとして再構築します。アプリケーションは同じホストとポートに再接続し、リトライを備えたドライバーのプールがあれば、ほとんどの場合は自動的に復帰します。',
  'High availability adds up to five streaming replicas, each a full copy on its own compute. Pick asynchronous replication for the fastest writes, or synchronous or quorum when you cannot lose acknowledged writes. When the primary stops responding, the most caught-up replica is promoted and the hostname repointed, so a driver pool with retries usually recovers on its own.':
    '高可用性では最大 5 台のストリーミングレプリカを追加でき、それぞれが独自のコンピュート上の完全なコピーです。書き込みを最速にしたいときは非同期レプリケーション、確認済みの書き込みを失えないときは同期またはクォーラムを選びます。プライマリが応答しなくなると、最も追随しているレプリカが昇格してホスト名が付け替えられるため、リトライを備えたドライバーのプールがあれば、ほとんどの場合は自動的に復帰します。',
  'What are branches good for?': 'Branch は何に使えますか?',
  'A branch is a short-lived, isolated copy of your database created from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Use a branch to rehearse a destructive migration, reproduce a bug, run heavy analytical queries, or give every pull request its own database. Branches diverge from the parent and never merge back, and every branch has a TTL: 24 hours by default and 7 days at most.':
    'Branch は、ストレージスナップショットから作成されるデータベースの短命な分離コピーで、独自のエンドポイントと親の認証情報を持ちます。親が凍結されることはなく、書き込みの一時停止も発生しません。破壊的なマイグレーションのリハーサル、バグの再現、重い分析クエリの実行、プルリクエストごとに専用データベースを用意する用途に使えます。Branch は親から分岐し、マージバックされることはありません。また、すべての Branch には TTL があり、既定は 24 時間、最長で 7 日間です。',
  'A branch is a short-lived copy created from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Rehearse a destructive migration, reproduce a bug, or give every pull request its own database. Branches never merge back and expire after 24 hours by default, 7 days at most.':
    'Branch は、ストレージスナップショットから作成される短命なコピーで、独自のエンドポイントと親の認証情報を持ちます。親が凍結されることはなく、書き込みの一時停止も発生しません。破壊的なマイグレーションのリハーサル、バグの再現、プルリクエストごとの専用データベースの用意に使えます。Branch はマージバックされず、既定では 24 時間、最長で 7 日間で期限切れになります。',
  'What does Appwrite manage, and what do I still own?':
    'Appwrite が管理するもの、自分で管理するものは何ですか?',
  'Appwrite manages the database container, storage, networking, TLS, backups, replication, security patches, and engine upgrades, and gives you a weekly maintenance window, pause and resume, and live metrics. You own everything above the wire protocol: schema, migrations, indexes, roles and grants, queries, which extensions to install, and the IP allowlist. There is nothing Appwrite-specific in your application code.':
    'Appwrite はデータベースコンテナ、ストレージ、ネットワーク、TLS、バックアップ、レプリケーション、セキュリティパッチ、エンジンのアップグレードを管理し、週次のメンテナンスウィンドウ、一時停止と再開、ライブメトリクスを提供します。ワイヤープロトコルより上のレイヤーはすべて利用者側の管理です。スキーマ、マイグレーション、インデックス、ロールと権限付与、クエリ、インストールする拡張機能、IP 許可リストが対象です。アプリケーションコードに Appwrite 固有の記述は一切ありません。',
  'Appwrite manages the container, storage, networking, TLS, backups, replication, security patches, and engine upgrades. You own everything above the wire protocol: schema, migrations, indexes, roles and grants, queries, extensions, and the IP allowlist. There is nothing Appwrite-specific in your application code.':
    'Appwrite はコンテナ、ストレージ、ネットワーク、TLS、バックアップ、レプリケーション、セキュリティパッチ、エンジンのアップグレードを管理します。ワイヤープロトコルより上のレイヤーはすべて利用者側の管理です。スキーマ、マイグレーション、インデックス、ロールと権限付与、クエリ、拡張機能、IP 許可リストが対象です。アプリケーションコードに Appwrite 固有の記述は一切ありません。',
  'Can I migrate an existing PostgreSQL database in?':
    '既存の PostgreSQL データベースを移行できますか?',
  'Yes, with the standard PostgreSQL tooling you already use. Create the database, copy its connection string, and run your dump and restore against the direct port 5432, because tools like pg_dump expect one session for the whole run and can misbehave in transaction mode. Then point your application runtime at the pooler on port 6432. Any client that speaks the PostgreSQL wire protocol works, including pgAdmin, DataGrip, and your migration tool of choice.':
    'はい。すでに使っている標準の PostgreSQL ツールでそのまま移行できます。データベースを作成し、接続文字列をコピーして、ダンプと復元は直接接続のポート 5432 に対して実行してください。pg_dump のようなツールは処理全体で 1 つのセッションを前提とするため、トランザクションモードでは正しく動作しないことがあります。その後、アプリケーションの実行時トラフィックをポート 6432 のプーラーに向けます。pgAdmin、DataGrip、お好みのマイグレーションツールなど、PostgreSQL のワイヤープロトコルを話すクライアントであれば何でも利用できます。',

  'Yes, with the standard PostgreSQL tooling you already use. Run your dump and restore against the direct port 5432, because tools like pg_dump expect one session for the whole run, then point your application runtime at the pooler on port 6432. Any client that speaks the wire protocol works, including pgAdmin and DataGrip.':
    'はい。すでに使っている標準の PostgreSQL ツールでそのまま移行できます。pg_dump のようなツールは処理全体で 1 つのセッションを前提とするため、ダンプと復元は直接接続のポート 5432 に対して実行し、そのうえでアプリケーションの実行時トラフィックをポート 6432 のプーラーに向けてください。pgAdmin や DataGrip など、ワイヤープロトコルを話すクライアントであれば何でも利用できます。',

  // Managed PostgreSQL feature sections
  'A PostgreSQL endpoint, nothing else in the way':
    '余計な層のない PostgreSQL エンドポイント',
  'Every database gets its own hostname on port 5432, TLS by default, and an admin role that owns it. Connect with psql, any driver, or any ORM: Prisma, Drizzle, Kysely, Laravel, Django, Rails. The Console credentials dialog hands you a ready-made DSN, .env, Prisma, Drizzle, or psql snippet, and you can rotate the primary password whenever your policy says so.':
    'すべてのデータベースには、ポート 5432 の専用ホスト名、既定で有効な TLS、そのデータベースを所有する admin ロールが用意されます。psql、任意のドライバー、または Prisma、Drizzle、Kysely、Laravel、Django、Rails などの ORM から接続できます。Console の認証情報ダイアログでは、DSN、.env、Prisma、Drizzle、psql のスニペットがそのまま手に入り、ポリシーに応じていつでもプライマリのパスワードをローテーションできます。',
  'Every database gets its own hostname on port 5432, TLS, and an admin role that owns it. Connect with psql, any driver, or any ORM, and copy a ready-made DSN, .env, or Prisma snippet from the Console.':
    'すべてのデータベースには、ポート 5432 の専用ホスト名、TLS、そのデータベースを所有する admin ロールが用意されます。psql、任意のドライバー、任意の ORM から接続でき、すぐに使える DSN、.env、Prisma のスニペットを Console からコピーできます。',
  'Connection pooling on port 6432': 'ポート 6432 のコネクションプーリング',
  'PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers exhaust a specification fast. The pooler runs next to your database on the same hostname with the same credentials and TLS: switch the port to 6432 and many short-lived clients share a small pool of server connections. Transaction mode is the default, session mode keeps prepared statements and LISTEN/NOTIFY, and reads route to replicas automatically once high availability is on.':
    'PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、サーバーレス関数や水平スケールするアプリサーバーは仕様の上限をすぐに使い切ります。プーラーはデータベースの隣で、同じホスト名、同じ認証情報、同じ TLS で動作します。ポートを 6432 に切り替えれば、多数の短命なクライアントが少数のサーバー接続のプールを共有します。既定はトランザクションモードで、セッションモードはプリペアドステートメントと LISTEN/NOTIFY を維持します。高可用性を有効にすると、読み取りは自動でレプリカに振り分けられます。',
  'PostgreSQL spends a backend process per connection, so serverless functions exhaust a specification fast. Switch to port 6432, same hostname and credentials, and short-lived clients share a small pool.':
    'PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、サーバーレス関数は仕様の上限をすぐに使い切ります。同じホスト名と認証情報のままポートを 6432 に切り替えれば、短命なクライアントが少数のプールを共有します。',
  'pgvector, PostGIS, and the rest at no extra cost':
    'pgvector、PostGIS、その他も追加費用なし',
  'The extension catalog follows your PostgreSQL version, so you install embeddings, geospatial types, fuzzy search, and crypto helpers the same way you would on your own server. Installs and uninstalls are free, up to 50 extensions per database, managed from the Console or with a Server SDK and an API key.':
    '拡張機能のカタログはお使いの PostgreSQL バージョンに追従するため、埋め込み、地理空間型、あいまい検索、暗号ヘルパーを自前のサーバーと同じようにインストールできます。インストールとアンインストールは無料で、1 データベースあたり最大 50 個まで、Console または Server SDK と API キーから管理できます。',
  'The catalog follows your PostgreSQL version, so you install embeddings, geospatial types, and fuzzy search the same way you would on your own server. Free to install, up to 50 per database.':
    'カタログはお使いの PostgreSQL バージョンに追従するため、埋め込み、地理空間型、あいまい検索を自前のサーバーと同じようにインストールできます。インストールは無料で、1 データベースあたり最大 50 個までです。',
  'Snapshot branches in minutes': '数分で作れるスナップショット Branch',
  'A branch is an isolated copy of your database taken from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Rehearse a destructive migration, reproduce a bug, or give every pull request its own database. Branches diverge from the parent and never merge back, so they expire: 24 hours by default, 7 days at most.':
    'Branch は、ストレージスナップショットから取得したデータベースの分離コピーで、独自のエンドポイントと親の認証情報を持ちます。親が凍結されることはなく、書き込みの一時停止も発生しません。破壊的なマイグレーションのリハーサル、バグの再現、プルリクエストごとの専用データベースの用意に使えます。Branch は親から分岐し、マージバックされないため、有効期限があります。既定は 24 時間、最長で 7 日間です。',
  'A branch is an isolated copy taken from a storage snapshot, with its own endpoint and the parent credentials. The parent never pauses writes. Rehearse a migration or give every pull request a database.':
    'Branch は、ストレージスナップショットから取得した分離コピーで、独自のエンドポイントと親の認証情報を持ちます。親の書き込みが止まることはありません。マイグレーションのリハーサルや、プルリクエストごとの専用データベースの用意に使えます。',
  'Backups off the instance, restores on demand':
    'インスタンス外に保存するバックアップと、必要なときの復元',
  'Every database is provisioned with a backup policy, and backups are stored off the database instance. Add policies with their own cron schedule and retention, take a manual backup before a risky change, and restore when something goes wrong. Enable point-in-time recovery as an add-on to archive the write-ahead log continuously and restore to any moment in a window of up to 35 days.':
    'すべてのデータベースにはバックアップポリシーが設定され、バックアップはデータベースインスタンスの外に保存されます。独自の cron スケジュールと保持期間を持つポリシーを追加し、リスクのある変更の前に手動バックアップを取り、問題が起きたときに復元できます。ポイントインタイムリカバリをアドオンとして有効にすると、write-ahead log を継続的にアーカイブし、最大 35 日間のウィンドウ内の任意の時点へ復元できます。',
  'Every database ships with a backup policy and backups are stored off the instance. Add your own schedule and retention, or enable point-in-time recovery to restore to any moment in the last 35 days.':
    'すべてのデータベースにはバックアップポリシーが設定され、バックアップはインスタンスの外に保存されます。独自のスケジュールと保持期間を追加したり、ポイントインタイムリカバリを有効にして直近 35 日間の任意の時点へ復元したりできます。',
  'Replicas with automatic failover': '自動フェイルオーバー付きのレプリカ',
  'Add up to five streaming replicas and pick how safe writes should be: asynchronous, synchronous, or quorum. If the primary stops responding, the most caught-up replica is promoted and the hostname repointed.':
    '最大 5 台のストリーミングレプリカを追加し、書き込みの安全性を非同期、同期、クォーラムから選べます。プライマリが応答しなくなると、最も追随しているレプリカが昇格し、ホスト名が付け替えられます。',
  'Add up to five streaming replicas next to the primary and pick how safe your writes should be: asynchronous for the fastest commits, synchronous or quorum when you cannot lose an acknowledged write. When the primary stops responding, Appwrite promotes the most caught-up replica and repoints the hostname, so your application reconnects to the same host. With read/write splitting on the pooler, those replicas serve your read traffic too.':
    'プライマリの隣に最大 5 台のストリーミングレプリカを追加し、書き込みの安全性を選べます。コミットを最速にしたいときは非同期、確認済みの書き込みを失えないときは同期またはクォーラムです。プライマリが応答しなくなると、Appwrite は最も追随しているレプリカを昇格させてホスト名を付け替えるため、アプリケーションは同じホストに再接続します。プーラーの読み書き分離を使えば、これらのレプリカが読み取りトラフィックも処理します。',
  'Live metrics and online resizing': 'ライブメトリクスとオンラインのリサイズ',
  'The Monitor tab tracks CPU, memory, queries per second, cache hit ratio, and disk growth with nothing to install. Inspect live sessions, cancel a query, then resize compute online when it is time.':
    'Monitor タブは、何もインストールせずに CPU、メモリ、秒あたりのクエリ数、キャッシュヒット率、ディスクの増加を追跡します。稼働中のセッションを調べ、クエリをキャンセルし、必要なタイミングでオンラインでコンピュートをリサイズできます。',
  'The Monitor tab tracks compute, connections, storage, and workload without anything to install: CPU, memory, queries per second, cache hit ratio, disk growth, and the largest tables. Inspect live sessions from the connections list, then cancel a query or terminate an idle-in-transaction session that is holding locks. When the metrics say it is time, resize compute online and let storage grow on its own.':
    'Monitor タブは、何もインストールせずにコンピュート、接続、ストレージ、ワークロードを追跡します。CPU、メモリ、秒あたりのクエリ数、キャッシュヒット率、ディスクの増加、最大のテーブルを確認できます。接続一覧から稼働中のセッションを調べ、クエリをキャンセルしたり、ロックを保持したままトランザクション内アイドルになっているセッションを終了させたりできます。メトリクスが示すタイミングが来たら、オンラインでコンピュートをリサイズし、ストレージは自動で拡張させられます。',

  // Managed PostgreSQL docs links
  'TablesDB docs': 'TablesDB のドキュメント',
  'DocumentsDB docs': 'DocumentsDB のドキュメント',
  'VectorsDB docs': 'VectorsDB のドキュメント',
  'Maintenance docs': 'メンテナンスのドキュメント',
  'Cloud regions': 'Cloud のリージョン',
  Pricing: '料金',
  'Scaling docs': 'スケーリングのドキュメント',
  'Connection pooling docs': 'コネクションプーリングのドキュメント',
  'Connections docs': '接続のドキュメント',
  'Extensions docs': '拡張機能のドキュメント',
  'High availability docs': '高可用性のドキュメント',
  'Branches docs': 'Branches のドキュメント',
  'Network security docs': 'ネットワークセキュリティのドキュメント',
  'Quick start docs': 'クイックスタートのドキュメント',
  'Monitoring docs': '監視のドキュメント',

  // Managed PostgreSQL visuals
  'Managed PostgreSQL': 'マネージド PostgreSQL',
  Pooler: 'プーラー',
  Pool: 'プール',
  'Your toolchain': 'ツールチェーン',
  Rotatable: 'ローテーション可能',
  'Rotate password': 'パスワードをローテーション',
  'Console or API': 'Console または API',
  'TLS by default': '既定で TLS',
  'Same credentials, any client': '同じ認証情報で、どのクライアントからでも',
  'Same credentials and TLS': '同じ認証情報と TLS',
  Clients: 'クライアント',
  'Writes and locked reads': '書き込みとロックを伴う読み取り',
  'SELECT traffic': 'SELECT のトラフィック',
  'Transaction mode by default. Read/write splitting turns on with high availability, so SELECT traffic reaches replicas.':
    '既定はトランザクションモードです。高可用性を有効にすると読み書き分離が有効になり、SELECT のトラフィックがレプリカに届きます。',
  'Search and AI': '検索と AI',
  Geospatial: '地理空間',
  'Security and data types': 'セキュリティとデータ型',
  'Vector data type and similarity search for embeddings, next to your relational data.':
    'リレーショナルデータと並べて使える、埋め込み向けのベクトル型と類似検索。',
  'Trigram matching for fuzzy string search, typo tolerance, and similarity ranking.':
    'あいまい文字列検索、タイプミス許容、類似度ランキング向けのトライグラムマッチング。',
  'Spatial types, indexes, and functions for geographic objects and radius queries.':
    '地理オブジェクトと半径クエリ向けの空間データ型、インデックス、関数。',
  'Represent and query hierarchical, tree-like data such as categories and org charts.':
    'カテゴリや組織図のような階層的・ツリー状のデータを表現してクエリできます。',
  'Cryptographic functions for hashing and encrypting values inside the database.':
    'データベース内の値をハッシュ化・暗号化するための暗号関数。',
  'Case-insensitive text, plus hstore for key/value columns and uuid-ossp for UUIDs.':
    '大文字と小文字を区別しないテキスト。キー/値カラム向けの hstore と UUID 向けの uuid-ossp も含みます。',
  'Free to install and uninstall': 'インストールとアンインストールは無料',
  'Up to 50 extensions per database':
    '1 データベースあたり最大 50 個の拡張機能',
  'Server SDK and API key, or the Console':
    'Server SDK と API キー、または Console',
  'Install from a Server SDK': 'Server SDK からインストール',
  'Parent database': '親データベース',
  'No write pause, no freeze': '書き込みの停止も凍結もなし',
  'Preview environment': 'プレビュー環境',
  'Rehearse an ALTER': 'ALTER のリハーサル',
  'Snapshot copy': 'スナップショットからのコピー',
  'Parent credentials reused': '親の認証情報をそのまま利用',
  'Branches diverge and never merge back':
    'Branch は分岐し、マージバックされません',
  'Own schedule and retention per policy':
    'ポリシーごとに独自のスケジュールと保持期間',
  'Daily policy': '日次ポリシー',
  'Kept until removed': '削除するまで保持',
  'WAL archived continuously': 'WAL を継続的にアーカイブ',
  'Window up to 35 days': '最大 35 日間のウィンドウ',
  'Stored off the instance': 'インスタンス外に保存',
  'Restore in place when you need it': '必要なときにその場で復元',
  'Next to promote': '次の昇格候補',
  'Streaming to 3 replicas': '3 台のレプリカへストリーミング',
  'Automatic failover': '自動フェイルオーバー',
  'Same hostname, same port': '同じホスト名、同じポート',
  'Replication mode': 'レプリケーションモード',
  'Fastest commits': '最速のコミット',
  'One confirmation': '1 台が確認',
  'Majority confirms': '過半数が確認',
  'Queries / sec': 'クエリ / 秒',
  'Active connections': 'アクティブな接続',
  'Cancel or terminate': 'キャンセルまたは終了',
  idle: 'アイドル',
  'idle in transaction': 'トランザクション内アイドル',
  'Resize compute': 'コンピュートのリサイズ',
  'Online, no dump and restore': 'オンラインで実行、ダンプと復元は不要',
  'Grows past 85% usage': '使用率 85% を超えると拡張',

  // Realtime product page
  'Live data over one WebSocket': '1 本の WebSocket でライブデータ',
  'Subscribe to Appwrite events over one WebSocket connection. Realtime brings type-safe channels, server-side query filters, live presence, and permission-aware events to every Appwrite service.':
    '1 本の WebSocket 接続で Appwrite のイベントを購読できます。Realtime は、型安全なチャンネル、サーバー側のクエリフィルター、ライブな Presence、権限を考慮したイベントを Appwrite のすべてのサービスにもたらします。',
  'Subscribe to events from any Appwrite service and receive changes within milliseconds instead of polling for them. A single connection carries every subscription, filters events server-side, and only delivers what the signed-in user is allowed to read.':
    'Appwrite のどのサービスのイベントでも購読でき、ポーリングせずにミリ秒単位で変更を受け取れます。1 本の接続がすべてのサブスクリプションを運び、サーバー側でイベントをフィルターし、サインイン中のユーザーが読み取れるものだけを配信します。',
  'Subscribe to events from any Appwrite service and get changes in milliseconds instead of polling. One connection carries every subscription and only delivers what the user is allowed to read.':
    'Appwrite のどのサービスのイベントでも購読でき、ポーリングせずにミリ秒単位で変更を受け取れます。1 本の接続がすべてのサブスクリプションを運び、ユーザーが読み取れるものだけを配信します。',
  '1 socket': 'ソケット 1 本',
  'Shared by all subscriptions': 'すべてのサブスクリプションで共有',
  Milliseconds: 'ミリ秒',
  'From write to subscriber': '書き込みから購読者まで',
  'Across every service': 'すべてのサービスで',
  'Filtered server-side': 'サーバー側でフィルター',
  'Live online status': 'リアルタイムのオンライン状態',
  'Start building with Realtime': 'Realtime で開発を始める',
  'Subscribe to your first channel and watch rows, files, and presence updates arrive as they happen.':
    '最初のチャンネルを購読して、行、ファイル、Presence の更新が起きた瞬間に届くのを確認できます。',

  // Realtime FAQ
  'What is Appwrite Realtime?': 'Appwrite Realtime とは何ですか?',
  'Realtime is a third protocol for talking to Appwrite, alongside REST and GraphQL. Instead of requesting new data over HTTP, you subscribe once and the server pushes new data to every connected client over a WebSocket as soon as it changes. Subscriptions cover events from all of Appwrite services, not just databases.':
    'Realtime は、REST と GraphQL に並ぶ Appwrite との 3 つめの通信プロトコルです。HTTP で新しいデータを要求する代わりに、一度購読すれば、変更があった瞬間にサーバーが WebSocket 経由で接続中のすべてのクライアントへ新しいデータをプッシュします。購読の対象はデータベースだけでなく、Appwrite のすべてのサービスのイベントです。',
  'How many WebSocket connections does my app open?':
    'アプリはいくつの WebSocket 接続を開きますか?',
  'Client SDKs use a single WebSocket per Realtime client for all subscriptions. Adding one with subscribe(), replacing its channels or queries with update(), and dropping it with unsubscribe() all apply on the existing socket where supported, so there is no full reconnect. The connection closes when you call realtime.disconnect().':
    'クライアント SDK は、Realtime クライアント 1 つあたり 1 本の WebSocket をすべてのサブスクリプションで使います。subscribe() での追加、update() でのチャンネルやクエリの差し替え、unsubscribe() での解除は、対応している範囲で既存のソケット上に適用されるため、完全な再接続は発生しません。接続は realtime.disconnect() を呼んだときに閉じます。',
  'Which resources can I subscribe to?': 'どのリソースを購読できますか?',
  'Channels cover account events, rows, files, teams, memberships, executions, functions, and presences. The Channel helper class builds the channel string for you with a fluent API, so you can target one row or every row in a table. Leave an ID blank to subscribe with a wildcard, and append .create(), .update(), or .delete() to narrow the stream to a single event type.':
    'チャンネルは、アカウントイベント、行、ファイル、チーム、メンバーシップ、実行、関数、presences をカバーします。Channel ヘルパークラスがフルエントな API でチャンネル文字列を組み立てるため、1 行だけを対象にすることも、テーブル内のすべての行を対象にすることもできます。ID を空にするとワイルドカードで購読でき、.create()、.update()、.delete() を付ければストリームを 1 つのイベント種別に絞り込めます。',
  'Can I filter events before they reach my callback?':
    'コールバックに届く前にイベントをフィルターできますか?',
  'Yes. Pass queries as a third parameter when you subscribe and Appwrite filters events server-side, so your callback only runs for updates that match. Realtime supports Query.equal, Query.notEqual, the greater than and less than comparisons, Query.isNull, Query.isNotNull, Query.and, and Query.or.':
    'はい。購読時に 3 つめのパラメーターとしてクエリを渡すと、Appwrite がサーバー側でイベントをフィルターするため、条件に一致する更新だけでコールバックが実行されます。Realtime は Query.equal、Query.notEqual、大なり・小なりの比較、Query.isNull、Query.isNotNull、Query.and、Query.or に対応しています。',
  'What does a Realtime message look like?':
    'Realtime のメッセージはどのような形ですか?',
  'Every message carries four properties: events (the Appwrite events that triggered the update), channels (the channels that can receive it), timestamp (an ISO 8601 time in UTC from the server), and payload (the same data as the matching response model). Branch on the event names in events to decide how to update your UI.':
    'すべてのメッセージは 4 つのプロパティを持ちます。events は更新をトリガーした Appwrite のイベント、channels はそれを受信できるチャンネル、timestamp はサーバーから送られる UTC の ISO 8601 時刻、payload は対応するレスポンスモデルと同じデータです。events のイベント名で分岐して、UI の更新方法を決めます。',
  'Can a user receive updates for data they cannot read?':
    'ユーザーは読み取れないデータの更新を受信できますか?',
  'No. Every subscription is secured by the same permissions system used by rows, files, and presences, so a user only receives updates for resources they have permission to access. Granting read to Role.any() is what makes a resource stream to any client, including visitors who are not signed in.':
    'いいえ。すべてのサブスクリプションは、行、ファイル、presences と同じ権限システムで保護されるため、ユーザーはアクセス権を持つリソースの更新だけを受信します。Role.any() に読み取りを付与した場合にかぎり、サインインしていない訪問者を含むあらゆるクライアントにリソースが配信されます。',
  'What happens when the user signs in or out?':
    'ユーザーがサインインまたはサインアウトすると何が起きますか?',
  'Realtime authenticates with the session that existed when the subscription was created. If you authenticate after subscribing, that subscription will not receive updates for the new user, so create the session first. When a user signs out and another signs in, call realtime.disconnect() and subscribe again for the new session.':
    'Realtime は、サブスクリプションを作成した時点のセッションで認証します。購読した後に認証した場合、そのサブスクリプションは新しいユーザー向けの更新を受信しないため、先にセッションを作成してください。あるユーザーがサインアウトして別のユーザーがサインインしたときは、realtime.disconnect() を呼び、新しいセッションで再度購読します。',
  'How does presence work?': 'Presence はどのように動作しますか?',
  'A presence is a short-lived record tied to a user, with a userId, a free-form status string, an optional metadata object, and an expiresAt timestamp. It is durable, so you can list presences at any time to see who is here, and live, so every change fires upsert, update, and delete events on the presences channels. Keep a record alive with a heartbeat, or use realtime.upsertPresence() so it is removed when the connection closes.':
    'Presence はユーザーに紐づく短命なレコードで、userId、自由形式の status 文字列、任意の metadata オブジェクト、expiresAt のタイムスタンプを持ちます。永続的なので、いつでも一覧を取得して誰がいるかを確認でき、同時にライブなので、変更ごとに presences チャンネルで upsert、更新、削除のイベントが発生します。ハートビートでレコードを維持するか、realtime.upsertPresence() を使って接続が閉じたときに削除されるようにします。',
  'A presence is a short-lived record tied to a user, with a userId, a free-form status, optional metadata, and an expiresAt timestamp. It is durable, so you can list presences at any time, and live, so every change fires upsert, update, and delete events. Keep a record alive with a heartbeat, or use realtime.upsertPresence() so it is removed when the connection closes.':
    'Presence はユーザーに紐づく短命なレコードで、userId、自由形式の status、任意の metadata、expiresAt のタイムスタンプを持ちます。永続的なのでいつでも一覧を取得でき、同時にライブなので、変更ごとに upsert、更新、削除のイベントが発生します。ハートビートでレコードを維持するか、realtime.upsertPresence() を使って接続が閉じたときに削除されるようにします。',
  'Can I use Realtime from a Server SDK with an API key?':
    'API キーを使う Server SDK から Realtime を使えますか?',
  'Not today. Realtime subscriptions are a client SDK feature and are not offered for Server SDKs with an API key. Presence records are the exception: they are also a regular HTTP resource, so server code can write them with an API key that has the presences.write scope and clients will see the change live.':
    '現時点ではできません。Realtime のサブスクリプションはクライアント SDK の機能で、API キーを使う Server SDK には提供されていません。例外は Presence レコードです。これは通常の HTTP リソースでもあるため、presences.write スコープを持つ API キーがあればサーバー側のコードから書き込めますし、クライアントはその変更をライブで受け取れます。',
  'Can I point the SDK at a custom WebSocket endpoint?':
    'SDK をカスタムの WebSocket エンドポイントに向けられますか?',
  'Yes. The SDK derives the Realtime endpoint from your Appwrite endpoint, which is wss://<REGION>.cloud.appwrite.io/v1/realtime by default. If you run Appwrite behind a custom proxy and moved the Realtime route, call setEndpointRealtime on the client with your own value.':
    'はい。SDK は Appwrite のエンドポイントから Realtime のエンドポイントを導出し、既定では wss://<REGION>.cloud.appwrite.io/v1/realtime になります。Appwrite をカスタムプロキシの背後で動かしていて Realtime のルートを変更している場合は、クライアントで setEndpointRealtime を呼び、独自の値を設定してください。',

  // Realtime feature sections
  'One connection, many subscriptions': '1 つの接続で多数のサブスクリプション',
  'Create a Realtime client once and every subscription shares a single WebSocket. Add one with subscribe(), replace its channels or queries with update(), and drop it with unsubscribe() without reconnecting the client. Call disconnect() when you want to close everything at once, like on sign out or app teardown.':
    'Realtime クライアントを一度作れば、すべてのサブスクリプションが 1 本の WebSocket を共有します。subscribe() で追加し、update() でチャンネルやクエリを差し替え、unsubscribe() で解除しても、クライアントは再接続しません。サインアウト時やアプリの終了時のようにまとめて閉じたいときは、disconnect() を呼びます。',
  'Create a Realtime client once and every subscription shares a single WebSocket. Add one with subscribe(), swap its channels or queries with update(), and drop it with unsubscribe(), all without reconnecting.':
    'Realtime クライアントを一度作れば、すべてのサブスクリプションが 1 本の WebSocket を共有します。subscribe() で追加し、update() でチャンネルやクエリを差し替え、unsubscribe() で解除するまで、いずれも再接続は不要です。',
  'Type-safe channels for every service':
    'すべてのサービスに対応する型安全なチャンネル',
  'Channels decide which resources you listen to, and the Channel helper builds them with a fluent API instead of hand-written strings. Target account events, rows, files, teams, memberships, executions, functions, and presences. Leave an ID blank to subscribe with a wildcard, or append .create(), .update(), or .delete() to narrow the stream.':
    'どのリソースを購読するかはチャンネルで決まります。文字列を手書きする代わりに、Channel ヘルパーがフルエントな API でチャンネルを組み立てます。アカウントイベント、行、ファイル、チーム、メンバーシップ、実行、関数、presences を対象にできます。ID を空にするとワイルドカードで購読でき、.create()、.update()、.delete() を付ければストリームを絞り込めます。',
  'The Channel helper builds channel strings with a fluent API instead of hand-written text. Target rows, files, teams, executions, and presences. Leave an ID blank for a wildcard, or append .create() to narrow the stream.':
    'Channel ヘルパーは、文字列を手書きする代わりにフルエントな API でチャンネル文字列を組み立てます。行、ファイル、チーム、実行、presences を対象にできます。ID を空にするとワイルドカードになり、.create() を付ければストリームを絞り込めます。',
  'Filter events before they reach you': '届く前にイベントをフィルター',
  'Pass queries when you subscribe and Appwrite filters events server-side, so your callback only runs for updates that match. The methods are the ones you already use for lists: equal, notEqual, the greater than and less than comparisons, isNull, isNotNull, and the and and or combinators.':
    '購読時にクエリを渡すと、Appwrite がサーバー側でイベントをフィルターするため、条件に一致する更新だけでコールバックが実行されます。使えるメソッドは一覧取得と同じで、equal、notEqual、大なり・小なりの比較、isNull、isNotNull、そして and と or の組み合わせです。',
  'Pass queries when you subscribe and Appwrite filters events on the server, so your callback only runs for updates that match. The methods are the ones you already use for lists, from equal to isNull and or.':
    '購読時にクエリを渡すと、Appwrite がサーバー側でイベントをフィルターするため、条件に一致する更新だけでコールバックが実行されます。使えるメソッドは一覧取得と同じで、equal から isNull、or まで揃っています。',
  'Presence that is durable and live': '永続的でライブな Presence',
  'A presence record carries a userId, a free-form status, an optional metadata object, and an expiresAt timestamp. List presences at any time to see who is here right now, and subscribe to the presences channels for upsert, update, and delete events in milliseconds. Use it for online dots, typing indicators, and who is viewing a document.':
    'Presence レコードは、userId、自由形式の status、任意の metadata オブジェクト、expiresAt のタイムスタンプを持ちます。いつでも一覧を取得して現在誰がいるかを確認でき、presences チャンネルを購読すれば upsert、更新、削除のイベントをミリ秒単位で受け取れます。オンライン表示、入力中インジケーター、ドキュメントの閲覧者表示などに使えます。',
  'A presence record carries a user, a free-form status, optional metadata, and an expiry. List presences to see who is here, and subscribe for upsert, update, and delete events in milliseconds.':
    'Presence レコードは、ユーザー、自由形式のステータス、任意のメタデータ、有効期限を持ちます。一覧を取得すれば今誰がいるかを確認でき、購読すれば upsert、更新、削除のイベントをミリ秒単位で受け取れます。',
  'A predictable payload on every event':
    'すべてのイベントで予測できるペイロード',
  'Each message carries the events that triggered it, the channels that can receive it, an ISO 8601 timestamp from the server, and a payload that matches the response model of the resource. Branch on the event names to tell a create from an update or a delete, then apply the payload straight to your state.':
    '各メッセージは、トリガーとなったイベント、受信できるチャンネル、サーバーから送られる ISO 8601 のタイムスタンプ、リソースのレスポンスモデルと一致するペイロードを持ちます。イベント名で分岐して作成・更新・削除を見分け、ペイロードをそのまま状態に反映できます。',
  'Every message carries the events that triggered it, the channels that can receive it, a server timestamp, and a payload matching the resource response model. Branch on the event names, then apply the payload.':
    'すべてのメッセージは、トリガーとなったイベント、受信できるチャンネル、サーバーのタイムスタンプ、リソースのレスポンスモデルと一致するペイロードを持ちます。イベント名で分岐して、ペイロードをそのまま反映できます。',
  'Permission-aware subscriptions': '権限を考慮したサブスクリプション',
  'Subscriptions use the same permissions as the rest of Appwrite, so a user only receives updates for resources they can read. Realtime uses the session you had when you subscribed, so reconnect when it changes.':
    'サブスクリプションは Appwrite の他の機能と同じ権限を使うため、ユーザーは読み取れるリソースの更新だけを受信します。Realtime は購読した時点のセッションを使うため、セッションが変わったら再接続してください。',
  'Subscriptions are secured by the same permissions system as the rest of Appwrite, so a user only receives updates for resources they can read. Granting read to Role.any() is what opens a stream to every client. Realtime uses the session that existed when you subscribed, so disconnect and subscribe again when the session changes.':
    'サブスクリプションは Appwrite の他の機能と同じ権限システムで保護されるため、ユーザーは読み取れるリソースの更新だけを受信します。Role.any() に読み取りを付与すると、あらゆるクライアントにストリームが開かれます。Realtime は購読した時点のセッションを使うため、セッションが変わったら切断して再度購読してください。',

  // Realtime docs links
  'Realtime overview': 'Realtime の概要',
  'Subscribe docs': '購読のドキュメント',
  'Channels docs': 'チャンネルのドキュメント',
  'Events reference': 'イベントリファレンス',
  'Realtime queries docs': 'Realtime のクエリドキュメント',
  'Payload docs': 'ペイロードのドキュメント',
  'Realtime authentication docs': 'Realtime の認証ドキュメント',
  'Auth presences': '認証の Presences',
  'Custom endpoint docs': 'カスタムエンドポイントのドキュメント',

  // Realtime visuals
  'Realtime client': 'Realtime クライアント',
  '1 WebSocket': 'WebSocket 1 本',
  'Four subscriptions, one connection': '4 つのサブスクリプション、1 つの接続',
  'Add a subscription': 'サブスクリプションを追加',
  'Swap channels, no reconnect': 'チャンネルを差し替えても再接続なし',
  'Drops every subscription': 'すべてのサブスクリプションを解除',
  subscribed: '購読中',
  'Account and teams': 'アカウントとチーム',
  'Files and functions': 'ファイルと関数',
  'Every account event for the signed-in user, from a new session to a name change.':
    '新しいセッションから名前の変更まで、サインイン中のユーザーのすべてのアカウントイベント。',
  'Create, update, and delete events on any team.':
    '任意のチームに対する作成・更新・削除イベント。',
  'Create, update, and delete events on any membership.':
    '任意のメンバーシップに対する作成・更新・削除イベント。',
  'Any create, update, or delete event on rows in a single table.':
    '単一のテーブルの行に対するすべての作成・更新・削除イベント。',
  'Update and delete events for one specific row.':
    '特定の 1 行に対する更新・削除イベント。',
  'Any row event across the project.': 'プロジェクト全体のすべての行イベント。',
  'Update and delete events on any file in one bucket.':
    '1 つのバケット内の任意のファイルに対する更新・削除イベント。',
  'Any update to a function execution.': '関数の実行に対するすべての更新。',
  'Every execution event for one function.':
    '1 つの関数に対するすべての実行イベント。',
  'Upsert, update, and delete events on any presence the subscriber can read.':
    '購読者が読み取れる任意の Presence に対する upsert・更新・削除イベント。',
  'Upsert, update, and delete events on a single presence record.':
    '単一の Presence レコードに対する upsert・更新・削除イベント。',
  'Narrow a presence stream to one event type with a filter.':
    'フィルターで Presence のストリームを 1 つのイベント種別に絞り込みます。',
  'Leave an ID blank and the helper subscribes with a wildcard.':
    'ID を空にすると、ヘルパーはワイルドカードで購読します。',
  'Event filters': 'イベントフィルター',
  'Events on the channel': 'チャンネル上のイベント',
  match: '一致',
  dropped: '破棄',
  'Filtered on the server': 'サーバーでフィルター済み',
  'Your callback': 'コールバック',
  '2 of 3 delivered': '3 件中 2 件を配信',
  'Non-matching events never reach the client.':
    '条件に一致しないイベントはクライアントに届きません。',
  'Who is here': 'いま誰がいるか',
  'List the active set at any time, or follow it live.':
    '現在の一覧はいつでも取得でき、ライブで追跡もできます。',
  'Presence record': 'Presence レコード',
  'Slides on heartbeat': 'ハートビートごとに延長',
  editing: '編集中',
  away: '離席中',
  typing: '入力中',
  'Every message carries': 'すべてのメッセージが持つもの',
  'The Appwrite events that triggered this update.':
    'この更新をトリガーした Appwrite のイベント。',
  'Every channel that can receive this message.':
    'このメッセージを受信できるすべてのチャンネル。',
  'ISO 8601 time in UTC, from the server.':
    'サーバーから送られる UTC の ISO 8601 時刻。',
  'The same data as the resource response model.':
    'リソースのレスポンスモデルと同じデータ。',
  'Branch on the event name': 'イベント名で分岐',
  'Member of team acme': 'チーム acme のメンバー',
  'Not a member of team acme': 'チーム acme のメンバーではない',
  'Event delivered': 'イベントを配信',
  'Nothing delivered': '配信なし',
  'Disconnect and subscribe again when the session changes.':
    'セッションが変わったら切断して再度購読します。',

  // Realtime hero art
  'Appwrite events': 'Appwrite のイベント',
  'Every subscription shares this connection.':
    'すべてのサブスクリプションがこの接続を共有します。',
  'Web app': 'ウェブアプリ',
  'New row in orders': 'orders に新しい行',
  'Mobile app': 'モバイルアプリ',
  'paige@example.com is editing': 'paige@example.com が編集中',
  'Live attendees': '現在の参加者',
  'Server-side filters': 'サーバー側のフィルター',
  'Clients only receive what they can read.':
    'クライアントは読み取れるものだけを受信します。',
  'One WebSocket, filtered server-side.':
    '1 本の WebSocket、サーバー側でフィルター。',
  'Realtime WebSocket API': 'Realtime WebSocket API',
  Presence: 'Presence',
  'REST API': 'REST API',
  'GraphQL API': 'GraphQL API',
  HTTP: 'HTTP',
  v18: 'v18',
  'Everything in Appwrite is realtime': 'Appwrite のすべてが Realtime',
  'Rows, files, function executions, sessions, teams, and presence all emit events on the same WebSocket. Subscribe once, get changes in milliseconds instead of polling, and only ever receive what the user can read.':
    '行、ファイル、Functions の実行、セッション、チーム、Presence は、すべて同じ WebSocket でイベントを発行します。一度購読すれば、ポーリングせずにミリ秒単位で変更を受け取れ、ユーザーが読めるものだけが届きます。',
  'Every service': 'すべてのサービス',
  'Not just databases': 'データベースだけではない',
  'Every service publishes channels: account and sessions, teams and memberships, rows, files, function executions, and presences. The Channel helper class builds the channel string for you with a fluent API, so you can target one row or every row in a table. Leave an ID blank to subscribe with a wildcard, and append .create(), .update(), or .delete() to narrow the stream to a single event type.':
    'すべてのサービスがチャンネルを公開します。アカウントとセッション、チームとメンバーシップ、行、ファイル、Functions の実行、presences です。Channel ヘルパーがフルエントな API でチャンネル文字列を組み立てるため、1 行だけを対象にすることも、テーブル内のすべての行を対象にすることもできます。ID を空にするとワイルドカードで購読でき、.create()、.update()、.delete() を付ければストリームを 1 つのイベント種別に絞り込めます。',
  'Subscribe to your first channel and watch rows, files, executions, and presence updates arrive as they happen.':
    '最初のチャンネルを購読して、行、ファイル、実行、Presence の更新が起きた瞬間に届くのを確認できます。',
  'Every service publishes to a channel': 'すべてのサービスがチャンネルに公開する',
  'Databases, Storage, Functions, Auth, teams, and presence all stream on the same socket. The Channel helper builds the string with a fluent API, so leave an ID blank for a wildcard or append .create() to narrow the stream.':
    'データベース、ストレージ、Functions、認証、チーム、Presence は、すべて同じソケットでストリームします。Channel ヘルパーがフルエントな API で文字列を組み立てるため、ID を空にするとワイルドカードになり、.create() を付ければストリームを絞り込めます。',
  'Auth, teams, and presence': '認証、チーム、Presence',
  'Built-in connection pooling': '組み込みのコネクションプーリング',
  'PostgreSQL spends a backend process per connection, so serverless functions and horizontally scaled app servers exhaust a specification fast. Point runtime traffic at the pooler, same hostname and credentials, and many clients share a small pool of server connections.':
    'PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、サーバーレス関数や水平スケールするアプリサーバーは仕様の上限をすぐに使い切ります。同じホスト名と認証情報のまま、実行時のトラフィックをプーラーに向けると、多数のクライアントが少数のサーバー接続プールを共有します。',
  'The limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. Since PostgreSQL spends a backend process per connection, point runtime traffic at the pooler, where many clients share a small pool of server connections. Keep migrations and long administrative sessions on a direct connection.':
    '接続上限は仕様によって決まり、最小の階層では 100、プラットフォーム全体の上限は 10,000 です。PostgreSQL は接続ごとにバックエンドプロセスを 1 つ使うため、実行時のトラフィックはプーラーに向けてください。そこでは多数のクライアントが少数のサーバー接続プールを共有します。マイグレーションや長時間の管理用セッションは直接接続のままにします。',
  'Yes, with the standard PostgreSQL tooling you already use. Run your dump and restore over a direct connection, because tools like pg_dump expect one session for the whole run, then point your application runtime at the pooler. Any client that speaks the wire protocol works, including pgAdmin and DataGrip.':
    'はい。すでに使っている標準の PostgreSQL ツールでそのまま移行できます。pg_dump のようなツールは処理全体で 1 つのセッションを前提とするため、ダンプと復元は直接接続で実行し、そのうえでアプリケーションの実行時トラフィックをプーラーに向けてください。pgAdmin や DataGrip など、ワイヤープロトコルを話すクライアントであれば何でも利用できます。',
  'A full extension catalog, at no extra cost':
    '追加費用なしの拡張機能カタログ',
  'Each database lists every extension its PostgreSQL version can install, from pgvector and PostGIS to pg_trgm, pgcrypto, and many more. Free to install, up to 50 per database.':
    '各データベースは、その PostgreSQL バージョンがインストールできる拡張機能をすべて一覧表示します。pgvector や PostGIS から pg_trgm、pgcrypto まで、ほかにも多数あります。インストールは無料で、1 データベースあたり最大 50 個までです。',
  'The engine reports every extension this PostgreSQL version can install, so the catalog is the full set available to this database, not a short list. That includes pgvector, PostGIS, pg_trgm, pgcrypto, and many more. Installs are free, up to 50 per database. Uninstalling always cascades, so anything depending on the extension is dropped with it.':
    'エンジンは、この PostgreSQL バージョンがインストールできる拡張機能をすべて報告するため、カタログはそのデータベースで使える一式であり、短い一覧ではありません。pgvector、PostGIS、pg_trgm、pgcrypto のほかにも多数あります。インストールは無料で、1 データベースあたり最大 50 個までです。アンインストールは常にカスケードするため、その拡張機能に依存するものも一緒に削除されます。',
  'Case-insensitive text type, so comparisons ignore letter case.':
    '大文字と小文字を区別しないテキスト型なので、比較時に文字の大小は無視されます。',
  'A sample of the catalog. Your database lists every extension it can install, including hstore, uuid-ossp, and many more.':
    'カタログの一部です。データベースには、hstore や uuid-ossp を含む、インストール可能な拡張機能がすべて表示されます。',
  'Compute credits on every Pro plan':
    'すべての Pro プランに含まれるコンピュートクレジット',
  'Specifications and pricing': '仕様と料金',
  'Specifications docs': '仕様のドキュメント',
  'Use the same hostname and credentials with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your PostgreSQL toolchain. Copy DSN, .env, and ORM snippets from the Console Credentials tab, or follow integration guides in the docs.':
    'Prisma、Drizzle、Sequelize、TypeORM、SQLAlchemy、psql など、PostgreSQL ツールチェーンと同じホスト名と認証情報で接続できます。Console の Credentials タブから DSN、.env、ORM スニペットをコピーするか、ドキュメントの統合ガイドに従ってください。',

  // Videos product page
  'Stream video and audio with Appwrite Videos. Turn files in Storage into adaptive HLS, DASH, and CMAF streams with encoding profiles, subtitles, and scrubbing thumbnails.':
    'Appwrite Videos で動画と音声をストリーミング。ストレージのファイルを、エンコードプロファイル、字幕、シークサムネイル付きのアダプティブ HLS、DASH、CMAF ストリームに変換します。',
  'Adaptive video streaming from your Storage files':
    'ストレージのファイルからアダプティブ動画ストリーミング',
  'Turn video and audio files into HLS, DASH, and CMAF streams with an encoding ladder, subtitles, and scrubbing thumbnails. First frame under 100 ms, by design. One API, in the same project as your Auth, Databases, and Storage.':
    '動画・音声ファイルを、エンコードラダー、字幕、シークサムネイル付きの HLS、DASH、CMAF ストリームに変換します。最初のフレームは設計上 100 ms 未満。認証、データベース、ストレージと同じプロジェクトで使える 1 つの API です。',
  'Start streaming with Videos': '動画のストリーミングを始める',
  'Upload a file to Storage, create a video, and get an adaptive stream URL for any HLS or DASH player.':
    'ストレージにファイルをアップロードして動画を作成すれば、あらゆる HLS / DASH プレーヤーで使えるアダプティブストリーム URL が手に入ります。',
  'Which files can I turn into a video?': 'どのファイルを動画にできますか？',
  'Any video or audio file in an Appwrite Storage bucket. Create a video from the bucket and file IDs, and the source file stays in Storage untouched. Audio-only sources stream the same way but have no timeline thumbnails.':
    'Appwrite Storage のバケットにある動画・音声ファイルなら何でも使えます。バケット ID とファイル ID から動画を作成でき、元のファイルはストレージにそのまま残ります。音声のみのソースも同じようにストリーミングできますが、タイムラインのサムネイルは生成されません。',
  'What is the difference between a profile and a rendition?':
    'プロファイルとレンディションの違いは何ですか？',
  'A profile is a reusable encoding target with a resolution, video and audio bitrate, and codec. A rendition is one encode of a video against a profile, packaged for one output. Each video has at most one rendition per profile and output. Updating a profile does not re-encode existing renditions.':
    'プロファイルは、解像度、動画と音声のビットレート、コーデックを持つ再利用可能なエンコード設定です。レンディションは、動画をプロファイルに沿って 1 回エンコードし、1 つの出力形式にパッケージしたものです。各動画には、プロファイルと出力の組み合わせごとに最大 1 つのレンディションがあります。プロファイルを更新しても、既存のレンディションは再エンコードされません。',
  'Which streaming formats are supported?': 'どのストリーミング形式に対応していますか？',
  'HLS, DASH, and CMAF. HLS uses MPEG-TS segments, DASH uses fMP4 segments, and CMAF encodes fMP4 once and exposes both an HLS and a DASH master manifest. Players request the master manifest and follow it to renditions, segments, and subtitles.':
    'HLS、DASH、CMAF に対応しています。HLS は MPEG-TS セグメント、DASH は fMP4 セグメントを使い、CMAF は fMP4 を 1 回エンコードして HLS と DASH の両方のマスターマニフェストを公開します。プレーヤーはマスターマニフェストを取得し、そこからレンディション、セグメント、字幕をたどります。',
  'How do subtitles work?': '字幕はどのように機能しますか？',
  'Upload a WebVTT or SubRip file to Storage and attach it to a video with a name and an ISO 639-2 language code. Uploads are converted to WebVTT and segmented for streaming. Subtitle tracks embedded in the source are extracted automatically during the first encoding job, and you can retag their language at any time.':
    'WebVTT または SubRip ファイルをストレージにアップロードし、名前と ISO 639-2 の言語コードを付けて動画に追加します。アップロードしたファイルは WebVTT に変換され、ストリーミング用にセグメント化されます。ソースに埋め込まれた字幕トラックは最初のエンコードジョブで自動的に抽出され、言語はいつでも付け直せます。',
  'What happens when I delete a video?': '動画を削除するとどうなりますか？',
  'Deleting a video removes its renditions, captions, timeline thumbnails, and transcoded segments. Deleting a profile keeps the renditions that were already encoded with it.':
    '動画を削除すると、そのレンディション、字幕、タイムラインのサムネイル、トランスコード済みセグメントが削除されます。プロファイルを削除しても、そのプロファイルでエンコード済みのレンディションは残ります。',
  'How do you reach a first frame under 100 ms?': '最初のフレームを 100 ms 未満にするには？',
  'The pipeline is designed for that startup budget. Players load a small master manifest, pick a rendition from the ladder that the connection can fetch immediately, and request a short first segment instead of the source file. Use the inspector to watch time to first frame on a real session.':
    'パイプラインはその起動予算向けに設計されています。プレーヤーは小さなマスターマニフェストを読み込み、回線がすぐ取れるレンディションをラダーから選び、ソースファイルではなく短い最初のセグメントを要求します。実際のセッションの最初のフレームまでの時間はインスペクターで確認できます。',
  'Who can manage videos in my organization?': '組織内で誰が動画を管理できますか？',
  'Videos follows your organization roles. The videos.read and videos.write scopes control who can view and manage videos, renditions, and captions. The project.videos.profiles.read and project.videos.profiles.write scopes cover encoding profiles.':
    '動画は組織のロールに従います。videos.read と videos.write のスコープで、動画、レンディション、字幕を閲覧・管理できるユーザーを制御します。エンコードプロファイルは project.videos.profiles.read と project.videos.profiles.write のスコープで制御します。',
  'Start from any file in Storage': 'ストレージ内の任意のファイルから開始',
  'Create a video from a file that is already in a Storage bucket. The first encoding job probes codecs, resolution, frame rate, duration, and bitrate, so you never parse media metadata yourself.':
    'ストレージのバケットにあるファイルから動画を作成します。最初のエンコードジョブがコーデック、解像度、フレームレート、再生時間、ビットレートを解析するので、メディアのメタデータを自分でパースする必要はありません。',
  'Encode an adaptive bitrate ladder': 'アダプティブビットレートラダーをエンコード',
  'Start from resolution presets or define your own encoding profiles. Renditions encode in the background and report status and progress until they are ready to stream.':
    '解像度のプリセットから始めるか、独自のエンコードプロファイルを定義します。レンディションはバックグラウンドでエンコードされ、ストリーミングの準備ができるまでステータスと進捗を報告します。',
  'HLS, DASH, and CMAF from one source': '1 つのソースから HLS、DASH、CMAF',
  'Package each rendition for the players you support. Every output has a master manifest that players follow to the right rendition for each viewer and connection.':
    'サポートするプレーヤーに合わせて各レンディションをパッケージします。各出力にはマスターマニフェストがあり、プレーヤーは視聴者と回線に合ったレンディションを選びます。',
  'Subtitles, uploaded or extracted': 'アップロードまたは抽出した字幕',
  'Upload WebVTT or SubRip files from Storage, or use the tracks already embedded in the source. Tag each track with a language, choose a default, and players pick it up from the manifest.':
    'ストレージから WebVTT や SubRip ファイルをアップロードするか、ソースに埋め込まれたトラックをそのまま使えます。各トラックに言語を設定してデフォルトを選べば、プレーヤーはマニフェストから読み込みます。',
  'Scrubbing thumbnails on the timeline': 'タイムラインのシークサムネイル',
  'Generate sprite sheets with a WebVTT index so viewers see a preview frame while they scrub.':
    'WebVTT インデックス付きのスプライトシートを生成し、視聴者がシーク中にプレビューフレームを見られるようにします。',
  'Play anywhere': 'どこでも再生',
  'Hand the master manifest URL to hls.js, dash.js, Shaka Player, AVPlayer, or ExoPlayer. Or copy an embed snippet from the Console and drop it into any page.':
    'マスターマニフェストの URL を hls.js、dash.js、Shaka Player、AVPlayer、ExoPlayer に渡すだけです。コンソールから埋め込みスニペットをコピーして、任意のページに貼り付けることもできます。',
  'One rendition per profile and output. Encode the same profile for more than one output.':
    'プロファイルと出力の組み合わせごとにレンディションは 1 つ。同じプロファイルを複数の出力向けにエンコードできます。',
  'For Safari, iOS, tvOS, and hls.js on every other browser.':
    'Safari、iOS、tvOS、およびその他のブラウザーの hls.js 向け。',
  'For dash.js, Shaka Player, ExoPlayer, and smart TVs.':
    'dash.js、Shaka Player、ExoPlayer、スマートテレビ向け。',
  'Encode fMP4 segments once and serve both an HLS and a DASH master.':
    'fMP4 セグメントを 1 回エンコードし、HLS と DASH の両方のマスターを配信します。',
  'Uploads are converted to WebVTT and segmented for streaming. Retag an embedded track from und to its real language without replacing the file.':
    'アップロードしたファイルは WebVTT に変換され、ストリーミング用にセグメント化されます。埋め込みトラックは、ファイルを置き換えずに und から実際の言語に付け直せます。',
  English: '英語',
  Hebrew: 'ヘブライ語',
  'Scrubbing preview': 'シークプレビュー',
  Encode: 'エンコード',
  Stream: 'ストリーム',
  'Probed metadata': '解析済みメタデータ',
  'Welcome to the launch': 'ローンチへようこそ',
  '3 outputs': '3 つの出力',
  'HLS, DASH, and CMAF': 'HLS、DASH、CMAF',
  Adaptive: 'アダプティブ',
  'Uploaded or extracted': 'アップロードまたは抽出',
  Timeline: 'タイムライン',
  'Scrubbing thumbnails': 'シークサムネイル',
  'Any player': '任意のプレーヤー',
  'Web, iOS, and Android': 'Web、iOS、Android',
  'From the first encode': '最初のエンコードから',
  'Source stays in Storage': 'ソースはストレージに残る',
  'The original file is never replaced': '元のファイルは置き換えられません',
  'Default project ladder': 'プロジェクトのデフォルトラダー',
  'Add a profile for any other bitrate': '別のビットレート用にプロファイルを追加',
  'Upload or extract': 'アップロードまたは抽出',
  'Retag an embedded track without replacing the file':
    'ファイルを置き換えずに埋め込みトラックの言語を付け直す',
  'Poster frame included': 'ポスターフレーム付き',
  'Same sprites, same index': '同じスプライト、同じインデックス',
  'Master manifest URL': 'マスターマニフェスト URL',
  Excerpt: '抜粋',
  'Demo video by McPix22': 'McPix22 のデモ動画',
  'Inspect quality as viewers play': '再生中の品質を検査',
  'Pop the inspector out beside any video in the Console. Watch a live experience score for that session, then drill into buffer, bitrate, dropped frames, and diagnostics when a stream misses the sub 100 ms first frame.':
    'コンソールで任意の動画の横にインスペクターを切り離して開けます。そのセッションのライブ体験スコアを確認し、ストリームが 100 ms 未満の最初のフレームを逃したときは、バッファ、ビットレート、ドロップフレーム、診断を掘り下げられます。',
  'Live experience score': 'ライブ体験スコア',
  'Follows the playing video': '再生中の動画に追従',
  'Pop out beside the player': 'プレーヤーの横に切り離して表示',
  '100 ms': '100 ms',
  'To first frame': '最初のフレームまで',
  'First frame': '最初のフレーム',
  'First frame in 100 ms': '最初のフレームは 100 ms',
  'Web, mobile, and native': 'Web、モバイル、ネイティブ',
  'Embed a built-in player': '内蔵プレーヤーを埋め込む',
  'Copy an iframe from the Console and drop the Appwrite player on any page. Set a start time, choose captions, lock a rendition, or leave quality on Auto. The stream is designed for a first frame in under 100 ms.':
    'コンソールから iframe をコピーして、任意のページに Appwrite プレーヤーを置けます。開始位置、字幕、レンディションの固定、または画質 Auto を選べます。ストリームは最初のフレームを 100 ms 未満で出すように設計されています。',
  'Use any player on any platform': 'どのプラットフォームでも任意のプレーヤーを使用',
  'Prefer your own player? The same master manifest works with hls.js, Shaka Player, and Video.js on the web, AVPlayer on Apple platforms, ExoPlayer on Android, and the usual Flutter and React Native players.':
    '既存のプレーヤーを使いたい場合も、同じマスターマニフェストが Web の hls.js、Shaka Player、Video.js、Apple の AVPlayer、Android の ExoPlayer、通常の Flutter と React Native プレーヤーで再生できます。',
  Autoplay: '自動再生',
  Captions: '字幕',
  Quality: '画質',
  'Embed on any page': '任意のページに埋め込み',
  'Flutter · React Native': 'Flutter · React Native',
  'First frame under 100 ms': '最初のフレームは 100 ms 未満',
  'Press play, see picture in under 100 ms': '再生を押して 100 ms 未満で映像を表示',
  'No waiting on the whole file. Streams open from a few kilobytes and one short segment, so picture lands before anyone notices.':
    'ファイル全体を待つ必要はありません。ストリームは数 KB と短いセグメント 1 つで始まり、誰かが気づく前に映像が表示されます。',
  'Master manifest': 'マスターマニフェスト',
  'First segment': '最初のセグメント',
  'Under 100 ms': '100 ms 未満',
  'Appwrite stream': 'Appwrite ストリーム',
  'Init segment': 'init セグメント',
  'Progressive download': 'プログレッシブダウンロード',
  'Whole file first': 'まずファイル全体',
  'Still downloading, no picture yet': 'ダウンロード中、まだ映像なし',
  budget: '予算',
  'to spare': '余裕',
  'Manifest to map the stream': 'ストリームを示すマニフェスト',
  'Starting rung, 1080p next': '開始の段、次に 1080p',
  'Segment to paint a frame': 'フレーム描画までのセグメント',
  'Picture on screen': '映像を表示中',
  'Climbs the ladder': 'ラダーを上がる',

  // Monitors, Beep, and Reviews product pages
  // Beep product page
  'Beep: the Appwrite Cloud app for your phone':
    'Beep: スマートフォン向けの Appwrite Cloud アプリ',
  'Appwrite Beep is the free Appwrite Cloud app for iPhone, iPad, and Android. Get a push when a monitor fires, acknowledge it for your team, page the next person in line, and check usage across every project.':
    'Appwrite Beep は、iPhone、iPad、Android 向けの無料の Appwrite Cloud アプリです。モニターでアラートが発生するとプッシュ通知を受け取り、確認済みにしてチームに知らせたり、次の担当者を呼び出したり、すべてのプロジェクトの使用量を確認したりできます。',
  'Your projects, in your pocket': 'プロジェクトを、ポケットに',
  'Appwrite Beep is the Appwrite Cloud app for iPhone, iPad, and Android. Get a push the moment a monitor fires, acknowledge it for your team, and check usage across every project. You choose what reaches you.':
    'Appwrite Beep は、iPhone、iPad、Android 向けの Appwrite Cloud アプリです。モニターでアラートが発生した瞬間にプッシュ通知を受け取り、確認済みにしてチームに知らせたり、すべてのプロジェクトの使用量を確認したりできます。受け取る通知は自分で選べます。',
  'On the App Store and Google Play': 'App Store と Google Play で配信中',
  'All your organizations and projects': 'すべての組織とプロジェクト',
  'Acknowledge, snooze, or mute': '確認済みにする、スヌーズ、ミュート',
  '30 minutes to 24 hours': '30 分から 24 時間まで',
  'Rings through silent mode on Android':
    'Android ではサイレントモードでも鳴動',
  'What is Appwrite Beep?': 'Appwrite Beep とは何ですか?',
  'Beep is the Appwrite Cloud app for your phone. It pushes alerts from your monitors as they fire and resolve, lets you acknowledge, snooze, and escalate them, and shows usage, Appwrite Cloud status, and the changelog for every organization and project you belong to.':
    'Beep は、スマートフォン向けの Appwrite Cloud アプリです。モニターのアラートを発生時と解決時にプッシュ通知で届け、アラートを確認済みにしたり、スヌーズしたり、エスカレーションしたりできます。また、所属するすべての組織とプロジェクトの使用量、Appwrite Cloud のステータス、変更履歴も確認できます。',
  'Is Beep free?': 'Beep は無料ですか?',
  'Yes. Beep is free on the App Store and Google Play, with no ads or in-app purchases. Sign in with your Appwrite Cloud account. The monitors that send alerts follow your organization’s plan.':
    'はい。Beep は App Store と Google Play で無料で提供されており、広告やアプリ内課金はありません。Appwrite Cloud のアカウントでサインインしてください。アラートを送信するモニターの利用範囲は、組織のプランによって決まります。',
  'Which notifications reach my phone?':
    'どの通知がスマートフォンに届きますか?',
  'Alerts from usage monitors, budget thresholds, and HTTP, DNS, TCP, and SMTP endpoint checks when they fire, and when they resolve if you want, plus a push when Appwrite publishes a changelog entry. Appwrite Cloud incidents show in the Updates tab.':
    '使用量モニター、予算のしきい値、HTTP、DNS、TCP、SMTP のエンドポイントチェックのアラートが、発生したとき (希望すれば解決したときも) に届きます。Appwrite が変更履歴を公開したときにもプッシュ通知が届きます。Appwrite Cloud の障害情報は「アップデート」タブに表示されます。',
  'Appwrite Monitors': 'Appwrite Monitors',
  'Who gets a push when a monitor fires?':
    'モニターでアラートが発生したとき、誰にプッシュ通知が届きますか?',
  'The organization roles the monitor notifies, Owners by default, on every phone where they are signed in to Beep. With an escalation, each step notifies its roles and people in turn until someone acknowledges the alert or it resolves. The same people also get an email and a Console notification.':
    'モニターが通知する組織のロール (デフォルトはオーナー) のメンバーに、Beep にサインインしているすべてのスマートフォンで届きます。エスカレーションを設定している場合は、誰かがアラートを確認済みにするか、アラートが解決するまで、各ステップのロールとメンバーに順番に通知します。同じメンバーには、メールとコンソール通知も届きます。',
  'Can I stop notifications without missing alerts?':
    'アラートを見逃さずに通知を止められますか?',
  'Yes. Snooze one organization or all of them for 30 minutes, 1 hour, 4 hours, or 24 hours, turn off organizations and projects you don’t watch, or mute a single monitor. Snoozed alerts still fire and still show in Beep, the Console, and email. Muting a monitor also stops its emails to you.':
    'はい。1 つの組織またはすべての組織を 30 分、1 時間、4 時間、24 時間スヌーズしたり、見ていない組織やプロジェクトをオフにしたり、特定のモニターだけをミュートしたりできます。スヌーズ中もアラートは発生し、Beep、コンソール、メールに表示されます。モニターをミュートすると、そのモニターからあなた宛てのメールも止まります。',
  'Do critical alerts ring through silent mode?':
    '重大なアラートはサイレントモードでも鳴りますか?',
  'On Android, yes. Monitors your team sets to Critical ring at alarm volume, even on silent, and in Do Not Disturb once you let Beep’s critical alerts override it. On iPhone, critical monitors arrive as normal notifications that follow your silent switch and Focus for now.':
    'Android では鳴ります。チームが「重大」に設定したモニターは、サイレントモードでもアラームの音量で鳴ります。Beep の重大なアラートがおやすみモードより優先されるよう許可すれば、おやすみモード中も鳴ります。iPhone では現在、重大なモニターも通常の通知として届き、サイレントスイッチと集中モードの設定に従います。',
  'Can I create monitors in Beep?': 'Beep でモニターを作成できますか?',
  'Monitors are created in the Console. In Beep you can acknowledge alerts, adjust a usage monitor’s threshold, pause or resume a monitor, and create and assign escalations.':
    'モニターはコンソールで作成します。Beep では、アラートを確認済みにしたり、使用量モニターのしきい値を調整したり、モニターを一時停止・再開したり、エスカレーションを作成して割り当てたりできます。',
  'Does Beep work with self-hosted Appwrite?':
    'Beep はセルフホストの Appwrite でも使えますか?',
  'No. Beep signs in to Appwrite Cloud only.':
    'いいえ。Beep がサインインできるのは Appwrite Cloud のみです。',
  'Put your projects in your pocket': 'プロジェクトを、いつでも手元に',
  'Download Beep, sign in with your Appwrite account, and choose what reaches you.':
    'Beep をダウンロードして Appwrite アカウントでサインインし、受け取る通知を選びましょう。',
  'Know the moment a monitor fires': 'アラートの発生をその瞬間に把握',
  'Usage monitors, budget thresholds, and endpoint checks push to every phone you are signed in on, with the severity in the title and the reading in the body. Acknowledge, snooze for an hour, or mute the monitor right from the notification.':
    '使用量モニター、予算のしきい値、エンドポイントチェックのアラートが、サインインしているすべてのスマートフォンにプッシュ通知で届きます。タイトルには重大度、本文には測定値が表示されます。通知から直接、確認済みにしたり、1 時間スヌーズしたり、モニターをミュートしたりできます。',
  'Know how bad it really is': 'どれほど深刻かを正確に把握',
  'Open an alert for the reading against its threshold, its peak, and the hours around it. Endpoint checks show the result, the response time, and when the certificate expires. Acknowledge it so your team sees you have it, adjust the threshold, or jump to the project.':
    'アラートを開くと、しきい値に対する測定値、ピーク、前後数時間の推移を確認できます。エンドポイントチェックでは、結果、応答時間、証明書の有効期限が表示されます。確認済みにして対応中であることをチームに知らせたり、しきい値を調整したり、プロジェクトに移動したりできます。',
  'Page the next person in line': '次の担当者を呼び出す',
  'Give a monitor an escalation and Beep notifies each step’s roles and people in turn, right away or after a wait you choose, until someone acknowledges the alert or it resolves. Create, edit, and assign escalations from your phone.':
    'モニターにエスカレーションを設定すると、誰かがアラートを確認済みにするか、アラートが解決するまで、Beep が各ステップのロールとメンバーに順番に通知します。通知は、すぐに、または指定した待機時間の後に送られます。エスカレーションの作成、編集、割り当てはスマートフォンからも行えます。',
  'Appwrite Cloud, at a glance': 'Appwrite Cloud をひと目で',
  'Home shows what is firing, how Appwrite Cloud is doing, and two weeks of alerts by severity, with your projects one tap away. Switch organizations from the menu and everything follows.':
    'ホームには、発生中のアラート、Appwrite Cloud の稼働状況、重大度別の 2 週間分のアラートが表示され、プロジェクトにもワンタップでアクセスできます。メニューから組織を切り替えると、すべての表示がその組織に切り替わります。',
  'Usage for every project': 'すべてのプロジェクトの使用量',
  'Search and filter every project in the organization, with firing ones first. Open one for bandwidth, requests, storage, executions, and compute over the last day, week, or month, next to the databases, functions, sites, and users it holds.':
    '組織内のすべてのプロジェクトを検索、絞り込みでき、アラートが発生中のプロジェクトが先頭に表示されます。プロジェクトを開くと、過去 1 日、1 週間、1 か月の帯域幅、リクエスト、ストレージ、実行、コンピュートを、そのプロジェクトにあるデータベース、関数、サイト、ユーザーとあわせて確認できます。',
  'You choose what reaches you': '受け取る通知を自分で選べる',
  'Turn organizations and projects on or off, decide whether resolved alerts and Appwrite updates reach you, and mute a single noisy monitor. Your choices belong to your account, so they apply on every device and in the Console.':
    '組織やプロジェクトごとに通知をオン・オフしたり、解決済みのアラートや Appwrite のアップデートを受け取るかどうかを決めたり、通知の多いモニターを個別にミュートしたりできます。設定はアカウントに保存されるため、すべてのデバイスとコンソールに適用されます。',
  'Quiet the noise while you fix it': '対応中は通知を静かに',
  'When one incident sends a burst of pushes, Home offers to snooze. Pause one organization or all of them for up to 24 hours. Alerts keep firing and showing in Beep, the Console, and email, and Beep tells you when notifications are back on.':
    '1 つの障害でプッシュ通知が集中して届くと、ホームにスヌーズの提案が表示されます。1 つの組織またはすべての組織の通知を最大 24 時間停止できます。その間もアラートは発生し、Beep、コンソール、メールに表示され、通知が再開すると Beep がお知らせします。',
  'Ring through silent mode': 'サイレントモードでも鳴らす',
  'On Android, monitors your team sets to Critical ring like an alarm, at alarm volume, even on silent, and in Do Not Disturb once you allow it. Everything else stays a normal notification.':
    'Android では、チームが「重大」に設定したモニターが、サイレントモードでもアラームと同じ音量でアラームのように鳴り、許可すればおやすみモード中も鳴ります。それ以外は通常の通知のままです。',
  'Keep up with every release': 'すべてのリリースを見逃さない',
  'The Updates tab shows Appwrite Cloud incidents as they happen and every changelog entry, with a push when something new ships. Read it in the app, or turn product updates off in settings.':
    '「アップデート」タブには、Appwrite Cloud の障害情報が発生と同時に表示されるほか、すべての変更履歴も表示され、新機能のリリース時にはプッシュ通知が届きます。アプリ内で読むことも、設定でプロダクトアップデートをオフにすることもできます。',
  'On every device you carry': '持ち歩くすべてのデバイスで',
  'Beep runs on iPhone, iPad, Android phones and foldables, and Macs with Apple silicon. Sign in once with your Appwrite account and every device gets your alerts.':
    'Beep は、iPhone、iPad、Android のスマートフォンと折りたたみ端末、Apple シリコン搭載の Mac で動作します。Appwrite アカウントでサインインするだけで、すべてのデバイスにアラートが届きます。',
  'Now · Outbound bandwidth': '現在 · 送信帯域幅',
  '> 5 GB threshold · peak 6.9 GB': 'しきい値 > 5 GB · ピーク 6.9 GB',
  Adjust: '調整',
  'Around this alert, per hour': 'アラート前後の推移 (1 時間ごと)',
  Announced: '通知回数',
  'Open in console': 'コンソールで開く',
  'Mute this monitor': 'このモニターをミュート',
  Budget: '予算',
  Home: 'ホーム',
  'Outbound bandwidth · storefront-prod': '送信帯域幅 · storefront-prod',
  'Checkout API health': 'チェックアウト API のヘルスチェック',
  'Budget at 100%': '予算の 100% に到達',
  'Budget used · Organization budget': '予算使用率 · 組織の予算',
  'Do Not Disturb': 'おやすみモード',
  'Critical alerts': '重大なアラート',
  'Open alert': 'アラートを開く',
  'Ring for what matters': '大事なアラートだけを鳴らす',
  'Monitors your team sets to Critical ring this phone like an alarm, even on silent and in Do Not Disturb. Everything else stays a normal notification.':
    'チームが「重大」に設定したモニターは、サイレントモードやおやすみモードでも、このスマートフォンをアラームのように鳴らします。それ以外は通常の通知のままです。',
  'Set monitors to Critical': 'モニターを「重大」に設定',
  'In the console, as a usage monitor’s severity or on a budget threshold.':
    'コンソールで、使用量モニターの重大度として、または予算のしきい値で設定します。',
  'Let them override Do Not Disturb': 'おやすみモードより優先させる',
  'Android asks you to switch it on for the channel.':
    'Android から、通知チャンネルでオンにするよう求められます。',
  'Set the volume': '音量を設定',
  'They ring at your alarm volume.': 'アラームの音量で鳴ります。',
  'iOS 16.4 or later, in light or dark mode with your system.':
    'iOS 16.4 以降。システムの設定に合わせて、ライトモードとダークモードで表示されます。',
  'A dashboard layout with what is firing, usage, and projects side by side.':
    '発生中のアラート、使用量、プロジェクトを並べて表示するダッシュボードレイアウト。',
  'Phones and foldables, with critical alerts that ring through silent mode.':
    'スマートフォンと折りたたみ端末に対応し、重大なアラートはサイレントモードでも鳴ります。',
  Mac: 'Mac',
  'Runs on Macs with Apple silicon, with a sidebar instead of tabs.':
    'Apple シリコン搭載の Mac で動作し、タブの代わりにサイドバーで操作します。',
  'Your Appwrite account': 'Appwrite アカウント',
  'Sign in with GitHub, Google, or email, with the same MFA as the Console.':
    'GitHub、Google、メールでサインインでき、コンソールと同じ MFA が適用されます。',
  'Works offline': 'オフラインでも利用可能',
  'Lose signal and Beep shows the last data it loaded, for up to 24 hours.':
    '電波が届かなくなっても、Beep は最後に読み込んだデータを最大 24 時間表示します。',
  Shortcuts: 'ショートカット',
  'Long-press the app icon for firing alerts, or to snooze for an hour.':
    'アプリアイコンを長押しすると、発生中のアラートを確認したり、1 時間スヌーズしたりできます。',
  'Free on the App Store and Google Play, with no ads or in-app purchases.':
    'App Store と Google Play で無料。広告やアプリ内課金はありません。',
  'Who an alert notifies, step by step, until someone acknowledges it or it resolves.':
    '確認済みになるか解決するまで、アラートを順に通知する相手。',
  'Then repeats 2 more times': 'その後、さらに 2 回繰り返し',
  'Your team sees that you have it, and the escalation stops.':
    '対応中であることがチームに伝わり、エスカレーションが止まります。',
  'Create and edit escalations on the phone':
    'スマートフォンでエスカレーションを作成・編集',
  '3 firing': '3 件が発生中',
  '2 critical · 1 error · 2 acknowledged':
    '重大 2 件 · エラー 1 件 · 確認済み 2 件',
  'Appwrite Cloud · Delayed Realtime events':
    'Appwrite Cloud · Realtime イベントの遅延',
  'Alerts fired · last 14 days': '発生したアラート · 過去 14 日間',
  '4 critical · busiest day Nov 19, 8 alerts':
    '重大 4 件 · 最多は 11月19日の 8 件',
  'See all': 'すべて表示',
  'Frankfurt · 379K requests in 24h':
    'フランクフルト · 24 時間で 379K リクエスト',
  'New York · 432K requests in 24h': 'ニューヨーク · 24 時間で 432K リクエスト',
  'Sydney · 58K requests in 24h': 'シドニー · 24 時間で 58K リクエスト',
  'Requests · 24h': 'リクエスト · 24 時間',
  '375K previous 24h': '直前の 24 時間は 375K',
  'In this project': 'このプロジェクトのリソース',
  'Thursday, December 3': '12月3日 木曜日',
  'Your team sees you have it': '対応中であることがチームに伝わります',
  'Snooze 1 hour': '1 時間スヌーズ',
  Snooze: 'スヌーズ',
  'Resolved alerts': '解決済みのアラート',
  'When an alert stops firing': 'アラートが解決したとき',
  'When Appwrite publishes a changelog entry':
    'Appwrite が変更履歴を公開したとき',
  'Acme Inc': 'Acme Inc',
  'Side projects': 'サイドプロジェクト',
  '7 notifications in 30 minutes': '30 分間に 7 件の通知',
  'Snooze notifications while you look into it. Alerts keep showing here and in the console.':
    '調査中は通知をスヌーズできます。アラートは引き続きここやコンソールに表示されます。',
  'Snooze notifications': '通知をスヌーズ',
  'All organizations': 'すべての組織',
  'No notifications from Acme Inc until then, critical alerts included, on all your devices. Alerts still fire and show here, in the console and in email.':
    'それまでは、重大なアラートも含め、Acme Inc からの通知はどのデバイスにも届きません。アラートは引き続き発生し、ここ、コンソール、メールに表示されます。',
  'Cloud status': 'Cloud のステータス',
  'Ongoing · Nov 19 at 4:14 PM': '継続中 · 11月19日 午後4:14',
  'Delayed Realtime events': 'Realtime イベントの遅延',
  Changelog: '変更履歴',
  'One-click APNs setup with the Appwrite CLI':
    'Appwrite CLI で APNs をワンクリック設定',
  'Set up APNs for an iOS app with one CLI command':
    'CLI コマンド 1 つで iOS アプリの APNs を設定',
  'Run appwrite init apns in your iOS project and the CLI sets up push notifications in one command.':
    'iOS プロジェクトで appwrite init apns を実行すると、CLI がコマンド 1 つでプッシュ通知を設定します。',
  'Usage monitors for every project':
    'すべてのプロジェクトで使用量モニターが利用可能に',
  'Get notified when a project crosses a usage threshold, by email or on your phone.':
    'プロジェクトの使用量がしきい値を超えると、メールまたはスマートフォンで通知を受け取れます。',
  '[Critical] Egress spike on storefront-prod':
    '[重大] 送信トラフィックの急増 (storefront-prod)',
  '6.2 GB > 5 GB · storefront-prod · Acme Inc · Frankfurt':
    '6.2 GB > 5 GB · storefront-prod · Acme Inc · フランクフルト',
  '[Error] Checkout API health on checkout-api':
    '[エラー] チェックアウト API のヘルスチェック (checkout-api)',
  'The HTTP check of api.checkout.example.com/v1/health failed: unexpected status code (503).':
    'api.checkout.example.com/v1/health の HTTP チェックが失敗しました: 想定外のステータスコード (503)。',
  '[Critical] Budget at 100% on Acme Inc':
    '[重大] 予算の 100% に到達 (Acme Inc)',
  '102% > 100% · Organization budget': '102% > 100% · 組織の予算',
  // Reviews product page
  'Reviews: AI code review for every pull request':
    'コードレビュー: すべてのプルリクエストを AI がレビュー',
  'Appwrite Reviews reviews every pull request on your Sites, Functions, and repositories on GitHub, GitLab, and Bitbucket. A summary, findings on the lines they concern, a verdict, and a merge confidence grade, on the same Git connection, Console, and bill as your deploys.':
    'Appwrite Reviews は、GitHub、GitLab、Bitbucket 上のサイト、Functions、リポジトリのすべてのプルリクエストをレビューします。概要、該当する行への指摘事項、判定、マージの信頼度を、デプロイと同じ Git 接続、コンソール、請求のまま利用できます。',
  'Every pull request, reviewed': 'すべてのプルリクエストに、レビューを',
  'Appwrite Reviews reads each pull request on your Sites, Functions, and repositories, and posts a summary, findings on the lines they concern, a verdict, and a merge confidence grade. It runs on the same Git connection, Console, and bill as your deploys.':
    'Appwrite Reviews は、サイト、Functions、リポジトリの各プルリクエストを読み込み、概要、該当する行への指摘事項、判定、マージの信頼度を投稿します。デプロイと同じ Git 接続、コンソール、請求で動作します。',
  'On GitHub, GitLab, and Bitbucket':
    'GitHub、GitLab、Bitbucket に対応',
  'Merge confidence on every review': 'すべてのレビューにマージの信頼度',
  'Each finding checked before it posts': 'すべての指摘を投稿前に検証',
  'Credits from your Appwrite plan': 'Appwrite プランのクレジットを使用',
  'For new Sites and Functions': '新しいサイトと Functions で',
  'What is Appwrite Reviews?': 'Appwrite Reviews とは何ですか?',
  'Appwrite Reviews is AI code review built into Appwrite. It reviews every pull request on the repositories you turn it on for, and posts a summary, findings on the lines they concern, a verdict, and a merge confidence grade from S to F on the pull request, with the full review and its trace in the Console.':
    'Appwrite Reviews は、Appwrite に組み込まれた AI コードレビューです。有効にしたリポジトリのすべてのプルリクエストをレビューし、概要、該当する行への指摘事項、判定、S から F までのマージの信頼度をプルリクエストに投稿します。レビューの全文とトレースはコンソールで確認できます。',
  'Which Git providers are supported?':
    'どの Git プロバイダーに対応していますか?',
  'GitHub, GitLab, including self-managed GitLab, and Bitbucket. On GitHub the reviewer is the same Appwrite app that deploys your Sites and Functions. On GitLab and Bitbucket it comments as the account that connected the installation.':
    'GitHub、GitLab (セルフマネージドの GitLab を含む)、Bitbucket に対応しています。GitHub では、サイトと Functions をデプロイするのと同じ Appwrite アプリがレビュアーになります。GitLab と Bitbucket では、インストールを接続したアカウントとしてコメントします。',
  'When does a review run?': 'レビューはいつ実行されますか?',
  'When a pull request is opened, reopened, or marked ready for review, and again when it gets new commits, which are reviewed incrementally. Mention the app with “review” to run it again. Drafts and closed pull requests are skipped.':
    'プルリクエストが作成、再オープン、またはレビュー準備完了になったときに実行され、新しいコミットが追加されると、その差分を再びレビューします。アプリにメンションして「review」とコメントすると、再実行できます。ドラフトとクローズ済みのプルリクエストはスキップされます。',
  'What does the reviewer read?': 'レビュアーは何を読み込みますか?',
  'The pull request and the repository: the diff, the code at the pull request’s head, the title and description, linked issues, failed checks from your other CI apps, dependency changes, and guideline files such as AGENTS.md, CLAUDE.md, and CONTRIBUTING.md. It doesn’t read your Appwrite project’s databases, files, logs, or secrets.':
    'プルリクエストとリポジトリです。差分、プルリクエストのヘッド時点のコード、タイトルと説明、リンクされた Issue、ほかの CI アプリで失敗したチェック、依存関係の変更、そして AGENTS.md、CLAUDE.md、CONTRIBUTING.md などのガイドラインファイルを読み込みます。Appwrite プロジェクトのデータベース、ファイル、ログ、シークレットは読み込みません。',
  'Does it approve pull requests?': 'プルリクエストを承認しますか?',
  'It posts a verdict with every review: approve, request changes, or comment. The verdict and the grade cap are decided by code from the findings, not by the model, and a pull request from someone without write access is not approved by default. Each review also posts a status check, so your branch rules can require it.':
    'レビューごとに、承認、変更をリクエスト、コメントのいずれかの判定を投稿します。判定とグレードの上限は、モデルではなく指摘事項をもとにコードで決まり、書き込み権限のないユーザーからのプルリクエストはデフォルトでは承認されません。各レビューはステータスチェックも投稿するため、ブランチ保護ルールで必須にできます。',
  'Can I talk to the reviewer?': 'レビュアーと会話できますか?',
  'Yes. Reply to any of its findings or mention the app with a question. It reads the code to answer, can resolve or dismiss a finding when the conversation settles it, and can save a learning for later reviews. Answers use no credits.':
    'はい。指摘に返信するか、アプリにメンションして質問できます。レビュアーはコードを読んで回答し、会話で結論が出れば指摘を解決済みにしたり却下したりできます。また、以降のレビューのために学習を保存することもできます。回答ではクレジットを消費しません。',
  'How is Reviews priced?': 'コードレビューの料金はどのように決まりますか?',
  'Reviews use credits from your Appwrite plan, with no per-seat fees. A Basic review uses 10 credits, Normal 50, and Expert 500. Free includes 100 credits a month for your organization, Pro includes 2,000 a month per project, and Scale 20,000. On paid plans, extra credits cost $1 per 100. Answers in conversations, failed reviews, and skipped reviews are free.':
    'レビューには Appwrite プランのクレジットを使用し、シート単位の料金はかかりません。1 回のレビューで使うクレジットは、ベーシックが 10、ノーマルが 50、エキスパートが 500 です。Free には組織あたり月 100 クレジット、Pro にはプロジェクトあたり月 2,000 クレジット、Scale には 20,000 クレジットが含まれます。有料プランでは、追加クレジットは 100 クレジットあたり $1 です。会話での回答、失敗したレビュー、スキップされたレビューは無料です。',
  'Does Reviews work with self-hosted Appwrite?':
    'コードレビューはセルフホストの Appwrite でも使えますか?',
  'Self-hosted instances can opt in by running the review service with their own model provider. Reviews there read the code without running commands in a sandbox.':
    'セルフホストのインスタンスでは、独自のモデルプロバイダーでレビューサービスを実行することで利用できます。その場合、レビューはサンドボックスでコマンドを実行せずにコードを読み込みます。',
  'Review your next pull request': '次のプルリクエストをレビュー',
  'Turn on Reviews for a repository, open a pull request, and get a summary, findings, and a merge confidence grade where you already work.':
    'リポジトリでコードレビューを有効にしてプルリクエストを作成すれば、いつもの作業場所で概要、指摘事項、マージの信頼度を受け取れます。',
  'On where your code already ships':
    'コードをデプロイする場所で、最初から有効',
  'Create a site or function from a repository and Review pull requests is already on. Turn it on for any other repository on GitHub, GitLab, or Bitbucket from the Repositories tab, one switch at a time or all at once.':
    'リポジトリからサイトや関数を作成すると、「プルリクエストをレビュー」が最初から有効になっています。GitHub、GitLab、Bitbucket のほかのリポジトリも、「リポジトリ」タブから 1 つずつ、またはまとめて有効にできます。',
  'A review on every pull request': 'プルリクエストごとにレビュー',
  'When a pull request opens or gets new commits, the reviewer posts one summary that it edits in place: what changed, a verdict, and a merge confidence grade from S to F, with the findings, a walkthrough, and what was fixed since the last review. New commits get an incremental review of just what changed.':
    'プルリクエストが作成されたときや新しいコミットが追加されたとき、レビュアーは概要を 1 つ投稿し、その場で更新していきます。概要には、変更内容、判定、S から F までのマージの信頼度に加え、指摘事項、ウォークスルー、前回のレビュー以降に修正された内容が含まれます。新しいコミットは、変更された部分だけを差分レビューします。',
  'Findings on the lines they concern': '指摘は該当する行に直接',
  'Each finding lands on the changed line with its severity, its category, and a plain explanation. When the fix is small, local, and certain, it comes as a suggested change, and every finding carries a prompt to fix it with AI.':
    '各指摘は、重要度、カテゴリ、わかりやすい説明とともに、変更された行に投稿されます。修正が小さく、局所的で、確実な場合は変更の提案として届き、すべての指摘に AI で修正するためのプロンプトが付きます。',
  'Grounded in your code, checked before it posts':
    'コードに基づき、投稿前に検証',
  'The reviewer reads the whole repository at the pull request’s head, your team’s guideline files, linked issues, and failed checks from your other CI apps. On Normal and Expert it can run commands in an isolated sandbox to confirm a suspected bug, and a second model checks every finding before anything is posted.':
    'レビュアーは、プルリクエストのヘッド時点のリポジトリ全体、チームのガイドラインファイル、リンクされた Issue、ほかの CI アプリで失敗したチェックを読み込みます。ノーマルとエキスパートでは、隔離されたサンドボックスでコマンドを実行してバグの疑いを確かめられ、投稿前には 2 つめのモデルがすべての指摘を検証します。',
  'Reply to a finding or mention the app with a question. It reads the code to answer, settles the thread when you are right, and holds its ground on bugs and security issues until the facts say otherwise. Answers use no credits.':
    '指摘に返信するか、アプリにメンションして質問できます。レビュアーはコードを読んで回答し、あなたが正しければスレッドを解決します。バグやセキュリティの問題については、事実が異なると示されるまで見解を変えません。回答ではクレジットを消費しません。',
  'It remembers how your team works': 'チームのやり方を記憶',
  'Ask it to remember a preference in any pull request and it becomes a learning that later reviews and answers in that repository follow. See every learning in the Console, who asked for it, and remove the ones you no longer want.':
    'どのプルリクエストでも、好みを覚えるよう依頼すると学習として保存され、そのリポジトリでの以降のレビューと回答はそれに従います。コンソールではすべての学習と依頼者を確認でき、不要になった学習は削除できます。',
  'Choose how hard it looks': 'レビューの深さを選択',
  'Set a strength per repository. Basic reads the code with cheaper models, Normal runs commands in a sandbox, and Expert splits the work across subagents with stronger models. Each review costs credits from your plan, with no per-seat fees.':
    'リポジトリごとに強度を設定できます。ベーシックは低コストのモデルでコードを読み、ノーマルはサンドボックスでコマンドを実行し、エキスパートは高性能なモデルで作業をサブエージェントに分担します。各レビューではプランのクレジットを消費し、シート単位の料金はかかりません。',
  'Ready for your coding agents': 'コーディングエージェントにも対応',
  'Pull requests from coding agents get reviewed like anyone else’s. The summary and every finding include a prompt to hand to your agent, and Appwrite agent skills read the review, push fixes, and ask again until the pull request reaches Tier S.':
    'コーディングエージェントが作成したプルリクエストも、ほかの人のプルリクエストと同じようにレビューされます。概要とすべての指摘には、エージェントに渡すためのプロンプトが含まれます。Appwrite の Agent Skills を使えば、エージェントがレビューを読んで修正をプッシュし、プルリクエストがティア S に達するまで再レビューを依頼します。',
  'See what it catches': 'どんな問題を見つけたかを確認',
  'The Performance tab shows pull requests reviewed, how many were approved and how many on the first try, findings per pull request, how often findings were fixed rather than dismissed, and the median review time, by author, repository, and category.':
    '「パフォーマンス」タブには、レビューしたプルリクエスト、承認された数と初回で承認された数、プルリクエストあたりの指摘数、指摘が却下されずに修正された割合、レビュー時間の中央値が、作成者、リポジトリ、カテゴリ別に表示されます。',
  'From pull request to production, on one platform':
    'プルリクエストから本番まで、1 つのプラットフォームで',
  'Reviews is part of the project that builds, hosts, and watches your app. The pull request it reviews deploys to Sites and Functions, Monitors watch what you merged, and Beep pages your phone when something needs you, all in one Console and on one bill.':
    'コードレビューは、アプリをビルド、ホスティング、監視するプロジェクトの一部です。レビューしたプルリクエストはサイトと Functions にデプロイされ、マージした内容は Monitors が監視し、対応が必要なときは Beep がスマートフォンに知らせます。すべてが 1 つのコンソールと 1 つの請求にまとまっています。',
  'Every finding includes a prompt to fix it with AI':
    'すべての指摘に AI で修正するためのプロンプト付き',
  'Get acme/storefront#482 to Tier S':
    'acme/storefront#482 をティア S にしてください',
  'Fix: a wrong password retries forever':
    '修正: パスワードを間違えると無限にリトライする問題',
  'Edit src/auth/retry.ts: stop on 401, cap at 5 attempts':
    'src/auth/retry.ts を編集: 401 で停止し、試行を 5 回までに制限',
  'git push, then comment @appwrite review':
    'git push してから @appwrite review とコメント',
  Gitea: 'Gitea',
  'on src/mail/send.ts': 'src/mail/send.ts にコメント',
  'Errors from sendEmail are swallowed':
    'sendEmail のエラーが握りつぶされています',
  'just now': 'たった今',
  'this is fine, the queue retries failed sends.':
    'これは問題ありません。失敗した送信はキューがリトライします。',
  'Confirmed in the mail queue: failed sends retry three times, then land in the dead-letter queue. Dismissing this finding.':
    'メールキューで確認しました。失敗した送信は 3 回リトライされ、その後デッドレターキューに入ります。この指摘は却下します。',
  'Answers use no credits': '回答ではクレジットを消費しません',
  'Reviews use your organization’s review credits.':
    'レビューには組織のレビュークレジットが使われます。',
  'Suggested change': '変更の提案',
  'Prompt to fix with AI': 'AI で修正するためのプロンプト',
  'Reply if this doesn’t apply.': '当てはまらない場合は返信してください。',
  'Up to 15 inline findings per review':
    'レビューごとに最大 15 件のインライン指摘',
  'on #491': '#491 にコメント',
  'remember: we use early returns instead of nested ifs.':
    '覚えておいてください: ネストした if ではなく早期リターンを使います。',
  'Saved. Later reviews and answers in this repository will follow it.':
    '保存しました。このリポジトリでの以降のレビューと回答はこれに従います。',
  'Use early returns instead of nested ifs.':
    'ネストした if ではなく早期リターンを使う。',
  'Never log tokens, even hashed ones.':
    'トークンはハッシュ化したものでもログに出力しない。',
  'Read rows with Query.select, not whole documents.':
    'ドキュメント全体ではなく、Query.select で行を読み取る。',
  'A learning can tell the reviewer to flag fewer of them.':
    '学習で、この種の指摘を控えるようレビュアーに伝えられます。',
  'One Git connection': '1 つの Git 接続',
  'The Appwrite Git app that deploys your Sites and Functions reviews their pull requests too.':
    'サイトと Functions をデプロイする Appwrite の Git アプリが、それらのプルリクエストのレビューも行います。',
  'Appwrite Reviews': 'Appwrite Reviews',
  'A summary, findings on the lines they concern, a verdict, and a grade on every pull request.':
    'すべてのプルリクエストに、概要、該当する行への指摘事項、判定、グレード。',
  'Sites and Functions': 'サイトと Functions',
  'A preview for the pull request, then production on merge, with rollbacks one click away.':
    'プルリクエストごとにプレビューを作成し、マージすると本番に反映されます。ロールバックもワンクリックです。',
  'Usage thresholds and endpoint checks tell you when what you merged misbehaves.':
    'マージした変更が問題を起こすと、使用量のしきい値とエンドポイントチェックが知らせます。',
  'Appwrite Beep': 'Appwrite Beep',
  'The alert reaches your phone, and the next person in line if nobody acknowledges it.':
    'アラートがスマートフォンに届き、誰も確認済みにしなければ次の担当者にも届きます。',
  edited: '編集済み',
  'Mergeable after minor fixes': '軽微な修正後にマージ可能',
  'Sign-in now stops on a wrong password and caps retries at five. One minor finding is left: the backoff ignores the Retry-After header.':
    'サインインは、パスワードが間違っている場合に停止し、リトライを 5 回までに制限するようになりました。重要度が低い指摘が 1 件残っています。バックオフが Retry-After ヘッダーを無視しています。',
  'Latest changes': '最新の変更',
  'Stops retrying on 401 responses and adds a limit of five attempts.':
    '401 レスポンスでリトライを停止し、試行回数を 5 回までに制限します。',
  'To re-run it, comment:': '再実行するには、次のようにコメントしてください:',
  'Merge confidence': 'マージの信頼度',
  'Fix with agent prompt': 'エージェント向けの修正プロンプト',
  Walkthrough: 'ウォークスルー',
  'Fixed since the last review': '前回のレビュー以降に修正済み',
  'Incremental review': '差分レビュー',
  'A second model checks every finding against the code before anything is posted.':
    '投稿する前に、2 つめのモデルがすべての指摘をコードと照合して検証します。',
  'The diff and the repository': '差分とリポジトリ',
  'Read at the pull request’s head': 'プルリクエストのヘッド時点で読み込み',
  'Your team’s guidelines': 'チームのガイドライン',
  'AGENTS.md, CLAUDE.md, CONTRIBUTING.md':
    'AGENTS.md、CLAUDE.md、CONTRIBUTING.md',
  'Linked issues': 'リンクされた Issue',
  'What the change is for': '変更の目的',
  'Failed checks': '失敗したチェック',
  'From your other CI apps': 'ほかの CI アプリから',
  'Dependency changes': '依存関係の変更',
  'Versions bumped in the lockfile': 'ロックファイルで更新されたバージョン',
  'Rules your team asked it to keep': 'チームが守るよう依頼したルール',
  'Kept 3 of 5 findings': '指摘 5 件中 3 件を保持',
  Posted: '投稿済み',
  '3 comments and a summary': 'コメント 3 件と概要',
  '2 min ago': '2 分前',
  '1 min ago': '1 分前',
  'Needs changes': '変更が必要',
  'Adds exponential backoff to sign-in retries. One finding: a wrong password retries forever.':
    'サインインのリトライに指数バックオフを追加します。指摘が 1 件あります。パスワードを間違えると無限にリトライします。',
  'One Git app deploys and reviews': '1 つの Git アプリでデプロイもレビューも',
  // Monitors product page
  'Monitors: usage alerts and endpoint checks for your Appwrite project':
    'Monitors: Appwrite プロジェクトの使用量アラートとエンドポイントチェック',
  'Appwrite Monitors watch your project usage and your endpoints and alert you by email, in the Console, and on your phone when a metric crosses your threshold or a check fails. Escalate, acknowledge, and act on alerts with webhooks, functions, and MCP.':
    'Appwrite Monitors は、プロジェクトの使用量とエンドポイントを監視し、メトリクスがしきい値を超えたときやチェックが失敗したときに、メール、コンソール、スマートフォンでアラートを届けます。アラートをエスカレーションしたり確認済みにしたりできるほか、Webhook、関数、MCP からアラートに対処することもできます。',
  'Know when your project needs you':
    'プロジェクトに対応が必要なとき、すぐにわかる',
  'Appwrite Monitors watch your project usage and your endpoints, and tell you the moment a metric crosses your threshold or a check fails, by email, in the Console, and on your phone with Appwrite Beep.':
    'Appwrite Monitors は、プロジェクトの使用量とエンドポイントを監視し、メトリクスがしきい値を超えた瞬間やチェックが失敗した瞬間に、メール、コンソール、そして Appwrite Beep でスマートフォンにお知らせします。',
  'Usage metrics across every service': 'すべてのサービスの使用量メトリクス',
  'HTTP, DNS, TCP, and SMTP endpoints': 'HTTP、DNS、TCP、SMTP のエンドポイント',
  'Between usage checks on Pro': 'Pro での使用量チェックの間隔',
  'Until someone acknowledges': '誰かが確認済みにするまで',
  'Ask your agent what is firing': 'エージェントに発生中のアラートを聞く',
  'What can Appwrite Monitors watch?':
    'Appwrite Monitors では何を監視できますか?',
  'Usage monitors compare one project metric with a threshold you set, such as outbound bandwidth, executions, database reads, storage, realtime connections, or webhook failures, for the whole project or a single resource. On Appwrite Cloud, endpoint checks also test an HTTP URL, a DNS record, a TCP port, or a mail server, and budget alerts watch your organization’s spend.':
    '使用量モニターは、送信帯域幅、実行、データベース読み取り、ストレージ、Realtime の接続数、Webhook の失敗など、プロジェクトのメトリクス 1 つを設定したしきい値と比較します。対象はプロジェクト全体でも、1 つのリソースでもかまいません。Appwrite Cloud では、エンドポイントチェックで HTTP URL、DNS レコード、TCP ポート、メールサーバーもテストでき、予算アラートで組織の支出も監視できます。',
  'How are thresholds set?': 'しきい値はどのように設定しますか?',
  'A threshold is a value in the metric’s own unit, like 5 GB of outbound bandwidth or a million database reads, counted over a window from 5 minutes to 30 days or compared with the latest reading. The Console suggests one from your recent peak and shows how often it would have fired before you save.':
    'しきい値は、送信帯域幅 5 GB やデータベース読み取り 100 万回のように、メトリクス自体の単位で表した値です。5 分から 30 日までの期間で集計するか、最新の値と比較します。コンソールは最近のピークからしきい値を提案し、保存する前に、そのしきい値でどれくらいアラートが発生していたかを表示します。',
  'Who gets notified when a monitor fires?':
    'モニターでアラートが発生したとき、誰に通知されますか?',
  'The organization roles a monitor notifies, Owners by default, by email, in the Console, and with a push on every phone signed in to Appwrite Beep. With an escalation, each step notifies its roles and people in turn until someone acknowledges the alert or it resolves. Members hear about an alert once when it fires and once when it resolves.':
    'モニターが通知する組織のロール (デフォルトはオーナー) のメンバーに、メール、コンソール、そして Appwrite Beep にサインインしているすべてのスマートフォンへのプッシュ通知で届きます。エスカレーションを設定している場合は、誰かがアラートを確認済みにするか、アラートが解決するまで、各ステップのロールとメンバーに順番に通知します。メンバーへの通知は、アラートの発生時と解決時に 1 回ずつです。',
  'What happens when usage recovers?': '使用量が回復するとどうなりますか?',
  'The alert resolves on its own when the condition stops holding or the next check passes, and everyone who was told it fired gets a resolved notice. Resolved alerts stay in the Alerts tab with how long they fired and how far past the threshold they went.':
    '条件が成立しなくなるか、次のチェックが成功すると、アラートは自動的に解決し、発生の通知を受けた全員に解決の通知が届きます。解決済みのアラートは、発生していた時間と、しきい値をどれだけ超えたかとともに「アラート」タブに残ります。',
  'Can my code react to alerts?': 'コードでアラートに反応できますか?',
  'Yes. Every alert fires monitors.[monitorId].alerts.[alertId].create when it opens and an update event when it is acknowledged or resolves. Deliver them to webhooks and function triggers, or start from the Alerts to chat or Pause on usage spike function templates.':
    'はい。すべてのアラートは、発生時に monitors.[monitorId].alerts.[alertId].create を、確認済みになったときや解決したときに更新イベントを発生させます。これらを Webhook や関数のトリガーに配信することも、Alerts to chat や Pause on usage spike の関数テンプレートから始めることもできます。',
  'Which plans include Monitors?': 'Monitors はどのプランに含まれますか?',
  'Free includes 3 usage monitors per project, checked every 15 minutes. Pro includes 25 usage monitors per project checked every minute, budget alerts, and 10 endpoint checks for your organization, with more at $2 a month each. Scale raises that to 100 usage monitors per project, 50 endpoint checks, and checks as often as every 30 seconds.':
    'Free には、15 分ごとにチェックされる使用量モニターがプロジェクトあたり 3 件含まれます。Pro には、1 分ごとにチェックされる使用量モニターがプロジェクトあたり 25 件、予算アラート、組織あたり 10 件のエンドポイントチェックが含まれ、追加のチェックは 1 件あたり月額 $2 です。Scale では、使用量モニターがプロジェクトあたり 100 件、エンドポイントチェックが 50 件に増え、最短 30 秒ごとにチェックできます。',
  'Do Monitors work on self-hosted Appwrite?':
    'Monitors はセルフホストの Appwrite でも使えますか?',
  'Yes, for usage monitors, which self-hosted Appwrite evaluates every minute with the same API, SDKs, and project events. Endpoint checks, budget alerts, and pushes to Appwrite Beep run on Appwrite Cloud.':
    'はい、使用量モニターは使えます。セルフホストの Appwrite は、同じ API、SDK、プロジェクトのイベントで使用量モニターを 1 分ごとに評価します。エンドポイントチェック、予算アラート、Appwrite Beep へのプッシュ通知は Appwrite Cloud で動作します。',
  'Know before your users do': 'ユーザーより先に気づく',
  'Start from a monitor suggested by your own usage, and get the alert by email, in the Console, and on your phone.':
    '実際の使用量から提案されたモニターから始めて、メール、コンソール、スマートフォンでアラートを受け取りましょう。',
  'Start from your own usage': '実際の使用量から始める',
  'Monitors suggests its first monitors from your last 7 days of usage: an egress spike, a surge in database reads, storage growth, and failing webhooks, each with a threshold set from what your project really does. Pick one and the form opens filled in.':
    'Monitors は、過去 7 日間の使用量から最初のモニターを提案します。送信トラフィックの急増、データベース読み取りの急増、ストレージの増加、失敗する Webhook の 4 つで、それぞれプロジェクトの実際の使用状況に基づいたしきい値が設定されています。1 つを選ぶと、入力済みのフォームが開きます。',
  'Drag the line, see what would have fired':
    'ラインをドラッグして、発生していたはずのアラートを確認',
  'Pick a metric and the Console charts its real history behind a dashed threshold. Drag the line, and before you save it tells you how often the monitor would have fired over the last 12 hours to 30 days, with every breach shaded red.':
    'メトリクスを選ぶと、コンソールが実際の履歴を破線のしきい値と重ねてグラフ表示します。ラインをドラッグすれば、保存する前に、過去 12 時間から 30 日間でモニターのアラートが何回発生していたかがわかり、しきい値を超えた箇所はすべて赤く表示されます。',
  'Watch any number your project produces': 'プロジェクトのあらゆる数値を監視',
  'Requests, bandwidth, executions, realtime connections, active users, database reads and writes, storage, messages, webhooks, and more. Watch the whole project or a single database, bucket, function, or site, counted over 5 minutes to 30 days.':
    'リクエスト、帯域幅、実行、Realtime の接続数、アクティブユーザー、データベースの読み取りと書き込み、ストレージ、メッセージ、Webhook など。プロジェクト全体、または 1 つのデータベース、バケット、関数、サイトを対象に、5 分から 30 日までの期間で集計して監視できます。',
  'Know when an endpoint stops answering':
    'エンドポイントが応答しなくなったらすぐにわかる',
  'On Appwrite Cloud, check an HTTP URL, a DNS record, a TCP port, or a mail server on the interval you choose. An HTTP check fails on any status outside 2xx, or when its certificate is about to expire, and you set the codes and the warning window.':
    'Appwrite Cloud では、HTTP URL、DNS レコード、TCP ポート、メールサーバーを、選んだ間隔でチェックできます。HTTP チェックは、ステータスが 2xx 以外のときや証明書の有効期限が近づいたときに失敗します。想定するステータスコードと警告期間は自分で設定できます。',
  'Every alert says how bad it is': 'すべてのアラートで深刻度がわかる',
  'An alert keeps the latest reading, its peak, and how far past the threshold it went. Open it for the timeline from the first firing to recovery, and acknowledge it so your team knows you have it. It resolves on its own when usage recovers or the next check passes.':
    'アラートには、最新の値、ピーク、しきい値をどれだけ超えたかが記録されます。開くと最初の発生から回復までのタイムラインを確認でき、確認済みにすれば対応中であることがチームに伝わります。使用量が回復するか、次のチェックが成功すると、自動的に解決します。',
  'Escalate until someone answers': '誰かが応答するまでエスカレーション',
  'Build an escalation once for your organization and pick it on any monitor. Each step notifies roles or named people, right away or after a wait, and repeats if nobody responds. It stops as soon as someone acknowledges the alert or it resolves.':
    '組織でエスカレーションを一度作成すれば、どのモニターでも選択できます。各ステップでは、ロールまたは指定したメンバーに、すぐに、または待機時間の後に通知し、誰も応答しなければ繰り返します。誰かがアラートを確認済みにするか、アラートが解決した時点で停止します。',
  'Email, the Console, and your phone': 'メール、コンソール、スマートフォン',
  'The people a monitor notifies get an email with a button to pause it, a notification in the Console, and a push in Appwrite Beep with Acknowledge, Snooze, and Mute. Monitors set to Critical ring Android phones through silent mode.':
    'モニターの通知先のメンバーには、モニターを一時停止するボタン付きのメール、コンソールの通知、そして「確認済みにする」「スヌーズ」「ミュート」を操作できる Appwrite Beep のプッシュ通知が届きます。「重大」に設定したモニターは、サイレントモードの Android スマートフォンでも鳴ります。',
  'Alerts your code can act on': 'コードで対応できるアラート',
  'Every alert fires a project event when it opens and again when it is acknowledged or resolves. Send it to a webhook, trigger a function, or start from a template that posts to Slack, Discord, Microsoft Teams, or Google Chat, or pauses a function or site on a usage spike.':
    'すべてのアラートは、発生時と、確認済みになったときや解決したときに、プロジェクトのイベントを発生させます。イベントを Webhook に送信したり、関数をトリガーしたりできるほか、Slack、Discord、Microsoft Teams、Google Chat に投稿するテンプレートや、使用量が急増したときに関数やサイトを一時停止するテンプレートから始めることもできます。',
  'Connect the Appwrite MCP server to Claude, Cursor, VS Code, or any MCP client. Your agent can create usage monitors and endpoint checks, list the alerts that are firing, and acknowledge them, and the same works from the server SDKs and the CLI.':
    'Appwrite MCP サーバーを Claude、Cursor、VS Code、または任意の MCP クライアントに接続しましょう。エージェントが使用量モニターやエンドポイントチェックを作成し、発生中のアラートを一覧表示して確認済みにできます。同じ操作はサーバー SDK や CLI からも行えます。',
  'Budget alerts before the bill': '請求の前に届く予算アラート',
  'Set budget alerts in your organization’s billing settings and Monitors watches how much of your budget cap this cycle has used, with up to four thresholds and the cap itself at 100%. They reach you by email and on your phone.':
    '組織の請求設定で予算アラートを設定すると、今期に予算上限のどれだけを使ったかを Monitors が監視します。しきい値は最大 4 つまで設定でき、100% は上限そのものです。アラートはメールとスマートフォンに届きます。',
  'Appwrite MCP · OAuth, no API keys':
    'Appwrite MCP · OAuth で接続、API キー不要',
  'Is anything firing on storefront-prod? If it is bandwidth, acknowledge it for me.':
    'storefront-prod で発生中のアラートはありますか? 帯域幅のアラートなら確認済みにしてください。',
  'One alert is firing: outbound bandwidth reached 6.2 GB in the last hour, 1.2× its 5 GB threshold, for 18 minutes.':
    '発生中のアラートが 1 件あります。直近 1 時間の送信帯域幅が 6.2 GB に達し、しきい値 5 GB の 1.2× の状態が 18 分間続いています。',
  'Acknowledged by Ana Silva': 'Ana Silva が確認済み',
  'within 1 hour': '(1 時間以内)',
  '1.2× the threshold': 'しきい値の 1.2×',
  '1.4× the threshold': 'しきい値の 1.4×',
  'Email, push, and Console': 'メール、プッシュ通知、コンソール',
  'Budget used this cycle': '今期の予算使用率',
  Cap: '上限',
  'Over 75% of budget used · Acme Inc':
    '予算の使用率が 75% を超えました · Acme Inc',
  'Email to Owners': 'オーナー宛てのメール',
  'Update budget cap': '予算上限を更新',
  'HTTP check': 'HTTP チェック',
  '14 days': '14 日',
  '1 min': '1 分',
  '10 s': '10 秒',
  'Appwrite Cloud, Pro and Scale': 'Appwrite Cloud の Pro と Scale',
  Storefront: 'ストアフロント',
  'Storefront DNS': 'ストアフロントの DNS',
  Queue: 'キュー',
  'Mail server': 'メールサーバー',
  'On call': 'オンコール',
  '2 times': '2 回',
  'Starts again from the first step, a monitor’s cooldown after the last one.':
    '最後のステップからモニターのクールダウン後に、最初のステップから再開します。',
  'Developers now → Ana, Ben after 10 m · repeats 2×':
    '開発者 (今すぐ) → Ana、Ben (10 分後) · 2 回繰り返し',
  'Owners now': 'オーナー (今すぐ)',
  'Ana Silva': 'Ana Silva',
  'Ben Okafor': 'Ben Okafor',
  'Project events': 'プロジェクトのイベント',
  'Function template': '関数テンプレート',
  'Alerts to chat': 'Alerts to chat',
  'Post alerts to Slack, Discord, Microsoft Teams, or Google Chat.':
    'アラートを Slack、Discord、Microsoft Teams、Google Chat に投稿します。',
  'Pause on usage spike': 'Pause on usage spike',
  'Turn off a function or site when its monitor fires.':
    'モニターでアラートが発生したときに、関数やサイトを無効にします。',
  Cloud: 'Cloud',
  'Requests and bandwidth': 'リクエストと帯域幅',
  'Outbound bandwidth is above 5 GB': '送信帯域幅が 5 GB を超えています',
  'Outbound bandwidth reached 6.2 GB in the last hour, 1.2× the threshold.':
    '直近 1 時間の送信帯域幅が 6.2 GB に達しました。しきい値の 1.2× です。',
  'Summed over 1 hour': '1 時間の合計',
  'View alert': 'アラートを表示',
  'Pause this monitor': 'このモニターを一時停止',
  'Console notification': 'コンソール通知',
  'Twice your peak, rounded up': 'ピークの 2 倍を切り上げた値',
  'HTTP monitor': 'HTTP モニター',
  'DNS monitor': 'DNS モニター',
  'TCP monitor': 'TCP モニター',
  'SMTP monitor': 'SMTP モニター',
  'Would have fired 2× in the last 24 hours':
    '過去 24 時間に 2 回発生していました',
  'Fires when': '発生条件',
  'Resolves on its own when usage recovers': '使用量が回復すると自動的に解決',
  '[Firing] Egress spike · storefront-prod':
    '[発生中] 送信トラフィックの急増 · storefront-prod',
  'Egress spike is firing': '送信トラフィックの急増が発生中',
  // Monitors, Beep, and Reviews art: strings passed to t() from fields the coverage check does not scan
  '4 min ago': '4 分前',
  '12 min ago': '12 分前',
  '18 min ago': '18 分前',
  '12m ago': '12 分前',
  '18m ago': '18 分前',
  '2h ago': '2 時間前',
  Frankfurt: 'フランクフルト',
  'Unexpected status code · HTTP 503': '想定外のステータスコード · HTTP 503',
  'Right away': '今すぐ',
  'After 10 min': '10 分後',
  'After 30 min': '30 分後',
  'Notified at 9:37': '9:37 に通知済み',
  'Next, in 6 min': '次は 6 分後',
  '3 firing · 2 acknowledged': '発生中 3 件 · 確認済み 2 件',
  'Projects in Acme Inc': 'Acme Inc のプロジェクト',
  'These settings belong to your console account and apply on all your devices. Emails are unaffected.':
    'これらの設定はコンソールのアカウントに保存され、すべてのデバイスに適用されます。メールには影響しません。',
  '4 hours': '4 時間',
  'Nov 19, 2026': '2026年11月19日',
  'Nov 17, 2026': '2026年11月17日',
  'The project, a function, or a site': 'プロジェクト、関数、またはサイト',
  'The project or one database': 'プロジェクトまたは 1 つのデータベース',
  'The project, a bucket, a database, a function, or a site':
    'プロジェクト、バケット、データベース、関数、またはサイト',
  'Read and write IOPS': '読み取り・書き込み IOPS',
  'One dedicated database': '1 つの専用データベース',
  'Analytics events': 'アナリティクスのイベント',
  'Analytics pageviews': 'アナリティクスのページビュー',
  'The whole project, bot traffic excluded':
    'プロジェクト全体 (ボットのトラフィックを除く)',
  'Budget used': '予算使用率',
  'Your organization, from billing settings': '組織 (請求設定で設定)',
  '5 min': '5 分',
  '15 min': '15 分',
  'After 10 minutes': '10 分後',
  'Used by 4 monitors': '4 件のモニターで使用中',
  'Used by 1 monitor': '1 件のモニターで使用中',
  'Peak hour 2.4 GB': '1 時間あたりの最大 2.4 GB',
  'Peak hour 380K': '1 時間あたりの最大 380K',
  'Now 18 GB': '現在 18 GB',
  'Peak hour 3': '1 時間あたりの最大 3',
  'Ready to merge': 'マージ準備完了',
  'Needs changes before merging': 'マージ前に変更が必要',
  'Significant problems': '大きな問題あり',
  'Serious problems': '深刻な問題あり',
  'Do not merge': 'マージ不可',
  '4 files': '4 ファイル',
  '412 reviews': '412 件のレビュー',
  '48% first try': '初回で48%',
  '6 critical': '重大 6 件',
  'Fixed, not dismissed': '却下されずに修正',
  Respond: '対応',
  'One organization bill': '組織の請求は 1 つ',
  'Same team and roles': 'チームとロールも同じ',
  'No per-seat fees': 'シート単位の料金なし',
  'Retry sign-in with backoff': 'バックオフ付きでサインインをリトライ',
  'A wrong password retries forever':
    'パスワードを間違えると無限にリトライする',
  'The loop has no exit when signIn rejects with invalid credentials. Stop on a 401 and cap the attempts.':
    'signIn が無効な認証情報で拒否されたとき、ループを抜ける条件がありません。401 で停止し、試行回数に上限を設けてください。',
  'Token is logged before it is hashed':
    'トークンがハッシュ化される前にログに出力されています',
  'The raw session token reaches your logs on every sign-in. Log the session ID instead.':
    'サインインのたびに、生のセッショントークンがログに残ります。代わりにセッション ID をログに出力してください。',
  '10 credits per review': 'レビューごとに 10 クレジット',
  '50 credits per review': 'レビューごとに 50 クレジット',
  '500 credits per review': 'レビューごとに 500 クレジット',
  // Monitors, Beep, and Reviews: hero stat values and the Observe stage label
  Observe: '監視',
  'Every org': 'すべての組織',
  'From the push': 'プッシュ通知から',
  'Every PR': 'すべての PR',
  'S to F': 'S〜F',
  'No seats': 'シート課金なし',
  'On by default': 'デフォルトで有効',
  '4 checks': '4 種類のチェック',
  'Free of charge': '無料',
  'Stop on a 401 and cap the attempts.': '401 が返ったら停止し、試行回数に上限を設けてください。',
}
