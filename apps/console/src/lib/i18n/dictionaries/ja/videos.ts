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
  Captions: '字幕',
  'Back to videos': '動画一覧に戻る',
  'Switch video': '動画を切り替え',
  'No videos found': '動画が見つかりません',
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
  'The console calls GET /project/profiles on your project API. A server error here usually means the Videos service failed to load or seed profiles for this project, not a problem with your browser.':
    'コンソールはプロジェクト API の GET /project/profiles を呼び出します。ここでサーバーエラーになる場合、ブラウザではなく、このプロジェクトの Videos サービスがプロファイルの読み込みまたはシードに失敗している可能性が高いです。',
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
  'Renditions already encoded with these profiles stay playable. This action cannot be undone.':
    'これらのプロファイルでエンコード済みのレンディションは引き続き再生できます。この操作は元に戻せません。',
  'Delete profiles': 'プロファイルを削除',
  'Failed to delete profiles': 'プロファイルを削除できませんでした',
  'Select all profiles': 'すべてのプロファイルを選択',
  'Select profile': 'プロファイルを選択',
  '1 profile selected': '1 件のプロファイルを選択中',
  'profiles selected': '件のプロファイルを選択中',
  profile: 'プロファイル',
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
  '1 selected profile is larger than the source':
    '選択したプロファイルのうち 1 件がソースより大きいサイズです',
  'selected profiles are larger than the source':
    '件の選択したプロファイルがソースより大きいサイズです',
  'Upscaling adds size without adding detail.':
    'アップスケールしてもディテールは増えず、ファイルサイズだけが大きくなります。',
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
  'Their segments are removed and players stop receiving these qualities. This action cannot be undone.':
    'セグメントが削除され、プレーヤーはこれらの画質を受信しなくなります。この操作は元に戻せません。',
  'Delete renditions': 'レンディションを削除',
  'Failed to delete renditions': 'レンディションを削除できませんでした',
  'Failed to retry renditions': 'レンディションを再試行できませんでした',
  'Select all renditions': 'すべてのレンディションを選択',
  'Select rendition': 'レンディションを選択',
  '1 rendition selected': '1 件のレンディションを選択中',
  'renditions selected': '件のレンディションを選択中',
  rendition: 'レンディション',
  'Successfully deleted': '削除しました',

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
  'Renditions are still encoding. Playback starts when one is ready.':
    'レンディションはまだ処理中です。準備ができると再生が始まります。',
  'No renditions are ready to play.': '再生できるレンディションはまだありません。',
  'This video has no source to play.': 'この動画には再生できるソースがありません。',
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
  Playing: '再生中',
  'No levels parsed yet.': 'まだレベルが解析されていません。',
  'Quality levels are only available for adaptive streams.':
    '画質レベルはアダプティブストリームでのみ利用できます。',
  Segments: 'セグメント',
  'No segments loaded yet.': 'まだセグメントが読み込まれていません。',
  'Load time': '読み込み時間',
  'Event log': 'イベントログ',
  'Player lifecycle events, level switches, and errors (newest first).':
    'プレーヤーのライフサイクルイベント、レベル切り替え、エラー (新しい順)。',
  'No events recorded yet.': 'まだイベントは記録されていません。',
  Manifests: 'マニフェスト',
  'Master manifests': 'マスターマニフェスト',
  'Media playlists': 'メディアプレイリスト',
  'Public playback URLs. Clients need read access to the source file; the console adds admin mode.':
    '公開再生 URL です。クライアントにはソースファイルの読み取り権限が必要です。コンソールでは admin モードが付加されます。',
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
  'Upload WebVTT or SRT files per language, or write them in the editor.':
    '言語ごとに WebVTT または SRT ファイルをアップロードするか、エディターで作成します。',
  'Upload subtitle': '字幕をアップロード',
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
  'Write captions in the editor, or import WebVTT or SRT files from Storage. Tracks embedded in the source file are extracted automatically by the first rendition or timeline job.':
    'エディターで字幕を作成するか、ストレージから WebVTT または SRT ファイルをインポートします。ソースファイルに埋め込まれたトラックは、最初のレンディションまたはタイムラインのジョブで自動的に抽出されます。',
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
  'Video player': '動画プレーヤー',
  'Loading video': '動画を読み込み中',
  'This video does not exist or was removed. Check the link and try again.':
    'この動画は存在しないか、削除されました。リンクを確認してもう一度お試しください。',
  'This video is private': 'この動画は非公開です',
  'Sign in to watch it, or ask the owner to make it public.':
    '視聴するにはサインインするか、所有者に公開してもらってください。',
  'Access denied': 'アクセスが拒否されました',
  'Your account cannot play this video. The owner may need to update bucket or video permissions.':
    'このアカウントでは動画を再生できません。所有者がバケットまたは動画の権限を更新する必要がある場合があります。',
  'Unable to play': '再生できません',
  'Something went wrong while loading this video. Try again later.':
    '動画の読み込み中に問題が発生しました。しばらくしてからもう一度お試しください。',
  'Missing project': 'プロジェクトがありません',
  'Add a project query parameter to this URL, for example ?project=yourProjectId.':
    'URL に project クエリパラメータを追加してください。例: ?project=yourProjectId',
  'Not ready to play': '再生の準備ができていません',
  'This video is still being prepared. Try again in a few minutes.':
    'この動画はまだ準備中です。数分後にもう一度お試しください。',
  Embed: '埋め込み',
  'Embed code': '埋め込みコード',
  'Embed code copied': '埋め込みコードをコピーしました',
  'Embed video': '動画を埋め込む',
  'Copy the iframe snippet below. Optionally set a start time for playback.':
    '以下の iframe コードをコピーしてください。必要に応じて再生の開始時刻を指定できます。',
  'Start time': '開始時刻',
  'Custom start time': '開始時刻を指定',
  'Embed plays from the beginning.': '埋め込みは最初から再生されます。',
  'Playback begins at this time when the embed loads.':
    '埋め込みの読み込み時、この時刻から再生を開始します。',
  'Copy embed code': '埋め込みコードをコピー',
  'Subtitle size': '字幕サイズ',
  'Compact subtitles': 'コンパクト',
  'Standard subtitles': '標準',
  'Large subtitles': '大',
  'The source file is still being probed. Try again when encoding starts or finishes.':
    'ソースファイルをまだ解析中です。エンコードが開始または完了してからもう一度お試しください。',

  // Create video wizard
  'Encoding settings': 'エンコード設定',
  Upload: 'アップロード',
  Demo: 'デモ',
  'Advanced settings': '詳細設定',
  'Serves both HLS and DASH.': 'HLS と DASH の両方に対応します。',
  'Plays natively on Safari and iOS.': 'Safari と iOS でネイティブ再生できます。',
  'Not supported natively on Safari.': 'Safari ではネイティブ再生に対応していません。',
  'Review': '確認',
  'Uploaded from your computer': 'コンピューターからアップロード',
  'File in Storage': 'ストレージ内のファイル',
  'Demo video by Blender Studio': 'Blender Studio のデモ動画',
  'Timeline previews': 'タイムラインプレビュー',
  'Bucket created': 'バケットを作成しました',
  'Video created with issues': '動画は作成されましたが、一部の処理に失敗しました',
  'Open the video to retry the steps that failed.': '動画を開いて、失敗した処理を再試行してください。',
  'Video creation continues in the background.': '動画の作成はバックグラウンドで続行されます。',
  'Open video': '動画を開く',
  'Choose a source': 'ソースを選択',
  'Every video starts from a file in Storage. Upload one, pick a file you already have, or start with a demo.':
    'すべての動画はストレージ内のファイルから作成されます。ファイルをアップロードするか、既存のファイルを選択するか、デモ動画から始めましょう。',
  'Configure encoding': 'エンコードを設定',
  'Choose how the video is packaged for adaptive streaming. Encoding starts as soon as the source is ready.':
    'アダプティブストリーミング用のパッケージ方法を選択します。ソースの準備が整うとすぐにエンコードが始まります。',
  'Audio-only sources have no frames to preview.':
    '音声のみのソースにはプレビューするフレームがありません。',
  'Sprite sheet thumbnails and a WebVTT track for previews while scrubbing. Also sets the video poster.':
    'シーク中のプレビュー用に、スプライトシートのサムネイルと WebVTT トラックを生成します。動画のポスター画像にも使用されます。',
  'Clear every profile to skip encoding. You can create renditions later from the video page.':
    'エンコードをスキップするには、すべてのプロファイルの選択を解除します。レンディションは後から動画ページで作成できます。',
  'Review and create': '確認して作成',
  'Name your video and confirm the details. You can rename it later.':
    '動画に名前を付けて、内容を確認してください。名前は後から変更できます。',
  'Destination bucket': '保存先バケット',
  'No renditions. You can encode later.': 'レンディションなし。後からエンコードできます。',
  'Too large for this bucket.': 'このバケットには大きすぎます。',
  'Maximum file size:': '最大ファイルサイズ:',
  'This bucket does not accept this file type.': 'このバケットはこのファイル形式を受け付けません。',
  'Drop a file here or click to browse': 'ここにファイルをドロップするか、クリックして選択',
  'MP4, MOV, WebM, MKV, and audio files': 'MP4、MOV、WebM、MKV、音声ファイル',
  'Up to': '最大',
  'Choose a video or audio file.': '動画または音声ファイルを選択してください。',
  'The file stays in its bucket. Nothing is copied.':
    'ファイルは元のバケットに残り、コピーされません。',
  'Loading file...': 'ファイルを読み込み中...',
  'Too large for the selected bucket.': '選択したバケットには大きすぎます。',
  'Open movies by Blender Studio, served from Wikimedia Commons. The file is downloaded in your browser and uploaded to the selected bucket.':
    'Blender Studio のオープンムービーを Wikimedia Commons から配信しています。ファイルはブラウザでダウンロードされ、選択したバケットにアップロードされます。',
  'Create Videos bucket': 'Videos バケットを作成',
  'The source file is stored in a Storage bucket. Create one to continue.':
    'ソースファイルはストレージのバケットに保存されます。続行するにはバケットを作成してください。',
  'The uploaded file is stored here and stays in Storage.':
    'アップロードしたファイルはここに保存され、ストレージに残ります。',
  'Drop a video or audio file from your computer.':
    'コンピューターから動画または音声ファイルをドロップします。',
  'Choose from Storage': 'ストレージから選択',
  'Use a file that is already in a bucket.': 'バケット内の既存ファイルを使用します。',
  'Try a demo video': 'デモ動画を試す',
  'Import an open movie to explore Videos.': 'オープンムービーをインポートして動画機能を試せます。',
  'No source selected': 'ソースが選択されていません',
  'Preview unavailable': 'プレビューを利用できません',
  'Audio only': '音声のみ',
  'New video': '新しい動画',
  'Choose a source to see a preview.': 'ソースを選択するとプレビューが表示されます。',
  'Creating your video': '動画を作成しています',
  'Encoding renditions': 'レンディションをエンコード中',
  'You can leave this screen. Encoding continues on the video.':
    'この画面を離れても、エンコードは動画上で続行されます。',
  'The source is saved first. Renditions start after that.':
    '先にソースを保存します。その後レンディションを開始します。',
  'The upload ended before the whole file was stored.':
    'ファイル全体が保存される前にアップロードが終了しました。',
  'The demo download ended before the whole file was received.':
    'ファイル全体を受信する前にデモのダウンロードが終了しました。',
  'Saving the source and registering the video.':
    'ソースを保存し、動画を登録しています。',
  'Open the video when you are ready.': '準備ができたら動画を開いてください。',
  '{ready} of {total} ready': '{ready} / {total} 件が準備完了',
  'Video could not be created': '動画を作成できませんでした',
  'Keep this window open while the file uploads. Once the video exists, you can open it and the remaining steps continue in the background.':
    'ファイルのアップロード中はこのウィンドウを開いたままにしてください。動画が作成されたら開くことができ、残りの処理はバックグラウンドで続行されます。',
  'The video was not created. Go back to adjust the source or try again.':
    '動画は作成されませんでした。戻ってソースを変更するか、もう一度お試しください。',
  'Download demo video': 'デモ動画をダウンロード',
  'Fetch the open movie in your browser.': 'ブラウザでオープンムービーを取得します。',
  'Upload to Storage': 'ストレージにアップロード',
  'Store the source file in your bucket.': 'ソースファイルをバケットに保存します。',
  'Register video': '動画を登録',
  'Create the video from the source file.': 'ソースファイルから動画を作成します。',
  'Prepare source': 'ソースを準備',
  'Download, probe, and extract embedded subtitles.':
    'ダウンロード、解析、埋め込み字幕の抽出を行います。',
  'Queue sprite sheet thumbnails for scrubbing previews.':
    'シークプレビュー用のスプライトシートサムネイルをキューに追加します。',
  'Queue renditions': 'レンディションをキューに追加',
  'Start encoding one rendition per selected profile.':
    '選択したプロファイルごとに 1 つのレンディションのエンコードを開始します。',
  'Short and light. The fastest way to try encoding.': '短くて軽量。エンコードを試すのに最適です。',
  'Bright 16:9 cartoon with fast camera moves.':
    '素早いカメラワークの鮮やかな 16:9 アニメーション。',
  'Longer 16:9 short with detailed outdoor scenes.':
    '精細な屋外シーンを含む、やや長めの 16:9 短編。',
  'Ultrawide frame. Shows letterboxed renditions.':
    'ウルトラワイド画角。レターボックス付きのレンディションを確認できます。',
  'Fast action and rich color for bitrate tests.':
    '激しい動きと豊かな色彩で、ビットレートのテストに最適です。',
  'Ultrawide hybrid of 2D and 3D animation.':
    '2D と 3D を組み合わせたウルトラワイドのアニメーション。',

  // Subtitle editor
  'Subtitle editor': '字幕エディター',
  'New subtitle': '新しい字幕',
  'Open in editor': 'エディターで開く',
  'Back to subtitles': '字幕一覧に戻る',
  'Subtitle created': '字幕を作成しました',
  'Subtitle saved. The track is re-packaged in the background.':
    '字幕を保存しました。トラックはバックグラウンドで再パッケージされます。',
  'Subtitle not found': '字幕が見つかりません',
  'This subtitle may have been deleted. Go back to the list to pick another track.':
    'この字幕は削除された可能性があります。一覧に戻って別のトラックを選択してください。',
  'Loading cues...': 'キューを読み込み中...',
  "Couldn't load this track": 'このトラックを読み込めませんでした',
  'Embedded tracks can be read once they are packaged.':
    '埋め込みトラックはパッケージ後に読み込めます。',
  'The subtitle file could not be read.': '字幕ファイルを読み込めませんでした。',
  'Start from an empty track': '空のトラックから開始',
  Cues: 'キュー',
  'Failed to download subtitles': '字幕のダウンロードに失敗しました',
  'No cues': 'キューがありません',
  'No cues match your search': '検索に一致するキューはありません',
  'Search cues...': 'キューを検索...',
  'Only cues with issues': '問題のあるキューのみ',
  'No cues yet. Play the video and press N to add a cue at the playhead, or import a WebVTT or SRT file.':
    'キューはまだありません。動画を再生して N キーで再生位置にキューを追加するか、WebVTT または SRT ファイルをインポートしてください。',
  'Select a cue in the list or on the timeline to update it.':
    '一覧またはタイムラインでキューを選択すると更新できます。',
  '{count} cues selected': '{count} 件のキューを選択中',
  'Cue {index} of {total}': 'キュー {index} / {total}',
  'Merge with next cue': '次のキューと結合',
  'Delete cue': 'キューを削除',
  'Set start to playhead': '開始を再生位置に設定',
  'Set end to playhead': '終了を再生位置に設定',
  'Use playhead': '再生位置を使用',
  'Cue text': 'キューのテキスト',
  '{value} chars/sec': '{value} 文字/秒',
  'Longest line: {value}': '最長行: {value}',
  'Lines: {value}': '行数: {value}',
  'Empty cue': '空のキュー',
  'Has errors': 'エラーあり',
  'Has warnings': '警告あり',
  'Showing now': '表示中',
  'End time must be after the start time.':
    '終了時刻は開始時刻より後にしてください。',
  'Cue has no text.': 'キューにテキストがありません。',
  'Overlaps the previous cue.': '前のキューと重なっています。',
  'Ends after the video does.': '動画の終了後に終わります。',
  'Reading speed is above 20 characters per second.':
    '読み取り速度が 1 秒あたり 20 文字を超えています。',
  'A line is longer than 42 characters.': '42 文字を超える行があります。',
  'More than 2 lines.': '3 行以上あります。',
  'Add cue at playhead': '再生位置にキューを追加',
  'Split cue at playhead': '再生位置でキューを分割',
  'Move the playhead inside a cue to split it.':
    '分割するには、再生ヘッドをキューの範囲内に移動してください。',
  'Back 1 second': '1 秒戻る',
  'Forward 1 second': '1 秒進む',
  'Playback speed': '再生速度',
  'Drag cues to move them, drag their edges to retime. Hold Alt to turn off snapping.':
    'キューをドラッグして移動し、端をドラッグしてタイミングを調整します。Alt キーを押している間はスナップが無効になります。',
  Zoom: 'ズーム',
  'Shift timings': 'タイミングをずらす',
  'Move cues earlier or later to fix a track that is out of sync. Cues never move before 0:00.':
    'キューを前後に移動して、ずれたトラックを修正します。キューが 0:00 より前に移動することはありません。',
  Offset: 'オフセット',
  'Seconds or a timestamp. Use a negative value to show cues earlier, e.g. -1.5 or -0:02.000.':
    '秒数またはタイムスタンプを入力します。キューを早めるには負の値を使用します（例: -1.5、-0:02.000）。',
  'All cues': 'すべてのキュー',
  'Selected cues': '選択したキュー',
  Shift: 'ずらす',
  'Cue timings shifted': 'キューのタイミングをずらしました',
  'Fix overlaps': '重なりを修正',
  'No overlapping cues': '重なっているキューはありません',
  'Trimmed {count} overlapping cues': '重なっていた {count} 件のキューを短縮しました',
  'Remove empty cues': '空のキューを削除',
  'No empty cues': '空のキューはありません',
  'Removed {count} empty cues': '{count} 件の空のキューを削除しました',
  'From your computer': 'コンピューターから',
  'From Storage': 'ストレージから',
  'Import subtitle file': '字幕ファイルをインポート',
  'Choose a .vtt or .srt file. Its cues replace the cues in the editor.':
    '.vtt または .srt ファイルを選択してください。エディター内のキューはファイルのキューに置き換わります。',
  'No cues found in this file': 'このファイルにキューが見つかりません',
  'Imported {count} cues. Undo to restore the previous cues.':
    '{count} 件のキューをインポートしました。元に戻すと以前のキューを復元できます。',
  'Failed to import subtitles': '字幕のインポートに失敗しました',
  'Download WebVTT': 'WebVTT をダウンロード',
  'Download SRT': 'SRT をダウンロード',
  'Add at least one cue.': 'キューを 1 件以上追加してください。',
  'Fix {count} cues with errors before saving.':
    '保存する前に、エラーのある {count} 件のキューを修正してください。',
  'Choose a language in Settings.': '設定で言語を選択してください。',
  'Enter a valid name in Settings.': '設定で有効な名前を入力してください。',
  'Choose a Storage bucket in Settings.':
    '設定でストレージのバケットを選択してください。',
  'The selected bucket does not accept .vtt files.':
    '選択したバケットは .vtt ファイルを受け付けません。',
  'Storage bucket': 'ストレージのバケット',
  'This bucket only allows specific file extensions and .vtt is not one of them. Choose another bucket or allow .vtt in the bucket settings.':
    'このバケットは特定の拡張子のみ許可しており、.vtt は含まれていません。別のバケットを選択するか、バケットの設定で .vtt を許可してください。',
  'Embedded tracks have no file in Storage. Saving uploads the cues as a new WebVTT file to this bucket and links the track to it.':
    '埋め込みトラックにはストレージ上のファイルがありません。保存すると、キューが新しい WebVTT ファイルとしてこのバケットにアップロードされ、トラックに関連付けられます。',
  'Saving uploads a new WebVTT file to this bucket and re-packages the track. The previous file is kept.':
    '保存すると、新しい WebVTT ファイルがこのバケットにアップロードされ、トラックが再パッケージされます。以前のファイルは保持されます。',
  'Discard unsaved changes?': '保存されていない変更を破棄しますか？',
  'Your subtitle changes have not been saved. Leaving this page discards them.':
    '字幕の変更は保存されていません。このページを離れると変更は破棄されます。',
  'Play or pause': '再生または一時停止',
  'Seek 1 second (Shift: 5 seconds)': '1 秒移動（Shift: 5 秒）',
  'Step one frame': '1 フレーム移動',
  'Previous or next cue': '前または次のキュー',
  'Update cue text': 'キューのテキストを更新',
  'Delete selected cues': '選択したキューを削除',
  'Read-only': '読み取り専用',
  'Empty cues are left out of the file.': '空のキューはファイルに含まれません。',
  'Previous cue': '前のキュー',
  'Next cue': '次のキュー',
  'Add cue': 'キューを追加',
  '+{count} more': 'ほか {count} 件',
  'Split cue': 'キューを分割',
  'More actions': 'その他の操作',
  'Mute or unmute': 'ミュートの切り替え',
  Milliseconds: 'ミリ秒',
  'Drag to adjust. Hold Shift for bigger steps or Alt for smaller ones.':
    'ドラッグして調整します。Shift キーで大きく、Alt キーで細かく調整できます。',
  'Click a cue to split it at the pointer. Hold Shift to keep splitting, press Esc to stop.':
    'キューをクリックすると、ポインターの位置で分割します。Shift キーを押したままにすると続けて分割でき、Esc キーで終了します。',

  // Renditions empty state
  'Encode this video for streaming': 'この動画をストリーミング用にエンコード',
  'Renditions are the quality levels a player switches between. Encode a few to stream this video smoothly on any device and connection.':
    'レンディションは、プレーヤーが切り替える画質レベルです。いくつかエンコードしておくと、どのデバイスや回線でもこの動画をスムーズに配信できます。',
  'Pick encoding profiles': 'エンコードプロファイルを選択',
  'Each profile you select becomes one rendition at its own resolution and bitrate.':
    '選択した各プロファイルが、それぞれの解像度とビットレートで 1 つのレンディションになります。',
  'Choose an output format': '出力形式を選択',
  'Package the renditions as HLS, DASH, or CMAF. Appwrite encodes them in the background.':
    'レンディションを HLS、DASH、CMAF のいずれかでパッケージ化します。エンコードは Appwrite がバックグラウンドで行います。',
  'Stream the manifest': 'マニフェストを配信',
  'Point any player at the manifest URL. It switches renditions as bandwidth changes.':
    '任意のプレーヤーにマニフェスト URL を指定します。帯域幅の変化に応じてレンディションが切り替わります。',

  // Subtitles empty state
  'Add subtitles to this video': 'この動画に字幕を追加',
  'Make this video accessible in every language your viewers speak. Players show the tracks you add here alongside the stream.':
    '視聴者が話すすべての言語でこの動画を楽しめるようにしましょう。ここで追加したトラックは、プレーヤーでストリームと一緒に表示されます。',
  'Write in the editor': 'エディターで作成',
  'Type captions while the video plays, then drag cues on the timeline to fine-tune their timing.':
    '動画を再生しながら字幕を入力し、タイムライン上のキューをドラッグしてタイミングを微調整します。',
  'Or import a file': 'ファイルをインポート',
  'Bring WebVTT or SRT files from Storage. Tracks embedded in the source are extracted automatically.':
    'ストレージから WebVTT または SRT ファイルを取り込みます。ソースに埋め込まれたトラックは自動で抽出されます。',
  'Ship with every stream': 'すべてのストリームで配信',
  'Subtitles are listed in the manifest, so viewers can pick a language in any player.':
    '字幕はマニフェストに含まれるため、視聴者はどのプレーヤーでも言語を選択できます。',

  // Captions (Stream v4 UI)
  'Create caption': '字幕を作成',
  'Update caption': '字幕を更新',
  'Delete caption': '字幕を削除',
  'Caption added': '字幕を追加しました',
  'Caption updated': '字幕を更新しました',
  'Caption deleted': '字幕を削除しました',
  'Default caption updated': 'デフォルト字幕を更新しました',
  'Failed to save caption': '字幕の保存に失敗しました',
  'Failed to delete caption': '字幕の削除に失敗しました',
  'Failed to download captions': '字幕のダウンロードに失敗しました',
  'Upload caption': '字幕をアップロード',
  'Add captions': '字幕を追加',
  'Add captions to this video': 'この動画に字幕を追加',
  'Back to captions': '字幕一覧に戻る',
  'Caption editor': '字幕エディター',
  'New caption': '新しい字幕',
  'Caption created': '字幕を作成しました',
  'Caption saved. The track is re-packaged in the background.':
    '字幕を保存しました。トラックはバックグラウンドで再パッケージ化されます。',
  'Caption not found': '字幕が見つかりません',
  'This caption may have been deleted. Go back to the list to pick another track.':
    'この字幕は削除された可能性があります。一覧に戻って別のトラックを選んでください。',
  'The caption file could not be read.': '字幕ファイルを読み取れませんでした。',
  'Failed to import captions': '字幕のインポートに失敗しました',
  'Import caption file': '字幕ファイルをインポート',
  'Your caption changes have not been saved. Leaving this page discards them.':
    '字幕の変更は保存されていません。このページを離れると変更は破棄されます。',
  'Default caption': 'デフォルト字幕',
  'Players turn this caption on automatically.':
    'プレーヤーはこの字幕を自動的にオンにします。',
  'Select caption file': '字幕ファイルを選択',
  'Attach a WebVTT or SRT file from Storage. Captions are segmented to match the stream and listed in every manifest.':
    'ストレージから WebVTT または SRT ファイルを添付します。字幕はストリームに合わせてセグメント化され、すべてのマニフェストに載ります。',
  'Caption URL': '字幕 URL',
  'Copy HLS caption URL': 'HLS 字幕 URL をコピー',
  'Copy DASH caption URL': 'DASH 字幕 URL をコピー',
  'Copy CMAF caption URL': 'CMAF 字幕 URL をコピー',
  'Open caption file': '字幕ファイルを開く',
  'No caption tracks. Add them from the Captions page.':
    '字幕トラックがありません。Captions ページから追加してください。',
  'Captions are listed in the manifest, so viewers can pick a language in any player.':
    '字幕はマニフェストに含まれるため、視聴者はどのプレーヤーでも言語を選択できます。',
}
