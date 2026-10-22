import { lazy, Suspense } from 'react'
import { useDebugMode } from '@/components/global/providers/DebugMode'

const DebugMenu = lazy(() =>
  import('@/components/global/providers/DebugMenu').then((module) => ({
    default: module.DebugMenu,
  })),
)

/**
 * Keep the debug menu out of the marketing/blog JS graph until it is opened.
 * Parsing that module on every public page delayed first taps (INP).
 */
export function DebugMenuMount() {
  const { isDebugModeOpen } = useDebugMode()
  if (!isDebugModeOpen) return null

  return (
    <Suspense fallback={null}>
      <DebugMenu />
    </Suspense>
  )
}
