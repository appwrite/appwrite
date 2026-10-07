import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ID, VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import type { StorageFileSelection } from '@/components/global/shared/StorageFileExplorer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useVideoCreateSourcePref } from '@/hooks/use-video-create-source-pref'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { canCreateBucket } from '@/lib/console-access-checks'
import {
  Dependencies,
  isVideoRenditionActive,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
  useProjectBuckets,
  useVideoProfiles,
  videoRenditionsQueryOptions,
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
  snapshotFile,
  type CreateVideoResult,
  type CreateVideoSource,
  type CreateVideoStepId,
  type CreateVideoStepState,
} from '@/lib/videos/create-video-pipeline'
import { useT } from '@/lib/i18n/translate'
import {
  bucketMaxFileSize,
  DemoPanel,
  getBucketFileIssue,
  SourceTabs,
  StoragePanel,
  UploadPanel,
  type CreateVideoSourceTab,
} from './CreateVideoSourceStep'
import { CreateVideoProgress } from './CreateVideoProgress'
import {
  CreateVideoSummary,
  type CreateVideoSourceMeta,
  type CreateVideoSourceSummary,
} from './CreateVideoSummary'
import {
  getDefaultProfileSelection,
  OutputFormatField,
  ProfilePicker,
  sortProfilesByPixels,
} from './RenditionOptions'

interface CreateVideoProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Preselects a Storage file as the source. */
  initialSource?: StorageFileSelection
}

const SOURCE_KIND_LABEL: Record<CreateVideoSourceTab, string> = {
  upload: 'Uploaded from your computer',
  storage: 'File in Storage',
  demo: 'Demo video by Blender Studio',
}

/** Storage bucket `maximumFileSize` API cap (5 GiB). */
const STORAGE_BUCKET_MAXIMUM_FILE_SIZE_BYTES = 5_368_709_120

function createBucketMaximumFileSizeFromPlan(
  plan: Models.BillingPlan | null | undefined,
): number | undefined {
  const fileSize = plan?.fileSize
  const fileSizeNumber =
    fileSize == null
      ? null
      : typeof fileSize === 'bigint'
        ? Number(fileSize)
        : Number(fileSize)

  if (
    fileSizeNumber == null ||
    !Number.isFinite(fileSizeNumber) ||
    fileSizeNumber <= 0 ||
    fileSizeNumber === -1
  ) {
    return undefined
  }

  const planBytes = fileSizeNumber * 1000 * 1000
  return Math.min(planBytes, STORAGE_BUCKET_MAXIMUM_FILE_SIZE_BYTES)
}

type RunState = {
  steps: CreateVideoStepId[]
  states: Partial<Record<CreateVideoStepId, CreateVideoStepState>>
  video: Models.Video | null
  phase: 'running' | 'encoding' | 'done' | 'failed'
  failedSteps: CreateVideoStepId[]
  profiles: { id: string; name: string }[]
  /** Renditions the server accepted. Encoding finishes when this many are terminal. */
  expectedRenditions: number
}

function stripExtension(fileName: string): string {
  const dot = fileName.lastIndexOf('.')
  return dot > 0 ? fileName.slice(0, dot) : fileName
}

/** Fullscreen wizard: pick a source, configure encoding, then watch progress. */
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
  const { source: savedSource, setSource: persistSource } =
    useVideoCreateSourcePref()
  const tabTouched = useRef(!!initialSource)

  const [tab, setTab] = useState<CreateVideoSourceTab>(
    initialSource ? 'storage' : savedSource,
  )
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [storageSelection, setStorageSelection] =
    useState<StorageFileSelection | null>(initialSource ?? null)
  const [demoId, setDemoId] = useState<string | null>(null)
  const [bucketId, setBucketId] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [output, setOutput] = useState<VideoOutput>(VideoOutput.Cmaf)
  const [selectedProfiles, setSelectedProfiles] = useState<Set<string>>(
    new Set(),
  )
  const [profilesTouched, setProfilesTouched] = useState(false)
  const [meta, setMeta] = useState<CreateVideoSourceMeta | null>(null)
  const [run, setRun] = useState<RunState | null>(null)
  const [preparingFile, setPreparingFile] = useState(false)
  const detachedRef = useRef(false)
  const finishOnceRef = useRef(false)

  useEffect(() => {
    if (tabTouched.current || initialSource) return
    setTab(savedSource)
  }, [savedSource, initialSource])

  const selectTab = (next: CreateVideoSourceTab) => {
    tabTouched.current = true
    setTab(next)
    persistSource(next)
  }

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

  const createBucketMaximumFileSize = useMemo(
    () => createBucketMaximumFileSizeFromPlan(plan),
    [plan],
  )
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
        ...(createBucketMaximumFileSize != null
          ? { maximumFileSize: createBucketMaximumFileSize }
          : {}),
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
          previewUrl: null,
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
  }

  const handleCreate = async () => {
    const src = pipelineSource()
    if (!src || preparingFile) return
    const options = {
      output,
      profileIds: selectedProfileList.map((p) => p.$id),
    }
    let source = src
    if (src.kind === 'upload') {
      setPreparingFile(true)
      try {
        source = { ...src, file: await snapshotFile(src.file) }
      } catch (error) {
        toast.error(getErrorMessage(error))
        return
      } finally {
        setPreparingFile(false)
      }
    }
    detachedRef.current = false
    finishOnceRef.current = false
    setRun({
      steps: getCreateVideoSteps(src, options),
      states: {},
      video: null,
      phase: 'running',
      failedSteps: [],
      profiles: selectedProfileList.map((profile) => ({
        id: profile.$id,
        name: profile.name,
      })),
      expectedRenditions: selectedProfileList.length,
    })
    const update = (patch: (prev: RunState) => RunState) =>
      setRun((prev) => (prev ? patch(prev) : prev))

    runCreateVideoPipeline({
      projectId,
      queryClient,
      source,
      name: name.trim() || defaultName,
      options,
      onStep: (id, state) =>
        update((prev) => ({ ...prev, states: { ...prev.states, [id]: state } })),
      onVideo: (video) => update((prev) => ({ ...prev, video })),
    })
      .then((result) => {
        if (result.renditionsQueued > 0 && !detachedRef.current) {
          update((prev) => ({
            ...prev,
            phase: 'encoding',
            video: result.video,
            failedSteps: result.failedSteps,
            expectedRenditions: result.renditionsQueued,
          }))
          return
        }
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

  const watchRenditions =
    !!run?.video &&
    run.profiles.length > 0 &&
    (run.phase === 'running' || run.phase === 'encoding')
  const { data: renditionsData } = useQuery({
    ...videoRenditionsQueryOptions(projectId, run?.video?.$id),
    enabled: watchRenditions,
    refetchInterval: watchRenditions ? 2000 : false,
  })
  const liveRenditions = renditionsData?.renditions ?? []

  useEffect(() => {
    if (run?.phase !== 'encoding' || !run.video || finishOnceRef.current) return
    if (liveRenditions.length < run.expectedRenditions) return
    if (liveRenditions.some((rendition) => isVideoRenditionActive(rendition.status))) {
      return
    }
    finishOnceRef.current = true
    const failed = liveRenditions.some(
      (rendition) =>
        rendition.status === 'error' || rendition.status === 'aborted',
    )
    const result: CreateVideoResult = {
      video: run.video,
      failedSteps: failed ? ['renditions'] : [],
      renditionsQueued: liveRenditions.length,
    }
    setRun((prev) =>
      prev
        ? {
            ...prev,
            phase: 'done',
            failedSteps: result.failedSteps,
          }
        : prev,
    )
    handleFinished(result)
  }, [liveRenditions, run?.phase, run?.expectedRenditions, run?.video])

  const handleSkip = () => {
    detachedRef.current = true
    toast.info(t('Video creation continues in the background.'))
    onClose()
  }

  const handleClose = () => {
    if (run?.phase === 'running' || run?.phase === 'encoding') {
      detachedRef.current = true
      toast.info(t('Video creation continues in the background.'))
    }
    onClose()
  }

  const handleOpenRunVideo = () => {
    if (!run?.video) return
    if (run.phase === 'running' || run.phase === 'encoding') {
      detachedRef.current = true
    }
    onClose()
    openVideo(run.video.$id)
  }

  const canSkip =
    !!run?.video &&
    run.profiles.length > 0 &&
    (run.phase === 'running' || run.phase === 'encoding')

  const footer = run ? (
    run.phase === 'failed' ? (
      <>
        <Button variant="outline" onClick={() => setRun(null)}>
          {t('Back')}
        </Button>
        <Button onClick={handleCreate}>{t('Try again')}</Button>
      </>
    ) : (
      <>
        {run.profiles.length > 0 ? (
          <Button
            variant="outline"
            className="min-w-28"
            onClick={run.phase === 'done' ? handleClose : handleSkip}
            disabled={run.phase !== 'done' && !canSkip}
          >
            {run.phase === 'done' ? t('Close') : t('Skip')}
          </Button>
        ) : null}
        <Button onClick={handleOpenRunVideo} disabled={!run.video}>
          {t('Open video')}
        </Button>
      </>
    )
  ) : (
    <>
      <Button variant="outline" onClick={handleClose}>
        {t('Cancel')}
      </Button>
      <Button onClick={handleCreate} disabled={!sourceReady || preparingFile}>
        {t('Create video')}
      </Button>
    </>
  )

  const destination = {
    buckets,
    bucketsLoading,
    value: bucketId,
    onValueChange: setBucketId,
    onCreateBucket: () => createBucketMutation.mutate(),
    createBucketDisabledReason,
    creatingBucket: createBucketMutation.isPending,
  }

  const form = (
    <div className="min-w-0 space-y-5">
      <SourceTabs value={tab} onValueChange={selectTab} />
      {tab === 'upload' ? (
        <UploadPanel
          file={uploadFile}
          onFileChange={setUploadFile}
          bucket={bucket}
          destination={destination}
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
          destination={destination}
        />
      )}
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
      <OutputFormatField value={output} onValueChange={setOutput} />
    </div>
  )

  return (
    <WizardLayout
      title={t('Create video')}
      fullscreen
      onClose={handleClose}
      footerAlign="right"
      maxWidth="max-w-6xl"
      useSidebar={!run}
      skipInitialFieldFocus
      contentClassName={
        run ? 'mx-auto w-full min-w-0 max-w-xl' : 'min-w-0'
      }
      sidebar={
        <CreateVideoSummary
          source={source}
          meta={effectiveMeta}
          onMeta={setMeta}
          bucketName={tab !== 'storage' ? (bucket?.name ?? null) : null}
          showEncoding
          output={output}
          profileNames={selectedProfileList.map((p) => p.name)}
        />
      }
      footer={footer}
    >
      {run ? (
        <CreateVideoProgress
          steps={run.steps}
          states={run.states}
          phase={run.phase}
          hasFailedSteps={run.failedSteps.length > 0}
          profiles={run.profiles}
          renditions={liveRenditions}
        />
      ) : (
        form
      )}
    </WizardLayout>
  )
}
