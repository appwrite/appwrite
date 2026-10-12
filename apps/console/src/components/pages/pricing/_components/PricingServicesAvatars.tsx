import { MarketingProductPills } from '@/components/pages/marketing/MarketingProductPills'
import { useT } from '@/lib/i18n/translate'
import { marketingProductToolkit } from '@/lib/marketing/product-toolkit'

export function PricingServicesAvatars() {
  const t = useT()
  return (
    <div className="mx-auto mt-20 max-w-3xl text-center sm:mt-24 lg:mt-28">
      <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('All platform services included')}
      </p>
      <p className="mx-auto mt-2 max-w-2xl text-[13px] leading-6 text-muted-foreground sm:text-[14px] sm:leading-7">
        {t('Every plan includes the full Appwrite platform toolkit.')} {/* pragma: allowlist secret */}
      </p>
      <MarketingProductPills
        className="mt-5 sm:mt-6"
        build={marketingProductToolkit.build}
        deploy={marketingProductToolkit.deploy}
        protect={marketingProductToolkit.protect}
      />
    </div>
  )
}
