import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { cn } from '@/lib/utils'

interface HorizontalScrollFadeProps {
  children: ReactNode
  className?: string
  viewportClassName?: string
  /** Tailwind `from-*` class matching the scroll area background. */
  fadeFromClassName?: string
}

export function HorizontalScrollFade({
  children,
  className,
  viewportClassName,
  fadeFromClassName = 'from-background',
}: HorizontalScrollFadeProps) {
  const scrollRef = useRef<HTMLDivElement>(null)
  const [showLeftFade, setShowLeftFade] = useState(false)
  const [showRightFade, setShowRightFade] = useState(false)

  const updateFades = useCallback(() => {
    const element = scrollRef.current
    if (!element) return

    const { scrollLeft, scrollWidth, clientWidth } = element
    setShowLeftFade(scrollLeft > 1)
    setShowRightFade(scrollLeft + clientWidth < scrollWidth - 1)
  }, [])

  useEffect(() => {
    const element = scrollRef.current
    if (!element) return

    updateFades()

    element.addEventListener('scroll', updateFades, { passive: true })

    const resizeObserver = new ResizeObserver(updateFades)
    resizeObserver.observe(element)

    const content = element.firstElementChild
    if (content) {
      resizeObserver.observe(content)
    }

    return () => {
      element.removeEventListener('scroll', updateFades)
      resizeObserver.disconnect()
    }
  }, [updateFades, children])

  return (
    <div className={cn('relative min-w-0', className)}>
      <div
        ref={scrollRef}
        className={cn(
          'overflow-x-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden',
          viewportClassName,
        )}
      >
        {children}
      </div>

      <div
        aria-hidden
        className={cn(
          'pointer-events-none absolute inset-y-0 start-0 z-10 w-10 bg-gradient-to-r to-transparent transition-opacity duration-200 sm:w-12',
          fadeFromClassName,
          showLeftFade ? 'opacity-100' : 'opacity-0',
        )}
      />
      <div
        aria-hidden
        className={cn(
          'pointer-events-none absolute inset-y-0 end-0 z-10 w-10 bg-gradient-to-l to-transparent transition-opacity duration-200 sm:w-12',
          fadeFromClassName,
          showRightFade ? 'opacity-100' : 'opacity-0',
        )}
      />
    </div>
  )
}
