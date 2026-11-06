import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import type { DiagramAlignGuide } from '@/lib/diagram-generator/node-align-snap'

type DiagramAlignGuidesLayerProps = {
  guides: DiagramAlignGuide[]
  brand: CoverBrandTheme
  width: number
  height: number
}

export function DiagramAlignGuidesLayer({
  guides,
  brand,
  width,
  height,
}: DiagramAlignGuidesLayerProps) {
  if (guides.length === 0) return null

  return (
    <svg
      className="pointer-events-none absolute inset-0 overflow-visible"
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      overflow="visible"
      aria-hidden
    >
      {guides.map((guide, index) =>
        guide.orientation === 'vertical' ? (
          <line
            key={`v-${index}`}
            x1={guide.position}
            y1={guide.start}
            x2={guide.position}
            y2={guide.end}
            stroke={brand.brandCta}
            strokeWidth={1}
            strokeDasharray="4 4"
            opacity={0.9}
          />
        ) : (
          <line
            key={`h-${index}`}
            x1={guide.start}
            y1={guide.position}
            x2={guide.end}
            y2={guide.position}
            stroke={brand.brandCta}
            strokeWidth={1}
            strokeDasharray="4 4"
            opacity={0.9}
          />
        ),
      )}
    </svg>
  )
}
