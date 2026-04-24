import { useRef } from 'react'
import { FolderGit2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CopyableId } from '@/components/global/shared/CopyableId'

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

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Target</h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Enter a project ID to load its blocks.
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-3">
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

        {focusedProjectId && (
          <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-background px-3 py-2">
            <FolderGit2 className="h-4 w-4 text-muted-foreground" />
            <span className="text-[13px] font-medium text-foreground">
              Project
            </span>
            <CopyableId id={focusedProjectId} size="sm" maxWidth={240} />
          </div>
        )}
      </div>
    </div>
  )
}
