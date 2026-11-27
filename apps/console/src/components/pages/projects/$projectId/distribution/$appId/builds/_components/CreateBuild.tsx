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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type {
  DistributionApp,
  DistributionBuildReferenceType,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  REFERENCE_TYPES,
  REFERENCE_TYPE_LABELS,
  parseVersionCode,
} from '@/lib/distribution/builds'
import { useT } from '@/lib/i18n/translate'
import { useCreateDistributionBuild } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PlatformIcon, platformLabel } from '../../../_components/platform'

type CreateBuildProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  app: DistributionApp
  targets: DistributionTarget[]
}

type BuildDraft = {
  targetId: string
  type: DistributionBuildReferenceType
  reference: string
  versionName: string
  versionCode: string
}

const REFERENCE_PLACEHOLDERS: Record<DistributionBuildReferenceType, string> = {
  branch: 'main',
  tag: 'v1.0.0',
  commit: '9f1c2e7',
}

const PREFIX = 'create-build'

function nextVersionCode(target: DistributionTarget | undefined): string {
  return target ? String(target.versionCode + 1) : ''
}

function emptyDraft(
  app: DistributionApp,
  targets: DistributionTarget[],
): BuildDraft {
  const target = targets[0]
  return {
    targetId: target?.$id ?? '',
    type: 'branch',
    reference: app.providerBranch,
    versionName: '',
    versionCode: nextVersionCode(target),
  }
}

export function CreateBuild({
  open,
  onOpenChange,
  projectId,
  app,
  targets,
}: CreateBuildProps) {
  const t = useT()
  const navigate = useNavigate()
  const [draft, setDraft] = useState(() => emptyDraft(app, targets))
  const createBuild = useCreateDistributionBuild(projectId)

  const target = targets.find((item) => item.$id === draft.targetId)
  const manual = target?.versionStrategy === 'manual'
  const reference = draft.reference.trim()
  const versionCode = parseVersionCode(draft.versionCode)
  const versionCodeInvalid =
    manual && draft.versionCode.trim() !== '' && !versionCode
  const valid = !!target && reference !== '' && (!manual || !!versionCode)
  const busy = createBuild.isPending

  const handleOpenChange = (next: boolean) => {
    if (busy) return
    if (!next) setDraft(emptyDraft(app, targets))
    onOpenChange(next)
  }

  const selectTarget = (targetId: string) =>
    setDraft({
      ...draft,
      targetId,
      versionCode: nextVersionCode(
        targets.find((item) => item.$id === targetId),
      ),
    })

  const selectType = (type: DistributionBuildReferenceType) =>
    setDraft({
      ...draft,
      type,
      reference: type === 'branch' ? app.providerBranch : '',
    })

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!valid || !target) return
    const versionName = draft.versionName.trim()
    createBuild.mutate(
      {
        appId: app.$id,
        targetId: target.$id,
        type: draft.type,
        reference,
        ...(versionName ? { versionName } : {}),
        ...(manual && versionCode ? { versionCode } : {}),
      },
      {
        onSuccess: (build) => {
          toast.success(t('Build started'))
          setDraft(emptyDraft(app, targets))
          onOpenChange(false)
          void navigate({
            to: '/projects/$projectId/distribution/$appId/builds/$buildId',
            params: { projectId, appId: app.$id, buildId: build.$id },
          })
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to start build'))),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create build')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Build a target from a branch, tag or commit of the connected repository.',
            )}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit}>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor={`${PREFIX}-target`} className="text-[13px]">
                {t('Target')}
              </Label>
              <Select
                value={draft.targetId}
                onValueChange={selectTarget}
                disabled={busy}
              >
                <SelectTrigger
                  id={`${PREFIX}-target`}
                  className="h-9 w-full text-[13px]"
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {targets.map((option) => (
                    <SelectItem
                      key={option.$id}
                      value={option.$id}
                      className="text-[13px]"
                    >
                      <PlatformIcon platform={option.platform} />
                      {platformLabel(option.platform)}
                      <span
                        dir="ltr"
                        className="font-mono text-muted-foreground"
                      >
                        {option.identifier}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-4 sm:grid-cols-[140px_1fr]">
              <div className="space-y-2">
                <Label
                  htmlFor={`${PREFIX}-reference-type`}
                  className="text-[13px]"
                >
                  {t('Build from')}
                </Label>
                <Select
                  value={draft.type}
                  onValueChange={(value) =>
                    selectType(value as DistributionBuildReferenceType)
                  }
                  disabled={busy}
                >
                  <SelectTrigger
                    id={`${PREFIX}-reference-type`}
                    className="h-9 w-full text-[13px]"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {REFERENCE_TYPES.map((type) => (
                      <SelectItem
                        key={type}
                        value={type}
                        className="text-[13px]"
                      >
                        {t(REFERENCE_TYPE_LABELS[type])}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor={`${PREFIX}-reference`} className="text-[13px]">
                  {t(REFERENCE_TYPE_LABELS[draft.type])}
                </Label>
                <Input
                  id={`${PREFIX}-reference`}
                  dir="ltr"
                  value={draft.reference}
                  onChange={(event) =>
                    setDraft({ ...draft, reference: event.target.value })
                  }
                  placeholder={REFERENCE_PLACEHOLDERS[draft.type]}
                  spellCheck={false}
                  autoCapitalize="off"
                  autoCorrect="off"
                  autoComplete="off"
                  disabled={busy}
                  className="h-9 font-mono text-[13px]"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor={`${PREFIX}-version-name`} className="text-[13px]">
                {t('Version name')}{' '}
                <span className="font-normal text-muted-foreground">
                  {t('(optional)')}
                </span>
              </Label>
              <Input
                id={`${PREFIX}-version-name`}
                dir="ltr"
                value={draft.versionName}
                onChange={(event) =>
                  setDraft({ ...draft, versionName: event.target.value })
                }
                placeholder="1.0.0"
                autoComplete="off"
                disabled={busy}
                aria-describedby={`${PREFIX}-version-name-hint`}
                className="h-9 font-mono text-[13px]"
              />
              <p
                id={`${PREFIX}-version-name-hint`}
                className="text-[12px] text-muted-foreground"
              >
                {t('Leave empty to use the version set in your project.')}
              </p>
            </div>
            {manual && target ? (
              <div className="space-y-2">
                <Label
                  htmlFor={`${PREFIX}-version-code`}
                  className="text-[13px]"
                >
                  {t('Version code')}
                </Label>
                <Input
                  id={`${PREFIX}-version-code`}
                  dir="ltr"
                  type="number"
                  inputMode="numeric"
                  min={1}
                  step={1}
                  value={draft.versionCode}
                  onChange={(event) =>
                    setDraft({ ...draft, versionCode: event.target.value })
                  }
                  disabled={busy}
                  aria-invalid={versionCodeInvalid}
                  aria-describedby={
                    versionCodeInvalid
                      ? `${PREFIX}-version-code-error ${PREFIX}-version-code-hint`
                      : `${PREFIX}-version-code-hint`
                  }
                  className="h-9 font-mono text-[13px]"
                />
                <p
                  id={`${PREFIX}-version-code-hint`}
                  className="text-[12px] text-muted-foreground"
                >
                  {t('This target sets version codes manually.')}{' '}
                  {t('Last build number:')}{' '}
                  <span dir="ltr" className="font-mono">
                    {target.versionCode}
                  </span>
                </p>
                {versionCodeInvalid ? (
                  <p
                    id={`${PREFIX}-version-code-error`}
                    role="alert"
                    className="text-[12px] text-destructive"
                  >
                    {t('Enter a whole number from 1 to 2,100,000,000.')}
                  </p>
                ) : null}
              </div>
            ) : null}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-9 text-[13px]"
              onClick={() => handleOpenChange(false)}
              disabled={busy}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              className="h-9 text-[13px]"
              disabled={!valid || busy}
            >
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
