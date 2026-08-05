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
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  MARKETING_PRODUCT_NAV_CATEGORIES,
  PRODUCT_NAV_REGISTRY,
  isProductId,
  type ProductNavCategory,
} from '@/lib/products/registry'
import { isProductNavItemNew } from '@/lib/products/new-badge'
import type { ProductNavItemId } from '@/lib/products/types'
import { ProductNewBadge } from '@/components/global/shared/ProductNewBadge'
import { cn } from '@/lib/utils'
import { useI18n } from '@/lib/i18n'
import {
  analyticsAttrs,
  getMarketingProductAnalyticsAction,
} from '@/lib/analytics-actions'

const NAV_TRIGGER_CLASS =
  'inline-flex h-9 cursor-pointer items-center gap-1 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground'

type MarketingProductsNavPanelProps = {
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
  compact?: boolean
  closeSheet?: boolean
}

type ProductNamesCopy = {
  auth: string
  databases: string
  storage: string
  functions: string
  messaging: string
  sites: string
}

type ProductNavigationItemsCopy = {
  authTagline: string
  databasesTagline: string
  storageTagline: string
  functionsTagline: string
  messagingTagline: string
  sitesTagline: string
  realtimeName: string
  realtimeTagline: string
  agentName: string
  agentTagline: string
  domainsName: string
  domainsTagline: string
  firewallName: string
  firewallTagline: string
  advisorName: string
  advisorTagline: string
}

type ProductNavigationCategoriesCopy = {
  build: string
  deploy: string
  protect: string
}

type ProductNavigationCopy = {
  triggerLabel: string
  desktopTitle: string
  desktopSubtitle: string
  newLabel: string
  categories: ProductNavigationCategoriesCopy
  items: ProductNavigationItemsCopy
}

function useVisibleMarketingProductNavCategories(): ProductNavCategory[] {
  const { features } = useConsoleProfile()

  return useMemo(
    () =>
      MARKETING_PRODUCT_NAV_CATEGORIES.map((category) => ({
        ...category,
        productIds: category.productIds.filter((id) => {
          if (id === 'firewall') return features.firewall
          if (id === 'domains') return features.domains
          if (id === 'agent') return features.agent
          return true
        }),
      })).filter((category) => category.productIds.length > 0),
    [features.agent, features.domains, features.firewall],
  )
}

function getLocalizedCategoryLabel(
  categoryId: 'build' | 'deploy' | 'protect',
  categoriesCopy: ProductNavigationCategoriesCopy,
): string {
  if (categoryId === 'build') return categoriesCopy.build
  if (categoryId === 'deploy') return categoriesCopy.deploy
  return categoriesCopy.protect
}

function getLocalizedProductNavItemName(
  navItemId: ProductNavItemId,
  fallbackName: string,
  productNamesCopy: ProductNamesCopy,
  navigationItemsCopy: ProductNavigationItemsCopy,
): string {
  if (navItemId === 'auth') return productNamesCopy.auth
  if (navItemId === 'databases') return productNamesCopy.databases
  if (navItemId === 'storage') return productNamesCopy.storage
  if (navItemId === 'functions') return productNamesCopy.functions
  if (navItemId === 'messaging') return productNamesCopy.messaging
  if (navItemId === 'sites') return productNamesCopy.sites
  if (navItemId === 'realtime') return navigationItemsCopy.realtimeName
  if (navItemId === 'agent') return navigationItemsCopy.agentName
  if (navItemId === 'domains') return navigationItemsCopy.domainsName
  if (navItemId === 'firewall') return navigationItemsCopy.firewallName
  if (navItemId === 'advisor') return navigationItemsCopy.advisorName
  return fallbackName
}

function getLocalizedProductNavItemTagline(
  navItemId: ProductNavItemId,
  fallbackTagline: string,
  navigationItemsCopy: ProductNavigationItemsCopy,
): string {
  if (navItemId === 'auth') return navigationItemsCopy.authTagline
  if (navItemId === 'databases') return navigationItemsCopy.databasesTagline
  if (navItemId === 'storage') return navigationItemsCopy.storageTagline
  if (navItemId === 'functions') return navigationItemsCopy.functionsTagline
  if (navItemId === 'messaging') return navigationItemsCopy.messagingTagline
  if (navItemId === 'sites') return navigationItemsCopy.sitesTagline
  if (navItemId === 'realtime') return navigationItemsCopy.realtimeTagline
  if (navItemId === 'agent') return navigationItemsCopy.agentTagline
  if (navItemId === 'domains') return navigationItemsCopy.domainsTagline
  if (navItemId === 'firewall') return navigationItemsCopy.firewallTagline
  if (navItemId === 'advisor') return navigationItemsCopy.advisorTagline
  return fallbackTagline
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
  productNamesCopy,
  navigationItemsCopy,
  newLabel,
  variant = 'default',
  closeSheet = false,
}: {
  navItemId: ProductNavItemId
  isActive: boolean
  onNavigate?: () => void
  productNamesCopy: ProductNamesCopy
  navigationItemsCopy: ProductNavigationItemsCopy
  newLabel: string
  variant?: 'default' | 'compact' | 'dense'
  closeSheet?: boolean
}) {
  const item = PRODUCT_NAV_REGISTRY[navItemId]
  const Icon = item.icon
  const localizedName = getLocalizedProductNavItemName(
    navItemId,
    item.name,
    productNamesCopy,
    navigationItemsCopy,
  )
  const localizedTagline = getLocalizedProductNavItemTagline(
    navItemId,
    item.tagline,
    navigationItemsCopy,
  )
  const isDense = variant === 'dense'
  const isCompact = variant === 'compact'
  const productAnalytics = getMarketingProductAnalyticsAction(navItemId)
  const isNew = isProductNavItemNew(navItemId)

  const link = (
    <Link
      to={item.href}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      {...(productAnalytics ? analyticsAttrs(productAnalytics) : {})}
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
            'flex items-center gap-1.5 font-semibold text-foreground',
            isDense ? 'text-[12px]' : 'text-[13px]',
          )}
        >
          <span className="min-w-0 truncate">{localizedName}</span>
          {isNew ? <ProductNewBadge label={newLabel} /> : null}
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
          {localizedTagline}
        </span>
      </span>

      {!isActive && !isDense && !isCompact ? (
        <ArrowRight
          className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-muted-foreground/70 rtl:group-hover:-translate-x-0.5"
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
  productNamesCopy,
  navigationItemsCopy,
  newLabel,
  variant = 'dense',
  closeSheet = false,
}: {
  label: string
  navItemIds: readonly ProductNavItemId[]
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
  productNamesCopy: ProductNamesCopy
  navigationItemsCopy: ProductNavigationItemsCopy
  newLabel: string
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
            productNamesCopy={productNamesCopy}
            navigationItemsCopy={navigationItemsCopy}
            newLabel={newLabel}
            variant={variant}
            closeSheet={closeSheet}
          />
        ))}
      </div>
    </div>
  )
}

function DesktopProductsNavPanel({
  categories,
  activeNavItemId,
  onNavigate,
  productNamesCopy,
  navigationCopy,
}: {
  categories: readonly ProductNavCategory[]
  activeNavItemId?: ProductNavItemId
  onNavigate?: () => void
  productNamesCopy: ProductNamesCopy
  navigationCopy: ProductNavigationCopy
}) {
  return (
    <div className="text-start">
      <div className="border-b border-border bg-muted/15 px-4 py-2.5">
        <p className="text-start text-[13px] font-semibold text-foreground">
          {navigationCopy.desktopTitle}
        </p>
      </div>

      <div className="space-y-4 p-3">
        {categories.map((category) => (
          <ProductsNavCategorySection
            key={category.id}
            label={getLocalizedCategoryLabel(category.id, navigationCopy.categories)}
            navItemIds={category.productIds}
            activeNavItemId={activeNavItemId}
            onNavigate={onNavigate}
            productNamesCopy={productNamesCopy}
            navigationItemsCopy={navigationCopy.items}
            newLabel={navigationCopy.newLabel}
          />
        ))}
      </div>
    </div>
  )
}

function MobileProductsNavPanel({
  categories,
  activeNavItemId,
  productNamesCopy,
  navigationCopy,
  closeSheet,
}: {
  categories: readonly ProductNavCategory[]
  activeNavItemId?: ProductNavItemId
  productNamesCopy: ProductNamesCopy
  navigationCopy: ProductNavigationCopy
  closeSheet?: boolean
}) {
  return (
    <div className="space-y-4 px-1 pb-1 text-start">
      {categories.map((category) => (
        <ProductsNavCategorySection
          key={category.id}
          label={getLocalizedCategoryLabel(category.id, navigationCopy.categories)}
          navItemIds={category.productIds}
          activeNavItemId={activeNavItemId}
          productNamesCopy={productNamesCopy}
          navigationItemsCopy={navigationCopy.items}
          newLabel={navigationCopy.newLabel}
          variant="compact"
          closeSheet={closeSheet}
        />
      ))}
    </div>
  )
}

export function MarketingProductsNavPanel({
  activeNavItemId,
  onNavigate,
  compact = false,
  closeSheet = false,
}: MarketingProductsNavPanelProps) {
  const { catalog } = useI18n()
  const productNamesCopy = catalog.website.products.productNames
  const navigationCopy = catalog.website.products.navigation
  const categories = useVisibleMarketingProductNavCategories()

  if (compact) {
    return (
      <MobileProductsNavPanel
        categories={categories}
        activeNavItemId={activeNavItemId}
        productNamesCopy={productNamesCopy}
        navigationCopy={navigationCopy}
        closeSheet={closeSheet}
      />
    )
  }

  return (
    <DesktopProductsNavPanel
      categories={categories}
      activeNavItemId={activeNavItemId}
      onNavigate={onNavigate}
      productNamesCopy={productNamesCopy}
      navigationCopy={navigationCopy}
    />
  )
}

export function MarketingProductsNavPopover() {
  const { catalog } = useI18n()
  const navigationCopy = catalog.website.products.navigation
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
          {...analyticsAttrs('marketing-nav-products')}
        >
          {navigationCopy.triggerLabel}
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
  const { catalog } = useI18n()
  const navigationCopy = catalog.website.products.navigation
  const { pathname } = useLocation()
  const activeNavItemId = useMemo(() => getActiveNavItemId(pathname), [pathname])

  return (
    <Accordion type="single" collapsible className="px-1">
      <AccordionItem value="products" className="border-none">
        <AccordionTrigger
          className="flex h-10 w-full items-center justify-between rounded-md px-3 py-0 text-start text-[13px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground hover:no-underline [&[data-state=open]]:bg-accent [&[data-state=open]]:text-foreground"
          {...analyticsAttrs('marketing-nav-products')}
        >
          {navigationCopy.triggerLabel}
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
  return item.menu === 'products' || item.label === 'Products' || item.label === 'מוצרים'
}
