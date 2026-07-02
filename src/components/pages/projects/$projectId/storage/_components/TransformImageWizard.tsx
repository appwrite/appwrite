import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { flushSync } from 'react-dom'
import { ImageFormat, ImageGravity } from '@appwrite.io/console'
import type { LucideIcon } from 'lucide-react'
import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  Check,
  Copy,
  Download,
  CornerDownLeft,
  CornerDownRight,
  CornerUpLeft,
  CornerUpRight,
  Crosshair,
  ExternalLink,
  Loader2,
  Maximize2,
  Redo2,
  RotateCcw,
  Undo2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { toast } from 'sonner'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Slider } from '@/components/ui/slider'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { formatBytes } from '@/lib/utils/mock-data'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import {
  buildAdminFileViewUrl,
  buildAdminPreviewUrl,
  buildAdminDesignCanvasPreviewUrl,
  buildAdminUntransformedPreviewUrl,
  buildFilePreviewUrl,
  defaultImageTransformState,
  imageTransformStatesEqual,
  isImageTransformSizeSectionDirty,
  isImageTransformQualitySectionDirty,
  isImageTransformStyleSectionDirty,
  OUTPUT_FORMAT_LABELS,
  resetImageTransformQualitySection,
  resetImageTransformSizeSection,
  resetImageTransformStyleSection,
  TRANSFORM_IMAGE_GRAVITY_GRID_ROWS,
  type ImageTransformState,
  type StorageInspectorPreviewDefaults,
} from './transform-image-wizard-state'
import {
  TRANSFORM_IMAGE_CODE_SDK_OPTIONS,
  buildTransformImageCodeSnippet,
  type TransformImageCodeSdkId,
} from './transform-image-wizard-snippets'
import { TransformImageDesignOverlay } from './TransformImageDesignOverlay'
import { TransformImagePresetsPopover } from './TransformImagePresetsPopover'
import { useT } from '@/lib/i18n/translate'
import { ICON_NO_RTL_FLIP_CLASS } from '@/lib/layout/force-ltr'

type TransformWizardCanvasMode = 'edit' | 'compare'

const GRAVITY_PICKER_ICON: Record<ImageGravity, LucideIcon> = {
  [ImageGravity.Topleft]: CornerUpLeft,
  [ImageGravity.Top]: ArrowUp,
  [ImageGravity.Topright]: CornerUpRight,
  [ImageGravity.Left]: ArrowLeft,
  [ImageGravity.Center]: Crosshair,
  [ImageGravity.Right]: ArrowRight,
  [ImageGravity.Bottomleft]: CornerDownLeft,
  [ImageGravity.Bottom]: ArrowDown,
  [ImageGravity.Bottomright]: CornerDownRight,
}

export type TransformImageWizardProps = {
  open: boolean
  onClose: () => void
  projectId: string
  bucketId: string
  fileId: string
  fileName: string
  preferAvif: boolean
  /** Original file size in bytes (`Models.File.sizeOriginal`) for preview vs original comparison */
  originalSizeBytes?: number
  /**
   * Width the inspector used for its `getFilePreview` request (frozen base × file-size caps).
   * Keeps the wizard default preview URL identical to the cached inspector image.
   */
  initialPreviewRequestWidthPx?: number
}

function NullablePxInput({
  id,
  value,
  onChange,
  restoreValue,
  min,
  max,
}: {
  id: string
  value: number | null
  onChange: (next: number | null) => void
  restoreValue: number
  min: number
  max: number
}) {
  const t = useT()
  const isNull = value === null
  return (
    <div className="relative">
      <Input
        id={id}
        type="number"
        min={min}
        max={max}
        disabled={isNull}
        placeholder={isNull ? 'NULL' : undefined}
        value={isNull ? '' : value}
        onChange={(e) => {
          const raw = e.target.value
          if (raw === '') {
            onChange(null)
            return
          }
          const n = parseInt(raw, 10)
          if (!Number.isFinite(n)) return
          onChange(Math.min(max, Math.max(min, n)))
        }}
        onKeyDown={(e) => {
          if (isNull) return
          if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return
          e.preventDefault()
          const cur = value ?? restoreValue
          const step = e.altKey ? 20 : e.shiftKey ? 10 : 1
          const delta = e.key === 'ArrowUp' ? step : -step
          onChange(Math.min(max, Math.max(min, cur + delta)))
        }}
        className={cn(
          'h-9 pe-24 text-[13px]',
          isNull && 'cursor-not-allowed opacity-50',
        )}
      />
      <div className="pointer-events-none absolute inset-y-0 end-2 flex items-center">
        <div className="pointer-events-auto flex items-center gap-1.5">
          <Checkbox
            id={`${id}-null`}
            checked={isNull}
            onCheckedChange={(checked) => {
              onChange(checked === true ? null : restoreValue)
            }}
            onClick={(e) => e.stopPropagation()}
            className="h-4 w-4"
          />
          <label
            htmlFor={`${id}-null`}
            className="cursor-pointer select-none text-[11px] text-muted-foreground"
          >
            {t('Null')}
          </label>
        </div>
      </div>
    </div>
  )
}

function sanitizeHexDigits(raw: string, maxLen: number): string {
  return raw
    .replace(/#/g, '')
    .replace(/[^0-9a-fA-F]/g, '')
    .slice(0, maxLen)
}

/** Stored hex (no `#`) → `#rrggbb` for `<input type="color" />` */
function hexToColorInputValue(raw: string): string {
  const digits = sanitizeHexDigits(raw, 12)
  if (digits.length === 0) return '#808080'
  if (digits.length <= 3) {
    const d = (digits + '000').slice(0, 3)
    const rr = d[0] + d[0]
    const gg = d[1] + d[1]
    const bb = d[2] + d[2]
    return `#${rr}${gg}${bb}`.toLowerCase()
  }
  const six = (digits + '000000').slice(0, 6)
  return `#${six}`.toLowerCase()
}

function pickerToStoredHex(v: string): string {
  return v.replace(/^#/, '').toUpperCase().slice(0, 6)
}

function HexColorField({
  id,
  label,
  value,
  onChange,
  allowClear,
  helperText,
}: {
  id: string
  label: string
  value: string
  onChange: (next: string) => void
  allowClear?: boolean
  helperText?: string
}) {
  const t = useT()
  const pickerValue = useMemo(() => hexToColorInputValue(value), [value])
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <Label
          htmlFor={`${id}-hex`}
          className="text-[12px] text-muted-foreground"
        >
          {label}
        </Label>
        {allowClear && value.trim() !== '' ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7 shrink-0 px-2 text-[11px] text-muted-foreground"
            onClick={() => onChange('')}
          >
            {t('Clear')}
          </Button>
        ) : null}
      </div>
      <div className="flex gap-2">
        <input
          id={`${id}-picker`}
          type="color"
          className={cn(
            'size-9 shrink-0 cursor-pointer overflow-hidden rounded-lg border border-input bg-background p-0 shadow-xs disabled:opacity-50',
            '[&::-webkit-color-swatch-wrapper]:rounded-[inherit] [&::-webkit-color-swatch-wrapper]:p-0',
            '[&::-webkit-color-swatch]:rounded-md [&::-webkit-color-swatch]:border-0',
            '[&::-moz-color-swatch]:rounded-md [&::-moz-color-swatch]:border-0',
          )}
          value={pickerValue}
          onChange={(e) => onChange(pickerToStoredHex(e.target.value))}
          aria-label={`${label} ${t('color picker')}`}
        />
        <Input
          id={`${id}-hex`}
          className="h-9 min-w-0 flex-1 font-mono text-[13px]"
          placeholder="RRGGBB…"
          value={value}
          spellCheck={false}
          onChange={(e) => onChange(sanitizeHexDigits(e.target.value, 12))}
        />
      </div>
      {helperText ? (
        <p className="text-[11px] text-muted-foreground">{helperText}</p>
      ) : null}
    </div>
  )
}

/** Ring size for footer preview metrics (loading + ready); keeps layout stable. */
const FOOTER_PREVIEW_RING_PX = 40

/** Circular ring; `progress` is 0–100 for the arc. Center shows percent only. */
function PreviewReductionRing({
  progress,
  tone,
  children,
  ringSize = 56,
}: {
  progress: number
  tone: 'success' | 'warning' | 'muted'
  children: ReactNode
  /** Default 56; use 40 for compact footer */
  ringSize?: number
}) {
  const size = ringSize
  const stroke = ringSize <= 44 ? 2 : 3
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const pct = Math.min(100, Math.max(0, progress))
  const offset = c * (1 - pct / 100)
  const arcClass =
    tone === 'success'
      ? 'stroke-emerald-500'
      : tone === 'warning'
        ? 'stroke-amber-500'
        : 'stroke-muted-foreground/45'

  return (
    <div
      className="relative shrink-0"
      style={{ width: size, height: size }}
      role="img"
      aria-label={`${Math.round(pct)}%`}
    >
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
        className="-rotate-90"
        aria-hidden
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          className="stroke-border"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          className={cn(
            'transition-[stroke-dashoffset] duration-300 motion-reduce:transition-none',
            arcClass,
          )}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={offset}
        />
      </svg>
      <div className="pointer-events-none absolute inset-0 grid place-items-center p-0">
        {children}
      </div>
    </div>
  )
}

async function fetchPreviewPayloadBytes(
  url: string,
  signal: AbortSignal,
): Promise<number | null> {
  try {
    const headRes = await fetch(url, {
      method: 'HEAD',
      signal,
      credentials: 'include',
      cache: 'no-store',
    })
    if (headRes.ok) {
      const cl = headRes.headers.get('content-length')
      if (cl) {
        const n = parseInt(cl, 10)
        if (Number.isFinite(n) && n > 0) return n
      }
    }
  } catch {
    /* try GET */
  }
  try {
    const res = await fetch(url, {
      method: 'GET',
      signal,
      credentials: 'include',
      cache: 'no-store',
    })
    if (!res.ok) return null
    const blob = await res.blob()
    return blob.size > 0 ? blob.size : null
  } catch {
    return null
  }
}

const PREVIEW_DOWNLOAD_MIME_EXT: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/jpg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/avif': 'avif',
  'image/gif': 'gif',
  'image/heic': 'heic',
  'image/heif': 'heic',
}

function previewDownloadBasename(fileName: string): string {
  const t = fileName.trim()
  const noExt = t.replace(/\.[^/.]+$/, '')
  const raw = noExt || 'image'
  const safe = raw.replace(/[/\\?%*:|"<>]/g, '-').slice(0, 180)
  return safe || 'image'
}

function previewDownloadExtension(
  output: ImageFormat | null,
  mimeType: string,
  sourceFileName: string,
): string {
  const baseMime = mimeType.split(';')[0]?.trim().toLowerCase() ?? ''
  const fromMime = PREVIEW_DOWNLOAD_MIME_EXT[baseMime]
  if (fromMime) return fromMime
  if (output != null) {
    const s = String(output as unknown as string).toLowerCase()
    if (s === 'jpeg' || s === 'jpg') return 'jpg'
    if (/^[a-z0-9]+$/.test(s)) return s
  }
  const m = /\.([a-zA-Z0-9]+)$/.exec(sourceFileName.trim())
  const fromName = m?.[1]?.toLowerCase()
  if (fromName === 'jpeg' || fromName === 'jpg') return 'jpg'
  if (fromName) return fromName.slice(0, 8)
  return 'jpg'
}

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(id)
  }, [value, delayMs])
  return debounced
}

export function TransformImageWizard({
  open,
  onClose,
  projectId,
  bucketId,
  fileId,
  fileName,
  preferAvif,
  originalSizeBytes = 0,
  initialPreviewRequestWidthPx,
}: TransformImageWizardProps) {
  const t = useT()
  const previewDefaults: StorageInspectorPreviewDefaults = useMemo(
    () => ({
      originalSizeBytes,
      initialPreviewRequestWidthPx,
    }),
    [originalSizeBytes, initialPreviewRequestWidthPx],
  )

  const [state, setState] = useState<ImageTransformState>(() =>
    defaultImageTransformState({ preferAvif, ...previewDefaults }),
  )
  const stateRef = useRef(state)
  stateRef.current = state

  const undoStackRef = useRef<ImageTransformState[]>([])
  const redoStackRef = useRef<ImageTransformState[]>([])
  const interactionSnapshotRef = useRef<ImageTransformState | null>(null)
  const [historyTick, setHistoryTick] = useState(0)

  const bumpHistoryUi = useCallback(() => {
    setHistoryTick((t) => t + 1)
  }, [])

  const recordUndoPoint = useCallback(() => {
    const cur = structuredClone(stateRef.current)
    undoStackRef.current = [...undoStackRef.current.slice(-39), cur]
    redoStackRef.current = []
    bumpHistoryUi()
  }, [bumpHistoryUi])

  const onTransformInteractionStart = useCallback(() => {
    interactionSnapshotRef.current = structuredClone(stateRef.current)
  }, [])

  const onTransformInteractionEnd = useCallback(() => {
    const before = interactionSnapshotRef.current
    interactionSnapshotRef.current = null
    if (!before) return
    if (imageTransformStatesEqual(before, stateRef.current)) return
    undoStackRef.current = [...undoStackRef.current.slice(-39), before]
    redoStackRef.current = []
    bumpHistoryUi()
  }, [bumpHistoryUi])

  const undo = useCallback(() => {
    const stack = undoStackRef.current
    if (stack.length === 0) return
    const prev = structuredClone(stack[stack.length - 1])
    const cur = structuredClone(stateRef.current)
    undoStackRef.current = stack.slice(0, -1)
    redoStackRef.current = [...redoStackRef.current, cur]
    flushSync(() => {
      setState(prev)
    })
    bumpHistoryUi()
  }, [bumpHistoryUi])

  const redo = useCallback(() => {
    const stack = redoStackRef.current
    if (stack.length === 0) return
    const next = structuredClone(stack[stack.length - 1])
    const cur = structuredClone(stateRef.current)
    redoStackRef.current = stack.slice(0, -1)
    undoStackRef.current = [...undoStackRef.current, cur]
    flushSync(() => {
      setState(next)
    })
    bumpHistoryUi()
  }, [bumpHistoryUi])

  const [canvasMode, setCanvasMode] =
    useState<TransformWizardCanvasMode>('edit')

  const [mainView, setMainView] = useState<'design' | 'code'>('design')
  const [codeTab, setCodeTab] = useState<TransformImageCodeSdkId>('web')
  const [copiedSdk, setCopiedSdk] = useState(false)
  const [previewDownloadBusy, setPreviewDownloadBusy] = useState(false)
  const [previewBytes, setPreviewBytes] = useState<number | null>(null)
  const [previewBytesLoading, setPreviewBytesLoading] = useState(false)
  const [previewMeasureDone, setPreviewMeasureDone] = useState(false)

  const previewPanZoom = useViewportPanZoom({ maxZoom: 3 })
  const {
    canvasRef,
    pan,
    zoom,
    isDragging,
    bindCanvas,
    zoomIn,
    zoomOut,
    resetView,
    zoomPercentage,
    zoomInDisabled,
    zoomOutDisabled,
  } = previewPanZoom

  const handleCanvasKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLDivElement>) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      const k = e.key
      if (k === '+' || k === '=') {
        if (zoomInDisabled) return
        e.preventDefault()
        zoomIn()
      } else if (k === '-' || k === '_') {
        if (zoomOutDisabled) return
        e.preventDefault()
        zoomOut()
      } else if (k === '0') {
        e.preventDefault()
        resetView()
      }
    },
    [zoomIn, zoomOut, resetView, zoomInDisabled, zoomOutDisabled],
  )

  useEffect(() => {
    if (open) {
      setState(defaultImageTransformState({ preferAvif, ...previewDefaults }))
      undoStackRef.current = []
      redoStackRef.current = []
      setCanvasMode('edit')
      setHistoryTick((t) => t + 1)
      setMainView('design')
      setCodeTab('web')
      resetView()
    }
  }, [open, preferAvif, fileId, previewDefaults, resetView])

  const previewUrl = useMemo(
    () => buildAdminPreviewUrl(projectId, bucketId, fileId, state),
    [projectId, bucketId, fileId, state],
  )

  const shareablePreviewUrl = useMemo(
    () => buildFilePreviewUrl(projectId, bucketId, fileId, state),
    [projectId, bucketId, fileId, state],
  )

  const designCanvasPreviewUrl = useMemo(
    () => buildAdminDesignCanvasPreviewUrl(projectId, bucketId, fileId, state),
    [projectId, bucketId, fileId, state],
  )

  const debouncedPreviewUrl = useDebouncedValue(previewUrl, 240)
  const debouncedDesignCanvasPreviewUrl = useDebouncedValue(
    designCanvasPreviewUrl,
    240,
  )

  const localFileViewUrl = useMemo(
    () => buildAdminFileViewUrl(projectId, bucketId, fileId),
    [projectId, bucketId, fileId],
  )

  const untransformedPreviewUrl = useMemo(
    () => buildAdminUntransformedPreviewUrl(projectId, bucketId, fileId),
    [projectId, bucketId, fileId],
  )

  useEffect(() => {
    if (!open || !debouncedPreviewUrl || originalSizeBytes <= 0) {
      setPreviewBytes(null)
      setPreviewBytesLoading(false)
      setPreviewMeasureDone(false)
      return
    }
    setPreviewMeasureDone(false)
    setPreviewBytesLoading(true)
    const ac = new AbortController()
    void fetchPreviewPayloadBytes(debouncedPreviewUrl, ac.signal)
      .then((n) => {
        if (!ac.signal.aborted) {
          setPreviewBytes(n)
          setPreviewBytesLoading(false)
          setPreviewMeasureDone(true)
        }
      })
      .catch(() => {
        if (!ac.signal.aborted) {
          setPreviewBytes(null)
          setPreviewBytesLoading(false)
          setPreviewMeasureDone(true)
        }
      })
    return () => {
      ac.abort()
    }
  }, [open, debouncedPreviewUrl, originalSizeBytes])
  const resetAllToDefaults = useCallback(() => {
    recordUndoPoint()
    const next = defaultImageTransformState({ preferAvif, ...previewDefaults })
    setState(next)
    toast.message(t('All parameters reset to defaults'))
  }, [preferAvif, previewDefaults, recordUndoPoint, t])

  const resetSizeSection = useCallback(() => {
    recordUndoPoint()
    setState((s) => ({
      ...s,
      ...resetImageTransformSizeSection(preferAvif, previewDefaults),
    }))
    toast.message(t('Size & crop reset'))
  }, [preferAvif, previewDefaults, recordUndoPoint, t])

  const resetQualitySection = useCallback(() => {
    recordUndoPoint()
    setState((s) => ({
      ...s,
      ...resetImageTransformQualitySection(preferAvif, previewDefaults),
    }))
    toast.message(t('Quality & format reset'))
  }, [preferAvif, previewDefaults, recordUndoPoint, t])

  const resetStyleSection = useCallback(() => {
    recordUndoPoint()
    setState((s) => ({
      ...s,
      ...resetImageTransformStyleSection(preferAvif, previewDefaults),
    }))
    toast.message(t('Style & effects reset'))
  }, [preferAvif, previewDefaults, recordUndoPoint, t])

  const copyPreviewUrl = useCallback(() => {
    void navigator.clipboard.writeText(shareablePreviewUrl)
    toast.success(t('Preview URL copied'))
  }, [shareablePreviewUrl, t])

  const openPreviewInNewTab = useCallback(() => {
    window.open(previewUrl, '_blank', 'noopener,noreferrer')
  }, [previewUrl])

  const downloadPreview = useCallback(async () => {
    try {
      const res = await fetch(previewUrl, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
      })
      if (!res.ok) {
        toast.error(`${t('Could not download preview')} (${res.status})`)
        return
      }
      const blob = await res.blob()
      const ext = previewDownloadExtension(state.output, blob.type, fileName)
      const base = previewDownloadBasename(fileName)
      const downloadName = `${base}-preview.${ext}`
      const objectUrl = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = objectUrl
      a.download = downloadName
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(objectUrl)
      toast.success(t('Preview downloaded'))
    } catch (e) {
      toast.error(getErrorMessage(e))
    } finally {
      setPreviewDownloadBusy(false)
    }
  }, [fileName, previewUrl, state.output, t])

  const projectApiEndpoint = getProjectApiEndpoint(projectId)

  const activeCodeSnippet = useMemo(
    () =>
      buildTransformImageCodeSnippet(
        codeTab,
        projectId,
        bucketId,
        fileId,
        state,
        projectApiEndpoint,
      ),
    [codeTab, projectId, bucketId, fileId, state, projectApiEndpoint],
  )

  const copyActiveCode = useCallback(async () => {
    await navigator.clipboard.writeText(activeCodeSnippet)
    setCopiedSdk(true)
    toast.success(t('Copied'))
    window.setTimeout(() => setCopiedSdk(false), 2000)
  }, [activeCodeSnippet, t])

  const handleCodeTabChange = useCallback((v: string) => {
    setCodeTab(v as TransformImageCodeSdkId)
  }, [])

  const codeEditorConfig =
    TRANSFORM_IMAGE_CODE_SDK_OPTIONS.find((o) => o.id === codeTab) ??
    TRANSFORM_IMAGE_CODE_SDK_OPTIONS[0]

  const sizeSectionDirty = useMemo(
    () => isImageTransformSizeSectionDirty(state, preferAvif, previewDefaults),
    [state, preferAvif, previewDefaults],
  )
  const qualitySectionDirty = useMemo(
    () =>
      isImageTransformQualitySectionDirty(state, preferAvif, previewDefaults),
    [state, preferAvif, previewDefaults],
  )
  const styleSectionDirty = useMemo(
    () => isImageTransformStyleSectionDirty(state, preferAvif, previewDefaults),
    [state, preferAvif, previewDefaults],
  )

  const sizeComparison = useMemo(() => {
    if (originalSizeBytes <= 0) return null
    if (previewBytesLoading || !previewMeasureDone)
      return { kind: 'loading' as const }
    if (previewBytes === null) return { kind: 'unavailable' as const }
    const original = originalSizeBytes
    const preview = previewBytes
    const delta = original - preview
    const pctOfOriginal = Math.min(100, Math.round((preview / original) * 100))
    const pctSaved =
      original > 0 ? Math.round((Math.max(0, delta) / original) * 100) : 0
    const pctLarger =
      original > 0 && delta < 0 ? Math.round((-delta / original) * 100) : 0
    return {
      kind: 'ready' as const,
      original,
      preview,
      delta,
      pctOfOriginal,
      pctSaved,
      pctLarger,
    }
  }, [originalSizeBytes, previewBytes, previewBytesLoading, previewMeasureDone])

  void historyTick
  const canUndo = undoStackRef.current.length > 0
  const canRedo = redoStackRef.current.length > 0

  useEffect(() => {
    if (!open || mainView !== 'design') return
    const id = requestAnimationFrame(() => {
      canvasRef.current?.focus({ preventScroll: true })
    })
    return () => cancelAnimationFrame(id)
  }, [open, mainView, fileId, canvasRef])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement | null
      if (
        t?.closest('input, textarea, [contenteditable="true"]') ||
        t?.closest('[role="textbox"]')
      ) {
        return
      }
      const mod = e.metaKey || e.ctrlKey
      if (!mod || e.key.toLowerCase() !== 'z') return
      e.preventDefault()
      if (e.shiftKey) redo()
      else undo()
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [open, undo, redo])

  if (!open) return null

  return (
    <WizardLayout
      title={fileName.trim() || t('Transform image')}
      headerBottom={
        <div className="flex w-full flex-wrap items-center justify-between gap-x-4 gap-y-2 bg-muted/30 px-4 py-2 shadow-none">
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <Tabs
              value={mainView}
              onValueChange={(v) => setMainView(v as 'design' | 'code')}
              className="shadow-none"
            >
              <TabsList className="grid h-9 w-full max-w-xs grid-cols-2 shadow-none">
                <TabsTrigger
                  value="design"
                  className="text-[13px] shadow-none data-[state=active]:shadow-none"
                >
                  {t('Design')}
                </TabsTrigger>
                <TabsTrigger
                  value="code"
                  className="text-[13px] shadow-none data-[state=active]:shadow-none"
                >
                  {t('Code')}
                </TabsTrigger>
              </TabsList>
            </Tabs>
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              disabled={!canUndo}
              onClick={undo}
              aria-label={t('Undo')}
              title={`${t('Undo')} (⌘Z)`}
            >
              <Undo2 className="h-3.5 w-3.5" />
              {t('Undo')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              disabled={!canRedo}
              onClick={redo}
              aria-label={t('Redo')}
              title={`${t('Redo')} (⌘⇧Z)`}
            >
              <Redo2 className="h-3.5 w-3.5" />
              {t('Redo')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              onClick={resetAllToDefaults}
            >
              <RotateCcw className="h-3.5 w-3.5" />
              {t('Reset all')}
            </Button>
            {mainView === 'design' ? (
              <TransformImagePresetsPopover
                preferAvif={preferAvif}
                previewDefaults={previewDefaults}
                projectId={projectId}
                state={state}
                setState={setState}
                recordUndoPoint={recordUndoPoint}
              />
            ) : null}
          </div>
        </div>
      }
      fullscreen
      useSidebar={false}
      constrainWidth={false}
      constrainFooterWidth={false}
      maxWidth=""
      contentPadding={false}
      skipInitialFieldFocus
      contentWrapperClassName="flex-1 min-h-0 overflow-hidden"
      fullscreenInnerClassName="flex h-full min-h-0 flex-1 flex-col px-0 py-0 !max-w-none w-full"
      contentClassName="flex min-h-0 flex-1 flex-col"
      onClose={onClose}
      footer={
        <div className="flex w-full min-w-0 items-center justify-between gap-4">
          <div
            className={cn(
              'flex min-w-0 flex-1 items-center gap-2.5',
              sizeComparison != null && 'min-h-11',
            )}
            aria-busy={sizeComparison?.kind === 'loading'}
          >
            {sizeComparison && sizeComparison.kind === 'loading' && (
              <>
                <PreviewReductionRing
                  progress={0}
                  tone="muted"
                  ringSize={FOOTER_PREVIEW_RING_PX}
                >
                  <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                </PreviewReductionRing>
                <p className="min-w-0 flex-1 truncate text-[11px] text-muted-foreground">
                  {t('Measuring preview size…')}
                </p>
              </>
            )}
            {sizeComparison && sizeComparison.kind === 'unavailable' && (
              <>
                <PreviewReductionRing
                  progress={0}
                  tone="muted"
                  ringSize={FOOTER_PREVIEW_RING_PX}
                >
                  <span className="text-[9px] font-normal text-muted-foreground">
                    -
                  </span>
                </PreviewReductionRing>
                <p className="min-w-0 flex-1 truncate text-[11px] text-muted-foreground">
                  {t('Preview size unavailable · original')}{' '}
                  <span className="font-medium tabular-nums text-foreground">
                    {formatBytes(originalSizeBytes)}
                  </span>
                </p>
              </>
            )}
            {sizeComparison && sizeComparison.kind === 'ready' && (
              <>
                {sizeComparison.delta > 0 ? (
                  <PreviewReductionRing
                    progress={sizeComparison.pctSaved}
                    tone="success"
                    ringSize={FOOTER_PREVIEW_RING_PX}
                  >
                    <span className="inline-flex items-baseline justify-center gap-px tabular-nums leading-none text-[10px] font-medium text-foreground">
                      {sizeComparison.pctSaved}
                      <span className="text-[8px] font-normal text-muted-foreground">
                        %
                      </span>
                    </span>
                  </PreviewReductionRing>
                ) : sizeComparison.delta < 0 ? (
                  <PreviewReductionRing
                    progress={Math.min(100, sizeComparison.pctOfOriginal)}
                    tone="warning"
                    ringSize={FOOTER_PREVIEW_RING_PX}
                  >
                    <span className="inline-flex items-baseline justify-center gap-px tabular-nums leading-none text-[10px] font-medium text-amber-700 dark:text-amber-400">
                      <span className="text-[8px] font-normal">+</span>
                      {sizeComparison.pctLarger}
                      <span className="text-[8px] font-normal text-muted-foreground">
                        %
                      </span>
                    </span>
                  </PreviewReductionRing>
                ) : (
                  <PreviewReductionRing
                    progress={Math.min(100, sizeComparison.pctOfOriginal)}
                    tone="muted"
                    ringSize={FOOTER_PREVIEW_RING_PX}
                  >
                    <span className="inline-flex items-baseline justify-center gap-px tabular-nums leading-none text-[10px] font-medium text-muted-foreground">
                      {sizeComparison.pctOfOriginal}
                      <span className="text-[8px] font-normal text-muted-foreground/80">
                        %
                      </span>
                    </span>
                  </PreviewReductionRing>
                )}
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[11px] leading-tight text-foreground">
                    <span className="font-medium tabular-nums">
                      {formatBytes(sizeComparison.original)}
                    </span>
                    <ArrowRight className="mx-1 inline h-3 w-3 shrink-0 align-text-bottom text-muted-foreground" />
                    <span className="font-medium tabular-nums">
                      {formatBytes(sizeComparison.preview)}
                    </span>
                    <span className="text-muted-foreground">
                      {' '}
                      · {sizeComparison.pctOfOriginal}% {t('of original')}
                    </span>
                    {sizeComparison.delta < 0 ? (
                      <>
                        {' '}
                        <Badge
                          variant="warning"
                          className="ms-0.5 inline h-4 align-middle px-1 py-0 text-[9px]"
                        >
                          {t('Larger payload')}
                        </Badge>
                      </>
                    ) : null}
                  </p>
                </div>
              </>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={previewDownloadBusy}
              onClick={() => void downloadPreview()}
            >
              {previewDownloadBusy ? (
                <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
              ) : (
                <Download className="me-1.5 h-3.5 w-3.5" />
              )}
              {t('Download')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={copyPreviewUrl}
            >
              <Copy className="me-1.5 h-3.5 w-3.5" />
              {t('Copy URL')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={openPreviewInNewTab}
            >
              <ExternalLink className="me-1.5 h-3.5 w-3.5" />
              {t('Open')}
            </Button>
          </div>
        </div>
      }
    >
      <div className="flex min-h-0 flex-1 flex-col md:flex-row">
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {mainView === 'design' ? (
            <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
              <div className="relative z-[1] flex min-h-0 flex-1 flex-col">
                <div className="relative min-h-0 flex-1">
                  <div
                    ref={canvasRef}
                    {...bindCanvas}
                    tabIndex={-1}
                    role="application"
                    aria-label={t(
                      'Pan and zoom preview. Use the scroll wheel to zoom, drag to pan. Keys: + or = zoom in, - zoom out, 0 reset.',
                    )}
                    onKeyDown={handleCanvasKeyDown}
                    className={cn(
                      'absolute inset-0 overflow-hidden select-none outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                      isDragging ? 'cursor-grabbing' : 'cursor-grab',
                    )}
                  >
                    <div
                      className="relative h-full w-full will-change-transform"
                      style={{
                        transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
                        transformOrigin: '0 0',
                      }}
                    >
                      <SchemaBlueprintMat />
                      <div className="relative z-10 flex h-full w-full items-center justify-center px-4 py-10">
                        <TransformImageDesignOverlay
                          zoom={zoom}
                          state={state}
                          setState={setState}
                          localImgSrc={localFileViewUrl}
                          originalImgSrc={untransformedPreviewUrl}
                          serverImgSrc={debouncedDesignCanvasPreviewUrl}
                          imgAlt={fileName}
                          canvasMode={canvasMode}
                          onTransformInteractionStart={
                            onTransformInteractionStart
                          }
                          onTransformInteractionEnd={onTransformInteractionEnd}
                        />
                      </div>
                    </div>
                  </div>
                  <div className="pointer-events-none absolute inset-x-4 top-4 z-20 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <div className="pointer-events-auto flex shrink-0 items-center gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                        onClick={zoomIn}
                        disabled={zoomInDisabled}
                        aria-label={t('Zoom in')}
                      >
                        <ZoomIn className="h-4 w-4" />
                      </Button>
                      <div className="flex h-8 min-w-[64px] items-center justify-center rounded-md border border-border bg-card/95 px-3 backdrop-blur-sm">
                        <span className="text-[12px] font-medium text-foreground">
                          {zoomPercentage}%
                        </span>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                        onClick={zoomOut}
                        disabled={zoomOutDisabled}
                        aria-label={t('Zoom out')}
                      >
                        <ZoomOut className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                        onClick={resetView}
                        aria-label={t('Reset pan and zoom')}
                      >
                        <Maximize2 className="h-4 w-4" />
                      </Button>
                    </div>
                    <div className="pointer-events-auto shrink-0">
                      <ToggleGroup
                        type="single"
                        value={canvasMode}
                        onValueChange={(v) => {
                          if (v === 'edit' || v === 'compare') {
                            setCanvasMode(v)
                          }
                        }}
                        variant="outline"
                        size="sm"
                        className="rounded-md border border-border bg-card/95 p-0.5 shadow-none backdrop-blur-sm"
                        aria-label={t('Design canvas mode')}
                      >
                        <ToggleGroupItem
                          value="edit"
                          className="h-8 px-3 text-[12px] data-[state=on]:bg-muted"
                        >
                          {t('Edit')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                          value="compare"
                          className="h-8 px-3 text-[12px] data-[state=on]:bg-muted"
                        >
                          {t('Compare')}
                        </ToggleGroupItem>
                      </ToggleGroup>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
              <div className="relative min-h-0 flex-1">
                <SchemaBlueprintMat />
                <div className="absolute inset-0 overflow-hidden">
                  <CodeEditor
                    key={codeTab}
                    modelPath={codeEditorConfig.modelPath}
                    value={activeCodeSnippet}
                    language={codeEditorConfig.language}
                    readOnly
                    minimap={false}
                    height="100%"
                    className="h-full min-h-0 rounded-none border-0"
                  />
                </div>
                <div className="pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-end p-3 sm:p-4">
                  <div className="pointer-events-auto flex max-w-full flex-wrap items-center justify-end gap-2">
                    <Select value={codeTab} onValueChange={handleCodeTabChange}>
                      <SelectTrigger
                        className="h-8 w-[min(200px,calc(100vw-8rem))] shrink-0 border-border bg-card/95 text-[12px] shadow-none backdrop-blur-sm"
                        aria-label={t('SDK example')}
                      >
                        <SelectValue placeholder="SDK" />
                      </SelectTrigger>
                      <SelectContent
                        position="popper"
                        className="z-[10070] max-h-[min(60dvh,360px)] overflow-y-auto"
                      >
                        {TRANSFORM_IMAGE_CODE_SDK_OPTIONS.map((opt) => (
                          <SelectItem
                            key={opt.id}
                            value={opt.id}
                            className="text-[13px]"
                          >
                            {opt.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 w-8 shrink-0 border-border bg-card/95 p-0 shadow-none backdrop-blur-sm me-2"
                      onClick={copyActiveCode}
                      aria-label={copiedSdk ? t('Copied') : t('Copy code example')}
                    >
                      {copiedSdk ? (
                        <Check className="h-3.5 w-3.5 text-emerald-600" />
                      ) : (
                        <Copy className="h-3.5 w-3.5" />
                      )}
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        <aside className="flex max-h-[min(48dvh,420px)] min-h-0 w-full shrink-0 flex-col border-t border-border bg-background md:max-h-none md:w-[min(420px,100%)] md:border-s md:border-t-0">
          <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
            <Accordion
              type="multiple"
              defaultValue={['size']}
              className="w-full"
            >
              <AccordionItem value="size">
                <AccordionTrigger
                  className={cn(
                    'text-[13px] hover:no-underline [&>span]:min-w-0',
                  )}
                >
                  <span className="flex min-w-0 flex-1 items-center gap-2 pe-1">
                    <span className="min-w-0 flex-1 truncate py-0.5">
                      {t('Size & crop')}
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className={cn(
                        'h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground',
                        !sizeSectionDirty && 'invisible pointer-events-none',
                      )}
                      aria-label={t('Reset size and crop')}
                      title={
                        sizeSectionDirty ? t('Reset size and crop') : undefined
                      }
                      tabIndex={sizeSectionDirty ? 0 : -1}
                      aria-hidden={!sizeSectionDirty}
                      onPointerDown={(e) => {
                        if (!sizeSectionDirty) return
                        e.stopPropagation()
                      }}
                      onClick={(e) => {
                        if (!sizeSectionDirty) return
                        e.preventDefault()
                        e.stopPropagation()
                        resetSizeSection()
                      }}
                    >
                      <RotateCcw className="h-3.5 w-3.5" />
                    </Button>
                  </span>
                </AccordionTrigger>
                <AccordionContent className="space-y-4 px-0.5 pt-1">
                  <div className="space-y-2">
                    <div className="flex justify-between gap-2">
                      <Label
                        htmlFor="transform-width"
                        className="text-[12px] text-muted-foreground"
                      >
                        {t('Width (px)')}
                      </Label>
                      <span className="text-[12px] tabular-nums text-foreground">
                        {state.width === null ? '-' : state.width}
                      </span>
                    </div>
                    {state.width !== null && (
                      <Slider
                        min={64}
                        max={2000}
                        step={1}
                        value={[state.width]}
                        onValueChange={([v]) =>
                          setState((s) => ({
                            ...s,
                            width: Math.round(v ?? s.width ?? 480),
                          }))
                        }
                        className="py-1"
                      />
                    )}
                    <NullablePxInput
                      id="transform-width"
                      value={state.width}
                      onChange={(width) => setState((s) => ({ ...s, width }))}
                      restoreValue={480}
                      min={64}
                      max={4000}
                    />
                  </div>
                  <div className="space-y-2">
                    <div className="flex justify-between gap-2">
                      <Label
                        htmlFor="transform-height"
                        className="text-[12px] text-muted-foreground"
                      >
                        {t('Height (px)')}
                      </Label>
                      <span className="text-[12px] tabular-nums text-foreground">
                        {state.height === null ? '-' : state.height}
                      </span>
                    </div>
                    {state.height !== null && (
                      <Slider
                        min={1}
                        max={2000}
                        step={1}
                        value={[state.height]}
                        onValueChange={([v]) =>
                          setState((s) => ({
                            ...s,
                            height: Math.round(v ?? s.height ?? 480),
                          }))
                        }
                        className="py-1"
                      />
                    )}
                    <NullablePxInput
                      id="transform-height"
                      value={state.height}
                      onChange={(height) => setState((s) => ({ ...s, height }))}
                      restoreValue={480}
                      min={1}
                      max={4000}
                    />
                  </div>
                  <p className="text-[11px] leading-snug text-muted-foreground">
                    {t(
                      'Null width or height omits that dimension from the preview URL so the API derives sizing (often from the other side). A number is an explicit pixel target.',
                    )}
                  </p>
                  <div className="min-w-0 space-y-2">
                    <div className="flex items-center gap-2">
                      <Label className="shrink-0 text-[12px] text-muted-foreground">
                        {t('Gravity')}
                      </Label>
                      <span
                        className="ms-auto min-w-0 shrink-0 whitespace-nowrap text-end font-mono text-[10px] leading-none tracking-wide text-muted-foreground"
                        title={state.gravity}
                        aria-live="polite"
                      >
                        {state.gravity}
                      </span>
                    </div>
                    <div
                      className="w-full max-w-[11.5rem] rounded-lg border border-border bg-muted/25 p-px shadow-inner dark:bg-muted/15"
                      role="group"
                      aria-label={t('Crop gravity')}
                    >
                      <div className="grid grid-cols-3 gap-px bg-border/70">
                        {TRANSFORM_IMAGE_GRAVITY_GRID_ROWS.flatMap((row, ri) =>
                          row.map((g, ci) => {
                            const Icon = GRAVITY_PICKER_ICON[g]
                            const selected = state.gravity === g
                            const i = ri * 3 + ci
                            const cornerBtn =
                              i === 0
                                ? 'rounded-ss-[calc(var(--radius-lg)_-_1px)]'
                                : i === 2
                                  ? 'rounded-se-[calc(var(--radius-lg)_-_1px)]'
                                  : i === 6
                                    ? 'rounded-es-[calc(var(--radius-lg)_-_1px)]'
                                    : i === 8
                                      ? 'rounded-ee-[calc(var(--radius-lg)_-_1px)]'
                                      : ''
                            return (
                              <button
                                key={g}
                                type="button"
                                className={cn(
                                  'relative flex aspect-square min-h-10 w-full min-w-0 items-center justify-center',
                                  'bg-background/90 transition-colors duration-150',
                                  'hover:bg-muted/70 hover:text-foreground',
                                  'focus-visible:z-[2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                  selected
                                    ? 'z-[1] bg-primary/[0.12] text-primary ring-1 ring-inset ring-primary/40 dark:bg-primary/18 dark:ring-primary/55'
                                    : 'text-muted-foreground',
                                  cornerBtn,
                                )}
                                aria-label={g}
                                aria-pressed={selected}
                                title={g}
                                onClick={() =>
                                  setState((s) => ({ ...s, gravity: g }))
                                }
                              >
                                <Icon
                                  className={cn(
                                    'size-[15px] shrink-0',
                                    ICON_NO_RTL_FLIP_CLASS,
                                    selected ? 'opacity-100' : 'opacity-[0.72]',
                                  )}
                                  strokeWidth={selected ? 2.25 : 1.85}
                                  aria-hidden
                                />
                              </button>
                            )
                          }),
                        )}
                      </div>
                    </div>
                  </div>
                </AccordionContent>
              </AccordionItem>

              <AccordionItem value="quality">
                <AccordionTrigger
                  className={cn(
                    'text-[13px] hover:no-underline [&>span]:min-w-0',
                  )}
                >
                  <span className="flex min-w-0 flex-1 items-center gap-2 pe-1">
                    <span className="min-w-0 flex-1 truncate py-0.5">
                      {t('Quality & format')}
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className={cn(
                        'h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground',
                        !qualitySectionDirty && 'invisible pointer-events-none',
                      )}
                      aria-label={t('Reset quality and format')}
                      title={
                        qualitySectionDirty
                          ? t('Reset quality and format')
                          : undefined
                      }
                      tabIndex={qualitySectionDirty ? 0 : -1}
                      aria-hidden={!qualitySectionDirty}
                      onPointerDown={(e) => {
                        if (!qualitySectionDirty) return
                        e.stopPropagation()
                      }}
                      onClick={(e) => {
                        if (!qualitySectionDirty) return
                        e.preventDefault()
                        e.stopPropagation()
                        resetQualitySection()
                      }}
                    >
                      <RotateCcw className="h-3.5 w-3.5" />
                    </Button>
                  </span>
                </AccordionTrigger>
                <AccordionContent className="space-y-4 px-0.5 pt-1">
                  <div className="space-y-2">
                    <div className="flex justify-between gap-2">
                      <Label className="text-[12px] text-muted-foreground">
                        {t('Quality')}
                      </Label>
                      <span className="text-[12px] tabular-nums text-foreground">
                        {state.quality}
                      </span>
                    </div>
                    <Slider
                      min={1}
                      max={100}
                      step={1}
                      value={[state.quality]}
                      onValueChange={([v]) =>
                        setState((s) => ({
                          ...s,
                          quality: Math.round(v ?? s.quality),
                        }))
                      }
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] text-muted-foreground">
                      {t('Output format')}
                    </Label>
                    <Select
                      value={state.output ?? 'original'}
                      onValueChange={(v) =>
                        setState((s) => ({
                          ...s,
                          output: v === 'original' ? null : (v as ImageFormat),
                        }))
                      }
                    >
                      <SelectTrigger className="h-9 text-[13px]">
                        <SelectValue placeholder={t('Original')} />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="original" className="text-[13px]">
                          {t('Original (no conversion)')}
                        </SelectItem>
                        {OUTPUT_FORMAT_LABELS.map(({ value, label }) => (
                          <SelectItem
                            key={value}
                            value={value}
                            className="text-[13px]"
                          >
                            {label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </AccordionContent>
              </AccordionItem>

              <AccordionItem value="style">
                <AccordionTrigger
                  className={cn(
                    'text-[13px] hover:no-underline [&>span]:min-w-0',
                  )}
                >
                  <span className="flex min-w-0 flex-1 items-center gap-2 pe-1">
                    <span className="min-w-0 flex-1 truncate py-0.5">
                      {t('Style & effects')}
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className={cn(
                        'h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground',
                        !styleSectionDirty && 'invisible pointer-events-none',
                      )}
                      aria-label={t('Reset style and effects')}
                      title={
                        styleSectionDirty
                          ? t('Reset style and effects')
                          : undefined
                      }
                      tabIndex={styleSectionDirty ? 0 : -1}
                      aria-hidden={!styleSectionDirty}
                      onPointerDown={(e) => {
                        if (!styleSectionDirty) return
                        e.stopPropagation()
                      }}
                      onClick={(e) => {
                        if (!styleSectionDirty) return
                        e.preventDefault()
                        e.stopPropagation()
                        resetStyleSection()
                      }}
                    >
                      <RotateCcw className="h-3.5 w-3.5" />
                    </Button>
                  </span>
                </AccordionTrigger>
                <AccordionContent className="space-y-4 px-0.5 pt-1">
                  <div className="space-y-2">
                    <div className="flex justify-between gap-2">
                      <Label className="text-[12px] text-muted-foreground">
                        {t('Opacity')}
                      </Label>
                      <span className="text-[12px] tabular-nums text-foreground">
                        {Math.round(state.opacity * 100)}%
                      </span>
                    </div>
                    <Slider
                      min={0}
                      max={100}
                      step={1}
                      value={[Math.round(state.opacity * 100)]}
                      onValueChange={([v]) =>
                        setState((s) => ({
                          ...s,
                          opacity: (v ?? 100) / 100,
                        }))
                      }
                    />
                    <p className="text-[11px] text-muted-foreground">
                      {t(
                        'The preview reflects opacity when the output format supports transparency.',
                      )}
                    </p>
                  </div>
                  <div className="space-y-2">
                    <div className="flex justify-between gap-2">
                      <Label
                        htmlFor="transform-rotation"
                        className="text-[12px] text-muted-foreground"
                      >
                        {t('Rotation (degrees)')}
                      </Label>
                      <span className="text-[12px] tabular-nums text-foreground">
                        {state.rotation}°
                      </span>
                    </div>
                    <Slider
                      min={-360}
                      max={360}
                      step={1}
                      value={[state.rotation]}
                      onValueChange={([v]) =>
                        setState((s) => ({
                          ...s,
                          rotation: Math.round(
                            Math.min(360, Math.max(-360, v ?? s.rotation)),
                          ),
                        }))
                      }
                      className="py-1"
                    />
                    <Input
                      id="transform-rotation"
                      type="number"
                      min={-360}
                      max={360}
                      className="h-9 text-[13px]"
                      value={state.rotation}
                      onChange={(e) => {
                        const v = parseInt(e.target.value, 10)
                        setState((s) => ({
                          ...s,
                          rotation: Number.isFinite(v)
                            ? Math.min(360, Math.max(-360, v))
                            : 0,
                        }))
                      }}
                      onKeyDown={(e) => {
                        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')
                          return
                        e.preventDefault()
                        recordUndoPoint()
                        const step = e.shiftKey ? 15 : 1
                        const delta = e.key === 'ArrowLeft' ? -step : step
                        setState((s) => ({
                          ...s,
                          rotation: Math.min(
                            360,
                            Math.max(-360, s.rotation + delta),
                          ),
                        }))
                      }}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] text-muted-foreground">
                      {t('Border width (px)')}
                    </Label>
                    <Input
                      type="number"
                      min={0}
                      max={100}
                      className="h-9 text-[13px]"
                      value={state.borderWidth || ''}
                      onChange={(e) => {
                        const v = parseInt(e.target.value, 10)
                        setState((s) => ({
                          ...s,
                          borderWidth: Number.isFinite(v)
                            ? Math.min(100, Math.max(0, v))
                            : 0,
                        }))
                      }}
                    />
                  </div>
                  <HexColorField
                    id="transform-border-color"
                    label={t('Border color')}
                    value={state.borderColor}
                    onChange={(borderColor) =>
                      setState((s) => ({ ...s, borderColor }))
                    }
                    allowClear
                    helperText={t('Picker or hex (no #). Up to 12 API chars. Clear to omit.')}
                  />
                  <div className="space-y-2">
                    <Label className="text-[12px] text-muted-foreground">
                      {t('Border radius (px)')}
                    </Label>
                    <Input
                      type="number"
                      min={0}
                      max={4000}
                      className="h-9 text-[13px]"
                      value={state.borderRadius || ''}
                      onChange={(e) => {
                        const v = parseInt(e.target.value, 10)
                        setState((s) => ({
                          ...s,
                          borderRadius: Number.isFinite(v)
                            ? Math.min(4000, Math.max(0, v))
                            : 0,
                        }))
                      }}
                    />
                  </div>
                  <HexColorField
                    id="transform-background"
                    label={t('Background')}
                    value={state.background}
                    onChange={(background) =>
                      setState((s) => ({ ...s, background }))
                    }
                    allowClear
                    helperText={t('Clear when unused (e.g. transparent PNG).')}
                  />
                </AccordionContent>
              </AccordionItem>
            </Accordion>
          </div>
        </aside>
      </div>
    </WizardLayout>
  )
}
