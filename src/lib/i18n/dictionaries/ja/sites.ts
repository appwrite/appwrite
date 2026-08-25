/**
 * Japanese translations for sites.
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaSitesDictionary: Record<string, string> = {
  // Placeholder pages
  'Add Domain': 'ドメインの追加',
  'Add a custom domain to your site': 'サイトにカスタムドメインを追加',
  'Sites Usage': 'サイトの使用量',
  'View usage statistics for this site': 'このサイトの使用統計を表示',
  'Verify Domain': 'ドメインの検証',
  'Search sites...': 'サイトを検索...',
  'Create site': 'サイトの作成',
  "You don't have permission to create sites.":
    'サイトを作成する権限がありません。',
  'Loading sites...': 'サイトを読み込み中...',
  'Last deployed': '最終デプロイ',
  'Unnamed Site': '名前未設定のサイト',
  'No sites yet': 'サイトがまだありません',
  'Preview not available': 'プレビューは利用できません',
  'Delete Sites': 'サイトの削除',
  'Are you sure you want to delete': '次を削除してもよろしいですか',
  'This action cannot be undone.': 'この操作は元に戻せません。',
  'Site deleted successfully': 'サイトを削除しました',
  'Successfully deleted': '削除しました',
  'Failed to delete sites': 'サイトの削除に失敗しました',
  // Deployments
  'Loading deployments...': 'デプロイを読み込み中...',
  'Download started': 'ダウンロードを開始しました',
  'Failed to download source code': 'ソースコードのダウンロードに失敗しました',
  'Failed to download build output': 'ビルド出力のダウンロードに失敗しました',
  'Deployment rebuild started': 'デプロイの再ビルドを開始しました',
  'Failed to redeploy': '再デプロイに失敗しました',
  'Settings changes are not live yet': '設定の変更はまだ反映されていません',
  "You've updated site settings, but they won't take effect until you redeploy. The current deployment is still running with the previous settings.":
    'サイトの設定を更新しましたが、再デプロイするまで反映されません。現在のデプロイは、以前の設定のまま実行され続けています。',
  'Deployment activated successfully': 'デプロイを有効化しました',
  'Failed to activate deployment': 'デプロイの有効化に失敗しました',
  'Build cancelled': 'ビルドをキャンセルしました',
  'Failed to cancel build': 'ビルドのキャンセルに失敗しました',
  'Deployment deleted successfully': 'デプロイを削除しました',
  'Failed to delete deployment': 'デプロイの削除に失敗しました',
  'Failed to delete deployments': 'デプロイの削除に失敗しました',
  'Active deployment': 'アクティブなデプロイ',
  'Cancel build': 'ビルドのキャンセル',
  'Deployment screenshot': 'デプロイのスクリーンショット',
  'Light screenshot': 'ライトモードのスクリーンショット',
  'Dark screenshot': 'ダークモードのスクリーンショット',
  'Build duration': 'ビルド時間',
  'Total size': '合計サイズ',
  'Global CDN': 'グローバル CDN',
  'Content Delivery Network': 'コンテンツ配信ネットワーク（CDN）',
  'Learn more →': '詳細を見る →',
  'DDoS protection': 'DDoS 保護',
  'DDoS Mitigation': 'DDoS 緩和',
  'View all domains': 'すべてのドメインを表示',
  'Add domain': 'ドメインの追加',
  'View all': 'すべて表示',
  'Source code': 'ソースコード',
  'Build output': 'ビルド出力',
  'Build logs': 'ビルドログ',
  'There is no active deployment': 'アクティブなデプロイがありません',
  'Deployment ID': 'デプロイ ID',
  'Total Size': '合計サイズ',
  'Build must be ready before activating':
    '有効化する前にビルドが完了している必要があります',
  'No deployments yet': 'デプロイがまだありません',
  'Delete Deployments': 'デプロイの削除',
  'Delete deployment': 'デプロイの削除',
  'Keep building': 'ビルドを続行',
  'Redeploy deployment': 'デプロイの再実行',
  'Activate deployment': 'デプロイの有効化',
  // Logs pages
  'No logs yet': 'ログがまだありません',
  'No executions yet': '実行がまだありません',
  // Domains pages
  'No domains yet': 'ドメインがまだありません',
  'Redirect to': 'リダイレクト先',
  'Deployed from': 'デプロイ元',
  'View logs': 'ログを表示',
  'Domain has been deleted': 'ドメインを削除しました',
  'Loading domains...': 'ドメインを読み込み中...',
  'Generating certificate': '証明書を発行中',
  'Verification failed': '検証に失敗しました',
  'Certificate generation failed': '証明書の発行に失敗しました',
  'Back to sites': 'サイト一覧に戻る',
  'Your site is currently being deployed.': 'サイトは現在デプロイ中です。',
  'Filters coming soon': 'フィルターは近日公開',
  'Loading settings...': '設定を読み込み中...',
  'Site name used for identification': '識別用のサイト名',
  'Enter site name': 'サイト名を入力',
  'Site name updated successfully': 'サイト名を更新しました',
  'Failed to update site name': 'サイト名の更新に失敗しました',
  'Site name is required': 'サイト名は必須です',
  // Settings: status card
  'Enable or disable this site without deleting it.':
    'このサイトを削除せずに有効化・無効化できます。',
  'Site has been enabled': 'サイトを有効化しました',
  'Site has been disabled': 'サイトを無効化しました',
  'Site is disabled': 'サイトは無効です',
  'This site is disabled and not accessible to visitors. Console actions remain available.':
    'このサイトは無効になっており、訪問者がアクセスすることはできません。コンソールからの操作は引き続き利用できます。',
  'Enable this site in the Settings tab': '設定タブでこのサイトを有効にしてください',
  'to make it available to visitors.': '訪問者が利用できるようにしてください。',
  // Settings: danger zone
  'Delete site': 'サイトの削除',
  'this site': 'このサイト',
  'Failed to delete site': 'サイトの削除に失敗しました',
  'No repository connected': 'リポジトリが接続されていません',
  'Connect repository': 'リポジトリの接続',
  'Last updated': '最終更新',
  'Open repository in new tab': '新しいタブでリポジトリを開く',
  'Disconnect repository': 'リポジトリの切断',
  'Are you sure you want to disconnect': '次を切断してもよろしいですか',
  'Branch Settings': 'Branch 設定',
  'Production branch': '本番 Branch',
  'Root directory': 'ルートディレクトリ',
  'Repository settings updated successfully': 'リポジトリ設定を更新しました',
  'Failed to update repository settings': 'リポジトリ設定の更新に失敗しました',
  'Repository connected successfully': 'リポジトリを接続しました',
  'Failed to connect repository': 'リポジトリの接続に失敗しました',
  'Repository disconnected successfully': 'リポジトリを切断しました',
  'Failed to disconnect repository': 'リポジトリの切断に失敗しました',
  'No changes to save': '保存する変更がありません',
  'Please select an installation and repository':
    'インストールとリポジトリを選択してください',
  // Settings: framework card
  'Learn more': '詳細を見る',
  'Server side rendering': 'サーバーサイドレンダリング',
  'Static site': '静的サイト',
  'Select framework': 'Framework を選択',
  'Output directory': '出力ディレクトリ',
  'Enter output directory': '出力ディレクトリを入力',
  'Fallback file': 'フォールバックファイル',
  'Select a framework to configure the fallback file':
    'フォールバックファイルを設定するには Framework を選択してください',
  'Framework settings updated successfully': 'Framework 設定を更新しました',
  'Failed to update framework settings': 'Framework 設定の更新に失敗しました',
  'Framework is required': 'Framework は必須です',
  'Install command': 'インストールコマンド',
  'Enter install command': 'インストールコマンドを入力',
  'Build command': 'ビルドコマンド',
  'Enter build command': 'ビルドコマンドを入力',
  'Build commands updated successfully': 'ビルドコマンドを更新しました',
  'Failed to update build commands': 'ビルドコマンドの更新に失敗しました',
  'Specification updated successfully': '仕様を更新しました',
  'Failed to update specification': '仕様の更新に失敗しました',
  'Select an image': 'Image を選択',
  'Runtime settings updated successfully': 'ランタイム設定を更新しました',
  'Failed to update runtime settings': 'ランタイム設定の更新に失敗しました',
  // Settings: start command card
  'Start command': '起動コマンド',
  'Enter start command': '起動コマンドを入力',
  'Start command updated successfully': '起動コマンドを更新しました',
  'Failed to update start command': '起動コマンドの更新に失敗しました',
  'Seconds per request': 'リクエストあたりの秒数',
  'Request timeout updated successfully':
    'リクエストタイムアウトを更新しました',
  'Failed to update request timeout':
    'リクエストタイムアウトの更新に失敗しました',
  'Full request logging': 'リクエストの完全ログ',
  'Logging updated successfully': 'ログ設定を更新しました',
  'Failed to update logging': 'ログ設定の更新に失敗しました',
  'Identifiers and timestamps for this site.':
    'このサイトの識別子とタイムスタンプ。',
  'Site ID:': 'サイト ID:',
  'Created:': '作成日:',
  'Last updated:': '最終更新:',
  // Settings: retention and triggers
  'Retention has been updated': '保持設定を更新しました',
  'Failed to update retention': '保持設定の更新に失敗しました',
  'Triggers updated.': 'トリガーを更新しました。',
  'Failed to update triggers': 'トリガーの更新に失敗しました',
  // Settings: silent mode card
  'Silent mode': 'サイレントモード',
  'Disable automated commit comments': '自動コミットコメントを無効化',
  // Settings: variables card
  'Environment variables': '環境変数',
  // Overview extras
  'Loading site...': 'サイトを読み込み中...',
  'Recent deployments': '最近のデプロイ',
  'Deployments will appear here when available':
    'デプロイが利用可能になるとここに表示されます',
  // Start command label
  '(optional)': '（任意）',
  'About start command (optional)': '起動コマンドについて（任意）',
  'Copy ID': 'ID のコピー',
  'Copy name': '名前のコピー',
  'Copy link': 'リンクのコピー',
  'Copy as JSON': 'JSON としてコピー',
  'Open in new tab': '新しいタブで開く',
  'Open in new window': '新しいウィンドウで開く',
  'Site deleted': 'サイトを削除しました',
  'Verify domain': 'ドメインの検証',
  'Domain name': 'ドメイン名',
  'Domain verified': 'ドメインを検証しました',
  'Verifying...': '検証中...',
  'Verification in progress': '検証中',
  'Failed to verify domain': 'ドメインの検証に失敗しました',
  'Failed to remove domain': 'ドメインの削除に失敗しました',
  'Failed to add domain': 'ドメインの追加に失敗しました',
  'Failed to register domain': 'ドメインの登録に失敗しました',
  'Invalid URL': '無効な URL',
  'Enter URL': 'URL を入力',
  'Select branch': 'Branch を選択',
  'Connect repository first': '先にリポジトリを接続してください',
  // Template gallery
  'Search templates...': 'テンプレートを検索...',
  'No templates found': 'テンプレートが見つかりません',
  'No templates available': '利用可能なテンプレートがありません',
  'Search use cases...': 'ユースケースを検索...',
  'No use case found.': 'ユースケースが見つかりません。',
  'Search frameworks...': 'Framework を検索...',
  'No framework found.': 'Framework が見つかりません。',
  'All frameworks': 'すべての Framework',
  'All use cases': 'すべてのユースケース',
  'Dev tools': '開発ツール',
  // Create wizard: entry view
  'Import repository': 'リポジトリのインポート',
  'Connect Git provider': 'Git プロバイダーの接続',
  'Connect GitHub': 'GitHub の接続',
  'Connect GitLab': 'GitLab の接続',
  'Select organization': 'Organization を選択',
  'Add account': 'アカウントの追加',
  'Search...': '検索...',
  'No repositories found': 'リポジトリが見つかりません',
  'No repositories available': '利用可能なリポジトリがありません',
  "Can't find a repository?": 'リポジトリが見つかりませんか?',
  'Update GitHub permissions': 'GitHub 権限の更新',
  'Update GitLab permissions': 'GitLab 権限の更新',
  'Clone template': 'テンプレートのクローン',
  'Upload your website manually': 'Web サイトを手動でアップロード',
  // Create wizard: manual upload
  'Only .tar.gz files are supported': '.tar.gz ファイルのみサポートしています',
  'File size must be less than 100MB':
    'ファイルサイズは 100MB 未満である必要があります',
  'Please enter a valid domain': '有効なドメインを入力してください',
  'Failed to create site': 'サイトの作成に失敗しました',
  'Other options': 'その他のオプション',
  'Import from Git': 'Git からインポート',
  'Browse templates': 'テンプレートを閲覧',
  'Upload file': 'ファイルのアップロード',
  'Only .tar.gz files up to 100MB': '.tar.gz ファイルのみ、最大 100MB',
  'Site name': 'サイト名',
  'Site ID': 'サイト ID',
  'My awesome site': 'すばらしいサイト',
  'Auto-generated': '自動生成',
  // Create wizard: quick deploy and repository config
  'Please fill in all required fields': '必須項目をすべて入力してください',
  'GitHub Repository': 'GitHub リポジトリ',
  'GitLab Repository': 'GitLab リポジトリ',
  'View on GitHub': 'GitHub で表示',
  'View repository': 'リポジトリを表示',
  'Detecting framework...': 'Framework を検出中...',
  'Git configuration': 'Git 設定',
  'Ready to deploy': 'デプロイの準備完了',
  // Create wizard: template config
  'Template not found': 'テンプレートが見つかりません',
  'Please select a repository': 'リポジトリを選択してください',
  'Template is missing repository information':
    'テンプレートにリポジトリ情報がありません',
  'View source': 'ソースを表示',
  'Live demo': 'ライブデモ',
  'Connect your repository': 'リポジトリを接続',
  'Connect later': '後で接続',
  'Connect Git repository': 'Git リポジトリの接続',
  'Template variables': 'テンプレート変数',
  'Optional variables': '任意の変数',
  // Create wizard: deploying and finish
  'Deployment cancelled': 'デプロイをキャンセルしました',
  'Failed to cancel deployment': 'デプロイのキャンセルに失敗しました',
  'Cancel deployment': 'デプロイのキャンセル',
  'Go to dashboard': 'ダッシュボードへ',
  'Loading preview…': 'プレビューを読み込み中…',
  'Generating preview…': 'プレビューを生成中…',
  'Visit site': 'サイトを開く',
  'Next steps': '次のステップ',
  'Add repository': 'リポジトリの追加',
  'Connect Git for automatic deployments': 'Git を接続して自動デプロイ',
  'Add custom domain': 'カスタムドメインの追加',
  'Use your own domain name': '独自のドメイン名を使用',
  'Configure your own domain name': '独自のドメイン名を設定',
  'Copy site URL': 'サイト URL のコピー',
  'Copy URL to clipboard': 'URL をクリップボードにコピー',
  'Copy the site URL to clipboard': 'サイト URL をクリップボードにコピー',
  'URL copied to clipboard': 'URL をクリップボードにコピーしました',
  'Open on mobile': 'モバイルで開く',
  'Scan QR code': 'QR コードをスキャン',
  'Scan QR code to view on your phone':
    'QR コードをスキャンしてスマートフォンで表示',
  'View on mobile': 'モバイルで表示',
  'QR code to open site on mobile': 'モバイルでサイトを開く QR コード',
  'Site URL is not available yet': 'サイト URL はまだ利用できません',
  'Deployment successful!': 'デプロイに成功しました!',
  'Your site is now live': 'サイトが公開されました',
  'View aggregated usage statistics across all sites':
    'すべてのサイトにわたる集計使用統計を表示',
  'Configure DNS settings to verify your domain':
    'ドメインを検証するために DNS 設定を構成',
  'Create your first site to get started': '最初のサイトを作成して始めましょう',
  'Cannot delete the active deployment. Please activate another deployment first.':
    'アクティブなデプロイは削除できません。先に別のデプロイを有効化してください。',
  "Appwrite's CDN provides global coverage with 120+ points of presence worldwide, reducing latency through edge caching and content optimization. All content is delivered over TLS for secure, encrypted connections.":
    'Appwrite の CDN は世界 120 以上の PoP でグローバルにカバーし、エッジキャッシュとコンテンツ最適化でレイテンシを低減します。すべてのコンテンツは TLS 経由で安全に暗号化配信されます。',
  "Appwrite's network includes built-in DDoS mitigation to protect against distributed denial-of-service attacks, ensuring uninterrupted access to your sites and maintaining high availability even during high traffic loads.":
    'Appwrite のネットワークには DDoS 緩和が組み込まれており、分散型サービス拒否攻撃からサイトを保護し、高トラフィック時でも高可用性を維持します。',
  'Build output is available after the deployment has completed.':
    'ビルド出力はデプロイ完了後に利用できます。',
  'Create your first deployment to activate this site.':
    'このサイトを有効化するには最初のデプロイを作成してください。',
  'The active deployment cannot be deleted from the list':
    'アクティブなデプロイは一覧から削除できません',
  'Wait for the build to finish or cancel it first':
    'ビルドの完了を待つか、先にキャンセルしてください',
  'Create your first deployment to get started':
    '最初のデプロイを作成して始めましょう',
  'Are you sure you want to delete this deployment? This action cannot be undone.':
    'このデプロイを削除してもよろしいですか? この操作は元に戻せません。',
  'Stop the current deployment? You can deploy again later.':
    '現在のデプロイを停止しますか? 後で再デプロイできます。',
  "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build.":
    '現在のサイト設定を使用して、このデプロイの新しいビルドを作成します。元のデプロイのコードは保持され、新しいビルドに使用されます。',
  'This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.':
    'アクティブなデプロイをこれに切り替えます。有効化後、すべてのトラフィックがこのデプロイにルーティングされます。',
  'Logs will appear here when your site runs.':
    'サイトが実行されると、ここにログが表示されます。',
  'Executions will appear here when your site runs.':
    'サイトが実行されると、ここに実行が表示されます。',
  'Connect a custom domain to your site for a branded experience':
    'ブランド体験のためにサイトにカスタムドメインを接続',
  'SSL certificate is being issued. This usually takes a couple of minutes.':
    'SSL 証明書を発行中です。通常、数分かかります。',
  'Permanently delete this site and all its data. This action cannot be undone.':
    'このサイトとすべてのデータを完全に削除します。この操作は元に戻せません。',
  'and all its data? This action cannot be undone.':
    'とすべてのデータを削除してもよろしいですか? この操作は元に戻せません。',
  'Connect your site to a Git repository for automatic deployments':
    '自動デプロイのためにサイトを Git リポジトリに接続',
  'Connect a repository to enable automatic deployments':
    '自動デプロイを有効にするためにリポジトリを接続',
  'Select a Git installation and repository to connect to this site. You can connect an existing repository or create a new site from a template.':
    'このサイトに接続する Git インストールとリポジトリを選択してください。既存のリポジトリを接続するか、テンプレートから新しいサイトを作成できます。',
  "from this site? This will remove the Git integration but won't affect your deployments.":
    'をこのサイトから切断してもよろしいですか? Git 連携は削除されますが、デプロイには影響しません。',
  'Choose the directory containing your site code':
    'サイトコードを含むディレクトリを選択',
  'Choose how your site is rendered at runtime.':
    'ランタイムでサイトをどのようにレンダリングするかを選択',
  'Choose your stack, adapter mode, and where build output is written.':
    'スタック、アダプターモード、ビルド出力先を選択',
  "File to serve for routes that don't match any static files":
    '静的ファイルに一致しないルートで配信するファイル',
  'Shell commands run on the build worker (defaults follow your framework).':
    'ビルドワーカーで実行するシェルコマンド (デフォルトは Framework に従います)。',
  'CPU and memory allocated on the build worker for dependency install and compile steps.':
    '依存関係のインストールとコンパイル用にビルドワーカーに割り当てる CPU とメモリ。',
  'CPU and memory allocated when your site handles requests, including server-side rendering (SSR).':
    'サイトがリクエストを処理するとき (サーバーサイドレンダリング (SSR) を含む) に割り当てる CPU とメモリ。',
  'Base image used when your site runs in production (SSR, API routes, and dynamic handlers). Pick an image that matches your stack. Changes take effect after the next successful deploy.':
    'サイトが本番で実行されるとき (SSR、API ルート、動的ハンドラー) に使用するベース Image。スタックに合った Image を選択してください。変更は次回のデプロイ成功後に反映されます。',
  'No images are available for this framework yet.':
    'この Framework 向けの Image はまだありません。',
  'Choose a framework in build settings to see compatible images.':
    '互換性のある Image を表示するには、ビルド設定で Framework を選択してください。',
  'Command used to start your SSR server after a successful deploy. Leave it empty to use the framework default.':
    'デプロイ成功後に SSR サーバーを起動するコマンド。Framework のデフォルトを使う場合は空のままにしてください。',
  'Upper bound on how long a single request may run before the platform stops it. Use a higher value for slow SSR or data-heavy pages; use a lower value to fail fast when something hangs. Allowed range is 1–30 seconds.':
    'プラットフォームがリクエストを停止するまでの上限時間。SSR が遅い場合やデータ量の多いページには高い値、ハング時に早く失敗させたい場合は低い値を使用してください。許容範囲は 1 から 30 秒です。',
  'Timeout must be between 1 and 30 seconds':
    'タイムアウトは 1 から 30 秒の間である必要があります',
  'Controls how much detail is captured for each request. Full logging helps you debug production issues with stdout, stderr, and stack traces in the console. Turning logging off reduces overhead and can slightly improve response time when you do not need that detail.':
    '各リクエストでどれだけ詳細を記録するかを制御します。完全ログは stdout、stderr、スタックトレースをコンソールで確認でき、本番問題のデバッグに役立ちます。ログをオフにするとオーバーヘッドが減り、詳細が不要な場合は応答時間がわずかに改善されることがあります。',
  'Enabled - logs and errors from your site are recorded.':
    '有効: サイトからのログとエラーが記録されます。',
  'Disabled - lighter request records; responses may be slightly faster.':
    '無効: 軽量なリクエスト記録。応答がわずかに速くなる場合があります。',
  'Control whether Appwrite posts automated comments on commits in your connected GitHub repository (for example deployment notes on pull requests). Deployments, checks, and builds are unchanged-only optional commit comments are skipped when silent mode is on.':
    '接続した GitHub リポジトリのコミットに Appwrite が自動コメントを投稿するかを制御します (例: プルリクエストへのデプロイメモ)。デプロイ、チェック、ビルドは変わりません。サイレントモードがオンのときは任意のコミットコメントのみスキップされます。',
  'Configure environment variables for your site deployments. Site-specific variables override global project variables. Set the environment variables or secret keys that will be passed to this site during deployment.':
    'サイトデプロイの環境変数を構成します。サイト固有の変数はプロジェクト全体の変数を上書きします。デプロイ時にこのサイトへ渡す環境変数またはシークレットキーを設定してください。',
  'Shell command that starts your SSR app after deploy (for example, npm run start). If left empty, your framework default is used. This field is optional.':
    'デプロイ後に SSR アプリを起動するシェルコマンド (例: npm run start)。空のままにすると Framework のデフォルトが使われます。このフィールドは任意です。',
  'Are you sure you want to delete this site? This action cannot be undone.':
    'このサイトを削除してもよろしいですか? この操作は元に戻せません。',
  'Verification failed. Check DNS and retry.':
    '検証に失敗しました。DNS を確認して再試行してください。',
  'Import repositories for automatic deployments':
    '自動デプロイ用にリポジトリをインポート',
  'If you selected specific repositories during setup, you may need to update your permissions to include additional ones.':
    'セットアップ時に特定のリポジトリのみを選択した場合、追加のリポジトリを含めるために権限の更新が必要なことがあります。',
  'Want to deploy without connecting a repository or using a template?':
    'リポジトリ接続やテンプレートを使わずにデプロイしますか?',
  'Please fill in all required fields and upload a file':
    '必須項目をすべて入力し、ファイルをアップロードしてください',
  'Upload a .tar.gz file containing your site source code':
    'サイトのソースコードを含む .tar.gz ファイルをアップロード',
  'Drop your file here or click to browse':
    'ファイルをここにドロップするか、クリックして参照',
  'Your site will be accessible at this URL':
    'サイトはこの URL でアクセスできます',
  'Want to use your own domain? After deployment, you can connect a custom domain via CNAME record or let Appwrite manage your DNS.':
    '独自ドメインを使いますか? デプロイ後、CNAME レコードでカスタムドメインを接続するか、Appwrite に DNS 管理を任せられます。',
  'Could not detect framework. Select one manually.':
    'Framework を検出できませんでした。手動で選択してください。',
  'Repository not connected. Go back and select a repository.':
    'リポジトリが接続されていません。戻ってリポジトリを選択してください。',
  'Repository information is missing from the URL.':
    'URL にリポジトリ情報がありません。',
  'Production branch for the repo linked to the site. Successful deployments from this branch get activated automatically.':
    'サイトにリンクされたリポジトリの本番 Branch。ここからのデプロイ成功時は自動的に有効化されます。',
  'Path to site code in the linked repo. Use the repository root (./) or a subdirectory that contains your app (e.g. ./apps/web).':
    'リンクされたリポジトリ内のサイトコードへのパス。リポジトリルート (./) またはアプリを含むサブディレクトリ (例: ./apps/web) を使用してください。',
  'Disable automated comments on repository commits':
    'リポジトリのコミットへの自動コメントを無効化',
  'Clone this template into a new Git repository or link it to an existing one.':
    'このテンプレートを新しい Git リポジトリにクローンするか、既存のリポジトリにリンク',
  'Deploy now and connect your version control later via CLI or Git integration in your settings.':
    '今すぐデプロイし、CLI または設定の Git 連携で後からバージョン管理を接続',
  'Create and deploy a Site with a connected git repository.':
    '接続された Git リポジトリでサイトを作成してデプロイ',
  'Configure the environment variables for this template':
    'このテンプレートの環境変数を構成',
  'Screenshot may take a few moments after build completes':
    'ビルド完了後、スクリーンショットの生成に少し時間がかかることがあります',
  'Configure your site or share it with others':
    'サイトを構成するか、他のユーザーと共有',
  'Connect a Git repository for automatic deployments':
    '自動デプロイ用に Git リポジトリを接続',
  'Scan this QR code to open your site on a mobile device':
    'この QR コードをスキャンしてモバイルデバイスでサイトを開く',
  'QR code could not be generated. Try again in a moment.':
    'QR コードを生成できませんでした。しばらくしてから再試行してください。',
  Usage: '使用量',
  Sites: 'サイト',
  Preview: 'プレビュー',
  Site: 'サイト',
  Status: 'ステータス',
  Created: '作成日',
  Updated: '更新日',
  Never: 'なし',
  Deployed: 'デプロイ済み',
  preview: 'プレビュー',
  sites: 'サイト',
  site: 'サイト',
  selected: '選択済み',
  Cancel: 'キャンセル',
  Delete: '削除',
  Building: 'ビルド中',
  Source: 'ソース',
  Connected: '接続済み',
  Active: 'アクティブ',
  Domains: 'ドメイン',
  more: 'その他',
  domains: 'ドメイン',
  Download: 'ダウンロード',
  Redeploy: '再デプロイ',
  Visit: '訪問',
  Type: 'タイプ',
  Duration: '所要時間',
  Manual: '手動',
  by: 'による',
  Activate: '有効化',
  deployments: 'デプロイ',
  deployment: 'デプロイ',
  Ready: '準備完了',
  Processing: '処理中',
  Waiting: '待機中',
  Failed: '失敗',
  Canceled: 'キャンセル済み',
  Timeout: 'タイムアウト',
  logs: 'ログ',
  Domain: 'ドメイン',
  Retry: '再試行',
  Logs: 'ログ',
  Records: 'レコード',
  Verified: '検証済み',
  Deployments: 'デプロイ',
  Variables: '変数',
  Settings: '設定',
  Name: '名前',
  Update: '更新',
  Repository: 'リポジトリ',
  Connect: '接続',
  Disconnect: '切断',
  Adapter: 'アダプター',
  Framework: 'Framework',
  Commands: 'コマンド',
  Specification: '仕様',
  Image: 'Image',
  Logging: 'ログ記録',
  Details: '詳細',
  Global: 'グローバル',
  Copy: 'コピー',
  Verify: '検証',
  Change: '変更',
  Add: '追加',
  Required: '必須',
  Invalid: '無効',
  Starter: 'スターター',
  AI: 'AI',
  Databases: 'データベース',
  Messaging: 'メッセージング',
  Utilities: 'ユーティリティ',
  Deploy: 'デプロイ',
  Back: '戻る',
  Detect: '検出',
  Branch: 'Branch',
  configured: '設定済み',
  Build: 'ビルド',
  Reset: 'リセット',
  Version: 'バージョン',
  required: '必須',
  Secret: 'シークレット',
  Size: 'サイズ',
  Close: '閉じる',
}
