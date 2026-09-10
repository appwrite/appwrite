import { useKeyboardShortcutsContext } from '@/components/global/providers/KeyboardShortcuts'

/**
 * Hook to access command center controls from any component
 */
export function useCommandCenter() {
  const context = useKeyboardShortcutsContext()
  
  return {
    openCommandCenter: context.openCommandCenter,
    openCommandCenterPage: context.openCommandCenterPage,
    closeCommandCenter: context.closeCommandCenter,
    isCommandCenterOpen: context.isCommandCenterOpen,
  }
}
