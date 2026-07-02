import { useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { CoverTemplateId, CoverTheme } from '@/lib/cover-generator/constants'
import { buildCoverApiUrl, createDefaultCoverData } from '@/lib/cover-generator/parse-params'
import { COVER_GENERATOR_TEMPLATE_PANEL_WIDTH_PX } from '@/components/pages/generator/layout'
import { COVER_TEMPLATE_DEFINITIONS } from '@/lib/cover-generator/template-config'
import {
  CoverScaledPreview,
  isCoverGeneratorDomPreviewTemplate,
} from '@/components/pages/generator/_components/CoverPreviewContent'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverTemplateCardProps = {
  template: CoverTemplateId
  theme: CoverTheme
  selected?: boolean
  variant?: 'start' | 'panel'
  onSelect: () => void
}

export function CoverTemplateCard({
  template,
  theme,
  selected = false,
  variant = 'start',
  onSelect,
}: CoverTemplateCardProps) {
  const previewRef = useRef<HTMLDivElement>(null)
  const [previewWidth, setPreviewWidth] = useState(
    COVER_GENERATOR_TEMPLATE_PANEL_WIDTH_PX,
  )
  const previewData = useMemo(
    () => createDefaultCoverData(template, theme),
    [template, theme],
  )
  const usesDomPreview = isCoverGeneratorDomPreviewTemplate(template)
  const previewUrl = useMemo(() => {
    if (typeof window === 'undefined' || usesDomPreview) return ''
    return buildCoverApiUrl({ ...previewData, format: 'png' }, window.location.origin)
  }, [previewData, usesDomPreview])
  const definition = COVER_TEMPLATE_DEFINITIONS.find((item) => item.id === template)
  const previewBackground = getCoverBrandThemeForSvgExport(theme).background

  useLayoutEffect(() => {
    const element = previewRef.current
    if (!element) return

    const updatePreviewWidth = () => {
      const width = element.getBoundingClientRect().width
      if (width > 0) {
        setPreviewWidth(width)
      }
    }

    updatePreviewWidth()
    const resizeObserver = new ResizeObserver(updatePreviewWidth)
    resizeObserver.observe(element)
    return () => resizeObserver.disconnect()
  }, [])

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'flex w-full flex-col overflow-hidden border text-start transition-colors',
        variant === 'panel' ? 'rounded-md' : 'rounded-xl',
        selected
          ? 'border-[var(--brand-cta)] bg-[var(--brand-cta)]/5 ring-1 ring-[var(--brand-cta)]/25'
          : 'border-border bg-card/40 hover:bg-accent/40',
      )}
    >
      <div
        ref={previewRef}
        className="relative aspect-[1200/630] w-full overflow-hidden border-b border-border"
        style={{ backgroundColor: previewBackground }}
      >
        {previewWidth > 0 ? (
          <CoverScaledPreview
            data={previewData}
            previewUrl={previewUrl || undefined}
            displayWidth={previewWidth}
            className="absolute left-0 top-0"
          />
        ) : null}
      </div>
      <div className={variant === 'panel' ? 'px-1.5 py-1' : 'px-3 py-2.5'}>
        <span
          className={cn(
            'block font-medium text-foreground',
            variant === 'panel'
              ? 'truncate text-center text-[10px]'
              : 'text-[14px]',
          )}
        >
          {definition?.label ?? template}
        </span>
      </div>
    </button>
  )
}
