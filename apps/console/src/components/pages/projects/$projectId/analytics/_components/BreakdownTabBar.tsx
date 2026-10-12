import { useMemo } from 'react'
import { Check, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import type { BreakdownTab } from './BreakdownPanel'

/** Same look as `TabsTrigger`, for the group tab that opens a menu. */
const GROUP_TRIGGER_CLASS =
  'inline-flex h-[calc(100%-1px)] flex-1 cursor-pointer items-center justify-center gap-1 whitespace-nowrap rounded-md border border-transparent px-2 py-1 text-sm font-medium text-muted-foreground transition-[color,box-shadow,background-color] hover:text-foreground focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-inset focus-visible:ring-ring/50 data-[state=active]:border-input data-[state=active]:bg-background data-[state=active]:text-foreground dark:data-[state=active]:border-border'

type Item =
  | { kind: 'tab'; tab: BreakdownTab }
  | { kind: 'group'; label: string; tabs: BreakdownTab[] }

/** Consecutive tabs sharing a `group` collapse into one menu tab. */
function toItems(tabs: BreakdownTab[]): Item[] {
  const items: Item[] = []
  for (const tab of tabs) {
    const last = items[items.length - 1]
    if (tab.group && last?.kind === 'group' && last.label === tab.group) {
      last.tabs.push(tab)
    } else if (tab.group) {
      items.push({ kind: 'group', label: tab.group, tabs: [tab] })
    } else {
      items.push({ kind: 'tab', tab })
    }
  }
  return items
}

/**
 * Dimension tabs for a breakdown card or its modal. Grouped tabs (e.g. the
 * five UTM parameters) sit behind a single "Campaigns" tab with a menu, so a
 * card can hold related dimensions without a long tab row.
 */
export function BreakdownTabBar({
  tabs,
  value,
  onValueChange,
}: {
  tabs: BreakdownTab[]
  value: string
  onValueChange: (tabId: string) => void
}) {
  const t = useT()
  const items = useMemo(() => toItems(tabs), [tabs])

  return (
    <Tabs value={value} onValueChange={onValueChange}>
      <TabsList>
        {items.map((item) =>
          item.kind === 'tab' ? (
            <TabsTrigger key={item.tab.id} value={item.tab.id}>
              {t(item.tab.label)}
            </TabsTrigger>
          ) : (
            <GroupTab
              key={`group-${item.label}`}
              label={item.label}
              tabs={item.tabs}
              value={value}
              onValueChange={onValueChange}
            />
          ),
        )}
      </TabsList>
    </Tabs>
  )
}

function GroupTab({
  label,
  tabs,
  value,
  onValueChange,
}: {
  label: string
  tabs: BreakdownTab[]
  value: string
  onValueChange: (tabId: string) => void
}) {
  const t = useT()
  const selected = tabs.find((tab) => tab.id === value)

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          data-state={selected ? 'active' : 'inactive'}
          className={GROUP_TRIGGER_CLASS}
        >
          {/* Shows the picked parameter once one is active. */}
          {t(selected?.label ?? label)}
          <ChevronDown className="h-3.5 w-3.5 opacity-70" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="min-w-44">
        {tabs.map((tab) => (
          <DropdownMenuItem
            key={tab.id}
            onSelect={() => onValueChange(tab.id)}
            className="justify-between text-[13px]"
          >
            {t(tab.label)}
            <Check
              className={cn(
                'h-3.5 w-3.5',
                tab.id === value ? 'opacity-100' : 'opacity-0',
              )}
            />
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
