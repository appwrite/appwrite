import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'
import { zeroServiceItems } from '@/lib/zero/marketing-content'

/** Every service a Zero project has, and what each allows until it is claimed. */
export function ZeroServicesGrid() {
  const t = useT()

  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {zeroServiceItems.map((service, index) => {
        const product = PRODUCT_REGISTRY[service.id]
        const afterClaim = service.id === 'messaging'
        return (
          <li
            key={service.id}
            className="product-hero-rise"
            style={riseStyle(index * 70)}
          >
            <MarketingSiteLink
              href={product.path}
              className={cn(
                'link-unstyled group relative isolate flex h-full items-start gap-3 overflow-hidden rounded-xl border border-border bg-card/45 p-4 text-start transition-[border-color,transform] duration-300 hover:-translate-y-0.5 hover:border-[rgb(var(--tone-rgb)/0.45)] motion-reduce:transition-none motion-reduce:hover:translate-y-0',
              )}
            >
              <span
                className="product-tone-glow pointer-events-none absolute -start-1/3 -top-full -z-10 h-[260%] w-[90%] opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                aria-hidden
              />
              <ProductIconTile icon={product.icon} size="sm" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                  <h3 className="text-[14px] font-semibold text-foreground">
                    {t(product.name)}
                  </h3>
                  <Badge
                    variant={afterClaim ? 'outline' : 'secondary'}
                    className="text-[10px]"
                  >
                    {t(service.limit)}
                  </Badge>
                </div>
                <p className="mt-1 text-[13px] leading-5 text-muted-foreground">
                  {t(service.detail)}
                </p>
              </div>
            </MarketingSiteLink>
          </li>
        )
      })}
    </ul>
  )
}
