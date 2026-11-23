export const jaSitesBatch: Record<string, string> = {
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
  'Build output is only available for ready deployments.':
    'ビルド出力は、準備完了のデプロイでのみ利用できます。',
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
  'Select a GitHub installation and repository to connect to this site. You can connect an existing repository or create a new site from a template.':
    'このサイトに接続する GitHub インストールとリポジトリを選択してください。既存のリポジトリを接続するか、テンプレートから新しいサイトを作成できます。',
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
  'If you selected specific repositories during setup, you may need to update your GitHub permissions to include additional ones.':
    'セットアップ時に特定のリポジトリのみを選択した場合、追加のリポジトリを含めるために GitHub 権限の更新が必要なことがあります。',
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
}
