import { ProductAvatarsList } from '@/components/global/shared/ProductAvatarsList'
import { formatPricingServiceList, pricingServices } from '@/lib/pricing/services'

export function PricingServicesAvatars() {
  return (
    <div className="mx-auto mt-20 max-w-3xl text-center sm:mt-24 lg:mt-28">
      <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        All platform services included
      </p>
      <p className="mx-auto mt-2 max-w-2xl text-[13px] leading-6 text-muted-foreground sm:text-[14px] sm:leading-7">
        Every plan includes {formatPricingServiceList()}.
      </p>
      <ProductAvatarsList
        className="mt-5 sm:mt-6"
        items={pricingServices}
        ariaLabel="Included Appwrite services"
      />
    </div>
  )
}
