import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { Link, useBlocker, useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  ArrowLeft,
  Download,
  Eraser,
  FileText,
  FolderOpen,
  HardDrive,
  Keyboard,
  MoreHorizontal,
  MoveHorizontal,
  Redo2,
  SeparatorVertical,
  Undo2,
  Upload,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import { VideoStatusBadge } from '../VideoStatusBadge'
import { VideoActionButton, VideoPage } from '../VideoPage'
import { useVideoDetailActions } from '../video-detail-actions'
import { PlaybackClockContext, usePlaybackClock } from './playback-clock'
import { ShiftCues } from './ShiftCues'
import {
  SUBTITLE_CUE_TEXT_INPUT_ID,
  SubtitleCuePanel,
  type CuePatch,
} from './SubtitleCuePanel'
import { SubtitleEditorPlayer, SubtitleTransport } from './SubtitleEditorPlayer'
import { SubtitleFilePreview } from './SubtitleFilePreview'
import { SubtitleTimeline } from './SubtitleTimeline'
import {
  bucketAcceptsWebVtt,
  isSubtitleTrackNameValid,
  SubtitleTrackDetails,
  type SubtitleTrackDraft,
} from './SubtitleTrackDetails'
import { useSubtitleHistory } from './use-subtitle-history'
import { DeleteSubtitle } from '../DeleteSubtitle'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import {
  StorageFileExplorerDialog,
  STORAGE_FILE_ACCEPT_SUBTITLE,
} from '@/components/global/shared/StorageFileExplorerDialog'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import {
  fetchVideoSubtitleCues,
  useProjectBuckets,
  useProjectVideo,
  useDeleteVideoSubtitle,
  useSaveVideoSubtitleCues,
  useUpdateVideoSubtitle,
  useVideoRenditions,
  useVideoTimeline,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  createSubtitleCueId,
  mergeSubtitleCues,
  parseSubtitleText,
  shiftSubtitleCues,
  sortSubtitleCues,
  splitSubtitleCue,
  stripCueMarkup,
  SUBTITLE_DEFAULT_CUE_DURATION,
  SUBTITLE_MIN_CUE_DURATION,
  trimSubtitleOverlaps,
  validateSubtitleCues,
  type SubtitleCue,
} from '@/lib/videos/subtitle-cues'
import {
  downloadSubtitleCues,
  SUBTITLE_DOWNLOAD_FORMATS,
  type SubtitleDownloadFormat,
} from '@/lib/videos/subtitle-download'

type EditorTab = 'cues' | 'track' | 'file'

const FRAME_STEP = 1 / 25
const roundMs = (seconds: number) => Math.round(seconds * 1000) / 1000

const IS_MAC =
  typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)
const MOD = IS_MAC ? '⌘' : 'Ctrl'

const SHORTCUTS: Array<{ keys: string[]; label: string }> = [
  { keys: ['Space'], label: 'Play or pause' },
  { keys: ['←', '→'], label: 'Seek 1 second (Shift: 5 seconds)' },
  { keys: [',', '.'], label: 'Step one frame' },
  { keys: ['M'], label: 'Mute or unmute' },
  { keys: ['↑', '↓'], label: 'Previous or next cue' },
  { keys: ['N'], label: 'Add cue at playhead' },
  { keys: ['S'], label: 'Split cue at playhead' },
  { keys: ['['], label: 'Set start to playhead' },
  { keys: [']'], label: 'Set end to playhead' },
  { keys: ['Enter'], label: 'Update cue text' },
  { keys: ['Delete'], label: 'Delete selected cues' },
  { keys: [MOD, 'Z'], label: 'Undo' },
  { keys: [MOD, 'Shift', 'Z'], label: 'Redo' },
  { keys: [MOD, 'S'], label: 'Save' },
]

/** Clicks inside these keep the selected cues; anywhere else clears them. */
const KEEP_SELECTION_SELECTOR = [
  '[data-subtitle-keep-selection]',
  '[data-radix-popper-content-wrapper]',
  '[role="dialog"]',
  '[role="alertdialog"]',
  '[data-sonner-toaster]',
].join(', ')

function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return (
    target.isContentEditable ||
    target.tagName === 'INPUT' ||
    target.tagName === 'TEXTAREA' ||
    target.tagName === 'SELECT' ||
    target.getAttribute('role') === 'spinbutton' ||
    target.getAttribute('role') === 'slider' ||
    !!target.closest('[role="dialog"], [role="menu"], [role="listbox"][data-radix-select-viewport]')
  )
}

export function SubtitleEditor({
  projectId,
  videoId,
  subtitle,
  initialCues,
}: {
  projectId: string
  videoId: string
  /** The track being updated; omitted when creating a new one. */
  subtitle?: Models.VideoSubtitle | null
  initialCues: SubtitleCue[]
}) {
  const t = useT()
  const navigate = useNavigate()
  const isCreate = !subtitle
  const { writeDisabledReason } = useVideoDetailActions()
  const { data: video } = useProjectVideo(projectId, videoId)
  const { data: renditionsData } = useVideoRenditions(projectId, videoId)
  const { data: timeline } = useVideoTimeline(projectId, videoId)
  const { buckets, isLoading: bucketsLoading } = useProjectBuckets(
    projectId,
    0,
    100,
  )
  const saveMutation = useSaveVideoSubtitleCues(projectId, videoId)
  const updateMutation = useUpdateVideoSubtitle(projectId, videoId)
  const saving = saveMutation.isPending || updateMutation.isPending

  const history = useSubtitleHistory(initialCues)
  const { cues, commit } = history

  const [draft, setDraft] = useState<SubtitleTrackDraft>(() => ({
    name: subtitle?.name ?? '',
    code: subtitle?.code ?? '',
    isDefault: subtitle?.default ?? false,
    bucketId: subtitle?.bucketId || '',
  }))
  useEffect(() => {
    if (draft.bucketId || buckets.length === 0) return
    const preferred =
      buckets.find((b) => b.$id === video?.bucketId && bucketAcceptsWebVtt(b)) ??
      buckets.find(bucketAcceptsWebVtt) ??
      buckets[0]
    setDraft((prev) => ({ ...prev, bucketId: preferred.$id }))
  }, [buckets, draft.bucketId, video?.bucketId])

  const trackDirty =
    !!subtitle &&
    (draft.name.trim() !== subtitle.name ||
      draft.code !== subtitle.code ||
      draft.isDefault !== subtitle.default)
  const dirty = history.dirty || trackDirty || (isCreate && cues.length > 0)
  const dirtyRef = useRef(dirty)
  dirtyRef.current = dirty

  const [tab, setTab] = useState<EditorTab>(isCreate ? 'track' : 'cues')
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set())
  const [primaryId, setPrimaryId] = useState<string | null>(null)
  const [shiftOpen, setShiftOpen] = useState(false)
  const [storageImportOpen, setStorageImportOpen] = useState(false)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const videoRef = useRef<HTMLVideoElement | null>(null)
  const clock = usePlaybackClock(videoRef)
  const duration = (video?.duration ?? 0) / 1000 // API reports milliseconds

  const issues = useMemo(
    () => validateSubtitleCues(cues, duration),
    [cues, duration],
  )
  const errorCount = useMemo(
    () =>
      [...issues.values()].filter((list) =>
        list.some((issue) => issue.severity === 'error'),
      ).length,
    [issues],
  )

  const getPlayhead = useCallback(
    () => roundMs(videoRef.current?.currentTime ?? 0),
    [],
  )
  const seek = useCallback((time: number) => {
    const el = videoRef.current
    if (!el) return
    el.currentTime = Math.max(0, Math.min(el.duration || time, time))
  }, [])

  const selectOnly = useCallback((id: string | null) => {
    setSelectedIds(id ? new Set([id]) : new Set())
    setPrimaryId(id)
  }, [])

  const cuesRef = useRef(cues)
  cuesRef.current = cues
  const primaryRef = useRef(primaryId)
  primaryRef.current = primaryId

  const selectFromList = useCallback(
    (id: string, { additive, range }: { additive: boolean; range: boolean }) => {
      if (range && primaryRef.current) {
        const list = cuesRef.current
        const from = list.findIndex((cue) => cue.id === primaryRef.current)
        const to = list.findIndex((cue) => cue.id === id)
        const [a, b] = from < to ? [from, to] : [to, from]
        setSelectedIds(new Set(list.slice(a, b + 1).map((cue) => cue.id)))
        return
      }
      if (additive) {
        setSelectedIds((prev) => {
          const next = new Set(prev)
          if (next.has(id)) next.delete(id)
          else next.add(id)
          return next
        })
        setPrimaryId(id)
        return
      }
      selectOnly(id)
      const cue = cuesRef.current.find((item) => item.id === id)
      if (cue) seek(cue.start)
    },
    [seek, selectOnly],
  )

  const selectFromTimeline = useCallback(
    (id: string, additive: boolean) => {
      if (!additive) {
        selectOnly(id)
        return
      }
      setSelectedIds((prev) => {
        const next = new Set(prev)
        if (next.has(id)) next.delete(id)
        else next.add(id)
        return next
      })
      setPrimaryId(id)
    },
    [selectOnly],
  )

  const selectAdjacentCue = (direction: -1 | 1) => {
    if (cues.length === 0) return
    const index = cues.findIndex((cue) => cue.id === primaryId)
    let nextIndex: number
    if (index >= 0) {
      nextIndex = Math.max(0, Math.min(cues.length - 1, index + direction))
    } else {
      // Nothing selected: step from the playhead.
      const time = getPlayhead()
      const after = cues.findIndex((cue) => cue.start >= time)
      nextIndex =
        direction > 0
          ? after < 0 ? cues.length - 1 : after
          : Math.max(0, (after < 0 ? cues.length : after) - 1)
    }
    selectFromList(cues[nextIndex].id, { additive: false, range: false })
  }

  const changeCue = useCallback(
    (id: string, patch: CuePatch, coalesceKey?: string) => {
      commit((list) => {
        const index = list.findIndex((cue) => cue.id === id)
        if (index < 0) return list
        const current = list[index]
        const next = {
          ...current,
          ...patch,
          ...(patch.start != null ? { start: roundMs(Math.max(0, patch.start)) } : {}),
          ...(patch.end != null ? { end: roundMs(Math.max(0, patch.end)) } : {}),
        }
        if (
          next.start === current.start &&
          next.end === current.end &&
          next.text === current.text
        ) {
          return list
        }
        const updated = [...list]
        updated[index] = next
        return patch.start != null || patch.end != null
          ? sortSubtitleCues(updated)
          : updated
      }, coalesceKey)
    },
    [commit],
  )

  const retime = useCallback(
    (id: string, start: number, end: number, session: string) =>
      changeCue(id, { start, end }, session),
    [changeCue],
  )

  const focusCueText = () =>
    requestAnimationFrame(() =>
      document.getElementById(SUBTITLE_CUE_TEXT_INPUT_ID)?.focus(),
    )

  const addCueAtPlayhead = () => {
    const start = getPlayhead()
    const next = cues.find((cue) => cue.start > start + SUBTITLE_MIN_CUE_DURATION)
    let end = start + SUBTITLE_DEFAULT_CUE_DURATION
    if (next && next.start < end) end = next.start
    if (duration > 0) end = Math.min(end, Math.max(duration, start + SUBTITLE_MIN_CUE_DURATION))
    const cue: SubtitleCue = {
      id: createSubtitleCueId(),
      start,
      end: roundMs(Math.max(end, start + SUBTITLE_MIN_CUE_DURATION)),
      text: '',
    }
    commit((list) => sortSubtitleCues([...list, cue]))
    selectOnly(cue.id)
    setTab('cues')
    focusCueText()
  }

  const splitTarget = (time: number) => {
    const primary = cues.find((cue) => cue.id === primaryId)
    return primary && primary.start < time && time < primary.end
      ? primary
      : cues.find((cue) => cue.start < time && time < cue.end)
  }

  const splitAt = (time: number, failureMessage: string) => {
    const target = splitTarget(time)
    const parts = target ? splitSubtitleCue(target, roundMs(time)) : null
    if (!target || !parts) {
      toast.info(failureMessage)
      return false
    }
    commit((list) =>
      sortSubtitleCues([
        ...list.filter((cue) => cue.id !== target.id),
        ...parts,
      ]),
    )
    selectOnly(parts[1].id)
    return true
  }

  const splitAtPlayhead = () =>
    splitAt(getPlayhead(), t('Move the playhead inside a cue to split it.'))

  /** Split tool: the timeline shows a cut line under the pointer and splits on click. */
  const [splitMode, setSplitMode] = useState(false)

  const splitCueAt = (id: string, time: number, keepSplitting: boolean) => {
    const target = cues.find((cue) => cue.id === id)
    const parts = target ? splitSubtitleCue(target, roundMs(time)) : null
    if (!target || !parts) return
    commit((list) =>
      sortSubtitleCues([...list.filter((cue) => cue.id !== id), ...parts]),
    )
    selectOnly(parts[1].id)
    if (!keepSplitting) setSplitMode(false)
  }

  const deleteCues = (ids: string[]) => {
    if (ids.length === 0) return
    const remove = new Set(ids)
    const firstIndex = cues.findIndex((cue) => remove.has(cue.id))
    const remaining = cues.filter((cue) => !remove.has(cue.id))
    commit(() => remaining)
    selectOnly(remaining[Math.min(firstIndex, remaining.length - 1)]?.id ?? null)
  }

  const mergeWithNext = (id: string) => {
    const index = cues.findIndex((cue) => cue.id === id)
    const next = cues[index + 1]
    if (index < 0 || !next) return
    const merged = mergeSubtitleCues(cues[index], next)
    commit((list) =>
      sortSubtitleCues([
        ...list.filter((cue) => cue.id !== id && cue.id !== next.id),
        merged,
      ]),
    )
    selectOnly(merged.id)
  }

  const shiftCues = (offset: number, scope: 'selected' | 'all') => {
    commit((list) =>
      sortSubtitleCues(
        shiftSubtitleCues(list, offset, scope === 'selected' ? selectedIds : undefined),
      ),
    )
    toast.success(t('Cue timings shifted'))
  }

  const fixOverlaps = () => {
    const fixed = trimSubtitleOverlaps(cues)
    const changed = fixed.filter((cue, index) => cue !== cues[index]).length
    if (changed === 0) {
      toast.info(t('No overlapping cues'))
      return
    }
    commit(() => fixed)
    toast.success(
      t('Trimmed {count} overlapping cues').replace('{count}', String(changed)),
    )
  }

  const removeEmptyCues = () => {
    const kept = cues.filter((cue) => stripCueMarkup(cue.text).trim())
    const removed = cues.length - kept.length
    if (removed === 0) {
      toast.info(t('No empty cues'))
      return
    }
    commit(() => kept)
    toast.success(
      t('Removed {count} empty cues').replace('{count}', String(removed)),
    )
  }

  const importCues = (imported: SubtitleCue[]) => {
    if (imported.length === 0) {
      toast.error(t('No cues found in this file'))
      return
    }
    commit(() => imported)
    selectOnly(null)
    toast.success(
      t('Imported {count} cues. Undo to restore the previous cues.').replace(
        '{count}',
        String(imported.length),
      ),
    )
  }

  const importLocalFile = async (file: File) => {
    try {
      importCues(parseSubtitleText(await file.text()))
    } catch (error) {
      toast.error(getErrorMessage(error) || t('Failed to import subtitles'))
    }
  }

  const importStorageFile = async (selection: {
    bucketId: string
    fileId: string
  }) => {
    try {
      importCues(
        await fetchVideoSubtitleCues(projectId, videoId, {
          $id: '',
          bucketId: selection.bucketId,
          fileId: selection.fileId,
        }),
      )
    } catch (error) {
      toast.error(getErrorMessage(error) || t('Failed to import subtitles'))
    }
  }

  const exportCues = (format: SubtitleDownloadFormat) =>
    downloadSubtitleCues(
      cues,
      draft.name.trim() || subtitle?.name || '',
      draft.code,
      format,
    )

  const [deleteOpen, setDeleteOpen] = useState(false)
  const deleteMutation = useDeleteVideoSubtitle(projectId, videoId)
  const confirmDelete = () => {
    if (!subtitle) return
    deleteMutation.mutate(subtitle.$id, {
      onSuccess: () => {
        dirtyRef.current = false
        setDeleteOpen(false)
        toast.success(t('Subtitle deleted'))
        void navigate({
          to: '/projects/$projectId/videos/$videoId/subtitles',
          params: { projectId, videoId },
          replace: true,
        })
      },
      onError: (error) =>
        toast.error(getErrorMessage(error) || t('Failed to delete subtitle')),
    })
  }

  const selectedBucket = buckets.find((bucket) => bucket.$id === draft.bucketId)
  const needsUpload = isCreate || history.dirty
  const saveDisabledReason = (() => {
    if (writeDisabledReason) return writeDisabledReason
    if (cues.length === 0) return t('Add at least one cue.')
    if (errorCount > 0) {
      return t('Fix {count} cues with errors before saving.').replace(
        '{count}',
        String(errorCount),
      )
    }
    if (!draft.code) return t('Choose a language in Settings.')
    if (!isSubtitleTrackNameValid(draft.name)) {
      return t('Enter a valid name in Settings.')
    }
    if (needsUpload && !draft.bucketId) {
      return t('Choose a Storage bucket in Settings.')
    }
    if (needsUpload && !bucketAcceptsWebVtt(selectedBucket)) {
      return t('The selected bucket does not accept .vtt files.')
    }
    if (!dirty) return t('No changes to save.')
    return null
  })()

  const save = () => {
    if (saveDisabledReason || saving) return
    const onError = (error: Error) =>
      toast.error(getErrorMessage(error) || t('Failed to save subtitle'))
    const track = {
      name: draft.name.trim(),
      code: draft.code,
      isDefault: draft.isDefault,
    }

    if (subtitle && !history.dirty) {
      updateMutation.mutate(
        { subtitleId: subtitle.$id, ...track },
        {
          onSuccess: () => toast.success(t('Subtitle updated')),
          onError,
        },
      )
      return
    }

    saveMutation.mutate(
      {
        subtitleId: subtitle?.$id,
        cues,
        bucketId: draft.bucketId,
        ...track,
      },
      {
        onSuccess: (saved) => {
          history.markSaved()
          if (subtitle) {
            toast.success(
              t('Subtitle saved. The track is re-packaged in the background.'),
            )
            return
          }
          toast.success(t('Subtitle created'))
          dirtyRef.current = false
          void navigate({
            to: '/projects/$projectId/videos/$videoId/subtitles/$subtitleId',
            params: { projectId, videoId, subtitleId: saved.$id },
            replace: true,
          })
        },
        onError,
      },
    )
  }

  const saveRef = useRef(save)
  saveRef.current = save

  const blocker = useBlocker({
    shouldBlockFn: () => dirtyRef.current,
    enableBeforeUnload: () => dirtyRef.current,
    withResolver: true,
  })

  const shortcutsRef = useRef<(event: KeyboardEvent) => void>(() => {})
  shortcutsRef.current = (event: KeyboardEvent) => {
    const mod = event.metaKey || event.ctrlKey
    const key = event.key.toLowerCase()
    if (mod && key === 's') {
      event.preventDefault()
      saveRef.current()
      return
    }
    if (isTypingTarget(event.target)) return
    if (mod && key === 'z') {
      event.preventDefault()
      if (event.shiftKey) history.redo()
      else history.undo()
      return
    }
    if (mod && key === 'y') {
      event.preventDefault()
      history.redo()
      return
    }
    if (mod || event.altKey) return
    const el = videoRef.current
    const primary = cues.find((cue) => cue.id === primaryId)
    switch (event.key) {
      case ' ':
      case 'k':
        if (!el) return
        if (el.paused) void el.play().catch(() => {})
        else el.pause()
        break
      case 'ArrowLeft':
      case 'ArrowRight': {
        const step = (event.shiftKey ? 5 : 1) * (event.key === 'ArrowLeft' ? -1 : 1)
        seek((el?.currentTime ?? 0) + step)
        break
      }
      case ',':
      case '.':
        el?.pause()
        seek((el?.currentTime ?? 0) + (event.key === ',' ? -FRAME_STEP : FRAME_STEP))
        break
      case 'ArrowUp':
      case 'ArrowDown':
        if (cues.length === 0) return
        selectAdjacentCue(event.key === 'ArrowUp' ? -1 : 1)
        break
      case 'n':
      case 'N':
        addCueAtPlayhead()
        break
      case 's':
      case 'S':
        splitAtPlayhead()
        break
      case 'm':
      case 'M':
        if (videoRef.current) videoRef.current.muted = !videoRef.current.muted
        break
      case '[':
        if (primary) changeCue(primary.id, { start: getPlayhead() })
        break
      case ']':
        if (primary) changeCue(primary.id, { end: getPlayhead() })
        break
      case 'Enter':
        if (!primary) return
        setTab('cues')
        focusCueText()
        break
      case 'Delete':
      case 'Backspace':
        deleteCues([...selectedIds])
        break
      case 'Escape':
        if (splitMode) setSplitMode(false)
        else selectOnly(null)
        break
      default:
        return
    }
    event.preventDefault()
  }

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => shortcutsRef.current(event)
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  useEffect(() => {
    const onPointerDown = (event: PointerEvent) => {
      if (!(event.target instanceof Element)) return
      if (event.target.closest(KEEP_SELECTION_SELECTOR)) return
      // Commit pending inspector input before the inspector unmounts.
      const focused = document.activeElement
      if (
        focused instanceof HTMLElement &&
        focused.closest('[data-subtitle-keep-selection]')
      ) {
        focused.blur()
      }
      selectOnly(null)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [selectOnly])

  if (!video) return null

  // The h-7 button overhangs the 21px title line so the header keeps its height.
  const backButton = (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          asChild
          variant="ghost"
          size="icon"
          className="-my-1 -ms-1 h-7 w-7 shrink-0"
        >
          <Link
            to="/projects/$projectId/videos/$videoId/subtitles"
            params={{ projectId, videoId }}
            aria-label={t('Back to subtitles')}
          >
            <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
          </Link>
        </Button>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">
        {t('Back to subtitles')}
      </TooltipContent>
    </Tooltip>
  )

  const title = (
    <span className="flex min-w-0 items-center gap-2">
      <span className="truncate">
        {isCreate ? t('New subtitle') : subtitle.name}
      </span>
      {subtitle ? (
        <span className="hidden shrink-0 sm:inline-flex">
          <VideoStatusBadge status={subtitle.status} kind="subtitle" />
        </span>
      ) : null}
      {dirty ? (
        <>
          <span className="hidden shrink-0 text-[12px] font-normal text-muted-foreground lg:inline">
            {t('Unsaved changes')}
          </span>
          <span
            role="img"
            aria-label={t('Unsaved changes')}
            className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500 lg:hidden"
          />
        </>
      ) : null}
    </span>
  )

  const importItems = (
    <>
      <DropdownMenuItem onClick={() => fileInputRef.current?.click()}>
        <MenuItemContent icon={HardDrive}>{t('From your computer')}</MenuItemContent>
      </DropdownMenuItem>
      <DropdownMenuItem onClick={() => setStorageImportOpen(true)}>
        <MenuItemContent icon={FolderOpen}>{t('From Storage')}</MenuItemContent>
      </DropdownMenuItem>
    </>
  )
  const exportItems = SUBTITLE_DOWNLOAD_FORMATS.map((format) => (
    <DropdownMenuItem key={format.id} onClick={() => exportCues(format.id)}>
      <MenuItemContent icon={FileText}>{t(format.label)}</MenuItemContent>
    </DropdownMenuItem>
  ))
  const toolItems = (
    <>
      <DropdownMenuItem onClick={() => setShiftOpen(true)}>
        <MenuItemContent icon={MoveHorizontal}>{t('Shift timings')}</MenuItemContent>
      </DropdownMenuItem>
      <DropdownMenuItem onClick={fixOverlaps}>
        <MenuItemContent icon={SeparatorVertical}>{t('Fix overlaps')}</MenuItemContent>
      </DropdownMenuItem>
      <DropdownMenuItem onClick={removeEmptyCues}>
        <MenuItemContent icon={Eraser}>{t('Remove empty cues')}</MenuItemContent>
      </DropdownMenuItem>
    </>
  )

  const toolbar = (
    <div data-subtitle-keep-selection className="flex items-center gap-1">
      <div className="hidden items-center gap-1 md:flex">
        <ShortcutsPopover />
        <div className="mx-1 h-5 w-px bg-border" />
      </div>
      <HeaderIconButton
        icon={Undo2}
        label={t('Undo')}
        disabled={!history.canUndo}
        onClick={history.undo}
      />
      <HeaderIconButton
        icon={Redo2}
        label={t('Redo')}
        disabled={!history.canRedo}
        onClick={history.redo}
      />
      <div className="hidden items-center gap-1 md:flex">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" size="sm" className="h-9 gap-1.5 text-[13px]">
              <Upload className="h-3.5 w-3.5" />
              {t('Import')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            {importItems}
          </DropdownMenuContent>
        </DropdownMenu>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              disabled={cues.length === 0}
            >
              <Download className="h-3.5 w-3.5" />
              {t('Export')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            {exportItems}
          </DropdownMenuContent>
        </DropdownMenu>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              disabled={cues.length === 0}
            >
              <Wrench className="h-3.5 w-3.5" />
              {t('Tools')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            {toolItems}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
      <DropdownMenu>
        <Tooltip>
          <TooltipTrigger asChild>
            <DropdownMenuTrigger asChild>
              <Button
                variant="outline"
                size="icon"
                className="h-9 w-9 md:hidden"
                aria-label={t('More actions')}
              >
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
          </TooltipTrigger>
          <TooltipContent className="text-[12px]">{t('More actions')}</TooltipContent>
        </Tooltip>
        <DropdownMenuContent align="end" className="w-52">
          <DropdownMenuSub>
            <DropdownMenuSubTrigger>
              <MenuItemIcon icon={Upload} />
              {t('Import')}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="w-52">{importItems}</DropdownMenuSubContent>
          </DropdownMenuSub>
          <DropdownMenuSub>
            <DropdownMenuSubTrigger disabled={cues.length === 0}>
              <MenuItemIcon icon={Download} />
              {t('Export')}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="w-52">{exportItems}</DropdownMenuSubContent>
          </DropdownMenuSub>
          <DropdownMenuSub>
            <DropdownMenuSubTrigger disabled={cues.length === 0}>
              <MenuItemIcon icon={Wrench} />
              {t('Tools')}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="w-56">{toolItems}</DropdownMenuSubContent>
          </DropdownMenuSub>
        </DropdownMenuContent>
      </DropdownMenu>
      <VideoActionButton
        size="sm"
        className="h-9 text-[13px]"
        disabledReason={saveDisabledReason}
        disabled={saving}
        onClick={save}
      >
        {t('Save')}
      </VideoActionButton>
    </div>
  )

  return (
    <VideoPage
      title={title}
      titleLeading={backButton}
      titleActions={toolbar}
      spreadsheetContent
    >
      <PlaybackClockContext.Provider value={clock}>
        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto lg:overflow-hidden">
          {/* Below lg the panes stack as player, timeline, then cues. */}
          <div className="contents lg:flex lg:min-h-0 lg:flex-1 lg:flex-row">
            <div className="order-1 flex min-w-0 shrink-0 flex-col lg:order-none lg:min-h-[260px] lg:flex-1 lg:shrink">
              <div className="flex h-[min(56.25vw,45dvh)] min-h-[180px] flex-col lg:h-auto lg:min-h-0 lg:flex-1">
              <SubtitleEditorPlayer
                projectId={projectId}
                video={video}
                renditions={renditionsData?.renditions ?? []}
                videoRef={videoRef}
                cues={cues}
                selectedId={primaryId}
                languageCode={draft.code}
                onSelectCue={(id) => {
                  selectOnly(id)
                  setTab('cues')
                }}
              />
              </div>
              <SubtitleTransport
                videoRef={videoRef}
                onAddCue={addCueAtPlayhead}
                onToggleSplit={() => setSplitMode((value) => !value)}
                splitActive={splitMode}
                canSplit={cues.length > 0}
              />
            </div>
            <aside className="order-3 flex h-[min(560px,80dvh)] min-h-[360px] w-full shrink-0 flex-col border-t border-border bg-background lg:order-none lg:h-auto lg:min-h-0 lg:w-[380px] lg:border-s lg:border-t-0 xl:w-[420px]">
              <Tabs
                value={tab}
                onValueChange={(value) => setTab(value as EditorTab)}
                className="flex min-h-0 flex-1 flex-col gap-0"
              >
                <div className="shrink-0 border-b border-border px-4 py-2">
                  <TabsList className="h-8">
                    <TabsTrigger value="cues" className="text-[12px]">
                      {t('Cues')}
                      <span className="ms-1 tabular-nums text-muted-foreground">
                        {cues.length}
                      </span>
                    </TabsTrigger>
                    <TabsTrigger value="track" className="text-[12px]">
                      {t('Settings')}
                    </TabsTrigger>
                    <TabsTrigger value="file" className="text-[12px]">
                      {t('File')}
                    </TabsTrigger>
                  </TabsList>
                </div>
                <TabsContent value="cues" className="mt-0 flex min-h-0 flex-1 flex-col">
                  <SubtitleCuePanel
                    cues={cues}
                    issues={issues}
                    selectedIds={selectedIds}
                    primaryId={primaryId}
                    languageCode={draft.code}
                    getPlayhead={getPlayhead}
                    onSelect={selectFromList}
                    onChangeCue={changeCue}
                    onDelete={deleteCues}
                    onMergeNext={mergeWithNext}
                    onShift={() => setShiftOpen(true)}
                    onPrevious={() => selectAdjacentCue(-1)}
                    onNext={() => selectAdjacentCue(1)}
                    onAddCue={addCueAtPlayhead}
                  />
                </TabsContent>
                <TabsContent value="track" className="mt-0 flex min-h-0 flex-1 flex-col">
                  <SubtitleTrackDetails
                    draft={draft}
                    onChange={(patch) => setDraft((prev) => ({ ...prev, ...patch }))}
                    buckets={buckets}
                    bucketsLoading={bucketsLoading}
                    embedded={!!subtitle?.embedded}
                    onDelete={subtitle ? () => setDeleteOpen(true) : undefined}
                    deleteDisabledReason={writeDisabledReason}
                  />
                </TabsContent>
                <TabsContent value="file" className="mt-0 flex min-h-0 flex-1 flex-col">
                  <SubtitleFilePreview cues={cues} languageCode={draft.code} />
                </TabsContent>
              </Tabs>
            </aside>
          </div>
          <SubtitleTimeline
            className="order-2 lg:order-none"
            cues={cues}
            issues={issues}
            selectedIds={selectedIds}
            duration={duration}
            spriteCues={timeline?.cues}
            videoRef={videoRef}
            languageCode={draft.code}
            onSelect={selectFromTimeline}
            onRetime={retime}
            splitMode={splitMode}
            onSplit={splitCueAt}
          />
        </div>
      </PlaybackClockContext.Provider>

      <input
        ref={fileInputRef}
        type="file"
        accept=".vtt,.srt,text/vtt,application/x-subrip"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0]
          event.target.value = ''
          if (file) void importLocalFile(file)
        }}
      />
      <StorageFileExplorerDialog
        open={storageImportOpen}
        onOpenChange={setStorageImportOpen}
        projectId={projectId}
        title={t('Import subtitle file')}
        description={t('Choose a .vtt or .srt file. Its cues replace the cues in the editor.')}
        confirmLabel={t('Import')}
        accept={STORAGE_FILE_ACCEPT_SUBTITLE}
        onConfirm={(selection) => void importStorageFile(selection)}
      />
      <ShiftCues
        open={shiftOpen}
        onOpenChange={setShiftOpen}
        selectedCount={selectedIds.size}
        onApply={shiftCues}
      />
      {subtitle ? (
        <DeleteSubtitle
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
          subtitle={subtitle}
          onConfirm={confirmDelete}
          isConfirming={deleteMutation.isPending}
        />
      ) : null}
      <ConfirmActionDialog
        open={blocker.status === 'blocked'}
        onOpenChange={(open) => {
          if (!open && blocker.status === 'blocked') blocker.reset()
        }}
        title={t('Discard unsaved changes?')}
        description={t('Your subtitle changes have not been saved. Leaving this page discards them.')}
        confirmLabel={t('Discard')}
        confirmVariant="destructive"
        onConfirm={() => {
          if (blocker.status === 'blocked') blocker.proceed()
        }}
      />
    </VideoPage>
  )
}

function HeaderIconButton({
  icon: Icon,
  label,
  disabled,
  onClick,
}: {
  icon: LucideIcon
  label: string
  disabled?: boolean
  onClick: () => void
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
          >
            <Icon className="h-4 w-4" />
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}

function ShortcutsPopover() {
  const t = useT()
  return (
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9"
              aria-label={t('Keyboard shortcuts')}
            >
              <Keyboard className="h-4 w-4" />
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent className="text-[12px]">
          {t('Keyboard shortcuts')}
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="start" className="w-80 p-0">
        <p className="border-b border-border px-4 py-2.5 text-[13px] font-medium">
          {t('Keyboard shortcuts')}
        </p>
        <ul className="space-y-1.5 px-4 py-3">
          {SHORTCUTS.map((shortcut) => (
            <li
              key={shortcut.label}
              className="flex items-center justify-between gap-3 text-[12px]"
            >
              <span className="text-muted-foreground">{t(shortcut.label)}</span>
              <Keys keys={shortcut.keys} />
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  )
}

function Keys({ keys }: { keys: string[] }): ReactNode {
  return (
    <span className="flex shrink-0 items-center gap-1" dir="ltr">
      {keys.map((key) => (
        <kbd
          key={key}
          className="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] text-foreground"
        >
          {key}
        </kbd>
      ))}
    </span>
  )
}
