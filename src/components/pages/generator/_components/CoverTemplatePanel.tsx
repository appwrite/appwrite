import { useMemo } from 'react'
import type { CoverTemplateCategoryFilter } from '@/lib/cover-generator/template-categories'
import {
  COVER_HEIGHT,
  COVER_WIDTH,
  type CoverTemplateId,
  type CoverTheme,
} from '@/lib/cover-generator/constants'
import { buildCoverApiUrl, createDefaultCoverData } from '@/lib/cover-generator/parse-params'
import {
  COVER_TEMPLATE_CATEGORIES,
  getCoverTemplateCategorySections,
  getCoverTemplatesForCategory,
} from '@/lib/cover-generator/template-categories'
import { COVER_GENERATOR_TEMPLATE_PANEL_WIDTH_PX } from '@/components/pages/generator/layout'
import { COVER_TEMPLATE_DEFINITIONS } from '@/lib/cover-generator/template-config'
import { CoverCardsAngledPreview } from '@/components/pages/generator/_components/CoverCardsAngledPreview'
import { CoverScreenshotAngledPreview } from '@/components/pages/generator/_components/CoverScreenshotAngledPreview'
import { CoverThemeSelect } from '@/components/pages/generator/_components/CoverThemeSelect'
import { cn } from '@/lib/utils'

export type { CoverTemplateCategoryFilter as CoverTemplateTypeFilter } from '@/lib/cover-generator/template-categories'

type CoverTemplatePanelProps = {
  selectedTemplate: CoverTemplateId
  theme: CoverTheme
  categoryFilter: CoverTemplateCategoryFilter
  onSelectTemplate: (template: CoverTemplateId) => void
  onCategoryFilterChange: (filter: CoverTemplateCategoryFilter) => void
  onThemeChange: (theme: CoverTheme) => void
  variant?: 'panel' | 'compact'
}

function CoverTemplateCard({
  template,
  theme,
  selected,
  onSelect,
}: {
  template: CoverTemplateId
  theme: CoverTheme
  selected: boolean
  onSelect: () => void
}) {
  const previewData = useMemo(
    () => createDefaultCoverData(template, theme),
    [template, theme],
  )
  const usesDomPreview =
    template === 'cards-angled' || template === 'screenshot-angled'
  const previewUrl = useMemo(() => {
    if (typeof window === 'undefined' || usesDomPreview) return ''
    return buildCoverApiUrl({ ...previewData, format: 'png' }, window.location.origin)
  }, [previewData, usesDomPreview])
  const definition = COVER_TEMPLATE_DEFINITIONS.find((item) => item.id === template)
  const thumbnailScale = COVER_GENERATOR_TEMPLATE_PANEL_WIDTH_PX / COVER_WIDTH

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'flex w-full flex-col overflow-hidden rounded-md border text-left transition-colors',
        selected
          ? 'border-[var(--brand-cta)] bg-[var(--brand-cta)]/5 ring-1 ring-[var(--brand-cta)]/25'
          : 'border-border bg-card/40 hover:border-border hover:bg-accent/30',
      )}
    >
      <div className="relative aspect-[1200/630] w-full overflow-hidden border-b border-border bg-muted/20">
        {template === 'cards-angled' ? (
          <div
            className="pointer-events-none absolute left-0 top-0 origin-top-left"
            style={{
              width: COVER_WIDTH,
              height: COVER_HEIGHT,
              transform: `scale(${thumbnailScale})`,
            }}
          >
            <CoverCardsAngledPreview
              data={previewData as Extract<typeof previewData, { template: 'cards-angled' }>}
              width={COVER_WIDTH}
              height={COVER_HEIGHT}
            />
          </div>
        ) : template === 'screenshot-angled' ? (
          <div
            className="pointer-events-none absolute left-0 top-0 origin-top-left"
            style={{
              width: COVER_WIDTH,
              height: COVER_HEIGHT,
              transform: `scale(${thumbnailScale})`,
            }}
          >
            <CoverScreenshotAngledPreview
              data={
                previewData as Extract<typeof previewData, { template: 'screenshot-angled' }>
              }
              width={COVER_WIDTH}
              height={COVER_HEIGHT}
            />
          </div>
        ) : previewUrl ? (
          <img
            src={previewUrl}
            alt=""
            draggable={false}
            className="h-full w-full object-cover"
          />
        ) : null}
      </div>
      <div className="px-1.5 py-1">
        <span className="block truncate text-center text-[10px] font-medium text-foreground">
          {definition?.label ?? template}
        </span>
      </div>
    </button>
  )
}

export function CoverTemplatePanel({
  selectedTemplate,
  theme,
  categoryFilter,
  onSelectTemplate,
  onCategoryFilterChange,
  onThemeChange,
  variant = 'panel',
}: CoverTemplatePanelProps) {
  const categorySections = useMemo(
    () => getCoverTemplateCategorySections(categoryFilter),
    [categoryFilter],
  )

  const visibleTemplates = useMemo(
    () => getCoverTemplatesForCategory(categoryFilter),
    [categoryFilter],
  )

  if (variant === 'compact') {
    return (
      <div className="space-y-2">
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
        <div className="flex gap-1.5 overflow-x-auto pb-0.5">
          <FilterChip
            active={categoryFilter === 'all'}
            onClick={() => onCategoryFilterChange('all')}
            label="All"
          />
          {COVER_TEMPLATE_CATEGORIES.map((category) => (
            <FilterChip
              key={category.id}
              active={categoryFilter === category.id}
              onClick={() => onCategoryFilterChange(category.id)}
              label={category.label}
            />
          ))}
        </div>
        <div className="flex gap-2 overflow-x-auto pb-1">
          {visibleTemplates.map((template) => {
            const definition = COVER_TEMPLATE_DEFINITIONS.find(
              (item) => item.id === template,
            )
            return (
              <button
                key={template}
                type="button"
                onClick={() => onSelectTemplate(template)}
                className={cn(
                  'shrink-0 rounded-md border px-3 py-1.5 text-[12px] font-medium transition-colors',
                  selectedTemplate === template
                    ? 'border-[var(--brand-cta)]/40 bg-[var(--brand-cta)]/5 text-foreground'
                    : 'border-border text-muted-foreground hover:bg-accent/50',
                )}
              >
                {definition?.label ?? template}
              </button>
            )
          })}
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="shrink-0 space-y-2 border-b border-border px-2 py-2">
        <p className="text-[12px] font-medium text-foreground">Templates</p>
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
      </div>

      <div className="shrink-0 border-b border-border px-2 py-1.5">
        <p className="mb-1 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Category
        </p>
        <div className="flex flex-wrap gap-1">
          <FilterChip
            active={categoryFilter === 'all'}
            onClick={() => onCategoryFilterChange('all')}
            label="All"
          />
          {COVER_TEMPLATE_CATEGORIES.map((category) => (
            <FilterChip
              key={category.id}
              active={categoryFilter === category.id}
              onClick={() => onCategoryFilterChange(category.id)}
              label={category.label}
            />
          ))}
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-1.5">
        <div className="space-y-3">
          {categorySections.map((category) => (
            <section key={category.id}>
              {categoryFilter === 'all' ? (
                <p className="mb-1 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {category.label}
                </p>
              ) : null}
              <div className="grid grid-cols-1 gap-1">
                {category.templateIds.map((template) => (
                  <CoverTemplateCard
                    key={template}
                    template={template}
                    theme={theme}
                    selected={selectedTemplate === template}
                    onSelect={() => onSelectTemplate(template)}
                  />
                ))}
              </div>
            </section>
          ))}
        </div>
      </div>
    </div>
  )
}

function FilterChip({
  active,
  onClick,
  label,
}: {
  active: boolean
  onClick: () => void
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors',
        active
          ? 'bg-accent text-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      {label}
    </button>
  )
}
