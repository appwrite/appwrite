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
}
