import { useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type {
  DistributionRelease,
  UpdateDistributionReleaseParams,
} from '@/lib/appwrite/distribution'
import {
  RELEASE_VERSION_MAX_LENGTH,
  notesError,
  notesToRows,
  rowsToNotes,
  sameNotes,
  versionError,
} from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import { useUpdateDistributionRelease } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ReleaseNotesFields } from './ReleaseNotesFields'

type UpdateReleaseProps = {
  projectId: string
  release: DistributionRelease | null
  onOpenChange: (open: boolean) => void
}

export function UpdateRelease({
  projectId,
  release,
  onOpenChange,
}: UpdateReleaseProps) {
  return (
    <Dialog
      open={release !== null}
      onOpenChange={(open) => {
        if (!open) onOpenChange(false)
      }}
    >
      {release ? (
        <UpdateReleaseContent
          key={release.$id}
          projectId={projectId}
          release={release}
          onClose={() => onOpenChange(false)}
        />
      ) : null}
    </Dialog>
  )
}

function UpdateReleaseContent({
  projectId,
  release,
  onClose,
}: {
  projectId: string
  release: DistributionRelease
  onClose: () => void
}) {
  const t = useT()
  const updateRelease = useUpdateDistributionRelease(projectId)
  const [version, setVersion] = useState(release.version)
  const [rows, setRows] = useState(() => notesToRows(release.notes))

  const trimmed = version.trim()
  const notes = rowsToNotes(rows)
  const changes: Omit<UpdateDistributionReleaseParams, 'appId' | 'releaseId'> =
    {
      ...(trimmed !== release.version ? { version: trimmed } : {}),
      ...(sameNotes(notes, release.notes) ? {} : { notes }),
    }
  const versionMessage = versionError(version)
  const valid = trimmed.length > 0 && !versionMessage && !notesError(rows)
  const changed = Object.keys(changes).length > 0

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!valid || !changed) return
    updateRelease.mutate(
      { appId: release.appId, releaseId: release.$id, ...changes },
      {
        onSuccess: () => {
          toast.success(t('Release updated'))
          onClose()
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to update release'))),
      },
    )
  }

  return (
    <DialogContent
      className="sm:max-w-lg p-0"
      onInteractOutside={(event) => {
        if (updateRelease.isPending) event.preventDefault()
      }}
      onEscapeKeyDown={(event) => {
        if (updateRelease.isPending) event.preventDefault()
      }}
    >
      <DialogHeader className="px-6 pt-6 pb-4 text-start">
        <DialogTitle>{t('Update release')}</DialogTitle>
        <DialogDescription className="text-[13px] mt-2">
          {t(
            'Submissions already sent to a store keep the version and notes they were sent with.',
          )}
        </DialogDescription>
      </DialogHeader>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="max-h-[60dvh] space-y-4 overflow-y-auto px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="update-release-version" className="text-[13px]">
              {t('Version')}
            </Label>
            <Input
              id="update-release-version"
              dir="ltr"
              value={version}
              onChange={(event) => setVersion(event.target.value)}
              maxLength={RELEASE_VERSION_MAX_LENGTH + 1}
              spellCheck={false}
              autoComplete="off"
              disabled={updateRelease.isPending}
              aria-invalid={versionMessage !== null}
              className={cn(
                'h-9 font-mono text-[13px]',
                versionMessage && 'border-destructive',
              )}
            />
            {versionMessage ? (
              <p className="text-[12px] text-destructive">
                {t(versionMessage)}
              </p>
            ) : null}
          </div>
          <ReleaseNotesFields
            id="update-release"
            rows={rows}
            onChange={setRows}
            disabled={updateRelease.isPending}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={onClose}
            disabled={updateRelease.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="submit"
            className="h-9 text-[13px]"
            disabled={!valid || !changed || updateRelease.isPending}
          >
            {t('Update')}
          </Button>
        </div>
      </form>
    </DialogContent>
  )
}
