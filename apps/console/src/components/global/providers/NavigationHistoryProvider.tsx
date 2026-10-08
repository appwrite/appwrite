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
   * Go back to the previous internal path and remove it from history.
   * Call skipNextPush() before navigate() so we don't re-push the page we left.
   */
  popHistory: () => string | undefined
  /**
   * Get a copy of the back stack (oldest first). Only "previous" pages - where we can go back to.
   */
  getBackStack: () => { path: string; title: string }[]
  /**
   * Remove the given path and any entries after it from the stack, then return the path.
   * Call skipNextPush() before navigate() so we don't re-push the page we left.
   */
  popUntil: (path: string) => string | undefined
  /**
   * Call before navigate() when the navigation is "back" or "history select".
   * Prevents the path we're leaving from being pushed onto the back stack.
   */
  skipNextPush: () => void
  /**
   * Whether there is a forward history (after having gone back).
   */
  hasForwardHistory: () => boolean
  /**
   * Go forward: pop from forward stack and return the path. Call skipNextPush() before navigate().
   */
  popForward: () => string | undefined
}

export type NavigationHistoryEntry = { path: string; title: string }

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

  // Back stack: pages we can go back to (previous pages only)
  const historyStackRef = useRef<NavigationHistoryEntry[]>([])
  // Forward stack: pages we can go forward to (after having gone back)
  const forwardStackRef = useRef<NavigationHistoryEntry[]>([])

  const currentPathRef = useRef<string>('')
  // When true, the next path change is from our "back" or "forward" or "history select" - don't push, and clear forward on normal nav
  const skipNextPushRef = useRef(false)

  // Track navigation changes
  useEffect(() => {
    const searchString = location.searchStr || ''
    const currentPath = location.pathname + searchString

    if (currentPath !== currentPathRef.current) {
      if (skipNextPushRef.current) {
        skipNextPushRef.current = false
      } else if (currentPathRef.current) {
        // Normal navigation: push the page we're leaving onto back stack and clear forward stack
        forwardStackRef.current = []
        historyStackRef.current.push({
          path: currentPathRef.current,
          title:
            typeof document !== 'undefined'
              ? document.title
              : currentPathRef.current,
        })
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
    return stack.length > 0 ? stack[stack.length - 1].path : undefined
  }, [])

  const popHistory = useCallback(() => {
    const stack = historyStackRef.current
    const currentPath = currentPathRef.current
    const currentTitle =
      typeof document !== 'undefined' ? document.title : currentPath
    const entry = stack.pop()
    if (entry) {
      forwardStackRef.current.push({ path: currentPath, title: currentTitle })
    }
    return entry?.path
  }, [])

  const getBackStack = useCallback(() => {
    return [...historyStackRef.current]
  }, [])

  const popUntil = useCallback((path: string) => {
    const stack = historyStackRef.current
    const i = stack.findIndex((e) => e.path === path)
    if (i === -1) return undefined
    const currentPath = currentPathRef.current
    const currentTitle =
      typeof document !== 'undefined' ? document.title : currentPath
    stack.splice(i)
    forwardStackRef.current.push({ path: currentPath, title: currentTitle })
    return path
  }, [])

  const skipNextPush = useCallback(() => {
    skipNextPushRef.current = true
  }, [])

  const hasForwardHistory = useCallback(() => {
    return forwardStackRef.current.length > 0
  }, [])

  const popForward = useCallback(() => {
    const entry = forwardStackRef.current.pop()
    return entry?.path
  }, [])

  const value: NavigationHistoryContextType = {
    hasInternalHistory,
    getPreviousPath,
    popHistory,
    getBackStack,
    popUntil,
    skipNextPush,
    hasForwardHistory,
    popForward,
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
