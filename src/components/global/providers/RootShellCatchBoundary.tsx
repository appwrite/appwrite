import { CatchBoundary, useRouterState } from '@tanstack/react-router'
import { ErrorComponent } from '@/components/error/Component'
import { reportRouterCaughtError } from '@/lib/sentry/report-error'

/**
 * Catches render errors in the root shell (providers beside/around the route
 * outlet) that would otherwise hit TanStack's global CatchBoundary, which uses
 * a hardcoded default UI and never reports to Sentry.
 */
export function RootShellCatchBoundary({
  children,
}: {
  children: React.ReactNode
}) {
  const resetKey = useRouterState({ select: (s) => s.loadedAt })

  return (
    <CatchBoundary
      getResetKey={() => resetKey}
      errorComponent={({ error, reset }) => (
        <ErrorComponent error={error} reset={reset} />
      )}
      onCatch={(error, errorInfo) => {
        reportRouterCaughtError(error, errorInfo, {
          source: 'root-shell-catch-boundary',
        })
      }}
    >
      {children}
    </CatchBoundary>
  )
}
