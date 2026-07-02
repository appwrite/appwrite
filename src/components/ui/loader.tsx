import { ExternalLink } from 'lucide-react'
import { motion, AnimatePresence } from 'motion/react'
import { useEffect, useState } from 'react'

import { getStatusIcon, getStatusPresentation } from '@/lib/cloud-status-copy'
import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

function LoaderBrandMark() {
  return (
    <div className={cn('inline-flex items-end gap-1.5', FORCE_LTR_CLASS)} dir="ltr">
      <AppwriteWordmark className="h-8" />
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
  const [shouldRender, setShouldRender] = useState(isVisible)
  const [showSpinner, setShowSpinner] = useState(false)

  // Show spinner only after 3 seconds of loading, and only when status is not indicating an issue
  useEffect(() => {
    if (isVisible && !statusBanner) {
      setShowSpinner(false)
      const timer = setTimeout(() => {
        setShowSpinner(true)
      }, 3000)
      return () => clearTimeout(timer)
    } else {
      setShowSpinner(false)
    }
  }, [isVisible, statusBanner])

  useEffect(() => {
    if (isVisible) {
      setShouldRender(true)
    } else {
      // Start fade-out immediately; onComplete after animation finishes
      setShouldRender(false)
      const timer = setTimeout(() => {
        onComplete?.()
      }, 500) // Match exit animation duration
      return () => clearTimeout(timer)
    }
  }, [isVisible, onComplete])

  return (
    <AnimatePresence>
      {shouldRender && (
        <motion.div
          initial={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.5, ease: 'easeInOut' }}
          className="fixed inset-0 z-[9999] bg-background"
          aria-label="Loading"
        >
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
          {showSpinner && !statusBanner && (
            <div className="absolute bottom-8 left-1/2 -translate-x-1/2">
              <div className="w-4 h-4 border-2 rounded-full animate-spin border-muted border-t-muted-foreground" />
            </div>
          )}
        </motion.div>
      )}
    </AnimatePresence>
  )
}
