import { useLayoutEffect, useMemo, useRef, useState } from 'react'
import { DiagramArtboard } from '@/components/pages/generator/diagrams/_components/DiagramArtboard'
import type { DiagramTemplateCatalogItem } from '@/lib/diagram-generator/diagram-template-catalog'
import { normalizeDiagramDocument } from '@/lib/diagram-generator/storage'
import { createDiagramFromTemplate } from '@/lib/diagram-generator/templates'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type DiagramTemplatePreviewCardProps = {
  template: DiagramTemplateCatalogItem
  onSelect: () => void
}

export function DiagramTemplatePreviewCard({
  template,
  onSelect,
}: DiagramTemplatePreviewCardProps) {
  const previewRef = useRef<HTMLDivElement>(null)
  const [displayWidth, setDisplayWidth] = useState(0)

  const document = useMemo(
    () => normalizeDiagramDocument(createDiagramFromTemplate(template.id)),
    [template.id],
  )
  const previewBackground = getCoverBrandThemeForSvgExport(document.theme).background
  const scale = displayWidth > 0 ? displayWidth / document.width : 0

  useLayoutEffect(() => {
    const element = previewRef.current
    if (!element) return

    const updateDisplayWidth = () => {
      const width = element.getBoundingClientRect().width
      if (width > 0) {
        setDisplayWidth(width)
      }
    }

    updateDisplayWidth()
    const resizeObserver = new ResizeObserver(updateDisplayWidth)
    resizeObserver.observe(element)
    return () => resizeObserver.disconnect()
  }, [])

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'flex w-full flex-col overflow-hidden rounded-xl border border-border bg-card/40 text-start transition-colors hover:bg-accent/40',
      )}
    >
      <div
        ref={previewRef}
        className="relative aspect-[1200/630] w-full overflow-hidden border-b border-border"
        style={{ backgroundColor: previewBackground }}
      >
        {displayWidth > 0 ? (
          <div
            className="pointer-events-none absolute left-0 top-0 origin-top-left"
            style={{
              width: document.width,
              height: document.height,
              transform: `scale(${scale})`,
            }}
          >
            <DiagramArtboard
              document={document}
              width={document.width}
              height={document.height}
              interactive={false}
            />
          </div>
        ) : null}
      </div>
      <div className="px-3 py-2.5">
        <span className="block text-[14px] font-medium text-foreground">
          {template.label}
        </span>
      </div>
    </button>
  )
}
