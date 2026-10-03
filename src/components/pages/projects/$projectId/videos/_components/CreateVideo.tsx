import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ID, VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Check, GalleryHorizontal } from 'lucide-react'
import { toast } from 'sonner'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import type { StorageFileSelection } from '@/components/global/shared/StorageFileExplorer'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { canCreateBucket } from '@/lib/console-access-checks'
import {
  Dependencies,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
  useProjectBuckets,
  useVideoProfiles,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  DEMO_VIDEOS,
  demoVideoFileName,
  pickDemoVideoVariant,
} from '@/lib/videos/demo-videos'
import {
  CreateVideoError,
  getCreateVideoSteps,
  runCreateVideoPipeline,
  type CreateVideoResult,
  type CreateVideoSource,
  type CreateVideoStepId,
  type CreateVideoStepState,
} from '@/lib/videos/create-video-pipeline'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  bucketMaxFileSize,
  DemoPanel,
  DestinationBucketField,
  getBucketFileIssue,
  SourceTabs,
  StoragePanel,
  UploadPanel,
  type CreateVideoSourceTab,
} from './CreateVideoSourceStep'
import {
  CREATE_VIDEO_STEP_COPY,
  CreateVideoProgress,
} from './CreateVideoProgress'
import {
  CreateVideoSummary,
  type CreateVideoSourceMeta,
  type CreateVideoSourceSummary,
} from './CreateVideoSummary'
import {
  getDefaultProfileSelection,
  OutputPicker,
  ProfilePicker,
  sortProfilesByPixels,
} from './RenditionOptions'
import { VideoOutputBadge } from './VideoOutputBadge'
import { VideoTermHint } from './VideoTermHint'

interface CreateVideoProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Preselects a Storage file as the source. */
  initialSource?: StorageFileSelection
}

const WIZARD_STEPS = ['Source', 'Encoding settings', 'Review'] as const

const SOURCE_KIND_LABEL: Record<CreateVideoSourceTab, string> = {
  upload: 'Uploaded from your computer',
  storage: 'File in Storage',
  demo: 'Demo video by Blender Studio',
}

type RunState = {
  steps: CreateVideoStepId[]
  states: Partial<Record<CreateVideoStepId, CreateVideoStepState>>
  video: Models.Video | null
  phase: 'running' | 'done' | 'failed'
  failedSteps: CreateVideoStepId[]
}

function stripExtension(fileName: string): string {
  const dot = fileName.lastIndexOf('.')
  return dot > 0 ? fileName.slice(0, dot) : fileName
}

/** Fullscreen wizard: pick a source, configure encoding, review, then create. */
export function CreateVideo({
  open,
  onOpenChange,
  projectId,
  initialSource,
}: CreateVideoProps) {
  if (!open) return null
  return (
    <CreateVideoWizard
      projectId={projectId}
      initialSource={initialSource}
      onClose={() => onOpenChange(false)}
    />
  )
}

function StepHeading({
  title,
  description,
}: {
  title: string
  description: string
}) {
  return (
    <div>
      <h2 className="text-[18px] font-semibold tracking-tight text-foreground">
        {title}
      </h2>
      <p className="mt-1.5 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
        {description}
      </p>
    </div>
  )
}

function ReviewRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[160px_minmax(0,1fr)] sm:gap-4">
      <dt className="text-[12px] text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-[13px] text-foreground">{children}</dd>
    </div>
  )
}

function CreateVideoWizard({
  projectId,
  initialSource,
  onClose,
}: {
  projectId: string
  initialSource?: StorageFileSelection
  onClose: () => void
}) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [step, setStep] = useState(0)
  const [tab, setTab] = useState<CreateVideoSourceTab>(
    initialSource ? 'storage' : 'upload',
  )
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [storageSelection, setStorageSelection] =
    useState<StorageFileSelection | null>(initialSource ?? null)
  const [demoId, setDemoId] = useState<string | null>(null)
  const [bucketId, setBucketId] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [timeline, setTimeline] = useState(true)
  const [output, setOutput] = useState<VideoOutput>(VideoOutput.Hls)
  const [selectedProfiles, setSelectedProfiles] = useState<Set<string>>(
    new Set(),
  )
  const [profilesTouched, setProfilesTouched] = useState(false)
  const [meta, setMeta] = useState<CreateVideoSourceMeta | null>(null)
  const [run, setRun] = useState<RunState | null>(null)
  const detachedRef = useRef(false)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const {
    buckets,
    total: bucketsTotal,
    isLoading: bucketsLoading,
  } = useProjectBuckets(projectId, 0, 100)
  const { data: profilesData, isLoading: profilesLoading } =
    useVideoProfiles(projectId)
  const profiles = useMemo(
    () => sortProfilesByPixels(profilesData?.profiles ?? []),
    [profilesData?.profiles],
  )

  const { data: storageFile } = useQuery({
    queryKey: [
      'video-source-file',
      projectId,
      storageSelection?.bucketId,
      storageSelection?.fileId,
    ],
    queryFn: () =>
      sdk.forProject(projectId).storage.getFile({
        bucketId: storageSelection!.bucketId,
        fileId: storageSelection!.fileId,
      }),
    enabled: !!storageSelection,
    staleTime: 60 * 1000,
  })

  useEffect(() => {
    if (bucketId && buckets.some((bucket) => bucket.$id === bucketId)) return
    if (buckets.length === 0) return
    const preferred =
      buckets.find((bucket) => /video/i.test(`${bucket.name} ${bucket.$id}`)) ??
      buckets[0]
    setBucketId(preferred.$id)
  }, [buckets, bucketId])
  const bucket = buckets.find((b) => b.$id === bucketId) ?? null

  const planMaxFileSize =
    plan?.fileSize && Number(plan.fileSize) > 0
      ? Number(plan.fileSize) * 1000 * 1000
      : undefined
  const bucketLimitReached =
    (plan?.buckets ?? 0) > 0 && bucketsTotal >= (plan?.buckets ?? 0)
  const createBucketDisabledReason = !canCreateBucket(access, features)
    ? t("You don't have permission to create buckets.")
    : bucketLimitReached
      ? t('Bucket limit reached for your plan.')
      : null

  const createBucketMutation = useMutation({
    mutationFn: () =>
      sdk.forProject(projectId).storage.createBucket({
        bucketId: ID.unique(),
        name: 'Videos',
        maximumFileSize: planMaxFileSize,
      }),
    onSuccess: async (created) => {
      await queryClient.refetchQueries({ queryKey: Dependencies.BUCKETS })
      setBucketId(created.$id)
      toast.success(t('Bucket created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const demo = DEMO_VIDEOS.find((d) => d.id === demoId) ?? null
  const demoVariant = demo
    ? pickDemoVideoVariant(demo, bucketMaxFileSize(bucket))
    : null

  const [objectUrl, setObjectUrl] = useState<string | null>(null)
  useEffect(() => {
    if (!uploadFile) {
      setObjectUrl(null)
      return
    }
    const url = URL.createObjectURL(uploadFile)
    setObjectUrl(url)
    return () => URL.revokeObjectURL(url)
  }, [uploadFile])

  const storageViewUrl = useMemo(
    () =>
      storageSelection
        ? withAdminMode(
            sdk.forProject(projectId).storage.getFileView(storageSelection),
          )
        : null,
    [projectId, storageSelection],
  )

  const source: CreateVideoSourceSummary | null = useMemo(() => {
    const kindLabel = SOURCE_KIND_LABEL[tab]
    if (tab === 'upload') {
      return uploadFile
        ? {
            kindLabel,
            name: uploadFile.name,
            size: uploadFile.size,
            mimeType: uploadFile.type || undefined,
            previewUrl: objectUrl,
          }
        : null
    }
    if (tab === 'storage') {
      return storageSelection
        ? {
            kindLabel,
            name: storageFile?.name ?? storageSelection.fileId,
            size: storageFile?.sizeOriginal,
            mimeType: storageFile?.mimeType,
            previewUrl: storageViewUrl,
          }
        : null
    }
    return demo
      ? {
          kindLabel,
          name: demo.title,
          size: demoVariant?.size,
          mimeType: 'video/webm',
          previewUrl: (demoVariant ?? demo.variants['480p']).url,
          posterUrl: demo.posterUrl,
        }
      : null
  }, [
    tab,
    uploadFile,
    objectUrl,
    storageSelection,
    storageFile,
    storageViewUrl,
    demo,
    demoVariant,
  ])

  const previewUrl = source?.previewUrl ?? null
  useEffect(() => setMeta(null), [previewUrl])

  const effectiveMeta: CreateVideoSourceMeta | null =
    meta ??
    (tab === 'demo' && demo && demoVariant
      ? {
          width: demoVariant.width,
          height: demoVariant.height,
          duration: demo.duration,
          hasVideo: true,
        }
      : null)
  const audioOnly = effectiveMeta ? !effectiveMeta.hasVideo : false
  const sourceWidth = effectiveMeta?.hasVideo ? effectiveMeta.width : null
  const sourceHeight = effectiveMeta?.hasVideo ? effectiveMeta.height : null
  const sourceSize = useMemo(
    () =>
      sourceWidth && sourceHeight
        ? { width: sourceWidth, height: sourceHeight }
        : null,
    [sourceWidth, sourceHeight],
  )

  useEffect(() => {
    if (profilesTouched) return
    setSelectedProfiles(getDefaultProfileSelection(profiles, sourceSize))
  }, [profiles, sourceSize, profilesTouched])

  const selectedProfileList = profiles.filter((p) =>
    selectedProfiles.has(p.$id),
  )
  const timelineEnabled = timeline && !audioOnly

  const uploadIssue =
    tab === 'upload' && uploadFile ? getBucketFileIssue(bucket, uploadFile) : null
  const demoIssue =
    tab === 'demo' && demo && demoVariant
      ? getBucketFileIssue(bucket, {
          name: demoVideoFileName(demo, demoVariant),
          size: demoVariant.size,
        })
      : null
  const sourceReady =
    tab === 'storage'
      ? !!storageSelection
      : tab === 'upload'
        ? !!uploadFile && !!bucket && !uploadIssue
        : !!demo && !!demoVariant && !!bucket && !demoIssue

  const defaultName =
    tab === 'demo'
      ? (demo?.title ?? '')
      : tab === 'upload'
        ? stripExtension(uploadFile?.name ?? '')
        : stripExtension(storageFile?.name ?? '')

  const pipelineSource = (): CreateVideoSource | null => {
    if (tab === 'storage' && storageSelection) {
      return { kind: 'storage', ...storageSelection }
    }
    if (!bucket) return null
    if (tab === 'upload' && uploadFile) {
      return { kind: 'upload', bucketId: bucket.$id, file: uploadFile }
    }
    if (tab === 'demo' && demo && demoVariant) {
      return { kind: 'demo', bucketId: bucket.$id, demo, variant: demoVariant }
    }
    return null
  }

  const plannedSteps = getCreateVideoSteps(
    { kind: tab },
    {
      timeline: timelineEnabled,
      profileIds: selectedProfileList.map((p) => p.$id),
    },
  )

  const openVideo = (videoId: string) =>
    navigate({
      to: '/projects/$projectId/videos/$videoId',
      params: { projectId, videoId },
    })

  const handleFinished = (result: CreateVideoResult) => {
    const detached = detachedRef.current
    const action = detached
      ? { label: t('Open'), onClick: () => openVideo(result.video.$id) }
      : undefined
    if (result.failedSteps.length > 0) {
      toast.warning(t('Video created with issues'), {
        description: t(
          'Open the video to retry the steps that failed.',
        ),
        action,
      })
      return
    }
    toast.success(t('Video created'), { action })
    if (!detached) {
      onClose()
      openVideo(result.video.$id)
    }
  }

  const handleCreate = () => {
    const src = pipelineSource()
    if (!src) return
    const options = {
      timeline: timelineEnabled,
      output,
      profileIds: selectedProfileList.map((p) => p.$id),
    }
    detachedRef.current = false
    setRun({
      steps: getCreateVideoSteps(src, options),
      states: {},
      video: null,
      phase: 'running',
      failedSteps: [],
    })
    const update = (patch: (prev: RunState) => RunState) =>
      setRun((prev) => (prev ? patch(prev) : prev))

    runCreateVideoPipeline({
      projectId,
      queryClient,
      source: src,
      name: name.trim() || defaultName,
      options,
      onStep: (id, state) =>
        update((prev) => ({ ...prev, states: { ...prev.states, [id]: state } })),
      onVideo: (video) => update((prev) => ({ ...prev, video })),
    })
      .then((result) => {
        update((prev) => ({
          ...prev,
          phase: 'done',
          video: result.video,
          failedSteps: result.failedSteps,
        }))
        handleFinished(result)
      })
      .catch((error) => {
        update((prev) => ({ ...prev, phase: 'failed' }))
        if (detachedRef.current) {
          toast.error(
            error instanceof CreateVideoError
              ? error.message
              : t('Failed to create video'),
          )
        }
      })
  }

  const handleClose = () => {
    if (run?.phase === 'running') {
      detachedRef.current = true
      toast.info(t('Video creation continues in the background.'))
    }
    onClose()
  }

  const handleOpenRunVideo = () => {
    if (!run?.video) return
    if (run.phase === 'running') detachedRef.current = true
    onClose()
    openVideo(run.video.$id)
  }

  const stepper = (
    <nav aria-label={t('Steps')}>
      <ol className="mx-auto grid w-full max-w-6xl grid-cols-3 gap-2 px-6 pt-3 pb-2.5">
        {WIZARD_STEPS.map((label, index) => {
          const complete = !!run || index < step
          const current = !run && index === step
          const clickable = !run && index < step
          return (
            <li key={label} className="min-w-0">
              <button
                type="button"
                disabled={!clickable}
                aria-current={current ? 'step' : undefined}
                onClick={() => setStep(index)}
                className="group flex w-full flex-col gap-2 text-start disabled:cursor-default"
              >
                <span className="relative h-1 w-full overflow-hidden rounded-full bg-border">
                  <span
                    className={cn(
                      'absolute inset-y-0 start-0 rounded-full bg-foreground transition-[width] duration-300 ease-out',
                      complete || current ? 'w-full' : 'w-0',
                      complete && 'bg-foreground/60 group-hover:bg-foreground',
                    )}
                  />
                </span>
                <span className="flex min-w-0 items-center gap-1.5 text-[12px]">
                  {complete ? (
                    <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground group-hover:text-foreground" />
                  ) : (
                    <span
                      className={cn(
                        'shrink-0 font-mono tabular-nums',
                        current ? 'text-foreground' : 'text-muted-foreground/70',
                      )}
                    >
                      {index + 1}
                    </span>
                  )}
                  <span
                    className={cn(
                      'truncate',
                      current
                        ? 'font-medium text-foreground'
                        : complete
                          ? 'text-muted-foreground group-hover:text-foreground'
                          : 'text-muted-foreground/70',
                    )}
                  >
                    {t(label)}
                  </span>
                </span>
              </button>
            </li>
          )
        })}
      </ol>
    </nav>
  )

  const footer = run ? (
    run.phase === 'failed' ? (
      <>
        <Button variant="outline" onClick={() => setRun(null)}>
          {t('Back')}
        </Button>
        <Button onClick={handleCreate}>{t('Try again')}</Button>
      </>
    ) : (
      <Button onClick={handleOpenRunVideo} disabled={!run.video}>
        {t('Open video')}
      </Button>
    )
  ) : (
    <>
      {step === 0 ? (
        <Button variant="outline" onClick={handleClose}>
          {t('Cancel')}
        </Button>
      ) : (
        <Button variant="outline" onClick={() => setStep(step - 1)}>
          {t('Back')}
        </Button>
      )}
      {step < WIZARD_STEPS.length - 1 ? (
        <Button
          onClick={() => setStep(step + 1)}
          disabled={step === 0 && !sourceReady}
        >
          {t('Continue')}
        </Button>
      ) : (
        <Button onClick={handleCreate} disabled={!sourceReady}>
          {t('Create video')}
        </Button>
      )}
    </>
  )

  const content = run ? (
    <CreateVideoProgress
      steps={run.steps}
      states={run.states}
      phase={run.phase}
      hasFailedSteps={run.failedSteps.length > 0}
    />
  ) : step === 0 ? (
    <div className="space-y-6">
      <StepHeading
        title={t('Choose a source')}
        description={t(
          'Every video starts from a file in Storage. Upload one, pick a file you already have, or start with a demo.',
        )}
      />
      <SourceTabs value={tab} onValueChange={setTab} />
      {tab === 'upload' ? (
        <UploadPanel
          file={uploadFile}
          onFileChange={setUploadFile}
          bucket={bucket}
        />
      ) : tab === 'storage' ? (
        <StoragePanel
          projectId={projectId}
          file={storageFile}
          selection={storageSelection}
          onSelectionChange={setStorageSelection}
        />
      ) : (
        <DemoPanel
          selectedId={demoId}
          onSelect={(next) => setDemoId(next.id)}
          bucket={bucket}
        />
      )}
      {tab !== 'storage' ? (
        <DestinationBucketField
          buckets={buckets}
          bucketsLoading={bucketsLoading}
          value={bucketId}
          onValueChange={setBucketId}
          onCreateBucket={() => createBucketMutation.mutate()}
          createBucketDisabledReason={createBucketDisabledReason}
          creatingBucket={createBucketMutation.isPending}
        />
      ) : null}
    </div>
  ) : step === 1 ? (
    <div className="space-y-6">
      <StepHeading
        title={t('Configure encoding')}
        description={t(
          'Choose how the video is packaged for adaptive streaming. Encoding starts as soon as the source is ready.',
        )}
      />
      <div className="flex items-start gap-3 rounded-xl border border-border bg-card/50 px-4 py-3.5">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <GalleryHorizontal className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Label
              htmlFor="create-video-timeline"
              className="text-[13px] font-medium"
            >
              {t('Generate timeline')}
            </Label>
            {!audioOnly ? (
              <Badge variant="info" className="text-[10px] shrink-0">
                {t('Recommended')}
              </Badge>
            ) : null}
          </div>
          <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
            {audioOnly
              ? t('Audio-only sources have no frames to preview.')
              : t(
                  'Sprite sheet thumbnails and a WebVTT track for previews while scrubbing. Also sets the video poster.',
                )}
          </p>
        </div>
        <Switch
          id="create-video-timeline"
          checked={timelineEnabled}
          disabled={audioOnly}
          onCheckedChange={setTimeline}
        />
      </div>
      <div className="space-y-2.5">
        <Label className="flex items-center gap-1 text-[13px]">
          {t('Output')}
          <VideoTermHint term="output" />
        </Label>
        <OutputPicker value={output} onValueChange={setOutput} />
      </div>
      <div className="space-y-2">
        <ProfilePicker
          projectId={projectId}
          profiles={profiles}
          loading={profilesLoading}
          selected={selectedProfiles}
          onSelectedChange={(next) => {
            setProfilesTouched(true)
            setSelectedProfiles(next)
          }}
          source={sourceSize}
          openManageInNewTab
        />
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Clear every profile to skip encoding. You can create renditions later from the video page.',
          )}
        </p>
      </div>
    </div>
  ) : (
    <div className="space-y-6">
      <StepHeading
        title={t('Review and create')}
        description={t(
          'Name your video and confirm the details. You can rename it later.',
        )}
      />
      <div className="space-y-2">
        <Label htmlFor="create-video-name" className="text-[13px]">
          {t('Name')}{' '}
          <span className="text-muted-foreground">({t('optional')})</span>
        </Label>
        <Input
          id="create-video-name"
          value={name}
          maxLength={128}
          onChange={(event) => setName(event.target.value)}
          placeholder={defaultName || t('Defaults to the file name')}
          className="h-9 text-[13px]"
        />
      </div>
      <dl className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card/50">
        <ReviewRow label={t('Source')}>
          <span className="block truncate font-medium">{source?.name}</span>
          <span className="block text-[12px] text-muted-foreground">
            {t(SOURCE_KIND_LABEL[tab])}
          </span>
        </ReviewRow>
        {tab !== 'storage' && bucket ? (
          <ReviewRow label={t('Destination bucket')}>{bucket.name}</ReviewRow>
        ) : null}
        <ReviewRow label={t('Output')}>
          <VideoOutputBadge output={output} />
        </ReviewRow>
        <ReviewRow label={t('Profiles')}>
          {selectedProfileList.length > 0 ? (
            <span className="flex flex-wrap gap-1.5">
              {selectedProfileList.map((profile) => (
                <Badge
                  key={profile.$id}
                  variant="info"
                  className="text-[11px] font-mono"
                >
                  {profile.name}
                </Badge>
              ))}
            </span>
          ) : (
            <span className="text-muted-foreground">
              {t('No renditions. You can encode later.')}
            </span>
          )}
        </ReviewRow>
        <ReviewRow label={t('Timeline previews')}>
          {timelineEnabled ? t('Enabled') : t('Disabled')}
        </ReviewRow>
      </dl>
      <div className="space-y-3">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t('What happens next')}
        </h3>
        <ol className="space-y-3">
          {plannedSteps.map((id, index) => (
            <li key={id} className="flex gap-3">
              <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold tabular-nums text-muted-foreground">
                {index + 1}
              </span>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground">
                  {t(CREATE_VIDEO_STEP_COPY[id].title)}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {t(CREATE_VIDEO_STEP_COPY[id].description)}
                </p>
              </div>
            </li>
          ))}
        </ol>
      </div>
    </div>
  )

  return (
    <>
      <WizardLayout
        title={t('Create video')}
        fullscreen
        onClose={handleClose}
        footerAlign="right"
        maxWidth="max-w-6xl"
        headerBottom={stepper}
        skipInitialFieldFocus={step !== 2 || !!run}
        initialFocusKey={step}
        sidebar={
          <CreateVideoSummary
            source={source}
            meta={effectiveMeta}
            onMeta={setMeta}
            bucketName={tab !== 'storage' ? (bucket?.name ?? null) : null}
            showEncoding={step > 0 || !!run}
            output={output}
            profileNames={selectedProfileList.map((p) => p.name)}
            timeline={timelineEnabled}
          />
        }
        footer={footer}
      >
        {content}
      </WizardLayout>
    </>
  )
}
