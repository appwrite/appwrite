import { useEffect, useRef, useState, type ReactNode } from 'react'

/** Below this much free space the scene would crowd the nav, so it stays hidden. */
const MIN_HEIGHT = 140
const MIN_HEIGHT_COMPACT = 120

/**
 * Fills the free sidebar space above Settings with a holiday scene, or the
 * narrow `rail` version of it on the collapsed sidebar. Parent must be a flex
 * column; the scene only takes space nothing else needs.
 */
export function HolidaySidebarScene({
  compact,
  scene,
  rail,
}: {
  compact: boolean
  scene: ReactNode
  rail: ReactNode
}) {
  const ref = useRef<HTMLDivElement>(null)
  const [height, setHeight] = useState(0)

  useEffect(() => {
    const element = ref.current
    if (!element) return
    const observer = new ResizeObserver(([entry]) => {
      setHeight(entry.contentRect.height)
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  const roomy = height >= (compact ? MIN_HEIGHT_COMPACT : MIN_HEIGHT)

  return (
    <div
      ref={ref}
      className="pointer-events-none relative -mx-3 -mb-4 min-h-0 flex-1 basis-0 overflow-hidden [mask-image:linear-gradient(to_bottom,transparent,black_30%)]"
      aria-hidden
    >
      {roomy && (compact ? rail : scene)}
    </div>
  )
}
