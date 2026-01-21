import { useCallback } from 'react'
import { useNavigate } from '@tanstack/react-router'

interface UseSmartNavigationOptions {
  /** Fallback path to navigate to if no browser history exists */
  fallbackPath?: string
}

/**
 * Hook for smart navigation that prefers browser history with fallback support
 *
 * @example
 * ```tsx
 * const goBack = useSmartNavigation({ fallbackPath: '/organizations/123/billing' })
 *
 * <Button onClick={goBack}>Cancel</Button>
 * ```
 */
export function useSmartNavigation({
  fallbackPath,
}: UseSmartNavigationOptions = {}) {
  const navigate = useNavigate()

  const goBack = useCallback(() => {
    // Simple and reliable: if there's history, go back
    // window.history.length > 1 means there's at least one page to go back to
    if (window.history.length > 1) {
      window.history.back()
    } else if (fallbackPath) {
      // No history, use fallback path
      navigate({ to: fallbackPath as any })
    } else {
      // Last resort: go to root
      navigate({ to: '/' as any })
    }
  }, [navigate, fallbackPath])

  return goBack
}
