import { ExternalLink, Loader2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'

import { getStatusIcon, getStatusPresentation } from '@/lib/cloud-status-copy'
import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { LegacyAppwriteLogo } from '@/components/global/shared/LegacyAppwriteBrand'
import { isLegacyThemeFromStorage } from '@/lib/legacy-theme-assets'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Delay before the bottom spinner appears on long loads. */
const SPINNER_DELAY_MS = 1500
/** Fade the full overlay, background included, into the page underneath. */
export const FULLSCREEN_LOADER_HIDE_MS = 420

function LoaderBrandMark() {
  const isLegacy = isLegacyThemeFromStorage()

  return (
    <div
      className={cn('inline-flex items-end gap-1.5', FORCE_LTR_CLASS)}
      dir="ltr"
    >
      {isLegacy ? (
        <LegacyAppwriteLogo className="h-8" aria-label="Appwrite" />
      ) : (
        <AppwriteWordmark className="h-8" />
      )}
      <span className="pb-0.5 text-xs font-extralight tracking-tight text-muted-foreground">
        / 2.0
      </span>
    </div>
  )
}

export type FullscreenLoaderStatusBanner = {
  /** Main title (inherits container text color). */
  title: string
  /** Optional report title (styled with text-foreground). */
  reportTitle?: string
  /** Optional maintenance window (styled with text-foreground/70). */
  maintenanceWindow?: string
  /** Optional affected regions line (matches CloudStatusBanner). */
  regionsLine?: string
  href: string
  /** Used for banner color (matches CloudStatusBanner). */
  state: 'degraded' | 'downtime' | 'maintenance'
}

interface FullscreenLoaderProps {
  isVisible: boolean
  onComplete?: () => void
  /** When set, shows a compact notice under the logo that we're experiencing issues (e.g. from status API). */
  statusBanner?: FullscreenLoaderStatusBanner
}

export function FullscreenLoader({
  isVisible,
  onComplete,
  statusBanner,
}: FullscreenLoaderProps) {
  const t = useT()
  const hasStatusBanner = Boolean(statusBanner)
  const [showSpinner, setShowSpinner] = useState(false)
  const [present, setPresent] = useState(isVisible)
  const onCompleteRef = useRef(onComplete)
  const rootRef = useRef<HTMLDivElement>(null)
  onCompleteRef.current = onComplete

  // Show spinner only after 1.5s of the current visible period. Depend on a
  // boolean for the status banner so object identity cannot reset the timer.
  useEffect(() => {
    if (!isVisible || hasStatusBanner) {
      setShowSpinner(false)
      return
    }

    setShowSpinner(false)
    const timer = setTimeout(() => {
      setShowSpinner(true)
    }, SPINNER_DELAY_MS)
    return () => clearTimeout(timer)
  }, [isVisible, hasStatusBanner])

  useEffect(() => {
    if (isVisible) {
      setPresent(true)
      return
    }

    if (!present) return

    let cancelled = false
    const finish = () => {
      if (cancelled) return
      setPresent(false)
      onCompleteRef.current?.()
    }

    const reduceMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    const el = rootRef.current
    if (reduceMotion || !el) {
      finish()
      return
    }

    // Animate the element that paints the backdrop. A class toggle was leaving
    // that layer opaque until unmount, so the background snapped off after the logo.
    const animation = el.animate([{ opacity: 1 }, { opacity: 0 }], {
      duration: FULLSCREEN_LOADER_HIDE_MS,
      easing: 'ease-in-out',
      fill: 'forwards',
    })
    animation.onfinish = finish
    return () => {
      cancelled = true
      animation.cancel()
    }
  }, [isVisible, present])

  if (!present) return null

  return (
    <div
      ref={rootRef}
      className="pointer-events-none fixed inset-0 z-[9999]"
      style={{ backgroundColor: 'var(--background)' }}
      aria-label="Loading"
      data-fullscreen-loader=""
    >
      <div className="absolute inset-0">
          {statusBanner &&
            (() => {
              const presentation = getStatusPresentation(statusBanner.state)
              const Icon = getStatusIcon(statusBanner.state)
              return (
                <a
                  href={statusBanner.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={cn(
                    'absolute top-0 start-0 end-0 z-10 min-h-14 transition-all duration-200 hover:opacity-95',
                    'flex min-h-14 flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4',
                    presentation.containerClassName,
                  )}
                >
                  <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                    <Icon className="mt-0.5 h-4 w-4 shrink-0 sm:mt-0" />
                    <p className="text-[13px] font-medium leading-snug">
                      {t(statusBanner.title)}
                      {statusBanner.reportTitle ? (
                        <>
                          {' '}
                          <span className="text-foreground">
                            {t(statusBanner.reportTitle)}
                          </span>
                        </>
                      ) : null}
                      {statusBanner.maintenanceWindow ? (
                        <>
                          {' '}
                          <span className="text-foreground/70">
                            {statusBanner.maintenanceWindow}
                          </span>
                        </>
                      ) : null}
                      {statusBanner.regionsLine ? (
                        <>
                          {' '}
                          <span className="text-foreground/70">
                            {t(statusBanner.regionsLine)}
                          </span>
                        </>
                      ) : null}
                    </p>
                  </div>
                  <span
                    className={cn(
                      'flex h-8 w-fit shrink-0 items-center gap-2 rounded-md px-3 text-[13px] font-medium sm:ms-auto',
                      presentation.buttonClassName,
                    )}
                  >
                    <span className="hidden sm:inline">{t('View Status')}</span>
                    <span className="sm:hidden">{t('Status')}</span>
                    <ExternalLink className="h-3.5 w-3.5" />
                  </span>
                </a>
              )
            })()}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <LoaderBrandMark />
          </div>
          {showSpinner && !hasStatusBanner ? (
            <div
              className="pointer-events-none absolute bottom-8 left-1/2 z-10 -translate-x-1/2"
              data-fullscreen-loader-spinner=""
              aria-hidden
            >
              <Loader2 className="h-5 w-5 animate-spin text-foreground/70" />
            </div>
          ) : null}
      </div>
    </div>
  )
}
