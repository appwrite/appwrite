import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  getInitTicketHolderTitle,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'
export {
  getInitTicketHolderName,
  getInitTicketNumberForUser,
} from '@/lib/init/ticket-render-data'
import {
  INIT_TICKET_ASPECT_RATIO,
  INIT_TICKET_BOTTOM_TRIM_PERCENT,
  INIT_TICKET_MAX_WIDTH_PX,
  INIT_TICKET_STUB_LABEL_INSET,
  initTicketContentGridStyle,
  initTicketDisplayAspectRatio,
  initTicketHolderNameFontSizeClass,
  initTicketInsetStyle,
  initTicketStubTitleClass,
} from '@/lib/init/ticket-layout'
import type { ResolvedInitTicketAppearance } from '@/lib/init/ticket-types'
import {
  prefersInitTicketDeviceTilt,
  useInitTicketDeviceTilt,
} from '@/lib/init/use-init-ticket-device-tilt'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { getInitTicketStackOption } from '@/lib/init/ticket-stack'
import { getFrameworkIconFile } from '@/lib/frameworks'
import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'
import { scrambleSensitiveText } from '@/lib/scramble-sensitive-text'

const TILT_MAX_X = 22
const TILT_MAX_Y = 32
const TILT_DURATION_MS = 140
const FLIP_DURATION_MS = 720
const RESET_DURATION_MS = 520
const FLIP_DRAG_THRESHOLD_PX = 10

interface InitTicketCardProps {
  dateRangeLabel: string
  holderName: string
  githubUsername?: string
  ticketNumber: string
  prefs: InitTicketPrefs
  ticketAppearance: ResolvedInitTicketAppearance
  blurred?: boolean
  /** Disables flip/tilt (e.g. collapsed section preview). */
  previewOnly?: boolean
  /** Disables interaction while a share video is being captured. */
  captureMode?: boolean
  className?: string
}

export type InitTicketCardHandle = {
  prepareForVideoCapture: () => void
  setCaptureTilt: (x: number, y: number) => void
  resetCaptureTilt: () => void
}

interface TicketFaceSharedProps {
  dateRangeLabel: string
  holderName: string
  githubUsername?: string
  ticketNumber: string
  prefs: InitTicketPrefs
  passLabel: string
  holderTitle: string
  accentColor: string
  usesDarkImage: boolean
  ticketBgSrc: string
  inset: ReturnType<typeof initTicketInsetStyle>
}

function TicketGitHubBadge({
  username,
  usesDarkImage,
}: {
  username: string
  usesDarkImage: boolean
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-1.5',
        usesDarkImage ? 'text-white/75' : 'text-neutral-600',
      )}
    >
      <img
        src="/icons/github.svg"
        alt=""
        aria-hidden
        className={cn(
          'size-3.5 shrink-0 object-contain',
          usesDarkImage ? 'brightness-0 invert' : 'brightness-0',
        )}
      />
      <span className="truncate text-[clamp(10px,1.8vw,12px)] font-medium">
        @{username}
      </span>
    </div>
  )
}

function getInitTicketStackIconSrc(
  iconKey: string,
  usesDarkImage: boolean,
): string | null {
  if (iconKey === 'appwrite') {
    return `/icons/${usesDarkImage ? 'appwrite-white.svg' : 'appwrite.svg'}`
  }
  const iconFile = getFrameworkIconFile(iconKey)
  return iconFile ? `/icons/${iconFile}` : null
}

function TicketStackIcons({
  stack,
  usesDarkImage,
}: {
  stack: InitTicketPrefs['stack']
  usesDarkImage: boolean
}) {
  if (!stack.length) return null

  return (
    <div className="flex flex-wrap gap-x-3.5 gap-y-3">
      {stack.map((id) => {
        const option = getInitTicketStackOption(id)
        if (!option) return null
        const iconSrc = getInitTicketStackIconSrc(option.iconKey, usesDarkImage)

        return (
          <span
            key={id}
            className="flex items-center justify-center"
            title={option.label}
          >
            {iconSrc ? (
              <img
                src={iconSrc}
                alt=""
                aria-hidden
                className={cn(
                  'size-5 object-contain',
                  option.iconKey !== 'appwrite' &&
                    (usesDarkImage ? 'brightness-0 invert' : 'brightness-0'),
                )}
              />
            ) : (
              <Globe
                className={cn(
                  'size-5',
                  usesDarkImage ? 'text-white' : 'text-neutral-900',
                )}
                aria-hidden
              />
            )}
          </span>
        )
      })}
    </div>
  )
}

function TicketStubContent({
  ticketNumber,
  holderName,
  holderTitle,
  passLabel,
  dateRangeLabel,
  accentColor,
  usesDarkImage,
}: {
  ticketNumber: string
  holderName: string
  holderTitle: string
  passLabel: string
  dateRangeLabel: string
  accentColor: string
  usesDarkImage: boolean
}) {
  const mutedClass = usesDarkImage ? 'text-white/55' : 'text-neutral-500'
  const labelClass = usesDarkImage ? 'text-white/70' : 'text-neutral-600'
  const { left, right, bottom } = INIT_TICKET_STUB_LABEL_INSET

  return (
    <div className="relative h-full min-w-0" aria-hidden>
      <div
        className="absolute flex items-end justify-start"
        style={{
          left: `${left}%`,
          right: `${right}%`,
          bottom: `${bottom}%`,
        }}
      >
        <div className="flex origin-bottom-start -rotate-90 flex-col items-start gap-1 whitespace-nowrap text-start">
          <InitWordmark
            accentColor={accentColor}
            className={cn(
              'text-[clamp(10px,1.8vw,14px)]',
              usesDarkImage ? 'text-white' : 'text-neutral-900',
            )}
          />
          <p
            className="font-mono text-[clamp(9px,1.6vw,12px)] font-semibold tabular-nums tracking-wide"
            style={{ color: accentColor }}
          >
            {ticketNumber}
          </p>
          <p
            className={cn(
              'text-[clamp(11px,2vw,16px)] font-normal',
              labelClass,
            )}
          >
            {holderName}
          </p>
          <p
            className={cn(
              'font-medium leading-none',
              initTicketStubTitleClass(holderTitle),
              mutedClass,
            )}
          >
            {holderTitle}
          </p>
          <p
            className={cn(
              'text-[7px] font-semibold uppercase tracking-[0.22em]',
              mutedClass,
            )}
          >
            {passLabel}
          </p>
          <p
            className={cn(
              'text-[7px] font-semibold uppercase tracking-[0.14em]',
              mutedClass,
            )}
          >
            {dateRangeLabel}
          </p>
        </div>
      </div>
    </div>
  )
}

function TicketFaceShell({
  ticketBgSrc,
  inset,
  usesDarkImage,
  isBack,
  children,
}: {
  ticketBgSrc: string
  inset: ReturnType<typeof initTicketInsetStyle>
  usesDarkImage: boolean
  isBack?: boolean
  children: ReactNode
}) {
  const [loadedBackgroundSrc, setLoadedBackgroundSrc] = useState<string | null>(
    null,
  )
  const backgroundLoaded = loadedBackgroundSrc === ticketBgSrc

  return (
    <div
      className={cn(
        'absolute inset-0 w-full [backface-visibility:hidden] [transform-style:preserve-3d]',
        isBack && '[transform:rotateY(180deg)]',
      )}
    >
      <img
        key={ticketBgSrc}
        src={ticketBgSrc}
        alt=""
        className={cn(
          'pointer-events-none absolute inset-0 size-full object-contain object-top',
          backgroundLoaded ? 'opacity-100' : 'opacity-0',
        )}
        aria-hidden
        draggable={false}
        onLoad={() => setLoadedBackgroundSrc(ticketBgSrc)}
      />
      <div
        className={cn(
          'absolute inset-0 overflow-visible',
          backgroundLoaded ? 'opacity-100' : 'opacity-0',
          usesDarkImage ? 'text-white' : 'text-neutral-900',
        )}
        style={inset}
      >
        {children}
      </div>
    </div>
  )
}

function TicketFrontFace(props: TicketFaceSharedProps) {
  const {
    dateRangeLabel,
    holderName,
    githubUsername,
    ticketNumber,
    prefs,
    passLabel,
    holderTitle,
    accentColor,
    usesDarkImage,
  } = props

  const contentGrid = initTicketContentGridStyle()

  return (
    <TicketFaceShell {...props}>
      <div className="grid h-full min-h-0 overflow-visible" style={contentGrid}>
        <div className="flex min-w-0 flex-col justify-between pe-[8%]">
          <div className="space-y-2">
            <InitWordmark
              accentColor={accentColor}
              className={cn(
                'text-[clamp(24px,5vw,38px)]',
                usesDarkImage ? 'text-white' : 'text-neutral-900',
              )}
            />
            <p
              className={cn(
                'text-[clamp(10px,2vw,13px)] font-semibold uppercase tracking-[0.16em]',
                usesDarkImage ? 'text-white/60' : 'text-neutral-500',
              )}
            >
              {dateRangeLabel}
            </p>
          </div>

          <div className="space-y-4">
            <TicketStackIcons
              stack={prefs.stack}
              usesDarkImage={usesDarkImage}
            />
            <div
              className={cn(
                'space-y-2.5 border-t border-dashed pt-4',
                usesDarkImage ? 'border-white/20' : 'border-neutral-900/15',
              )}
            >
              <p
                className={cn(
                  'truncate font-normal leading-[1.02] tracking-tight',
                  initTicketHolderNameFontSizeClass(holderName),
                  usesDarkImage ? 'text-white' : 'text-neutral-900',
                )}
              >
                {holderName}
              </p>
              <p
                className={cn(
                  'truncate text-[clamp(12px,2.2vw,16px)] font-medium leading-relaxed',
                  usesDarkImage ? 'text-white/70' : 'text-neutral-600',
                )}
              >
                {holderTitle}
              </p>
              {githubUsername ? (
                <TicketGitHubBadge
                  username={githubUsername}
                  usesDarkImage={usesDarkImage}
                />
              ) : null}
              <p
                className={cn(
                  'text-[9px] font-semibold uppercase tracking-[0.2em]',
                  usesDarkImage ? 'text-white/55' : 'text-neutral-500',
                )}
              >
                {passLabel}
              </p>
              <p
                className="font-mono text-[9px] font-medium tabular-nums sm:text-[10px]"
                style={{ color: accentColor }}
              >
                {ticketNumber}
              </p>
            </div>
          </div>
        </div>

        <div className="min-w-0">
          <TicketStubContent
            ticketNumber={ticketNumber}
            holderName={holderName}
            holderTitle={holderTitle}
            passLabel={passLabel}
            dateRangeLabel={dateRangeLabel}
            accentColor={accentColor}
            usesDarkImage={usesDarkImage}
          />
        </div>
      </div>
    </TicketFaceShell>
  )
}

function TicketBackFace(props: TicketFaceSharedProps) {
  const {
    dateRangeLabel,
    holderName,
    githubUsername,
    ticketNumber,
    prefs,
    passLabel,
    holderTitle,
    accentColor,
    usesDarkImage,
  } = props
  const mutedClass = usesDarkImage ? 'text-white/55' : 'text-neutral-500'

  const contentGrid = initTicketContentGridStyle()

  return (
    <TicketFaceShell {...props} isBack>
      <div className="grid h-full min-h-0 overflow-visible" style={contentGrid}>
        <div className="flex min-w-0 flex-col justify-between pe-[8%]">
          <div className="space-y-2">
            <p
              className={cn(
                'text-[9px] font-semibold uppercase tracking-[0.24em]',
                mutedClass,
              )}
            >
              Official pass
            </p>
            <InitWordmark
              accentColor={accentColor}
              className={cn(
                'text-[clamp(22px,4.5vw,34px)]',
                usesDarkImage ? 'text-white' : 'text-neutral-900',
              )}
            />
            <p
              className="font-mono text-[11px] font-semibold tabular-nums sm:text-[12px]"
              style={{ color: accentColor }}
            >
              {ticketNumber}
            </p>
          </div>

          <div className="space-y-3">
            <div
              className="flex h-10 items-end justify-start gap-0.5 overflow-hidden"
              aria-hidden
            >
              {Array.from({ length: 24 }).map((_, index) => (
                <span
                  key={index}
                  className={cn(
                    'w-0.5 rounded-full',
                    usesDarkImage ? 'bg-white/30' : 'bg-neutral-900/25',
                  )}
                  style={{ height: `${28 + ((index * 17) % 40)}%` }}
                />
              ))}
            </div>
            <div
              className={cn(
                'space-y-1 border-t border-dashed pt-3',
                usesDarkImage ? 'border-white/20' : 'border-neutral-900/15',
              )}
            >
              <p
                className={cn(
                  'text-[9px] font-semibold uppercase tracking-[0.2em]',
                  mutedClass,
                )}
              >
                Valid for Init week
              </p>
              <p
                className={cn(
                  'text-[12px] font-medium',
                  usesDarkImage ? 'text-white/90' : 'text-neutral-800',
                )}
              >
                {dateRangeLabel}
              </p>
              <p
                className={cn(
                  'truncate text-[clamp(11px,1.9vw,15px)] font-normal leading-tight',
                  usesDarkImage ? 'text-white/90' : 'text-neutral-800',
                )}
              >
                {holderName}
              </p>
              <p
                className={cn(
                  'truncate text-[11px] font-medium',
                  usesDarkImage ? 'text-white/65' : 'text-neutral-500',
                )}
              >
                {holderTitle}
              </p>
              <p
                className={cn(
                  'text-[9px] font-semibold uppercase tracking-[0.2em]',
                  mutedClass,
                )}
              >
                {passLabel}
              </p>
              {githubUsername ? (
                <TicketGitHubBadge
                  username={githubUsername}
                  usesDarkImage={usesDarkImage}
                />
              ) : null}
              <TicketStackIcons
                stack={prefs.stack}
                usesDarkImage={usesDarkImage}
              />
            </div>
          </div>
        </div>
        <div aria-hidden />
      </div>
    </TicketFaceShell>
  )
}

export const InitTicketCard = forwardRef<
  InitTicketCardHandle,
  InitTicketCardProps
>(function InitTicketCard(
  {
    dateRangeLabel,
    holderName,
    githubUsername,
    ticketNumber,
    prefs,
    ticketAppearance,
    blurred = false,
    previewOnly = false,
    captureMode = false,
    className,
  },
  ref,
) {
  const captureRootRef = useRef<HTMLDivElement>(null)
  const ticketFrameRef = useRef<HTMLDivElement>(null)
  const interactive = !blurred && !previewOnly && !captureMode
  const {
    backgroundSrc: ticketBgSrc,
    usesDarkChrome: usesDarkImage,
    shadowClassName,
    shadowOffsetY,
    passLabel,
    holderTitle: defaultHolderTitle,
    accentColor,
  } = ticketAppearance
  const holderTitle = getInitTicketHolderTitle(prefs, defaultHolderTitle)

  // Scramble PII before CSS blur so real values are not recoverable from the DOM.
  const displayHolderName = useMemo(
    () => (blurred ? scrambleSensitiveText(holderName) : holderName),
    [blurred, holderName],
  )
  const displayGithubUsername = useMemo(() => {
    if (!githubUsername) return undefined
    return blurred ? scrambleSensitiveText(githubUsername) : githubUsername
  }, [blurred, githubUsername])
  const displayTicketNumber = useMemo(
    () => (blurred ? scrambleSensitiveText(ticketNumber) : ticketNumber),
    [blurred, ticketNumber],
  )

  const sceneRef = useRef<HTMLDivElement>(null)
  const flipperRef = useRef<HTMLDivElement>(null)
  const shadowRef = useRef<HTMLDivElement>(null)
  const tiltRef = useRef({ x: 0, y: 0 })
  const pointerTiltRef = useRef({ x: 0, y: 0 })
  const deviceTiltRef = useRef({ x: 0, y: 0 })
  const pointerTiltActiveRef = useRef(false)
  const flippedRef = useRef(false)
  const animatingRef = useRef(false)
  const tiltRafRef = useRef(0)
  const shadowOffsetYRef = useRef(shadowOffsetY)
  const pointerStartRef = useRef<{ x: number; y: number } | null>(null)
  const didDragRef = useRef(false)
  const touchActiveRef = useRef(false)
  const [isFlipped, setIsFlipped] = useState(false)
  const [reducedMotion, setReducedMotion] = useState(false)

  const deviceTiltEnabled =
    interactive && !reducedMotion && prefersInitTicketDeviceTilt()

  useEffect(() => {
    shadowOffsetYRef.current = shadowOffsetY
  }, [shadowOffsetY])

  const inset = initTicketInsetStyle()

  const faceProps: TicketFaceSharedProps = {
    dateRangeLabel,
    holderName: displayHolderName,
    githubUsername: displayGithubUsername,
    ticketNumber: displayTicketNumber,
    prefs,
    passLabel,
    holderTitle,
    accentColor,
    usesDarkImage,
    ticketBgSrc,
    inset,
  }

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const update = () => setReducedMotion(media.matches)
    update()
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])

  const applyTransform = useCallback(
    (duration = TILT_DURATION_MS) => {
      const flipper = flipperRef.current
      const shadow = shadowRef.current
      if (!flipper || reducedMotion) return

      let rotateY = (flippedRef.current ? 180 : 0) + tiltRef.current.y
      let rotateX = tiltRef.current.x

      if (captureMode && duration === 0) {
        rotateY = Math.round(rotateY * 4) / 4
        rotateX = Math.round(rotateX * 4) / 4
      }

      const easing =
        duration >= FLIP_DURATION_MS
          ? 'cubic-bezier(0.16, 1, 0.3, 1)'
          : 'cubic-bezier(0.25, 0.46, 0.45, 0.94)'

      const transition =
        duration === 0 ? 'none' : `transform ${duration}ms ${easing}`
      flipper.style.transition = transition
      flipper.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`

      if (shadow) {
        const translateX = tiltRef.current.y * 0.55
        const translateY =
          shadowOffsetYRef.current + Math.abs(tiltRef.current.x) * 0.08
        const scale = 1 - Math.abs(tiltRef.current.x) * 0.008
        shadow.style.transition = transition
        shadow.style.transform = `translate3d(${translateX}px, ${translateY}px, 0) scale(${scale})`
      }
    },
    [captureMode, reducedMotion],
  )

  const scheduleTiltApply = useCallback(
    (duration = TILT_DURATION_MS) => {
      if (tiltRafRef.current) {
        cancelAnimationFrame(tiltRafRef.current)
      }
      tiltRafRef.current = window.requestAnimationFrame(() => {
        tiltRafRef.current = 0
        applyTransform(duration)
      })
    },
    [applyTransform],
  )

  const syncEffectiveTilt = useCallback(
    (duration = TILT_DURATION_MS) => {
      tiltRef.current = pointerTiltActiveRef.current
        ? pointerTiltRef.current
        : deviceTiltRef.current
      scheduleTiltApply(duration)
    },
    [scheduleTiltApply],
  )

  const { startListening: startDeviceTilt, listening: deviceTiltListening } =
    useInitTicketDeviceTilt({
      enabled: deviceTiltEnabled,
      maxTiltX: TILT_MAX_X,
      maxTiltY: TILT_MAX_Y,
      onTiltChange: (tilt) => {
        deviceTiltRef.current = tilt
        if (!pointerTiltActiveRef.current && !animatingRef.current) {
          syncEffectiveTilt(TILT_DURATION_MS)
        }
      },
    })

  useEffect(() => {
    if (!deviceTiltEnabled) return

    const Orientation =
      DeviceOrientationEvent as typeof DeviceOrientationEvent & {
        requestPermission?: () => Promise<PermissionState>
      }
    if (typeof Orientation.requestPermission !== 'function') {
      void startDeviceTilt()
    }
  }, [deviceTiltEnabled, startDeviceTilt])

  const computeTiltAtClientCoords = useCallback(
    (clientX: number, clientY: number) => {
      const scene = sceneRef.current
      if (!scene) return null

      const rect = scene.getBoundingClientRect()
      if (rect.width <= 0 || rect.height <= 0) return null

      const px = (clientX - rect.left) / rect.width - 0.5
      const py = (clientY - rect.top) / rect.height - 0.5

      return {
        x: -py * TILT_MAX_X,
        y: px * TILT_MAX_Y,
      }
    },
    [],
  )

  const applyMouseHoverTilt = useCallback(
    (clientX: number, clientY: number) => {
      if (deviceTiltEnabled) return

      const tilt = computeTiltAtClientCoords(clientX, clientY)
      if (!tilt) return

      pointerTiltActiveRef.current = false
      tiltRef.current = tilt
      scheduleTiltApply(TILT_DURATION_MS)
    },
    [computeTiltAtClientCoords, deviceTiltEnabled, scheduleTiltApply],
  )

  const applyPointerTilt = useCallback(
    (clientX: number, clientY: number) => {
      if (deviceTiltEnabled) return

      const tilt = computeTiltAtClientCoords(clientX, clientY)
      if (!tilt) return

      applyMouseHoverTilt(clientX, clientY)
    },
    [applyMouseHoverTilt, computeTiltAtClientCoords, deviceTiltEnabled],
  )

  const updateTiltFromPointer = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      applyPointerTilt(event.clientX, event.clientY)
    },
    [applyPointerTilt],
  )

  const releaseMouseHoverTilt = useCallback(() => {
    pointerTiltActiveRef.current = false
    pointerTiltRef.current = { x: 0, y: 0 }
    if (deviceTiltEnabled && deviceTiltListening) {
      syncEffectiveTilt(TILT_DURATION_MS)
      return
    }
    tiltRef.current = { x: 0, y: 0 }
    scheduleTiltApply(RESET_DURATION_MS)
  }, [
    deviceTiltEnabled,
    deviceTiltListening,
    scheduleTiltApply,
    syncEffectiveTilt,
  ])

  const releasePointerTilt = releaseMouseHoverTilt

  const applyMouseHoverTiltRef = useRef(applyMouseHoverTilt)
  const releaseMouseHoverTiltRef = useRef(releaseMouseHoverTilt)

  useEffect(() => {
    applyMouseHoverTiltRef.current = applyMouseHoverTilt
  }, [applyMouseHoverTilt])

  useEffect(() => {
    releaseMouseHoverTiltRef.current = releaseMouseHoverTilt
  }, [releaseMouseHoverTilt])

  useEffect(() => {
    if (!interactive || reducedMotion) return

    let mouseHovering = false

    const isPointerOverScene = (clientX: number, clientY: number) => {
      const scene = sceneRef.current
      if (!scene) return false

      const target = document.elementFromPoint(clientX, clientY)
      if (target && (target === scene || scene.contains(target))) return true

      const rect = scene.getBoundingClientRect()
      return (
        clientX >= rect.left &&
        clientX <= rect.right &&
        clientY >= rect.top &&
        clientY <= rect.bottom
      )
    }

    const handleWindowMouseMove = (event: MouseEvent) => {
      if (animatingRef.current || touchActiveRef.current) return

      if (!isPointerOverScene(event.clientX, event.clientY)) {
        if (mouseHovering) {
          mouseHovering = false
          pointerStartRef.current = null
          releaseMouseHoverTiltRef.current()
        }
        return
      }

      mouseHovering = true
      applyMouseHoverTiltRef.current(event.clientX, event.clientY)
    }

    window.addEventListener('mousemove', handleWindowMouseMove, {
      passive: true,
    })
    return () => window.removeEventListener('mousemove', handleWindowMouseMove)
  }, [interactive, reducedMotion])

  const handleSceneMouseMove = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      if (
        !interactive ||
        reducedMotion ||
        animatingRef.current ||
        touchActiveRef.current
      ) {
        return
      }
      applyMouseHoverTilt(event.clientX, event.clientY)
    },
    [applyMouseHoverTilt, interactive, reducedMotion],
  )

  const handleSceneMouseLeave = useCallback(() => {
    if (!interactive || reducedMotion || touchActiveRef.current) return
    pointerStartRef.current = null
    releaseMouseHoverTilt()
  }, [interactive, reducedMotion, releaseMouseHoverTilt])

  const handlePointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion || animatingRef.current) return

      // Mouse hover uses onMouseMove + window mousemove (pointermove is unreliable on scaled cards).
      if (event.pointerType === 'mouse') return

      const isTouch = event.pointerType === 'touch'
      const isHoverPointer =
        event.pointerType !== 'touch' && event.buttons === 0

      if (!isTouch && !isHoverPointer && event.buttons === 0) return

      if (pointerStartRef.current) {
        const dx = event.clientX - pointerStartRef.current.x
        const dy = event.clientY - pointerStartRef.current.y
        if (Math.hypot(dx, dy) > FLIP_DRAG_THRESHOLD_PX) {
          didDragRef.current = true
        }
      }

      updateTiltFromPointer(event)
    },
    [interactive, reducedMotion, updateTiltFromPointer],
  )

  useEffect(() => {
    return () => {
      if (tiltRafRef.current) {
        window.cancelAnimationFrame(tiltRafRef.current)
      }
    }
  }, [])

  const endTouchInteraction = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (event.currentTarget.hasPointerCapture(event.pointerId)) {
        event.currentTarget.releasePointerCapture(event.pointerId)
      }
      touchActiveRef.current = false
      pointerStartRef.current = null
      releasePointerTilt()
    },
    [releasePointerTilt],
  )

  const handlePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion) return

      pointerStartRef.current = { x: event.clientX, y: event.clientY }
      didDragRef.current = false

      if (deviceTiltEnabled) {
        void startDeviceTilt()
      }

      if (event.pointerType === 'touch') {
        touchActiveRef.current = true
        event.currentTarget.setPointerCapture(event.pointerId)
        if (!deviceTiltEnabled) {
          updateTiltFromPointer(event)
        }
      }
    },
    [
      deviceTiltEnabled,
      interactive,
      reducedMotion,
      startDeviceTilt,
      updateTiltFromPointer,
    ],
  )

  const handlePointerUp = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion) return
      if (event.pointerType === 'touch') {
        endTouchInteraction(event)
      }
    },
    [endTouchInteraction, interactive, reducedMotion],
  )

  const handlePointerCancel = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion) return
      if (event.pointerType === 'touch') {
        endTouchInteraction(event)
      }
    },
    [endTouchInteraction, interactive, reducedMotion],
  )

  const handlePointerLeave = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion || touchActiveRef.current) return
      if (event.pointerType === 'touch' || event.pointerType === 'mouse') return
      pointerStartRef.current = null
      releasePointerTilt()
    },
    [interactive, reducedMotion, releasePointerTilt],
  )

  const handleFlip = useCallback(() => {
    if (!interactive || reducedMotion) return

    const nextFlipped = !flippedRef.current
    flippedRef.current = nextFlipped
    setIsFlipped(nextFlipped)
    animatingRef.current = true
    applyTransform(FLIP_DURATION_MS)
    window.setTimeout(() => {
      animatingRef.current = false
    }, FLIP_DURATION_MS)
  }, [applyTransform, interactive, reducedMotion])

  const handleClick = useCallback(() => {
    if (!interactive || reducedMotion || didDragRef.current) return
    handleFlip()
  }, [handleFlip, interactive, reducedMotion])

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLDivElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault()
        handleFlip()
      }
    },
    [handleFlip],
  )

  useImperativeHandle(
    ref,
    () => ({
      prepareForVideoCapture() {
        flippedRef.current = false
        setIsFlipped(false)
        pointerTiltActiveRef.current = false
        tiltRef.current = { x: 0, y: 0 }
        applyTransform(0)
      },
      setCaptureTilt(x: number, y: number) {
        pointerTiltActiveRef.current = false
        tiltRef.current = { x, y }
        applyTransform(0)
      },
      resetCaptureTilt() {
        pointerTiltActiveRef.current = false
        tiltRef.current = { x: 0, y: 0 }
        applyTransform(0)
      },
    }),
    [applyTransform],
  )

  return (
    <div
      ref={captureRootRef}
      className={cn(
        'relative mx-auto w-full select-none',
        blurred && 'blur-[6px]',
        captureMode && 'pointer-events-none overflow-visible antialiased',
        className,
      )}
      style={{ maxWidth: INIT_TICKET_MAX_WIDTH_PX }}
      aria-hidden={blurred}
    >
      <div
        ref={ticketFrameRef}
        className={cn('relative w-full', captureMode && 'overflow-visible')}
        style={{
          aspectRatio: initTicketDisplayAspectRatio(),
        }}
      >
        <div
          ref={shadowRef}
          data-init-ticket-capture-exclude
          className={cn(
            'absolute inset-x-10 bottom-0 h-6 -translate-y-0.5 rounded-full blur-3xl',
            shadowClassName,
          )}
          aria-hidden
        />

        <div
          ref={sceneRef}
          className={cn(
            'absolute inset-x-0 top-0 w-full [perspective:1000px]',
            captureMode && 'overflow-visible',
            interactive && !reducedMotion && 'cursor-pointer touch-none',
          )}
          style={{
            aspectRatio: INIT_TICKET_ASPECT_RATIO,
            ...(captureMode
              ? {}
              : {
                  clipPath: `inset(0 0 ${INIT_TICKET_BOTTOM_TRIM_PERCENT}% 0)`,
                }),
          }}
          onMouseMove={
            interactive && !reducedMotion ? handleSceneMouseMove : undefined
          }
          onMouseLeave={
            interactive && !reducedMotion ? handleSceneMouseLeave : undefined
          }
          onPointerDown={
            interactive && !reducedMotion ? handlePointerDown : undefined
          }
          onPointerMove={
            interactive && !reducedMotion ? handlePointerMove : undefined
          }
          onPointerUp={
            interactive && !reducedMotion ? handlePointerUp : undefined
          }
          onPointerCancel={
            interactive && !reducedMotion ? handlePointerCancel : undefined
          }
          onPointerLeave={
            interactive && !reducedMotion ? handlePointerLeave : undefined
          }
          onClick={interactive && !reducedMotion ? handleClick : undefined}
          onKeyDown={interactive && !reducedMotion ? handleKeyDown : undefined}
          role={interactive && !reducedMotion ? 'button' : undefined}
          tabIndex={interactive && !reducedMotion ? 0 : undefined}
          aria-pressed={interactive && !reducedMotion ? isFlipped : undefined}
          aria-label={
            interactive && !reducedMotion
              ? isFlipped
                ? 'Init ticket back, click to flip to front'
                : 'Init ticket front, click to flip to back'
              : undefined
          }
        >
          <div
            ref={flipperRef}
            className={cn(
              'absolute inset-0 [transform-style:preserve-3d]',
              captureMode && 'overflow-visible',
            )}
          >
            <TicketFrontFace {...faceProps} />
            {!captureMode ? <TicketBackFace {...faceProps} /> : null}
          </div>
        </div>
      </div>
    </div>
  )
})
