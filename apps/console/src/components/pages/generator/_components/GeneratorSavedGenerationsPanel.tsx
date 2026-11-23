import { useState } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Pencil, Trash2 } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RenameSavedGenerationDialog } from '@/components/pages/generator/_components/RenameSavedGenerationDialog'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export type GeneratorSavedGenerationItem = {
  id: string
  name: string
  updatedAt: number
  subtitle?: string
}

type GeneratorSavedGenerationsPanelProps = {
  generations: GeneratorSavedGenerationItem[]
  isAuthenticated: boolean
  isDeleting?: boolean
  isRenaming?: boolean
  maxNameLength: number
  emptyTitle: string
  emptyDescription: string
  signInHint: string
  icon: LucideIcon
  onOpenGeneration: (generationId: string) => void
  onRenameGeneration: (generationId: string, name: string) => Promise<void>
  onDeleteGeneration: (generationId: string) => void
}

export function GeneratorSavedGenerationsPanel({
  generations,
  isAuthenticated,
  isDeleting = false,
  isRenaming = false,
  maxNameLength,
  emptyTitle,
  emptyDescription,
  signInHint,
  icon: Icon,
  onOpenGeneration,
  onRenameGeneration,
  onDeleteGeneration,
}: GeneratorSavedGenerationsPanelProps) {
  const [renameTarget, setRenameTarget] = useState<GeneratorSavedGenerationItem | null>(
    null,
  )
  const isBusy = isDeleting || isRenaming

  const handleRenameSubmit = async (name: string) => {
    if (!renameTarget) return
    await onRenameGeneration(renameTarget.id, name)
    setRenameTarget(null)
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {!isAuthenticated ? (
        <p className="mb-4 text-[12px] text-muted-foreground">{signInHint}</p>
      ) : null}

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-card/50">
        {generations.length === 0 ? (
          <EmptyState
            icon={Icon}
            title={emptyTitle}
            description={emptyDescription}
            isEmpty
            variant="centered"
            className="min-h-0 flex-1 py-8"
          />
        ) : (
          <ul
            className={cn(
              'min-h-0 flex-1 divide-y divide-border overflow-y-auto',
              isBusy && 'pointer-events-none opacity-60',
            )}
          >
            {generations.map((generation) => (
              <li
                key={generation.id}
                className="flex items-center gap-3 bg-card/30 px-4 py-3.5 transition-colors hover:bg-accent/40"
              >
                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted/50 text-muted-foreground">
                  <Icon className="size-4" />
                </div>
                <button
                  type="button"
                  onClick={() => onOpenGeneration(generation.id)}
                  className="min-w-0 flex-1 text-start"
                >
                  <span className="block truncate text-[13px] font-medium text-foreground">
                    {generation.name}
                  </span>
                  <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                    {generation.subtitle ? (
                      <>
                        {generation.subtitle}
                        <span className="mx-1.5 text-border">·</span>
                      </>
                    ) : null}
                    Updated{' '}
                    <DateTooltip date={new Date(generation.updatedAt).toISOString()} />
                  </span>
                </button>
                <div className="flex shrink-0 items-center gap-1.5">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-7 text-[12px]"
                    onClick={() => onOpenGeneration(generation.id)}
                  >
                    Open
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-7 shrink-0 text-muted-foreground"
                    disabled={isBusy}
                    aria-label="Update name"
                    onClick={() => setRenameTarget(generation)}
                  >
                    <Pencil className="size-3.5" />
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-7 shrink-0 text-muted-foreground"
                    disabled={isBusy}
                    aria-label="Delete"
                    onClick={() => onDeleteGeneration(generation.id)}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      <RenameSavedGenerationDialog
        open={renameTarget != null}
        onOpenChange={(open) => {
          if (!open) setRenameTarget(null)
        }}
        initialName={renameTarget?.name ?? ''}
        maxLength={maxNameLength}
        isSubmitting={isRenaming}
        onSubmit={handleRenameSubmit}
      />
    </div>
  )
}
