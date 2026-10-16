import {
  Children,
  isValidElement,
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

type TabsItemProps = {
  id?: string
  title?: string
  children?: ReactNode
}

function getTab({ id, title }: TabsItemProps) {
  const tabId = id ?? title ?? 'tab'
  return { id: tabId, title: title ?? tabId }
}

export function Tabs({
  children,
  proseVariant = 'docs',
}: {
  children: ReactNode
  proseVariant?: MarkdocProseVariant
}) {
  const { os } = useUserOs()
  // Read tabs from children during render so the server HTML has every panel.
  const tabs = useMemo(() => {
    const items: Array<{ id: string; title: string }> = []
    Children.forEach(children, (child) => {
      if (!isValidElement<TabsItemProps>(child)) return
      const tab = getTab(child.props)
      if (!items.some((item) => item.id === tab.id)) items.push(tab)
    })
    return items
  }, [children])
  const [activeId, setActiveId] = useState(() => tabs[0]?.id ?? '')
  const [mounted, setMounted] = useState(false)
  const userSelectedRef = useRef(false)
  const previousOsRef = useRef(os)
  const tabTextClass =
    proseVariant === 'blog' ? BLOG_BODY_TEXT_SIZE_CLASS : 'text-[13px]'

  useEffect(() => setMounted(true), [])

  // The OS is only known in the browser; keep source order until mount so
  // hydration matches the server HTML.
  const orderedTabs = useMemo(
    () => (mounted ? orderTabsByPreferredOs(tabs, os) : tabs),
    [mounted, tabs, os],
  )

  const selectTab = useCallback((id: string) => {
    userSelectedRef.current = true
    setActiveId(id)
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
      tabs: orderedTabs,
    }),
    [activeId, orderedTabs, selectTab],
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

export function TabsItem(props: TabsItemProps) {
  const ctx = useContext(TabsContext)
  const { id } = getTab(props)

  // Inactive panels stay in the HTML, hidden, so crawlers read every tab.
  return (
    <div hidden={ctx ? ctx.activeId !== id : undefined}>{props.children}</div>
  )
}
