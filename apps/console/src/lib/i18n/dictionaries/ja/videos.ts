/**
 * Japanese translations for the Videos product (list, profiles, detail tabs,
 * stream player, and debug panel).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const jaVideosDictionary: Record<string, string> = {
  // Navigation and titles
  Videos: '動画',
  'On demand': 'オンデマンド',
  'On demand and live': 'オンデマンドとライブ',
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
  'No profiles match your search': '検索に一致するプロファイルがありません',
  'No profiles match your filters':
    'フィルターに一致するプロファイルがありません',
  'Try a different search term or clear the search.':
    '別のキーワードで検索するか、検索をクリアしてください。',
  profiles: 'プロファイル',
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
  'Source file': 'ソースファイル',
  'Defaults to the file name': '未入力の場合はファイル名を使用します',
  'This file does not look like a video. The server may reject it.':
    'このファイルは動画ではない可能性があります。サーバーで拒否されることがあります。',
  'Select source video': 'ソース動画を選択',
  'Choose a bucket, then pick or upload a video file.':
    'バケットを選択し、動画ファイルを選ぶかアップロードしてください。',
  'Failed to create video': '動画を作成できませんでした',

  // Statuses
  Downloading: 'ダウンロード中',
  Aborted: '中止',
  Encoding: 'エンコード中',
  'Not encoded': '未エンコード',
  Encoded: 'エンコード済み',
  Uploading: 'アップロード中',

  // Profiles
  'Create profile': 'プロファイルを作成',
  Presets: 'プリセット',
  'Custom profile': 'カスタムプロファイル',
  'Create custom profile': 'カスタムプロファイルを作成',
  'Choose a preset to add a rendition quality. Video codec is chosen by the server when encoding.':
    'プリセットを選んでレンディションの画質を追加します。動画コーデックはエンコード時にサーバーが選択します。',
  'Set the resolution and bitrate of the rendition. Video codec is chosen by the server when encoding.':
    'レンディションの解像度とビットレートを設定します。動画コーデックはエンコード時にサーバーが選択します。',
  'Update profile': 'プロファイルを更新',
  'Delete profile': 'プロファイルを削除',
  'Profile created': 'プロファイルを作成しました',
  'Profile updated': 'プロファイルを更新しました',
  'Profile deleted': 'プロファイルを削除しました',
  'Failed to save profile': 'プロファイルを保存できませんでした',
  'Failed to delete profile': 'プロファイルを削除できませんでした',
  'Failed to load profiles': 'プロファイルを読み込めませんでした',
  'The console calls GET /videos/profiles on your project API. A server error here usually means the Videos service failed to load or seed profiles for this project, not a problem with your browser.':
    'コンソールはプロジェクト API の GET /videos/profiles を呼び出します。ここでサーバーエラーになる場合、ブラウザではなく、このプロジェクトの Videos サービスがプロファイルの読み込みまたはシードに失敗している可能性が高いです。',
  'No profiles': 'プロファイルがありません',
  'Create a profile to choose the resolution and bitrate of your renditions.':
    'プロファイルを作成して、レンディションの解像度とビットレートを指定します。',
  'Profiles define the resolution and bitrate of each rendition. Use them when encoding a video into adaptive streams.':
    'プロファイルは各レンディションの解像度とビットレートを定義します。動画をアダプティブストリームにエンコードする際に使用します。',
  'Profiles define the resolution and bitrate of each rendition. Video codec is chosen by the server when encoding. Use profiles when creating adaptive streams.':
    'プロファイルは各レンディションの解像度とビットレートを定義します。映像コーデックはエンコード時にサーバー側で決まります。アダプティブストリームを作成するときに使用します。',
  'This video has no detected audio track after prepare. Adaptive streams and the original file will play without sound. Re-upload a file with an audio track or check the source in storage.':
    '準備後、この動画から音声トラックは検出されませんでした。アダプティブストリームと元ファイルは無音で再生されます。音声付きファイルを再アッロードするか、ストレージのソースを確認してください。',
  'No audio track was found when the file was probed. Encoding profiles cannot add audio. Replace the storage file with one that includes audio and create a new video, or confirm the original plays with sound on your device.':
    'ファイルの解析時に音声トラックは見つかりませんでした。エンコードプロファイルで音声は追加できません。音声付きファイルに差し替えて新しい動画を作成するか、元ファイルが端末で音声付きで再生されるか確認してください。',
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

  // Overview
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
  'Available once a rendition is ready.':
    'レンディションの準備ができると利用できます。',
  Source: 'ソース',
  'Stream format': 'ストリーム形式',
  'Original file': '元のファイル',
  'No ready HLS renditions': '準備完了の HLS レンディションがありません',
  'No ready DASH renditions': '準備完了の DASH レンディションがありません',
  'No ready CMAF renditions': '準備完了の CMAF レンディションがありません',
  'No ready renditions': '準備完了のレンディションがありません',
  'Processing renditions': 'レンディションを処理中',
  'View renditions': 'レンディションを表示',
  '{encoding} encoding · {ready} of {total} ready':
    '{encoding} 件をエンコード中 · {ready}/{total} 件完了',
  '{failed} failed · {ready} of {total} ready':
    '{failed} 件失敗 · {ready}/{total} 件完了',
  'Some renditions failed': '一部のレンディションが失敗しました',
  'Auto quality': '自動画質',
  'Subtitles off': '字幕オフ',
  'New renditions are ready.': '新しいレンディションの準備ができました。',
  'Reload stream': 'ストリームを再読み込み',
  'Go to start': '先頭に戻る',
  'Go to end': '末尾に移動',
  Mute: 'ミュート',
  Unmute: 'ミュート解除',
  Volume: '音量',
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
  Stats: '統計',
  Level: 'レベル',
  Lock: '固定',
  Reload: '再読み込み',
  Throughput: 'スループット',
  'Waiting for the player': 'プレーヤーを待機中',
  "The inspector follows the video playing on a video's Overview page. Open one to continue inspecting.":
    'インスペクターは動画の概要ページで再生中の動画を追跡します。概要ページを開いて検査を続けてください。',
  'Go to overview': '概要へ移動',
  Inspector: 'インスペクター',
  'Close inspector': 'インスペクターを閉じる',
  'Show window': 'ウィンドウを表示',
  'The inspector is open in a separate window.':
    'インスペクターは別ウィンドウで開いています。',
  'Your browser blocked the window. Allow pop-ups for this site to open the inspector.':
    'ブラウザーがウィンドウをブロックしました。インスペクターを開くには、このサイトのポップアップを許可してください。',
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
  'Worker state for rendition and subtitle encoding jobs.':
    'レンディションと字幕エンコードジョブの worker 状態。',
  'Create your first renditions': '最初のレンディションを作成',
  'Encode this video into HLS, DASH, or CMAF for adaptive streaming. Until then, the player uses the original Storage file.':
    'アダプティブストリーミング用に HLS、DASH、または CMAF にエンコードしてください。それまではプレーヤーは Storage の元ファイルを使用します。',
  'Encode this video into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.':
    'アダプティブビットレートで配信するには、この動画を HLS、DASH、または CMAF レンディションにエンコードしてください。',
  'Select codec': 'コーデックを選択',
  'Video created': '動画を作成しました',
  'Choose a video file from Storage. Appwrite probes the file and lets you encode renditions for adaptive streaming.':
    'Storage から動画ファイルを選びます。Appwrite がファイルをプローブし、アダプティブストリーミング用のレンディションをエンコードできます。',
  'Sprite thumbnail cues from the timeline WebVTT. Select a thumbnail to seek the player.':
    'タイムライン WebVTT のスプライトサムネイル cue。サムネイルを選ぶとプレーヤーがシークします。',

  // Workspace: sidebar, submenu, and welcome state
  'All videos': 'すべての動画',
  Breadcrumb: 'パンくずリスト',
  'Video sections': '動画のセクション',
  Media: 'メディア',
  Streaming: 'ストリーミング',
  Debugger: 'デバッガー',
  'Encoding profiles': 'エンコードプロファイル',
  'Select a video': '動画を選択',
  'Stream video with Appwrite': 'Appwrite で動画をストリーミング',
  'Pick a video from the list to play it, encode renditions, add subtitles, and get streaming URLs.':
    'リストから動画を選ぶと、再生、レンディションのエンコード、字幕の追加、ストリーミング URL の取得ができます。',
  'Turn files in Storage into adaptive streams that play smoothly on any device and connection.':
    'ストレージのファイルを、あらゆるデバイスと回線でスムーズに再生できるアダプティブストリームに変換します。',
  'Create a video': '動画を作成',
  'Pick a video or audio file you uploaded to Storage. The file stays in its bucket.':
    'ストレージにアップロードした動画または音声ファイルを選びます。ファイルはバケットに残ります。',
  'Give the manifest URL to any HLS or DASH player. Quality adapts to each viewer.':
    'マニフェスト URL を HLS または DASH プレーヤーに渡します。画質は視聴者ごとに自動で調整されます。',
  'Stream it': 'ストリーミング',
  'Sort videos': '動画を並べ替え',
  'No videos match your search.': '検索に一致する動画はありません。',
  'No videos match your filters.': 'フィルターに一致する動画はありません。',
  'No videos yet. Create one from a Storage file.':
    'まだ動画がありません。ストレージのファイルから作成してください。',
  'Live streaming is coming soon. Ingest a live feed and deliver it with the same HLS and DASH outputs.':
    'ライブストリーミングは近日公開予定です。ライブ映像を取り込み、同じ HLS と DASH 出力で配信できます。',
  'Duration (longest first)': '再生時間 (長い順)',
  'Duration (shortest first)': '再生時間 (短い順)',
  'Resolution (highest first)': '解像度 (高い順)',
  'Resolution (lowest first)': '解像度 (低い順)',
  'Size (largest first)': 'サイズ (大きい順)',
  'Size (smallest first)': 'サイズ (小さい順)',
  'Res.': '解像度',
  Preparing: '準備中',
  'Source failed': 'ソースの準備に失敗',
  'Timeline generated': 'タイムライン生成済み',
  'Time range': '時間範囲',
  'Sprite region': 'スプライト領域',
  'Scroll through the thumbnails and hover one to see its time range and sprite region. Select one to use it as the preview image.':
    'サムネイルをスクロールし、カーソルを合わせると時間範囲とスプライト領域が表示されます。選択するとプレビュー画像として使用します。',
  'The frame shown before playback starts. Select a thumbnail above or scrub through the video to choose it.':
    '再生開始前に表示されるフレームです。上のサムネイルを選択するか、動画をスクラブして選んでください。',
  'Generate a timeline to choose a preview image.':
    'プレビュー画像を選ぶにはタイムラインを生成してください。',
  'Selected frame': '選択中のフレーム',
  'Previous frame': '前のフレーム',
  'Next frame': '次のフレーム',
  'Preview frame': 'プレビューフレーム',
  'Set as preview': 'プレビューに設定',
  "Saving a preview frame isn't available yet. The Videos API can't set a video's poster frame.":
    'プレビューフレームの保存はまだ利用できません。Videos API はまだ動画のポスターフレームを設定できません。',
  'Sprite sheet': 'スプライトシート',
  'Ready to stream': 'ストリーミング可能',
  'HLS manifest URL': 'HLS マニフェスト URL',
  'Copy HLS manifest URL': 'HLS マニフェスト URL をコピー',

  // Source preparation
  chunks: 'チャンク',
  'Choose a video or audio file from Storage. The file stays in its bucket, and you can encode renditions for adaptive streaming.':
    'ストレージから動画または音声ファイルを選びます。ファイルはバケットに残り、アダプティブストリーミング用のレンディションをエンコードできます。',
  'This file does not look like video or audio. The server may reject it.':
    'このファイルは動画や音声ではないようです。サーバーに拒否される可能性があります。',

  // Overview
  'Play the video, follow processing, and see what the source file contains.':
    '動画の再生、処理状況の確認、ソースファイルの内容の確認ができます。',
  'Debug playback': '再生をデバッグ',
  'Renditions ready': '準備完了のレンディション',
  'Source size': 'ソースサイズ',
  'Source details': 'ソースの詳細',
  'Encode renditions': 'レンディションをエンコード',
  'Pick profiles and an output format such as HLS.':
    'プロファイルと HLS などの出力形式を選びます。',
  'Add subtitles': '字幕を追加',
  'Upload WebVTT or SRT files per language.':
    '言語ごとに WebVTT または SRT ファイルをアップロードします。',
  'Add subtitle': '字幕を追加',
  'Generate a timeline': 'タイムラインを生成',
  'Thumbnails for scrubbing and poster images.':
    'シーク用のサムネイルとポスター画像です。',
  'Open timeline': 'タイムラインを開く',
  'Copy a manifest URL into your player.':
    'マニフェスト URL をプレーヤーにコピーします。',
  'View streaming URLs': 'ストリーミング URL を表示',
  'Get streaming': 'ストリーミングを始める',
  '{done} of {total} required steps done':
    '必須ステップ {total} 件中 {done} 件完了',

  // Renditions
  'Each rendition is one quality level of this video in one output format. Players switch between them as bandwidth changes.':
    'レンディションは、1 つの出力形式における動画の 1 つの画質レベルです。プレーヤーは帯域幅に応じて切り替えます。',
  'All outputs': 'すべての出力',
  'All statuses': 'すべてのステータス',
  'No renditions match your filters':
    'フィルターに一致するレンディションはありません',
  'Try a different output or status.':
    '別の出力またはステータスをお試しください。',
  Profile: 'プロファイル',
  'Segment length': 'セグメント長',
  'Media playlist URL': 'メディアプレイリスト URL',
  'Copy media playlist URL': 'メディアプレイリスト URL をコピー',
  'Open media playlist': 'メディアプレイリストを開く',
  renditions: 'レンディション',
  'Rendition ID': 'レンディション ID',
  'Profile name': 'プロファイル名',
  'Video bitrate (kbps)': '動画ビットレート (kbps)',
  'Audio bitrate (kbps)': '音声ビットレート (kbps)',
  Progress: '進捗',
  'Started at': '開始',
  'Ended at': '終了',
  Started: '開始済み',
  Ended: '終了済み',
  Uploading: 'アップロード中',
  Aborted: '中止',

  // Subtitles
  'Text tracks in any language. Every HLS, DASH, and CMAF manifest lists them, so viewers can pick one in the player.':
    'あらゆる言語のテキストトラックです。HLS、DASH、CMAF のすべてのマニフェストに含まれるため、視聴者はプレーヤーで選択できます。',
  'Embedded in source': 'ソースに埋め込み',
  'Remove default': 'デフォルトを解除',
  'Subtitle URL': '字幕 URL',
  'Copy HLS subtitle URL': 'HLS 字幕 URL をコピー',
  'Copy DASH subtitle URL': 'DASH 字幕 URL をコピー',
  'Copy CMAF subtitle URL': 'CMAF 字幕 URL をコピー',
  'Open subtitle file': '字幕ファイルを開く',
  'Embedded tracks are extracted only once, so this track will not come back. This action cannot be undone.':
    '埋め込みトラックの抽出は 1 回だけのため、このトラックは元に戻りません。この操作は取り消せません。',

  // Timeline
  'Thumbnails sampled across the video. Players show them while scrubbing, and any frame works as a poster image.':
    '動画全体から抽出したサムネイルです。プレーヤーはシーク中に表示し、どのフレームもポスター画像として使えます。',
  'Regenerate timeline': 'タイムラインを再生成',
  'Loading timeline...': 'タイムラインを読み込み中...',
  'Generating timeline': 'タイムラインを生成中',
  'Sampling frames into sprite sheets. Thumbnails appear here when ready.':
    'フレームをスプライトシートに抽出しています。準備ができるとサムネイルがここに表示されます。',
  'No timeline yet': 'タイムラインはまだありません',
  'Generate a timeline to get scrubbing thumbnails and a preview image for this video.':
    'タイムラインを生成すると、この動画のシーク用サムネイルとプレビュー画像を取得できます。',
  Thumbnails: 'サムネイル',
  'Sprite sheets': 'スプライトシート',
  'Thumbnail size': 'サムネイルサイズ',
  'Timeline file': 'タイムラインファイル',
  'A WebVTT file that maps time ranges to sprite sheet regions. Point your player thumbnail option at this URL.':
    '時間範囲をスプライトシートの領域に対応付ける WebVTT ファイルです。プレーヤーのサムネイル設定にこの URL を指定してください。',
  'Timeline URL': 'タイムライン URL',
  'First lines': '先頭の行',
  'Preview image': 'プレビュー画像',

  // Streaming
  'Give a master manifest URL to any HLS or DASH player. It lists every ready rendition and subtitle, and the player picks the best quality for each viewer.':
    'マスターマニフェスト URL を HLS または DASH プレーヤーに渡します。準備済みのすべてのレンディションと字幕が含まれ、プレーヤーが視聴者ごとに最適な画質を選びます。',
  'Nothing to stream yet': 'ストリーミングできるものはまだありません',
  'Manifests become available as soon as the first rendition for that output is ready.':
    'その出力の最初のレンディションが準備できると、マニフェストを利用できます。',
  'Use HLS for Apple devices and most web players, DASH for Android and Shaka Player. CMAF serves both from one set of files.':
    'Apple デバイスや多くの Web プレーヤーには HLS、Android や Shaka Player には DASH を使います。CMAF は 1 組のファイルで両方を配信します。',
  'No ready renditions for this output yet':
    'この出力の準備済みレンディションはまだありません',
  'Manifest, segment, and subtitle requests use the read permissions of the source file in Storage. Grant read access to Any for public playback, or to signed-in users for private videos.':
    'マニフェスト、セグメント、字幕のリクエストには、ストレージ上のソースファイルの読み取り権限が使われます。公開再生には Any に、非公開の動画にはログイン済みユーザーに読み取り権限を付与してください。',
  'Open bucket': 'バケットを開く',
  'Native HTML': 'ネイティブ HTML',
  'Install a player': 'プレーヤーをインストール',
  '{index} of {total}': '{index} / {total}',
  'Install {player}': '{player} をインストール',
  '{player} documentation': '{player} のドキュメント',
  'All streaming URLs': 'すべてのストリーミング URL',
  'Add to build.gradle.kts (module)': 'build.gradle.kts (モジュール) に追加',
  'Allow network access in AndroidManifest.xml':
    'AndroidManifest.xml でネットワークアクセスを許可',
  'Install the iOS pods': 'iOS の Pod をインストール',
  'Nothing to install. Safari and iOS play HLS natively. Other browsers need a library such as hls.js.':
    'インストールは不要です。Safari と iOS は HLS をネイティブで再生します。その他のブラウザでは hls.js などのライブラリが必要です。',
  'Nothing to install. AVPlayer is part of AVKit on iOS, iPadOS, tvOS, visionOS, and macOS.':
    'インストールは不要です。AVPlayer は iOS、iPadOS、tvOS、visionOS、macOS の AVKit に含まれています。',
  'Subtitle tracks': '字幕トラック',
  'Master manifests already include these. Use the direct URLs to load a track yourself. For DASH the URL returns the WebVTT file.':
    'マスターマニフェストにはすでに含まれています。トラックを直接読み込む場合はこれらの URL を使います。DASH の場合は WebVTT ファイルが返されます。',
  'No subtitle tracks. Add them from the Subtitles page.':
    '字幕トラックはありません。字幕ページから追加してください。',
  'Track is still being packaged': 'トラックをパッケージ化中です',
  'Individual streams and segments': '個別のストリームとセグメント',
  'Advanced. Players reach these from the master manifest. Use them to debug one quality level or fetch a single segment.':
    '上級者向け。プレーヤーはマスターマニフェストからこれらにアクセスします。特定の画質レベルのデバッグや、単一セグメントの取得に使います。',
  'Available once a rendition is ready.':
    'レンディションの準備ができると利用できます。',
  'Stream index': 'ストリームインデックス',
  'Segment ID': 'セグメント ID',
  'From the media playlist': 'メディアプレイリストから',
  'Media playlist': 'メディアプレイリスト',
  'DASH has no per-stream playlist. Segments are listed in the MPD.':
    'DASH にはストリームごとのプレイリストはありません。セグメントは MPD に記載されています。',
  Segment: 'セグメント',
  'Enter a segment ID': 'セグメント ID を入力',
  'Subtitle track': '字幕トラック',
  'From the subtitle playlist': '字幕プレイリストから',
  'Subtitle segment': '字幕セグメント',

  // Debugger
  'Play each output the way a client would and inspect manifests, quality switches, buffer health, and player events.':
    'クライアントと同じように各出力を再生し、マニフェスト、画質の切り替え、バッファの状態、プレーヤーイベントを確認できます。',

  // Settings and profiles
  'Reusable presets for resolution and bitrate, shared by every video in this project. Pick one or more when you create renditions.':
    '解像度とビットレートの再利用可能なプリセットで、プロジェクト内のすべての動画で共有されます。レンディション作成時に 1 つ以上選びます。',
  'Video bitrate (bps)': '映像ビットレート (bps)',
  'Sort profiles': 'プロファイルを並べ替え',
  'Video bitrate (highest first)': '映像ビットレート (高い順)',
  'Video bitrate (lowest first)': '映像ビットレート (低い順)',
  'Audio bitrate (bps)': '音声ビットレート (bps)',

  // Glossary tooltips
  'One encoded copy of the video at a set resolution and bitrate. Players switch between renditions as bandwidth changes.':
    '決まった解像度とビットレートでエンコードされた動画のコピーです。プレーヤーは帯域幅に応じてレンディションを切り替えます。',
  'A reusable encoding preset: resolution plus video and audio bitrate. Each rendition is encoded from one profile.':
    '再利用可能なエンコード設定 (解像度、映像と音声のビットレート) です。各レンディションは 1 つのプロファイルからエンコードされます。',
  'The streaming format a rendition is packaged in: HLS, DASH, or CMAF.':
    'レンディションをパッケージ化するストリーミング形式 (HLS、DASH、CMAF) です。',
  "HTTP Live Streaming. Apple's format: native on Safari and iOS, and works elsewhere with hls.js.":
    'HTTP Live Streaming。Apple の形式で、Safari と iOS ではネイティブ再生、その他の環境では hls.js で再生できます。',
  'MPEG-DASH. An open standard supported by most web and TV players, but not native Safari.':
    'MPEG-DASH。多くの Web・TV プレーヤーが対応するオープン標準ですが、Safari はネイティブ非対応です。',
  'Encodes once and serves the same segments as both HLS and DASH. Saves storage and encoding time.':
    '1 回のエンコードで同じセグメントを HLS と DASH の両方で配信します。ストレージとエンコード時間を節約できます。',
  'The playlist a player loads first. It lists every rendition and subtitle so the player can pick the best one.':
    'プレーヤーが最初に読み込むプレイリストです。すべてのレンディションと字幕が含まれ、プレーヤーが最適なものを選べます。',
  'A playlist for a single rendition stream. Players reach it from the master manifest.':
    '単一のレンディションストリーム用のプレイリストです。プレーヤーはマスターマニフェストからアクセスします。',
  'A short chunk of video, a few seconds long. Players download segments one after another.':
    '数秒程度の短い動画の断片です。プレーヤーはセグメントを順番にダウンロードします。',
  'Adaptive bitrate: the player picks a quality automatically based on network speed.':
    'アダプティブビットレート: プレーヤーがネットワーク速度に応じて画質を自動で選びます。',
  'Data per second of video. Higher bitrate means better quality and bigger files.':
    '動画 1 秒あたりのデータ量です。ビットレートが高いほど高画質になり、ファイルも大きくなります。',
  'Thumbnail sprite sheets plus a WebVTT index. Players use it to show previews while scrubbing.':
    'サムネイルのスプライトシートと WebVTT インデックスです。プレーヤーはシーク中のプレビュー表示に使います。',
  'A single image that packs many thumbnails into a grid.':
    '多数のサムネイルをグリッド状にまとめた 1 枚の画像です。',
  'An image cut from the timeline. The first one is used as the video poster.':
    'タイムラインから切り出した画像です。最初の画像が動画のポスターとして使われます。',
  'Extracted automatically from the source file. Retag its language if it shows as und.':
    'ソースファイルから自動で抽出されました。und と表示される場合は言語を設定し直してください。',
  'Players turn on the default subtitle automatically.':
    'プレーヤーはデフォルトの字幕を自動でオンにします。',
  'ISO 639-2 three-letter code, like eng or heb. und means undetermined.':
    'eng や jpn などの ISO 639-2 の 3 文字コードです。und は未定義を意味します。',
  'The longest segment in this rendition, in seconds.':
    'このレンディション内で最も長いセグメントの秒数です。',
  'The profile is larger than the source. Upscaling adds size without adding detail.':
    'プロファイルがソースより大きいです。アップスケールしてもサイズが増えるだけで、精細さは上がりません。',
  'Position of a stream (track) inside the rendition, starting at 0.':
    'レンディション内のストリーム (トラック) の位置で、0 から始まります。',
  'The compression format of a stream, like H.264 for video or AAC for audio.':
    'ストリームの圧縮形式です (映像なら H.264、音声なら AAC など)。',
  'The file format that wraps the audio and video streams, like MP4.':
    '音声と映像のストリームを格納するファイル形式です (MP4 など)。',
  'Rename the video, see its source file, or delete it.':
    '動画の名前の変更、ソースファイルの確認、削除ができます。',
  'Read from the file during the first encoding job.':
    '最初のエンコードジョブでファイルから読み取られます。',
  'Metadata appears once the first rendition or timeline job reads the file.':
    '最初のレンディションまたはタイムラインのジョブがファイルを読み取ると、メタデータが表示されます。',
  'Add WebVTT or SRT files from Storage. Tracks embedded in the source file are extracted automatically by the first rendition or timeline job.':
    'ストレージから WebVTT または SRT ファイルを追加します。ソースファイルに埋め込まれたトラックは、最初のレンディションまたはタイムラインのジョブで自動的に抽出されます。',
  'Audio-only videos have no frames for a timeline.':
    '音声のみの動画にはタイムライン用のフレームがありません。',
  'Choose encoding profiles and an output format. Each profile becomes one quality level, and Appwrite reads the file metadata on the first job.':
    'エンコードプロファイルと出力形式を選択します。各プロファイルが 1 つの画質レベルになり、Appwrite は最初のジョブでファイルのメタデータを読み取ります。',
  'The Storage file this video was created from. Each rendition or timeline job downloads its own copy and deletes it when done.':
    'この動画の作成元となったストレージのファイルです。レンディションやタイムラインの各ジョブは独自のコピーをダウンロードし、完了後に削除します。',
  'Bitrate ladder': 'ビットレートラダー',
  'Every rendition in the manifest. The marker shows the current bandwidth estimate: renditions to its left fit the connection.':
    'マニフェスト内のすべてのレンディションです。マーカーは現在の推定帯域幅を示し、その左側のレンディションが接続に収まります。',
  Average: '平均',
  'Watch time': '視聴時間',
  'Time per rendition': 'レンディション別の時間',
  'Share of watch time spent on each rendition.':
    '各レンディションで再生された視聴時間の割合です。',
  'Play the video to measure time per rendition.':
    '動画を再生すると、レンディション別の時間を計測します。',
  'Switch history': '切り替え履歴',
  'Every rendition change, newest first.':
    'すべてのレンディション切り替えを新しい順に表示します。',
  'No rendition switches yet.': 'まだレンディションの切り替えはありません。',
  Playlists: 'プレイリスト',
  'Experience score': '体験スコア',
  'How this session feels to a viewer, from 0 to 100.':
    'このセッションが視聴者にとってどれだけ快適かを 0 から 100 で示します。',
  Diagnostics: '診断',
  'Issues detected in this session and how to fix them.':
    'このセッションで検出された問題と、その解決方法です。',
  Manifest: 'マニフェスト',
  Rebuffering: 'リバッファリング',
  Stalls: '停止',
  'Average bitrate': '平均ビットレート',
  'Share of top rendition': '最上位レンディション比',
  'Quality switches': '画質の切り替え',
  'Locked by you': '手動で固定',
  Upscaling: 'アップスケール',
  Seeks: 'シーク',
  'Media timeline': 'メディアタイムライン',
  'How much video is downloaded ahead of the playhead, and the quality of every downloaded segment. Select a point on the buffer bar to seek.':
    '再生位置より先にどれだけダウンロード済みか、各セグメントがどの画質で取得されたかを示します。バッファバーをクリックするとその位置にシークします。',
  'The rest of the video is downloaded, so playback cannot stall.':
    '残りの動画はすべてダウンロード済みのため、再生が止まることはありません。',
  '{seconds} s is downloaded ahead of the playhead, enough to ride out network hiccups.':
    '再生位置より先に {seconds} 秒分がダウンロード済みで、一時的なネットワークの乱れにも耐えられます。',
  'Only {seconds} s is downloaded ahead. Playback may stall if the network slows down.':
    '先読みは {seconds} 秒分のみです。ネットワークが遅くなると再生が止まる可能性があります。',
  'Almost nothing is downloaded ahead of the playhead. Playback is likely to stall.':
    '再生位置より先がほとんどダウンロードされていません。再生が止まる可能性が高いです。',
  'Ready to play': '再生可能',
  'Not downloaded': '未ダウンロード',
  'Segment quality': 'セグメントの画質',
  'Brighter means higher quality.': '明るいほど高画質です。',
  'Waiting for playback': '再生待ち',
  'Playback failed': '再生に失敗しました',
  Excellent: '非常に良い',
  Good: '良い',
  Fair: '普通',
  Poor: '悪い',
  Startup: '起動',
  'Time until the first frame': '最初のフレームまでの時間',
  Smoothness: 'スムーズさ',
  'Stalls and time spent rebuffering': '停止回数とリバッファリング時間',
  'Picture quality': '画質',
  'Bitrate played and upscaling': '再生ビットレートとアップスケール',
  Stability: '安定性',
  'Frames dropped while decoding': 'デコード中のドロップフレーム',
  'Slow startup': '起動が遅い',
  'Viewers wait too long for the first frame. Shorter segments or a lower starting rendition help.':
    '最初のフレームまでの待ち時間が長すぎます。セグメントを短くするか、開始レンディションを下げると改善します。',
  'Playback stalled': '再生が停止しました',
  'The buffer ran empty. Compare bandwidth with the bitrate below to see if the ladder needs a lower rung.':
    'バッファが空になりました。下の帯域幅とビットレートを比較し、ラダーにより低い段が必要か確認してください。',
  'Bandwidth below the lowest rendition':
    '帯域幅が最低レンディションを下回っています',
  'Even the smallest rendition barely fits this connection. Add a lower bitrate rendition for slow networks.':
    '最小のレンディションでもこの接続にはぎりぎりです。低速ネットワーク向けに、より低いビットレートのレンディションを追加してください。',
  'Video is upscaled': '動画がアップスケールされています',
  'The player is larger than the rendition it receives, so the picture looks soft. Add a higher resolution rendition.':
    'プレーヤーが受信しているレンディションより大きいため、映像がぼやけて見えます。より高解像度のレンディションを追加してください。',
  'Frames are being dropped': 'フレームがドロップしています',
  'This device struggles to decode the stream. A lower frame rate or a lighter codec helps.':
    'このデバイスではストリームのデコードが追いついていません。フレームレートを下げるか、より軽いコーデックを使うと改善します。',
  'Quality keeps dropping': '画質の低下が続いています',
  'The player switched down several times. Closer bitrate steps between renditions make switches less visible.':
    'プレーヤーが何度も画質を下げました。レンディション間のビットレート差を小さくすると、切り替えが目立ちにくくなります。',
  'Single rendition': 'レンディションが 1 つだけ',
  'With one rendition the player cannot adapt to the network. Add more renditions for adaptive streaming.':
    'レンディションが 1 つだけだと、プレーヤーはネットワークに合わせて調整できません。アダプティブストリーミングのためにレンディションを追加してください。',
  'Progressive playback': 'プログレッシブ再生',
  'The original file plays as a single download, without adaptive bitrate.':
    '元のファイルをアダプティブビットレートなしで、1 回のダウンロードとして再生します。',
  'No issues detected': '問題は検出されませんでした',
  'Startup, smoothness, and picture quality all look healthy.':
    '起動、スムーズさ、画質のいずれも良好です。',
  'Playing bitrate': '再生中のビットレート',
  'Bandwidth and bitrate': '帯域幅とビットレート',
  'Network throughput against the rendition being played. Dashed lines are your renditions; red marks stalls.':
    '再生中のレンディションに対するネットワークのスループットです。破線はレンディション、赤は停止を示します。',
  'Collecting samples...': 'サンプルを収集中...',
  'Buffer health': 'バッファの状態',
  'Seconds of video ready ahead of the playhead. Below the target line, stalls become likely.':
    '再生位置より先に準備できている動画の秒数です。目標ラインを下回ると停止が起きやすくなります。',
  'Rendered height': '表示上の高さ',
  'Playback rate': '再生速度',
  'Live latency': 'ライブ遅延',
  'Audio tracks': '音声トラック',
  'Session details': 'セッションの詳細',
  Transferred: '転送量',
  'Average throughput': '平均スループット',
  'Median time to first byte': '最初のバイトまでの時間 (中央値)',
  'Slowest 5% load time': '読み込み時間 (遅い 5%)',
  'Waiting for first byte': '最初のバイト待ち',
  'Media time': 'メディア時間',
  'Time to first byte': '最初のバイトまでの時間',
  Waterfall: 'ウォーターフォール',
  'Request URL': 'リクエスト URL',
  Playlist: 'プレイリスト',
  'Select a manifest to view it.': 'マニフェストを選択すると内容を表示します。',
  Lines: '行数',
  'Copy contents': '内容をコピー',
  'Filter events...': 'イベントを絞り込み...',
  Info: '情報',
  Warnings: '警告',
  'Copy log': 'ログをコピー',
  'No events match your filters.': '条件に一致するイベントはありません。',
  'This stream on this device': 'このデバイスでのこのストリーム',
  'The browser decoder checks each rendition: whether it can play it, play it without dropping frames, and play it with hardware acceleration.':
    'ブラウザーのデコーダーで各レンディションを確認します。再生できるか、フレームを落とさずに再生できるか、ハードウェアアクセラレーションで再生できるかを調べます。',
  'Rendition checks are only available for adaptive streams.':
    'レンディションの確認はアダプティブストリームでのみ利用できます。',
  'Some renditions cannot be decoded here. The player skips them, so viewers on this device never get that quality.':
    '一部のレンディションはこのデバイスでデコードできません。プレーヤーはそれらをスキップするため、このデバイスの視聴者はその画質を受け取れません。',
  'Some renditions may drop frames on this device. Consider a lighter codec profile or a lower frame rate.':
    '一部のレンディションはこのデバイスでフレームがドロップする可能性があります。より軽いコーデックプロファイルや低いフレームレートを検討してください。',
  Supported: '対応',
  Smooth: 'スムーズ',
  'Hardware decoding': 'ハードウェアデコード',
  'Fits player': 'プレーヤーに収まる',
  'Larger than the player': 'プレーヤーより大きい',
  'Codec support': 'コーデック対応',
  'Playback capabilities': '再生機能',
  'Media Source Extensions': 'Media Source Extensions',
  'Native HLS': 'ネイティブ HLS',
  'Picture in picture': 'ピクチャーインピクチャー',
  'Display and network': 'ディスプレイとネットワーク',
  Screen: '画面',
  'HDR display': 'HDR ディスプレイ',
  'Wide color (P3)': '広色域 (P3)',
  'Downlink estimate': '推定ダウンリンク',
  'Round trip time': 'ラウンドトリップ時間',
  'Data saver': 'データセーバー',
  Browser: 'ブラウザー',
  Buffering: 'バッファリング中',
  'Experience score, diagnostics, and live charts for this playback session.':
    'この再生セッションの体験スコア、診断、リアルタイムグラフです。',
  'The bitrate ladder, which rendition is playing, and every adaptive switch.':
    'ビットレートラダー、再生中のレンディション、すべてのアダプティブ切り替えです。',
  'Every segment request with timing, throughput, and a waterfall.':
    'すべてのセグメントリクエストのタイミング、スループット、ウォーターフォールです。',
  'The player event log, newest first.':
    'プレーヤーのイベントログを新しい順に表示します。',
  'Master and media playlists as the player fetched them, with syntax highlighting.':
    'プレーヤーが取得したマスタープレイリストとメディアプレイリストを、シンタックスハイライト付きで表示します。',
  'What this browser and screen can decode and display, checked against this stream.':
    'このブラウザーと画面でデコード・表示できる内容を、このストリームに照らして確認します。',
  'Copy summary': '概要をコピー',
  'Export session': 'セッションをエクスポート',
  Native: 'ネイティブ',
  'Press 1 to 6 to switch sections': '1〜6 キーでセクションを切り替え',
  Snapshot: 'スナップショット',
  Seek: 'シーク',
  Play: '再生',
  'Reset session data': 'セッションデータをリセット',
  'Clear collected data': '収集したデータを消去',
  'Empties charts, segments, and events. Playback continues.':
    'グラフ、セグメント、イベントを空にします。再生は続行されます。',
  'Restart session': 'セッションを再開始',
  'Reloads the stream and measures everything from startup.':
    'ストリームを再読み込みし、起動時からすべてを計測し直します。',
  'Bandwidth is far above your highest rendition, so it runs along the top of the chart. Hover to see exact values.':
    '帯域幅が最高画質のレンディションを大きく上回っているため、グラフの上端に沿って表示されます。正確な値はカーソルを合わせると確認できます。',
  'Start from a preset': 'プリセットから始める',
  'Pick a ready-made rung like 1080p or 720p, or set the width, height, and bitrates yourself.':
    '1080p や 720p などの既製の段階を選ぶか、幅、高さ、ビットレートを自分で設定します。',
  'Each profile produces one rendition of a video. Combine several to build a quality ladder.':
    '各プロファイルから動画のレンディションが 1 つ作成されます。複数を組み合わせて画質のラダーを構成できます。',
  'Stream adaptively': 'アダプティブに配信する',
  'Players switch between renditions as bandwidth changes, so playback stays smooth on any network.':
    'プレーヤーは帯域幅の変化に応じてレンディションを切り替えるため、どのネットワークでも再生がスムーズです。',
  'Define your quality ladder': '画質のラダーを定義',
  'Encoding profiles set the resolution and bitrate of every rendition, so each viewer gets the best quality their connection can handle.':
    'エンコードプロファイルは各レンディションの解像度とビットレートを決めるため、視聴者は回線に合った最適な画質で視聴できます。',
}
