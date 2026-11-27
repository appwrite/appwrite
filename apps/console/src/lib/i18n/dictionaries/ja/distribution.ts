export const jaDistributionDictionary: Record<string, string> = {
  // Shared
  'Loading apps...': 'アプリを読み込み中...',
  'My app': 'マイアプリ',
  'No builds yet': 'ビルドがまだありません',
  'This distribution app does not exist or has been removed.':
    'この Distribution アプリは存在しないか、削除されています。',
  Distribution: 'Distribution',
  Track: 'トラック',
  'Create the app': 'アプリを作成する',
  'Build every platform': '全プラットフォームをビルドする',
  'Each version gets its own build per platform, with status and duration tracked here.':
    '各バージョンはプラットフォームごとにビルドされ、ステータスと所要時間をここで確認できます。',
  'Submit to the stores': 'ストアに提出する',
  'Code signing': 'コード署名',
  Signing: '署名中',
  Expiring: 'まもなく期限切れ',
  Revoked: '取り消し済み',
  // Statuses
  Validating: '検証中',
  'In review': '審査中',
  Published: '公開済み',
  Rejected: '却下',
  Halted: '一時停止中',
  'Needs attention': '要対応',
  Blocker: 'ブロッカー',
  'Manual action required': '手動での対応が必要',
  // Targets
  '4 vCPU, 8 GB RAM': '4 vCPU、8 GB RAM',
  '8 vCPU, 16 GB RAM': '8 vCPU、16 GB RAM',
  'A target holds the store identity and build settings for one platform of this app.':
    'ターゲットには、このアプリの 1 つのプラットフォームに対するストア情報とビルド設定が含まれます。',
  'Add an Android target to build this app and submit it to Google Play.':
    'Android ターゲットを追加すると、このアプリをビルドして Google Play に提出できます。',
  'Add an iOS target to build this app and submit it to the App Store.':
    'iOS ターゲットを追加すると、このアプリをビルドして App Store に提出できます。',
  'Add target': 'ターゲットを追加',
  'Android target': 'Android ターゲット',
  'App Store app ID': 'App Store のアプリ ID',
  'Appwrite assigns the next build number to every build.':
    'Appwrite がビルドごとに次のビルド番号を割り当てます。',
  'Automatic submission': '自動提出',
  'Build configuration': 'ビルド構成',
  'Build timeout (seconds)': 'ビルドのタイムアウト (秒)',
  'Builds and submissions for this platform stop until you add the target again. This action cannot be undone.':
    'ターゲットを再度追加するまで、このプラットフォームのビルドと提出は停止します。この操作は元に戻せません。',
  'Builds use the build number from your project.':
    'ビルドにはプロジェクトで設定したビルド番号が使用されます。',
  'Closed testing': 'クローズドテスト',
  'Failed to add target': 'ターゲットの追加に失敗しました',
  'Failed to remove target': 'ターゲットの削除に失敗しました',
  'Failed to update target': 'ターゲットの更新に失敗しました',
  Flavor: 'フレーバー',
  'Framework default': 'フレームワークのデフォルト',
  'Gradle task': 'Gradle タスク',
  'How builds of this target are numbered and submitted.':
    'このターゲットのビルドの番号付けと提出方法です。',
  'Internal testing': '内部テスト',
  'iOS target': 'iOS ターゲット',
  'Last build number:': '最後のビルド番号:',
  'Manage connections': '接続を管理',
  'Manage signing': '署名を管理',
  'No Android target': 'Android ターゲットがありません',
  'No connections for this store yet.': 'このストアの接続はまだありません。',
  'No iOS target': 'iOS ターゲットがありません',
  'No signing identities for this platform yet.':
    'このプラットフォームの署名 ID はまだありません。',
  'Open testing': 'オープンテスト',
  'Output path': '出力パス',
  'Override the framework defaults. Leave a field empty to use the default.':
    'フレームワークのデフォルトを上書きします。デフォルトを使う場合は空欄のままにします。',
  'Remove target': 'ターゲットを削除',
  'Resolved by preflight': 'プリフライトで取得',
  'Resolved by preflight from the bundle ID.':
    'プリフライトでバンドル ID から取得されます。',
  Scheme: 'スキーム',
  'Signing identity': '署名 ID',
  'Store and signing': 'ストアと署名',
  'Store connection': 'ストア接続',
  'Submit every successful Git build of this target to the selected track.':
    'このターゲットの Git ビルドが成功するたびに、選択したトラックへ提出します。',
  'Target added': 'ターゲットを追加しました',
  'Target removed': 'ターゲットを削除しました',
  'Target updated': 'ターゲットを更新しました',
  'TestFlight (external)': 'TestFlight (外部)',
  'TestFlight (internal)': 'TestFlight (内部)',
  'Use 0 for the default timeout.':
    'デフォルトのタイムアウトを使う場合は 0 を指定します。',
  'Version strategy': 'バージョン戦略',
  'Where builds of this target are submitted and how they are signed.':
    'このターゲットのビルドの提出先と署名方法です。',
  Workspace: 'ワークスペース',
  'Xcode project': 'Xcode プロジェクト',
  // App tabs
  Artifacts: 'アーティファクト',
  Releases: 'リリース',
  // Settings
  'Variables are passed to every build of this app. App variables override project variables with the same key. Keys cannot start with DISTRIBUTION_ or APPWRITE_.':
    '変数はこのアプリのすべてのビルドに渡されます。同じキーのプロジェクト変数よりアプリ変数が優先されます。キーを DISTRIBUTION_ または APPWRITE_ で始めることはできません。',
  'Permanently delete this app with its targets, variables and builds. Apps already published to the stores stay there. This action cannot be undone.':
    'このアプリとそのターゲット、変数、ビルドを完全に削除します。ストアで公開済みのアプリはそのまま残ります。この操作は元に戻せません。',
  'Its targets, variables, builds and repository link are removed. This action cannot be undone.':
    'ターゲット、変数、ビルド、リポジトリとの連携が削除されます。この操作は元に戻せません。',
  'Enter app name': 'アプリ名を入力',
  'Build on push': 'プッシュ時にビルド',
  'Build every target when a push matches your triggers. When off, start builds manually.':
    'プッシュがトリガーに一致すると、すべてのターゲットをビルドします。オフの場合は手動でビルドを開始します。',
  'Report commit status': 'コミットステータスを報告',
  'Show building, passed or failed on each commit. Can be required in branch protection.':
    '各コミットにビルド中、成功、失敗を表示します。ブランチ保護で必須にできます。',
  'Post build summaries': 'ビルドの概要を投稿',
  'Distribution does not comment on pull requests yet. This preference is kept for when it does.':
    'Distribution はまだプルリクエストにコメントしません。この設定は今後の対応に備えて保存されます。',
  'Git automation': 'Git 自動化',
  'Choose what happens when you push to the connected repository.':
    '接続したリポジトリにプッシュしたときの動作を選択します。',
  'Connected repository': '接続済みのリポジトリ',
  'Connect your app to a Git repository to build it when you push.':
    'アプリを Git リポジトリに接続すると、プッシュ時にビルドされます。',
  'Repository details unavailable': 'リポジトリの詳細を取得できません',
  'Pushes to this repository will stop building this app. Existing builds are kept.':
    'このリポジトリへのプッシュでこのアプリはビルドされなくなります。既存のビルドは保持されます。',
  "This app's Git installation can no longer reach its repository, so pushes can't build. Disconnect the repository, then connect it again through the installation that has it now.":
    'このアプリの Git インストールからリポジトリにアクセスできなくなったため、プッシュでビルドできません。リポジトリの接続を解除し、現在そのリポジトリを持つインストールから再接続してください。',
  "This app's repository details could not be loaded. Its Git connection is unchanged, but branch and root directory options are unavailable until access is restored.":
    'このアプリのリポジトリの詳細を読み込めませんでした。Git の接続は変わっていませんが、アクセスが回復するまでブランチとルートディレクトリの設定は利用できません。',
  'Choose the directory containing your app code':
    'アプリのコードを含むディレクトリを選択します',
  'App name updated': 'アプリ名を更新しました',
  'Failed to update app name': 'アプリ名の更新に失敗しました',
  'The name shown for this app in the Console.':
    'Console でこのアプリに表示される名前です。',
  'Failed to update app status': 'アプリのステータスの更新に失敗しました',
  'A disabled app ignores pushes and does not accept new builds.':
    '無効にしたアプリはプッシュを無視し、新しいビルドを受け付けません。',
  'Pushes to these branches start a build. When empty, only the production branch builds.':
    'これらのブランチへのプッシュでビルドが始まります。空の場合は本番ブランチのみビルドされます。',
  'Only changes to these paths start a build. When empty, any change builds.':
    'これらのパスへの変更だけがビルドを開始します。空の場合はすべての変更でビルドされます。',
  'Pushing a matching tag starts a build. When empty, tags never build. GitHub and Gitea only.':
    '一致するタグをプッシュするとビルドが始まります。空の場合、タグではビルドされません。GitHub と Gitea のみ対応しています。',
  'Limit which pushes build this app. Use glob patterns, and start a pattern with ! to exclude it.':
    'このアプリをビルドするプッシュを絞り込みます。glob パターンを使用し、除外するパターンは ! で始めます。',
  'Use letters, numbers and glob characters, and do not include and exclude the same pattern.':
    '英数字と glob 文字を使用し、同じパターンを含めると同時に除外しないでください。',

  // Builds
  'Add a target to the app to start builds.':
    'ビルドを開始するには、アプリにターゲットを追加してください。',
  'Back to builds': 'ビルド一覧に戻る',
  'Build a target from a branch, tag or commit of the connected repository.':
    '接続済みリポジトリのブランチ、タグ、またはコミットからターゲットをビルドします。',
  'Build canceled': 'ビルドをキャンセルしました',
  'Build deleted': 'ビルドを削除しました',
  'Build failed': 'ビルドに失敗しました',
  'Build from': 'ビルド元',
  'Build not found': 'ビルドが見つかりません',
  'Build started': 'ビルドを開始しました',
  'Cancel the build before you delete it.':
    '削除する前にビルドをキャンセルしてください。',
  'Connect a repository to the app to start builds.':
    'ビルドを開始するには、アプリにリポジトリを接続してください。',
  'Could not load this build': 'このビルドを読み込めませんでした',
  'Create build': 'ビルドを作成',
  'Delete build': 'ビルドを削除',
  'Each build compiles one target from the repository and signs it for its store.':
    '各ビルドはリポジトリから 1 つのターゲットをコンパイルし、ストア向けに署名します。',
  'Failed to delete build': 'ビルドの削除に失敗しました',
  'Failed to start build': 'ビルドの開始に失敗しました',
  'Fetching source': 'ソースを取得中',
  Inspecting: '検査中',
  'Its logs are deleted, and so is its artifact when no submission uses it. This action cannot be undone.':
    'ログは削除されます。どの提出にも使われていない場合は、アーティファクトも削除されます。この操作は元に戻せません。',
  'Leave empty to use the version set in your project.':
    '空欄の場合は、プロジェクトで設定されたバージョンを使用します。',
  'Load earlier logs': '以前のログを読み込む',
  'Loading builds...': 'ビルドを読み込み中...',
  'No version': 'バージョンなし',
  'Showing the most recent logs.': '最新のログを表示しています。',
  'Start a build from the connected repository, or push to a branch that matches your triggers.':
    '接続済みリポジトリからビルドを開始するか、トリガーに一致するブランチに push してください。',
  'The build stops and its logs are kept. Start a new build to try again.':
    'ビルドは停止し、ログは保持されます。もう一度試すには新しいビルドを開始してください。',
  'This build does not exist or has been deleted.':
    'このビルドは存在しないか、削除されています。',
  'This build has no commit to build again.':
    'このビルドには再ビルドできるコミットがありません。',
  'This build has no logs.': 'このビルドにはログがありません。',
  'Failed to load logs': 'ログを読み込めませんでした',
  'This target sets version codes manually.':
    'このターゲットはバージョンコードを手動で設定します。',
  'Uploading to the store': 'ストアにアップロード中',
  'Version code': 'バージョンコード',
  'Version name': 'バージョン名',
  builds: 'ビルド',
  'Enter a whole number from 1 to 2,100,000,000.':
    '1 から 2,100,000,000 までの整数を入力してください。',
  'Failed to load builds': 'ビルドを読み込めませんでした',
  // Apps
  'A native Android project built with Gradle.':
    'Gradle でビルドするネイティブ Android プロジェクトです。',
  'A native iOS project built with Xcode.':
    'Xcode でビルドするネイティブ iOS プロジェクトです。',
  'App created, but these targets were not added:':
    'アプリは作成されましたが、次のターゲットは追加されませんでした:',
  'At least two parts separated by periods, each starting with a letter, as in your Gradle applicationId.':
    'Gradle の applicationId と同じく、ピリオドで区切った 2 つ以上の部分で、それぞれ英字で始まります。',
  'Build Android and iOS releases from one place and submit them to Google Play and App Store Connect.':
    'Android と iOS のリリースを 1 か所でビルドし、Google Play と App Store Connect に提出できます。',
  'Build from a branch on every push or tag.':
    'プッシュやタグのたびにブランチからビルドします。',
  'Build the app from a connected Git repository.':
    '接続した Git リポジトリからアプリをビルドします。',
  'Builds use the toolchain of this framework. Install and build commands can be changed per target later.':
    'このフレームワークのツールチェーンでビルドします。インストールコマンドとビルドコマンドは、後からターゲットごとに変更できます。',
  'Choose a framework': 'フレームワークを選択',
  'Choose platforms': 'プラットフォームを選択',
  'Choose the directory containing your app project':
    'アプリのプロジェクトを含むディレクトリを選択してください',
  'Connect a Git repository': 'Git リポジトリを接続',
  'Connect a repository': 'リポジトリを接続',
  'Each platform becomes a target with its own store identifier. Connections and signing are set on the target later.':
    '各プラットフォームは、独自のストア識別子を持つターゲットになります。接続と署名は後からターゲットで設定します。',
  'JavaScript or TypeScript with native iOS and Android builds.':
    'JavaScript または TypeScript で、iOS と Android のネイティブビルドを作成します。',
  'Latest build': '最新のビルド',
  'Letters, numbers, hyphens and periods, as registered in your Apple Developer account.':
    'Apple Developer アカウントに登録したとおりの、英数字、ハイフン、ピリオドです。',
  'Name your app': 'アプリに名前を付ける',
  'No apps found': 'アプリが見つかりません',
  'No repository': 'リポジトリなし',
  'Not available for this framework': 'このフレームワークでは利用できません',
  'One Dart codebase for iOS and Android.':
    'iOS と Android を 1 つの Dart コードベースで開発します。',
  'Path to the app in the linked repository. Use the repository root (./) or the directory that holds the app project.':
    '連携したリポジトリ内のアプリのパスです。リポジトリのルート (./) か、アプリのプロジェクトを含むディレクトリを指定します。',
  'Pick Flutter, React Native, native Android or native iOS, and the platforms you ship to.':
    'Flutter、React Native、ネイティブ Android、ネイティブ iOS から選び、配布先のプラットフォームを指定します。',
  'Production branch of the app. Pushes to this branch start a build for every target.':
    'アプリの本番ブランチです。このブランチへのプッシュで、すべてのターゲットのビルドが始まります。',
  'Pushes to the repository start builds. You can also upload builds yourself and connect a repository later.':
    'リポジトリへのプッシュでビルドが始まります。ビルドを自分でアップロードし、リポジトリを後から接続することもできます。',
  'Send builds to Google Play and App Store Connect, or submit them automatically.':
    'ビルドを Google Play と App Store Connect に送信するか、自動で提出します。',
  'Ship your apps to the stores': 'アプリをストアに配信',
  'The name is shown in the Console. Each store keeps its own listing name.':
    'この名前はコンソールに表示されます。ストアの掲載名はストアごとに管理されます。',
  'Try a different name or ID.': '別の名前または ID をお試しください。',
  'Upload builds later': 'ビルドを後でアップロード',
  'Upload signed builds yourself. A repository can be connected in settings.':
    '署名済みのビルドを自分でアップロードします。リポジトリは設定から接続できます。',
  "You don't have permission to create apps.":
    'アプリを作成する権限がありません。',
  // Artifacts
  'Upload artifact': 'アーティファクトをアップロード',
  'Binaries built by Appwrite or uploaded from your own pipeline. A ready artifact can be submitted to the stores from a release.':
    'Appwrite でビルドしたバイナリ、または独自のパイプラインからアップロードしたバイナリです。準備完了のアーティファクトはリリースからストアに提出できます。',
  'Failed to load artifacts': 'アーティファクトを読み込めませんでした',
  'No artifacts yet': 'アーティファクトがまだありません',
  'Builds produce artifacts here. You can also upload an IPA or AAB built elsewhere.':
    'ビルドで作成されたアーティファクトがここに表示されます。別の環境でビルドした IPA または AAB をアップロードすることもできます。',
  artifacts: 'アーティファクト',
  'Only a ready artifact can be downloaded.':
    'ダウンロードできるのは準備完了のアーティファクトのみです。',
  Signed: '署名済み',
  Unsigned: '未署名',
  'Upload an IPA for iOS or an AAB for Android. Appwrite inspects the binary and matches it to a target by its bundle ID or package name.':
    'iOS 向けの IPA または Android 向けの AAB をアップロードします。Appwrite がバイナリを検査し、バンドル ID またはパッケージ名でターゲットに関連付けます。',
  'Choose an .ipa or .aab file.':
    '.ipa または .aab ファイルを選択してください。',
  'The upload runs in the background while you keep working. Reloading the page stops it.':
    'アップロードはバックグラウンドで続くため、そのまま作業を続けられます。ページを再読み込みすると停止します。',
  'This app has no targets yet. An artifact that matches no target is marked invalid.':
    'このアプリにはまだターゲットがありません。どのターゲットにも一致しないアーティファクトは無効になります。',
  'Delete {name}? Its file is removed and it can no longer be submitted. This action cannot be undone.':
    '{name} を削除しますか？ファイルが削除され、今後は提出できなくなります。この操作は元に戻せません。',
  'Artifact deleted': 'アーティファクトを削除しました',
  'Failed to delete artifact': 'アーティファクトを削除できませんでした',
  'Delete artifact': 'アーティファクトを削除',
  'Uploaded. Appwrite is inspecting the binary.':
    'アップロードしました。Appwrite がバイナリを検査しています。',
  'View artifacts': 'アーティファクトを表示',
  // Connections
  "You don't have permission to manage store connections.":
    'ストア接続を管理する権限がありません。',
  'App Store Connect': 'App Store Connect',
  'Google Play': 'Google Play',
  'Create connection': '接続を作成',
  'Connection deleted': '接続を削除しました',
  'An app target still uses this connection. Choose another connection for the target, then try again.':
    'アプリのターゲットがまだこの接続を使用しています。ターゲットに別の接続を選択してから、もう一度お試しください。',
  'Failed to delete connection': '接続を削除できませんでした',
  'Key ID': 'キー ID',
  'Issuer ID': '発行者 ID',
  'Service account': 'サービスアカウント',
  'Google Cloud project': 'Google Cloud プロジェクト',
  'Search connections...': '接続を検索...',
  'Connect a store account': 'ストアアカウントを接続',
  'Add an App Store Connect API key or a Google Play service account so Appwrite can upload builds and manage signing.':
    'App Store Connect の API キーまたは Google Play のサービスアカウントを追加すると、Appwrite がビルドのアップロードと署名の管理を行えるようになります。',
  'Delete connection': '接続を削除',
  'App Store Connect connected': 'App Store Connect を接続しました',
  'Connect App Store Connect': 'App Store Connect を接続',
  'Appwrite uses an App Store Connect API key to upload builds and manage signing. It never asks for your Apple ID or password.':
    'Appwrite は App Store Connect の API キーを使ってビルドのアップロードと署名の管理を行います。Apple ID やパスワードを求めることはありません。',
  'Create a team key': 'チームキーを作成',
  'Add the key': 'キーを追加',
  'The Account Holder must first request API access.':
    'まずアカウント保持者 (Account Holder) が API アクセスをリクエストする必要があります。',
  'Only the Account Holder can do this, once per team, in App Store Connect under Users and Access → Integrations.':
    'これはアカウント保持者のみが、チームごとに一度だけ、App Store Connect の Users and Access → Integrations で行えます。',
  'Generate a team key, not an individual key.':
    '個人キーではなく、チームキーを生成してください。',
  'Open Users and Access → Integrations → Team Keys and generate a key.':
    'Users and Access → Integrations → Team Keys を開き、キーを生成します。',
  'Give the key the App Manager role.':
    'キーに App Manager ロールを付与します。',
  'Choose Admin instead if Appwrite should create your certificates and provisioning profiles. A key role cannot be changed later.':
    'Appwrite に証明書とプロビジョニングプロファイルを作成させる場合は、代わりに Admin を選択してください。キーのロールは後から変更できません。',
  'Download the .p8 file and note the key ID and issuer ID.':
    '.p8 ファイルをダウンロードし、キー ID と発行者 ID を控えておきます。',
  'Apple lets you download a key only once.':
    'Apple ではキーを一度しかダウンロードできません。',
  'Open App Store Connect': 'App Store Connect を開く',
  'Shown above the list of team keys.':
    'チームキーの一覧の上に表示されています。',
  'Private key (.p8)': '秘密鍵 (.p8)',
  'App Store Connect rejected the key.':
    'App Store Connect がキーを拒否しました。',
  'Google Play connected': 'Google Play を接続しました',
  'Connect Google Play': 'Google Play を接続',
  'Appwrite uses a service account from your own Google Cloud project to upload bundles to Google Play.':
    'Appwrite は、お客様自身の Google Cloud プロジェクトのサービスアカウントを使って Google Play にバンドルをアップロードします。',
  'Create a service account': 'サービスアカウントを作成',
  'Invite it to Play Console': 'Play Console に招待',
  'Enable the Google Play Android Developer API.':
    'Google Play Android Developer API を有効にします。',
  'Turn it on in the Google Cloud project that will own the service account.':
    'サービスアカウントを所有する Google Cloud プロジェクトで有効にします。',
  'Create a service account.': 'サービスアカウントを作成します。',
  'In IAM and admin → Service accounts, create an account. It needs no Google Cloud roles.':
    'IAM and admin → Service accounts でアカウントを作成します。Google Cloud のロールは不要です。',
  'Create a JSON key.': 'JSON キーを作成します。',
  'Open the account, then Keys → Add key → Create new key, and choose JSON. Keep the downloaded file.':
    'アカウントを開き、Keys → Add key → Create new key で JSON を選択します。ダウンロードしたファイルは保管しておいてください。',
  'Open the API library': 'API ライブラリを開く',
  'Open service accounts': 'サービスアカウントを開く',
  'Invite the service account email.':
    'サービスアカウントのメールアドレスを招待します。',
  'In Play Console, open Users and permissions → Invite new users and enter the email of the service account.':
    'Play Console で Users and permissions → Invite new users を開き、サービスアカウントのメールアドレスを入力します。',
  'Grant access to your apps.': 'アプリへのアクセス権を付与します。',
  'Add the apps Appwrite will publish, with permission to release to the testing tracks and to production that you plan to use.':
    'Appwrite が公開するアプリを追加し、使用する予定のテストトラックと本番へのリリース権限を付与します。',
  'Send the invitation.': '招待を送信します。',
  'A service account accepts it straight away. Permission changes can take a few minutes to apply.':
    'サービスアカウントはすぐに招待を承諾します。権限の変更が反映されるまで数分かかることがあります。',
  'Open Play Console': 'Play Console を開く',
  'Service account key (JSON)': 'サービスアカウントキー (JSON)',
  'Google Play rejected the service account.':
    'Google Play がサービスアカウントを拒否しました。',
  'Connection updated': '接続を更新しました',
  'Update connection': '接続を更新',
  'Rename the connection or replace its key. Appwrite checks a new key before saving it.':
    '接続の名前を変更するか、キーを置き換えます。新しいキーは保存前に Appwrite が検証します。',
  'Rename the connection or replace its service account key. Appwrite checks a new key before saving it.':
    '接続の名前を変更するか、サービスアカウントキーを置き換えます。新しいキーは保存前に Appwrite が検証します。',
  'Last check failed:': '前回のチェックに失敗しました:',
  'New private key (.p8)': '新しい秘密鍵 (.p8)',
  'New service account key (JSON)': '新しいサービスアカウントキー (JSON)',
  'Leave the key fields empty to keep the current key.':
    '現在のキーを使い続ける場合は、キーの項目を空のままにしてください。',
  'Leave this empty to keep the current key.':
    '現在のキーを使い続ける場合は、空のままにしてください。',
  'The connection was not updated.': '接続は更新されませんでした。',
  'Enter a name.': '名前を入力してください。',
  'Enter the key ID.': 'キー ID を入力してください。',
  'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.':
    'キー ID は 2X9R4HXF34 のような 10 文字の英大文字と数字です。',
  'Enter the issuer ID. Individual keys have none, so use a team key.':
    '発行者 ID を入力してください。個人キーには発行者 ID がないため、チームキーを使用してください。',
  'An issuer ID looks like 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1.':
    '発行者 ID は 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1 のような形式です。',
  'Add the .p8 private key.': '.p8 秘密鍵を追加してください。',
  'The .p8 file must contain a PEM private key.':
    '.p8 ファイルには PEM 形式の秘密鍵が含まれている必要があります。',
  'Add the service account JSON key.':
    'サービスアカウントの JSON キーを追加してください。',
  'The key is not valid JSON.': 'キーが有効な JSON ではありません。',
  'This is not a service account key. Create a JSON key for a service account.':
    'これはサービスアカウントのキーではありません。サービスアカウントの JSON キーを作成してください。',
  'The key needs client_email and private_key. Download a new JSON key.':
    'キーには client_email と private_key が必要です。新しい JSON キーをダウンロードしてください。',
  'The file could not be read. Try again.':
    'ファイルを読み込めませんでした。もう一度お試しください。',
  'Drop files here': 'ここにファイルをドロップ',
  'Drop a file here': 'ここにファイルをドロップ',
  'Add another file': '別のファイルを追加',
  'Paste contents': '内容を貼り付け',
  // Releases
  releases: 'リリース',
  'A release is one version of this app. Submit it to each store from the release page.':
    'リリースはこのアプリの 1 つのバージョンです。リリースページから各ストアに提出します。',
  'Add a target to this app before submitting a release.':
    'リリースを提出する前に、このアプリにターゲットを追加してください。',
  'Add locale': 'ロケールを追加',
  'Appwrite stops this submission and withdraws it from review. You can submit the release again later.':
    'Appwrite はこの提出を停止し、審査から取り下げます。リリースは後で再提出できます。',
  'Appwrite uploads the artifact to the store, then follows processing and review. Track the progress on this page.':
    'Appwrite がアーティファクトをストアにアップロードし、処理と審査の状況を追跡します。進捗はこのページで確認できます。',
  'Back to releases': 'リリースに戻る',
  'Below 100% requests a staged rollout that you can raise, pause and resume. If the store applies a different percentage, you are warned after submitting.':
    '100% 未満にすると段階的ロールアウトをリクエストし、割合の引き上げ、一時停止、再開ができます。ストアが別の割合を適用した場合は、提出後に警告が表示されます。',
  'Bug fixes and performance improvements.': 'バグ修正とパフォーマンスの改善。',
  'Builds for this release': 'このリリースのビルド',
  'Builds started for this release. Submit a ready build to a store from here.':
    'このリリース用に開始されたビルドです。準備完了のビルドはここからストアに提出できます。',
  'Cancel submission': '提出をキャンセル',
  'Change requested': '変更をリクエスト済み',
  'Choose the percentage of Google Play users who receive this version. Use 100% to finish the staged rollout.':
    'このバージョンを受け取る Google Play ユーザーの割合を選択します。100% にすると段階的ロールアウトが完了します。',
  'Could not load this release': 'このリリースを読み込めませんでした',
  'Create a release for the next version of this app, then submit its builds to the App Store and Google Play.':
    'このアプリの次のバージョンのリリースを作成し、そのビルドを App Store と Google Play に提出します。',
  'Create release': 'リリースを作成',
  'Delete release': 'リリースを削除',
  'Delete release {version}? Its submission history is deleted with it. Versions already in the stores stay there. This action cannot be undone.':
    'リリース {version} を削除しますか？提出履歴も一緒に削除されます。すでにストアにあるバージョンはそのまま残ります。この操作は元に戻せません。',
  'Each locale can have only one note.':
    '各ロケールに設定できるノートは 1 つだけです。',
  'Each store target gets its own submissions. The status follows the store as it processes, reviews and releases the version.':
    'ストアのターゲットごとに個別の提出があります。ステータスは、ストアでの処理、審査、リリースの進行に合わせて更新されます。',
  'Enter a locale for every note.':
    'すべてのノートにロケールを入力してください。',
  'Enter a whole number from 1 to 100.':
    '1 から 100 までの整数を入力してください。',
  'Failed to create release': 'リリースの作成に失敗しました',
  'Failed to create submission': '提出の作成に失敗しました',
  'Failed to delete release': 'リリースの削除に失敗しました',
  'Failed to load releases': 'リリースの読み込みに失敗しました',
  'Failed to update release': 'リリースの更新に失敗しました',
  'Failed to update the rollout': 'ロールアウトの更新に失敗しました',
  'Failed to update the submission': '提出の更新に失敗しました',
  'Loading artifacts...': 'アーティファクトを読み込み中...',
  Located: '特定済み',
  'No builds for this release yet.': 'このリリースのビルドはまだありません。',
  'No release notes.': 'リリースノートはありません。',
  'No releases yet': 'リリースがまだありません',
  'No submissions for this target yet.':
    'このターゲットの提出はまだありません。',
  Notes: 'ノート',
  'Pause rollout': 'ロールアウトを一時停止',
  Processed: '処理済み',
  'Published on': '公開日時',
  'Release automatically': '自動でリリース',
  'Release created': 'リリースを作成しました',
  'Release deleted': 'リリースを削除しました',
  'Release mode': 'リリース方法',
  'Release not found': 'リリースが見つかりません',
  'Release notes': 'リリースノート',
  'Release the version as soon as Apple approves it. Turn this off to release it yourself from this page.':
    'Apple の承認後すぐにバージョンをリリースします。オフにすると、このページから手動でリリースできます。',
  'Release to the store': 'ストアにリリース',
  'Release updated': 'リリースを更新しました',
  Released: 'リリース済み',
  'Remove notes': 'ノートを削除',
  'Resume rollout': 'ロールアウトを再開',
  'Reviewed on': '審査日時',
  Rollout: 'ロールアウト',
  'Rollout percentage': 'ロールアウトの割合',
  'Rollout updated': 'ロールアウトを更新しました',
  'Search releases': 'リリースを検索',
  'Search releases...': 'リリースを検索...',
  Settled: '完了',
  'Store releases cannot be rolled back. To stop a release, pause a Google Play staged rollout or an App Store phased release, or submit a previous artifact with a higher version code as a new submission.':
    'ストアでのリリースはロールバックできません。リリースを止めるには、Google Play の段階的ロールアウトか App Store の段階的リリースを一時停止するか、以前のアーティファクトをより大きいバージョンコードで新しく提出してください。',
  'Store status': 'ストアのステータス',
  'Submission created': '提出を作成しました',
  Submissions: '提出',
  'Submissions already sent to a store keep the version and notes they were sent with.':
    'すでにストアに送信された提出は、送信時のバージョンとノートのまま変わりません。',
  'Submit build': 'ビルドを提出',
  'Submit release': 'リリースを提出',
  'Submit to Google Play': 'Google Play に提出',
  'Submit to the App Store': 'App Store に提出',
  'Submitted on': '提出日時',
  'The store continues offering this version from the current rollout percentage.':
    'ストアは現在のロールアウトの割合から、このバージョンの提供を再開します。',
  'The store makes this approved version available to users. A released version cannot be withdrawn.':
    '承認済みのこのバージョンがストアでユーザーに公開されます。リリースしたバージョンは取り下げられません。',
  'The store stops offering this version to more users. Users who already updated keep it. You can resume the rollout later.':
    'ストアはこのバージョンを新たなユーザーに提供しなくなります。すでに更新したユーザーはそのまま利用できます。ロールアウトは後で再開できます。',
  'The version can be at most 64 characters.':
    'バージョンは 64 文字以内で入力してください。',
  'The version users see in the stores, for example 1.2.0.':
    'ストアでユーザーに表示されるバージョンです (例: 1.2.0)。',
  'This release does not exist or has been deleted.':
    'このリリースは存在しないか、削除されています。',
  'This target has no ready artifact. Build the release or upload a binary first.':
    'このターゲットには準備完了のアーティファクトがありません。先にリリースをビルドするか、バイナリをアップロードしてください。',
  'Update release': 'リリースを更新',
  'Update rollout': 'ロールアウトを更新',
  Validated: '検証済み',
  'What changed in this version, per store locale. The stores show these notes to users.':
    'このバージョンの変更内容をストアのロケールごとに入力します。ストアはこのノートをユーザーに表示します。',
  "You don't have permission to create releases.":
    'リリースを作成する権限がありません。',
  "You don't have permission to manage submissions.":
    '提出を管理する権限がありません。',
  "You don't have permission to update releases.":
    'リリースを更新する権限がありません。',
  "You don't have permission to view submissions. Ask an organization owner for access.":
    '提出を表示する権限がありません。組織のオーナーにアクセスを依頼してください。',
  'Select an artifact': 'アーティファクトを選択',
  'The selected artifact is not ready for this target. Choose another artifact or wait for it to finish processing.':
    '選択したアーティファクトはこのターゲットで使用できる状態ではありません。別のアーティファクトを選択するか、処理が終わるまでお待ちください。',
  'The store applied a {percent}% rollout instead of the requested {requested}%.':
    'ストアはリクエストされた {requested}% ではなく {percent}% のロールアウトを適用しました。',
  "This build's artifact is not available for submission.":
    'このビルドのアーティファクトは提出に使用できません。',
  "This build's artifact is still processing.":
    'このビルドのアーティファクトはまだ処理中です。',
  "This build's target no longer exists.":
    'このビルドのターゲットはもう存在しません。',
  // Signing
  "You don't have permission to manage signing identities.":
    '署名 ID を管理する権限がありません。',
  'Import iOS certificate': 'iOS 証明書をインポート',
  'Import Android keystore': 'Android キーストアをインポート',
  'Generate with Appwrite': 'Appwrite で生成',
  'Add signing identity': '署名 ID を追加',
  'Signing identity deleted': '署名 ID を削除しました',
  'An app target still uses this signing identity. Choose another one for the target, then try again.':
    'アプリのターゲットがまだこの署名 ID を使用しています。ターゲットに別の署名 ID を選択してから、もう一度お試しください。',
  'Failed to delete signing identity': '署名 ID を削除できませんでした',
  'No signing identities': '署名 ID がありません',
  'Import your certificates and keystores, or let Appwrite generate and renew them for you.':
    '証明書とキーストアをインポートするか、Appwrite に生成と更新を任せましょう。',
  Identifiers: '識別子',
  'Copy fingerprint': 'フィンガープリントをコピー',
  'signing identities': '署名 ID',
  'Delete signing identity': '署名 ID を削除',
  Imported: 'インポート済み',
  Managed: 'マネージド',
  'Generate signing identity': '署名 ID を生成',
  'Appwrite creates the signing material, keeps it encrypted, and renews what it can before it expires.':
    'Appwrite が署名用の素材を作成して暗号化して保管し、可能なものは期限切れ前に更新します。',
  'Choose an App Store Connect connection.':
    'App Store Connect の接続を選択してください。',
  'Add at least one bundle ID.': 'Bundle ID を 1 つ以上追加してください。',
  'Signing identity generated': '署名 ID を生成しました',
  'Appwrite creates an Apple Distribution certificate through your App Store Connect connection, then an App Store provisioning profile for each bundle ID. This needs a team key with the Admin role. Appwrite keeps one managed certificate per Apple team.':
    'Appwrite は App Store Connect の接続を通じて Apple Distribution 証明書を作成し、Bundle ID ごとに App Store プロビジョニングプロファイルを作成します。これには Admin ロールのチームキーが必要です。Appwrite が管理する証明書は Apple チームごとに 1 つです。',
  'Appwrite generates an RSA 2048 upload key with a certificate valid for 30 years. For a new app, the first bundle signed with it becomes the upload key in Google Play. For an existing app, download upload-certificate.pem afterwards and request an upload key reset in Play Console.':
    'Appwrite は有効期間 30 年の証明書を持つ RSA 2048 のアップロード鍵を生成します。新しいアプリでは、この鍵で署名した最初のバンドルによって Google Play のアップロード鍵として登録されます。既存のアプリでは、後で upload-certificate.pem をダウンロードし、Play Console でアップロード鍵のリセットをリクエストしてください。',
  'Managed iOS signing needs an App Store Connect connection. Connect one first.':
    'マネージドの iOS 署名には App Store Connect の接続が必要です。先に接続してください。',
  'Go to connections': '接続へ移動',
  'App Store Connect connection': 'App Store Connect の接続',
  'Choose a connection': '接続を選択',
  'Press Enter after each bundle ID. Include the IDs of app extensions.':
    'Bundle ID ごとに Enter を押します。App Extension の ID も含めてください。',
  'The signing identity was not generated.': '署名 ID は生成されませんでした。',
  'Download signing files': '署名ファイルをダウンロード',
  'Anyone with these files and passwords can sign apps as you. Store them somewhere safe, such as a password manager.':
    'これらのファイルとパスワードがあれば、誰でもあなたとしてアプリに署名できます。パスワードマネージャーなど安全な場所に保管してください。',
  'The signing files could not be loaded.':
    '署名ファイルを読み込めませんでした。',
  'Passwords are shown only in this dialog. Copy them before you close it.':
    'パスワードはこのダイアログにのみ表示されます。閉じる前にコピーしてください。',
  'Resetting the upload key of an existing app?':
    '既存のアプリのアップロード鍵をリセットしますか?',
  'In Play Console, open Protected with Play → Manage Play app signing → Request upload key reset, and upload upload-certificate.pem. Google holds the app signing key, so users keep receiving updates.':
    'Play Console で Protected with Play → Manage Play app signing → Request upload key reset を開き、upload-certificate.pem をアップロードします。アプリ署名鍵は Google が保持しているため、ユーザーは引き続きアップデートを受け取れます。',
  'Certificate password': '証明書のパスワード',
  'Keystore password': 'キーストアのパスワード',
  'Key password': '鍵のパスワード',
  'Key alias': '鍵のエイリアス',
  'Upload the keystore that holds your upload key, so Appwrite signs bundles the way Google Play expects. Files and passwords are encrypted before they are stored.':
    'アップロード鍵を含むキーストアをアップロードすると、Appwrite は Google Play の要件どおりにバンドルに署名します。ファイルとパスワードは保存前に暗号化されます。',
  'Add the keystore file.': 'キーストアファイルを追加してください。',
  'Enter the keystore password.': 'キーストアのパスワードを入力してください。',
  'Enter the key alias.': '鍵のエイリアスを入力してください。',
  'Signing identity imported': '署名 ID をインポートしました',
  'Upload key': 'アップロード鍵',
  Keystore: 'キーストア',
  'A PKCS#12 keystore is checked right away. A JKS keystore is checked by its first signing job.':
    'PKCS#12 キーストアはすぐに検証されます。JKS キーストアは最初の署名ジョブで検証されます。',
  'Leave empty to use the keystore password. A PKCS#12 keystore always uses the keystore password.':
    'キーストアのパスワードを使う場合は空のままにします。PKCS#12 キーストアでは常にキーストアのパスワードが使われます。',
  'The signing identity was not imported.':
    '署名 ID はインポートされませんでした。',
  'Import iOS signing identity': 'iOS の署名 ID をインポート',
  'Upload an Apple Distribution certificate with its private key and the App Store provisioning profiles that include it. Files are encrypted before they are stored.':
    '秘密鍵付きの Apple Distribution 証明書と、それを含む App Store プロビジョニングプロファイルをアップロードします。ファイルは保存前に暗号化されます。',
  'Add the .p12 certificate.': '.p12 証明書を追加してください。',
  'Add at least one App Store provisioning profile.':
    'App Store プロビジョニングプロファイルを 1 つ以上追加してください。',
  'Certificate (.p12)': '証明書 (.p12)',
  'In Keychain Access, export the Apple Distribution certificate together with its private key.':
    'キーチェーンアクセスで、Apple Distribution 証明書を秘密鍵と一緒に書き出します。',
  'Leave empty if the .p12 file has no password.':
    '.p12 ファイルにパスワードがない場合は空のままにします。',
  'Provisioning profiles (.mobileprovision)':
    'プロビジョニングプロファイル (.mobileprovision)',
  'Add one App Store profile for each bundle ID, including app extensions. Each profile must contain this certificate.':
    'App Extension を含め、Bundle ID ごとに App Store プロファイルを 1 つ追加します。各プロファイルにはこの証明書が含まれている必要があります。',
  // Overview
  'Checking…': '確認しています…',
  'Run checks again': 'チェックを再実行',
  'Could not run the checks': 'チェックを実行できませんでした',
  'Ready to submit': '提出の準備ができています',
  'Not ready to submit': '提出の準備ができていません',
  'Resolve the blockers below before submitting.':
    '提出する前に、以下のブロッカーを解決してください。',
  'Latest submission': '最新の提出',
  'No submissions yet': '提出はまだありません',
  'View build': 'ビルドを表示',
  'View release': 'リリースを表示',
  'Add an iOS or Android target to check whether this app is ready to submit to the stores.':
    'iOS または Android のターゲットを追加すると、このアプリをストアに提出できるかどうかを確認できます。',
  'Go to targets': 'ターゲットへ移動',
  'Store tasks': 'ストアでの作業',
  'Appwrite cannot do these for you. Complete them in each store before the first submission.':
    'これらの作業は Appwrite では代行できません。最初の提出の前に、各ストアで完了してください。',
  'Create the app record in App Store Connect with the bundle ID of the target.':
    'ターゲットの Bundle ID で App Store Connect にアプリを登録します。',
  'Accept the latest agreements in App Store Connect.':
    'App Store Connect で最新の契約に同意します。',
  'Complete the app privacy labels.': 'App のプライバシー情報を入力します。',
  'Answer the export compliance questions.':
    '輸出コンプライアンスに関する質問に回答します。',
  'Create the app in Play Console with the package name of the target.':
    'ターゲットのパッケージ名で Play Console にアプリを作成します。',
  'Accept the latest agreements in Play Console.':
    'Play Console で最新の契約に同意します。',
  'Complete the data safety form.': 'データセーフティ フォームに入力します。',
  'Upload the first build in Play Console yourself. Google Play accepts uploads from Appwrite after that.':
    '最初のビルドは Play Console からご自身でアップロードしてください。それ以降は Google Play が Appwrite からのアップロードを受け付けます。',
  'New personal developer accounts must run a closed test before releasing to production.':
    '新しい個人のデベロッパー アカウントでは、本番環境にリリースする前にクローズドテストを実施する必要があります。',
  // Review fixes: signing download
  'Show signing files': '署名ファイルを表示',
  'Every download is recorded in the audit log.':
    'ダウンロードはすべて監査ログに記録されます。',
  'The file could not be saved.': 'ファイルを保存できませんでした。',
  // Review fixes: apps and settings
  'Could not load this app': 'このアプリを読み込めませんでした',
  'App created, but the repository was not linked.':
    'アプリは作成されましたが、リポジトリはリンクされませんでした。',
  'Connect it from the app settings.': 'アプリの設定から接続してください。',
  'The server did not save these changes.':
    'サーバーはこれらの変更を保存しませんでした。',
  // Review fixes: credentials
  'Choose a file of type {extensions}.':
    '{extensions} 形式のファイルを選択してください。',
  'Delete {name}? Appwrite stops using its credentials immediately. This action cannot be undone.':
    '{name} を削除しますか？Appwrite はこの接続のアクセス資格情報の使用をただちに停止します。この操作は元に戻せません。',
  'Delete {name}? Appwrite deletes its encrypted files. Download them first if you still need them. This action cannot be undone.':
    '{name} を削除しますか？Appwrite は暗号化されたファイルを削除します。まだ必要な場合は先にダウンロードしてください。この操作は元に戻せません。',
  'Failed to load signing identities': '署名 ID の読み込みに失敗しました',
  // Publish
  Publish: '公開',
  'Publish app': 'アプリを公開',
  "You don't have permission to publish this app.":
    'このアプリを公開する権限がありません。',
  'Add a target to publish this app.':
    'このアプリを公開するにはターゲットを追加してください。',
  'Choose the targets to publish and check that each one is ready for its store.':
    '公開するターゲットを選び、それぞれがストアに提出できる状態か確認します。',
  'Choose the release that the builds and submissions belong to.':
    'ビルドと提出をまとめるリリースを選びます。',
  'Choose what to submit for each target.':
    'ターゲットごとに提出する内容を選びます。',
  'Review what happens for each target, then publish.':
    'ターゲットごとの処理内容を確認してから公開します。',
  'Release automatically after approval': '承認後に自動でリリース',
  'This target has no store connection.':
    'このターゲットにはストア接続がありません。',
  'Run checks': 'チェックを実行',
  'Running checks...': 'チェックを実行中...',
  'Select at least one target to publish.':
    '公開するターゲットを 1 つ以上選んでください。',
  'Run the checks to continue.': '続行するにはチェックを実行してください。',
  'Some checks could not run. Run them again to continue.':
    '一部のチェックを実行できませんでした。続行するにはもう一度実行してください。',
  'Resolve the blockers or deselect those targets to continue.':
    '続行するには、ブロッカーを解消するか該当するターゲットの選択を外してください。',
  'Failed to run the checks': 'チェックを実行できませんでした',
  'Create a release': 'リリースを作成',
  'The version people see in the stores, for example 1.4.0.':
    'ストアに表示されるバージョンです (例: 1.4.0)。',
  'What is new in this release': 'このリリースの新機能',
  'Add to an open release': '進行中のリリースに追加',
  'This app has no draft or active releases.':
    'このアプリには下書きまたは進行中のリリースがありません。',
  'Choose a release': 'リリースを選択',
  'Build from {branch}': '{branch} からビルド',
  'Build from Git': 'Git からビルド',
  'Connect a repository in the app settings to build from Git.':
    'Git からビルドするには、アプリの設定でリポジトリを接続してください。',
  'Use an artifact': 'アーティファクトを使用',
  'Appwrite builds the app for this release. Submit the build from the release page when it is ready.':
    'Appwrite がこのリリース用にアプリをビルドします。ビルドの準備ができたら、リリースのページから提出してください。',
  'This target sets version codes manually. Use a number higher than the last upload.':
    'このターゲットはバージョンコードを手動で設定します。前回のアップロードより大きい数値を指定してください。',
  'No ready artifacts for this target. Upload one from the Artifacts tab.':
    'このターゲットには準備完了のアーティファクトがありません。アーティファクトタブからアップロードしてください。',
  'Choose an artifact': 'アーティファクトを選択',
  'After approval': '承認後',
  'Wait for a manual release': '手動リリースを待つ',
  'Submit this build from the release page when it is ready.':
    'このビルドの準備ができたら、リリースのページから提出してください。',
  'Starting build': 'ビルドを開始中',
  Submitting: '提出中',
  Submitted: '提出済み',
  'Failed to create the release': 'リリースを作成できませんでした',
  'Failed to publish this target': 'このターゲットを公開できませんでした',
  'Publishing started': '公開を開始しました',
  // Review fixes: publish
  Artifact: 'アーティファクト',
  'The checks use the track shown for each target. Choose the track for an artifact in the Source step.':
    'チェックには各ターゲットに表示されているトラックが使われます。アーティファクトのトラックはソースのステップで選びます。',
  'The checks changed since you ran them. Review the targets to publish.':
    'チェックの実行後に結果が変わりました。ターゲットを確認してから公開してください。',
  'Could not load the targets of this app.':
    'このアプリのターゲットを読み込めませんでした。',
  'Set a production branch in the app settings to build from Git.':
    'Git からビルドするには、アプリの設定で本番ブランチを設定してください。',
}
