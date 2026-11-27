import { useState, type FormEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
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
import {
  RELEASE_VERSION_MAX_LENGTH,
  notesError,
  notesToRows,
  rowsToNotes,
  versionError,
} from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import { useCreateDistributionRelease } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ReleaseNotesFields } from './ReleaseNotesFields'

type CreateReleaseProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  appId: string
}

export function CreateRelease({
  open,
  onOpenChange,
  projectId,
  appId,
}: CreateReleaseProps) {
  const t = useT()
  const navigate = useNavigate()
  const createRelease = useCreateDistributionRelease(projectId)
  const [version, setVersion] = useState('')
  const [rows, setRows] = useState(() => notesToRows({}))

  const versionMessage = versionError(version)
  const valid =
    version.trim().length > 0 && !versionMessage && !notesError(rows)

  const reset = () => {
    setVersion('')
    setRows(notesToRows({}))
  }

  const handleOpenChange = (next: boolean) => {
    if (createRelease.isPending) return
    if (!next) reset()
    onOpenChange(next)
  }

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!valid) return
    createRelease.mutate(
      { appId, version: version.trim(), notes: rowsToNotes(rows) },
      {
        onSuccess: (release) => {
          toast.success(t('Release created'))
          reset()
          onOpenChange(false)
          void navigate({
            to: '/projects/$projectId/distribution/$appId/releases/$releaseId',
            params: { projectId, appId, releaseId: release.$id },
          })
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to create release'))),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create release')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'A release is one version of this app. Submit it to each store from the release page.',
            )}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit}>
          <div className="border-t border-border" />
          <div className="max-h-[60dvh] space-y-4 overflow-y-auto px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor="create-release-version" className="text-[13px]">
                {t('Version')}
              </Label>
              <Input
                id="create-release-version"
                dir="ltr"
                value={version}
                onChange={(event) => setVersion(event.target.value)}
                placeholder="1.0.0"
                maxLength={RELEASE_VERSION_MAX_LENGTH + 1}
                spellCheck={false}
                autoComplete="off"
                autoFocus
                disabled={createRelease.isPending}
                aria-invalid={versionMessage !== null}
                className={cn(
                  'h-9 font-mono text-[13px]',
                  versionMessage && 'border-destructive',
                )}
              />
              <p
                className={cn(
                  'text-[12px]',
                  versionMessage ? 'text-destructive' : 'text-muted-foreground',
                )}
              >
                {t(
                  versionMessage ??
                    'The version users see in the stores, for example 1.2.0.',
                )}
              </p>
            </div>
            <ReleaseNotesFields
              id="create-release"
              rows={rows}
              onChange={setRows}
              disabled={createRelease.isPending}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-9 text-[13px]"
              onClick={() => handleOpenChange(false)}
              disabled={createRelease.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              className="h-9 text-[13px]"
              disabled={!valid || createRelease.isPending}
            >
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
