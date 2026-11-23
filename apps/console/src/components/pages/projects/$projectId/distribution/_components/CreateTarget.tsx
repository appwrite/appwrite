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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { DistributionPlatform } from '@/lib/appwrite/distribution'
import {
  identifierHint,
  identifierLabel,
  isValidIdentifier,
} from '@/lib/distribution/identifiers'
import { useT } from '@/lib/i18n/translate'
import { useCreateDistributionTarget } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PlatformIcon, platformLabel } from './platform'
import {
  ConnectionField,
  SigningIdentityField,
  TrackField,
} from './TargetFields'
import { defaultTrack } from './target-options'

type CreateTargetProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  appId: string
  platforms: DistributionPlatform[]
  platform: DistributionPlatform
}

type TargetDraft = {
  platform: DistributionPlatform
  identifier: string
  connectionId: string
  signingIdentityId: string
  track: string
}

function emptyDraft(platform: DistributionPlatform): TargetDraft {
  return {
    platform,
    identifier: '',
    connectionId: '',
    signingIdentityId: '',
    track: defaultTrack(platform),
  }
}

export function CreateTarget({
  open,
  onOpenChange,
  projectId,
  appId,
  platforms,
  platform,
}: CreateTargetProps) {
  const t = useT()
  const [draft, setDraft] = useState(() => emptyDraft(platform))
  const createTarget = useCreateDistributionTarget(projectId)

  const identifier = draft.identifier.trim()
  const identifierValid = isValidIdentifier(draft.platform, identifier)
  const identifierInvalid = identifier.length > 0 && !identifierValid
  const prefix = 'create-target'

  const handleOpenChange = (next: boolean) => {
    if (createTarget.isPending) return
    if (!next) setDraft(emptyDraft(platform))
    onOpenChange(next)
  }

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!identifierValid) return
    createTarget.mutate(
      {
        appId,
        platform: draft.platform,
        identifier,
        ...(draft.connectionId ? { connectionId: draft.connectionId } : {}),
        ...(draft.signingIdentityId
          ? { signingIdentityId: draft.signingIdentityId }
          : {}),
        ...(draft.track ? { track: draft.track } : {}),
      },
      {
        onSuccess: () => {
          toast.success(t('Target added'))
          setDraft(emptyDraft(platform))
          onOpenChange(false)
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to add target'))),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add target')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'A target holds the store identity and build settings for one platform of this app.',
            )}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit}>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-platform`} className="text-[13px]">
                {t('Platform')}
              </Label>
              <Select
                value={draft.platform}
                onValueChange={(value) =>
                  setDraft(emptyDraft(value as DistributionPlatform))
                }
                disabled={platforms.length < 2 || createTarget.isPending}
              >
                <SelectTrigger
                  id={`${prefix}-platform`}
                  className="h-9 w-full text-[13px]"
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {platforms.map((option) => (
                    <SelectItem
                      key={option}
                      value={option}
                      className="text-[13px]"
                    >
                      <PlatformIcon platform={option} />
                      {platformLabel(option)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-identifier`} className="text-[13px]">
                {t(identifierLabel(draft.platform))}
              </Label>
              <Input
                id={`${prefix}-identifier`}
                dir="ltr"
                value={draft.identifier}
                onChange={(event) =>
                  setDraft({ ...draft, identifier: event.target.value })
                }
                placeholder="com.example.app"
                spellCheck={false}
                autoCapitalize="off"
                autoCorrect="off"
                autoComplete="off"
                autoFocus
                disabled={createTarget.isPending}
                aria-invalid={identifierInvalid}
                aria-describedby={`${prefix}-identifier-hint`}
                className={cn(
                  'h-9 font-mono text-[13px]',
                  identifierInvalid && 'border-destructive',
                )}
              />
              <p
                id={`${prefix}-identifier-hint`}
                className={cn(
                  'text-[12px]',
                  identifierInvalid
                    ? 'text-destructive'
                    : 'text-muted-foreground',
                )}
              >
                {t(identifierHint(draft.platform))}
              </p>
            </div>
            <ConnectionField
              key={`connection-${draft.platform}`}
              id={`${prefix}-connection`}
              projectId={projectId}
              platform={draft.platform}
              value={draft.connectionId}
              onChange={(connectionId) => setDraft({ ...draft, connectionId })}
              disabled={createTarget.isPending}
            />
            <SigningIdentityField
              key={`signing-${draft.platform}`}
              id={`${prefix}-signing-identity`}
              projectId={projectId}
              platform={draft.platform}
              value={draft.signingIdentityId}
              onChange={(signingIdentityId) =>
                setDraft({ ...draft, signingIdentityId })
              }
              disabled={createTarget.isPending}
            />
            <TrackField
              id={`${prefix}-track`}
              platform={draft.platform}
              value={draft.track}
              onChange={(track) => setDraft({ ...draft, track })}
              disabled={createTarget.isPending}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-9 text-[13px]"
              onClick={() => handleOpenChange(false)}
              disabled={createTarget.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              className="h-9 text-[13px]"
              disabled={!identifierValid || createTarget.isPending}
            >
              {t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
