import { useEffect, useMemo, useRef, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import type { LucideIcon } from 'lucide-react'
import { Search, X } from 'lucide-react'
import {
  matchesSettingsSearch,
  sectionHasMatchingCards,
  type SettingsCardIndexEntry,
} from '@/lib/settings-search'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  SettingsSearchProvider,
  SettingsSearchProviderLocal,
  DeferEmptyResultsProvider,
  useSettingsSearch,
} from './SettingsSearchContext'

export const SETTINGS_LAYOUT_NAV_WIDTH_CLASS = 'w-56'

export type SettingsLayoutNavItem = {
  id: string
  label: string
  icon: LucideIcon
  keywords?: string[]
  /** Route `to` — typed loosely so org/project layouts can share this shell. */
  to: string
  params?: Record<string, string>
}

type SettingsLayoutShellProps = {
  navItems: SettingsLayoutNavItem[]
  activeSectionId: string
  cardIndex: SettingsCardIndexEntry[]
  onNavigateToSection: (sectionId: string) => void
  children: ReactNode
  searchPlaceholder?: string
  mobileNavAriaLabel?: string
  desktopNavAriaLabel?: string
  /** When set, search state is controlled by the parent (e.g. org overview). */
  searchQuery?: string
  onSearchQueryChange?: (value: string) => void
  navWidthClassName?: string
}

function SettingsSearchInput({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (value: string) => void
  placeholder: string
}) {
  return (
    <div className="relative w-full mb-2">
      <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
      <Input
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={cn(
          'h-9 w-full rounded-md border border-border bg-accent/50 ps-10 pe-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
          value && 'pe-9',
        )}
      />
      {value ? (
        <button
          type="button"
          onClick={() => onChange('')}
          className="absolute end-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground rounded p-0.5"
          aria-label="Clear search"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      ) : null}
    </div>
  )
}

function SettingsLayoutShellContent({
  navItems,
  activeSectionId,
  cardIndex,
  onNavigateToSection,
  children,
  searchPlaceholder = 'Search settings...',
  mobileNavAriaLabel = 'Settings section',
  desktopNavAriaLabel = 'Settings navigation',
  navWidthClassName = SETTINGS_LAYOUT_NAV_WIDTH_CLASS,
}: Omit<SettingsLayoutShellProps, 'searchQuery' | 'onSearchQueryChange'>) {
  const { query, setQuery } = useSettingsSearch()
  const q = query.trim().toLowerCase()

  const sectionIds = useMemo(() => navItems.map((item) => item.id), [navItems])

  const navMatchBySection = useMemo(() => {
    if (!q) return new Map<string, boolean>()
    return new Map(
      navItems.map((item) => [
        item.id,
        sectionHasMatchingCards(q, item.id, cardIndex) ||
          matchesSettingsSearch(q, {
            title: item.label,
            keywords: item.keywords,
          }),
      ]),
    )
  }, [q, navItems, cardIndex])

  const deferEmptyResults = Boolean(
    q &&
      !navMatchBySection.get(activeSectionId) &&
      sectionIds.some((id) => navMatchBySection.get(id)),
  )

  const onNavigateToSectionRef = useRef(onNavigateToSection)
  onNavigateToSectionRef.current = onNavigateToSection

  // Debounce auto-switch so typing does not navigate on every keystroke (which steals focus).
  // Do not list onNavigateToSection in deps — parents often pass inline handlers that change every render.
  useEffect(() => {
    if (!q) return

    if (navMatchBySection.get(activeSectionId)) return

    const target = sectionIds.find((id) => navMatchBySection.get(id))
    if (!target || target === activeSectionId) return

    const timeoutId = window.setTimeout(() => {
      onNavigateToSectionRef.current(target)
    }, 400)

    return () => window.clearTimeout(timeoutId)
  }, [q, activeSectionId, sectionIds, navMatchBySection])

  const navLinkClassName = (itemId: string, isActive: boolean) =>
    cn(
      'flex items-center gap-2 rounded-md px-3 py-2 text-[13px] font-medium transition-colors',
      isActive
        ? 'bg-accent text-foreground'
        : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      q && navMatchBySection.get(itemId) && !isActive
        ? 'ring-1 ring-ring/40'
        : undefined,
    )

  return (
    <div className="flex w-full min-w-0 flex-col gap-4 lg:flex-row lg:gap-8">
      <div className="lg:hidden space-y-2" aria-label={mobileNavAriaLabel}>
        <SettingsSearchInput
          value={query}
          onChange={setQuery}
          placeholder={searchPlaceholder}
        />
        <Select
          value={activeSectionId}
          onValueChange={onNavigateToSection}
        >
          <SelectTrigger size="sm" className="h-9 w-full text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {navItems.map((item) => (
              <SelectItem
                key={item.id}
                value={item.id}
                className="text-[13px]"
              >
                <span className="flex items-center gap-2">
                  <item.icon className="h-4 w-4 shrink-0" />
                  {item.label}
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <nav
        className={cn(
          'hidden lg:flex sticky top-4 shrink-0 flex-col gap-2 self-start',
          navWidthClassName,
        )}
        aria-label={desktopNavAriaLabel}
      >
        <SettingsSearchInput
          value={query}
          onChange={setQuery}
          placeholder={searchPlaceholder}
        />
        {navItems.map((item) => {
          const Icon = item.icon
          return (
            <Link
              key={item.id}
              to={item.to as '/'}
              params={item.params}
              className={navLinkClassName(item.id, activeSectionId === item.id)}
            >
              <Icon className="h-4 w-4 shrink-0" />
              {item.label}
            </Link>
          )
        })}
      </nav>

      <DeferEmptyResultsProvider deferEmptyResults={deferEmptyResults}>
        <div className="min-w-0 flex-1">{children}</div>
      </DeferEmptyResultsProvider>
    </div>
  )
}

export function SettingsLayoutShell({
  searchQuery,
  onSearchQueryChange,
  ...props
}: SettingsLayoutShellProps) {
  const isControlled =
    searchQuery !== undefined && onSearchQueryChange !== undefined

  if (isControlled) {
    return (
      <SettingsSearchProvider
        query={searchQuery}
        onQueryChange={onSearchQueryChange}
      >
        <SettingsLayoutShellContent {...props} />
      </SettingsSearchProvider>
    )
  }

  return (
    <SettingsSearchProviderLocal>
      <SettingsLayoutShellContent {...props} />
    </SettingsSearchProviderLocal>
  )
}
