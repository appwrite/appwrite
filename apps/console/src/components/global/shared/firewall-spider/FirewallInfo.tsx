import {
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
  type RefObject,
} from 'react'
import { Link } from '@tanstack/react-router'
import { Ban, Gauge, Radar, Volume2, VolumeX, X } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { HeadlessVideoPlayer } from '@/components/global/shared/HeadlessVideoPlayer'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const FIREWALL_PROMO_VIDEO = '/videos/firewall-trailer-faststart.mp4'

/** Inline so they win over the shared dialog's zoom and duration classes. */
const CONTENT_ENTER = {
  '--tw-animation-duration': '520ms',
  '--tw-ease': 'cubic-bezier(0.22, 1, 0.36, 1)',
  '--tw-enter-opacity': '0',
  '--tw-enter-scale': '0.97',
  '--tw-enter-translate-y': '12px',
} as CSSProperties

const CONTENT_EXIT = {
  '--tw-animation-duration': '240ms',
  '--tw-ease': 'cubic-bezier(0.4, 0, 1, 1)',
  '--tw-exit-opacity': '0',
  '--tw-exit-scale': '0.98',
  '--tw-exit-translate-y': '6px',
} as CSSProperties

/** Max drift before the glow jumps back to the main video's frame. */
const GLOW_SYNC_TOLERANCE_SECONDS = 0.12

/**
 * Blurred, brightened copy of the main video. The trailer is mostly black, so
 * it needs a strong brightness lift to read as light spill. Follows the main
 * video's time and play state so the glow matches what is on screen.
 */
function VideoGlow({
  sourceRef,
}: {
  sourceRef: RefObject<HTMLDivElement | null>
}) {
  const glowRef = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const timer = window.setInterval(() => {
      const glow = glowRef.current
      const source = sourceRef.current?.querySelector('video')
      if (!glow || !source || source.readyState < 2) return
      if (
        Math.abs(glow.currentTime - source.currentTime) >
        GLOW_SYNC_TOLERANCE_SECONDS
      ) {
        glow.currentTime = source.currentTime
      }
      if (source.paused && !glow.paused) glow.pause()
      if (!source.paused && glow.paused) void glow.play().catch(() => {})
    }, 250)
    return () => window.clearInterval(timer)
  }, [sourceRef])

  return (
    <video
      ref={glowRef}
      src={FIREWALL_PROMO_VIDEO}
      muted
      loop
      playsInline
      preload="auto"
      disablePictureInPicture
      className="absolute inset-0 size-full object-cover [filter:brightness(5)_saturate(1.8)]"
    />
  )
}

/** Fades the video in on its first frame instead of popping over the placeholder. */
function VideoFadeIn({ children }: { children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node) return
    if (node.querySelector('video')?.readyState) {
      setLoaded(true)
      return
    }
    // Media events don't bubble, so listen in the capture phase.
    const onLoaded = () => setLoaded(true)
    node.addEventListener('loadeddata', onLoaded, true)
    return () => node.removeEventListener('loadeddata', onLoaded, true)
  }, [])

  return (
    <div
      ref={ref}
      className={cn(
        'absolute inset-0 transition-opacity duration-700 ease-out',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
    >
      {children}
    </div>
  )
}

const HIGHLIGHTS: ReadonlyArray<{
  icon: LucideIcon
  title: string
  description: string
}> = [
  {
    icon: Ban,
    title: 'Deny crawlers and scrapers',
    description: 'Return a 403 to any request that matches your conditions.',
  },
  {
    icon: Gauge,
    title: 'Rate limit noisy clients',
    description:
      'Throttle each client IP and return a 429 once it goes over quota.',
  },
  {
    icon: Radar,
    title: 'Preview impact first',
    description:
      'See how much recent traffic a rule would match before you enable it.',
  },
]

export function FirewallInfo({
  open,
  onOpenChange,
  projectId,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
}) {
  const t = useT()
  const [soundOn, setSoundOn] = useState(false)
  const mediaRef = useRef<HTMLDivElement>(null)

  const handleOpenChange = (next: boolean) => {
    if (!next) setSoundOn(false)
    onOpenChange(next)
  }
  const close = () => handleOpenChange(false)
  const soundLabel = soundOn ? t('Turn off sound') : t('Turn on sound')

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton={false}
        disableAutoFocus
        overlayClassName={cn(
          'data-[state=open]:[--tw-animation-duration:480ms] data-[state=closed]:[--tw-animation-duration:260ms]',
          'data-[state=open]:[--tw-ease:cubic-bezier(0.22,1,0.36,1)] data-[state=closed]:[--tw-ease:cubic-bezier(0.4,0,1,1)]',
        )}
        className="gap-0 border-0 bg-transparent p-0 shadow-none sm:max-w-[560px]"
        style={open ? CONTENT_ENTER : CONTENT_EXIT}
      >
        {/* Blurring a rounded clip gives a soft squircle instead of an ellipse. */}
        <div
          aria-hidden
          className="pointer-events-none absolute -inset-x-10 -top-10 p-10 opacity-60 [filter:blur(56px)]"
        >
          <div className="aspect-video" />
          <div className="absolute inset-0 overflow-hidden rounded-[96px]">
            <VideoFadeIn>
              <VideoGlow sourceRef={mediaRef} />
            </VideoFadeIn>
          </div>
        </div>
        <div className="relative overflow-hidden rounded-lg border border-border bg-background shadow-lg">
          <div
            ref={mediaRef}
            className="relative aspect-video w-full overflow-hidden border-b border-border bg-muted"
          >
            <VideoFadeIn>
              <HeadlessVideoPlayer
                src={FIREWALL_PROMO_VIDEO}
                fit="cover"
                muted={!soundOn}
              />
            </VideoFadeIn>
            <button
              type="button"
              aria-label={t('Close')}
              className="absolute end-3 top-3 z-10 flex size-8 items-center justify-center rounded-md bg-background/80 text-muted-foreground backdrop-blur-sm transition-colors hover:bg-background hover:text-foreground"
              onClick={close}
            >
              <X className="size-4" aria-hidden />
            </button>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="absolute bottom-3 end-3 z-10 h-8 w-8 bg-card/95 p-0 backdrop-blur-sm"
                  aria-label={soundLabel}
                  {...analyticsAttrs('firewall-spider-promo-sound')}
                  onClick={() => setSoundOn((on) => !on)}
                >
                  {soundOn ? (
                    <Volume2 className="size-4" aria-hidden />
                  ) : (
                    <VolumeX className="size-4" aria-hidden />
                  )}
                </Button>
              </TooltipTrigger>
              <TooltipContent side="top">{soundLabel}</TooltipContent>
            </Tooltip>
          </div>

          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <div className="flex items-center gap-2">
              <DialogTitle>
                {t('Keep unwanted bots out of your app')}
              </DialogTitle>
              <Badge variant="info" className="text-[10px] shrink-0">
                {t('New')}
              </Badge>
            </div>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Deny, rate limit, redirect, or challenge requests before they hit your API, Functions, or Sites.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <ul className="space-y-3.5 px-6 py-5">
            {HIGHLIGHTS.map(({ icon: Icon, title, description }) => (
              <li key={title} className="flex items-start gap-3">
                <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  <Icon className="size-4" aria-hidden />
                </div>
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-foreground">
                    {t(title)}
                  </p>
                  <p className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
                    {t(description)}
                  </p>
                </div>
              </li>
            ))}
          </ul>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button asChild variant="outline">
              <Link
                to="/products/$productId"
                params={{ productId: 'firewall' }}
                {...analyticsAttrs('firewall-spider-promo-modal-learn-more')}
                onClick={close}
              >
                {t('Learn more')}
              </Link>
            </Button>
            <Button asChild>
              <Link
                to="/projects/$projectId/firewall"
                params={{ projectId }}
                {...analyticsAttrs('firewall-spider-promo-create-rule')}
                onClick={close}
              >
                {t('Create rule')}
              </Link>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
