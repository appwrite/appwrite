import { useMemo, useRef, useState } from 'react'
import { ID } from '@appwrite.io/console'
import { useQueries, useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Check, ChevronRight } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type {
  DistributionApp,
  DistributionArtifact,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  PUBLISH_STEPS,
  PUBLISH_STEP_LABELS,
  checkKey,
  isPublishComplete,
  isReleaseValid,
  isSourceValid,
  publishOrder,
  publishTrack,
  releaseNotes,
  targetTrack,
  targetsState,
  type PublishStep,
  type ReleaseDraft,
  type TargetCheck,
  type TargetProgress,
  type TargetSelection,
  type TargetSource,
} from '@/lib/distribution/publish'
import { hasRepository, parseVersionCode } from '@/lib/distribution/builds'
import { useT } from '@/lib/i18n/translate'
import {
  openDistributionReleasesQueryOptions,
  readyDistributionArtifactsQueryOptions,
  useCreateDistributionBuild,
  useCreateDistributionCheck,
  useCreateDistributionRelease,
  useCreateDistributionSubmission,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PLATFORM_PROVIDERS } from '../../_components/platform'
import { TRACKS } from '../../_components/target-options'
import { PublishRelease } from './PublishRelease'
import { PublishReview, type ReviewRelease } from './PublishReview'
import { PublishSources, type TargetArtifacts } from './PublishSources'
import { PublishTargets } from './PublishTargets'

const STEP_DESCRIPTIONS: Record<PublishStep, string> = {
  targets:
    'Choose the targets to publish and check that each one is ready for its store.',
  release: 'Choose the release that the builds and submissions belong to.',
  sources: 'Choose what to submit for each target.',
  review: 'Review what happens for each target, then publish.',
}

type PublishProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  app: DistributionApp
  targets: DistributionTarget[]
}

function defaultTrack(target: DistributionTarget): string {
  return targetTrack(target.track, TRACKS[PLATFORM_PROVIDERS[target.platform]])
}

function buildUnavailableReason(app: DistributionApp): string | null {
  if (!hasRepository(app)) {
    return 'Connect a repository in the app settings to build from Git.'
  }
  if (app.providerBranch.trim() === '') {
    return 'Set a production branch in the app settings to build from Git.'
  }
  return null
}

function initialSelections(
  targets: DistributionTarget[],
): Record<string, TargetSelection> {
  return Object.fromEntries(
    targets.map((target) => [
      target.$id,
      { selected: target.connectionId !== '' },
    ]),
  )
}

function initialSources(
  targets: DistributionTarget[],
  buildable: boolean,
): Record<string, TargetSource> {
  return Object.fromEntries(
    targets.map((target) => [
      target.$id,
      {
        kind: buildable ? 'build' : 'artifact',
        artifactId: '',
        versionCode: '',
        track: defaultTrack(target),
        automatic: true,
      },
    ]),
  )
}

export function Publish({
  open,
  onOpenChange,
  projectId,
  app,
  targets: initialTargets,
}: PublishProps) {
  const t = useT()
  const [targets] = useState(initialTargets)
  const navigate = useNavigate()
  const appId = app.$id
  const buildReason = buildUnavailableReason(app)

  const [step, setStep] = useState<PublishStep>('targets')
  const [selections, setSelections] = useState(() => initialSelections(targets))
  const [checks, setChecks] = useState<Record<string, TargetCheck>>({})
  const [release, setRelease] = useState<ReleaseDraft>({
    mode: 'new',
    releaseId: '',
    version: '',
    notes: '',
  })
  const [sources, setSources] = useState(() =>
    initialSources(targets, buildReason === null),
  )
  const [newReleaseId] = useState(() => ID.unique())
  const [progress, setProgress] = useState<Record<string, TargetProgress>>({})
  const [releaseError, setReleaseError] = useState<string | null>(null)
  const [running, setRunning] = useState(false)
  const [started, setStarted] = useState(false)
  const [createdRelease, setCreatedRelease] = useState<{
    $id: string
    version: string
  } | null>(null)
  const completed = useRef(new Set<string>())

  const createCheck = useCreateDistributionCheck(projectId)
  const createRelease = useCreateDistributionRelease(projectId)
  const createSubmission = useCreateDistributionSubmission(projectId)
  const createBuild = useCreateDistributionBuild(projectId)

  const selected = useMemo(
    () => targets.filter((target) => selections[target.$id]?.selected),
    [targets, selections],
  )
  const defaultTracks = useMemo(
    () =>
      Object.fromEntries(
        targets.map((target) => [target.$id, defaultTrack(target)]),
      ),
    [targets],
  )
  const trackOf = (target: DistributionTarget) =>
    publishTrack(sources[target.$id], defaultTracks[target.$id])
  const tracks = Object.fromEntries(
    targets.map((target) => [target.$id, trackOf(target)]),
  )
  const checkEntries = (queue: DistributionTarget[]) =>
    queue.map((target) => ({ targetId: target.$id, track: trackOf(target) }))
  const state = targetsState(checkEntries(selected), checks)

  const { data: openReleases = [] } = useQuery({
    ...openDistributionReleasesQueryOptions(projectId, appId),
    enabled: open && !!projectId,
  })

  const artifactQueries = useQueries({
    queries: selected.map((target) => ({
      ...readyDistributionArtifactsQueryOptions(projectId, appId, target.$id),
      enabled: open && step !== 'targets' && !!projectId,
    })),
  })
  const artifacts = useMemo(() => {
    const byTarget: Record<string, TargetArtifacts> = {}
    const byId: Record<string, DistributionArtifact> = {}
    selected.forEach((target, index) => {
      const query = artifactQueries[index]
      const ready = query?.data ?? []
      byTarget[target.$id] = { artifacts: ready, isLoading: !!query?.isLoading }
      ready.forEach((artifact) => {
        byId[artifact.$id] = artifact
      })
    })
    return { byTarget, byId }
  }, [selected, artifactQueries])

  const effectiveSources = useMemo(() => {
    const result: Record<string, TargetSource> = {}
    for (const target of selected) {
      const source = sources[target.$id]
      result[target.$id] = {
        ...source,
        artifactId:
          source.artifactId ||
          artifacts.byTarget[target.$id]?.artifacts[0]?.$id ||
          '',
      }
    }
    return result
  }, [selected, sources, artifacts])

  const ordered = useMemo(
    () => publishOrder(selected, effectiveSources),
    [selected, effectiveSources],
  )

  const sourcesValid = selected.every((target) =>
    isSourceValid(
      effectiveSources[target.$id],
      target.versionStrategy === 'manual',
      app.providerBranch,
    ),
  )

  const reviewRelease: ReviewRelease = useMemo(() => {
    if (createdRelease) {
      return { version: createdRelease.version, status: null }
    }
    if (release.mode === 'existing') {
      const existing = openReleases.find(
        (item) => item.$id === release.releaseId,
      )
      return {
        version: existing?.version ?? release.releaseId,
        status: existing?.status ?? null,
      }
    }
    return { version: release.version.trim(), status: null }
  }, [createdRelease, release, openReleases])

  const stepIndex = PUBLISH_STEPS.indexOf(step)
  const canContinue =
    (step === 'targets' && state === 'ready') ||
    (step === 'release' && isReleaseValid(release)) ||
    (step === 'sources' && sourcesValid)

  const handleOpenChange = (next: boolean) => {
    if (running) return
    onOpenChange(next)
  }

  const updateSelection = (
    targetId: string,
    changes: Partial<TargetSelection>,
  ) =>
    setSelections((current) => ({
      ...current,
      [targetId]: { ...current[targetId], ...changes },
    }))

  const updateSource = (targetId: string, changes: Partial<TargetSource>) =>
    setSources((current) => ({
      ...current,
      [targetId]: { ...current[targetId], ...changes },
    }))

  const updateProgress = (targetId: string, next: TargetProgress) =>
    setProgress((current) => ({ ...current, [targetId]: next }))

  const runChecks = async (
    queue: DistributionTarget[],
  ): Promise<Record<string, TargetCheck>> => {
    const pending = checkEntries(queue)
    setChecks((current) => ({
      ...current,
      ...Object.fromEntries(
        pending.map(({ targetId, track }) => [
          checkKey(targetId, track),
          { status: 'running' } satisfies TargetCheck,
        ]),
      ),
    }))
    const results = await Promise.all(
      pending.map(async ({ targetId, track }) => {
        let result: TargetCheck
        try {
          const check = await createCheck.mutateAsync({
            appId,
            targetId,
            track,
          })
          result = { status: 'done', check }
        } catch (error) {
          result = {
            status: 'failed',
            error: getErrorMessage(error, t('Failed to run the checks')),
          }
        }
        setChecks((current) => ({
          ...current,
          [checkKey(targetId, track)]: result,
        }))
        return [checkKey(targetId, track), result] as const
      }),
    )
    return Object.fromEntries(results)
  }

  const resolveRelease = async (): Promise<string | null> => {
    if (createdRelease) return createdRelease.$id
    if (release.mode === 'existing') return release.releaseId
    try {
      const notes = releaseNotes(release)
      const created = await createRelease.mutateAsync({
        appId,
        releaseId: newReleaseId,
        version: release.version.trim(),
        ...(notes ? { notes } : {}),
      })
      setCreatedRelease({ $id: created.$id, version: created.version })
      setReleaseError(null)
      return created.$id
    } catch (error) {
      setReleaseError(getErrorMessage(error, t('Failed to create the release')))
      return null
    }
  }

  const publishTarget = async (
    target: DistributionTarget,
    releaseId: string,
  ) => {
    const source = effectiveSources[target.$id]
    updateProgress(target.$id, { status: 'running' })
    try {
      if (source.kind === 'artifact') {
        await createSubmission.mutateAsync({
          appId,
          releaseId,
          targetId: target.$id,
          artifactId: source.artifactId,
          track: trackOf(target),
          ...(PLATFORM_PROVIDERS[target.platform] === 'apple'
            ? { automatic: source.automatic }
            : {}),
        })
      } else {
        const versionCode =
          target.versionStrategy === 'manual'
            ? parseVersionCode(source.versionCode)
            : null
        await createBuild.mutateAsync({
          appId,
          targetId: target.$id,
          releaseId,
          type: 'branch',
          reference: app.providerBranch,
          ...(versionCode === null ? {} : { versionCode }),
        })
      }
      completed.current.add(target.$id)
      updateProgress(target.$id, { status: 'done' })
    } catch (error) {
      updateProgress(target.$id, {
        status: 'failed',
        error: getErrorMessage(error, t('Failed to publish this target')),
      })
    }
  }

  const execute = async (queue: DistributionTarget[]) => {
    setRunning(true)
    const results = await runChecks(queue)
    if (targetsState(checkEntries(queue), results) !== 'ready') {
      setRunning(false)
      setStep('targets')
      toast.error(
        t(
          'The checks changed since you ran them. Review the targets to publish.',
        ),
      )
      return
    }
    setStarted(true)
    const releaseId = await resolveRelease()
    if (releaseId) {
      for (const target of queue) {
        await publishTarget(target, releaseId)
      }
    }
    setRunning(false)
    const finished = Object.fromEntries(
      [...completed.current].map((targetId) => [
        targetId,
        { status: 'done' } satisfies TargetProgress,
      ]),
    )
    if (
      releaseId &&
      isPublishComplete(
        selected.map((target) => target.$id),
        finished,
      )
    ) {
      toast.success(t('Publishing started'))
      onOpenChange(false)
      navigate({
        to: '/projects/$projectId/distribution/$appId/releases/$releaseId',
        params: { projectId, appId, releaseId },
      })
    }
  }

  const handlePublish = () =>
    execute(ordered.filter((target) => !completed.current.has(target.$id)))

  const handleRetry = (targetId: string) => {
    const target = ordered.find((item) => item.$id === targetId)
    if (target) execute([target])
  }

  const handleBack = () => {
    if (stepIndex === 0) {
      handleOpenChange(false)
      return
    }
    setStep(PUBLISH_STEPS[stepIndex - 1])
  }

  const handleContinue = () => {
    if (!canContinue) return
    setStep(PUBLISH_STEPS[stepIndex + 1])
  }

  const handleReleaseChange = (changes: Partial<ReleaseDraft>) =>
    setRelease((current) => {
      const next = { ...current, ...changes }
      if (next.mode === 'existing' && !next.releaseId) {
        next.releaseId = openReleases[0]?.$id ?? ''
      }
      return next
    })

  const publishable = !running && (!started || releaseError !== null)

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Publish app')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(STEP_DESCRIPTIONS[step])}
          </DialogDescription>
          <ol
            aria-label={t('Steps')}
            className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1"
          >
            {PUBLISH_STEPS.map((item, index) => (
              <li key={item} className="flex items-center gap-2">
                {index > 0 ? (
                  <ChevronRight className="h-3.5 w-3.5 text-muted-foreground rtl:rotate-180" />
                ) : null}
                <span
                  aria-current={item === step ? 'step' : undefined}
                  className={cn(
                    'flex items-center gap-1.5 text-[12px]',
                    item === step
                      ? 'font-medium text-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  <span
                    className={cn(
                      'flex h-5 w-5 items-center justify-center rounded-full border text-[11px]',
                      item === step
                        ? 'border-foreground bg-foreground text-background'
                        : 'border-border',
                    )}
                  >
                    {index < stepIndex ? (
                      <Check className="h-3 w-3" />
                    ) : (
                      index + 1
                    )}
                  </span>
                  {t(PUBLISH_STEP_LABELS[item])}
                </span>
              </li>
            ))}
          </ol>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="max-h-[min(60dvh,560px)] overflow-y-auto px-6 pb-4 pt-0">
          {step === 'targets' ? (
            <PublishTargets
              targets={targets}
              selections={selections}
              tracks={tracks}
              checks={checks}
              state={state}
              onChange={updateSelection}
              onRunChecks={() => void runChecks(selected)}
            />
          ) : null}
          {step === 'release' ? (
            <PublishRelease
              release={release}
              releases={openReleases}
              onChange={handleReleaseChange}
            />
          ) : null}
          {step === 'sources' ? (
            <PublishSources
              branch={app.providerBranch}
              buildReason={buildReason}
              targets={selected}
              sources={effectiveSources}
              artifacts={artifacts.byTarget}
              onChange={updateSource}
            />
          ) : null}
          {step === 'review' ? (
            <PublishReview
              release={reviewRelease}
              releaseError={releaseError}
              branch={app.providerBranch}
              targets={ordered}
              tracks={tracks}
              sources={effectiveSources}
              artifacts={artifacts.byId}
              progress={progress}
              running={running}
              onRetry={handleRetry}
            />
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={handleBack}
            disabled={running || (step === 'review' && started)}
          >
            {stepIndex === 0 ? t('Cancel') : t('Back')}
          </Button>
          {step === 'review' ? (
            <Button
              type="button"
              className="h-9 text-[13px]"
              onClick={handlePublish}
              disabled={!publishable}
            >
              {t('Publish')}
            </Button>
          ) : (
            <Button
              type="button"
              className="h-9 text-[13px]"
              onClick={handleContinue}
              disabled={!canContinue}
            >
              {t('Continue')}
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
