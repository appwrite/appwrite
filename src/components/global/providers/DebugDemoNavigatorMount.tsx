import { lazy, Suspense } from 'react'

const DebugDemoNavigator = lazy(() =>
  import('@/components/global/providers/DebugDemoNavigator').then((module) => ({
    default: module.DebugDemoNavigator,
  })),
)

export function DebugDemoNavigatorMount() {
  return (
    <Suspense fallback={null}>
      <DebugDemoNavigator />
    </Suspense>
  )
}
