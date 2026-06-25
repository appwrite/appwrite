import type { ReactNode } from 'react'
import { useCurrentFrame } from 'remotion'
import { HERO_BROWSER_FRAME } from '../lib/hero-browser-frame'

const SHINE_BORDER_WIDTH = 1
const SHINE_CYCLE_FRAMES = 180
/** Caps how strong the animated ring reads on screen. */
const SHINE_INTENSITY = 0.42

type FlowPanelShineBorderProps = {
  children: ReactNode
  index: number
  width: number
  height: number
}

/** Soft rotating highlight on the flow panel border. */
export function FlowPanelShineBorder({
  children,
  index,
  width,
  height,
}: FlowPanelShineBorderProps) {
  const frame = useCurrentFrame()
  const { outerRadius, shellBorder } = HERO_BROWSER_FRAME
  const angle =
    ((frame + index * 55) % SHINE_CYCLE_FRAMES) * (360 / SHINE_CYCLE_FRAMES)

  const borderGradient = `conic-gradient(
    from ${angle}deg at 50% 50%,
    ${shellBorder} 0deg,
    rgba(255, 255, 255, 0.04) 48deg,
    rgba(255, 255, 255, 0.18) 78deg,
    rgba(253, 54, 110, 0.1) 102deg,
    rgba(124, 103, 254, 0.08) 132deg,
    rgba(255, 255, 255, 0.1) 162deg,
    ${shellBorder} 360deg
  )`

  const innerRadius = Math.max(0, outerRadius - SHINE_BORDER_WIDTH)

  return (
    <div
      style={{
        position: 'relative',
        width,
        height,
      }}
    >
      <div
        aria-hidden
        style={{
          position: 'absolute',
          inset: 0,
          borderRadius: outerRadius,
          padding: SHINE_BORDER_WIDTH,
          background: borderGradient,
          opacity: SHINE_INTENSITY,
          WebkitMask:
            'linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0)',
          WebkitMaskComposite: 'xor',
          maskComposite: 'exclude',
          pointerEvents: 'none',
        }}
      />
      <div
        style={{
          position: 'relative',
          width: '100%',
          height: '100%',
          borderRadius: innerRadius,
          overflow: 'hidden',
        }}
      >
        {children}
      </div>
    </div>
  )
}
