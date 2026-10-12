import { useMemo, useState } from 'react'
import { FileImage } from 'lucide-react'
import { CoverTemplateCard } from '@/components/pages/generator/_components/CoverTemplateCard'
import { GeneratorSavedGenerationsPanel } from '@/components/pages/generator/_components/GeneratorSavedGenerationsPanel'
import { GeneratorStartShell } from '@/components/pages/generator/_components/GeneratorStartShell'
import { Button } from '@/components/ui/button'
import type { SavedCoverGeneration } from '@/lib/cover-generator/cover-generation-prefs'
import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import { DEFAULT_COVER_THEME_ID } from '@/lib/cover-generator/themes'
import {
  COVER_TEMPLATE_CATEGORIES,
  getCoverTemplatesForCategory,
  type CoverTemplateCategoryFilter,
} from '@/lib/cover-generator/template-categories'
import { COVER_TEMPLATE_DEFINITIONS } from '@/lib/cover-generator/template-config'

type CoverStartViewProps = {
  generations: SavedCoverGeneration[]
  isAuthenticated: boolean
  isDeleting?: boolean
  isRenaming?: boolean
  maxNameLength: number
  onSelectTemplate: (templateId: CoverTemplateId) => void
  onOpenGeneration: (generationId: string) => void
  onRenameGeneration: (generationId: string, name: string) => Promise<void>
  onDeleteGeneration: (generationId: string) => void
}

export function CoverStartView({
  generations,
  isAuthenticated,
  isDeleting = false,
  isRenaming = false,
  maxNameLength,
  onSelectTemplate,
  onOpenGeneration,
  onRenameGeneration,
  onDeleteGeneration,
}: CoverStartViewProps) {
  const [categoryFilter, setCategoryFilter] =
    useState<CoverTemplateCategoryFilter>('all')

  const templates = useMemo(
    () => getCoverTemplatesForCategory(categoryFilter),
    [categoryFilter],
  )

  const savedItems = useMemo(
    () =>
      generations.map((generation) => {
        const definition = COVER_TEMPLATE_DEFINITIONS.find(
          (entry) => entry.id === generation.templateId,
        )
        return {
          id: generation.id,
          name: generation.name,
          updatedAt: generation.updatedAt,
          subtitle: definition?.label,
        }
      }),
    [generations],
  )

  return (
    <GeneratorStartShell
      title="Create a cover"
      description="Continue a saved cover or start from a template. Your work is saved automatically while you edit."
      savedTitle="Saved covers"
      templatesTitle="Choose template"
      saved={
        <GeneratorSavedGenerationsPanel
          generations={savedItems}
          isAuthenticated={isAuthenticated}
          isDeleting={isDeleting}
          isRenaming={isRenaming}
          maxNameLength={maxNameLength}
          icon={FileImage}
          emptyTitle="No saved covers yet"
          emptyDescription="Pick a template to create your first cover."
          signInHint="Sign in to sync covers across devices."
          onOpenGeneration={onOpenGeneration}
          onRenameGeneration={onRenameGeneration}
          onDeleteGeneration={onDeleteGeneration}
        />
      }
      templates={
        <div className="min-w-0 space-y-4">
          <div className="sticky top-0 z-10 flex flex-wrap gap-1.5 bg-background pb-3 pt-0.5">
            <Button
              type="button"
              size="sm"
              variant={categoryFilter === 'all' ? 'secondary' : 'outline'}
              className="h-7 text-[11px]"
              onClick={() => setCategoryFilter('all')}
            >
              All
            </Button>
            {COVER_TEMPLATE_CATEGORIES.map((category) => (
              <Button
                key={category.id}
                type="button"
                size="sm"
                variant={categoryFilter === category.id ? 'secondary' : 'outline'}
                className="h-7 text-[11px]"
                onClick={() => setCategoryFilter(category.id)}
              >
                {category.label}
              </Button>
            ))}
          </div>
          <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            {templates.map((templateId) => (
              <CoverTemplateCard
                key={templateId}
                template={templateId}
                theme={DEFAULT_COVER_THEME_ID}
                onSelect={() => onSelectTemplate(templateId)}
              />
            ))}
          </div>
        </div>
      }
    />
  )
}
