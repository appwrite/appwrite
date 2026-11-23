import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
  type RefObject,
} from 'react'
import type { Dispatch, SetStateAction } from 'react'
import type { ImageFormat } from '@appwrite.io/console'
import { RotateCw } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import {
  computeImageTransformDisplayLayout,
  gravityToObjectPosition,
  OUTPUT_FORMAT_LABELS,
  type ImageTransformState,
} from './transform-image-wizard-state'
import { useT } from '@/lib/i18n/translate'

const CLAMP_W = (w: number) => Math.min(4000, Math.max(64, Math.round(w)))
const CLAMP_H = (h: number) => Math.min(4000, Math.max(1, Math.round(h)))
const CLAMP_R = (r: number) => Math.min(4000, Math.max(0, Math.round(r)))
const CLAMP_ROT = (r: number) =>
  Math.min(360, Math.max(-360, Math.round(r)))

function previewOutputFormatLabel(output: ImageFormat | null): string {
  if (output == null) return 'Original'
  const row = OUTPUT_FORMAT_LABELS.find((r) => r.value === output)
  return row?.label ?? 'Format'
}

type ResizeEdge = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w'

function resizeDeltaForEdge(
  edge: ResizeEdge,
  dx: number,
  dy: number,
): { dw: number; dh: number } {
  switch (edge) {
    case 'e':
      return { dw: dx, dh: 0 }
    case 'w':
      return { dw: -dx, dh: 0 }
    case 's':
      return { dw: 0, dh: dy }
    case 'n':
      return { dw: 0, dh: -dy }
    case 'se':
      return { dw: dx, dh: dy }
    case 'sw':
      return { dw: -dx, dh: dy }
    case 'ne':
      return { dw: dx, dh: -dy }
    case 'nw':
      return { dw: -dx, dh: -dy }
    default:
      return { dw: 0, dh: 0 }
  }
}

const HANDLE_HIT_BASE =
  'pointer-events-auto absolute flex min-h-[44px] min-w-[44px] items-center justify-center rounded-sm border-0 bg-transparent p-0 outline-none focus-visible:ring-2 focus-visible:ring-ring'

const HANDLE_EDGE = `${HANDLE_HIT_BASE} z-20`

const HANDLE_TOOL = `${HANDLE_HIT_BASE} z-30`

/** Dotted frame guide sits this many px outside the crop rect */
const FRAME_GUIDE_OUTSET = 28

/** Extra space around rotated AABB so corner / rotate handles are not clipped by parent overflow-hidden */
const PREVIEW_VIEW_MARGIN = 64

/** Local ↔ server preview crossfade */
const PREVIEW_CROSSFADE_CLASS = 'transition-opacity duration-300 ease-in-out'

const HANDLE_DOT =
  'h-5 w-5 shrink-0 rounded-full border-2 border-border bg-background shadow-sm'

type RadiusCorner = 'nw' | 'ne' | 'se' | 'sw'

function outwardUnitForCorner(corner: RadiusCorner, rect: DOMRect) {
  const cx = rect.left + rect.width / 2
  const cy = rect.top + rect.height / 2
  let px: number
  let py: number
  switch (corner) {
    case 'nw':
      px = rect.left
      py = rect.top
      break
    case 'ne':
      px = rect.right
      py = rect.top
      break
    case 'se':
      px = rect.right
      py = rect.bottom
      break
    case 'sw':
      px = rect.left
      py = rect.bottom
      break
  }
  const vx = px - cx
  const vy = py - cy
  const len = Math.hypot(vx, vy) || 1
  return { ux: vx / len, uy: vy / len }
}

const RADIUS_CORNER_CURSOR: Record<RadiusCorner, string> = {
  nw: 'cursor-nwse-resize',
  ne: 'cursor-nesw-resize',
  se: 'cursor-nwse-resize',
  sw: 'cursor-nesw-resize',
}

const RADIUS_CORNER_POS: Record<
  RadiusCorner,
  string
> = {
  nw: 'start-0 top-0 -translate-x-[42px] -translate-y-[42px]',
  ne: 'end-0 top-0 translate-x-[42px] -translate-y-[42px]',
  se: 'end-0 bottom-0 translate-x-[42px] translate-y-[42px]',
  sw: 'start-0 bottom-0 -translate-x-[42px] translate-y-[42px]',
}

function CornerRadiusArchIcon({ corner }: { corner: RadiusCorner }) {
  return (
    <span
      className={cn(
        'pointer-events-none flex h-10 w-10 items-center justify-center text-primary',
        corner === 'ne' && 'scale-x-[-1]',
        corner === 'sw' && 'scale-y-[-1]',
        corner === 'se' && 'scale-x-[-1] scale-y-[-1]',
      )}
    >
      <svg viewBox="0 0 32 32" className="h-full w-full" aria-hidden>
        <path
          d="M 6 30 A 22 22 0 0 1 30 6"
          fill="none"
          stroke="currentColor"
          strokeWidth="3.5"
          strokeLinecap="round"
        />
      </svg>
    </span>
  )
}

const EDGE_CURSOR: Record<ResizeEdge, string> = {
  nw: 'cursor-nwse-resize',
  n: 'cursor-ns-resize',
  ne: 'cursor-nesw-resize',
  e: 'cursor-ew-resize',
  se: 'cursor-nwse-resize',
  s: 'cursor-ns-resize',
  sw: 'cursor-nesw-resize',
  w: 'cursor-ew-resize',
}

/** Resize handles: inside the frame; larger inset = farther from the image edge */
const RESIZE_HANDLE_POS: Record<ResizeEdge, string> = {
  nw: 'start-5 top-5',
  n: 'start-1/2 top-5 -translate-x-1/2',
  ne: 'end-5 top-5',
  e: 'end-5 top-1/2 -translate-y-1/2',
  se: 'end-5 bottom-5',
  s: 'start-1/2 bottom-5 -translate-x-1/2',
  sw: 'start-5 bottom-5',
  w: 'start-5 top-1/2 -translate-y-1/2',
}

type Drag =
  | {
      kind: 'resize'
      edge: ResizeEdge
      startClientX: number
      startClientY: number
      baseW: number
      baseH: number
      aspectLock: boolean
    }
  | { kind: 'rotate'; cx: number; cy: number; lastAngle: number }
  | {
      kind: 'radiusCorner'
      corner: RadiusCorner
      startClientX: number
      startClientY: number
      startBorderRadius: number
      ux: number
      uy: number
    }

const CORNER_RESIZE: ResizeEdge[] = ['nw', 'ne', 'se', 'sw']

function applyAspectLockedResize(
  edge: ResizeEdge,
  baseW: number,
  baseH: number,
  dw: number,
  dh: number,
): { nw: number; nh: number } {
  const ar = baseW / Math.max(1, baseH)
  if (CORNER_RESIZE.includes(edge)) {
    const scaleW = (baseW + dw) / baseW
    const scaleH = (baseH + dh) / baseH
    const scale =
      Math.abs(scaleW - 1) > Math.abs(scaleH - 1) ? scaleW : scaleH
    return {
      nw: CLAMP_W(Math.round(baseW * scale)),
      nh: CLAMP_H(Math.round(baseH * scale)),
    }
  }
  if (edge === 'e' || edge === 'w') {
    const nw = CLAMP_W(baseW + dw)
    const nh = CLAMP_H(Math.max(1, Math.round(nw / ar)))
    return { nw, nh }
  }
  if (edge === 'n' || edge === 's') {
    const nh = CLAMP_H(baseH + dh)
    const nw = CLAMP_W(Math.max(64, Math.round(nh * ar)))
    return { nw, nh }
  }
  const nw = CLAMP_W(baseW + dw)
  const nh = CLAMP_H(baseH + dh)
  return { nw, nh }
}

function resizeKeyboardNudge(
  edge: ResizeEdge,
  key: string,
  shift: boolean,
): { dw: number; dh: number } | null {
  const step = shift ? 32 : 8
  switch (key) {
    case 'ArrowRight':
      if (edge === 'e' || edge === 'ne' || edge === 'se')
        return { dw: step, dh: 0 }
      if (edge === 'w' || edge === 'nw' || edge === 'sw')
        return { dw: -step, dh: 0 }
      return null
    case 'ArrowLeft':
      if (edge === 'e' || edge === 'ne' || edge === 'se')
        return { dw: -step, dh: 0 }
      if (edge === 'w' || edge === 'nw' || edge === 'sw')
        return { dw: step, dh: 0 }
      return null
    case 'ArrowDown':
      if (edge === 's' || edge === 'se' || edge === 'sw')
        return { dw: 0, dh: step }
      if (edge === 'n' || edge === 'ne' || edge === 'nw')
        return { dw: 0, dh: -step }
      return null
    case 'ArrowUp':
      if (edge === 's' || edge === 'se' || edge === 'sw')
        return { dw: 0, dh: -step }
      if (edge === 'n' || edge === 'ne' || edge === 'nw')
        return { dw: 0, dh: step }
      return null
    default:
      return null
  }
}

export type TransformCanvasMode = 'edit' | 'compare'

export function TransformImageDesignOverlay({
  zoom,
  state,
  setState,
  localImgSrc,
  originalImgSrc,
  serverImgSrc,
  imgAlt,
  canvasMode = 'edit',
  onTransformInteractionStart,
  onTransformInteractionEnd,
}: {
  zoom: number
  state: ImageTransformState
  setState: Dispatch<SetStateAction<ImageTransformState>>
  /**
   * File view URL for the **edit** stack underlay (instant client-side crop that
   * matches the server preview geometry).
   */
  localImgSrc: string
  /**
   * Untransformed preview URL (`getFilePreview` with only bucket + file id, + `mode=admin`).
   * Used for the **compare** “Original” pane so it never shares the transformed design-canvas URL.
   */
  originalImgSrc?: string
  /**
   * Debounced file preview URL for idle fidelity. Must be built **without** API
   * `rotation` so rotation matches the local layer (CSS on the parent only); otherwise
   * counter-rotating the bitmap breaks `overflow-hidden` on the frame.
   */
  serverImgSrc: string
  imgAlt: string
  canvasMode?: TransformCanvasMode
  /** Called when the user begins a pointer resize / rotate / radius drag (for undo grouping). */
  onTransformInteractionStart?: () => void
  /** Called after a pointer resize / rotate / radius drag ends (for undo grouping). */
  onTransformInteractionEnd?: () => void
}) {
  const t = useT()
  const wrapRef = useRef<HTMLDivElement>(null)
  const frameRef = useRef<HTMLDivElement>(null)
  const localImgRef = useRef<HTMLImageElement>(null)
  const [nat, setNat] = useState<{ w: number; h: number } | null>(null)
  const natRef = useRef<{ w: number; h: number } | null>(null)
  natRef.current = nat

  const [dragging, setDragging] = useState(false)
  const [serverDecoded, setServerDecoded] = useState(false)
  const dragRef = useRef<Drag | null>(null)
  const captureRef = useRef<{
    el: HTMLElement
    pointerId: number
  } | null>(null)

  const stateRef = useRef(state)
  stateRef.current = state

  const pristineSrc = originalImgSrc ?? localImgSrc

  const layout = useMemo(() => {
    if (!nat) return null
    return computeImageTransformDisplayLayout(nat, state)
  }, [nat, state])

  useEffect(() => {
    setServerDecoded(false)
  }, [serverImgSrc])

  const serverPreviewReady = !dragging && serverDecoded

  const showServerLayer = canvasMode === 'edit' && serverPreviewReady

  const showServerFidelity = showServerLayer

  const objectPosStyle = {
    objectPosition: gravityToObjectPosition(state.gravity),
  } as const

  const applyResizeKeyboard = (edge: ResizeEdge, e: React.KeyboardEvent) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')
      return
    const n = natRef.current
    if (!n) return
    const delta = resizeKeyboardNudge(edge, e.key, e.altKey)
    if (!delta) return
    e.preventDefault()
    const { logicalW: bw, logicalH: bh } = computeImageTransformDisplayLayout(
      n,
      stateRef.current,
    )
    const aspectLock = e.shiftKey && CORNER_RESIZE.includes(edge)
    const { nw, nh } = aspectLock
      ? applyAspectLockedResize(edge, bw, bh, delta.dw, delta.dh)
      : {
          nw: CLAMP_W(bw + delta.dw),
          nh: CLAMP_H(bh + delta.dh),
        }
    onTransformInteractionStart?.()
    setState((prev) => {
      if (aspectLock) {
        return { ...prev, width: nw, height: nh }
      }
      if (edge === 'e' || edge === 'w') return { ...prev, width: nw }
      if (edge === 'n' || edge === 's') return { ...prev, height: nh }
      return { ...prev, width: nw, height: nh }
    })
    queueMicrotask(() => onTransformInteractionEnd?.())
  }

  const applyRadiusKeyboard = (e: React.KeyboardEvent) => {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return
    e.preventDefault()
    const step = e.altKey ? 20 : e.shiftKey ? 10 : 1
    const delta = e.key === 'ArrowUp' ? step : -step
    onTransformInteractionStart?.()
    setState((s) => ({
      ...s,
      borderRadius: CLAMP_R(s.borderRadius + delta),
    }))
    queueMicrotask(() => onTransformInteractionEnd?.())
  }

  const endDrag = useCallback(() => {
    const hadDrag = dragRef.current !== null
    const cap = captureRef.current
    if (cap) {
      try {
        cap.el.releasePointerCapture(cap.pointerId)
      } catch {
        /* */
      }
      captureRef.current = null
    }
    dragRef.current = null
    setDragging(false)
    if (hadDrag) onTransformInteractionEnd?.()
  }, [onTransformInteractionEnd])

  const beginCapture = (el: HTMLElement, pointerId: number) => {
    try {
      el.setPointerCapture(pointerId)
      captureRef.current = { el, pointerId }
    } catch {
      captureRef.current = null
    }
  }

  const onPointerMove = useCallback(
    (e: PointerEvent) => {
      const d = dragRef.current
      if (!d) return
      e.preventDefault()

      if (d.kind === 'resize') {
        const dx = (e.clientX - d.startClientX) / zoom
        const dy = (e.clientY - d.startClientY) / zoom
        const { dw, dh } = resizeDeltaForEdge(d.edge, dx, dy)
        const { nw, nh } = d.aspectLock
          ? applyAspectLockedResize(d.edge, d.baseW, d.baseH, dw, dh)
          : {
              nw: CLAMP_W(d.baseW + dw),
              nh: CLAMP_H(d.baseH + dh),
            }
        setState((prev) => {
          if (d.aspectLock) {
            return { ...prev, width: nw, height: nh }
          }
          if (d.edge === 'e' || d.edge === 'w') {
            return { ...prev, width: nw }
          }
          if (d.edge === 'n' || d.edge === 's') {
            return { ...prev, height: nh }
          }
          return { ...prev, width: nw, height: nh }
        })
        return
      }

      if (d.kind === 'rotate') {
        const prev = dragRef.current
        if (!prev || prev.kind !== 'rotate') return
        const a = Math.atan2(e.clientY - prev.cy, e.clientX - prev.cx)
        let da = a - prev.lastAngle
        if (da > Math.PI) da -= 2 * Math.PI
        if (da < -Math.PI) da += 2 * Math.PI
        dragRef.current = { ...prev, lastAngle: a }
        const deltaDeg = (da * 180) / Math.PI
        setState((s) => ({
          ...s,
          rotation: CLAMP_ROT(s.rotation + deltaDeg),
        }))
        return
      }

      if (d.kind === 'radiusCorner') {
        const delta =
          ((e.clientX - d.startClientX) * d.ux +
            (e.clientY - d.startClientY) * d.uy) /
          zoom
        const next = CLAMP_R(d.startBorderRadius - delta)
        setState((prev) => ({ ...prev, borderRadius: next }))
      }
    },
    [setState, zoom],
  )

  useEffect(() => {
    if (!dragging) return
    window.addEventListener('pointermove', onPointerMove, { passive: false })
    const up = () => endDrag()
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
    return () => {
      window.removeEventListener('pointermove', onPointerMove)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
    }
  }, [dragging, onPointerMove, endDrag])

  const startResize = (edge: ResizeEdge, e: React.PointerEvent) => {
    const n = natRef.current
    if (!n) return
    e.stopPropagation()
    e.preventDefault()
    onTransformInteractionStart?.()
    const s = stateRef.current
    const { logicalW: bw, logicalH: bh } = computeImageTransformDisplayLayout(
      n,
      s,
    )
    dragRef.current = {
      kind: 'resize',
      edge,
      startClientX: e.clientX,
      startClientY: e.clientY,
      baseW: bw,
      baseH: bh,
      aspectLock: e.shiftKey,
    }
    beginCapture(e.currentTarget as HTMLElement, e.pointerId)
    setDragging(true)
  }

  const startRotate = (e: React.PointerEvent) => {
    e.stopPropagation()
    e.preventDefault()
    onTransformInteractionStart?.()
    const el = frameRef.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const cx = r.left + r.width / 2
    const cy = r.top + r.height / 2
    const a = Math.atan2(e.clientY - cy, e.clientX - cx)
    dragRef.current = { kind: 'rotate', cx, cy, lastAngle: a }
    beginCapture(e.currentTarget as HTMLElement, e.pointerId)
    setDragging(true)
  }

  const startRadiusCorner = (corner: RadiusCorner, e: React.PointerEvent) => {
    e.stopPropagation()
    e.preventDefault()
    onTransformInteractionStart?.()
    const fr = frameRef.current
    if (!fr) return
    const rect = fr.getBoundingClientRect()
    const { ux, uy } = outwardUnitForCorner(corner, rect)
    dragRef.current = {
      kind: 'radiusCorner',
      corner,
      startClientX: e.clientX,
      startClientY: e.clientY,
      startBorderRadius: stateRef.current.borderRadius,
      ux,
      uy,
    }
    beginCapture(e.currentTarget as HTMLElement, e.pointerId)
    setDragging(true)
  }

  const handleLocalLoad = () => {
    const im = localImgRef.current
    if (!im?.naturalWidth) return
    setNat({ w: im.naturalWidth, h: im.naturalHeight })
  }

  const handleServerLoad = async (e: React.SyntheticEvent<HTMLImageElement>) => {
    const im = e.currentTarget
    if (!im.naturalWidth) return
    try {
      if (im.decode) await im.decode()
    } catch {
      /* */
    }
    setServerDecoded(true)
  }

  const handleServerError = () => {
    setServerDecoded(false)
  }

  const showHandles = !!layout && canvasMode === 'edit'

  const borderStyle =
    state.borderWidth > 0
      ? `${state.borderWidth}px solid ${
          state.borderColor.trim()
            ? `#${state.borderColor.replace(/^#/, '').slice(0, 12)}`
            : 'hsl(var(--border))'
        }`
      : undefined

  const bgFill =
    state.background.trim().length > 0
      ? `#${state.background.replace(/^#/, '').slice(0, 12)}`
      : undefined

  if (!nat || !layout) {
    return (
      <div
        ref={wrapRef}
        className={cn(
          'relative w-fit max-w-full select-none',
          dragging && 'cursor-inherit',
        )}
      >
        <img
          ref={localImgRef}
          src={localImgSrc}
          alt={imgAlt}
          draggable={false}
          onLoad={handleLocalLoad}
          className="pointer-events-none block max-h-[min(80dvh,720px)] max-w-full object-contain"
        />
      </div>
    )
  }

  const { displayW, displayH, aabbW, aabbH, logicalW, logicalH } = layout

  const frameShell = (opts: {
    frameRefProp: RefObject<HTMLDivElement | null> | null
    children: ReactNode
  }) => (
    <div className="flex h-full w-full items-center justify-center">
      <div
        className="relative flex items-center justify-center"
        style={{ width: aabbW, height: aabbH }}
      >
        <div
          className={cn(
            'group/frame relative',
            dragging && 'cursor-inherit',
          )}
          style={{
            width: displayW,
            height: displayH,
            transform: `rotate(${state.rotation}deg)`,
            transformOrigin: 'center center',
          }}
        >
          <div
            ref={opts.frameRefProp ?? undefined}
            className="relative overflow-hidden"
            style={{
              width: displayW,
              height: displayH,
              borderRadius: state.borderRadius,
              backgroundColor: bgFill,
              border: borderStyle,
              boxSizing: 'border-box',
            }}
          >
            {opts.children}
          </div>
        </div>
      </div>
    </div>
  )

  if (canvasMode === 'compare') {
    const rightShowServer = serverPreviewReady
    return (
      <div
        ref={wrapRef}
        className={cn(
          'group/preview-size relative shrink-0 select-none',
          dragging && 'cursor-inherit',
        )}
      >
        <div className="flex w-full max-w-full flex-col items-stretch justify-center gap-5 sm:flex-row sm:items-start sm:gap-6">
          <div className="flex min-w-0 flex-1 flex-col items-center gap-1.5 sm:flex-initial">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Original')}
            </p>
            <div
              className="flex w-full max-w-full min-h-0 items-center justify-center"
              style={{
                width: aabbW + PREVIEW_VIEW_MARGIN * 2,
                height: aabbH + PREVIEW_VIEW_MARGIN * 2,
              }}
            >
              <img
                ref={localImgRef}
                src={pristineSrc}
                alt={imgAlt}
                draggable={false}
                onLoad={handleLocalLoad}
                className="pointer-events-none max-h-full max-w-full object-contain"
              />
            </div>
          </div>
          <div className="flex min-w-0 flex-1 flex-col items-center gap-1.5 sm:flex-initial">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Output')}
            </p>
            <div
              className="flex w-full max-w-full items-center justify-center"
              style={{
                width: aabbW + PREVIEW_VIEW_MARGIN * 2,
                height: aabbH + PREVIEW_VIEW_MARGIN * 2,
              }}
            >
              {frameShell({
                frameRefProp: null,
                children: (
                  <div
                    className="relative h-full w-full"
                    style={{ opacity: state.opacity }}
                  >
                    <img
                      src={localImgSrc}
                      alt=""
                      aria-hidden
                      draggable={false}
                      className={cn(
                        'pointer-events-none absolute inset-0 z-0 block h-full w-full object-cover',
                        PREVIEW_CROSSFADE_CLASS,
                        rightShowServer ? 'opacity-0' : 'opacity-100',
                      )}
                      style={objectPosStyle}
                    />
                    <div
                      key={serverImgSrc}
                      className={cn(
                        'pointer-events-none absolute inset-0 z-[1] h-full w-full',
                        PREVIEW_CROSSFADE_CLASS,
                        rightShowServer ? 'opacity-100' : 'opacity-0',
                      )}
                    >
                      <img
                        src={serverImgSrc}
                        alt={rightShowServer ? imgAlt : ''}
                        aria-hidden={!rightShowServer}
                        draggable={false}
                        onLoad={handleServerLoad}
                        onError={handleServerError}
                        className="pointer-events-none block h-full w-full object-cover"
                        style={objectPosStyle}
                      />
                    </div>
                  </div>
                ),
              })}
            </div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div
      ref={wrapRef}
      className={cn(
        'group/preview-size relative shrink-0 select-none',
        dragging && 'cursor-inherit',
      )}
      style={{
        width: aabbW + PREVIEW_VIEW_MARGIN * 2,
        height: aabbH + PREVIEW_VIEW_MARGIN * 2,
      }}
    >
      <div className="flex h-full w-full items-center justify-center">
        <div
          className="relative flex items-center justify-center"
          style={{ width: aabbW, height: aabbH }}
        >
          <div
            className={cn(
              'group/frame relative',
              dragging && 'cursor-inherit',
            )}
            style={{
              width: displayW,
              height: displayH,
              transform: `rotate(${state.rotation}deg)`,
              transformOrigin: 'center center',
            }}
          >
        <div
          ref={frameRef}
          className="relative overflow-hidden"
          style={{
            width: displayW,
            height: displayH,
            borderRadius: state.borderRadius,
            backgroundColor: bgFill,
            border: borderStyle,
            boxSizing: 'border-box',
          }}
        >
          <div
            className="relative h-full w-full"
            style={{ opacity: state.opacity }}
          >
            <>
                <img
                  ref={localImgRef}
                  src={localImgSrc}
                  alt={showServerFidelity ? '' : imgAlt}
                  aria-hidden={showServerFidelity}
                  draggable={false}
                  onLoad={handleLocalLoad}
                  className={cn(
                    'pointer-events-none absolute inset-0 z-0 block h-full w-full object-cover',
                    PREVIEW_CROSSFADE_CLASS,
                    !showServerFidelity
                      ? 'opacity-100'
                      : 'opacity-0',
                  )}
                  style={objectPosStyle}
                />
                <div
                  key={serverImgSrc}
                  className={cn(
                    'pointer-events-none absolute inset-0 z-[1] h-full w-full',
                    PREVIEW_CROSSFADE_CLASS,
                    showServerFidelity ? 'opacity-100' : 'opacity-0',
                  )}
                >
                  <img
                    src={serverImgSrc}
                    alt={showServerFidelity ? imgAlt : ''}
                    aria-hidden={!showServerFidelity}
                    draggable={false}
                    onLoad={handleServerLoad}
                    onError={handleServerError}
                    className="pointer-events-none block h-full w-full object-cover"
                    style={objectPosStyle}
                  />
                </div>
              </>
          </div>
        </div>
        {showHandles && (
          <div
            className="pointer-events-none absolute start-0 top-0 opacity-100 transition-opacity duration-150"
            style={{ width: displayW, height: displayH }}
          >
          <div
            className="pointer-events-none absolute z-[1] border-[3px] border-dotted border-muted-foreground/35"
            style={{
              inset: -FRAME_GUIDE_OUTSET,
              borderRadius: Math.min(
                4000,
                Math.max(0, state.borderRadius + FRAME_GUIDE_OUTSET),
              ),
            }}
          />
          <div
            className="pointer-events-auto absolute inset-0 z-[5] cursor-default"
            aria-hidden
          />
          {(
            ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as const
          ).map((edge) => (
            <button
              key={edge}
              type="button"
              className={cn(
                HANDLE_EDGE,
                EDGE_CURSOR[edge],
                RESIZE_HANDLE_POS[edge],
              )}
              aria-label={`${t('Resize from')} ${edge}. ${t('Use arrow keys to nudge; Alt for larger steps. Hold Shift with corner handles to keep aspect. Pointer drag with Shift locks aspect.')}`}
              onPointerDown={(ev) => startResize(edge, ev)}
              onKeyDown={(ev) => applyResizeKeyboard(edge, ev)}
            >
              <span className={HANDLE_DOT} />
            </button>
          ))}
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className={cn(
                  HANDLE_TOOL,
                  'start-1/2 top-0 -translate-x-1/2 -translate-y-[calc(100%+40px)] cursor-grab active:cursor-grabbing',
                )}
                aria-label={t(
                  'Rotate: drag in a circle, or use Left and Right arrow keys. Shift with arrows rotates 15 degrees.',
                )}
                onPointerDown={startRotate}
                onKeyDown={(ev) => {
                  if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
                    ev.preventDefault()
                    onTransformInteractionStart?.()
                    const step = ev.shiftKey ? 15 : 1
                    const delta = ev.key === 'ArrowLeft' ? -step : step
                    setState((s) => ({
                      ...s,
                      rotation: CLAMP_ROT(s.rotation + delta),
                    }))
                    queueMicrotask(() => onTransformInteractionEnd?.())
                  }
                }}
              >
                <span className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-border bg-background shadow-sm backdrop-blur-sm">
                  <RotateCw className="h-5 w-5 text-muted-foreground" />
                </span>
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-[220px] text-[12px]">
              {t(
                'Drag in a circle to rotate. Keyboard: Left or Right arrow (Shift for 15° steps). Same as rotation in the sidebar.',
              )}
            </TooltipContent>
          </Tooltip>
          {(['nw', 'ne', 'se', 'sw'] as const).map((corner) => (
            <Tooltip key={`r-${corner}`}>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className={cn(
                    HANDLE_TOOL,
                    RADIUS_CORNER_POS[corner],
                    RADIUS_CORNER_CURSOR[corner],
                  )}
                  aria-label={t(
                    'Corner radius: drag toward the image to increase radius, or use Up and Down arrow keys. Shift for larger steps, Alt for largest steps.',
                  )}
                  onPointerDown={(ev) => startRadiusCorner(corner, ev)}
                  onKeyDown={applyRadiusKeyboard}
                >
                  <CornerRadiusArchIcon corner={corner} />
                </button>
              </TooltipTrigger>
              <TooltipContent
                side={
                  corner === 'nw' || corner === 'ne'
                    ? 'top'
                    : corner === 'sw'
                      ? 'left'
                      : 'bottom'
                }
                className="max-w-[220px] text-[12px]"
              >
                {t(
                  'Drag toward the image along the diagonal to increase corner radius (away from the image to decrease). Keyboard: Up or Down arrow (Shift or Alt for bigger steps).',
                )}
              </TooltipContent>
            </Tooltip>
          ))}
        </div>
      )}
          </div>
        </div>
      </div>
      <div
        className="pointer-events-none absolute inset-x-0 bottom-1 z-[5] flex translate-y-3 justify-center px-2 opacity-100 transition-opacity duration-150"
      >
        <span className="rounded-md border border-border bg-card/90 px-2 py-1 text-[11px] font-medium tabular-nums text-foreground shadow-sm backdrop-blur-sm">
          <span className="text-muted-foreground">
            {nat.w}×{nat.h}
          </span>
          <span className="text-muted-foreground"> → </span>
          <span className="text-foreground">
            {logicalW} × {logicalH} px
          </span>
          <span className="text-muted-foreground"> · </span>
          <span className="text-muted-foreground">
            {t(previewOutputFormatLabel(state.output))}
          </span>
        </span>
      </div>
    </div>
  )
}
