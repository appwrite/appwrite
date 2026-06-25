import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from 'remotion'
import { BrandTitleSuffix } from '../components/BrandTypography'
import {
  CONSOLE_TITLE,
  CONSOLE_TITLE_DROP_FRAMES,
  CONSOLE_TITLE_START,
  VIDEO,
} from '../constants'

const TITLE_FONT_SIZE = 84

export function ConsoleShowcaseScene() {
  const frame = useCurrentFrame()
  const titleFrame = Math.max(0, frame - CONSOLE_TITLE_START)

  const dropProgress = interpolate(
    titleFrame,
    [0, CONSOLE_TITLE_DROP_FRAMES],
    [0, 1],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.out(Easing.cubic),
    },
  )

  const titleDropY = interpolate(
    dropProgress,
    [0, 1],
    [-(VIDEO.height * 0.12 + TITLE_FONT_SIZE), 0],
  )

  return (
    <AbsoluteFill className="flex items-center justify-center px-24 text-center">
      <h1
        className="font-aeonik flex items-baseline justify-center whitespace-nowrap leading-none tracking-[-0.022em]"
        style={{
          fontSize: TITLE_FONT_SIZE,
          color: '#fafafa',
          transform: `translateY(${titleDropY}px)`,
        }}
      >
        {CONSOLE_TITLE}
        <BrandTitleSuffix size="lg" fontSize={TITLE_FONT_SIZE} />
      </h1>
    </AbsoluteFill>
  )
}
