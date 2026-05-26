import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import {
  formatInitTicketNumber,
  getInitTicketHolderTitle,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'
import {
  INIT_TICKET_ASPECT_RATIO,
  INIT_TICKET_BOTTOM_TRIM_PERCENT,
  INIT_TICKET_MAX_WIDTH_PX,
  INIT_TICKET_STUB_LABEL_INSET,
  initTicketContentGridStyle,
  initTicketDisplayAspectRatio,
  initTicketInsetStyle,
} from '@/lib/init/ticket-layout'
import type { ResolvedInitTicketAppearance } from '@/lib/init/ticket-types'
import { getInitTicketStackOption } from '@/lib/init/ticket-stack'
import { getFrameworkIconFile } from '@/lib/frameworks'
import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'

const TILT_MAX_X = 22
const TILT_MAX_Y = 32
const TILT_DURATION_MS = 140
const FLIP_DURATION_MS = 720
const RESET_DURATION_MS = 520

interface InitTicketCardProps {
  eventName: string
  dateRangeLabel: string
  holderName: string
  githubUsername?: string
  ticketNumber: string
  prefs: InitTicketPrefs
  ticketAppearance: ResolvedInitTicketAppearance
  blurred?: boolean
  /** Disables flip/tilt (e.g. collapsed section preview). */
  previewOnly?: boolean
  className?: string
}

interface TicketFaceSharedProps {
  eventName: string
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
    <div className="flex flex-wrap gap-2.5">
      {stack.map((id) => {
        const option = getInitTicketStackOption(id)
        if (!option) return null
        const iconSrc = getInitTicketStackIconSrc(option.iconKey, usesDarkImage)

        return (
          <span key={id} className="flex items-center justify-center" title={option.label}>
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
  eventName,
  ticketNumber,
  holderName,
  holderTitle,
  passLabel,
  dateRangeLabel,
  accentColor,
  usesDarkImage,
}: {
  eventName: string
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
    <div className="relative h-full min-w-0 overflow-visible" aria-hidden>
      <div
        className="absolute flex items-end justify-start overflow-visible"
        style={{
          left: `${left}%`,
          right: `${right}%`,
          bottom: `${bottom}%`,
        }}
      >
        <div className="flex origin-bottom-left -rotate-90 flex-col items-start gap-1.5 whitespace-nowrap text-left">
          <p className="font-aeonik-pro text-[clamp(10px,1.8vw,14px)] font-bold italic leading-none">
            {eventName}
            <span style={{ color: accentColor }}>_</span>
          </p>
          <p
            className="font-mono text-[clamp(9px,1.6vw,12px)] font-semibold tabular-nums tracking-wide"
            style={{ color: accentColor }}
          >
            {ticketNumber}
          </p>
          <p
            className={cn(
              'max-w-[140px] truncate text-[clamp(8px,1.4vw,11px)] font-medium',
              labelClass,
            )}
          >
            {holderName}
          </p>
          <p
            className={cn(
              'max-w-[140px] truncate text-[clamp(7px,1.2vw,10px)] font-medium',
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
  return (
    <div
      className={cn(
        'absolute inset-0 w-full [backface-visibility:hidden] [transform-style:preserve-3d]',
        isBack && '[transform:rotateY(180deg)]',
      )}
    >
      <img
        src={ticketBgSrc}
        alt=""
        className="pointer-events-none absolute inset-0 size-full object-contain object-top"
        aria-hidden
        draggable={false}
      />
      <div
        className={cn(
          'absolute inset-0 overflow-visible',
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
    eventName,
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
            <p className="font-aeonik-pro text-[clamp(24px,5vw,38px)] font-bold italic leading-none">
              {eventName}
              <span style={{ color: accentColor }}>_</span>
            </p>
            <p
              className={cn(
                'text-[clamp(10px,2vw,13px)] font-semibold uppercase tracking-[0.16em]',
                usesDarkImage ? 'text-white/60' : 'text-neutral-500',
              )}
            >
              {dateRangeLabel}
            </p>
          </div>

          <div className="space-y-3">
            <TicketStackIcons stack={prefs.stack} usesDarkImage={usesDarkImage} />
            <div
              className={cn(
                'space-y-1.5 border-t border-dashed pt-3',
                usesDarkImage ? 'border-white/20' : 'border-neutral-900/15',
              )}
            >
              <p
                className={cn(
                  'truncate text-[clamp(14px,2.8vw,20px)] font-medium leading-tight',
                  usesDarkImage ? 'text-white/95' : 'text-neutral-900',
                )}
              >
                {holderName}
              </p>
              <p
                className={cn(
                  'truncate text-[clamp(11px,2vw,14px)] font-medium leading-snug',
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

        <TicketStubContent
          eventName={eventName}
          ticketNumber={ticketNumber}
          holderName={holderName}
          holderTitle={holderTitle}
          passLabel={passLabel}
          dateRangeLabel={dateRangeLabel}
          accentColor={accentColor}
          usesDarkImage={usesDarkImage}
        />
      </div>
    </TicketFaceShell>
  )
}

function TicketBackFace(props: TicketFaceSharedProps) {
  const {
    eventName,
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
          <p className="font-aeonik-pro text-[clamp(22px,4.5vw,34px)] font-bold italic leading-none">
            {eventName}
            <span style={{ color: accentColor }}>_</span>
          </p>
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
                'truncate text-[11px]',
                usesDarkImage ? 'text-white/60' : 'text-neutral-500',
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
            <TicketStackIcons stack={prefs.stack} usesDarkImage={usesDarkImage} />
          </div>
        </div>
        </div>
        <div aria-hidden />
      </div>
    </TicketFaceShell>
  )
}

export function InitTicketCard({
  eventName,
  dateRangeLabel,
  holderName,
  githubUsername,
  ticketNumber,
  prefs,
  ticketAppearance,
  blurred = false,
  previewOnly = false,
  className,
}: InitTicketCardProps) {
  const interactive = !blurred && !previewOnly
  const { backgroundSrc: ticketBgSrc, usesDarkChrome: usesDarkImage, shadowClassName, shadowOffsetY, passLabel, holderTitle: defaultHolderTitle, accentColor } =
    ticketAppearance
  const holderTitle = getInitTicketHolderTitle(prefs, defaultHolderTitle)

  const sceneRef = useRef<HTMLDivElement>(null)
  const flipperRef = useRef<HTMLDivElement>(null)
  const shadowRef = useRef<HTMLDivElement>(null)
  const tiltRef = useRef({ x: 0, y: 0 })
  const flippedRef = useRef(false)
  const animatingRef = useRef(false)
  const tiltRafRef = useRef(0)
  const shadowOffsetYRef = useRef(shadowOffsetY)
  const [isFlipped, setIsFlipped] = useState(false)
  const [reducedMotion, setReducedMotion] = useState(false)

  useEffect(() => {
    shadowOffsetYRef.current = shadowOffsetY
  }, [shadowOffsetY])

  const inset = initTicketInsetStyle()

  const faceProps: TicketFaceSharedProps = {
    eventName,
    dateRangeLabel,
    holderName,
    githubUsername,
    ticketNumber,
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

      const rotateY = (flippedRef.current ? 180 : 0) + tiltRef.current.y
      const rotateX = tiltRef.current.x

      const easing =
        duration >= FLIP_DURATION_MS
          ? 'cubic-bezier(0.16, 1, 0.3, 1)'
          : 'cubic-bezier(0.25, 0.46, 0.45, 0.94)'

      flipper.style.transition = `transform ${duration}ms ${easing}`
      flipper.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`

      if (shadow) {
        const translateX = tiltRef.current.y * 0.55
        const translateY =
          shadowOffsetYRef.current + Math.abs(tiltRef.current.x) * 0.08
        const scale = 1 - Math.abs(tiltRef.current.x) * 0.008
        shadow.style.transition = `transform ${duration}ms ${easing}`
        shadow.style.transform = `translate3d(${translateX}px, ${translateY}px, 0) scale(${scale})`
      }
    },
    [reducedMotion],
  )

  const handlePointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!interactive || reducedMotion || animatingRef.current) return
      const scene = sceneRef.current
      if (!scene) return

      const rect = scene.getBoundingClientRect()
      const px = (event.clientX - rect.left) / rect.width - 0.5
      const py = (event.clientY - rect.top) / rect.height - 0.5

      tiltRef.current = {
        x: -py * TILT_MAX_X,
        y: px * TILT_MAX_Y,
      }

      if (tiltRafRef.current) return
      tiltRafRef.current = window.requestAnimationFrame(() => {
        tiltRafRef.current = 0
        applyTransform(TILT_DURATION_MS)
      })
    },
    [applyTransform, interactive, reducedMotion],
  )

  useEffect(() => {
    return () => {
      if (tiltRafRef.current) {
        window.cancelAnimationFrame(tiltRafRef.current)
      }
    }
  }, [])

  const handlePointerLeave = useCallback(() => {
    if (!interactive || reducedMotion) return
    tiltRef.current = { x: 0, y: 0 }
    applyTransform(RESET_DURATION_MS)
  }, [applyTransform, interactive, reducedMotion])

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

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLDivElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault()
        handleFlip()
      }
    },
    [handleFlip],
  )

  return (
    <div
      className={cn(
        'relative mx-auto w-full',
        blurred && 'select-none blur-[6px]',
        className,
      )}
      style={{ maxWidth: INIT_TICKET_MAX_WIDTH_PX }}
      aria-hidden={blurred}
    >
      <div
        className="relative w-full"
        style={{ aspectRatio: initTicketDisplayAspectRatio() }}
      >
        <div
          ref={shadowRef}
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
            interactive && !reducedMotion && 'cursor-pointer',
          )}
          style={{
            aspectRatio: INIT_TICKET_ASPECT_RATIO,
            clipPath: `inset(0 0 ${INIT_TICKET_BOTTOM_TRIM_PERCENT}% 0)`,
          }}
          onPointerMove={interactive && !reducedMotion ? handlePointerMove : undefined}
          onPointerLeave={interactive && !reducedMotion ? handlePointerLeave : undefined}
          onClick={interactive && !reducedMotion ? handleFlip : undefined}
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
            className="absolute inset-0 [transform-style:preserve-3d]"
          >
            <TicketFrontFace {...faceProps} />
            <TicketBackFace {...faceProps} />
          </div>
        </div>
      </div>
    </div>
  )
}

export function getInitTicketHolderName(
  accountName: string | undefined,
  prefs: InitTicketPrefs,
  fallback = 'Your name',
): string {
  return prefs.displayName?.trim() || accountName?.trim() || fallback
}

export function getInitTicketNumberForUser(userId?: string | null): string {
  return formatInitTicketNumber(userId)
}
