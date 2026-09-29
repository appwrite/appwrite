import { useEffect, useMemo, useRef, useState } from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { Link, useLocation, useParams } from '@tanstack/react-router'
import { Shield, Volume2, VolumeX } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { HeadlessVideoPlayer } from '@/components/global/shared/HeadlessVideoPlayer'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  FIREWALL_PROMO_BANNER_ENABLED,
  FIREWALL_PROMO_BANNER_ID,
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFirewallPromoPath,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { useT } from '@/lib/i18n/translate'
import { useAppChromeHeight } from '@/lib/layout/use-app-chrome-height'
import type { OperatorAccount } from '@/lib/operator-account'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'

const FIREWALL_PROMO_BANNER = getConsoleBannerById(FIREWALL_PROMO_BANNER_ID)!

const FIREWALL_PROMO_VIDEO_HLS = '/videos/firewall-trailer/index.m3u8'
const FIREWALL_PROMO_VIDEO_MP4_FALLBACK = '/videos/firewall-trailer.mp4'

/** Let the page settle before taking over the screen. */
const FIREWALL_PROMO_OPEN_DELAY_MS = 800

export function FirewallPromoBanner() {
  const t = useT()
  const location = useLocation()
  const { projectId } = useParams({ strict: false })
  const { account, isAuthenticated } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [closed, setClosed] = useState(false)
  const [ready, setReady] = useState(false)
  const [soundOn, setSoundOn] = useState(false)
  const skipButtonRef = useRef<HTMLButtonElement | null>(null)

  const preview = isPreviewEnabled(FIREWALL_PROMO_BANNER_ID)
  const dismissedFromPrefs = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        FIREWALL_PROMO_BANNER_ID,
      ),
    [account?.prefs],
  )

  const operatorAccount = account as OperatorAccount | undefined
  const canImpersonate =
    operatorAccount?.impersonator === true &&
    !operatorAccount?.impersonatorUserId

  const eligible =
    (FIREWALL_PROMO_BANNER_ENABLED || preview) &&
    isConsoleBannerVisible(FIREWALL_PROMO_BANNER, {
      preview,
      dismissed: dismissedFromPrefs,
    }) &&
    !isScreenshotModeActive &&
    (preview ||
      (isAuthenticated &&
        canImpersonate &&
        isFirewallPromoPath(location.pathname)))

  useEffect(() => {
    if (!eligible) {
      setReady(false)
      return
    }
    const timer = window.setTimeout(
      () => setReady(true),
      FIREWALL_PROMO_OPEN_DELAY_MS,
    )
    return () => window.clearTimeout(timer)
  }, [eligible])

  useEffect(() => {
    if (preview) setClosed(false)
  }, [preview])

  const open = ready && !closed

  useEffect(() => {
    if (!open) setSoundOn(false)
  }, [open])

  const chromeHeight = useAppChromeHeight(ready)

  if (!eligible || !ready) return null

  const dismiss = () => {
    setClosed(true)
    if (isAuthenticated) {
      dismissBanner.mutate(FIREWALL_PROMO_BANNER_ID)
    }
  }

  const description = t(
    'Deny, rate limit, redirect, or challenge requests before they hit your API, Functions, or Sites.',
  )

  return (
    // Non-modal so the app header stays usable without dismissing the promo.
    <DialogPrimitive.Root modal={false} open={open}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Content
          onInteractOutside={(event) => event.preventDefault()}
          onPointerDownOutside={(event) => event.preventDefault()}
          onFocusOutside={(event) => event.preventDefault()}
          onEscapeKeyDown={(event) => {
            event.preventDefault()
            dismiss()
          }}
          onOpenAutoFocus={(event) => {
            event.preventDefault()
            skipButtonRef.current?.focus({ preventScroll: true })
          }}
          style={{
            top: chromeHeight,
            height: `calc(100dvh - ${chromeHeight}px)`,
          }}
          className="fixed inset-x-0 bottom-0 z-[100] flex w-full flex-col overflow-hidden bg-background text-foreground outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 duration-500"
        >
          <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden p-6 sm:p-8 lg:p-10">
            <div
              aria-hidden
              className="pointer-events-none absolute inset-0 bg-gradient-to-b from-muted/20 via-background/40 to-background"
            />
            <div
              className="relative z-[1] min-h-0 w-full flex-1"
              style={{ containerType: 'size' }}
            >
              <div
                className="absolute left-1/2 top-1/2 isolate overflow-hidden rounded-2xl border-2 border-muted-foreground/8 bg-muted-foreground/[0.035] p-2 backdrop-blur-sm dark:border-muted/30 dark:bg-muted/10 sm:rounded-3xl sm:p-3"
                style={{
                  width: 'min(100cqw, calc(100cqh * 16 / 9))',
                  height: 'min(100cqh, calc(100cqw * 9 / 16))',
                  transform: 'translate(-50%, -50%)',
                }}
              >
                <div className="relative size-full overflow-hidden rounded-xl sm:rounded-2xl">
                  <HeadlessVideoPlayer
                    src={FIREWALL_PROMO_VIDEO_HLS}
                    fallbackSrc={FIREWALL_PROMO_VIDEO_MP4_FALLBACK}
                    fit="cover"
                    muted={!soundOn}
                    className="rounded-xl sm:rounded-2xl"
                  />
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="absolute bottom-3 end-3 z-10 h-8 w-8 bg-card/95 p-0 backdrop-blur-sm"
                        aria-label={
                          soundOn
                            ? t('Turn off sound')
                            : t('Turn on sound')
                        }
                        {...analyticsAttrs('firewall-promo-banner-sound')}
                        onClick={() => setSoundOn((on) => !on)}
                      >
                        {soundOn ? (
                          <Volume2 className="size-4" aria-hidden />
                        ) : (
                          <VolumeX className="size-4" aria-hidden />
                        )}
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="top">
                      {soundOn ? t('Turn off sound') : t('Turn on sound')}
                    </TooltipContent>
                  </Tooltip>
                </div>
              </div>
            </div>
          </div>

          <div className="relative z-20 shrink-0 border-t border-border bg-background">
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6 sm:py-4">
              <div className="flex min-w-0 items-center gap-3">
                <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  <Shield className="size-4" aria-hidden />
                </div>
                <div className="min-w-0">
                  <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <DialogPrimitive.Title className="truncate text-[13px] font-medium text-foreground">
                      Appwrite Firewall
                    </DialogPrimitive.Title>
                    <Badge variant="info" className="shrink-0 text-[10px]">
                      {t('Included on every plan')}
                    </Badge>
                  </div>
                  <p className="truncate text-[12px] text-muted-foreground">
                    {t(
                      'Project rules that run before traffic reaches your API, Functions, or Sites.',
                    )}
                  </p>
                  <DialogPrimitive.Description className="sr-only">
                    {description}
                  </DialogPrimitive.Description>
                </div>
              </div>

              <div className="flex shrink-0 items-center gap-2">
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className="h-8 text-[13px] text-muted-foreground"
                >
                  <Link
                    to="/products/$productId"
                    params={{ productId: 'firewall' }}
                    {...analyticsAttrs(FIREWALL_PROMO_BANNER.event)}
                    onClick={dismiss}
                  >
                    {t('Learn more')}
                  </Link>
                </Button>
                {projectId ? (
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="h-8 text-[13px]"
                  >
                    <Link
                      to="/projects/$projectId/firewall"
                      params={{ projectId }}
                      {...analyticsAttrs('firewall-promo-banner-open-firewall')}
                      onClick={dismiss}
                    >
                      {t('Open Firewall')}
                    </Link>
                  </Button>
                ) : null}
                <Button
                  ref={skipButtonRef}
                  type="button"
                  size="sm"
                  className="h-8 gap-1.5 text-[13px]"
                  {...analyticsAttrs('firewall-promo-banner-skip')}
                  onClick={dismiss}
                >
                  {t('Skip to console')}
                  <kbd className="hidden rounded bg-primary-foreground/15 px-1.5 py-0.5 text-[10px] font-medium leading-none sm:inline">
                    esc
                  </kbd>
                </Button>
              </div>
            </div>
          </div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
