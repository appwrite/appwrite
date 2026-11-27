import { useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
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
import { Switch } from '@/components/ui/switch'
import type {
  CreateDistributionSubmissionParams,
  DistributionProvider,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { artifactVersion } from '@/lib/distribution/publish'
import {
  MAX_ROLLOUT_PERCENT,
  MIN_ROLLOUT_PERCENT,
  ROLLOUT_MISMATCH_MESSAGE,
  isRolloutPercent,
  rolloutMismatch,
  rolloutMismatchMessage,
} from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import {
  readyDistributionArtifactsQueryOptions,
  useCreateDistributionSubmission,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PLATFORM_PROVIDERS, PlatformIcon } from '../../../_components/platform'
import {
  TRACKS,
  defaultTrack,
  trackLabel,
} from '../../../_components/target-options'

const SUBMIT_TITLES: Record<DistributionProvider, string> = {
  apple: 'Submit to the App Store',
  google: 'Submit to Google Play',
}

type CreateSubmissionProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  appId: string
  releaseId: string
  target: DistributionTarget | null
  initialArtifactId?: string
}

export function CreateSubmission({
  open,
  onOpenChange,
  projectId,
  appId,
  releaseId,
  target,
  initialArtifactId,
}: CreateSubmissionProps) {
  return (
    <Dialog
      open={open && target !== null}
      onOpenChange={(next) => {
        if (!next) onOpenChange(false)
      }}
    >
      {open && target ? (
        <CreateSubmissionContent
          key={`${target.$id}-${initialArtifactId ?? ''}`}
          projectId={projectId}
          appId={appId}
          releaseId={releaseId}
          target={target}
          initialArtifactId={initialArtifactId}
          onClose={() => onOpenChange(false)}
        />
      ) : null}
    </Dialog>
  )
}

function CreateSubmissionContent({
  projectId,
  appId,
  releaseId,
  target,
  initialArtifactId,
  onClose,
}: {
  projectId: string
  appId: string
  releaseId: string
  target: DistributionTarget
  initialArtifactId?: string
  onClose: () => void
}) {
  const t = useT()
  const provider = PLATFORM_PROVIDERS[target.platform]
  const tracks = TRACKS[provider]
  const createSubmission = useCreateDistributionSubmission(projectId)
  const { data: artifacts = [], isFetchedAfterMount } = useQuery({
    ...readyDistributionArtifactsQueryOptions(projectId, appId, target.$id),
    refetchOnMount: 'always',
  })
  const [selectedArtifactId, setSelectedArtifactId] = useState(
    initialArtifactId ?? '',
  )
  const [track, setTrack] = useState(
    target.track && tracks.includes(target.track)
      ? target.track
      : defaultTrack(target.platform),
  )
  const [automatic, setAutomatic] = useState(true)
  const [rollout, setRollout] = useState(String(MAX_ROLLOUT_PERCENT))

  const candidateId = selectedArtifactId || (artifacts[0]?.$id ?? '')
  const artifactId = artifacts.some((artifact) => artifact.$id === candidateId)
    ? candidateId
    : ''
  const selectionMissing = selectedArtifactId !== '' && artifactId === ''
  const artifactsLoading =
    !isFetchedAfterMount &&
    (artifacts.length === 0 || selectedArtifactId !== '')
  const rolloutValid = provider !== 'google' || isRolloutPercent(rollout)
  const valid = isFetchedAfterMount && artifactId !== '' && rolloutValid
  const prefix = 'create-submission'

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!valid) return
    const params: CreateDistributionSubmissionParams = {
      appId,
      releaseId,
      targetId: target.$id,
      artifactId,
      track,
      ...(provider === 'apple'
        ? { automatic }
        : { rollout: Number(rollout) / 100 }),
    }
    createSubmission.mutate(params, {
      onSuccess: (submission) => {
        toast.success(t('Submission created'))
        const mismatch =
          params.rollout === undefined
            ? null
            : rolloutMismatch(params.rollout, submission.rollout)
        if (mismatch) {
          toast.warning(
            rolloutMismatchMessage(t(ROLLOUT_MISMATCH_MESSAGE), mismatch),
          )
        }
        onClose()
      },
      onError: (error) =>
        toast.error(getErrorMessage(error, t('Failed to create submission'))),
    })
  }

  return (
    <DialogContent
      className="sm:max-w-lg p-0"
      onInteractOutside={(event) => {
        if (createSubmission.isPending) event.preventDefault()
      }}
      onEscapeKeyDown={(event) => {
        if (createSubmission.isPending) event.preventDefault()
      }}
    >
      <DialogHeader className="px-6 pt-6 pb-4 text-start">
        <DialogTitle>{t(SUBMIT_TITLES[provider])}</DialogTitle>
        <DialogDescription className="text-[13px] mt-2">
          {t(
            'Appwrite uploads the artifact to the store, then follows processing and review. Track the progress on this page.',
          )}
        </DialogDescription>
      </DialogHeader>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="space-y-4 px-6 py-4">
          <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-[13px]">
            <PlatformIcon
              platform={target.platform}
              className="text-muted-foreground"
            />
            <span className="truncate font-mono" dir="ltr">
              {target.identifier}
            </span>
          </div>
          <div className="space-y-2">
            <Label htmlFor={`${prefix}-artifact`} className="text-[13px]">
              {t('Artifact')}
            </Label>
            {artifactsLoading ? (
              <div className="flex h-9 items-center gap-1.5 text-[13px] text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('Loading artifacts...')}
              </div>
            ) : artifacts.length === 0 ? (
              <p
                className="text-[13px] text-muted-foreground"
                data-testid={`${prefix}-no-artifacts`}
              >
                {t(
                  'This target has no ready artifact. Build the release or upload a binary first.',
                )}{' '}
                <Link
                  to="/projects/$projectId/distribution/$appId/artifacts"
                  params={{ projectId, appId }}
                  className="text-foreground underline underline-offset-2"
                >
                  {t('View artifacts')}
                </Link>
              </p>
            ) : (
              <Select
                value={artifactId}
                onValueChange={setSelectedArtifactId}
                disabled={createSubmission.isPending}
              >
                <SelectTrigger
                  id={`${prefix}-artifact`}
                  className="h-9 w-full text-[13px]"
                >
                  <SelectValue placeholder={t('Select an artifact')} />
                </SelectTrigger>
                <SelectContent>
                  {artifacts.map((artifact) => (
                    <SelectItem
                      key={artifact.$id}
                      value={artifact.$id}
                      className="text-[13px]"
                    >
                      <span className="truncate">{artifact.name}</span>
                      <span
                        className="font-mono text-[12px] text-muted-foreground"
                        dir="ltr"
                      >
                        {artifactVersion(artifact)}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {selectionMissing && !artifactsLoading && artifacts.length > 0 ? (
              <p
                className="text-[12px] text-destructive"
                data-testid={`${prefix}-artifact-unavailable`}
              >
                {t(
                  'The selected artifact is not ready for this target. Choose another artifact or wait for it to finish processing.',
                )}
              </p>
            ) : null}
          </div>
          <div className="space-y-2">
            <Label htmlFor={`${prefix}-track`} className="text-[13px]">
              {t('Track')}
            </Label>
            <Select
              value={track}
              onValueChange={setTrack}
              disabled={createSubmission.isPending}
            >
              <SelectTrigger
                id={`${prefix}-track`}
                className="h-9 w-full text-[13px]"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {tracks.map((option) => (
                  <SelectItem
                    key={option}
                    value={option}
                    className="text-[13px]"
                  >
                    {t(trackLabel(option))}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {provider === 'apple' ? (
            <div className="flex items-start justify-between gap-4">
              <div className="space-y-1">
                <Label htmlFor={`${prefix}-automatic`} className="text-[13px]">
                  {t('Release automatically')}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Release the version as soon as Apple approves it. Turn this off to release it yourself from this page.',
                  )}
                </p>
              </div>
              <Switch
                id={`${prefix}-automatic`}
                checked={automatic}
                onCheckedChange={setAutomatic}
                disabled={createSubmission.isPending}
              />
            </div>
          ) : (
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-rollout`} className="text-[13px]">
                {t('Rollout percentage')}
              </Label>
              <div className="relative w-32">
                <Input
                  id={`${prefix}-rollout`}
                  type="number"
                  inputMode="numeric"
                  min={MIN_ROLLOUT_PERCENT}
                  max={MAX_ROLLOUT_PERCENT}
                  step={1}
                  value={rollout}
                  onChange={(event) => setRollout(event.target.value)}
                  disabled={createSubmission.isPending}
                  aria-invalid={!rolloutValid}
                  className={cn(
                    'h-9 pe-8 text-[13px]',
                    !rolloutValid && 'border-destructive',
                  )}
                />
                <span className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                  %
                </span>
              </div>
              <p
                className={cn(
                  'text-[12px]',
                  rolloutValid ? 'text-muted-foreground' : 'text-destructive',
                )}
              >
                {t(
                  'Below 100% requests a staged rollout that you can raise, pause and resume. If the store applies a different percentage, you are warned after submitting.',
                )}
              </p>
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={onClose}
            disabled={createSubmission.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="submit"
            className="h-9 text-[13px]"
            disabled={!valid || createSubmission.isPending}
          >
            {t('Submit')}
          </Button>
        </div>
      </form>
    </DialogContent>
  )
}
