import { useEffect, useRef, useState } from 'react'
import { cn } from '@/lib/utils'
import './native-oauth-promo-cover.css'

const GOOGLE_ICON_SRC = '/icons/google.svg'
const APPLE_ICON_SRC = '/icons/apple.svg'

/** Phone outer frame (border-box: 400×820 screen + 12px bezel padding). */
const STAGE_WIDTH = 424
const STAGE_HEIGHT = 844
/** Horizontal breathing room inside the preview (each side). */
const FRAME_INSET_X = 38
const FRAME_INSET_Y = 20
/** Shift down so the bottom sheet stays in frame without clipping the top bezel. */
const PHONE_FOCUS_SHIFT_DOWN_PX = 24
const PHONE_MAX_SCALE_DEFAULT = 0.58
const PHONE_MAX_SCALE_COMPACT = 0.56
const PHONE_MAX_SCALE_SQUARE = 0.61
const PHONE_BEZEL_PX = 12
const SHEET_TOP_IN_SCREEN_PX = 330
const SCREEN_INNER_HEIGHT_PX = STAGE_HEIGHT - PHONE_BEZEL_PX * 2
/** Stage-space Y bounds for the account sheet (open). */
const PHONE_SHEET_TOP_Y = PHONE_BEZEL_PX + SHEET_TOP_IN_SCREEN_PX
const PHONE_SHEET_BOTTOM_Y = PHONE_BEZEL_PX + SCREEN_INNER_HEIGHT_PX
const PHONE_SHEET_CENTER_Y = (PHONE_SHEET_TOP_Y + PHONE_SHEET_BOTTOM_Y) / 2
const SQUARE_FRAME_BOTTOM_MARGIN_PX = 12
/** Downward scroll nudge when the sheet is open (preview coords). */
const SQUARE_SHEET_SCROLL_NUDGE_PX = 28
/** Matches entrance transition — sheet sequence starts after slide-up finishes. */
const PHONE_ENTRANCE_MS = 2100
const PHONE_ENTRANCE_MS_SQUARE = 2800
const PHONE_ENTRANCE_TRANSITION =
  'transform 1.9s cubic-bezier(0.22, 1, 0.36, 1)'
const PHONE_ENTRANCE_TRANSITION_SQUARE =
  'transform 2.75s cubic-bezier(0.22, 1, 0.36, 1)'
/** Pause on the signed-in state before replaying the promo sequence. */
const PROMO_ANIMATION_LOOP_HOLD_MS = 3500

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="#fff"
      strokeWidth={3}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      <path d="M5 12.5l4.5 4.5L19 7.5" />
    </svg>
  )
}

type NativeOAuthPromoCoverProps = {
  /** Tighter preview (legacy compact strip). */
  compact?: boolean
  /** 450×450 promo card preview pane. */
  layout?: 'default' | 'square'
}

/** Looped promo animation: phone mockup only, scaled to the banner header. */
export function NativeOAuthPromoCover({
  compact = false,
  layout = 'default',
}: NativeOAuthPromoCoverProps = {}) {
  const phoneMaxScale =
    layout === 'square'
      ? PHONE_MAX_SCALE_SQUARE
      : compact
        ? PHONE_MAX_SCALE_COMPACT
        : PHONE_MAX_SCALE_DEFAULT
  const isSquareLayout = layout === 'square'
  const frameRef = useRef<HTMLDivElement>(null)
  const stageRef = useRef<HTMLDivElement>(null)
  const sheetRef = useRef<HTMLDivElement>(null)
  const stageScaleRef = useRef(1)
  const stageTopClosedRef = useRef(0)
  const stageTopOpenRef = useRef(0)
  const entranceDoneRef = useRef(false)
  const entranceStartedRef = useRef(false)
  const squareRecenterStartedRef = useRef(false)
  const screenRef = useRef<HTMLDivElement>(null)
  const rowRef = useRef<HTMLDivElement>(null)
  const ctaRef = useRef<HTMLDivElement>(null)
  const tapRef = useRef<HTMLDivElement>(null)

  const [sheetOpen, setSheetOpen] = useState(false)
  const [rowsVisible, setRowsVisible] = useState(false)
  const [rowSelected, setRowSelected] = useState(false)
  const [ctaPress, setCtaPress] = useState(false)
  const [ctaBusy, setCtaBusy] = useState(false)
  const [ctaDone, setCtaDone] = useState(false)
  const [tapGo, setTapGo] = useState(false)
  const [animationCycle, setAnimationCycle] = useState(0)

  useEffect(() => {
    entranceDoneRef.current = false
    entranceStartedRef.current = false
    squareRecenterStartedRef.current = false

    const frame = frameRef.current
    const stage = stageRef.current
    if (!frame || !stage) return

    const applyStageTransform = (top: number, scale: number) => {
      stage.style.left = '50%'
      stage.style.top = '0'
      stage.style.transform = `translateX(-50%) translateY(${top}px) scale(${scale})`
    }

    const computeSquareScale = () => {
      const availW = Math.max(frame.clientWidth - FRAME_INSET_X * 2, 1)
      const availH = Math.max(frame.clientHeight - FRAME_INSET_Y * 2, 1)
      const scaleW = availW / STAGE_WIDTH
      const sheetSpan = PHONE_SHEET_BOTTOM_Y - PHONE_SHEET_TOP_Y
      const scaleH = availH / (sheetSpan + 68)
      const maxScaleForBottom =
        (frame.clientHeight / 2 - SQUARE_FRAME_BOTTOM_MARGIN_PX) /
        (PHONE_SHEET_BOTTOM_Y - PHONE_SHEET_CENTER_Y)
      return Math.min(scaleW, scaleH, maxScaleForBottom, phoneMaxScale)
    }

    const computeSquareTops = (scale: number) => {
      const scaledH = STAGE_HEIGHT * scale
      const rawClosed =
        frame.clientHeight - FRAME_INSET_Y - scaledH + PHONE_FOCUS_SHIFT_DOWN_PX
      const topClosed = Math.max(FRAME_INSET_Y, rawClosed)

      const idealOpen =
        frame.clientHeight / 2 -
        PHONE_SHEET_CENTER_Y * scale +
        SQUARE_SHEET_SCROLL_NUDGE_PX * scale
      const maxOpen =
        frame.clientHeight -
        SQUARE_FRAME_BOTTOM_MARGIN_PX -
        PHONE_SHEET_BOTTOM_Y * scale
      const minOpen = FRAME_INSET_Y - PHONE_BEZEL_PX * scale
      const topOpen = Math.max(minOpen, Math.min(idealOpen, maxOpen))

      return { topClosed, topOpen }
    }

    const computeDefaultTop = (scale: number) => {
      const scaledH = STAGE_HEIGHT * scale
      const rawTop =
        frame.clientHeight - FRAME_INSET_Y - scaledH + PHONE_FOCUS_SHIFT_DOWN_PX
      return Math.max(FRAME_INSET_Y, rawTop)
    }

    const entranceTransition = isSquareLayout
      ? PHONE_ENTRANCE_TRANSITION_SQUARE
      : PHONE_ENTRANCE_TRANSITION

    const fit = () => {
      const scale = isSquareLayout
        ? computeSquareScale()
        : Math.min(
            Math.max(frame.clientWidth - FRAME_INSET_X * 2, 1) / STAGE_WIDTH,
            phoneMaxScale,
          )
      stageScaleRef.current = scale

      const scaledH = STAGE_HEIGHT * scale
      if (isSquareLayout) {
        const { topClosed, topOpen } = computeSquareTops(scale)
        stageTopClosedRef.current = topClosed
        stageTopOpenRef.current = topOpen
      }

      const restTop = isSquareLayout
        ? squareRecenterStartedRef.current
          ? stageTopOpenRef.current
          : stageTopClosedRef.current
        : computeDefaultTop(scale)

      if (!entranceDoneRef.current) {
        if (!entranceStartedRef.current) {
          entranceStartedRef.current = true
          const entranceEndTop = isSquareLayout
            ? stageTopClosedRef.current
            : restTop
          stage.style.transition = 'none'
          applyStageTransform(entranceEndTop + scaledH * 0.92, scale)
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              stage.style.transition = entranceTransition
              applyStageTransform(entranceEndTop, scale)
              entranceDoneRef.current = true
            })
          })
        }
        return
      }

      stage.style.transition = 'transform 0.2s ease-out'
      applyStageTransform(restTop, scale)
    }

    fit()
    const ro = new ResizeObserver(() => fit())
    ro.observe(frame)
    return () => ro.disconnect()
  }, [animationCycle, isSquareLayout, phoneMaxScale])

  useEffect(() => {
    if (!isSquareLayout || !sheetOpen) return
    const stage = stageRef.current
    if (!stage || !entranceDoneRef.current) return
    if (squareRecenterStartedRef.current) return
    squareRecenterStartedRef.current = true

    stage.style.transition =
      'transform 1.35s cubic-bezier(0.22, 1, 0.36, 1)'
    stage.style.left = '50%'
    stage.style.top = '0'
    stage.style.transform = `translateX(-50%) translateY(${stageTopOpenRef.current}px) scale(${stageScaleRef.current})`
  }, [animationCycle, isSquareLayout, sheetOpen])

  useEffect(() => {
    const reduce =
      typeof window !== 'undefined' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches

    if (reduce) {
      setSheetOpen(true)
      setRowsVisible(true)
      setRowSelected(true)
      setCtaDone(true)
      return
    }

    const timers: ReturnType<typeof setTimeout>[] = []
    const at = (ms: number, fn: () => void) => {
      timers.push(setTimeout(fn, ms))
    }

    const tapAt = (el: HTMLElement | null) => {
      const tap = tapRef.current
      const screen = screenRef.current
      const frame = frameRef.current
      if (!tap || !screen || !frame || !el) return
      const r = el.getBoundingClientRect()
      const s = screen.getBoundingClientRect()
      const k = stageScaleRef.current
      tap.style.left = `${(r.left - s.left) / k + r.width / k / 2}px`
      tap.style.top = `${(r.top - s.top) / k + r.height / k / 2}px`
      setTapGo(false)
      requestAnimationFrame(() => setTapGo(true))
    }

    const entranceMs = isSquareLayout
      ? PHONE_ENTRANCE_MS_SQUARE
      : PHONE_ENTRANCE_MS
    const sheetOpenAt = isSquareLayout ? entranceMs : entranceMs + 350
    const rowsAt = sheetOpenAt + (isSquareLayout ? 420 : 0)
    at(sheetOpenAt, () => {
      setSheetOpen(true)
    })
    at(rowsAt, () => {
      setRowsVisible(true)
    })
    at(rowsAt + 1700, () => {
      tapAt(rowRef.current)
      setRowSelected(true)
    })
    at(rowsAt + 2700, () => {
      tapAt(ctaRef.current)
      setCtaPress(true)
    })
    at(rowsAt + 2880, () => {
      setCtaPress(false)
      setCtaBusy(true)
    })
    at(rowsAt + 3900, () => {
      setCtaBusy(false)
      setCtaDone(true)
    })
    at(rowsAt + 3900 + PROMO_ANIMATION_LOOP_HOLD_MS, () => {
      setSheetOpen(false)
      setRowsVisible(false)
      setRowSelected(false)
      setCtaPress(false)
      setCtaBusy(false)
      setCtaDone(false)
      setTapGo(false)
      setAnimationCycle((cycle) => cycle + 1)
    })
    return () => timers.forEach(clearTimeout)
  }, [animationCycle, isSquareLayout])

  return (
    <div
      className={cn(
        'native-oauth-cover',
        isSquareLayout && 'native-oauth-cover-square',
      )}
      aria-hidden
    >
      <div ref={frameRef} className="native-oauth-cover-frame">
        <div
          ref={stageRef}
          className={cn(
            'native-oauth-cover-stage',
            ctaDone && 'native-oauth-cover-signed',
          )}
        >
          {!isSquareLayout ? (
            <>
              <div className="native-oauth-cover-abs native-oauth-cover-dots" />
              <div className="native-oauth-cover-abs native-oauth-cover-vignette" />
            </>
          ) : null}
          <div className="native-oauth-cover-abs native-oauth-cover-glow" />
          <div className="native-oauth-cover-abs native-oauth-cover-glow native-oauth-cover-glow-pink" />

          <div className="native-oauth-cover-phone-shell">
            <div className="native-oauth-cover-phone">
            <div
              ref={screenRef}
              className={cn(
                'native-oauth-cover-screen',
                sheetOpen && 'native-oauth-cover-open',
                rowsVisible && 'native-oauth-cover-rows',
              )}
            >
              <div className="native-oauth-cover-abs native-oauth-cover-island" />
              <div className="native-oauth-cover-abs native-oauth-cover-app">
                <div className="native-oauth-cover-icon">
                  <CheckIcon />
                </div>
                <div className="native-oauth-cover-bar native-oauth-cover-b1" />
                <div className="native-oauth-cover-bar native-oauth-cover-b2" />
                <div className="native-oauth-cover-bar native-oauth-cover-b3" />
                <div className="native-oauth-cover-pill">
                  <span className="native-oauth-cover-pill-dot" />
                  Session active
                </div>
              </div>
              <div className="native-oauth-cover-abs native-oauth-cover-scrim" />
              <div
                ref={sheetRef}
                className="native-oauth-cover-abs native-oauth-cover-sheet"
              >
                <div className="native-oauth-cover-grab" />
                <div className="native-oauth-cover-sheet-t">Choose an account</div>
                <div
                  ref={rowRef}
                  className={cn(
                    'native-oauth-cover-row',
                    rowSelected && 'native-oauth-cover-row-sel',
                  )}
                >
                  <div
                    className="native-oauth-cover-av native-oauth-cover-av-google bg-[#fafafa]"
                    aria-hidden
                  >
                    <img
                      src={GOOGLE_ICON_SRC}
                      alt=""
                      className="native-oauth-cover-provider-icon"
                      decoding="async"
                    />
                  </div>
                  <div className="native-oauth-cover-lines">
                    <div
                      className="native-oauth-cover-line"
                      style={{ width: '60%' }}
                    />
                    <div
                      className="native-oauth-cover-line native-oauth-cover-line-short"
                      style={{ width: '82%' }}
                    />
                  </div>
                  <div className="native-oauth-cover-tick">
                    <CheckIcon className="h-3 w-3" />
                  </div>
                </div>
                <div className="native-oauth-cover-row">
                  <div
                    className="native-oauth-cover-av native-oauth-cover-av-apple bg-[#141416]"
                    aria-hidden
                  >
                    <img
                      src={APPLE_ICON_SRC}
                      alt=""
                      className="native-oauth-cover-provider-icon"
                      decoding="async"
                    />
                  </div>
                  <div className="native-oauth-cover-lines">
                    <div
                      className="native-oauth-cover-line"
                      style={{ width: '52%' }}
                    />
                    <div
                      className="native-oauth-cover-line native-oauth-cover-line-short"
                      style={{ width: '74%' }}
                    />
                  </div>
                </div>
                <div
                  ref={ctaRef}
                  className={cn(
                    'native-oauth-cover-cta',
                    ctaPress && 'native-oauth-cover-cta-press',
                    ctaBusy && 'native-oauth-cover-cta-busy',
                    ctaDone && 'native-oauth-cover-cta-done',
                  )}
                >
                  <span className="native-oauth-cover-cta-s1">Continue</span>
                  <span className="native-oauth-cover-cta-s2">
                    <span className="native-oauth-cover-spin" />
                  </span>
                  <span className="native-oauth-cover-cta-s3">
                    <CheckIcon className="h-5 w-5" />
                    Signed in
                  </span>
                </div>
              </div>
              <div
                ref={tapRef}
                className={cn(
                  'native-oauth-cover-abs native-oauth-cover-tap',
                  tapGo && 'native-oauth-cover-tap-go',
                )}
              />
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
