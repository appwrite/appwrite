import {
  useState,
  useImperativeHandle,
  forwardRef,
  useEffect,
  useCallback,
  useRef,
} from 'react'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { cn } from '@/lib/utils'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface OrganizationUsageLimitsProps {
  projects: Models.Project[]
  orgUsage: unknown
  members: unknown
  organization: unknown
  targetLimit: number
  onRef: (ref: unknown) => void
}

export const OrganizationUsageLimits = forwardRef<
  { getSelectedProjects: () => string[] },
  OrganizationUsageLimitsProps
>(({ projects, targetLimit, onRef }, ref) => {
  const t = useT()
  const [selectedProjects, setSelectedProjects] = useState<Set<string>>(
    new Set(),
  )

  const getSelectedProjects = useCallback(
    () => Array.from(selectedProjects),
    [selectedProjects],
  )

  useImperativeHandle(
    ref,
    () => ({
      getSelectedProjects,
    }),
    [getSelectedProjects],
  )

  // Also call onRef callback with the ref object (only when selectedProjects changes)
  // Use a ref to store the callback to avoid recreating it
  const onRefRef = useRef(onRef)
  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  useEffect(() => {
    if (onRefRef.current) {
      onRefRef.current({ getSelectedProjects })
    }
  }, [getSelectedProjects])

  const toggleProject = (projectId: string) => {
    setSelectedProjects((prev) => {
      const newSet = new Set(prev)
      if (newSet.has(projectId)) {
        newSet.delete(projectId)
      } else {
        if (newSet.size < targetLimit) {
          newSet.add(projectId)
        }
      }
      return newSet
    })
  }

  const isSelected = (projectId: string) => selectedProjects.has(projectId)
  const canSelect = selectedProjects.size < targetLimit
  const isValid = selectedProjects.size === targetLimit

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Select projects to keep')}{' '}
          <span className="text-destructive">*</span>
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('You have')} {projects.length}{' '}
          {t('projects, but the selected plan allows only')} {targetLimit}.{' '}
          {t('Please select')} {targetLimit}{' '}
          {targetLimit !== 1 ? t('projects') : t('project')} {t('to keep.')}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        {!isValid && (
          <WarningAlert title={t('Invalid Selection')} className="mb-4">
            {t('Please select exactly')} {targetLimit}{' '}
            {targetLimit !== 1 ? t('projects') : t('project')}.
          </WarningAlert>
        )}

        <div className="min-w-0 space-y-3">
          {projects.map((project) => {
            const selected = isSelected(project.$id)
            const disabled = !selected && !canSelect

            return (
              <div
                key={project.$id}
                className={cn(
                  'flex min-w-0 items-start space-x-3 rounded-lg border p-3 transition-colors',
                  selected
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-muted/50',
                  disabled && 'opacity-50 cursor-not-allowed',
                )}
              >
                <Checkbox
                  id={project.$id}
                  checked={selected}
                  onCheckedChange={() => toggleProject(project.$id)}
                  disabled={disabled}
                  className="mt-1"
                />
                <Label
                  htmlFor={project.$id}
                  className={cn(
                    'min-w-0 flex-1 cursor-pointer',
                    disabled && 'cursor-not-allowed',
                  )}
                >
                  <div
                    className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground"
                    title={project.name}
                  >
                    {formatProjectNameForDisplay(project.name)}
                  </div>
                  <div className="min-w-0 truncate text-[12px] leading-normal text-muted-foreground mt-0.5">
                    {project.$id}
                  </div>
                </Label>
              </div>
            )
          })}
        </div>

        <div className="mt-4 text-[12px] text-muted-foreground">
          {t('Selected:')} {selectedProjects.size} / {targetLimit}
        </div>
      </div>
    </div>
  )
})

OrganizationUsageLimits.displayName = 'OrganizationUsageLimits'
