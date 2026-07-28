import type { ReactNode } from 'react'
import { Search, X } from 'lucide-react'
import type {
  MarketplaceLinkItem,
  MarketplaceNavGroup,
  MarketplaceNavId,
  MarketplaceNavItem,
} from '@/lib/marketplace/marketplace-nav'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

type MarketplaceSidebarProps = {
  navGroups: MarketplaceNavGroup[]
  links: MarketplaceLinkItem[]
  activeNavId: MarketplaceNavId
  activeItem?: MarketplaceNavItem
  onNavChange: (id: MarketplaceNavId) => void
  searchValue: string
  onSearchChange: (value: string) => void
  getItemCount?: (id: MarketplaceNavId) => number
  onLinkAction?: (link: MarketplaceLinkItem) => void
  children: ReactNode
}

function SidebarSearch({
  value,
  onChange,
}: {
  value: string
  onChange: (value: string) => void
}) {
  const t = useT()
  return (
    <div className="relative w-full mb-2">
      <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
      <Input
        placeholder={t('Search apps...')}
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
          aria-label={t('Clear search')}
        >
          <X className="h-3.5 w-3.5" />
        </button>
      ) : null}
    </div>
  )
}

function NavButton({
  isActive,
  onClick,
  icon: Icon,
  label,
  count,
}: {
  isActive: boolean
  onClick: () => void
  icon: MarketplaceNavItem['icon']
  label: string
  count?: number
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-start text-[13px] font-medium transition-colors',
        isActive
          ? 'bg-accent text-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      <Icon className="h-4 w-4 shrink-0" />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      {count !== undefined && count > 0 && (
        <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground">
          {count}
        </span>
      )}
    </button>
  )
}

function NavGroupsList({
  navGroups,
  activeNavId,
  onNavChange,
  getItemCount,
}: Pick<
  MarketplaceSidebarProps,
  'navGroups' | 'activeNavId' | 'onNavChange' | 'getItemCount'
>) {
  const t = useT()
  return (
    <div className="flex flex-col gap-5 pt-3">
      {navGroups.map((group) => (
        <div key={group.id}>
          <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t(group.label)}
          </p>
          <div className="flex flex-col gap-0.5">
            {group.items.map((item) => (
              <NavButton
                key={item.id}
                isActive={activeNavId === item.id}
                onClick={() => onNavChange(item.id)}
                icon={item.icon}
                label={t(item.label)}
                count={getItemCount?.(item.id)}
              />
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

function SidebarLinks({
  links,
  onLinkAction,
}: Pick<MarketplaceSidebarProps, 'links' | 'onLinkAction'>) {
  const t = useT()
  return (
    <div className="mt-2 flex flex-col gap-0.5 border-t border-border pt-4">
      {links.map((link) => {
        const Icon = link.icon
        const className = cn(
          'flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-start text-[13px] font-medium transition-colors',
          link.action === 'add-app'
            ? 'text-foreground hover:bg-accent/50'
            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        )

        if (link.href) {
          return (
            <a
              key={link.id}
              href={link.href}
              target={link.external ? '_blank' : undefined}
              rel={link.external ? 'noopener noreferrer' : undefined}
              className={className}
            >
              <Icon className="h-4 w-4 shrink-0" />
              {t(link.label)}
            </a>
          )
        }

        return (
          <button
            key={link.id}
            type="button"
            className={className}
            onClick={() => onLinkAction?.(link)}
            {...(link.action === 'add-app'
              ? analyticsAttrs('create-marketplace-app')
              : {})}
          >
            <Icon className="h-4 w-4 shrink-0" />
            {t(link.label)}
          </button>
        )
      })}
    </div>
  )
}

export function MarketplaceSidebar({
  navGroups,
  links,
  activeNavId,
  activeItem,
  onNavChange,
  searchValue,
  onSearchChange,
  getItemCount,
  onLinkAction,
  children,
}: MarketplaceSidebarProps) {
  const t = useT()
  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:gap-8">
      <div className="lg:hidden space-y-2" aria-label={t('Marketplace section')}>
        <SidebarSearch value={searchValue} onChange={onSearchChange} />
        <Select
          value={activeNavId}
          onValueChange={(v) => onNavChange(v as MarketplaceNavId)}
        >
          <SelectTrigger size="sm" className="h-9 w-full text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {navGroups.map((group) => (
              <SelectGroup key={group.id}>
                <SelectLabel className="text-[11px] uppercase tracking-wider">
                  {t(group.label)}
                </SelectLabel>
                {group.items.map((item) => (
                  <SelectItem
                    key={item.id}
                    value={item.id}
                    className="text-[13px]"
                  >
                    <span className="flex items-center gap-2">
                      <item.icon className="h-4 w-4 shrink-0" />
                      {t(item.label)}
                    </span>
                  </SelectItem>
                ))}
              </SelectGroup>
            ))}
          </SelectContent>
        </Select>
        <div className="lg:hidden">
          <SidebarLinks links={links} onLinkAction={onLinkAction} />
        </div>
      </div>

      <nav
        className="hidden lg:flex sticky top-4 w-52 shrink-0 flex-col self-start"
        aria-label={t('Marketplace navigation')}
      >
        <SidebarSearch value={searchValue} onChange={onSearchChange} />
        <NavGroupsList
          navGroups={navGroups}
          activeNavId={activeNavId}
          onNavChange={onNavChange}
          getItemCount={getItemCount}
        />
        <SidebarLinks links={links} onLinkAction={onLinkAction} />
      </nav>

      <div className="min-w-0 flex-1">
        {activeItem && (
          <div className="mb-6">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t(activeItem.label)}
            </h2>
            {activeItem.description && (
              <p className="text-[13px] text-muted-foreground mt-1">
                {t(activeItem.description)}
              </p>
            )}
          </div>
        )}
        {children}
      </div>
    </div>
  )
}
