import type { CSSProperties } from 'react'
import {
  AbsoluteFill,
  Easing,
  interpolate,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion'
import { BRAND, FINALE_LIGHTS_SHUTDOWN_FRAMES, SCENES } from '../constants'

function getFinaleLightsStrength(frame: number): number {
  const { from } = SCENES.finale
  if (frame < from) return 1

  return interpolate(
    frame - from,
    [0, FINALE_LIGHTS_SHUTDOWN_FRAMES],
    [1, 0],
    {
      extrapolateRight: 'clamp',
      easing: Easing.inOut(Easing.quad),
    },
  )
}

/** Homepage hero soft lights - boosted motion for 1920×1080 video. */
const HERO_LIGHTS = {
  pink: {
    gradient:
      'radial-gradient(ellipse at 28% 72%, rgba(253, 54, 110, 0.4) 0%, rgba(253, 54, 110, 0.18) 30%, rgba(253, 54, 110, 0.06) 48%, transparent 62%)',
    width: 1620,
    height: 940,
    left: '-42%',
    bottom: '-36%',
    transformOrigin: '72% 82%',
  },
  purple: {
    gradient:
      'radial-gradient(ellipse at 72% 28%, rgba(124, 103, 254, 0.36) 0%, rgba(124, 103, 254, 0.16) 32%, rgba(124, 103, 254, 0.05) 50%, transparent 64%)',
    width: 1640,
    height: 960,
    right: '-40%',
    top: '-36%',
    transformOrigin: '28% 18%',
  },
} as const

type SoftLightProps = {
  phase?: number
  strength?: number
  style: CSSProperties
  gradient: string
}

function SoftLight({ phase = 0, strength = 1, style, gradient }: SoftLightProps) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const t = frame / fps + phase
  const motion = 0.25 + strength * 0.75

  const driftX =
    (Math.sin(t * 0.78) * 96 + Math.sin(t * 0.31 + 0.6) * 48) * motion
  const driftY =
    (Math.cos(t * 0.68 + 0.4) * 78 + Math.sin(t * 0.26 + 1.1) * 38) * motion
  const scale = 1 + Math.sin(t * 0.58 + 0.2) * 0.12 * motion
  const rotate = Math.sin(t * 0.44 + 1.4) * 5.5 * motion
  const opacity = (0.76 + Math.sin(t * 0.88 + 0.8) * 0.24) * strength

  return (
    <div
      className="absolute"
      style={{
        ...style,
        opacity,
        transform: `translate(${driftX}px, ${driftY}px) scale(${scale}) rotate(${rotate}deg)`,
        background: gradient,
        willChange: 'transform, opacity',
      }}
    />
  )
}

/** Homepage-style soft lights on brand background. */
export function BrandBackground() {
  const frame = useCurrentFrame()
  const lightsStrength = getFinaleLightsStrength(frame)

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.background, overflow: 'hidden' }}>
      <SoftLight
        phase={0}
        strength={lightsStrength}
        gradient={HERO_LIGHTS.pink.gradient}
        style={{
          left: HERO_LIGHTS.pink.left,
          bottom: HERO_LIGHTS.pink.bottom,
          width: HERO_LIGHTS.pink.width,
          height: HERO_LIGHTS.pink.height,
          transformOrigin: HERO_LIGHTS.pink.transformOrigin,
        }}
      />

      <SoftLight
        phase={2.4}
        strength={lightsStrength}
        gradient={HERO_LIGHTS.purple.gradient}
        style={{
          right: HERO_LIGHTS.purple.right,
          top: HERO_LIGHTS.purple.top,
          width: HERO_LIGHTS.purple.width,
          height: HERO_LIGHTS.purple.height,
          transformOrigin: HERO_LIGHTS.purple.transformOrigin,
        }}
      />
    </AbsoluteFill>
  )
}
