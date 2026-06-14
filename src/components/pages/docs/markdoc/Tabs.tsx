import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'
import { BLOG_BODY_TEXT_SIZE_CLASS, type MarkdocProseVariant } from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'

type TabsContextValue = {
  activeId: string
  setActiveId: (id: string) => void
  registerTab: (id: string, title: string) => void
  tabs: Array<{ id: string; title: string }>
}

const TabsContext = createContext<TabsContextValue | null>(null)

export function useTabsContext() {
  return useContext(TabsContext)
}

export function Tabs({
  children,
  proseVariant = 'docs',
}: {
  children: ReactNode
  proseVariant?: MarkdocProseVariant
}) {
  const [activeId, setActiveId] = useState('')
  const [tabs, setTabs] = useState<Array<{ id: string; title: string }>>([])
  const tabTextClass =
    proseVariant === 'blog' ? BLOG_BODY_TEXT_SIZE_CLASS : 'text-[13px]'

  const value = useMemo(
    () => ({
      activeId,
      setActiveId,
      registerTab: (id: string, title: string) => {
        setTabs((prev) => {
          if (prev.some((tab) => tab.id === id)) return prev
          if (prev.length === 0) setActiveId(id)
          return [...prev, { id, title }]
        })
      },
      tabs,
    }),
    [activeId, tabs],
  )

  return (
    <TabsContext.Provider value={value}>
      <div className="not-prose my-6 overflow-hidden rounded-xl border border-border bg-background">
        {tabs.length > 1 ? (
          <div className="flex gap-1 overflow-x-auto border-b border-border px-4 pt-3">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveId(tab.id)}
                className={cn(
                  'shrink-0 cursor-pointer border-b-2 px-3 py-2 transition-colors',
                  tabTextClass,
                  activeId === tab.id
                    ? 'border-white text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
                )}
              >
                {tab.title}
              </button>
            ))}
          </div>
        ) : null}
        <div className="px-4 py-3">{children}</div>
      </div>
    </TabsContext.Provider>
  )
}

export function TabsItem({
  id,
  title,
  children,
}: {
  id?: string
  title?: string
  children?: ReactNode
}) {
  const ctx = useContext(TabsContext)
  const tabId = id ?? title ?? 'tab'

  if (ctx) {
    ctx.registerTab(tabId, title ?? tabId)
    if (ctx.activeId !== tabId) return null
  }

  return <div>{children}</div>
}
