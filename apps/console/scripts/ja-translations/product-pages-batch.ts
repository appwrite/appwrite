export const jaProductPagesBatch: Record<string, string> = {
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
  'Connect a Git repository and ship on every push. Commits to your production branch build and auto-activate on your primary domain; other branches get preview links for org members to review before merge.':
    'Git リポジトリを接続すれば、push するたびにリリースできます。本番ブランチへのコミットはビルドされ、メインドメインで自動的に有効化されます。それ以外のブランチには、マージ前に組織のメンバーがレビューできるプレビューリンクが発行されます。',
  'Connect a repository and deploy on every push, the same workflow as Sites. Set a production branch that auto-activates successful builds, filter by branch or path with glob patterns, and review preview deployments from pull requests.':
    'リポジトリを接続すれば、サイトと同じワークフローで push のたびにデプロイできます。成功したビルドを自動的に有効化する本番ブランチを設定し、glob パターンでブランチやパスによる絞り込みを行い、プルリクエストからのプレビューデプロイを確認できます。',
  'Connect a repository in the Console, set a production branch and root directory, then deploy on every push. Branch and path filters use glob patterns, matching the Sites Git workflow.':
    'コンソールでリポジトリを接続し、本番ブランチとルートディレクトリを設定すれば、push のたびにデプロイされます。ブランチとパスのフィルターには、サイトの Git ワークフローと同じ glob パターンを使用します。',
  'Connect the email, SMS, and push providers you already use. Add credentials once in the Console, pick a vendor per channel, and route every message through the stack you operate.':
    'すでに利用しているメール、SMS、push のプロバイダーを接続できます。コンソールで認証情報を一度追加し、チャネルごとにベンダーを選び、自分たちで運用するスタックですべてのメッセージを配信できます。',
  'Create a team for each customer, organization, or workspace in your app. Invite members by email, assign roles, and scope databases, storage buckets, functions, and other resources with team-based permissions. Teams give you tenant isolation without building custom RBAC, and membership privacy settings let you control whether member lists are visible to other users.':
    'アプリ内の顧客、組織、ワークスペースごとにチームを作成できます。メールでメンバーを招待し、ロールを割り当て、チーム単位の権限でデータベース、ストレージバケット、関数などのリソースを制御できます。チームを使えば、独自の RBAC を構築せずにテナント間の分離を実現でき、メンバーシップのプライバシー設定でメンバー一覧を他のユーザーに公開するかどうかも制御できます。',
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
    'メンバーシップ、招待、ロールを持つチームとして、顧客やワークスペースをそれぞれモデル化できます。独自の RBAC を構築することなく、コンソールの Users タブと Teams タブから、データベースやストレージなどのリソースを適切なテナントに限定できます。',
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
  'Start from official quick-starts or pick a template in the create wizard. Filter by framework and use case, connect GitHub, and deploy with build settings already tuned for Appwrite.':
    '公式のクイックスタートから始めるか、作成ウィザードでテンプレートを選べます。フレームワークとユースケースで絞り込み、GitHub を接続し、Appwrite 向けにあらかじめ調整されたビルド設定でデプロイできます。',
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
  'Yes. Browse templates from Sites > Templates in the Console and filter by framework or use case. The create wizard walks you through GitHub setup, production branch, environment variables, and domain configuration. Official quick-starts cover Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, and more.':
    'はい。コンソールの サイト > Templates からテンプレートを閲覧し、フレームワークやユースケースで絞り込めます。作成ウィザードでは、GitHub の設定、本番ブランチ、環境変数、ドメインの設定を順を追って行えます。公式のクイックスタートは、Next.js、Nuxt、SvelteKit、Astro、Vue、TanStack Start などに対応しています。',
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
  'Yes. Turn on encryption per bucket from Settings so new files are stored encrypted at rest. If files are leaked, encrypted objects cannot be read without your keys. Files larger than 20 MB skip encryption even when enabled.':
    'はい。Settings からバケットごとに暗号化を有効にすると、新しいファイルは保存時に暗号化された状態で保存されます。万が一ファイルが流出しても、暗号化されたオブジェクトは鍵がなければ読み取れません。20 MB を超えるファイルは、有効にしていても暗号化の対象外です。',
  'Yes. Usage charts show requests, bandwidth, builds, and compute over selectable ranges, with breakdowns to see where traffic comes from. The Logs tab records every request with status code, method, path, and duration. Open a log entry for request and response headers. For SSR sites, console.log and console.error output appears in response logs.':
    'はい。使用状況のグラフでは、選択した期間ごとにリクエスト、帯域幅、ビルド、コンピュートを確認でき、トラフィックの発生元も内訳で確認できます。Logs タブには、ステータスコード、メソッド、パス、所要時間とともにすべてのリクエストが記録されます。ログのエントリを開けば、リクエストとレスポンスのヘッダーを確認できます。SSR サイトでは、console.log と console.error の出力がレスポンスログに表示されます。',
  'Yes. Use the Server SDK from Functions, your API server, or any trusted backend with a project API key. This is the standard pattern for transactional flows such as OTP verification, password reset, order receipts, and inventory alerts triggered by platform events or custom logic.':
    'はい。Functions、自前の API サーバー、またはプロジェクトの API キーを持つ信頼できる任意のバックエンドから Server SDK を利用できます。これは、プラットフォームのイベントや独自のロジックによってトリガーされる OTP 認証、パスワードリセット、注文の受領確認、在庫アラートなどのトランザクションフローに使われる標準的なパターンです。',
}
