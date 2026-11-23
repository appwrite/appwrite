import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverContentLayoutTransform } from '@/lib/cover-generator/cover-layout-scale'
import { CoverOgBrandBackground } from '@/lib/cover-generator/og/brand-background'
import type { PreparedCoverData } from '@/lib/cover-generator/og/prepare-cover-data'
import { ScreenshotAngledOg } from '@/lib/cover-generator/og/templates/screenshot-angled'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/themes'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverOgRootProps = {
  data: Extract<CoverRenderData, { template: 'screenshot-angled' }>
  prepared: PreparedCoverData
}

export function CoverOgRoot({ data, prepared }: CoverOgRootProps) {
  const { scale, translateX, translateY } = getCoverContentLayoutTransform(
    data.width,
    data.height,
  )
  const brand = getCoverBrandThemeForSvgExport(data.theme)

  return (
    <div
      style={{
        position: 'relative',
        display: 'flex',
        width: data.width,
        height: data.height,
        overflow: 'hidden',
        backgroundColor: brand.background,
      }}
    >
      <CoverOgBrandBackground
        themeId={data.theme}
        width={data.width}
        height={data.height}
      />
      <div
        style={{
          position: 'absolute',
          inset: 0,
          display: 'flex',
        }}
      >
        <div
          style={{
            width: COVER_WIDTH,
            height: COVER_HEIGHT,
            display: 'flex',
            position: 'relative',
            transform: `translate(${translateX}px, ${translateY}px) scale(${scale})`,
            transformOrigin: 'top left',
          }}
        >
          <div style={{ position: 'absolute', inset: 0, display: 'flex' }}>
            <ScreenshotAngledOg
              data={data}
              themeId={data.theme}
              prepared={prepared}
            />
          </div>
        </div>
      </div>
    </div>
  )
}
