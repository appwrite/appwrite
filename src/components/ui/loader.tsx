import { motion, AnimatePresence } from 'motion/react'
import { useEffect, useState } from 'react'
import { useTheme } from 'next-themes'

// Logo dimensions from SVG viewBox (132×24)
const LOGO_WIDTH = 132
const LOGO_HEIGHT = 24
const CONTENT_MIN_WIDTH = LOGO_WIDTH + 6 + 32 // logo + gap-1.5 + " / 2.0" text

/**
 * Static fullscreen loader (logo + " / 2.0") with no theme hooks.
 * Used on first paint before client mount so the user sees the branded loader
 * instead of route-level "Loading..." text.
 */
export function StaticFullscreenLoader() {
  return (
    <div
      className="fixed inset-0 z-[9999] bg-background"
      aria-label="Loading"
    >
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <div
          className="flex items-center gap-1.5 min-h-6"
          style={{ minWidth: CONTENT_MIN_WIDTH }}
        >
          <img
            src="/appwrite-dark.svg"
            alt="Appwrite"
            width={LOGO_WIDTH}
            height={LOGO_HEIGHT}
            className="h-6 w-auto"
          />
          <span className="text-foreground/60 text-xs font-extralight tracking-tight">
            / 2.0
          </span>
        </div>
      </div>
    </div>
  )
}

interface FullscreenLoaderProps {
  isVisible: boolean
  onComplete?: () => void
}

export function FullscreenLoader({
  isVisible,
  onComplete,
}: FullscreenLoaderProps) {
  const [shouldRender, setShouldRender] = useState(isVisible)
  const [mounted, setMounted] = useState(false)
  const [showSpinner, setShowSpinner] = useState(false)
  const { theme, resolvedTheme } = useTheme()

  // Determine which logo to use based on theme
  // appwrite-light.svg has dark fill (#19191C) - use on light backgrounds
  // appwrite-dark.svg has light fill (#EDEDF0) - use on dark backgrounds
  // Wait for theme to be mounted to avoid hydration mismatch
  useEffect(() => {
    setMounted(true)
  }, [])

  // Show spinner only after 3 seconds of loading
  useEffect(() => {
    if (isVisible) {
      setShowSpinner(false)
      const timer = setTimeout(() => {
        setShowSpinner(true)
      }, 3000)
      return () => clearTimeout(timer)
    } else {
      setShowSpinner(false)
    }
  }, [isVisible])

  // Use resolvedTheme when available (handles system theme), otherwise fall back to theme
  // Only pick logo once theme is resolved to avoid flash
  const isDark = mounted ? (resolvedTheme ?? theme) === 'dark' : true
  const logoSrc = isDark ? '/appwrite-dark.svg' : '/appwrite-light.svg'

  useEffect(() => {
    if (isVisible) {
      setShouldRender(true)
    } else {
      // Delay unmounting to allow fade-out animation to complete
      const timer = setTimeout(() => {
        setShouldRender(false)
        onComplete?.()
      }, 500) // Match the exit animation duration
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
        >
          {/* Loader content – fixed-size wrapper prevents layout shift when logo/text appear */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <div
              className="flex items-center gap-1.5 min-h-6"
              style={{ minWidth: CONTENT_MIN_WIDTH }}
              suppressHydrationWarning
            >
              {mounted ? (
                <>
                  <img
                    src={logoSrc}
                    alt="Appwrite"
                    width={LOGO_WIDTH}
                    height={LOGO_HEIGHT}
                    className="h-6 w-auto"
                  />
                  <span className="text-foreground/60 text-xs font-extralight tracking-tight">/ 2.0</span>
                </>
              ) : (
                <span className="sr-only">Loading</span>
              )}
            </div>
          </div>
          {/* Spinner at bottom, only shown after 3s */}
          {showSpinner && (
            <div className="absolute bottom-8 left-1/2 -translate-x-1/2">
              <div className="w-4 h-4 border-2 border-muted-foreground/30 border-t-muted-foreground rounded-full animate-spin"></div>
            </div>
          )}
        </motion.div>
      )}
    </AnimatePresence>
  )
}
