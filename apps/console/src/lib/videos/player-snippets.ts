/**
 * Install steps and code samples for the video Install tab. Each player lists
 * the master manifest kinds it can play; snippets embed the selected URL.
 */
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import type { VideoMasterManifestKind } from '@/lib/videos/urls'

export type PlayerPlatformId =
  | 'web'
  | 'apple'
  | 'android'
  | 'flutter'
  | 'react-native'

export type PlayerId =
  | 'hlsjs'
  | 'shaka'
  | 'videojs'
  | 'native'
  | 'avplayer'
  | 'exoplayer'
  | 'video-player'
  | 'react-native-video'

export type PlayerFrameworkId =
  | 'vanilla'
  | 'react'
  | 'swiftui'
  | 'uikit'
  | 'compose'
  | 'views'

export type PlayerPackageManagerId = 'npm' | 'bun' | 'pnpm' | 'yarn'

export interface PlayerCodeFile {
  label: string
  code: string
  language: CodeBlockLanguage
}

export interface PlayerInstallStep {
  label: string
  code: string
  language: CodeBlockLanguage
}

export interface PlayerDefinition {
  id: PlayerId
  label: string
  formats: VideoMasterManifestKind[]
  frameworks: { id: PlayerFrameworkId; label: string }[]
  /** npm-style package; null when nothing needs installing through a JS package manager. */
  npmPackage: string | null
  docsUrl: string
}

const HLS_FORMATS: VideoMasterManifestKind[] = ['hls', 'cmaf-hls']
const ALL_FORMATS: VideoMasterManifestKind[] = [
  'hls',
  'cmaf-hls',
  'dash',
  'cmaf-dash',
]
const WEB_FRAMEWORKS: PlayerDefinition['frameworks'] = [
  { id: 'vanilla', label: 'Vanilla' },
  { id: 'react', label: 'React' },
]

export const PLAYERS: Record<PlayerId, PlayerDefinition> = {
  hlsjs: {
    id: 'hlsjs',
    label: 'hls.js',
    formats: HLS_FORMATS,
    frameworks: WEB_FRAMEWORKS,
    npmPackage: 'hls.js',
    docsUrl: 'https://github.com/video-dev/hls.js',
  },
  shaka: {
    id: 'shaka',
    label: 'Shaka Player',
    formats: ['dash', 'cmaf-dash', 'hls', 'cmaf-hls'],
    frameworks: WEB_FRAMEWORKS,
    npmPackage: 'shaka-player',
    docsUrl: 'https://shaka-player-demo.appspot.com/docs/api/tutorial-welcome.html',
  },
  videojs: {
    id: 'videojs',
    label: 'Video.js',
    formats: ALL_FORMATS,
    frameworks: WEB_FRAMEWORKS,
    npmPackage: 'video.js',
    docsUrl: 'https://videojs.com/guides/',
  },
  native: {
    id: 'native',
    label: 'Native HTML',
    formats: HLS_FORMATS,
    frameworks: WEB_FRAMEWORKS,
    npmPackage: null,
    docsUrl:
      'https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/video',
  },
  avplayer: {
    id: 'avplayer',
    label: 'AVPlayer',
    formats: HLS_FORMATS,
    frameworks: [
      { id: 'swiftui', label: 'SwiftUI' },
      { id: 'uikit', label: 'UIKit' },
    ],
    npmPackage: null,
    docsUrl: 'https://developer.apple.com/documentation/avfoundation/avplayer',
  },
  exoplayer: {
    id: 'exoplayer',
    label: 'Media3 ExoPlayer',
    formats: ALL_FORMATS,
    frameworks: [
      { id: 'compose', label: 'Jetpack Compose' },
      { id: 'views', label: 'Views' },
    ],
    npmPackage: null,
    docsUrl: 'https://developer.android.com/media/media3/exoplayer',
  },
  'video-player': {
    id: 'video-player',
    label: 'video_player',
    formats: HLS_FORMATS,
    frameworks: [],
    npmPackage: null,
    docsUrl: 'https://pub.dev/packages/video_player',
  },
  'react-native-video': {
    id: 'react-native-video',
    label: 'react-native-video',
    formats: HLS_FORMATS,
    frameworks: [],
    npmPackage: 'react-native-video',
    docsUrl: 'https://docs.thewidlarzgroup.com/react-native-video/',
  },
}

export const PLAYER_PLATFORMS: {
  id: PlayerPlatformId
  label: string
  players: PlayerId[]
}[] = [
  { id: 'web', label: 'Web', players: ['hlsjs', 'shaka', 'videojs', 'native'] },
  { id: 'apple', label: 'Apple', players: ['avplayer'] },
  { id: 'android', label: 'Android', players: ['exoplayer'] },
  { id: 'flutter', label: 'Flutter', players: ['video-player'] },
  {
    id: 'react-native',
    label: 'React Native',
    players: ['react-native-video'],
  },
]

export const PLAYER_PACKAGE_MANAGERS: {
  id: PlayerPackageManagerId
  label: string
}[] = [
  { id: 'npm', label: 'npm' },
  { id: 'bun', label: 'bun' },
  { id: 'pnpm', label: 'pnpm' },
  { id: 'yarn', label: 'yarn' },
]

const MEDIA3_VERSION = '1.8.0'

function isDash(format: VideoMasterManifestKind) {
  return format === 'dash' || format === 'cmaf-dash'
}

function addPackageCommand(manager: PlayerPackageManagerId, pkg: string) {
  return manager === 'npm' ? `npm install ${pkg}` : `${manager} add ${pkg}`
}

/** English note for players that need no install, shown instead of steps. */
export function getPlayerInstallNote(player: PlayerId): string | null {
  switch (player) {
    case 'native':
      return 'Nothing to install. Safari and iOS play HLS natively. Other browsers need a library such as hls.js.'
    case 'avplayer':
      return 'Nothing to install. AVPlayer is part of AVKit on iOS, iPadOS, tvOS, visionOS, and macOS.'
    default:
      return null
  }
}

/** English install steps; labels are translated at the render site. */
export function getPlayerInstallSteps(
  player: PlayerId,
  format: VideoMasterManifestKind,
  packageManager: PlayerPackageManagerId,
): PlayerInstallStep[] {
  const definition = PLAYERS[player]
  switch (player) {
    case 'native':
    case 'avplayer':
      return []
    case 'exoplayer': {
      const module = isDash(format) ? 'dash' : 'hls'
      return [
        {
          label: 'Add to build.gradle.kts (module)',
          code: [
            `implementation("androidx.media3:media3-exoplayer:${MEDIA3_VERSION}")`,
            `implementation("androidx.media3:media3-exoplayer-${module}:${MEDIA3_VERSION}")`,
            `implementation("androidx.media3:media3-ui:${MEDIA3_VERSION}")`,
          ].join('\n'),
          language: 'kotlin',
        },
        {
          label: 'Allow network access in AndroidManifest.xml',
          code: '<uses-permission android:name="android.permission.INTERNET" />',
          language: 'markup',
        },
      ]
    }
    case 'video-player':
      return [
        {
          label: 'Terminal',
          code: 'flutter pub add video_player',
          language: 'bash',
        },
      ]
    case 'react-native-video':
      return [
        {
          label: 'Terminal',
          code: addPackageCommand(packageManager, 'react-native-video'),
          language: 'bash',
        },
        {
          label: 'Install the iOS pods',
          code: 'cd ios && pod install',
          language: 'bash',
        },
      ]
    default:
      return [
        {
          label: 'Terminal',
          code: addPackageCommand(packageManager, definition.npmPackage!),
          language: 'bash',
        },
      ]
  }
}

const VIDEO_ELEMENT_HTML = `<video id="player" controls playsinline></video>
<script type="module" src="/main.js"></script>`

function videoJsType(format: VideoMasterManifestKind) {
  return isDash(format) ? 'application/dash+xml' : 'application/x-mpegURL'
}

function webFiles(
  player: PlayerId,
  framework: PlayerFrameworkId,
  url: string,
  format: VideoMasterManifestKind,
): PlayerCodeFile[] {
  const react = framework === 'react'
  switch (player) {
    case 'hlsjs':
      return react
        ? [
            {
              label: 'VideoPlayer.tsx',
              language: 'typescript',
              code: `import { useEffect, useRef } from 'react'
import Hls from 'hls.js'

const src =
  '${url}'

export function VideoPlayer() {
  const videoRef = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    if (Hls.isSupported()) {
      const hls = new Hls()
      hls.loadSource(src)
      hls.attachMedia(video)
      return () => hls.destroy()
    }
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      // Safari plays HLS natively
      video.src = src
    }
  }, [])

  return <video ref={videoRef} controls playsInline />
}`,
            },
          ]
        : [
            { label: 'index.html', language: 'markup', code: VIDEO_ELEMENT_HTML },
            {
              label: 'main.js',
              language: 'javascript',
              code: `import Hls from 'hls.js'

const video = document.getElementById('player')
const src =
  '${url}'

if (Hls.isSupported()) {
  const hls = new Hls()
  hls.loadSource(src)
  hls.attachMedia(video)
} else if (video.canPlayType('application/vnd.apple.mpegurl')) {
  // Safari plays HLS natively
  video.src = src
}`,
            },
          ]
    case 'shaka':
      return react
        ? [
            {
              label: 'VideoPlayer.tsx',
              language: 'typescript',
              code: `import { useEffect, useRef } from 'react'
import shaka from 'shaka-player'

const src =
  '${url}'

export function VideoPlayer() {
  const videoRef = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    shaka.polyfill.installAll()
    const player = new shaka.Player()
    player
      .attach(video)
      .then(() => player.load(src))
      .catch(console.error)
    return () => {
      void player.destroy()
    }
  }, [])

  return <video ref={videoRef} controls playsInline />
}`,
            },
          ]
        : [
            { label: 'index.html', language: 'markup', code: VIDEO_ELEMENT_HTML },
            {
              label: 'main.js',
              language: 'javascript',
              code: `import shaka from 'shaka-player'

shaka.polyfill.installAll()

const video = document.getElementById('player')
const player = new shaka.Player()
await player.attach(video)
await player.load(
  '${url}',
)`,
            },
          ]
    case 'videojs':
      return react
        ? [
            {
              label: 'VideoPlayer.tsx',
              language: 'typescript',
              code: `import { useEffect, useRef } from 'react'
import videojs from 'video.js'
import 'video.js/dist/video-js.css'

const src =
  '${url}'

export function VideoPlayer() {
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const element = document.createElement('video-js')
    containerRef.current?.appendChild(element)
    const player = videojs(element, {
      controls: true,
      fluid: true,
      sources: [{ src, type: '${videoJsType(format)}' }],
    })
    return () => player.dispose()
  }, [])

  return <div ref={containerRef} />
}`,
            },
          ]
        : [
            {
              label: 'index.html',
              language: 'markup',
              code: `<video id="player" class="video-js" controls playsinline></video>
<script type="module" src="/main.js"></script>`,
            },
            {
              label: 'main.js',
              language: 'javascript',
              code: `import videojs from 'video.js'
import 'video.js/dist/video-js.css'

const player = videojs('player', { fluid: true })
player.src({
  src: '${url}',
  type: '${videoJsType(format)}',
})`,
            },
          ]
    default:
      return react
        ? [
            {
              label: 'VideoPlayer.tsx',
              language: 'typescript',
              code: `const src =
  '${url}'

export function VideoPlayer() {
  return <video src={src} controls playsInline />
}`,
            },
          ]
        : [
            {
              label: 'index.html',
              language: 'markup',
              code: `<video
  controls
  playsinline
  src="${url}"
></video>`,
            },
          ]
  }
}

function appleFiles(framework: PlayerFrameworkId, url: string): PlayerCodeFile[] {
  if (framework === 'uikit') {
    return [
      {
        label: 'PlayerViewController.swift',
        language: 'swift',
        code: `import AVKit
import UIKit

final class PlayerViewController: UIViewController {
    private let streamURL = URL(
        string: "${url}"
    )!

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        let controller = AVPlayerViewController()
        controller.player = AVPlayer(url: streamURL)
        present(controller, animated: true) {
            controller.player?.play()
        }
    }
}`,
      },
    ]
  }
  return [
    {
      label: 'PlayerView.swift',
      language: 'swift',
      code: `import AVKit
import SwiftUI

struct PlayerView: View {
    @State private var player = AVPlayer(
        url: URL(string: "${url}")!
    )

    var body: some View {
        VideoPlayer(player: player)
            .onAppear { player.play() }
            .onDisappear { player.pause() }
    }
}`,
    },
  ]
}

function androidFiles(
  framework: PlayerFrameworkId,
  url: string,
  format: VideoMasterManifestKind,
): PlayerCodeFile[] {
  const mimeType = isDash(format)
    ? 'MimeTypes.APPLICATION_MPD'
    : 'MimeTypes.APPLICATION_M3U8'
  const mediaItem = `MediaItem.Builder()
    .setUri(STREAM_URL)
    .setMimeType(${mimeType})
    .build()`
  if (framework === 'views') {
    return [
      {
        label: 'PlayerActivity.kt',
        language: 'kotlin',
        code: `import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView

private const val STREAM_URL =
    "${url}"

class PlayerActivity : AppCompatActivity() {
    private var player: ExoPlayer? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_player)
    }

    override fun onStart() {
        super.onStart()
        player = ExoPlayer.Builder(this).build().also { exo ->
            findViewById<PlayerView>(R.id.player_view).player = exo
            exo.setMediaItem(
                ${mediaItem.replaceAll('\n', '\n                ')}
            )
            exo.prepare()
            exo.playWhenReady = true
        }
    }

    override fun onStop() {
        super.onStop()
        player?.release()
        player = null
    }
}`,
      },
      {
        label: 'res/layout/activity_player.xml',
        language: 'markup',
        code: `<androidx.media3.ui.PlayerView
    xmlns:android="http://schemas.android.com/apk/res/android"
    android:id="@+id/player_view"
    android:layout_width="match_parent"
    android:layout_height="match_parent" />`,
      },
    ]
  }
  return [
    {
      label: 'PlayerScreen.kt',
      language: 'kotlin',
      code: `import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView

private const val STREAM_URL =
    "${url}"

@Composable
fun PlayerScreen(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val player = remember {
        ExoPlayer.Builder(context).build().apply {
            setMediaItem(
                ${mediaItem.replaceAll('\n', '\n                ')}
            )
            prepare()
            playWhenReady = true
        }
    }
    DisposableEffect(player) {
        onDispose { player.release() }
    }
    AndroidView(
        modifier = modifier,
        factory = { PlayerView(it).apply { this.player = player } },
    )
}`,
    },
  ]
}

function flutterFiles(url: string): PlayerCodeFile[] {
  return [
    {
      label: 'lib/player_screen.dart',
      language: 'dart',
      code: `import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

const streamUrl =
    '${url}';

class PlayerScreen extends StatefulWidget {
  const PlayerScreen({super.key});

  @override
  State<PlayerScreen> createState() => _PlayerScreenState();
}

class _PlayerScreenState extends State<PlayerScreen> {
  late final VideoPlayerController _controller;

  @override
  void initState() {
    super.initState();
    _controller = VideoPlayerController.networkUrl(
      Uri.parse(streamUrl),
      formatHint: VideoFormat.hls,
    )..initialize().then((_) {
        setState(() {});
        _controller.play();
      });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_controller.value.isInitialized) {
      return const Center(child: CircularProgressIndicator());
    }
    return AspectRatio(
      aspectRatio: _controller.value.aspectRatio,
      child: VideoPlayer(_controller),
    );
  }
}`,
    },
  ]
}

function reactNativeFiles(url: string): PlayerCodeFile[] {
  return [
    {
      label: 'VideoPlayer.tsx',
      language: 'typescript',
      code: `import { StyleSheet } from 'react-native'
import Video from 'react-native-video'

const src =
  '${url}'

export function VideoPlayer() {
  return (
    <Video
      source={{ uri: src, type: 'm3u8' }}
      style={styles.video}
      resizeMode="contain"
      controls
    />
  )
}

const styles = StyleSheet.create({
  video: { width: '100%', aspectRatio: 16 / 9 },
})`,
    },
  ]
}

export function getPlayerCodeFiles(
  player: PlayerId,
  framework: PlayerFrameworkId,
  url: string,
  format: VideoMasterManifestKind,
): PlayerCodeFile[] {
  switch (player) {
    case 'avplayer':
      return appleFiles(framework, url)
    case 'exoplayer':
      return androidFiles(framework, url, format)
    case 'video-player':
      return flutterFiles(url)
    case 'react-native-video':
      return reactNativeFiles(url)
    default:
      return webFiles(player, framework, url, format)
  }
}

/** English prompt for an AI agent; mirrors what the Install tab shows. */
export function buildPlayerSetupPrompt({
  videoName,
  platformLabel,
  playerLabel,
  frameworkLabel,
  formatLabel,
  url,
  installNote,
  installSteps,
  codeFiles,
}: {
  videoName: string
  platformLabel: string
  playerLabel: string
  frameworkLabel?: string
  formatLabel: string
  url: string
  installNote: string | null
  installSteps: PlayerInstallStep[]
  codeFiles: PlayerCodeFile[]
}): string {
  const target = frameworkLabel
    ? `${platformLabel} (${frameworkLabel})`
    : platformLabel
  const lines = [
    `Add playback for the Appwrite video "${videoName}" to my ${target} app using ${playerLabel}.`,
    '',
    `Stream URL (${formatLabel} master manifest): ${url}`,
    '',
    'The master manifest lists every rendition and subtitle track, so the player handles adaptive bitrate and captions on its own.',
    'Requests use the read permissions of the source file in Appwrite Storage. Public videos need read access for Any.',
    '',
    '## Install',
    ...(installNote ? ['', installNote] : []),
    ...installSteps.flatMap((step) => [
      '',
      `${step.label}:`,
      '```' + step.language,
      step.code,
      '```',
    ]),
    '',
    '## Code',
    ...codeFiles.flatMap((file) => [
      '',
      `### ${file.label}`,
      '```' + file.language,
      file.code,
      '```',
    ]),
  ]
  return lines.join('\n')
}
