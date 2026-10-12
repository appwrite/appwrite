import { useKeyboardShortcutsContext } from '@/components/global/providers/keyboard-shortcuts-context'

/**
 * Hook to access command center controls from any component
 */
export function useCommandCenter() {
  const context = useKeyboardShortcutsContext()
  
  return {
    openCommandCenter: context.openCommandCenter,
    openCommandCenterPage: context.openCommandCenterPage,
    openBlogSearch: context.openBlogSearch,
    closeCommandCenter: context.closeCommandCenter,
    isCommandCenterOpen: context.isCommandCenterOpen,
  }
}
