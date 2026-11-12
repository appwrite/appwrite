import { useMemo } from 'react'
import type { CoverTemplateCategoryFilter } from '@/lib/cover-generator/template-categories'
import {
  type CoverTemplateId,
  type CoverTheme,
} from '@/lib/cover-generator/constants'
import {
  COVER_TEMPLATE_CATEGORIES,
  getCoverTemplateCategorySections,
  getCoverTemplatesForCategory,
} from '@/lib/cover-generator/template-categories'
import { COVER_TEMPLATE_DEFINITIONS } from '@/lib/cover-generator/template-config'
import { CoverThemeSelect } from '@/components/pages/generator/_components/CoverThemeSelect'
import { CoverTemplateCard } from '@/components/pages/generator/_components/CoverTemplateCard'
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
      <div className="shrink-0 space-y-2 border-b border-border px-4 py-3">
        <p className="text-[12px] font-medium text-foreground">Templates</p>
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
      </div>

      <div className="shrink-0 border-b border-border px-4 py-2.5">
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

      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
        <div className="space-y-3">
          {categorySections.map((category) => (
            <section key={category.id}>
              {categoryFilter === 'all' ? (
                <p className="mb-1 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {category.label}
                </p>
              ) : null}
              <div className="grid grid-cols-1 gap-2">
                {category.templateIds.map((template) => (
                  <CoverTemplateCard
                    key={template}
                    template={template}
                    theme={theme}
                    variant="panel"
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
