import type { Models } from '@appwrite.io/console'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { cn } from '@/lib/utils'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { useT } from '@/lib/i18n/translate'

const SELECTION_PAGE_SIZE = 5
const SELECTION_ROW_HEIGHT_CLASS = 'h-[46px]'
const SELECTION_LIST_MIN_HEIGHT_CLASS = 'min-h-[262px]'

interface DowngradeProjectSelectionProps {
  projects: Models.Project[]
  total: number
  page: number
  projectsLimit: number
  selectedProjectIds: Set<string>
  onToggleProject: (projectId: string) => void
  onPageChange: (page: number) => void
  loading?: boolean
  paginationDisabled?: boolean
}

export function DowngradeProjectSelection({
  projects,
  total,
  page,
  projectsLimit,
  selectedProjectIds,
  onToggleProject,
  onPageChange,
  loading = false,
  paginationDisabled = false,
}: DowngradeProjectSelectionProps) {
  const t = useT()
  const projectSelectionValid = selectedProjectIds.size === projectsLimit
  const totalPages = Math.max(1, Math.ceil(total / SELECTION_PAGE_SIZE))
  const safePage = Math.min(page, totalPages)
  const pageStart = (safePage - 1) * SELECTION_PAGE_SIZE
  const pageEnd = Math.min(pageStart + projects.length, total)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-[15px] font-semibold leading-normal text-foreground">
              {t('Choose projects to keep')}
            </h3>
            <p className="text-[13px] leading-normal text-muted-foreground mt-2">
              {t('The target plan allows')} {projectsLimit}{' '}
              {projectsLimit === 1 ? t('project') : t('projects')}.{' '}
              {t('Unselected projects and everything in them will be deleted.')}
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
          <WarningAlert title={t('Select projects to keep')}>
            {t('Choose exactly')} {projectsLimit}{' '}
            {projectsLimit === 1 ? t('project') : t('projects')}{' '}
            {t('to continue.')}
          </WarningAlert>
        ) : null}

        <div className={cn('space-y-2', SELECTION_LIST_MIN_HEIGHT_CLASS)}>
          {loading ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Loading projects...')}
            </p>
          ) : (
            <>
              {projects.map((project) => {
                const selected = selectedProjectIds.has(project.$id)
                const disabled =
                  !selected && selectedProjectIds.size >= projectsLimit

                return (
                  <div
                    key={project.$id}
                    className={cn(
                      'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                      SELECTION_ROW_HEIGHT_CLASS,
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
                      <p
                        className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground"
                        title={project.name}
                      >
                        {formatProjectNameForDisplay(project.name)}
                      </p>
                    </Label>
                  </div>
                )
              })}
              {Array.from({
                length: Math.max(0, SELECTION_PAGE_SIZE - projects.length),
              }).map((_, index) => (
                <div
                  key={`project-selection-spacer-${index}`}
                  className={SELECTION_ROW_HEIGHT_CLASS}
                  aria-hidden
                />
              ))}
            </>
          )}
        </div>

        {total > SELECTION_PAGE_SIZE ? (
          <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
            <p className="text-[12px] text-muted-foreground">
              {t('Showing')} {pageStart + 1}-{pageEnd} {t('of')} {total}{' '}
              {t('projects')}
            </p>
            <div className="flex items-center gap-1">
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-8 w-8"
                onClick={() => onPageChange(safePage - 1)}
                disabled={safePage <= 1 || loading || paginationDisabled}
                aria-label={t('Previous projects page')}
              >
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-8 w-8"
                onClick={() => onPageChange(safePage + 1)}
                disabled={safePage >= totalPages || loading || paginationDisabled}
                aria-label={t('Next projects page')}
              >
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
