import { useMemo, useState } from 'react'
import { Link, useLocation } from '@tanstack/react-router'
import { ArrowRight, ChevronDown } from 'lucide-react'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { SheetClose } from '@/components/ui/sheet'
import {
  MARKETING_PRODUCT_NAV_CATEGORIES,
  PRODUCT_NAV_REGISTRY,
  isProductId,
} from '@/lib/products/registry'
import type { ProductNavItemId } from '@/lib/products/types'
import { cn } from '@/lib/utils'

const NAV_TRIGGER_CLASS =
  'inline-flex h-9 cursor-pointer items-center gap-1 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground'

type MarketingProductsNavPanelProps = {
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
  compact?: boolean
  closeSheet?: boolean
}

function getActiveNavItemId(pathname: string): ProductNavItemId | undefined {
  const productMatch = pathname.match(/^\/products\/([^/]+)/)
  if (productMatch?.[1] && isProductId(productMatch[1])) {
    return productMatch[1]
  }

  if (pathname === '/domains' || pathname.startsWith('/domains/')) {
    return 'domains'
  }

  let bestMatch: ProductNavItemId | undefined
  let bestLength = 0

  for (const item of Object.values(PRODUCT_NAV_REGISTRY)) {
    if (!item.href.startsWith('/docs')) continue
    if (pathname === item.href || pathname.startsWith(`${item.href}/`)) {
      if (item.href.length > bestLength) {
        bestLength = item.href.length
        bestMatch = item.id
      }
    }
  }

  return bestMatch
}

function ProductNavLink({
  navItemId,
  isActive,
  onNavigate,
  variant = 'default',
  closeSheet = false,
}: {
  navItemId: ProductNavItemId
  isActive: boolean
  onNavigate?: () => void
  variant?: 'default' | 'compact' | 'dense'
  closeSheet?: boolean
}) {
  const item = PRODUCT_NAV_REGISTRY[navItemId]
  const Icon = item.icon
  const isDense = variant === 'dense'
  const isCompact = variant === 'compact'

  const link = (
    <Link
      to={item.href}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      className={cn(
        'group block cursor-pointer rounded-lg border border-transparent text-start transition-colors',
        isDense && 'flex items-center gap-2.5 px-2 py-2 hover:bg-accent/40',
        isCompact && 'flex items-start gap-3 px-3 py-2.5 hover:bg-accent/40',
        !isDense &&
          !isCompact &&
          'flex items-start gap-3 p-3 hover:border-border/80 hover:bg-accent/40',
        isActive &&
          (isDense || isCompact
            ? 'bg-muted/30'
            : 'border-border bg-muted/30'),
      )}
    >
      <span
        className={cn(
          'flex shrink-0 items-center justify-center rounded-md border border-border bg-muted/40',
          isDense ? 'size-7' : 'size-8',
        )}
      >
        <Icon
          className={cn('text-muted-foreground', isDense ? 'size-3.5' : 'size-4')}
          aria-hidden
        />
      </span>

      <span className={cn('min-w-0', isDense ? 'flex-1' : 'flex-1')}>
        <span
          className={cn(
            'block font-semibold text-foreground',
            isDense ? 'text-[12px]' : 'text-[13px]',
          )}
        >
          {item.name}
        </span>
        <span
          className={cn(
            'block text-muted-foreground',
            isDense
              ? 'mt-0.5 line-clamp-1 text-[11px] leading-4'
              : isCompact
                ? 'mt-0.5 line-clamp-2 text-[12px] leading-5'
                : 'mt-0.5 text-[12px] leading-5',
          )}
        >
          {item.tagline}
        </span>
      </span>

      {!isActive && !isDense && !isCompact ? (
        <ArrowRight
          className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-muted-foreground/70 rtl:rotate-180 rtl:group-hover:-translate-x-0.5"
          aria-hidden
        />
      ) : null}
    </Link>
  )

  if (closeSheet) {
    return <SheetClose asChild>{link}</SheetClose>
  }

  return link
}

function ProductsNavCategorySection({
  label,
  navItemIds,
  activeNavItemId,
  onNavigate,
  variant = 'dense',
  closeSheet = false,
}: {
  label: string
  navItemIds: readonly ProductNavItemId[]
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
  variant?: 'dense' | 'compact'
  closeSheet?: boolean
}) {
  return (
    <div className="min-w-0">
      <p className="px-2 pb-1.5 text-start text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div
        className={cn(
          variant === 'dense'
            ? 'grid grid-cols-3 gap-0.5'
            : 'space-y-0.5',
        )}
      >
        {navItemIds.map((navItemId) => (
          <ProductNavLink
            key={navItemId}
            navItemId={navItemId}
            isActive={navItemId === activeNavItemId}
            onNavigate={onNavigate}
            variant={variant}
            closeSheet={closeSheet}
          />
        ))}
      </div>
    </div>
  )
}

function DesktopProductsNavPanel({
  activeNavItemId,
  onNavigate,
}: {
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
}) {
  return (
    <div className="text-start">
      <div className="flex items-baseline justify-between gap-4 border-b border-border bg-muted/15 px-4 py-2.5">
        <p className="shrink-0 text-start text-[13px] font-semibold text-foreground">Platform products</p>
        <p className="min-w-0 truncate text-start text-[11px] text-muted-foreground">
          Build, deploy, and scale on one backend platform
        </p>
      </div>

      <div className="space-y-4 p-3">
        {MARKETING_PRODUCT_NAV_CATEGORIES.map((category) => (
          <ProductsNavCategorySection
            key={category.id}
            label={category.label}
            navItemIds={category.productIds}
            activeNavItemId={activeNavItemId}
            onNavigate={onNavigate}
          />
        ))}
      </div>

      <div className="border-t border-border bg-muted/20 px-4 py-2">
        <Link
          to="/home"
          onClick={onNavigate}
          className="inline-flex cursor-pointer items-center gap-1.5 text-start text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          View platform overview
          <ArrowRight className="size-3.5 rtl:rotate-180" aria-hidden />
        </Link>
      </div>
    </div>
  )
}

function MobileProductsNavPanel({
  activeNavItemId,
  closeSheet,
}: {
  activeNavItemId?: ProductNavItemId
  closeSheet?: boolean
}) {
  return (
    <div className="space-y-4 px-1 pb-1 text-start">
      {MARKETING_PRODUCT_NAV_CATEGORIES.map((category) => (
        <ProductsNavCategorySection
          key={category.id}
          label={category.label}
          navItemIds={category.productIds}
          activeNavItemId={activeNavItemId}
          variant="compact"
          closeSheet={closeSheet}
        />
      ))}

      {closeSheet ? (
        <div className="px-2 pt-1">
          <SheetClose asChild>
            <Link
              to="/home"
              className="inline-flex cursor-pointer items-center gap-1.5 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
              View platform overview
              <ArrowRight className="size-3.5 rtl:rotate-180" aria-hidden />
            </Link>
          </SheetClose>
        </div>
      ) : null}
    </div>
  )
}

export function MarketingProductsNavPanel({
  activeNavItemId,
  onNavigate,
  compact = false,
  closeSheet = false,
}: MarketingProductsNavPanelProps) {
  if (compact) {
    return (
      <MobileProductsNavPanel
        activeNavItemId={activeNavItemId}
        closeSheet={closeSheet}
      />
    )
  }

  return (
    <DesktopProductsNavPanel
      activeNavItemId={activeNavItemId}
      onNavigate={onNavigate}
    />
  )
}

export function MarketingProductsNavPopover() {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const activeNavItemId = useMemo(() => getActiveNavItemId(pathname), [pathname])

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={NAV_TRIGGER_CLASS}
          aria-expanded={open}
          aria-haspopup="dialog"
        >
          Products
          <ChevronDown
            className={cn('size-3.5 transition-transform duration-200', open && 'rotate-180')}
            aria-hidden
          />
        </button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        sideOffset={10}
        className="w-[min(calc(100vw-2rem),720px)] overflow-hidden rounded-xl border border-border bg-popover p-0 text-start shadow-lg md:w-[min(calc(100vw-2rem),840px)] lg:w-[min(calc(100vw-2rem),960px)] xl:w-[min(calc(100vw-2rem),1080px)]"
      >
        <MarketingProductsNavPanel
          activeNavItemId={activeNavItemId}
          onNavigate={() => setOpen(false)}
        />
      </PopoverContent>
    </Popover>
  )
}

export function MarketingProductsMobileNav() {
  const { pathname } = useLocation()
  const activeNavItemId = useMemo(() => getActiveNavItemId(pathname), [pathname])

  return (
    <Accordion type="single" collapsible className="px-1">
      <AccordionItem value="products" className="border-none">
        <AccordionTrigger className="flex h-10 w-full items-center justify-between rounded-md px-3 py-0 text-start text-[13px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground hover:no-underline [&[data-state=open]]:bg-accent [&[data-state=open]]:text-foreground">
          Products
        </AccordionTrigger>
        <AccordionContent className="pb-2 pt-1">
          <MarketingProductsNavPanel
            activeNavItemId={activeNavItemId}
            compact
            closeSheet
          />
        </AccordionContent>
      </AccordionItem>
    </Accordion>
  )
}

export function isMarketingProductsNavItem(item: { menu?: string; label: string }) {
  return item.menu === 'products' || item.label === 'Products'
}
