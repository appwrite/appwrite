import { AbsoluteFill } from 'remotion'
import type { ReactNode } from 'react'
import {
  type SlideDirection,
  SCENE_EXIT_FRAMES,
  useSlideTransition,
} from '../lib/slide-transition'

type BrandSceneProps = {
  children: ReactNode
  durationInFrames: number
  exitTo?: SlideDirection
  exitFrames?: number
  skipExit?: boolean
}

export function BrandScene({
  children,
  durationInFrames,
  exitTo = 'left',
  exitFrames = SCENE_EXIT_FRAMES,
  skipExit = false,
}: BrandSceneProps) {
  const { opacity, transform } = useSlideTransition({
    durationInFrames,
    exitTo,
    exitFrames,
    skipExit,
  })

  return (
    <AbsoluteFill style={{ overflow: 'hidden' }}>
      <AbsoluteFill style={{ opacity, transform }}>
        {children}
      </AbsoluteFill>
    </AbsoluteFill>
  )
}
