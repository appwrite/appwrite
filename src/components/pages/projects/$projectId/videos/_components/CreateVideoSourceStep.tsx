import {
  useLayoutEffect,
  useRef,
  useState,
  type DragEvent,
  type RefObject,
} from 'react'
import type { Models } from '@appwrite.io/console'
import {
  AlertCircle,
  Check,
  Clapperboard,
  FileAudio,
  FileVideo,
  FolderOpen,
  Plus,
  Upload,
  X,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  StorageFileExplorer,
  type StorageFileSelection,
} from '@/components/global/shared/StorageFileExplorer'
import {
  STORAGE_FILE_ACCEPT_VIDEO_AUDIO,
  storageAcceptToHtmlAccept,
  storageFileMatchesAccept,
} from '@/lib/storage/file-accept'
import { toByteCount } from '@/lib/utils/byte-display-unit'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatVideoDuration } from '@/lib/utils/video-format'
import {
  DEMO_VIDEOS,
  pickDemoVideoVariant,
  type DemoVideo,
} from '@/lib/videos/demo-videos'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type CreateVideoSourceTab = 'upload' | 'storage' | 'demo'

const TOOLTIP_Z = 'z-[10060]'
const UPLOAD_ACCEPT = storageAcceptToHtmlAccept([
  ...STORAGE_FILE_ACCEPT_VIDEO_AUDIO,
])

const SOURCE_TABS: {
  id: CreateVideoSourceTab
  title: string
  description: string
  icon: typeof Upload
}[] = [
  {
    id: 'upload',
    title: 'Upload a file',
    description: 'Drop a video or audio file from your computer.',
    icon: Upload,
  },
  {
    id: 'storage',
    title: 'Choose from Storage',
    description: 'Use a file that is already in a bucket.',
    icon: FolderOpen,
  },
  {
    id: 'demo',
    title: 'Try a demo video',
    description: 'Import an open movie to explore Videos.',
    icon: Clapperboard,
  },
]

export function bucketMaxFileSize(
  bucket: Models.Bucket | null | undefined,
): number | null {
  const max = toByteCount(bucket?.maximumFileSize)
  return max > 0 ? max : null
}

/** English issue for a file that the bucket would reject, or `null`. */
export function getBucketFileIssue(
  bucket: Models.Bucket | null | undefined,
  file: { name: string; size: number },
): 'size' | 'extension' | null {
  if (!bucket) return null
  const max = bucketMaxFileSize(bucket)
  if (max && file.size > max) return 'size'
  const allowed = (bucket.allowedFileExtensions ?? []).map((ext) =>
    ext.replace(/^\./, '').toLowerCase(),
  )
  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (allowed.length > 0 && !allowed.includes(ext)) return 'extension'
  return null
}

export function SourceTabs({
  value,
  onValueChange,
}: {
  value: CreateVideoSourceTab
  onValueChange: (value: CreateVideoSourceTab) => void
}) {
  const t = useT()
  return (
    <div
      role="radiogroup"
      aria-label={t('Source')}
      className="grid gap-3 sm:grid-cols-3"
    >
      {SOURCE_TABS.map((tab) => {
        const Icon = tab.icon
        const selected = value === tab.id
        return (
          <button
            key={tab.id}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onValueChange(tab.id)}
            className={cn(
              'flex w-full items-start gap-3 rounded-xl border border-border bg-card/50 p-3.5 text-start transition-all hover:bg-card',
              selected &&
                'border-primary ring-1 ring-primary/20 hover:border-primary',
            )}
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0">
              <span className="block text-[13px] font-medium text-foreground">
                {t(tab.title)}
              </span>
              <span className="mt-0.5 block text-[12px] leading-snug text-muted-foreground">
                {t(tab.description)}
              </span>
            </span>
          </button>
        )
      })}
    </div>
  )
}

function FileIssue({
  issue,
  bucket,
}: {
  issue: 'size' | 'extension'
  bucket: Models.Bucket | null | undefined
}) {
  const t = useT()
  const max = bucketMaxFileSize(bucket)
  return (
    <p className="flex items-start gap-1.5 text-[12px] text-destructive">
      <AlertCircle className="mt-px h-3.5 w-3.5 shrink-0" />
      <span>
        {issue === 'size'
          ? `${t('Too large for this bucket.')} ${t('Maximum file size:')} ${max ? formatBytes(max) : '-'}`
          : t('This bucket does not accept this file type.')}
      </span>
    </p>
  )
}

export function UploadPanel({
  file,
  onFileChange,
  bucket,
}: {
  file: File | null
  onFileChange: (file: File | null) => void
  bucket: Models.Bucket | null | undefined
}) {
  const t = useT()
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [rejected, setRejected] = useState(false)

  const accept = (candidate: File | undefined) => {
    if (!candidate) return
    const ok = storageFileMatchesAccept(
      { mimeType: candidate.type, name: candidate.name },
      STORAGE_FILE_ACCEPT_VIDEO_AUDIO,
    )
    setRejected(!ok)
    if (ok) onFileChange(candidate)
  }

  const onDrop = (event: DragEvent<HTMLElement>) => {
    event.preventDefault()
    setDragging(false)
    accept(event.dataTransfer.files?.[0])
  }

  const issue = file ? getBucketFileIssue(bucket, file) : null
  const Icon = file?.type.startsWith('audio/') ? FileAudio : FileVideo

  return (
    <div className="space-y-2">
      <input
        ref={inputRef}
        type="file"
        className="sr-only"
        accept={UPLOAD_ACCEPT}
        onChange={(event) => {
          accept(event.target.files?.[0])
          event.target.value = ''
        }}
      />
      {file ? (
        <div className="flex items-center gap-3 rounded-xl border border-border bg-card/50 px-4 py-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Icon className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-[13px] font-medium text-foreground">
              {file.name}
            </p>
            <p className="truncate text-[12px] text-muted-foreground">
              {[file.type || '-', formatBytes(file.size)].join(' · ')}
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={() => inputRef.current?.click()}
          >
            {t('Replace')}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
            aria-label={t('Remove file')}
            onClick={() => onFileChange(null)}
          >
            <X className="h-4 w-4" />
          </Button>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          onDragOver={(event) => {
            event.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={cn(
            'flex w-full flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-muted/20 px-6 py-12 text-center transition-colors hover:border-foreground/30 hover:bg-muted/40',
            dragging && 'border-primary bg-primary/5',
          )}
        >
          <span className="flex h-11 w-11 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-xs">
            <Upload className="h-5 w-5" />
          </span>
          <span>
            <span className="block text-[14px] font-medium text-foreground">
              {t('Drop a file here or click to browse')}
            </span>
            <span className="mt-1 block text-[12px] text-muted-foreground">
              {t('MP4, MOV, WebM, MKV, and audio files')}
              {bucketMaxFileSize(bucket)
                ? ` · ${t('Up to')} ${formatBytes(bucketMaxFileSize(bucket)!)}`
                : ''}
            </span>
          </span>
        </button>
      )}
      {rejected ? (
        <p className="flex items-start gap-1.5 text-[12px] text-destructive">
          <AlertCircle className="mt-px h-3.5 w-3.5 shrink-0" />
          {t('Choose a video or audio file.')}
        </p>
      ) : null}
      {issue ? <FileIssue issue={issue} bucket={bucket} /> : null}
    </div>
  )
}

export function StoragePanel({
  projectId,
  file,
  selection,
  onSelectionChange,
}: {
  projectId: string
  file: Models.File | undefined
  selection: StorageFileSelection | null
  onSelectionChange: (selection: StorageFileSelection | null) => void
}) {
  const t = useT()
  const [initialBucketId] = useState(selection?.bucketId ?? null)
  const explorerRef = useRef<HTMLDivElement>(null)
  const tailRef = useRef<HTMLDivElement>(null)
  const explorerHeight = useFillAvailableHeight(explorerRef, tailRef)
  const Icon = file?.mimeType?.startsWith('audio/') ? FileAudio : FileVideo
  const isMedia =
    !file || storageFileMatchesAccept(file, STORAGE_FILE_ACCEPT_VIDEO_AUDIO)
  return (
    <div className="space-y-2">
      <div
        ref={explorerRef}
        style={{ height: explorerHeight }}
        className="overflow-hidden rounded-xl border border-border bg-card/50"
      >
        <StorageFileExplorer
          projectId={projectId}
          selection={selection}
          onSelectionChange={onSelectionChange}
          accept={STORAGE_FILE_ACCEPT_VIDEO_AUDIO}
          acceptMismatchTooltip={t('Choose a video or audio file.')}
          initialBucketId={initialBucketId}
          keepSelectionOnNavigate
          aboveWizard
          variant="inline"
          className="h-full"
        />
      </div>
      <div ref={tailRef} className="space-y-2">
        {selection ? (
          <div className="flex items-center gap-3 rounded-xl border border-border bg-card/50 px-4 py-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Icon className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13px] font-medium text-foreground">
                {file?.name ?? t('Loading file...')}
              </p>
              <p className="truncate text-[12px] text-muted-foreground">
                {file
                  ? [file.mimeType, formatBytes(file.sizeOriginal)].join(' · ')
                  : '-'}
              </p>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
              aria-label={t('Remove file')}
              onClick={() => onSelectionChange(null)}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        ) : (
          <p className="text-[12px] text-muted-foreground">
            {t('The file stays in its bucket. Nothing is copied.')}
          </p>
        )}
        {!isMedia ? (
          <p className="text-[12px] text-amber-600 dark:text-amber-400">
            {t(
              'This file does not look like video or audio. The server may reject it.',
            )}
          </p>
        ) : null}
      </div>
    </div>
  )
}

const MIN_EXPLORER_HEIGHT = 320
/** Gap above the tail plus the wizard content's bottom padding (`py-6`). */
const EXPLORER_BOTTOM_RESERVE = 8 + 24

function getScrollParent(element: HTMLElement): HTMLElement | null {
  let node = element.parentElement
  while (node) {
    const { overflowY } = getComputedStyle(node)
    if (overflowY === 'auto' || overflowY === 'scroll') return node
    node = node.parentElement
  }
  return null
}

/** Height that fills the scroll parent from `ref` down, leaving room for `tailRef`. */
function useFillAvailableHeight(
  ref: RefObject<HTMLElement | null>,
  tailRef: RefObject<HTMLElement | null>,
) {
  const [height, setHeight] = useState(440)

  useLayoutEffect(() => {
    const element = ref.current
    if (!element) return
    const scroller = getScrollParent(element)
    if (!scroller) return

    const measure = () => {
      const offsetTop =
        element.getBoundingClientRect().top -
        scroller.getBoundingClientRect().top +
        scroller.scrollTop
      const tail = tailRef.current?.offsetHeight ?? 0
      const next = Math.max(
        MIN_EXPLORER_HEIGHT,
        Math.floor(
          scroller.clientHeight - offsetTop - tail - EXPLORER_BOTTOM_RESERVE,
        ),
      )
      setHeight((prev) => (prev === next ? prev : next))
    }

    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(scroller)
    if (tailRef.current) observer.observe(tailRef.current)
    window.addEventListener('resize', measure)
    return () => {
      observer.disconnect()
      window.removeEventListener('resize', measure)
    }
  }, [ref, tailRef])

  return height
}

export function DemoPanel({
  selectedId,
  onSelect,
  bucket,
}: {
  selectedId: string | null
  onSelect: (demo: DemoVideo) => void
  bucket: Models.Bucket | null | undefined
}) {
  const t = useT()
  const maxFileSize = bucketMaxFileSize(bucket)
  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {DEMO_VIDEOS.map((demo) => {
          const variant = pickDemoVideoVariant(demo, maxFileSize)
          const selected = selectedId === demo.id
          const card = (
            <button
              type="button"
              disabled={!variant}
              aria-pressed={selected}
              onClick={() => onSelect(demo)}
              className={cn(
                'group w-full overflow-hidden rounded-xl border border-border bg-card/50 text-start transition-all',
                variant
                  ? 'hover:border-foreground/20 hover:bg-card'
                  : 'cursor-not-allowed opacity-50',
                selected &&
                  'border-primary ring-1 ring-primary/20 hover:border-primary',
              )}
            >
              <div className="relative aspect-video overflow-hidden bg-muted">
                <img
                  src={demo.posterUrl}
                  alt=""
                  loading="lazy"
                  className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                />
                <span className="absolute bottom-2 end-2 rounded bg-black/75 px-1.5 py-0.5 font-mono text-[11px] tabular-nums text-white">
                  {formatVideoDuration(demo.duration)}
                </span>
                {selected ? (
                  <span className="absolute top-2 end-2 flex h-6 w-6 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm">
                    <Check className="h-3.5 w-3.5" />
                  </span>
                ) : null}
              </div>
              <div className="space-y-1 p-3">
                <div className="flex items-center gap-2">
                  <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                    {demo.title}
                  </p>
                  {variant ? (
                    <Badge variant="info" className="text-[10px] shrink-0">
                      {variant.quality}
                    </Badge>
                  ) : null}
                </div>
                <p className="line-clamp-2 text-[12px] leading-snug text-muted-foreground">
                  {t(demo.description)}
                </p>
                <p className="text-[11px] tabular-nums text-muted-foreground/80">
                  {variant ? formatBytes(variant.size) : '-'} · {demo.license}
                </p>
              </div>
            </button>
          )
          return variant ? (
            <div key={demo.id}>{card}</div>
          ) : (
            <Tooltip key={demo.id}>
              <TooltipTrigger asChild>
                <span className="block">{card}</span>
              </TooltipTrigger>
              <TooltipContent className={cn('max-w-xs text-xs', TOOLTIP_Z)}>
                {t('Too large for the selected bucket.')}
              </TooltipContent>
            </Tooltip>
          )
        })}
      </div>
      <p className="text-[12px] leading-relaxed text-muted-foreground">
        {t(
          'Open movies by Blender Studio, served from Wikimedia Commons. The file is downloaded in your browser and uploaded to the selected bucket.',
        )}
      </p>
    </div>
  )
}

export function DestinationBucketField({
  buckets,
  bucketsLoading,
  value,
  onValueChange,
  onCreateBucket,
  createBucketDisabledReason,
  creatingBucket,
}: {
  buckets: Models.Bucket[]
  bucketsLoading: boolean
  value: string | null
  onValueChange: (bucketId: string) => void
  onCreateBucket: () => void
  createBucketDisabledReason: string | null
  creatingBucket: boolean
}) {
  const t = useT()
  const selected = buckets.find((bucket) => bucket.$id === value)
  const max = bucketMaxFileSize(selected)

  const createButton = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-9 gap-1.5 text-[13px]"
      disabled={!!createBucketDisabledReason || creatingBucket}
      onClick={onCreateBucket}
    >
      <Plus className="h-3.5 w-3.5" />
      {t('Create Videos bucket')}
    </Button>
  )

  return (
    <div className="space-y-2">
      <Label className="text-[13px]">{t('Destination bucket')}</Label>
      {!bucketsLoading && buckets.length === 0 ? (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card/50 px-4 py-3 sm:flex-row sm:items-center">
          <p className="min-w-0 flex-1 text-[12px] text-muted-foreground">
            {t(
              'The source file is stored in a Storage bucket. Create one to continue.',
            )}
          </p>
          {createBucketDisabledReason ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">{createButton}</span>
              </TooltipTrigger>
              <TooltipContent className={cn('max-w-xs text-xs', TOOLTIP_Z)}>
                {createBucketDisabledReason}
              </TooltipContent>
            </Tooltip>
          ) : (
            createButton
          )}
        </div>
      ) : (
        <>
          <Select
            value={value ?? undefined}
            onValueChange={onValueChange}
            disabled={bucketsLoading}
          >
            <SelectTrigger className="h-9 w-full text-[13px]">
              <SelectValue placeholder={t('Select a bucket')} />
            </SelectTrigger>
            <SelectContent>
              {buckets.map((bucket) => (
                <SelectItem key={bucket.$id} value={bucket.$id}>
                  <span className="truncate">{bucket.name}</span>
                  <span className="ms-2 font-mono text-[11px] text-muted-foreground">
                    {bucket.$id}
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-[12px] text-muted-foreground">
            {t('The uploaded file is stored here and stays in Storage.')}
            {max ? ` ${t('Maximum file size:')} ${formatBytes(max)}` : ''}
          </p>
        </>
      )}
    </div>
  )
}
