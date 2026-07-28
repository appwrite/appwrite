import {
  Easing,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion'
import type { ReactNode } from 'react'

type BrandSlideEnterProps = {
  children: ReactNode
  delay?: number
}

/** Subtle marketing-style enter - opacity + small Y, no blur. */
export function BrandSlideEnter({ children, delay = 0 }: BrandSlideEnterProps) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const enter = spring({
    frame: frame - delay,
    fps,
    config: { damping: 200 },
    durationInFrames: 40,
  })

  const opacity = interpolate(enter, [0, 1], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  })

  const y = interpolate(enter, [0, 1], [14, 0], {
    easing: Easing.out(Easing.quad),
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  })

  return (
    <div style={{ opacity, transform: `translateY(${y}px)` }}>{children}</div>
  )
}
