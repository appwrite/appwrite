import { z } from 'zod'
import { ImageFormat, ImageGravity } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

/** Matches storage inspector: column × DPR, capped (~720–1600). Frozen per mount in the panel. */
export function getStorageInspectorPreviewBaseWidthPx(): number {
  if (typeof window === 'undefined') return 960
  return Math.min(1600, Math.max(720, Math.round(540 * window.devicePixelRatio)))
}

export function getStorageInspectorPreviewWidthFromBasePx(
  baseWidthPx: number,
  originalSizeBytes?: number,
): number {
  const bytes = originalSizeBytes ?? 0
  if (bytes > 0 && bytes < 12 * 1024) {
    return Math.min(baseWidthPx, 320)
  }
  if (bytes > 0 && bytes < 96 * 1024) {
    return Math.min(baseWidthPx, 640)
  }
  return baseWidthPx
}

/**
 * Width passed to `getFilePreview` in the file inspector and transform wizard default state
 * so the same URL hits the browser cache when opening the wizard after viewing a file.
 */
export function getStorageInspectorPreviewRequestWidthPx(
  originalSizeBytes?: number,
): number {
  return getStorageInspectorPreviewWidthFromBasePx(
    getStorageInspectorPreviewBaseWidthPx(),
    originalSizeBytes,
  )
}

export type StorageInspectorPreviewDefaults = {
  /** `Models.File.sizeOriginal` - caps preview width for tiny files (same as inspector). */
  originalSizeBytes?: number
  /**
   * Exact width the inspector used for its preview; when opening the wizard from the panel,
   * pass this so the default preview URL matches the cached image (base width is fixed per mount).
   */
  initialPreviewRequestWidthPx?: number
}

export function buildAdminStorageInspectorPreviewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
  options: StorageInspectorPreviewDefaults & { preferAvif: boolean },
): string {
  const width =
    options.initialPreviewRequestWidthPx != null
      ? Math.min(4000, Math.max(1, Math.round(options.initialPreviewRequestWidthPx)))
      : getStorageInspectorPreviewRequestWidthPx(options.originalSizeBytes)
  const raw = sdk.forProject(projectId).storage.getFilePreview({
    bucketId,
    fileId,
    width,
    output: options.preferAvif ? ImageFormat.Avif : undefined,
  })
  return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
}

export const PREVIEW_GRAVITY_VALUES = Object.values(
  ImageGravity,
) as ImageGravity[]

/** 3×3 spatial layout for crop gravity (top → bottom, left → right). */
export const TRANSFORM_IMAGE_GRAVITY_GRID_ROWS: readonly [
  readonly [ImageGravity, ImageGravity, ImageGravity],
  readonly [ImageGravity, ImageGravity, ImageGravity],
  readonly [ImageGravity, ImageGravity, ImageGravity],
] = [
  [ImageGravity.Topleft, ImageGravity.Top, ImageGravity.Topright],
  [ImageGravity.Left, ImageGravity.Center, ImageGravity.Right],
  [ImageGravity.Bottomleft, ImageGravity.Bottom, ImageGravity.Bottomright],
] as const

export type ImageTransformState = {
  /** null = omit width from preview (server default) */
  width: number | null
  /** null = omit height (aspect from width / original) */
  height: number | null
  gravity: ImageGravity
  quality: number
  borderWidth: number
  /** Hex without leading # */
  borderColor: string
  borderRadius: number
  /** 0–1 */
  opacity: number
  /** Degrees, -360–360 */
  rotation: number
  /** Hex without leading #; used with transparent PNG output */
  background: string
  /** null = omit output (original format) */
  output: ImageFormat | null
}

const widthNullable = z.union([
  z.number().min(64).max(4000),
  z.null(),
])
const heightNullable = z.union([
  z.number().min(1).max(4000),
  z.null(),
])

const transformJsonSchema = z
  .object({
    width: widthNullable.optional(),
    height: heightNullable.optional(),
    gravity: z.nativeEnum(ImageGravity).optional(),
    quality: z.number().min(0).max(100).optional(),
    borderWidth: z.number().min(0).max(100).optional(),
    borderColor: z.string().max(12).optional(),
    borderRadius: z.number().min(0).max(4000).optional(),
    opacity: z.number().min(0).max(1).optional(),
    rotation: z.number().min(-360).max(360).optional(),
    background: z.string().max(12).optional(),
    output: z.nativeEnum(ImageFormat).nullable().optional(),
  })

/** Maps Appwrite image gravity to CSS `object-position` for object-cover previews */
export function gravityToObjectPosition(g: ImageGravity): string {
  const key = g as unknown as string
  const map: Record<string, string> = {
    center: '50% 50%',
    'top-left': '0% 0%',
    top: '50% 0%',
    'top-right': '100% 0%',
    left: '0% 50%',
    right: '100% 50%',
    'bottom-left': '0% 100%',
    bottom: '50% 100%',
    'bottom-right': '100% 100%',
  }
  return map[key] ?? '50% 50%'
}

/**
 * Logical output size (matches wizard resize math) and on-canvas display size
 * after uniform downscale to fit the preview area.
 */
export function computeImageTransformDisplayLayout(
  nat: { w: number; h: number },
  state: ImageTransformState,
  maxDisplayW = 1280,
  maxDisplayH = 720,
): {
  logicalW: number
  logicalH: number
  displayW: number
  displayH: number
  /** Axis-aligned bounds of the display rect after rotation (for layout / no clip) */
  aabbW: number
  aabbH: number
} {
  const logicalW = Math.max(64, state.width ?? Math.min(480, nat.w))
  const implicitH = Math.round(
    (logicalW * nat.h) / Math.max(1, nat.w),
  )
  const logicalH = Math.max(1, state.height ?? implicitH)
  const fit = Math.min(1, maxDisplayW / logicalW, maxDisplayH / logicalH)
  let displayW = logicalW * fit
  let displayH = logicalH * fit

  const rad = (state.rotation * Math.PI) / 180
  const c = Math.abs(Math.cos(rad))
  const s = Math.abs(Math.sin(rad))
  let aabbW = displayW * c + displayH * s
  let aabbH = displayW * s + displayH * c
  if (aabbW > 0 && aabbH > 0) {
    const fitR = Math.min(1, maxDisplayW / aabbW, maxDisplayH / aabbH)
    displayW *= fitR
    displayH *= fitR
    aabbW *= fitR
    aabbH *= fitR
  }

  return {
    logicalW,
    logicalH,
    displayW,
    displayH,
    aabbW,
    aabbH,
  }
}

export function defaultImageTransformState(
  opts: { preferAvif: boolean } & StorageInspectorPreviewDefaults,
): ImageTransformState {
  const width =
    opts.initialPreviewRequestWidthPx != null
      ? Math.min(4000, Math.max(1, Math.round(opts.initialPreviewRequestWidthPx)))
      : getStorageInspectorPreviewRequestWidthPx(opts.originalSizeBytes)
  return {
    width,
    height: null,
    gravity: ImageGravity.Center,
    quality: 100,
    borderWidth: 0,
    borderColor: '',
    borderRadius: 0,
    opacity: 1,
    rotation: 0,
    background: '',
    output: opts.preferAvif ? ImageFormat.Avif : null,
  }
}

export type ImageTransformPreset = {
  id: string
  label: string
  patch: Partial<ImageTransformState>
}

/** One-click output sizes for the transform wizard (patch applied on top of defaults). */
export const IMAGE_TRANSFORM_PRESETS: readonly ImageTransformPreset[] = [
  {
    id: 'avatar-128',
    label: 'Profile avatar · 128 × 128',
    patch: { width: 128, height: 128 },
  },
  {
    id: 'thumb-256',
    label: 'Gallery thumbnail · 256 px wide',
    patch: { width: 256, height: null },
  },
  {
    id: 'card-640',
    label: 'Article / card · 640 px wide',
    patch: { width: 640, height: null },
  },
  {
    id: 'og-1200',
    label: 'Social preview · 1200 × 630',
    patch: { width: 1200, height: 630 },
  },
  {
    id: 'hd-1280',
    label: 'HD · 1280 px wide',
    patch: { width: 1280, height: null },
  },
  {
    id: 'full-hd-1920',
    label: 'Full HD · 1920 px wide',
    patch: { width: 1920, height: null },
  },
]

/**
 * Applies a built-in preset: fields not listed in the patch reset to
 * {@link defaultImageTransformState} (same as saved JSON presets).
 */
export function applyImageTransformPreset(
  preset: ImageTransformPreset,
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): ImageTransformState {
  return {
    ...defaultImageTransformState({ preferAvif, ...previewDefaults }),
    ...preset.patch,
  }
}

export function resetImageTransformSizeSection(
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): Pick<ImageTransformState, 'width' | 'height' | 'gravity'> {
  const d = defaultImageTransformState({ preferAvif, ...previewDefaults })
  return { width: d.width, height: d.height, gravity: d.gravity }
}

export function resetImageTransformQualitySection(
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): Pick<ImageTransformState, 'quality' | 'output'> {
  const d = defaultImageTransformState({ preferAvif, ...previewDefaults })
  return { quality: d.quality, output: d.output }
}

export function resetImageTransformStyleSection(
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): Pick<
  ImageTransformState,
  | 'opacity'
  | 'rotation'
  | 'borderWidth'
  | 'borderColor'
  | 'borderRadius'
  | 'background'
> {
  const d = defaultImageTransformState({ preferAvif, ...previewDefaults })
  return {
    opacity: d.opacity,
    rotation: d.rotation,
    borderWidth: d.borderWidth,
    borderColor: d.borderColor,
    borderRadius: d.borderRadius,
    background: d.background,
  }
}

export function isImageTransformSizeSectionDirty(
  s: ImageTransformState,
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): boolean {
  const d = resetImageTransformSizeSection(preferAvif, previewDefaults)
  return (
    s.width !== d.width || s.height !== d.height || s.gravity !== d.gravity
  )
}

export function isImageTransformQualitySectionDirty(
  s: ImageTransformState,
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): boolean {
  const d = resetImageTransformQualitySection(preferAvif, previewDefaults)
  return s.quality !== d.quality || s.output !== d.output
}

export function isImageTransformStyleSectionDirty(
  s: ImageTransformState,
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
): boolean {
  const d = resetImageTransformStyleSection(preferAvif, previewDefaults)
  return (
    s.opacity !== d.opacity ||
    s.rotation !== d.rotation ||
    s.borderWidth !== d.borderWidth ||
    s.borderColor !== d.borderColor ||
    s.borderRadius !== d.borderRadius ||
    s.background !== d.background
  )
}

export function imageTransformStatesEqual(
  a: ImageTransformState,
  b: ImageTransformState,
): boolean {
  return (
    a.width === b.width &&
    a.height === b.height &&
    a.gravity === b.gravity &&
    a.quality === b.quality &&
    a.borderWidth === b.borderWidth &&
    a.borderColor === b.borderColor &&
    a.borderRadius === b.borderRadius &&
    a.opacity === b.opacity &&
    a.rotation === b.rotation &&
    a.background === b.background &&
    a.output === b.output
  )
}

/**
 * Parses saved preset JSON and returns full transform state: starts from
 * {@link defaultImageTransformState}, then applies only keys present in the JSON
 * (omitted keys stay at defaults).
 */
export function mergeJsonIntoTransformState(
  raw: string,
  preferAvif: boolean,
  previewDefaults?: StorageInspectorPreviewDefaults,
):
  | { ok: true; state: ImageTransformState }
  | { ok: false; error: string } {
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return { ok: false, error: 'Invalid JSON' }
  }
  if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
    const o = parsed as Record<string, unknown>
    if (o.height === 0) o.height = null
    if (o.width === 0) o.width = null
  }
  const r = transformJsonSchema.safeParse(parsed)
  if (!r.success) {
    return {
      ok: false,
      error: r.error.issues.map((i) => i.message).join(', ') || 'Invalid parameters',
    }
  }
  const p = r.data
  const next: ImageTransformState = {
    ...defaultImageTransformState({ preferAvif, ...previewDefaults }),
  }
  if (p.width !== undefined) {
    next.width =
      p.width === null ? null : Math.min(4000, Math.max(64, Math.round(p.width)))
  }
  if (p.height !== undefined) {
    next.height =
      p.height === null ? null : Math.min(4000, Math.max(1, Math.round(p.height)))
  }
  if (p.gravity !== undefined) next.gravity = p.gravity
  if (p.quality !== undefined) next.quality = Math.round(p.quality)
  if (p.borderWidth !== undefined) next.borderWidth = Math.round(p.borderWidth)
  if (p.borderColor !== undefined) next.borderColor = p.borderColor.replace(/^#/, '')
  if (p.borderRadius !== undefined) next.borderRadius = Math.round(p.borderRadius)
  if (p.opacity !== undefined) next.opacity = p.opacity
  if (p.rotation !== undefined) next.rotation = Math.round(p.rotation)
  if (p.background !== undefined) next.background = p.background.replace(/^#/, '')
  if (p.output !== undefined) next.output = p.output
  return { ok: true, state: next }
}

export function transformStateToJsonSlice(state: ImageTransformState): string {
  const o: Record<string, unknown> = {
    width: state.width,
    height: state.height,
    gravity: state.gravity,
    quality: state.quality,
    borderWidth: state.borderWidth,
    borderColor: state.borderColor,
    borderRadius: state.borderRadius,
    opacity: state.opacity,
    rotation: state.rotation,
    background: state.background,
    output: state.output,
  }
  return JSON.stringify(o, null, 2)
}

/** Same fields as {@link transformStateToJsonSlice}, minified for prefs storage. */
export function transformStateToJsonCompact(state: ImageTransformState): string {
  const o: Record<string, unknown> = {
    width: state.width,
    height: state.height,
    gravity: state.gravity,
    quality: state.quality,
    borderWidth: state.borderWidth,
    borderColor: state.borderColor,
    borderRadius: state.borderRadius,
    opacity: state.opacity,
    rotation: state.rotation,
    background: state.background,
    output: state.output,
  }
  return JSON.stringify(o)
}

type PreviewArg = {
  bucketId: string
  fileId: string
  width?: number
  height?: number
  gravity?: ImageGravity
  quality?: number
  borderWidth?: number
  borderColor?: string
  borderRadius?: number
  opacity?: number
  rotation?: number
  background?: string
  output?: ImageFormat
}

export function buildGetFilePreviewArgs(
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
): PreviewArg {
  const args: PreviewArg = { bucketId, fileId }
  if (s.width !== null && s.width > 0) {
    args.width = Math.min(4000, Math.max(1, Math.round(s.width)))
  }
  if (s.height !== null && s.height > 0) {
    args.height = Math.min(4000, Math.max(1, Math.round(s.height)))
  }
  if (s.gravity !== ImageGravity.Center) {
    args.gravity = s.gravity
  }
  if (s.quality > 0 && s.quality < 100) {
    args.quality = Math.round(s.quality)
  }
  if (s.borderWidth > 0) args.borderWidth = Math.min(100, Math.round(s.borderWidth))
  if (s.borderColor.trim().length > 0) {
    args.borderColor = s.borderColor.replace(/^#/, '').slice(0, 12)
  }
  if (s.borderRadius > 0) {
    args.borderRadius = Math.min(4000, Math.round(s.borderRadius))
  }
  if (s.opacity < 1) args.opacity = Math.max(0, Math.min(1, s.opacity))
  if (s.rotation !== 0) {
    args.rotation = Math.max(-360, Math.min(360, Math.round(s.rotation)))
  }
  if (s.background.trim().length > 0) {
    args.background = s.background.replace(/^#/, '').slice(0, 12)
  }
  if (s.output) args.output = s.output
  return args
}

/**
 * Preview args for the transform **design canvas** only: same crop/transform as `s`
 * but **no** `rotation` param. Rotation is applied with CSS on the same wrapper as the
 * local file, so `overflow-hidden` + `border-radius` clip correctly and match the local layer.
 */
export function buildGetFilePreviewArgsForDesignCanvas(
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
): PreviewArg {
  return buildGetFilePreviewArgs(bucketId, fileId, { ...s, rotation: 0 })
}

export function buildAdminDesignCanvasPreviewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
): string {
  const args = buildGetFilePreviewArgsForDesignCanvas(bucketId, fileId, s)
  const raw = sdk.forProject(projectId).storage.getFilePreview(args as never)
  return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
}

/** Project preview URL for sharing / client apps (no `mode=admin`). */
export function buildFilePreviewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
): string {
  const args = buildGetFilePreviewArgs(bucketId, fileId, s)
  return sdk.forProject(projectId).storage.getFilePreview(args as never)
}

export function buildAdminPreviewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
): string {
  const raw = buildFilePreviewUrl(projectId, bucketId, fileId, s)
  return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
}

/** Raw file view (no transform params). Same auth as preview; stable while editing transforms. */
export function buildAdminFileViewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
): string {
  const raw = sdk
    .forProject(projectId)
    .storage.getFileView({ bucketId, fileId })
  return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
}

/**
 * Preview URL with **no** resize/format/gravity args - original image bytes as served by Storage.
 * Use for “Original” compare so it never matches the transformed design-canvas preview URL.
 */
export function buildAdminUntransformedPreviewUrl(
  projectId: string,
  bucketId: string,
  fileId: string,
): string {
  const raw = sdk
    .forProject(projectId)
    .storage.getFilePreview({ bucketId, fileId })
  return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
}

export const OUTPUT_FORMAT_LABELS: { value: ImageFormat; label: string }[] = [
  { value: ImageFormat.Jpeg, label: 'JPEG' },
  { value: ImageFormat.Png, label: 'PNG' },
  { value: ImageFormat.Webp, label: 'WebP' },
  { value: ImageFormat.Avif, label: 'AVIF' },
  { value: ImageFormat.Gif, label: 'GIF' },
  { value: ImageFormat.Heic, label: 'HEIC' },
]
