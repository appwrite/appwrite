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
}
