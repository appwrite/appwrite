import { Easing, interpolate, useCurrentFrame } from 'remotion'
import { EnterSwooshSound } from './EnterSwooshSound'
import {
  FlowPanelShineBorder,
} from './FlowPanelShineBorder'
import { HomeHeroBrowserFrame } from './HomeHeroBrowserFrame'
import { getHeroBrowserFrameHeight } from '../lib/hero-browser-frame'
import { SWOOSH_SOUND_SRC, SWOOSH_SOUND_VOLUME } from '../constants'
import type { FlowPanelEnterFrom } from '../lib/flow-panels-layout'
import {
  FLOW_PANEL_ENTER_FRAMES,
  FLOW_PANEL_EXIT_FRAMES,
  getFlowPanelEnterStart,
  getFlowPanelExitStart,
} from '../lib/flow-panels-timing'
import {
  getFlowPanelTravelDistanceX,
  getFlowPanelTravelDistanceY,
  FLOW_PANEL_CONTENT_ASPECT,
} from '../lib/flow-panels-layout'

type FlowPanelCardProps = {
  index: number
  panelCount: number
  width: number
  enterFrom: FlowPanelEnterFrom
  imageSrc?: string
}

function getPanelOffset(
  enterFrom: FlowPanelEnterFrom,
  direction: 'enter' | 'exit',
  progress: number,
  distanceX: number,
  distanceY: number,
) {
  const enterOffscreen = 1 - progress
  const exitOffscreen = progress

  if (direction === 'enter') {
    switch (enterFrom) {
      case 'left':
        return { x: -distanceX * enterOffscreen, y: 0 }
      case 'right':
        return { x: distanceX * enterOffscreen, y: 0 }
      case 'up':
        return { x: 0, y: -distanceY * enterOffscreen }
      case 'down':
        return { x: 0, y: distanceY * enterOffscreen }
    }
  }

  switch (enterFrom) {
    case 'left':
      return { x: distanceX * exitOffscreen, y: 0 }
    case 'right':
      return { x: -distanceX * exitOffscreen, y: 0 }
    case 'up':
      return { x: 0, y: -distanceY * exitOffscreen }
    case 'down':
      return { x: 0, y: distanceY * exitOffscreen }
  }
}

/** Flow panel frame - side cards from top, center from bottom. No opacity fade. */
export function FlowPanelCard({
  index,
  panelCount,
  width,
  enterFrom,
  imageSrc,
}: FlowPanelCardProps) {
  const frame = useCurrentFrame()
  const distanceX = getFlowPanelTravelDistanceX()
  const distanceY = getFlowPanelTravelDistanceY()
  const enterStart = getFlowPanelEnterStart(index)
  const enterEnd = enterStart + FLOW_PANEL_ENTER_FRAMES - 1
  const exitStart = getFlowPanelExitStart(index, panelCount)
  const exitEnd = exitStart + FLOW_PANEL_EXIT_FRAMES - 1
  const shellHeight = getHeroBrowserFrameHeight(width, true, true, FLOW_PANEL_CONTENT_ASPECT)

  let offsetX = 0
  let offsetY = 0

  if (frame >= enterStart && frame <= enterEnd) {
    const enterProgress = interpolate(frame, [enterStart, enterEnd], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.out(Easing.cubic),
    })
    const offset = getPanelOffset(
      enterFrom,
      'enter',
      enterProgress,
      distanceX,
      distanceY,
    )
    offsetX = offset.x
    offsetY = offset.y
  } else if (frame > enterEnd && frame < exitStart) {
    offsetX = 0
    offsetY = 0
  } else if (frame >= exitStart && frame <= exitEnd) {
    const exitProgress = interpolate(frame, [exitStart, exitEnd], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.in(Easing.cubic),
    })
    const offset = getPanelOffset(
      enterFrom,
      'exit',
      exitProgress,
      distanceX,
      distanceY,
    )
    offsetX = offset.x
    offsetY = offset.y
  } else if (frame > exitEnd) {
    const offset = getPanelOffset(enterFrom, 'exit', 1, distanceX, distanceY)
    offsetX = offset.x
    offsetY = offset.y
  } else {
    const offset = getPanelOffset(enterFrom, 'enter', 0, distanceX, distanceY)
    offsetX = offset.x
    offsetY = offset.y
  }

  return (
    <>
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={FLOW_PANEL_ENTER_FRAMES}
        startFrame={enterStart}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={FLOW_PANEL_EXIT_FRAMES}
        startFrame={exitStart}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <div
      style={{
        width,
        flexShrink: 0,
        transform: `translate(${offsetX}px, ${offsetY}px)`,
        willChange: 'transform',
      }}
    >
      <FlowPanelShineBorder
        index={index}
        width={width}
        height={shellHeight}
      >
        <HomeHeroBrowserFrame
          width={width}
          closed
          hideChrome
          contentAspectRatio={FLOW_PANEL_CONTENT_ASPECT}
          emptyContent={!imageSrc}
          imageSrc={imageSrc}
        />
      </FlowPanelShineBorder>
    </div>
    </>
  )
}
