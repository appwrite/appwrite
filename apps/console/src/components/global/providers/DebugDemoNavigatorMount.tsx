import { lazy, Suspense } from 'react'
import { useDebugMode } from '@/components/global/providers/DebugMode'

const DebugDemoNavigator = lazy(() =>
  import('@/components/global/providers/DebugDemoNavigator').then((module) => ({
    default: module.DebugDemoNavigator,
  })),
)

export function DebugDemoNavigatorMount() {
  const { isDebugModeOpen } = useDebugMode()
  if (!isDebugModeOpen) return null

  return (
    <Suspense fallback={null}>
      <DebugDemoNavigator />
    </Suspense>
  )
}
