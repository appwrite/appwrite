import { useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'

let suppressLogoHoverFlipUntilLeave = false
const logoFlipSuppressListeners = new Set<() => void>()

function emitLogoFlipSuppressChange() {
  logoFlipSuppressListeners.forEach((listener) => listener())
}

function setSuppressLogoHoverFlipUntilLeave(next: boolean) {
  if (suppressLogoHoverFlipUntilLeave === next) return
  suppressLogoHoverFlipUntilLeave = next
  emitLogoFlipSuppressChange()
}

function subscribeLogoFlipSuppress(listener: () => void) {
  logoFlipSuppressListeners.add(listener)
  return () => logoFlipSuppressListeners.delete(listener)
}

function getLogoFlipSuppressSnapshot() {
  return suppressLogoHoverFlipUntilLeave
}

function getLogoFlipSuppressServerSnapshot() {
  return false
}

/** Filled Appwrite mark (header); uses `currentColor`. */
function FilledAppwriteMark({ className }: { className?: string }) {
  return (
    <svg
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={cn('h-6 w-6 shrink-0', className)}
      aria-hidden
    >
      <path
        fill="currentColor"
        d="M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z"
      />
      <path
        fill="currentColor"
        d="M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z"
      />
    </svg>
  )
}

/**
 * Solid cloud silhouette (Heroicons 24/solid Cloud - MIT).
 * Rounded “weather” cloud reads clearer than stroke icons at small sizes.
 */
function FilledCloudMark({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="currentColor"
      className={cn('h-6 w-6 shrink-0', className)}
      aria-hidden
    >
      <path
        fillRule="evenodd"
        d="M4.5 9.75a6 6 0 0 1 11.573-2.226 3.75 3.75 0 0 1 4.133 4.303A4.5 4.5 0 0 1 18 20.25H6.75a5.25 5.25 0 0 1-2.23-10.004 6.072 6.072 0 0 1-.02-.496Z"
        clipRule="evenodd"
      />
    </svg>
  )
}

/**
 * Appwrite mark + pink cloud on hover (3D flip); parent header link must use Tailwind `group`.
 * Cloud profile only. After a click (navigate home), the mark stays on Appwrite until the pointer
 * leaves the link, including across SPA remounts - we do not infer “not hovered” on mount, which
 * would wrongly re-enable the flip while the cursor is still over the logo.
 * Self-hosted shows the Appwrite mark only (no cloud flip or suppress logic).
 */
export function ConsoleHeaderLogo({ className }: { className?: string }) {
  const { isCloud } = useConsoleProfile()
  const rootRef = useRef<HTMLDivElement>(null)
  const flipSuppressed = useSyncExternalStore(
    subscribeLogoFlipSuppress,
    getLogoFlipSuppressSnapshot,
    getLogoFlipSuppressServerSnapshot,
  )

  useEffect(() => {
    if (!isCloud) {
      setSuppressLogoHoverFlipUntilLeave(false)
    }
  }, [isCloud])

  useLayoutEffect(() => {
    if (typeof window === 'undefined' || !isCloud) return
    const parent = rootRef.current?.parentElement
    if (!parent) return

    // Do not clear suppress on mount using `:hover` - after SPA navigation the
    // new link often does not match `:hover` for a frame even while the pointer
    // is still over the logo, which incorrectly re-enabled the cloud flip.

    const armSuppress = () => setSuppressLogoHoverFlipUntilLeave(true)
    const clearSuppress = () => setSuppressLogoHoverFlipUntilLeave(false)

    parent.addEventListener('pointerdown', armSuppress)
    parent.addEventListener('click', armSuppress)
    parent.addEventListener('pointerleave', clearSuppress)
    parent.addEventListener('pointercancel', clearSuppress)
    return () => {
      parent.removeEventListener('pointerdown', armSuppress)
      parent.removeEventListener('click', armSuppress)
      parent.removeEventListener('pointerleave', clearSuppress)
      parent.removeEventListener('pointercancel', clearSuppress)
    }
  }, [isCloud])

  if (!isCloud) {
    return (
      <div className={cn('relative h-6 w-6 shrink-0', className)}>
        <FilledAppwriteMark />
      </div>
    )
  }

  return (
    <div ref={rootRef} className={cn('relative h-6 w-6 shrink-0 [perspective:88px]', className)}>
      <div
        className={cn(
          'absolute inset-0 flex items-center justify-center [transform-style:preserve-3d]',
          flipSuppressed
            ? '[transform:rotateY(0deg)] transition-none'
            : cn(
                '[transform:rotateY(0deg)] transition-transform duration-300 ease-out',
                'group-hover:[transform:rotateY(180deg)]',
                'motion-reduce:transition-none motion-reduce:group-hover:[transform:rotateY(0deg)]',
              ),
        )}
      >
        <div
          className="absolute inset-0 flex items-center justify-center [backface-visibility:hidden]"
          style={{ transform: 'rotateY(0deg)' }}
        >
          <FilledAppwriteMark />
        </div>
        <div
          className="absolute inset-0 flex items-center justify-center text-[var(--brand-cta)] [backface-visibility:hidden]"
          style={{ transform: 'rotateY(180deg)' }}
        >
          <FilledCloudMark />
        </div>
      </div>
    </div>
  )
}
