import { useMemo } from 'react'
import { Workflow } from 'lucide-react'
import { DiagramTemplatePreviewCard } from '@/components/pages/generator/diagrams/_components/DiagramTemplatePreviewCard'
import { GeneratorSavedGenerationsPanel } from '@/components/pages/generator/_components/GeneratorSavedGenerationsPanel'
import { GeneratorStartShell } from '@/components/pages/generator/_components/GeneratorStartShell'
import {
  DIAGRAM_TEMPLATE_CATALOG,
  getDiagramTemplateLabel,
} from '@/lib/diagram-generator/diagram-template-catalog'
import type { SavedDiagramGeneration } from '@/lib/diagram-generator/generation-prefs'
import type { DiagramTemplateId } from '@/lib/diagram-generator/types'

type DiagramStartViewProps = {
  generations: SavedDiagramGeneration[]
  isAuthenticated: boolean
  isDeleting?: boolean
  isRenaming?: boolean
  maxNameLength: number
  onSelectTemplate: (templateId: DiagramTemplateId) => void
  onOpenGeneration: (generationId: string) => void
  onRenameGeneration: (generationId: string, name: string) => Promise<void>
  onDeleteGeneration: (generationId: string) => void
}

export function DiagramStartView({
  generations,
  isAuthenticated,
  isDeleting = false,
  isRenaming = false,
  maxNameLength,
  onSelectTemplate,
  onOpenGeneration,
  onRenameGeneration,
  onDeleteGeneration,
}: DiagramStartViewProps) {
  const savedItems = useMemo(
    () =>
      generations.map((generation) => ({
        id: generation.id,
        name: generation.name,
        updatedAt: generation.updatedAt,
        subtitle: generation.templateId
          ? getDiagramTemplateLabel(generation.templateId)
          : undefined,
      })),
    [generations],
  )

  return (
    <GeneratorStartShell
      title="Create a diagram"
      description="Continue a saved diagram or start from a template. Your work is saved automatically while you edit."
      savedTitle="Saved diagrams"
      templatesTitle="Choose template"
      saved={
        <GeneratorSavedGenerationsPanel
          generations={savedItems}
          isAuthenticated={isAuthenticated}
          isDeleting={isDeleting}
          isRenaming={isRenaming}
          maxNameLength={maxNameLength}
          icon={Workflow}
          emptyTitle="No saved diagrams yet"
          emptyDescription="Pick a template to create your first diagram."
          signInHint="Sign in to sync diagrams across devices."
          onOpenGeneration={onOpenGeneration}
          onRenameGeneration={onRenameGeneration}
          onDeleteGeneration={onDeleteGeneration}
        />
      }
      templates={
        <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {DIAGRAM_TEMPLATE_CATALOG.map((template) => (
            <DiagramTemplatePreviewCard
              key={template.id}
              template={template}
              onSelect={() => onSelectTemplate(template.id)}
            />
          ))}
        </div>
      }
    />
  )
}
