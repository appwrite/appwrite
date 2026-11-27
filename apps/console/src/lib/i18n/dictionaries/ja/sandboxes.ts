/**
 * Japanese translations for the Sandboxes product (live workspaces with a
 * terminal and a file browser: sandboxes list, create dialog and detail).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaSandboxesDictionary: Record<string, string> = {
  // Navigation and titles
  Sandboxes: 'サンドボックス',
  sandboxes: 'サンドボックス',
  'Back to sandboxes': 'サンドボックス一覧に戻る',
  'Sandbox ID': 'サンドボックス ID',
  'Sandbox not found': 'サンドボックスが見つかりません',
  'This sandbox may have been deleted, or it was idle long enough to be deleted.':
    'このサンドボックスは削除されたか、使用されない時間が続いたため削除された可能性があります。',

  // List and empty state
  'Run code in a sandbox': 'サンドボックスでコードを実行',
  'A sandbox is a live workspace with a terminal and a file system. Create one to run commands, start processes and manage files, from the Console or the API.':
    'サンドボックスは、ターミナルとファイルシステムを備えたライブのワークスペースです。作成すると、Console または API からコマンドの実行、プロセスの起動、ファイルの管理ができます。',
  Creating: '作成中',
  "You don't have permission to create sandboxes.":
    'サンドボックスを作成する権限がありません。',

  // Create dialog
  'Create sandbox': 'サンドボックスを作成',
  'A live workspace you can open a terminal in, run commands in and manage files in. It is deleted after it has been idle for 15 minutes.':
    'ターミナルを開き、コマンドを実行し、ファイルを管理できるライブのワークスペースです。15 分間使用されないと削除されます。',
  'One NAME=value per line. Every command, process and terminal in the sandbox inherits them. They are never stored or shown again.':
    '1 行に 1 つずつ NAME=value の形式で入力します。サンドボックス内のすべてのコマンド、プロセス、ターミナルに引き継がれます。保存されることはなく、再表示もされません。',
  'Use NAME=value. Names use letters, digits and underscores, cannot start with a digit or SANDBOX_.':
    'NAME=value の形式で入力してください。名前には英字、数字、アンダースコアを使用でき、数字または SANDBOX_ で始めることはできません。',
  'A sandbox takes at most 100 variables.':
    'サンドボックスに設定できる変数は最大 100 個です。',
  'Sandbox created': 'サンドボックスを作成しました',

  // Delete
  'Delete sandbox': 'サンドボックスを削除',
  'The sandbox, its files and its processes are deleted. This cannot be undone.':
    'サンドボックスとそのファイル、プロセスが削除されます。この操作は元に戻せません。',
  'Sandbox deleted': 'サンドボックスを削除しました',

  // Detail
  'Starting sandbox...': 'サンドボックスを起動しています...',
  'Deleting sandbox...': 'サンドボックスを削除しています...',
  'Sandbox failed to start': 'サンドボックスの起動に失敗しました',
  'Delete it and create a new one.': '削除して、新しく作成してください。',
  "You don't have permission to run commands in sandboxes.":
    'サンドボックスでコマンドを実行する権限がありません。',

  // Terminal
  'Starting terminal...': 'ターミナルを起動しています...',
  'Restart terminal': 'ターミナルを再起動',
  'Process exited with code': 'プロセスが終了しました。終了コード',

  // Files
  Workspace: 'ワークスペース',
  'This folder is empty.': 'このフォルダーは空です。',
  'Files can be at most 5 MB.': 'ファイルサイズの上限は 5 MB です。',
  'Delete folder': 'フォルダーを削除',
  'Folder deleted': 'フォルダーを削除しました',
  'The folder and everything in it are deleted.':
    'フォルダーとその中のすべてが削除されます。',
  'The file is deleted from the sandbox.':
    'ファイルがサンドボックスから削除されます。',
}
