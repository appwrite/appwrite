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
  'Add a GitHub repository and every pull request gets a review with findings, a verdict, and a merge confidence grade. Mention the app in a comment to ask it anything.':
    'GitHub リポジトリを追加すると、すべてのプルリクエストに指摘事項、判定、マージの信頼度を含むレビューが付きます。コメントでアプリにメンションすると、何でも質問できます。',
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
  'Open on GitHub': 'GitHub で開く',

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
  'Connect a GitHub installation to the project to review its repositories.':
    'リポジトリをレビューするには、GitHub のインストールをプロジェクトに接続してください。',
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
  'Search GitHub repositories...': 'GitHub リポジトリを検索...',
  'No repositories match your search': '検索に一致するリポジトリはありません',
  'No repositories reviewed yet': 'レビュー対象のリポジトリはまだありません',
  'No GitHub installation': 'GitHub のインストールがありません',
  'Connect GitHub to review pull requests':
    'GitHub を接続してプルリクエストをレビュー',
  'Install the GitHub app on your account or organization, then pick the repositories to review.':
    'アカウントまたは組織に GitHub アプリをインストールし、レビューするリポジトリを選択してください。',
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
  'Open conversation on GitHub': 'GitHub で会話を開く',
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
  'Approval rate': '承認率',
  '{rate} approved by the first review': '{rate} が初回のレビューで承認',
  'Findings per pull request': 'プルリクエストあたりの指摘',
  '{critical} critical, {major} major': '重大 {critical} 件、重要 {major} 件',
  'Fix rate': '修正率',
  '{count} findings fixed by a later commit':
    '{count} 件の指摘が後のコミットで修正',
  'No completed reviews in this range':
    'この期間に完了したレビューはありません',
  'Performance shows how the authors of your pull requests fare in review once reviews complete. Try a longer range or another repository.':
    'レビューが完了すると、プルリクエストの作成者ごとのレビュー結果がここに表示されます。より長い期間か別のリポジトリをお試しください。',
  'Counts completed reviews in the range. Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.':
    '期間内に完了したレビューを集計します。承認と信頼度は各プルリクエストの最新のレビューに基づき、修正済みは後のコミットで解決された指摘を数えます。',

  // Finding outcomes
  'Useful findings': '有用な指摘',
  '{resolved} fixed, {dismissed} dismissed':
    '修正 {resolved} 件、却下 {dismissed} 件',
  Useful: '有用',
  Dismissed: '却下',
  'Findings by category': 'カテゴリ別の指摘',
  'Categories that are often dismissed are candidates for a learning that tells the reviewer to flag them less.':
    'よく却下されるカテゴリは、レビュアーに指摘を控えさせる学習の候補です。',
  'Useful findings is the share of settled findings that were fixed rather than dismissed; findings still open count for neither.':
    '有用な指摘は、決着した指摘のうち却下されずに修正されたものの割合です。未解決の指摘はどちらにも数えません。',
}
