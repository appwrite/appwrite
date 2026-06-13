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
  PRODUCT_IDS,
  PRODUCT_REGISTRY,
} from '@/lib/products/registry'
import type { ProductId } from '@/lib/products/types'
import { cn } from '@/lib/utils'

const NAV_TRIGGER_CLASS =
  'inline-flex h-9 items-center gap-1 rounded-md px-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground'

type MarketingProductsNavPanelProps = {
  activeProductId?: ProductId
  onNavigate?: () => void
  compact?: boolean
  closeSheet?: boolean
}

function getActiveProductId(pathname: string): ProductId | undefined {
  const match = pathname.match(/^\/products\/([^/]+)/)
  if (!match?.[1]) return undefined
  const id = match[1]
  return id in PRODUCT_REGISTRY ? (id as ProductId) : undefined
}

function ProductNavLink({
  productId,
  isActive,
  onNavigate,
  variant = 'default',
  closeSheet = false,
}: {
  productId: ProductId
  isActive: boolean
  onNavigate?: () => void
  variant?: 'default' | 'compact' | 'dense'
  closeSheet?: boolean
}) {
  const product = PRODUCT_REGISTRY[productId]
  const Icon = product.icon
  const isDense = variant === 'dense'
  const isCompact = variant === 'compact'

  const link = (
    <Link
      to="/products/$productId"
      params={{ productId }}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      className={cn(
        'group block rounded-lg border border-transparent text-left transition-colors',
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
          {product.name}
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
          {product.tagline}
        </span>
      </span>

      {!isActive && !isDense && !isCompact ? (
        <ArrowRight
          className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-muted-foreground/70"
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

function DesktopProductsNavPanel({
  activeProductId,
  onNavigate,
}: {
  activeProductId?: ProductId
  onNavigate?: () => void
}) {
  return (
    <div>
      <div className="flex items-baseline justify-between gap-4 border-b border-border bg-muted/15 px-4 py-2.5">
        <p className="shrink-0 text-[13px] font-semibold text-foreground">Platform products</p>
        <p className="min-w-0 truncate text-[11px] text-muted-foreground">
          Build, deploy, and scale on one backend platform
        </p>
      </div>

      <div className="grid grid-cols-2 gap-0.5 p-3 sm:grid-cols-3">
        {PRODUCT_IDS.map((productId) => (
          <ProductNavLink
            key={productId}
            productId={productId}
            isActive={productId === activeProductId}
            onNavigate={onNavigate}
            variant="dense"
          />
        ))}
      </div>

      <div className="border-t border-border bg-muted/20 px-4 py-2">
        <Link
          to="/home"
          onClick={onNavigate}
          className="inline-flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          View platform overview
          <ArrowRight className="size-3.5" aria-hidden />
        </Link>
      </div>
    </div>
  )
}

function MobileProductsNavPanel({
  activeProductId,
  closeSheet,
}: {
  activeProductId?: ProductId
  closeSheet?: boolean
}) {
  return (
    <div className="space-y-4 px-1 pb-1">
      <div className="space-y-0.5">
        {PRODUCT_IDS.map((productId) => (
          <ProductNavLink
            key={productId}
            productId={productId}
            isActive={productId === activeProductId}
            variant="compact"
            closeSheet={closeSheet}
          />
        ))}
      </div>

      {closeSheet ? (
        <div className="px-2 pt-1">
          <SheetClose asChild>
            <Link
              to="/home"
              className="inline-flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
              View platform overview
              <ArrowRight className="size-3.5" aria-hidden />
            </Link>
          </SheetClose>
        </div>
      ) : null}
    </div>
  )
}

export function MarketingProductsNavPanel({
  activeProductId,
  onNavigate,
  compact = false,
  closeSheet = false,
}: MarketingProductsNavPanelProps) {
  if (compact) {
    return (
      <MobileProductsNavPanel
        activeProductId={activeProductId}
        closeSheet={closeSheet}
      />
    )
  }

  return (
    <DesktopProductsNavPanel
      activeProductId={activeProductId}
      onNavigate={onNavigate}
    />
  )
}

export function MarketingProductsNavPopover() {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const activeProductId = useMemo(() => getActiveProductId(pathname), [pathname])

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
        className="w-[min(calc(100vw-2rem),720px)] overflow-hidden rounded-xl border border-border bg-popover p-0 shadow-lg"
      >
        <MarketingProductsNavPanel
          activeProductId={activeProductId}
          onNavigate={() => setOpen(false)}
        />
      </PopoverContent>
    </Popover>
  )
}

export function MarketingProductsMobileNav() {
  const { pathname } = useLocation()
  const activeProductId = useMemo(() => getActiveProductId(pathname), [pathname])

  return (
    <Accordion type="single" collapsible className="px-1">
      <AccordionItem value="products" className="border-none">
        <AccordionTrigger className="flex h-10 items-center rounded-md px-3 py-0 text-[13px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground hover:no-underline [&[data-state=open]]:bg-accent [&[data-state=open]]:text-foreground">
          Products
        </AccordionTrigger>
        <AccordionContent className="pb-2 pt-1">
          <MarketingProductsNavPanel
            activeProductId={activeProductId}
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
