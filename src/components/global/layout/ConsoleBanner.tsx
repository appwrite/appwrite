import { useState, useEffect } from 'react'
import { useTheme } from 'next-themes'
import { ArrowRightCircle, X } from 'lucide-react'
import { useI18n } from '@/lib/i18n'

const BANNER_HIDE_KEY = 'console-banner-hidden'
const BANNER_HIDE_DURATION = 7 * 24 * 60 * 60 * 1000 // 1 week in milliseconds

/**
 * ConsoleBanner Component
 *
 * A simple, elegant dark banner strip that appears sticky to the bottom
 * of the console, just above the footer. Promotes Imagine.dev.
 *
 * Props: None
 * State: Manages visibility based on localStorage
 *
 * Usage:
 * <ConsoleBanner />
 */
export function ConsoleBanner() {
  const [mounted, setMounted] = useState(false)
  const [isHidden, setIsHidden] = useState(false)
  const { theme, resolvedTheme } = useTheme()
  const { catalog } = useI18n()
  const consoleBannerCopy = catalog.app.consoleBanner

  useEffect(() => {
    setMounted(true)

    // Check if banner was hidden and if the hide period has expired
    const hiddenUntil = localStorage.getItem(BANNER_HIDE_KEY)
    if (hiddenUntil) {
      const hideTimestamp = parseInt(hiddenUntil, 10)
      const now = Date.now()
      if (now < hideTimestamp) {
        setIsHidden(true)
      } else {
        // Hide period expired, remove from localStorage
        localStorage.removeItem(BANNER_HIDE_KEY)
      }
    }
  }, [])

  const handleDismiss = (e: React.MouseEvent) => {
    e.preventDefault()
    e.stopPropagation()
    const hideUntil = Date.now() + BANNER_HIDE_DURATION
    localStorage.setItem(BANNER_HIDE_KEY, hideUntil.toString())
    setIsHidden(true)
  }

  // Determine which logo to use based on theme
  // Default to dark mode if theme is not yet resolved
  const isDark = mounted ? (resolvedTheme ?? theme) === 'dark' : true
  const logoSrc = isDark ? '/imagine-logo-dark.svg' : '/imagine-logo-light.svg'

  if (isHidden) {
    return null
  }

  return (
    <div className="sticky bottom-0 z-20 flex min-h-[54px] shrink-0 items-center border-t border-border bg-muted/50 backdrop-blur-sm px-3 py-3 sm:py-0 sm:h-[54px]">
      <div className="mx-auto w-full max-w-7xl flex items-center">
        <a
          href="https://imagine.dev"
          target="_blank"
          rel="noopener noreferrer"
          className="flex flex-1 min-h-0 items-center gap-3 text-start transition-opacity hover:opacity-80 py-1 sm:py-0"
        >
          <div className="flex items-center ps-2.5 py-1.5 shrink-0">
            <img
              src={logoSrc}
              alt={consoleBannerCopy.imagineAlt}
              className="h-5 w-auto shrink-0"
            />
          </div>
          <span className="text-[13px] text-muted-foreground min-w-0">
            <span className="font-medium text-foreground">Imagine.dev</span>{' '}
            &nbsp;{consoleBannerCopy.messagePrefix}{' '}
            <span className="font-medium text-foreground">Appwrite Cloud</span>
          </span>
          <ArrowRightCircle className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-50 hidden sm:block" />
        </a>
        <div className="flex items-center px-2.5 py-1.5 shrink-0">
          <button
            onClick={handleDismiss}
            className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label={consoleBannerCopy.dismiss}
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  )
}
