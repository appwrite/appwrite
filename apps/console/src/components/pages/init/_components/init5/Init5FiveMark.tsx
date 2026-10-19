import { useId, type CSSProperties } from 'react'
import {
  INIT5_FIVE_GLYPH_PATH,
  INIT5_FIVE_GLYPH_VIEWBOX,
} from '@/lib/init/init5-hero/five-glyph'
import { cn } from '@/lib/utils'

function useSvgId(prefix: string) {
  return `${prefix}-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`
}

const stop = (color: string): CSSProperties => ({ stopColor: color })

/**
 * Sticky collapsed-bar accent: a small 5 with a light streak tracing its
 * outline (a nod to the original Init line-art logo). SVG only - no WebGL.
 */
export function Init5CompactMark({
  animate = true,
  className,
}: {
  animate?: boolean
  className?: string
}) {
  const gradientId = useSvgId('init5-compact')
  return (
    <div
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-y-0 end-[14%] hidden items-center sm:flex',
        className,
      )}
    >
      <svg
        viewBox={INIT5_FIVE_GLYPH_VIEWBOX}
        className="h-10 w-auto overflow-visible"
      >
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" style={stop('var(--network-globe-region)')} />
            <stop offset="50%" style={stop('var(--brand-cta)')} />
            <stop offset="100%" style={stop('var(--network-globe-edge)')} />
          </linearGradient>
        </defs>
        <path
          d={INIT5_FIVE_GLYPH_PATH}
          fill={`url(#${gradientId})`}
          opacity={0.12}
        />
        <path
          d={INIT5_FIVE_GLYPH_PATH}
          fill="none"
          stroke={`url(#${gradientId})`}
          strokeWidth={1.2}
          strokeLinejoin="round"
          strokeLinecap="round"
          pathLength={100}
          strokeDasharray="24 76"
        >
          {animate ? (
            <animate
              attributeName="stroke-dashoffset"
              values="100;0"
              dur="3.6s"
              repeatCount="indefinite"
            />
          ) : null}
        </path>
      </svg>
    </div>
  )
}

/** Static, WebGL-free 5 used when a scene can't boot (no WebGL, lost context, chunk error). */
export function Init5StaticFive({
  className,
  style,
}: {
  className?: string
  style?: CSSProperties
}) {
  const gradientId = useSvgId('init5-static')
  return (
    <svg
      aria-hidden
      viewBox={INIT5_FIVE_GLYPH_VIEWBOX}
      className={cn('overflow-visible', className)}
      style={style}
    >
      <defs>
        <linearGradient id={gradientId} x1="0.1" y1="0" x2="0.9" y2="1">
          <stop offset="0%" style={stop('var(--network-globe-region)')} />
          <stop offset="45%" style={stop('var(--brand-cta)')} />
          <stop offset="100%" style={stop('var(--network-globe-edge)')} />
        </linearGradient>
      </defs>
      <path
        d={INIT5_FIVE_GLYPH_PATH}
        fill={`url(#${gradientId})`}
        style={{
          filter:
            'drop-shadow(0 0 24px color-mix(in oklch, var(--brand-cta) 45%, transparent))',
        }}
      />
    </svg>
  )
}
