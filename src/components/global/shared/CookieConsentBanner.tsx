import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useReducedMotion } from 'motion/react'
import { Cookie } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { getMarketingPageUrl } from '@/lib/marketing/urls'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const BANNER_ENTER_MS = 300
const BANNER_EXIT_MS = 200

type CookieConsentBannerProps = {
  open: boolean
  customizeOpen: boolean
  draftAnalytics: boolean
  reopening: boolean
  onAcceptAll: () => void
  onRejectNonEssential: () => void
  onSaveCustomPreferences: () => void
  onClose: () => void
  onCustomizeOpenChange: (open: boolean) => void
  onDraftAnalyticsChange: (enabled: boolean) => void
}

export function CookieConsentBanner({
  open,
  customizeOpen,
  draftAnalytics,
  reopening,
  onAcceptAll,
  onRejectNonEssential,
  onSaveCustomPreferences,
  onClose,
  onCustomizeOpenChange,
  onDraftAnalyticsChange,
}: CookieConsentBannerProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const cookiesPolicyHref = getMarketingPageUrl('/cookies', features.marketing)
  const showPreferences = customizeOpen || reopening
  const showStrip = open && !showPreferences
  const reduceMotion = useReducedMotion()
  const panelRef = useRef<HTMLDivElement>(null)
  const [mounted, setMounted] = useState(open)
  const [translateY, setTranslateY] = useState(0)
  const [isAnimating, setIsAnimating] = useState(false)

  useEffect(() => {
    if (open) {
      setMounted(true)
      return
    }

    const exitMs = reduceMotion ? 0 : BANNER_EXIT_MS
    const timeout = window.setTimeout(() => setMounted(false), exitMs)
    return () => window.clearTimeout(timeout)
  }, [open, reduceMotion])

  useLayoutEffect(() => {
    if (!mounted || !panelRef.current || !showStrip) return

    const height = panelRef.current.offsetHeight
    if (height <= 0) return

    if (open) {
      if (reduceMotion) {
        setTranslateY(0)
        setIsAnimating(false)
        return
      }

      setIsAnimating(false)
      setTranslateY(height)
      const frame = requestAnimationFrame(() => {
        setIsAnimating(true)
        setTranslateY(0)
      })
      return () => cancelAnimationFrame(frame)
    }

    if (reduceMotion) {
      setTranslateY(height)
      setIsAnimating(false)
      return
    }

    setIsAnimating(true)
    const frame = requestAnimationFrame(() => setTranslateY(height))
    return () => cancelAnimationFrame(frame)
  }, [mounted, open, reduceMotion, showStrip])

  useEffect(() => {
    if (!isAnimating) return

    const duration = open ? BANNER_ENTER_MS : BANNER_EXIT_MS
    const timeout = window.setTimeout(() => {
      setIsAnimating(false)
    }, duration)
    return () => window.clearTimeout(timeout)
  }, [isAnimating, open])

  const handlePreferencesOpenChange = (next: boolean) => {
    if (next) return
    if (reopening) onClose()
    else onCustomizeOpenChange(false)
  }

  return (
    <>
      {mounted && showStrip ? (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] overflow-hidden">
          <div
            ref={panelRef}
            className={cn(
              'pointer-events-auto border-t border-border bg-background pb-[env(safe-area-inset-bottom,0px)] shadow-lg',
              'will-change-transform [backface-visibility:hidden] motion-reduce:transition-none',
              isAnimating && 'transition-transform ease-out',
              isAnimating && (open ? 'duration-300' : 'duration-200 ease-in'),
              !open && !isAnimating && 'pointer-events-none',
            )}
            style={{
              transform: `translate3d(0, ${translateY}px, 0)`,
            }}
            role="dialog"
            aria-labelledby="cookie-consent-title"
            aria-describedby="cookie-consent-description"
          >
            <div className="mx-auto flex max-w-7xl flex-nowrap items-center gap-2 px-4 py-3 sm:gap-3 sm:px-6 sm:py-3.5">
              <Cookie
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
              />
              <p
                id="cookie-consent-description"
                className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden text-[13px] text-muted-foreground"
              >
                <span id="cookie-consent-title" className="truncate">
                  {t('We use cookies to improve Appwrite.')}
                </span>
                <a
                  href={cookiesPolicyHref}
                  className="link-unstyled shrink-0 font-medium text-foreground underline-offset-4 hover:underline"
                >
                  {t('Cookies Policy')}
                </a>
              </p>
              <div className="flex shrink-0 items-center gap-1.5">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 px-2 text-[11px] sm:px-2.5 sm:text-[12px]"
                  onClick={onRejectNonEssential}
                >
                  {t('Reject non-essential')}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 px-2.5 text-[12px]"
                  onClick={() => onCustomizeOpenChange(true)}
                >
                  {t('Customize')}
                </Button>
                <Button
                  size="sm"
                  className="h-8 px-2.5 text-[12px]"
                  onClick={onAcceptAll}
                >
                  {t('Accept all')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      ) : null}

      <Dialog open={showPreferences} onOpenChange={handlePreferencesOpenChange}>
        <DialogContent className="gap-0 p-0 sm:max-w-md">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle id="cookie-consent-title" className="text-[15px]">
              {t('Cookie preferences')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Choose which optional cookies you allow. Read our')}{' '}
              <a
                href={cookiesPolicyHref}
                className="link-unstyled font-medium text-foreground underline-offset-4 hover:underline"
              >
                {t('Cookies Policy')}
              </a>
              .
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-2 px-6 py-4">
            <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <Label className="text-[13px] font-medium text-foreground">
                    {t('Essential')}
                  </Label>
                  <Badge variant="info" className="text-[10px] shrink-0">
                    {t('Always active')}
                  </Badge>
                </div>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Required for sign-in, site access, security, and remembering your preferences.',
                  )}
                </p>
              </div>
            </div>

            <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
              <div className="min-w-0">
                <Label
                  htmlFor="cookie-consent-analytics"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Analytics')}
                </Label>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Privacy-friendly usage analytics and error reporting to help us improve Appwrite.',
                  )}
                </p>
              </div>
              <Switch
                id="cookie-consent-analytics"
                checked={draftAnalytics}
                onCheckedChange={onDraftAnalyticsChange}
                className="mt-0.5 shrink-0"
              />
            </div>
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            {reopening ? (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onClose}
              >
                {t('Cancel')}
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => onCustomizeOpenChange(false)}
              >
                {t('Back')}
              </Button>
            )}
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={onSaveCustomPreferences}
            >
              {t('Save preferences')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
