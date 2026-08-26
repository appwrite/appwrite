/** Brand tokens aligned with cover generator dark theme and marketing homepage. */
import { getFeatureSceneDuration } from './lib/feature-scene-timing'
import { getFlowPanelsSceneDuration } from './lib/flow-panels-timing'
import { FLOW_PANEL_LAYOUTS } from './lib/flow-panels-layout'
import { getTitleSceneDuration } from './lib/title-scene-timing'

import { FPS, VIDEO } from './lib/video-config'

export { FPS, VIDEO }

export const CONSOLE_TITLE_START = 8
export const CONSOLE_TITLE_DROP_FRAMES = 26

export const CONSOLE_SCREENSHOT_ENTER_FRAMES = 36
export const CONSOLE_SCREENSHOT_EXIT_FRAMES = CONSOLE_SCREENSHOT_ENTER_FRAMES
export const CONSOLE_SCREENSHOT_HOLD_FRAMES = FPS * 3
/** Title drop begins while the browser is still rising. */
export const CONSOLE_SCREENSHOT_TITLE_START = 18
export const CONSOLE_SCREENSHOT_TITLE_DROP_FRAMES = CONSOLE_TITLE_DROP_FRAMES
export const CONSOLE_SCREENSHOT_SCENE_DURATION =
  CONSOLE_SCREENSHOT_ENTER_FRAMES +
  CONSOLE_SCREENSHOT_HOLD_FRAMES +
  CONSOLE_SCREENSHOT_EXIT_FRAMES

export const FLOW_PANELS_SCENE_DURATION = getFlowPanelsSceneDuration(
  FLOW_PANEL_LAYOUTS.length,
)

export const BRAND = {
  background: '#19191c',
  foreground: '#fafafa',
  mutedForeground: '#a8a8b3',
  muted: '#2d2d31',
  border: '#3f4346',
  gridDot: 'rgba(63, 67, 70, 0.35)',
  cta: '#fd366e',
  purple: '#7c67fe',
  teal: '#85dbd8',
  orange: '#fe9567',
  logoGray: '#c4c6d7',
} as const

/** Left content column - matches cover generator `COVER_CONTENT_X` scaled to 1920. */
export const CONTENT_X = 154

export const INTRODUCING_TITLE = 'Introducing'

export const INTRO_TITLE = 'The new Appwrite Console'

export const THESIS_TITLE = 'Docs, API, and dashboard. One flow.'

export const CONSOLE_TITLE = 'Rebuilt to reduce friction'

export const SLOGAN = 'The open-source cloud for agents and developers'

export const AREA_VIDEO_SRC = 'videos/area.mp4'
export const CONSOLE_SCREENSHOT_VIDEO_SRC = 'videos/console-main.mp4'
export const EXPLORER_VIDEO_SRC = 'videos/explorer.mp4'
/** Native explorer recording size - used to size the split-layout browser frame. */
export const EXPLORER_VIDEO_WIDTH = 1724
export const EXPLORER_VIDEO_HEIGHT = 1080
export const EXPLORER_VIDEO_CONTENT_ASPECT =
  EXPLORER_VIDEO_HEIGHT / EXPLORER_VIDEO_WIDTH

/** Seconds of screen recording per feature beat. Replace clips in FEATURE_CLIPS when ready. */
export const FEATURE_CLIP_PLAY_FRAMES = 240
/** API explorer hold - longer so the screen recording can play through. */
export const EXPLORER_CLIP_PLAY_FRAMES = FPS * 2
/** Premount feature scenes so video decodes before the clip plays. */
export const FEATURE_CLIP_PREMOUNT_FRAMES = FPS * 2

export type FeatureClipConfig = {
  id: string
  text: string
  /** Multi-line title for split layout. Falls back to `text`. */
  textLines?: string[]
  pageTitle: string
  /** Screen recording for split layout clips. */
  videoSrc?: string
  videoStartFrame?: number
  /** Static screenshot under public/ for default layout clips. */
  imageSrc?: string
  videoDurationFrames: number
  /** Inner content height / width when using split layout. */
  contentAspectRatio?: number
  /** Title left, browser frame right (used for API explorer). */
  layout?: 'default' | 'split'
}

/** Swap `videoSrc`, `imageSrc`, or `videoStartFrame` per feature when assets are ready. */
export const FEATURE_CLIPS: FeatureClipConfig[] = [
  {
    id: 'audit-logs',
    text: 'Audit logs that actually help',
    pageTitle: 'Audit logs',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
  {
    id: 'usage-stats',
    text: 'Usage stats you can act on',
    pageTitle: 'Usage',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
  {
    id: 'realtime-playground',
    text: 'Realtime playground, built in',
    pageTitle: 'Realtime',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
  {
    id: 'api-explorer',
    text: 'API explorer at your fingertips',
    textLines: ['API explorer', 'at your fingertips'],
    pageTitle: 'API explorer',
    videoSrc: EXPLORER_VIDEO_SRC,
    videoStartFrame: 0,
    videoDurationFrames: EXPLORER_CLIP_PLAY_FRAMES,
    contentAspectRatio: EXPLORER_VIDEO_CONTENT_ASPECT,
    layout: 'split',
  },
  {
    id: 'keyboard',
    text: 'Built for the keyboard',
    pageTitle: 'Command center',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
  {
    id: 'speed',
    text: 'Faster where it counts',
    pageTitle: 'Console',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
  {
    id: 'unified-flow',
    text: 'From docs to deployment, one surface',
    pageTitle: 'Console',
    videoDurationFrames: FEATURE_CLIP_PLAY_FRAMES,
  },
]

const FINALE_DURATION = 206
/** Slow ambient light dim during the logo finale. */
export const FINALE_LIGHTS_SHUTDOWN_FRAMES = 130
/** Logo opacity ramp in the finale scene. */
export const FINALE_LOGO_FADE_FRAMES = 55
/** Logo fade-out at the end of the finale scene. */
export const FINALE_LOGO_FADE_OUT_FRAMES = 55
/** Start fade-out as lights finish dimming (was end-of-scene minus duration). */
export const FINALE_LOGO_FADE_OUT_START = 115

export const LAUNCH_MUSIC_SRC = 'audio/launch-music.wav'
/** Source track length (~33s @ 30fps). Loops for longer compositions. */
export const LAUNCH_MUSIC_SOURCE_FRAMES = 995
export const LAUNCH_MUSIC_PEAK_VOLUME = 0.2
/** Brief delay before audio mounts (WebAudio init). */
export const LAUNCH_MUSIC_START_DELAY_FRAMES = 5
/** WAV source is pre-trimmed; no MP3 padding skip needed. */
export const LAUNCH_MUSIC_TRIM_START_FRAMES = 0
/** Hold at zero volume after playback begins. */
export const LAUNCH_MUSIC_HOLD_AT_ZERO_FRAMES = 24
export const LAUNCH_MUSIC_FADE_IN_FRAMES = 60
export const LAUNCH_MUSIC_FADE_OUT_FRAMES = 90

/** Dragon Studio keyboard ASMR - converted to WAV in public/audio. */
export const TYPING_SOUND_SRC = 'audio/typing-keyboard.wav'
export const TYPING_SOUND_VOLUME = 0.4
/** Frames of each keystroke clip (maps to ~150ms at 30fps). */
export const TYPING_SOUND_CLIP_FRAMES = 5

export const SWOOSH_SOUND_SRC = 'audio/swoosh.wav'
/** Source clip length (~0.78s @ 30fps). */
export const SWOOSH_SOURCE_FRAMES = 24
export const SWOOSH_SOUND_VOLUME = 0.28

function buildSceneTimeline() {
  let from = 0

  const introducing = {
    id: 'introducing',
    text: INTRODUCING_TITLE,
    size: 'xl' as const,
    from,
    duration: getTitleSceneDuration(INTRODUCING_TITLE),
  }
  from += introducing.duration

  const intro = {
    id: 'intro',
    text: INTRO_TITLE,
    size: 'xl' as const,
    from,
    duration: getTitleSceneDuration(INTRO_TITLE),
  }
  from += intro.duration

  const consoleScreenshot = {
    id: 'console-screenshot',
    from,
    duration: CONSOLE_SCREENSHOT_SCENE_DURATION,
  }
  from += consoleScreenshot.duration

  const thesis = {
    id: 'thesis',
    text: THESIS_TITLE,
    size: 'lg' as const,
    from,
    duration: getTitleSceneDuration(THESIS_TITLE),
  }
  from += thesis.duration

  const flowPanels = {
    id: 'flow-panels',
    from,
    duration: FLOW_PANELS_SCENE_DURATION,
  }
  from += flowPanels.duration

  const features = FEATURE_CLIPS.map((clip) => {
    const duration = getFeatureSceneDuration(
      clip.text,
      clip.videoDurationFrames,
      clip.layout,
    )
    const entry = {
      ...clip,
      from,
      duration,
    }
    from += duration
    return entry
  })

  const slogan = {
    id: 'slogan',
    text: SLOGAN,
    size: 'lg' as const,
    from,
    duration: getTitleSceneDuration(SLOGAN),
  }
  from += slogan.duration

  const finale = { from, duration: FINALE_DURATION }

  return {
    introducing,
    intro,
    consoleScreenshot,
    thesis,
    flowPanels,
    features,
    slogan,
    finale,
    total: from + FINALE_DURATION,
  }
}

const timeline = buildSceneTimeline()

export const SCENES = {
  introducing: timeline.introducing,
  intro: timeline.intro,
  consoleScreenshot: timeline.consoleScreenshot,
  thesis: timeline.thesis,
  flowPanels: timeline.flowPanels,
  features: timeline.features,
  slogan: timeline.slogan,
  finale: timeline.finale,
} as const

export const DURATION_IN_FRAMES = timeline.total
