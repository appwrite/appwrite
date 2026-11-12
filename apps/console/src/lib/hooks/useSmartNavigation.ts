import { useCallback } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useNavigationHistorySafe } from '@/components/global/providers/NavigationHistoryProvider'

interface UseSmartNavigationOptions {
  /**
   * Explicit path to navigate to when closing.
   * If provided, this path will always be used instead of internal history.
   */
  fallbackPath?: string
}

/**
 * Hook for smart navigation with explicit path support
 *
 * Navigation priority:
 * 1. If fallbackPath is provided → navigate to that path
 * 2. If no fallbackPath → go back to previous internal console page
 * 3. If no internal history → go to root
 *
 * Important: This hook only considers navigation within the console.
 * If the user came from an external site, it will NOT go back to that site.
 *
 * @example
 * ```tsx
 * // With explicit path - always navigates to /organizations/123/settings/billing
 * const goBack = useSmartNavigation({ fallbackPath: '/organizations/123/settings/billing' })
 *
 * // Without explicit path - uses internal console history or root
 * const goBack = useSmartNavigation()
 *
 * <Button onClick={goBack}>Cancel</Button>
 * ```
 */
export function useSmartNavigation({
  fallbackPath,
}: UseSmartNavigationOptions = {}) {
  const navigate = useNavigate()
  const navigationHistory = useNavigationHistorySafe()

  const goBack = useCallback(() => {
    // Priority 1: If explicit path is provided, always use it
    if (fallbackPath) {
      navigate({ to: fallbackPath as unknown })
      return
    }

    // Priority 2: Go back to previous internal console page
    if (navigationHistory) {
      const previousPath = navigationHistory.popHistory()
      if (previousPath) {
        navigate({ to: previousPath as unknown })
        return
      }
    }

    // Priority 3: No internal history, go to root
    navigate({ to: '/' as unknown })
  }, [navigate, fallbackPath, navigationHistory])

  return goBack
}
