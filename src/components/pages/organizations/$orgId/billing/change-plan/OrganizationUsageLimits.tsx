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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

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
          Select Projects to Keep
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          You have {projects.length} projects, but the selected plan allows only{' '}
          {targetLimit}. Please select {targetLimit} project
          {targetLimit !== 1 ? 's' : ''} to keep.
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        {!isValid && (
          <Alert variant="destructive" className="mb-4">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>Invalid Selection</AlertTitle>
            <AlertDescription className="mt-2">
              Please select exactly {targetLimit} project
              {targetLimit !== 1 ? 's' : ''}.
            </AlertDescription>
          </Alert>
        )}

        <div className="space-y-3">
          {projects.map((project) => {
            const selected = isSelected(project.$id)
            const disabled = !selected && !canSelect

            return (
              <div
                key={project.$id}
                className={cn(
                  'flex items-start space-x-3 rounded-lg border p-3 transition-colors',
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
                    'flex-1 cursor-pointer',
                    disabled && 'cursor-not-allowed',
                  )}
                >
                  <div className="text-[13px] font-medium text-foreground">
                    {project.name}
                  </div>
                  <div className="text-[12px] text-muted-foreground mt-0.5">
                    {project.$id}
                  </div>
                </Label>
              </div>
            )
          })}
        </div>

        <div className="mt-4 text-[12px] text-muted-foreground">
          Selected: {selectedProjects.size} / {targetLimit}
        </div>
      </div>
    </div>
  )
})

OrganizationUsageLimits.displayName = 'OrganizationUsageLimits'
