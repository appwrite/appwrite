/**
 * Japanese translations for the Videos product (list, profiles, detail tabs,
 * stream player, and debug panel).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaVideosDictionary: Record<string, string> = {
  // Navigation and titles
  Videos: '動画',
  Video: '動画',
  Profiles: 'プロファイル',
  Renditions: 'レンディション',
  Rendition: 'レンディション',
  Subtitles: '字幕',
  'Back to videos': '動画一覧に戻る',
  'Untitled video': '無題の動画',
  'Video not found': '動画が見つかりません',
  "The video you're looking for doesn't exist or you don't have access to it.":
    'お探しの動画は存在しないか、アクセス権がありません。',
  'Loading video...': '動画を読み込んでいます...',
  'Loading videos...': '動画を読み込んでいます...',
  'Loading profiles...': 'プロファイルを読み込んでいます...',
  'Search videos...': '動画を検索...',
  'Search profiles...': 'プロファイルを検索...',
  video: '動画',
  videos: '動画',
  'this video': 'この動画',
  optional: '任意',

  // List page
  'Create video': '動画を作成',
  'No videos yet': '動画はまだありません',
  'Create a video from a Storage file to encode adaptive HLS, DASH, and CMAF streams.':
    'ストレージのファイルから動画を作成し、アダプティブな HLS、DASH、CMAF ストリームにエンコードします。',
  'Failed to load videos. Please try again.':
    '動画を読み込めませんでした。もう一度お試しください。',
  "You don't have permission to create videos.":
    '動画を作成する権限がありません。',
  "You don't have permission to manage videos.":
    '動画を管理する権限がありません。',
  'Delete videos': '動画を削除',
  'Delete video': '動画を削除',
  'Video deleted': '動画を削除しました',
  'Failed to delete videos': '動画を削除できませんでした',
  'Failed to delete video': '動画を削除できませんでした',
  'Are you sure you want to delete this video? Its renditions, subtitles, and previews are removed. The source file in Storage is kept.':
    'この動画を削除しますか？レンディション、字幕、プレビューは削除されます。ストレージのソースファイルは残ります。',

  // Create video
  'Choose a video file from Storage. Appwrite downloads a working copy so you can encode renditions and stream it.':
    'ストレージから動画ファイルを選択してください。Appwrite が作業用コピーをダウンロードし、レンディションのエンコードと配信ができるようになります。',
  'Source file': 'ソースファイル',
  'Defaults to the file name': '未入力の場合はファイル名を使用します',
  'This file does not look like a video. The server may reject it.':
    'このファイルは動画ではない可能性があります。サーバーで拒否されることがあります。',
  'Select source video': 'ソース動画を選択',
  'Choose a bucket, then pick or upload a video file.':
    'バケットを選択し、動画ファイルを選ぶかアップロードしてください。',
  'Video created. Downloading source...':
    '動画を作成しました。ソースをダウンロードしています...',
  'Failed to create video': '動画を作成できませんでした',

  // Statuses
  Downloading: 'ダウンロード中',
  Aborted: '中止',
  Encoding: 'エンコード中',
  Encoded: 'エンコード済み',
  Uploading: 'アップロード中',

  // Profiles
  'Create profile': 'プロファイルを作成',
  'Update profile': 'プロファイルを更新',
  'Delete profile': 'プロファイルを削除',
  'Profile created': 'プロファイルを作成しました',
  'Profile updated': 'プロファイルを更新しました',
  'Profile deleted': 'プロファイルを削除しました',
  'Failed to save profile': 'プロファイルを保存できませんでした',
  'Failed to delete profile': 'プロファイルを削除できませんでした',
  'Failed to load profiles': 'プロファイルを読み込めませんでした',
  'No profiles': 'プロファイルがありません',
  'Create a profile to choose the resolution and bitrate of your renditions.':
    'プロファイルを作成して、レンディションの解像度とビットレートを指定します。',
  'Profiles define the resolution and bitrate of each rendition. Use them when encoding a video into adaptive streams.':
    'プロファイルは各レンディションの解像度とビットレートを定義します。動画をアダプティブストリームにエンコードする際に使用します。',
  "You don't have permission to manage video profiles.":
    '動画プロファイルを管理する権限がありません。',
  'Renditions already encoded with this profile stay playable. This action cannot be undone.':
    'このプロファイルでエンコード済みのレンディションは引き続き再生できます。この操作は元に戻せません。',
  Width: '幅',
  Height: '高さ',
  Resolution: '解像度',
  'Video bitrate': '映像ビットレート',
  'Audio bitrate': '音声ビットレート',
  Bitrate: 'ビットレート',

  // Filters
  'Video codec': '映像コーデック',
  'Audio codec': '音声コーデック',
  'Duration (ms)': '再生時間 (ms)',
  'Size (bytes)': 'サイズ (バイト)',

  // Detail layout
  'Working copy released': '作業用コピーを解放済み',
  'Appwrite releases the working copy after encoding. Existing renditions keep streaming. Download it again from Storage to create more renditions.':
    'Appwrite はエンコード完了後に作業用コピーを解放します。既存のレンディションは引き続きストリーミングできます。レンディションを追加で作成するには、ストレージから再度ダウンロードしてください。',
  'Source download failed': 'ソースのダウンロードに失敗しました',
  'New renditions need a working copy of the source file. Download it again from Storage to continue encoding.':
    '新しいレンディションにはソースファイルの作業用コピーが必要です。エンコードを続けるには、ストレージから再度ダウンロードしてください。',
  'Download again': '再ダウンロード',
  'Source download started': 'ソースのダウンロードを開始しました',
  'Failed to start source download':
    'ソースのダウンロードを開始できませんでした',
  'Wait until the source download finishes.':
    'ソースのダウンロードが完了するまでお待ちください。',
  'Download the source again before creating more renditions.':
    '追加のレンディションを作成する前に、ソースを再度ダウンロードしてください。',
  'Renditions can be created once the source download is ready.':
    'ソースのダウンロードが完了すると、レンディションを作成できます。',

  // Overview
  'Source is ready to encode': 'ソースのエンコード準備ができました',
  'Create renditions to stream this video with adaptive bitrate. Until then, the player uses the original file.':
    'レンディションを作成すると、この動画をアダプティブビットレートで配信できます。それまではプレーヤーは元のファイルを再生します。',

  // Renditions
  'Create renditions': 'レンディションを作成',
  'No renditions': 'レンディションがありません',
  'Encode the source into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.':
    'ソースを HLS、DASH、CMAF のレンディションにエンコードし、アダプティブビットレートで配信します。',
  'Encode the source into one rendition per profile. Progress updates live while the worker encodes.':
    'プロファイルごとに 1 つのレンディションをエンコードします。エンコード中の進捗はリアルタイムで更新されます。',
  Output: '出力',
  'Manage profiles': 'プロファイルを管理',
  'No profiles. Create a profile first.':
    'プロファイルがありません。先にプロファイルを作成してください。',
  Exists: '作成済み',
  Upscale: 'アップスケール',
  'MPEG-TS segments. Plays natively on Safari and iOS.':
    'MPEG-TS セグメント。Safari と iOS でネイティブ再生できます。',
  'MPEG-DASH with fragmented MP4 segments.':
    'fragmented MP4 セグメントを使用する MPEG-DASH。',
  'Fragmented MP4 served as both HLS and DASH.':
    'HLS と DASH の両方で配信される fragmented MP4。',
  'Rendition queued': 'レンディションをキューに追加しました',
  'renditions queued': '件のレンディションをキューに追加',
  'Failed to create renditions': 'レンディションを作成できませんでした',
  'Encoding time': 'エンコード時間',
  'Delete rendition': 'レンディションを削除',
  'Rendition deleted': 'レンディションを削除しました',
  'Failed to delete rendition': 'レンディションを削除できませんでした',
  'Failed to retry rendition': 'レンディションを再試行できませんでした',
  'Its segments are removed and players stop receiving this quality. This action cannot be undone.':
    'セグメントが削除され、プレーヤーはこの画質を受信しなくなります。この操作は元に戻せません。',

  // Subtitles
  'Create subtitle': '字幕を作成',
  'Update subtitle': '字幕を更新',
  'Delete subtitle': '字幕を削除',
  'Subtitle added': '字幕を追加しました',
  'Subtitle updated': '字幕を更新しました',
  'Subtitle deleted': '字幕を削除しました',
  'Default subtitle updated': 'デフォルトの字幕を更新しました',
  'Failed to save subtitle': '字幕を保存できませんでした',
  'Failed to delete subtitle': '字幕を削除できませんでした',
  'No subtitles': '字幕がありません',
  'No subtitles added yet.': '字幕はまだ追加されていません。',
  'Add WebVTT or SRT files from Storage. Every HLS, DASH, and CMAF manifest lists them as text tracks.':
    'ストレージから WebVTT または SRT ファイルを追加します。HLS、DASH、CMAF のすべてのマニフェストにテキストトラックとして掲載されます。',
  'Attach a WebVTT or SRT file from Storage. Subtitles are segmented to match the stream and listed in every manifest.':
    'ストレージから WebVTT または SRT ファイルを添付します。字幕はストリームに合わせてセグメント化され、すべてのマニフェストに掲載されます。',
  'Subtitle file': '字幕ファイル',
  'Subtitle files must be WebVTT (text/vtt), SRT (application/x-subrip), or plain text.':
    '字幕ファイルは WebVTT (text/vtt)、SRT (application/x-subrip)、またはプレーンテキストである必要があります。',
  'Select subtitle file': '字幕ファイルを選択',
  'Choose a bucket, then pick or upload a .vtt or .srt file.':
    'バケットを選択し、.vtt または .srt ファイルを選ぶかアップロードしてください。',
  Language: '言語',
  'Select language': '言語を選択',
  'Search languages...': '言語を検索...',
  'No languages found': '言語が見つかりません',
  "Shown in the player's subtitle menu. Letters, numbers, spaces, and - . , ( ) _ ' only.":
    "プレーヤーの字幕メニューに表示されます。半角英字、数字、スペース、- . , ( ) _ ' のみ使用できます。",
  'Default subtitle': 'デフォルトの字幕',
  'Players turn this subtitle on automatically.':
    'プレーヤーでこの字幕が自動的に表示されます。',
  'Make default': 'デフォルトに設定',
  Embedded: '埋め込み',
  'Storage file': 'ストレージのファイル',
  'The Storage file is kept. This action cannot be undone.':
    'ストレージのファイルは残ります。この操作は元に戻せません。',

  // Settings
  'Shown in the Console and returned by the API.':
    'コンソールに表示され、API から返されます。',
  'Video name updated': '動画名を更新しました',
  'Failed to update video': '動画を更新できませんでした',
  'The Storage file this video was created from. Deleting the video keeps the file.':
    'この動画の作成元となったストレージのファイルです。動画を削除してもファイルは残ります。',
  'Permanently delete this video with its renditions, subtitles, and previews. The source file in Storage is kept.':
    'この動画をレンディション、字幕、プレビューとともに完全に削除します。ストレージのソースファイルは残ります。',
  'Enter video name': '動画名を入力',

  // Player
  'Original file': '元のファイル',
  'No ready HLS renditions': '準備完了の HLS レンディションがありません',
  'No ready DASH renditions': '準備完了の DASH レンディションがありません',
  'No ready CMAF renditions': '準備完了の CMAF レンディションがありません',
  'No ready renditions': '準備完了のレンディションがありません',
  'Downloading source': 'ソースをダウンロード中',
  'Processing renditions': 'レンディションを処理中',
  'Some renditions failed': '一部のレンディションが失敗しました',
  'The worker downloads the Storage file into a working copy before encoding.':
    'ワーカーはエンコード前にストレージのファイルを作業用コピーとしてダウンロードします。',
  'Auto quality': '自動画質',
  'Subtitles off': '字幕オフ',
  'New renditions are ready.': '新しいレンディションの準備ができました。',
  'Reload stream': 'ストリームを再読み込み',
  'Playing the original Storage file. Switch to HLS, DASH, or CMAF to test adaptive streaming.':
    'ストレージの元のファイルを再生しています。アダプティブストリーミングを試すには HLS、DASH、または CMAF に切り替えてください。',
  'Playing the original Storage file. Create HLS, DASH, or CMAF renditions to test adaptive streaming.':
    'ストレージの元のファイルを再生しています。アダプティブストリーミングを試すには HLS、DASH、または CMAF のレンディションを作成してください。',
  'Generate timeline': 'タイムラインを生成',
  'Timeline generation started': 'タイムラインの生成を開始しました',
  'Failed to generate timeline': 'タイムラインを生成できませんでした',

  // Debug panel
  Playback: '再生',
  Player: 'プレーヤー',
  'Native video element': 'ネイティブ video 要素',
  Position: '再生位置',
  'Ready state': 'Ready state',
  'Network state': 'Network state',
  Buffer: 'バッファ',
  'Buffered ahead': '先読み済み',
  'Buffered ranges': 'バッファ済み範囲',
  'Buffer visualizer': 'バッファビジュアライザー',
  'Fully buffered': 'すべてバッファ済み',
  'Buffer ahead, last 60s': '先読みバッファ (直近60秒)',
  Audio: '音声',
  Healthy: '良好',
  'Low buffer': 'バッファ低下',
  'Buffer critical': 'バッファ不足',
  Played: '再生済み',
  Buffered: 'バッファ済み',
  'Adaptive bitrate': 'アダプティブビットレート',
  'Current level': '現在のレベル',
  'Loading level': '読み込み中のレベル',
  'Next level': '次のレベル',
  'Level selection': 'レベル選択',
  Automatic: '自動',
  'Bandwidth estimate': '推定帯域幅',
  Latency: 'レイテンシ',
  'Dropped frames': 'ドロップフレーム',
  'Decoded resolution': 'デコード解像度',
  'Start playback to collect stream statistics.':
    '再生を開始するとストリームの統計情報を収集します。',
  Levels: 'レベル',
  Level: 'レベル',
  Playing: '再生中',
  Lock: '固定',
  'No levels parsed yet.': 'まだレベルが解析されていません。',
  'Quality levels are only available for adaptive streams.':
    '画質レベルはアダプティブストリームでのみ利用できます。',
  Segments: 'セグメント',
  'No segments loaded yet.': 'まだセグメントが読み込まれていません。',
  'Load time': '読み込み時間',
  Throughput: 'スループット',
  'Event log': 'イベントログ',
  'Player lifecycle events, level switches, and errors (newest first).':
    'プレーヤーのライフサイクルイベント、レベル切り替え、エラー (新しい順)。',
  'No events recorded yet.': 'まだイベントは記録されていません。',
  Manifests: 'マニフェスト',
  'Master manifests': 'マスターマニフェスト',
  'Media playlists': 'メディアプレイリスト',
  'Public playback URLs. Clients need read access to the source file; the console adds admin mode.':
    '公開再生 URL です。クライアントにはソースファイルの読み取り権限が必要です。コンソールでは admin モードが付加されます。',
  Reload: '再読み込み',
  Container: 'コンテナ',
  'Source bucket': 'ソースバケット',
  'Preview ID': 'プレビュー ID',
  'Aspect ratio': 'アスペクト比',
  'Video stream': '映像ストリーム',
  'Audio stream': '音声ストリーム',
  Codec: 'コーデック',
  Codecs: 'コーデック',
  'Format profile': 'フォーマットプロファイル',
  'Frame rate': 'フレームレート',
  'Sample rate': 'サンプルレート',
  Chunks: 'チャンク',
  'Target duration': 'ターゲット長',
  Elapsed: '経過時間',
  'No renditions requested yet.':
    'まだレンディションはリクエストされていません。',
  'No timeline yet. Generate sprite thumbnails for scrubbing previews and the video poster.':
    'タイムラインはまだありません。シークプレビューと動画ポスター用のスプライトサムネイルを生成してください。',
  'Generating sprite timeline. This view refreshes automatically.':
    'スプライトタイムラインを生成しています。この画面は自動的に更新されます。',
  'Select a thumbnail to seek the player.':
    'サムネイルを選択するとプレーヤーがその位置に移動します。',
  thumbnails: 'サムネイル',
  'Time to manifest': 'マニフェストまでの時間',
  'Time to first frame': '最初のフレームまでの時間',
  'Data source': 'データソース',
  'Inspect live playback health: buffer, timing, dropped frames, and which variant Shaka is using.':
    'ライブ再生の状態 (バッファ、タイミング、ドロップフレーム、Shaka が使用中の variant) を確認します。',
  'Lists every adaptive variant from the manifest. Lock a row to disable ABR and pin playback to that variant.':
    'マニフェスト内のすべてのアダプティブ variant を一覧表示します。行を固定すると ABR を無効にし、その variant に再生を固定します。',
  'Shows the most recent media segments fetched for the current stream, with size and load timing.':
    '現在のストリームで取得した最新のメディアセグメントを、サイズと読み込み時間付きで表示します。',
  'Chronological log of player lifecycle, adaptation, and errors (newest first).':
    'プレーヤーのライフサイクル、adaptation、エラーの時系列ログ (新しい順)。',
  'Public manifest URLs for HLS and DASH outputs, plus raw playlist or MPD text for debugging clients.':
    'HLS と DASH 出力の公開マニフェスト URL と、クライアントデバッグ用の playlist / MPD 生テキスト。',
  'Container and stream metadata from the Videos probe when the source file was ingested.':
    'ソースファイル取り込み時の Videos プローブによるコンテナとストリームのメタデータ。',
  'Worker state for the source download and every rendition and subtitle encoding job.':
    'ソースのダウンロードと各レンディション・字幕エンコードジョブの worker 状態。',
  'Sprite thumbnail cues from the timeline WebVTT. Select a thumbnail to seek the player.':
    'タイムライン WebVTT のスプライトサムネイル cue。サムネイルを選ぶとプレーヤーがシークします。',
}
