import { useRef } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { useBlocks } from '@/lib/react-query/hooks/manager'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'

export function TargetBar({
  draft,
  onDraftChange,
  onSubmit,
  focusedProjectId,
  onClear,
}: {
  draft: string
  onDraftChange: (v: string) => void
  onSubmit: () => void
  focusedProjectId: string | null
  onClear: () => void
}) {
  const inputRef = useRef<HTMLInputElement | null>(null)

  // Reads from the same react-query cache that BlocksList populates -
  // deduped by the shared query key so this does not trigger a separate fetch.
  const { data } = useBlocks(focusedProjectId)
  const meta = data?.blocks?.[0]

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Target</h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Enter a project ID to load its blocks.
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-end gap-2">
          <div className="flex-1 space-y-2">
            <Label htmlFor="blocks-project-id">Project ID</Label>
            <Input
              id="blocks-project-id"
              ref={inputRef}
              value={draft}
              onChange={(e) => onDraftChange(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  onSubmit()
                }
              }}
              placeholder="Enter project ID"
              spellCheck={false}
              autoComplete="off"
              className="h-9 max-w-md border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            disabled={!draft.trim()}
            onClick={onSubmit}
          >
            Load
          </Button>
          {focusedProjectId && (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              className="h-9 text-[13px]"
              onClick={onClear}
            >
              Clear
            </Button>
          )}
        </div>

        {meta?.projectName && (
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-md border border-border bg-background px-4 py-3 text-[13px]">
            <dt className="text-muted-foreground">Project</dt>
            <dd className="flex flex-wrap items-center gap-2 font-medium text-foreground min-w-0">
              <span className="truncate" title={meta.projectName}>
                {formatProjectNameForDisplay(meta.projectName)}
              </span>
              {meta.region && (
                <Badge
                  variant="secondary"
                  className="h-5 px-1.5 text-[11px] font-normal uppercase tracking-wide"
                >
                  {meta.region}
                </Badge>
              )}
            </dd>

            {meta.organizationName && (
              <>
                <dt className="text-muted-foreground">Organization</dt>
                <dd className="flex flex-wrap items-center gap-2 font-medium text-foreground">
                  <span>{meta.organizationName}</span>
                  {meta.billingPlan && (
                    <Badge
                      variant="outline"
                      className="h-5 px-1.5 text-[11px] font-normal capitalize"
                    >
                      {meta.billingPlan} plan
                    </Badge>
                  )}
                </dd>
              </>
            )}
          </dl>
        )}
      </div>
    </div>
  )
}
