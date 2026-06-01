import type { Models } from '@appwrite.io/console'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { cn } from '@/lib/utils'

interface DowngradeProjectSelectionProps {
  projects: Models.Project[]
  projectsLimit: number
  selectedProjectIds: Set<string>
  onToggleProject: (projectId: string) => void
}

export function DowngradeProjectSelection({
  projects,
  projectsLimit,
  selectedProjectIds,
  onToggleProject,
}: DowngradeProjectSelectionProps) {
  const projectSelectionValid = selectedProjectIds.size === projectsLimit

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-[15px] font-semibold leading-normal text-foreground">
              Choose projects to keep
            </h3>
            <p className="text-[13px] leading-normal text-muted-foreground mt-2">
              The target plan allows {projectsLimit} project
              {projectsLimit === 1 ? '' : 's'}. Unselected projects and
              everything in them will be deleted.
            </p>
          </div>
          <p className="text-[13px] font-medium leading-normal text-foreground shrink-0">
            {selectedProjectIds.size} / {projectsLimit}
          </p>
        </div>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {!projectSelectionValid ? (
          <WarningAlert title="Select projects to keep">
            Choose exactly {projectsLimit} project
            {projectsLimit === 1 ? '' : 's'} to continue.
          </WarningAlert>
        ) : null}

        <div className="space-y-2">
          {projects.map((project) => {
            const selected = selectedProjectIds.has(project.$id)
            const disabled =
              !selected && selectedProjectIds.size >= projectsLimit

            return (
              <div
                key={project.$id}
                className={cn(
                  'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                  selected
                    ? 'border-primary bg-primary/5'
                    : 'border-border bg-background/60',
                  disabled && 'opacity-50',
                )}
              >
                <Checkbox
                  id={`keep-project-${project.$id}`}
                  checked={selected}
                  disabled={disabled}
                  onCheckedChange={() => onToggleProject(project.$id)}
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor={`keep-project-${project.$id}`}
                  className={cn(
                    'min-w-0 flex-1 cursor-pointer',
                    disabled && 'cursor-not-allowed',
                  )}
                >
                  <p className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground">
                    {project.name}
                  </p>
                </Label>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
