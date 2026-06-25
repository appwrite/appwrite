import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from 'remotion'
import { BrandTitleSuffix } from '../components/BrandTypography'
import { EnterSwooshSound } from '../components/EnterSwooshSound'
import {
  HomeHeroBrowserFrame,
} from '../components/HomeHeroBrowserFrame'
import {
  CONSOLE_SCREENSHOT_ENTER_FRAMES,
  CONSOLE_SCREENSHOT_EXIT_FRAMES,
  CONSOLE_SCREENSHOT_SCENE_DURATION,
  CONSOLE_SCREENSHOT_TITLE_DROP_FRAMES,
  CONSOLE_SCREENSHOT_TITLE_START,
  CONSOLE_TITLE,
  CONSOLE_SCREENSHOT_VIDEO_SRC,
  SWOOSH_SOUND_SRC,
  SWOOSH_SOUND_VOLUME,
} from '../constants'
import {
  CONSOLE_SCREENSHOT_TITLE_FONT_SIZE,
  getConsoleScreenshotShellMetrics,
} from '../lib/console-screenshot-layout'

/** Console screenshot rises from the bottom with title dropping in above. */
export function ConsoleScreenshotScene() {
  const frame = useCurrentFrame()
  const {
    frameWidth,
    shellHeight,
    restTranslateY,
    titleZoneHeight,
    titleHiddenY,
    browserHiddenY,
  } = getConsoleScreenshotShellMetrics()
  const exitStart =
    CONSOLE_SCREENSHOT_SCENE_DURATION - CONSOLE_SCREENSHOT_EXIT_FRAMES
  const isExiting = frame >= exitStart

  const exitProgress = interpolate(
    frame,
    [exitStart, CONSOLE_SCREENSHOT_SCENE_DURATION - 1],
    [0, 1],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.in(Easing.cubic),
    },
  )

  const browserTranslateY = isExiting
    ? interpolate(exitProgress, [0, 1], [restTranslateY, browserHiddenY])
    : interpolate(
        frame,
        [0, CONSOLE_SCREENSHOT_ENTER_FRAMES],
        [shellHeight, restTranslateY],
        {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
          easing: Easing.out(Easing.cubic),
        },
      )

  const titleFrame = Math.max(0, frame - CONSOLE_SCREENSHOT_TITLE_START)
  const showTitle = frame >= CONSOLE_SCREENSHOT_TITLE_START

  const titleTranslateY = !showTitle
    ? titleHiddenY
    : isExiting
      ? interpolate(exitProgress, [0, 1], [0, titleHiddenY])
      : interpolate(
          titleFrame,
          [0, CONSOLE_SCREENSHOT_TITLE_DROP_FRAMES],
          [titleHiddenY, 0],
          {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
            easing: Easing.out(Easing.cubic),
          },
        )

  return (
    <AbsoluteFill style={{ overflow: 'hidden' }}>
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={CONSOLE_SCREENSHOT_ENTER_FRAMES}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={CONSOLE_SCREENSHOT_EXIT_FRAMES}
        startFrame={exitStart}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />

      {showTitle ? (
        <div
          className="absolute left-0 right-0 flex items-center justify-center px-24 text-center"
          style={{
            top: 0,
            height: titleZoneHeight,
          }}
        >
          <h1
            className="font-aeonik flex items-baseline justify-center whitespace-nowrap leading-none tracking-[-0.022em]"
            style={{
              fontSize: CONSOLE_SCREENSHOT_TITLE_FONT_SIZE,
              color: '#fafafa',
              transform: `translateY(${titleTranslateY}px)`,
            }}
          >
            {CONSOLE_TITLE}
            <BrandTitleSuffix size="lg" fontSize={CONSOLE_SCREENSHOT_TITLE_FONT_SIZE} />
          </h1>
        </div>
      ) : null}

      <div
        className="absolute left-1/2"
        style={{
          bottom: 0,
          width: frameWidth,
          transform: `translateX(-50%) translateY(${browserTranslateY}px)`,
        }}
      >
        <HomeHeroBrowserFrame
          width={frameWidth}
          closed
          videoSrc={CONSOLE_SCREENSHOT_VIDEO_SRC}
          videoFitWidth
        />
      </div>
    </AbsoluteFill>
  )
}
