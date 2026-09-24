import { createContext, useContext } from 'react'

export type TabsContextValue = {
  activeId: string
  setActiveId: (id: string) => void
  registerTab: (id: string, title: string) => void
  tabs: Array<{ id: string; title: string }>
}

export const TabsContext = createContext<TabsContextValue | null>(null)

export function useTabsContext() {
  return useContext(TabsContext)
}
