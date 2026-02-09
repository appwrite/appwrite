import { motion, AnimatePresence } from 'motion/react'
import { useEffect, useState } from 'react'

// Logo dimensions from SVG viewBox (132×24)
const LOGO_WIDTH = 132
const LOGO_HEIGHT = 24
const CONTENT_MIN_WIDTH = LOGO_WIDTH + 6 + 32 // logo + gap-1.5 + " / 2.0" text

// Loader is always dark regardless of app theme (matches .dark --background)
const LOADER_BG = 'oklch(0.141 0.005 285.823)'
const LOADER_TEXT = 'oklch(0.705 0.015 286.067)' // muted-foreground on dark

/**
 * Static fullscreen loader (logo + " / 2.0") with no theme hooks.
 * Used on first paint before client mount so the user sees the branded loader
 * instead of route-level "Loading..." text. Always dark.
 */
export function StaticFullscreenLoader() {
  return (
    <div
      className="fixed inset-0 z-[9999]"
      style={{ backgroundColor: LOADER_BG }}
      aria-label="Loading"
    >
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <div
          className="flex items-center gap-1.5 min-h-6 animate-in fade-in duration-500"
          style={{ minWidth: CONTENT_MIN_WIDTH }}
        >
          <img
            src="/appwrite-dark.svg"
            alt="Appwrite"
            width={LOGO_WIDTH}
            height={LOGO_HEIGHT}
            className="h-6 w-auto"
          />
          <span
            className="text-xs font-extralight tracking-tight"
            style={{ color: LOADER_TEXT }}
          >
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
  const [showSpinner, setShowSpinner] = useState(false)

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
          className="fixed inset-0 z-[9999]"
          style={{ backgroundColor: LOADER_BG }}
        >
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <motion.div
              className="flex items-center gap-1.5 min-h-6"
              style={{ minWidth: CONTENT_MIN_WIDTH }}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ duration: 0.5, ease: 'easeOut' }}
            >
              <img
                src="/appwrite-dark.svg"
                alt="Appwrite"
                width={LOGO_WIDTH}
                height={LOGO_HEIGHT}
                className="h-6 w-auto"
              />
              <span
                className="text-xs font-extralight tracking-tight"
                style={{ color: LOADER_TEXT }}
              >
                / 2.0
              </span>
            </motion.div>
          </div>
          {showSpinner && (
            <div className="absolute bottom-8 left-1/2 -translate-x-1/2">
              <div className="w-4 h-4 border-2 rounded-full animate-spin border-[oklch(0.274_0.006_286.033)] border-t-[oklch(0.705_0.015_286.067)]" />
            </div>
          )}
        </motion.div>
      )}
    </AnimatePresence>
  )
}
