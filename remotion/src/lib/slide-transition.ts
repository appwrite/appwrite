import { Easing, interpolate, useCurrentFrame } from 'remotion'
import { VIDEO } from './video-config'

export type SlideDirection = 'left' | 'right' | 'up' | 'down'

/** Full viewport travel so the entire scene clears the frame. */
function getSlideDistance(direction: SlideDirection) {
  switch (direction) {
    case 'left':
    case 'right':
      return VIDEO.width
    case 'up':
    case 'down':
      return VIDEO.height
  }
}

export const SCENE_EXIT_FRAMES = 22

export function getSlideOffset(direction: SlideDirection, progress: number) {
  const distance = progress * getSlideDistance(direction)

  switch (direction) {
    case 'left':
      return { x: -distance, y: 0 }
    case 'right':
      return { x: distance, y: 0 }
    case 'up':
      return { x: 0, y: -distance }
    case 'down':
      return { x: 0, y: distance }
  }
}

/** Full-frame slide off-screen - no fade until the scene has cleared the viewport. */
export function getExitTransition(
  frame: number,
  exitStart: number,
  exitEnd: number,
  exitTo: SlideDirection,
) {
  if (frame < exitStart) {
    return { opacity: 1, transform: 'translate(0px, 0px)' }
  }

  const slideProgress = interpolate(frame, [exitStart, exitEnd], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.in(Easing.cubic),
  })

  const offset = getSlideOffset(exitTo, slideProgress)

  return {
    opacity: 1,
    transform: `translate(${offset.x}px, ${offset.y}px)`,
  }
}

type SlideTransitionOptions = {
  durationInFrames: number
  exitTo?: SlideDirection
  exitFrames?: number
  skipExit?: boolean
}

/** Exit-only slide - scenes appear instantly, type-in handles enter. */
export function useSlideTransition({
  durationInFrames,
  exitTo = 'left',
  exitFrames = SCENE_EXIT_FRAMES,
  skipExit = false,
}: SlideTransitionOptions) {
  const frame = useCurrentFrame()

  if (skipExit || exitFrames <= 0) {
    return { opacity: 1, transform: 'translate(0px, 0px)' }
  }

  const exitStart = durationInFrames - exitFrames
  const exitEnd = durationInFrames - 1

  return getExitTransition(frame, exitStart, exitEnd, exitTo)
}
