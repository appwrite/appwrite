import {
  AbsoluteFill,
  Easing,
  interpolate,
  useCurrentFrame,
} from 'remotion'
import { HomeHeroBrowserFrame } from '../components/HomeHeroBrowserFrame'
import { EnterSwooshSound } from '../components/EnterSwooshSound'
import {
  AREA_VIDEO_SRC,
  SWOOSH_SOUND_SRC,
  SWOOSH_SOUND_VOLUME,
  VIDEO,
} from '../constants'
import {
  getBrowserCardShellHeight,
  getBrowserCardWidth,
} from '../lib/browser-card-layout'
import { FEATURE_CLIP_ENTER_FRAMES } from '../lib/feature-scene-timing'
import {
  BROWSER_CARD_TILT,
  buildBrowserCardTransform,
} from '../lib/perspective-video'

/** Full-length product demo clip (legacy standalone scene). */
export function ProductVideoScene() {
  const frame = useCurrentFrame()
  const frameWidth = getBrowserCardWidth()
  const shellHeight = getBrowserCardShellHeight(frameWidth)
  const cardTransform = buildBrowserCardTransform()

  const enterProgress = interpolate(
    frame,
    [0, FEATURE_CLIP_ENTER_FRAMES],
    [0, 1],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.out(Easing.cubic),
    },
  )

  const enterX = interpolate(
    enterProgress,
    [0, 1],
    [VIDEO.width * 0.55 + frameWidth * 0.5, 0],
  )

  return (
    <AbsoluteFill className="flex items-center justify-center">
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={FEATURE_CLIP_ENTER_FRAMES}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <div
        style={{
          perspective: BROWSER_CARD_TILT.perspective,
          transformStyle: 'preserve-3d',
        }}
      >
        <div
          style={{
            width: frameWidth,
            height: shellHeight,
            transform: `translateX(${enterX}px) ${cardTransform}`,
            transformOrigin: 'center center',
            transformStyle: 'preserve-3d',
            backfaceVisibility: 'hidden',
          }}
        >
          <HomeHeroBrowserFrame
            width={frameWidth}
            videoSrc={AREA_VIDEO_SRC}
            videoTrimTop={1}
            closed
          />
        </div>
      </div>
    </AbsoluteFill>
  )
}
