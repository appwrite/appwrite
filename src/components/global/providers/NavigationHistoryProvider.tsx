import {
  createContext,
  useContext,
  useEffect,
  useRef,
  useCallback,
  ReactNode,
} from 'react'
import { useLocation } from '@tanstack/react-router'

interface NavigationHistoryContextType {
  /**
   * Check if there's internal navigation history to go back to
   */
  hasInternalHistory: () => boolean
  /**
   * Get the previous internal path (or undefined if none)
   */
  getPreviousPath: () => string | undefined
  /**
   * Go back to the previous internal path and remove it from history
   * Returns the path that was navigated to, or undefined if no history
   */
  popHistory: () => string | undefined
}

const NavigationHistoryContext =
  createContext<NavigationHistoryContextType | null>(null)

interface NavigationHistoryProviderProps {
  children: ReactNode
}

/**
 * NavigationHistoryProvider
 *
 * Tracks internal navigation within the console application.
 * This ensures that "go back" functionality only navigates to pages
 * within the console, not to external sites the user may have come from.
 *
 * @example
 * ```tsx
 * // In app root
 * <NavigationHistoryProvider>
 *   <App />
 * </NavigationHistoryProvider>
 *
 * // In component
 * const { hasInternalHistory, getPreviousPath } = useNavigationHistory()
 * if (hasInternalHistory()) {
 *   // Safe to go back within the console
 * }
 * ```
 */
export function NavigationHistoryProvider({
  children,
}: NavigationHistoryProviderProps) {
  const location = useLocation()

  // Use ref to store history stack to avoid re-renders on navigation
  const historyStackRef = useRef<string[]>([])

  // Track the current path to avoid duplicates
  const currentPathRef = useRef<string>('')

  // Track navigation changes
  useEffect(() => {
    // Use searchStr for the string representation of search params
    // location.search in TanStack Router is an object, not a string
    const searchString = location.searchStr || ''
    const currentPath = location.pathname + searchString

    // Don't add duplicate consecutive entries
    if (currentPath !== currentPathRef.current) {
      // Add the previous path to history (not the current one)
      if (currentPathRef.current) {
        historyStackRef.current.push(currentPathRef.current)

        // Limit history stack to prevent memory issues
        if (historyStackRef.current.length > 50) {
          historyStackRef.current.shift()
        }
      }

      currentPathRef.current = currentPath
    }
  }, [location.pathname, location.searchStr])

  const hasInternalHistory = useCallback(() => {
    return historyStackRef.current.length > 0
  }, [])

  const getPreviousPath = useCallback(() => {
    const stack = historyStackRef.current
    return stack.length > 0 ? stack[stack.length - 1] : undefined
  }, [])

  const popHistory = useCallback(() => {
    return historyStackRef.current.pop()
  }, [])

  const value: NavigationHistoryContextType = {
    hasInternalHistory,
    getPreviousPath,
    popHistory,
  }

  return (
    <NavigationHistoryContext.Provider value={value}>
      {children}
    </NavigationHistoryContext.Provider>
  )
}

/**
 * Hook to access internal navigation history
 *
 * @throws Error if used outside of NavigationHistoryProvider
 */
export function useNavigationHistory(): NavigationHistoryContextType {
  const context = useContext(NavigationHistoryContext)

  if (!context) {
    throw new Error(
      'useNavigationHistory must be used within a NavigationHistoryProvider',
    )
  }

  return context
}

/**
 * Hook to safely access navigation history (returns null if not available)
 * Useful for optional navigation history checking
 */
export function useNavigationHistorySafe(): NavigationHistoryContextType | null {
  return useContext(NavigationHistoryContext)
}
