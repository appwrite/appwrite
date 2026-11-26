/**
 * Japanese translations for the Review product (AI code review of pull
 * requests: reviews list, review detail, and repositories).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaReviewsDictionary: Record<string, string> = {
  // Navigation and titles
  Review: 'コードレビュー',
  Reviews: 'レビュー',
  reviews: 'レビュー',
  Repositories: 'リポジトリ',
  'Back to reviews': 'レビューに戻る',
  'Review not found': 'レビューが見つかりません',
  'This review may have been deleted or the link is incorrect.':
    'このレビューは削除されたか、リンクが正しくない可能性があります。',

  // Empty states
  'Review pull requests with AI': 'AI でプルリクエストをレビュー',
  'Get a review on every pull request': 'すべてのプルリクエストをレビュー',
  'Each new pull request and commit gets a summary, findings on the lines they concern, a verdict, and a merge confidence grade.':
    '新しいプルリクエストとコミットごとに、概要、該当する行への指摘事項、判定、マージの信頼度が付きます。',
  'Talk to the reviewer': 'レビュアーと話す',
  'Mention the app in a comment to ask a question, request a new review, or have it remember a preference for later reviews.':
    'コメントでアプリにメンションすると、質問したり、新しいレビューを依頼したり、今後のレビューで守ってほしい方針を覚えさせたりできます。',
  'No reviews match your filters': 'フィルターに一致するレビューはありません',
  'No reviews yet': 'まだレビューはありません',
  'Reviews show up here when a pull request is opened or gets new commits in one of your repositories.':
    'リポジトリでプルリクエストが作成されるか、新しいコミットが追加されると、ここにレビューが表示されます。',
  'View repositories': 'リポジトリを表示',

  // Review details
  Verdict: '判定',
  Confidence: '信頼度',
  Finished: '終了',
  Findings: '指摘事項',
  'Skipped because': 'スキップした理由',
  'Failed because': '失敗した理由',
  'The review found nothing to flag.':
    'レビューで指摘事項は見つかりませんでした。',
  'View comment': 'コメントを表示',
  'Filter findings by severity': '重要度で指摘事項を絞り込む',

  // Statuses and verdicts
  Reviewing: 'レビュー中',
  'Changes requested': '変更をリクエスト',
  Commented: 'コメント済み',

  // Triggers
  'Pull request opened': 'プルリクエストの作成',
  'New commits': '新しいコミット',
  'Ready for review': 'レビュー準備完了',
  'Requested in a comment': 'コメントでリクエスト',

  // Finding severities
  Critical: '重大',
  Major: '高',
  Minor: '低',

  // Repositories
  Installation: 'インストール',
  'Already added': '追加済み',
  'Remove repository': 'リポジトリを削除',
  'Pull requests in the repository are reviewed when they are opened or get new commits.':
    'リポジトリのプルリクエストは、作成時と新しいコミットの追加時にレビューされます。',
  'Pull requests in this repository will no longer be reviewed. Past reviews stay in the project.':
    'このリポジトリのプルリクエストはレビューされなくなります。過去のレビューはプロジェクトに残ります。',
  'Code review turned on': 'コードレビューを有効にしました',
  'Code review turned off': 'コードレビューを無効にしました',
  'Failed to turn on code review': 'コードレビューを有効にできませんでした',
  'Failed to turn off code review': 'コードレビューを無効にできませんでした',
  "You don't have permission to add repositories.":
    'リポジトリを追加する権限がありません。',
  'Add all repositories': 'すべてのリポジトリを追加',
  'Adding repositories...': 'リポジトリを追加しています...',
  'Added: {count}': '追加: {count} 件',
  'Already on: {count}': '有効済み: {count} 件',
  'Failed: {count}': '失敗: {count} 件',
  'Some repositories could not be added':
    '一部のリポジトリを追加できませんでした',
  'Counting repositories...': 'リポジトリを数えています...',
  'Turn on code review for every repository in the installation?':
    'インストール内のすべてのリポジトリでコードレビューを有効にしますか？',
  'Add all': 'すべて追加',
  Trace: 'トレース',
  'No steps recorded yet.': 'まだステップは記録されていません。',
  // Trace
  'Review started': 'レビュー開始',
  'Merge only': 'マージのみ',
  'Model submitted': 'モデルが提出',
  'Review completed': 'レビュー完了',
  'Posted to GitHub': 'GitHub に投稿',
  'Invalid config': '無効な設定',
  'Sandbox ready': 'サンドボックス準備完了',
  'Sandbox setup': 'サンドボックスのセットアップ',
  'No sandbox': 'サンドボックスなし',
  'Sandbox failed': 'サンドボックス失敗',
  'Files filtered': 'ファイルを絞り込み',
  'Prompt files': 'プロンプトのファイル',
  'Read file': 'ファイルを読む',
  'AST search': 'AST 検索',
  'File history': 'ファイル履歴',
  'List refs': '参照の一覧',
  'Path in refs': '参照内のパス',
  'List files': 'ファイルの一覧',
  'Run command': 'コマンドを実行',
  'Subagent done': 'サブエージェント完了',
  'Subagent failed': 'サブエージェント失敗',
  'Subagent skipped': 'サブエージェントをスキップ',
  Placement: '配置',
  Relocated: '移動',
  Anchoring: 'アンカー',
  'Suggestion removed': '提案を削除',
  Remembered: '記憶',
  'Marked finding': '指摘をマーク',
  'tier {tier}': 'ティア {tier}',
  '{count} open finding': '未解決の指摘 {count} 件',
  '{count} open findings': '未解決の指摘 {count} 件',
  '{count} learning': '学習 {count} 件',
  '{count} learnings': '学習 {count} 件',
  'failed: {name}': '失敗: {name}',
  'Merge-only update since {sha}': '{sha} 以降はマージのみの更新',
  'Incremental review since {sha}': '{sha} 以降の差分レビュー',
  'Full review from base {sha}': 'ベース {sha} からの完全レビュー',
  'Only {ref} was merged in; previous review carried over':
    '{ref} のマージのみのため、前回のレビューを引き継ぎました',
  'Only the base branch was merged in; previous review carried over':
    'ベースブランチのマージのみのため、前回のレビューを引き継ぎました',
  '{count} finding ({subagents} from subagents)':
    '指摘 {count} 件（サブエージェントから {subagents} 件）',
  '{count} findings ({subagents} from subagents)':
    '指摘 {count} 件（サブエージェントから {subagents} 件）',
  '{count} finding': '指摘 {count} 件',
  '{count} findings': '指摘 {count} 件',
  '{count} resolved': '{count} 件解決',
  '{count} comment posted, {dropped} dropped, {open} still open':
    'コメント {count} 件を投稿、{dropped} 件を破棄、{open} 件が未解決',
  '{count} comments posted, {dropped} dropped, {open} still open':
    'コメント {count} 件を投稿、{dropped} 件を破棄、{open} 件が未解決',
  'Review posted': 'レビューを投稿しました',
  'review submitted': 'レビューを送信',
  'comment only': 'コメントのみ',
  'head moved': 'ヘッドが移動',
  '{count} dismissed': '{count} 件を却下',
  'The repository config is invalid': 'リポジトリの設定が無効です',
  'Started in {time}': '{time} で起動',
  '{count} file omitted': '{count} ファイルを省略',
  '{count} files omitted': '{count} ファイルを省略',
  '{count} file to review, {excluded} excluded':
    'レビュー対象 {count} ファイル、除外 {excluded} 件',
  '{count} files to review, {excluded} excluded':
    'レビュー対象 {count} ファイル、除外 {excluded} 件',
  '{count} file in the prompt': 'プロンプト内のファイル {count} 件',
  '{count} files in the prompt': 'プロンプト内のファイル {count} 件',
  '{count} too large to show': '{count} 件は大きすぎて表示できません',
  base: 'ベース',
  'last {count}': '直近 {count} 件',
  'with patches': 'パッチ付き',
  'all refs': 'すべての参照',
  '{count} ref': '参照 {count} 件',
  '{count} refs': '参照 {count} 件',
  'Kept {kept} of {count} finding': '指摘 {count} 件中 {kept} 件を保持',
  'Kept {kept} of {count} findings': '指摘 {count} 件中 {kept} 件を保持',
  kept: '保持',
  'lines {from} → {to}': '行 {from} → {to}',
  '{unattached} of {count} finding could not be placed on the diff':
    '指摘 {count} 件中 {unattached} 件を差分に配置できませんでした',
  '{unattached} of {count} findings could not be placed on the diff':
    '指摘 {count} 件中 {unattached} 件を差分に配置できませんでした',
  'skipped: {reason}': 'スキップ: {reason}',
  'timed out': 'タイムアウト',
  'exit {code}': '終了コード {code}',
  'Subagent {agent}': 'サブエージェント {agent}',
  '{count} step': '{count} ステップ',
  '{count} steps': '{count} ステップ',
  running: '実行中',

  // Repositories tab
  repositories: 'リポジトリ',
  Reviewed: 'レビュー対象',
  'All repositories': 'すべてのリポジトリ',
  'Reviewed since': 'レビュー開始日',
  'Review all': 'すべてレビュー',
  'Review all repositories': 'すべてのリポジトリをレビュー',
  'Choose repositories': 'リポジトリを選択',
  'Browse repositories': 'リポジトリを参照',
  'No repositories match your search': '検索に一致するリポジトリはありません',
  'No repositories reviewed yet': 'レビュー対象のリポジトリはまだありません',
  'No installation': 'インストールがありません',
  'Connect a Git provider to review pull requests':
    'Git プロバイダーを接続してプルリクエストをレビュー',
  'Turn on code review for a repository and its pull requests are reviewed when they are opened or get new commits.':
    'リポジトリのコードレビューを有効にすると、プルリクエストの作成時と新しいコミットの追加時にレビューされます。',
  'Turn on code review for every repository in {organization} ({count}).':
    '{organization} のすべてのリポジトリ（{count} 件）でコードレビューを有効にします。',
  'To review repositories added to the installation later, turn on Review new repositories.':
    '後からインストールに追加されたリポジトリもレビューするには、「新しいリポジトリをレビュー」を有効にしてください。',
  'Review pull requests in {name}': '{name} のプルリクエストをレビュー',

  // Review strength
  Strength: '強度',
  Basic: 'ベーシック',
  Normal: 'ノーマル',
  Expert: 'エキスパート',
  'Cheaper models that read the code without running it':
    'コードを実行せずに読む低コストのモデル',
  'The default models, running commands in a sandbox':
    '標準のモデル。サンドボックスでコマンドを実行します',
  'Stronger models that split the work across subagents':
    '作業をサブエージェントに分担する高性能なモデル',
  '{credits} credits per review': 'レビューごとに {credits} クレジット',
  'Review strength updated': 'レビュー強度を更新しました',
  'Failed to update review strength': 'レビュー強度を更新できませんでした',
  'Review strength for {name}': '{name} のレビュー強度',

  // New repositories
  'Review new repositories': '新しいリポジトリをレビュー',
  'Turn on code review for repositories added to {organization} on GitHub later.':
    '後から GitHub の {organization} に追加されたリポジトリのコードレビューを有効にします。',
  'New repositories will be reviewed': '新しいリポジトリをレビューします',
  'New repositories will not be reviewed': '新しいリポジトリはレビューしません',
  'Failed to update new repositories':
    '新しいリポジトリの設定を更新できませんでした',

  // Learnings
  Learnings: '学習',
  learnings: '件の学習',
  Learning: '学習内容',
  'Asked by': '依頼者',
  'Learning actions': '学習の操作',
  'Learning has been deleted': '学習を削除しました',
  'Failed to delete learning': '学習を削除できませんでした',
  'No learnings yet': '学習はまだありません',
  'When someone asks the reviewer to remember a preference in a pull request conversation, it shows up here. Later reviews of the repository follow it.':
    'プルリクエストの会話でレビュアーに好みを覚えるよう依頼すると、ここに表示されます。以降のリポジトリのレビューはそれに従います。',
  'Delete learning': '学習を削除',
  'Later reviews and answers will no longer follow this learning. This action cannot be undone.':
    '以降のレビューと回答はこの学習に従わなくなります。この操作は元に戻せません。',

  // Performance
  Performance: 'パフォーマンス',
  'Unknown author': '作成者不明',
  Outcome: '結果',
  'Still open': '未対応',
  'Reviews queued before authors were recorded':
    '作成者の記録開始前にキューに入ったレビュー',
  Bot: 'ボット',
  '{rate} first try': '初回で{rate}',
  '{count} critical': '重大 {count} 件',
  '{count} fixed': '{count} 件修正',
  '{count} reviews': '{count} 件のレビュー',
  'Date range': '期間',
  Author: '作成者',
  'Pull requests': 'プルリクエスト',
  'First try': '初回承認',
  'Findings / PR': '指摘 / PR',
  'Critical / major': '重大 / 重要',
  Fixed: '修正済み',
  'Last review': '最新のレビュー',
  'Pull requests reviewed': 'レビューしたプルリクエスト',
  '{count} authors': '{count} 人の作成者',
  '{rate} approved by the first review': '{rate} が初回のレビューで承認',
  'Findings per pull request': 'プルリクエストあたりの指摘',
  '{critical} critical, {major} major': '重大 {critical} 件、重要 {major} 件',
  '{count} findings fixed by a later commit':
    '{count} 件の指摘が後のコミットで修正',
  'No completed reviews in this range':
    'この期間に完了したレビューはありません',
  'Performance shows how the authors of your pull requests fare in review once reviews complete. Try a longer range or another repository.':
    'レビューが完了すると、プルリクエストの作成者ごとのレビュー結果がここに表示されます。より長い期間か別のリポジトリをお試しください。',

  // Performance: trends, comparison and charts
  'Compared with the {count} days before': '直前の {count} 日間との比較',
  'Review time': 'レビュー時間',
  median: '中央値',
  Authors: '作成者',
  'How each author’s pull requests fared in review. Select an author to see their reviews.':
    '作成者ごとのプルリクエストのレビュー結果です。作成者を選択すると、その作成者のレビューを表示します。',
  'Author actions': '作成者の操作',
  'Ranges are whole days in UTC, today included, and changes compare each figure with the same number of days before.':
    '期間は今日を含む UTC の日単位で、増減は各数値を直前の同じ日数と比較したものです。',
  'Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.':
    '承認と信頼度は各プルリクエストの最新のレビューに基づき、修正済みは後のコミットで解決された指摘を数えます。',
  'Review time is the median from a review being queued to it completing.':
    'レビュー時間は、レビューがキューに入ってから完了するまでの時間の中央値です。',
  '{resolved} fixed, {open} open, {dismissed} dismissed':
    '修正 {resolved} 件、未対応 {open} 件、却下 {dismissed} 件',
  Noisy: '却下が多い',
  'Most settled findings in this category were dismissed. A learning can tell the reviewer to flag fewer of them.':
    'このカテゴリで決着した指摘の大半が却下されました。学習で、この種の指摘を控えるようレビュアーに伝えられます。',
  'Completed reviews per week, by verdict': '週ごとの完了したレビュー (判定別)',
  'Completed reviews per day, by verdict': '日ごとの完了したレビュー (判定別)',
  'Findings posted per week, by severity': '週ごとの投稿された指摘 (重要度別)',
  'Findings posted per day, by severity': '日ごとの投稿された指摘 (重要度別)',
  Chart: 'グラフ',
  'No findings in this range': 'この期間に指摘はありません',
  'Critical and major': '重大・高',
  'Minor and info': '低・情報',

  // Finding outcomes
  'Useful findings': '有用な指摘',
  Useful: '有用',
  Dismissed: '却下',
  'Findings by category': 'カテゴリ別の指摘',
  'Categories that are often dismissed are candidates for a learning that tells the reviewer to flag them less.':
    'よく却下されるカテゴリは、レビュアーに指摘を控えさせる学習の候補です。',
  'Useful findings is the share of settled findings that were fixed rather than dismissed; findings still open count for neither.':
    '有用な指摘は、決着した指摘のうち却下されずに修正されたものの割合です。未解決の指摘はどちらにも数えません。',
  // Functions and sites: code review of the connected repository
  'Code review': 'コードレビュー',
  'Review pull requests': 'プルリクエストをレビュー',
  'Code review was not turned on': 'コードレビューを有効にできませんでした',
  'Turn it on later from Settings > Git.':
    '後から 設定 > Git で有効にできます。',
  'Code review is already on for this repository.':
    'このリポジトリではコードレビューがすでに有効です。',
  'Appwrite reviews each pull request when it is opened or gets new commits, and comments on it.':
    'Appwrite はプルリクエストが作成されたときや新しいコミットが追加されたときにレビューし、コメントします。',
  "Reviews use your organization's review credits.":
    'レビューには組織のレビュークレジットが使われます。',
  'Review pull requests to the connected repository and comment on them. The setting applies to the whole repository.':
    '接続したリポジトリへのプルリクエストをレビューし、コメントします。この設定はリポジトリ全体に適用されます。',
  'Each pull request is reviewed when it is opened or gets new commits.':
    'プルリクエストは作成時と新しいコミットの追加時にレビューされます。',
  'Review strength': 'レビュー強度',
  'How hard pull requests are reviewed, and what each review costs.':
    'プルリクエストをどこまで深くレビューするか、またレビューごとのコストです。',
  'View reviews': 'レビューを表示',
  'Connect a repository to this function to review its pull requests.':
    'この関数にリポジトリを接続すると、プルリクエストをレビューできます。',
  'Connect a repository to this site to review its pull requests.':
    'このサイトにリポジトリを接続すると、プルリクエストをレビューできます。',
  'Code review does not run on this provider':
    'このプロバイダーではコードレビューを利用できません',
  'Code review runs on GitHub, GitLab, Gitea, Bitbucket and Origin repositories.':
    'コードレビューは GitHub、GitLab、Gitea、Bitbucket、Origin のリポジトリで利用できます。',
  'Code review is off': 'コードレビューは無効です',
  'Turn on code review to have each pull request reviewed when it is opened or gets new commits.':
    'コードレビューを有効にすると、プルリクエストの作成時と新しいコミットの追加時にレビューされます。',
  'Pull requests that change files in':
    '次のディレクトリのファイルを変更するプルリクエスト:',
  'Reviews show up here when a pull request that changes this directory is opened or gets new commits.':
    'このディレクトリを変更するプルリクエストが作成されるか、新しいコミットが追加されると、ここにレビューが表示されます。',
  'Reviews show up here when a pull request is opened or gets new commits.':
    'プルリクエストが作成されるか、新しいコミットが追加されると、ここにレビューが表示されます。',
  'No reviews of this directory among the latest ones.':
    '最新のレビューの中に、このディレクトリのレビューはありません。',

  // Tabs: repositories, learnings, findings and credits
  'How each repository’s pull requests fared in review. Select a repository to narrow the tab to it.':
    'リポジトリごとのプルリクエストのレビュー結果です。リポジトリを選択すると、このタブをそのリポジトリに絞り込みます。',
  '{count} repositories': '{count} 件のリポジトリ',
  'Repository actions': 'リポジトリの操作',
  'Reviewed repositories': 'レビュー対象のリポジトリ',
  'Pull requests reviewed in the last 30 days. Select a repository to see its reviews.':
    '過去 30 日間にレビューしたプルリクエストです。リポジトリを選択するとそのレビューを表示します。',
  'Turn on code review for a repository right where you find it. Reviewed ones show their last 30 days.':
    '見つけたリポジトリでそのままコードレビューを有効にできます。レビュー対象のリポジトリには過去 30 日間の結果を表示します。',
  'Connect GitHub, GitLab, Gitea, Bitbucket or Origin to the project, then pick the repositories to review.':
    'GitHub、GitLab、Gitea、Bitbucket、Origin のいずれかをプロジェクトに接続し、レビューするリポジトリを選択してください。',
  'Connect a Git provider to the project to review its repositories.':
    'リポジトリをレビューするには、Git プロバイダーをプロジェクトに接続してください。',
  'No learnings for this repository': 'このリポジトリの学習はありません',
  'Ask the reviewer to remember a preference in one of its pull requests, and it shows up here.':
    'このリポジトリのプルリクエストでレビュアーに好みを覚えるよう依頼すると、ここに表示されます。',
  'Show all repositories': 'すべてのリポジトリを表示',
  'What the reviewer was asked to remember in pull request conversations. Later reviews and answers of the repository follow them.':
    'プルリクエストの会話でレビュアーに覚えるよう依頼した内容です。以降のリポジトリのレビューと回答はこれに従います。',
  'Show this repository only': 'このリポジトリのみ表示',
  'Open conversation': '会話を開く',
  'Open on {provider}': '{provider} で開く',
  'Most severe: {severity}': '最も重大: {severity}',
  'Review credits this cycle (this project)':
    '今期のレビュークレジット (このプロジェクト)',
  'Review credits this cycle': '今期のレビュークレジット',
  'All included credits are used. New pull requests are not reviewed until the next billing cycle.':
    '含まれるクレジットをすべて使用しました。次の請求サイクルまで新しいプルリクエストはレビューされません。',
  'All included credits are used. New pull requests are not reviewed until the next billing cycle or an upgrade.':
    '含まれるクレジットをすべて使用しました。次の請求サイクルまたはアップグレードまで、新しいプルリクエストはレビューされません。',
  '{extra} extra credits so far, about ${cost} on your next invoice.':
    'これまでの追加クレジットは {extra}、次回の請求書で約 ${cost} です。',
  'Extra credits past the allowance are billed with your usage, up to your budget cap.':
    '上限を超えた追加クレジットは、予算の上限まで使用量とともに請求されます。',
  'Reviews stop when the included credits are used, so you are never billed for extra credits.':
    '含まれるクレジットを使い切るとレビューが停止するため、追加クレジットが請求されることはありません。',
  'A normal review costs 50 credits. Answers to comments are free.':
    '通常のレビューは 50 クレジットです。コメントへの回答は無料です。',
  'Manage budget': '予算を管理',
  'Upgrade for more credits': 'アップグレードしてクレジットを追加',

  // Row menus
  'Copy text': 'テキストをコピー',
  'Copy title': 'タイトルをコピー',
  'Open profile on GitHub': 'GitHub でプロフィールを開く',
  'Open file': 'ファイルを開く',
  'Open comment': 'コメントを開く',
}
