export const jaFunctionsBatch: Record<string, string> = {
  'Hello world': 'ハローワールド',
  'Add a repository URL or owner/name in the address bar, e.g.':
    'アドレスバーにリポジトリの URL または owner/name を入力してください。例:',
  'Allow anyone to execute this function (execute role: any)':
    '誰でもこの関数を実行できるようにする (実行ロール: any)',
  'and all its data? This action cannot be undone.':
    'とそのすべてのデータを削除しますか? この操作は元に戻せません。',
  "Appwrite's CDN provides global coverage with 120+ points of presence worldwide, reducing latency through edge caching and content optimization. All content is delivered over TLS for secure, encrypted connections.":
    'Appwrite の CDN は世界 120 以上の拠点をカバーし、エッジキャッシュとコンテンツ最適化によって遅延を低減します。すべてのコンテンツは TLS 経由で配信され、安全に暗号化された接続を実現します。',
  "Appwrite's network includes built-in DDoS mitigation to protect against distributed denial-of-service attacks, ensuring uninterrupted access to your functions and maintaining high availability even during high traffic loads.":
    'Appwrite のネットワークには DDoS 対策が標準搭載されており、分散型サービス拒否攻撃から保護します。トラフィックが多い状況でも、関数への継続的なアクセスと高い可用性を維持します。',
  'Are you sure you want to delete this deployment? This action cannot be undone.':
    'このデプロイを削除してもよろしいですか? この操作は元に戻せません。',
  'Are you sure you want to delete this function? This action cannot be undone.':
    'この関数を削除してもよろしいですか? この操作は元に戻せません。',
  'Build must be ready before activating': 'アクティブにする前にビルドが完了している必要があります',
  'Build output is available after the deployment has completed.':
    'ビルド出力は、デプロイが完了した後に確認できます。',
  'Cannot delete the active deployment. Please activate another deployment first.':
    'アクティブなデプロイは削除できません。先に別のデプロイをアクティブにしてください。',
  'Choose the directory containing your function code':
    '関数のコードが含まれるディレクトリを選択してください',
  'Clone this template into a new Git repository or link it to an existing one.':
    'このテンプレートを新しい Git リポジトリに複製するか、既存のリポジトリにリンクできます。',
  'Commands run while your function deployment is being built and packaged.':
    '関数のデプロイがビルドおよびパッケージ化される際に実行されるコマンドです。',
  'Commands to run during function build.': '関数のビルド中に実行するコマンドです。',
  'Configure the environment variables for this template':
    'このテンプレートの環境変数を設定してください',
  'Connect a custom domain to your function for a branded experience':
    '関数にカスタムドメインを接続して、独自ブランドの体験を提供できます',
  'Connect a repository to deploy functions from your codebase':
    'リポジトリを接続して、コードベースから関数をデプロイできます',
  'Connect a repository to enable automatic deployments':
    'リポジトリを接続すると、自動デプロイを有効にできます',
  'Connect your function to a Git repository for automatic deployments':
    '関数を Git リポジトリに接続して、自動デプロイを有効にできます',
  'Control whether Appwrite posts automated comments on commits in your connected GitHub repository (for example deployment notes on pull requests). Deployments, checks, and builds are unchanged-only optional commit comments are skipped when silent mode is on.':
    'Appwrite が、接続された GitHub リポジトリのコミットに自動コメントを投稿するかどうかを制御します (例: プルリクエストへのデプロイ情報)。サイレントモードが有効な場合でも、デプロイ、チェック、ビルドの動作は変わらず、コミットへのコメントのみがスキップされます。',
  'CPU and memory allocated on the build worker while your function image is produced.':
    '関数のイメージが生成される間、ビルドワーカーに割り当てられる CPU とメモリです。',
  'CPU and memory available to each function execution at runtime.':
    '各関数の実行時に利用できる CPU とメモリです。',
  'Create and deploy a Function with a connected git repository.':
    'Git リポジトリを接続した状態で、Function を作成してデプロイできます。',
  'Create your first deployment to activate this function.':
    'この関数を有効にするには、最初のデプロイを作成してください。',
  'Create your first deployment to get started':
    '最初のデプロイを作成して始めましょう',
  'Create your first function to deploy and manage serverless functions':
    '最初の関数を作成して、サーバーレス関数のデプロイと管理を始めましょう',
  'Cron expression must have 5 parts (minute hour day month weekday)':
    'Cron 式は 5 つの要素 (分 時 日 月 曜日) で構成されている必要があります',
  'Custom domain can be added in function settings.':
    'カスタムドメインは関数の設定から追加できます。',
  'Deploy now and connect your version control later via CLI or Git integration in your function settings.':
    '今すぐデプロイし、バージョン管理は後から関数の設定で CLI または Git 連携を使って接続できます。',
  'Directory containing your function code': '関数のコードが含まれるディレクトリ',
  'Disable automated comments on repository commits':
    'リポジトリのコミットへの自動コメントを無効にする',
  'Disabled - less log output per execution.':
    '無効 - 実行ごとのログ出力が少なくなります。',
  'Edit code locally and prepare gzip for deployment':
    'コードをローカルで編集し、デプロイ用の gzip を準備します',
  'Enable or disable this function without deleting it.':
    'この関数を削除せずに有効化・無効化できます。',
  'Enabled - function stdout and stderr are recorded.':
    '有効 - 関数の stdout と stderr が記録されます。',
  'Enter the file path (e.g. src/utils.js or lib/helper.ts).':
    'ファイルパスを入力してください (例: src/utils.js または lib/helper.ts)。',
  'Events that trigger this function (maximum 100).':
    'この関数をトリガーするイベントです (最大 100 件)。',
  'Execution cannot be created because there is no active deployment':
    'アクティブなデプロイがないため、実行を作成できません',
  'Execution environment for this function and the file Appwrite loads as the handler. CPU and memory per run are set under Specification.':
    'この関数の実行環境と、Appwrite がハンドラーとして読み込むファイルです。実行ごとの CPU とメモリは Specification で設定します。',
  'Executions will appear here when your function runs.':
    '関数が実行されると、ここに実行結果が表示されます。',
  'Failed to load functions. Please try again.':
    '関数の読み込みに失敗しました。もう一度お試しください。',
  'Format: minute hour day month weekday (e.g., "0 0 * * *" for daily at midnight)':
    '形式: 分 時 日 月 曜日 (例: 毎日 0 時に実行する場合は "0 0 * * *")',
  'from the project? This cannot be undone.':
    'をプロジェクトから削除しますか? この操作は元に戻せません。',
  'from this function? This will remove all Git configuration and you will need to reconnect the repository to enable automatic deployments.':
    'をこの関数から削除しますか? Git の設定はすべて削除され、自動デプロイを有効にするにはリポジトリを再接続する必要があります。',
  'Function limit reached for your plan.': 'プランの関数の上限に達しました。',
  'Function templates will appear here when they are available in the catalog.':
    '関数テンプレートは、カタログで利用可能になるとここに表示されます。',
  'Gzip compression is not supported in this browser':
    'このブラウザでは gzip 圧縮がサポートされていません',
  'Identifiers and timestamps for this function.':
    'この関数の識別子とタイムスタンプです。',
  'Maximum time a single execution may run before it is stopped. Use a higher value for slow I/O or heavy work; use a lower value to cap run time. Allowed range is 1–900 seconds.':
    '1 回の実行が停止されるまでに実行できる最大時間です。低速な I/O や重い処理には大きい値を、実行時間を短く制限したい場合は小さい値を設定してください。設定可能な範囲は 1〜900 秒です。',
  'More highlighted templates will show here when the catalog includes them.':
    '注目のテンプレートは、カタログに追加されるとここに表示されます。',
  'No active deployment. Deploy the function first to run executions.':
    'アクティブなデプロイがありません。実行するには、先に関数をデプロイしてください。',
  'Path to function code in the linked repo. Use the repository root (./) or a subdirectory that contains your function code.':
    'リンクされたリポジトリ内の関数コードへのパスです。リポジトリのルート (./) か、関数のコードを含むサブディレクトリを指定してください。',
  "Path to your function's entry point": '関数のエントリーポイントへのパス',
  'Permanently delete this function and all its data. This action cannot be undone.':
    'この関数とすべてのデータを完全に削除します。この操作は元に戻せません。',
  'Pick where your function runs. Both support custom domains after deployment.':
    '関数の実行場所を選択してください。どちらもデプロイ後にカスタムドメインをサポートします。',
  'Please fill in function name and runtime':
    '関数名とランタイムを入力してください',
  'Production branch for the repo linked to the function. Successful deployments from this branch get activated automatically.':
    '関数にリンクされたリポジトリの本番ブランチです。このブランチからの成功したデプロイは自動的にアクティブ化されます。',
  'Provide essential metadata to define the content type, authentication details, and the expected response format.':
    'コンテンツタイプ、認証情報、期待するレスポンス形式を定義するために必要なメタデータを入力してください。',
  'Provide the request body to include the main data you want to send to the server.':
    'サーバーに送信したい主なデータを含むリクエストボディを入力してください。',
  'Repository URL or owner/name is required':
    'リポジトリの URL または owner/name は必須です',
  'Return immediately and run in the background. View the response on the executions tab when it completes.':
    '即座にレスポンスを返し、バックグラウンドで実行されます。完了したら実行タブでレスポンスを確認できます。',
  'Run this function on a schedule using cron expressions.':
    'Cron 式を使って、この関数をスケジュール実行できます。',
  'Run your function or connect a repository for deployments':
    '関数を実行するか、リポジトリを接続してデプロイできます',
  'Runs every 6 hours (00:00, 06:00, 12:00, 18:00)':
    '6 時間ごとに実行 (00:00, 06:00, 12:00, 18:00)',
  'Runs once per month on the 15th at midnight': '毎月 15 日 0 時に 1 回実行',
  'Runs once per month on the 1st at midnight': '毎月 1 日 0 時に 1 回実行',
  'Runs once per week on Friday at midnight': '毎週金曜 0 時に 1 回実行',
  'Runs once per week on Sunday at midnight': '毎週日曜 0 時に 1 回実行',
  'Runs once per week on Thursday at midnight': '毎週木曜 0 時に 1 回実行',
  'Runs once per week on Tuesday at midnight': '毎週火曜 0 時に 1 回実行',
  'Runs once per week on Wednesday at midnight': '毎週水曜 0 時に 1 回実行',
  'Runtime specification for your function': '関数のランタイム仕様',
  'Select a GitHub installation and repository to connect to this function':
    'この関数に接続する GitHub インストールとリポジトリを選択してください',
  'Select scopes to grant the dynamic key generated temporarily for your function. It is best practice to allow only necessary permissions.':
    '関数のために一時的に生成される動的キーに付与するスコープを選択してください。必要な権限のみを許可することを推奨します。',
  'Select the runtime specification for your function':
    '関数のランタイム仕様を選択してください',
  'Set the environment variables or secret keys that will be passed to this function.':
    'この関数に渡す環境変数やシークレットキーを設定してください。',
  'Set the events that will trigger your function. Maximum 100 events allowed.':
    '関数をトリガーするイベントを設定してください。最大 100 件まで設定できます。',
  'SSL certificate is being issued. This usually takes a couple of minutes.':
    'SSL 証明書を発行しています。通常は数分で完了します。',
  'Stop the current deployment? You can deploy again later.':
    '現在のデプロイを停止しますか? 後で再度デプロイできます。',
  'The active deployment cannot be deleted from the list':
    'アクティブなデプロイは一覧から削除できません',
  "The function you're looking for doesn't exist or you don't have access to it.":
    'お探しの関数は存在しないか、アクセス権がありません。',
  'This catalog is built from our public GitHub repository. Browse the source, open issues, or submit a pull request if you want to add or improve a template.':
    'このカタログは公開されている GitHub リポジトリから構成されています。テンプレートの追加や改善をしたい場合は、ソースコードを確認したり、issue を作成したり、プルリクエストを送信したりしてください。',
  'This function is disabled and not accessible to end users through the API. Console actions remain available.':
    'この関数は無効になっており、API 経由でエンドユーザーがアクセスすることはできません。コンソールからの操作は引き続き利用できます。',
  "This may take a few minutes. We'll update automatically when it's ready.":
    'これには数分かかる場合があります。準備が整い次第、自動的に更新されます。',
  "This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.":
    'この操作により、現在の関数の設定を使ってこのデプロイの新しいビルドが作成されます。元のデプロイのコードは保持され、新しいビルドに使用されます。',
  'This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.':
    'この操作により、アクティブなデプロイがこのデプロイに切り替わります。有効化されると、すべてのトラフィックがこのデプロイにルーティングされます。',
  'Timeout must be between 1 and 900 seconds':
    'Timeout は 1〜900 秒の間で設定する必要があります',
  'to make it available to end users.': 'エンドユーザーが利用できるようにします。',
  'Try adjusting filters or search, or clear everything to see the full catalog.':
    'フィルターや検索条件を調整するか、すべてクリアしてカタログ全体を表示してください。',
  'Upload a .tar.gz archive containing your function code':
    '関数のコードを含む .tar.gz アーカイブをアップロードしてください',
  'Verification failed. Check DNS and retry.':
    '確認に失敗しました。DNS を確認してもう一度お試しください。',
  'Wait for the build to finish or cancel it first':
    'ビルドの完了を待つか、先にキャンセルしてください',
  'When enabled, execution output is written to your function logs in the console, which helps debugging. Disabling it reduces log volume when you do not need stdout and stderr from every run.':
    '有効にすると、実行の出力がコンソールの関数ログに書き込まれ、デバッグに役立ちます。毎回の実行で stdout と stderr が不要な場合は、無効にすることでログ量を減らせます。',
  "You don't have permission to create functions.":
    '関数を作成する権限がありません。',
  "You've updated function settings, but they won't take effect until you redeploy. The current deployment is still running with the previous settings.":
    '関数の設定を更新しましたが、再デプロイするまで反映されません。現在のデプロイは、以前の設定のまま実行され続けています。',
  'Your function is currently being redeployed.': '関数は現在再デプロイ中です。',
  'to see the supported data and how to log it.':
    'サポートされているデータとその記録方法を確認できます。',
  'Logging is disabled for this function. Enable logging in settings to view execution logs.':
    'この関数のログ記録は無効になっています。実行ログを表示するには、設定でログ記録を有効にしてください。',
  'Logging is disabled for this function. Enable logging in settings to view execution errors.':
    'この関数のログ記録は無効になっています。実行エラーを表示するには、設定でログ記録を有効にしてください。',
  "Body data is not captured by Appwrite for your user's security and privacy. To display body data in the Logs tab, use":
    'ユーザーのセキュリティとプライバシーを守るため、Appwrite はボディデータを記録しません。ログタブにボディデータを表示するには、次を使用してください',
}
