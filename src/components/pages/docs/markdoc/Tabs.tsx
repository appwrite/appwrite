import {
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  BLOG_BODY_TEXT_SIZE_CLASS,
  type MarkdocProseVariant,
} from '@/lib/blog/prose-typography'
import { useUserOs } from '@/hooks/use-user-os'
import {
  matchTabToUserOs,
  orderTabsByPreferredOs,
  resolvePreferredOsTabId,
} from '@/lib/user-os'
import { cn } from '@/lib/utils'
import { TabsContext } from './tabs-context'

export function Tabs({
  children,
  proseVariant = 'docs',
}: {
  children: ReactNode
  proseVariant?: MarkdocProseVariant
}) {
  const { os } = useUserOs()
  const [activeId, setActiveId] = useState('')
  const [tabs, setTabs] = useState<Array<{ id: string; title: string }>>([])
  const userSelectedRef = useRef(false)
  const previousOsRef = useRef(os)
  const tabTextClass =
    proseVariant === 'blog' ? BLOG_BODY_TEXT_SIZE_CLASS : 'text-[13px]'

  const orderedTabs = useMemo(
    () => orderTabsByPreferredOs(tabs, os),
    [tabs, os],
  )

  const selectTab = useCallback((id: string) => {
    userSelectedRef.current = true
    setActiveId(id)
  }, [])

  const registerTab = useCallback((id: string, title: string) => {
    setTabs((prev) => {
      if (prev.some((tab) => tab.id === id)) return prev
      return [...prev, { id, title }]
    })
  }, [])

  useEffect(() => {
    if (tabs.length === 0) return

    const hasOsTabs = tabs.some(
      (tab) =>
        matchTabToUserOs(tab.id) != null ||
        matchTabToUserOs(tab.title) != null,
    )

    const osChanged = previousOsRef.current !== os
    if (osChanged) {
      previousOsRef.current = os
      if (hasOsTabs) userSelectedRef.current = false
      else return
    }

    if (userSelectedRef.current) return

    const preferred = hasOsTabs
      ? resolvePreferredOsTabId(tabs, os)
      : null
    const next = preferred ?? orderedTabs[0]?.id ?? ''
    if (next && next !== activeId) setActiveId(next)
  }, [tabs, os, orderedTabs, activeId])

  const value = useMemo(
    () => ({
      activeId,
      setActiveId: selectTab,
      registerTab,
      tabs: orderedTabs,
    }),
    [activeId, orderedTabs, selectTab, registerTab],
  )

  return (
    <TabsContext.Provider value={value}>
      <div className="not-prose my-6 overflow-hidden rounded-xl border border-border bg-background">
        {orderedTabs.length > 1 ? (
          <div className="flex gap-1 overflow-x-auto border-b border-border px-4 pt-3">
            {orderedTabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => selectTab(tab.id)}
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
  const tabTitle = title ?? tabId
  const registerTab = ctx?.registerTab

  useEffect(() => {
    registerTab?.(tabId, tabTitle)
  }, [registerTab, tabId, tabTitle])

  if (ctx && ctx.activeId !== tabId) return null

  return <div>{children}</div>
}
