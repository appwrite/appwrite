import {
  ArrowRight,
  Database,
  Fingerprint,
  Folder,
  Gauge,
  Globe,
  MessageSquare,
  Receipt,
  ScrollText,
  Shield,
  ShieldCheck,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { AppwriteMark } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import {
  enterpriseCapabilityGroups,
  enterpriseConsolidationOutcomes,
  enterpriseDeploymentHighlights,
  enterpriseDeploymentOptions,
  enterpriseDeploymentSharedBenefits,
  enterprisePlanCapabilities,
  enterpriseValueProps,
  enterpriseVendorSprawl,
} from '@/lib/enterprise/content'
import { allCustomerLogos } from '@/lib/home/customer-logos'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'

/* -------------------------------------------------------------------------------------------------
 * Customer logos: an open cloud, no tiles
 * -----------------------------------------------------------------------------------------------*/

export function EnterpriseLogoCloud() {
  return (
    <ul className="mx-auto grid max-w-6xl grid-cols-3 items-center gap-x-6 gap-y-10 sm:grid-cols-4 lg:grid-cols-5 lg:gap-x-12">
      {allCustomerLogos.map((logo, index) => (
        <li
          key={logo.src}
          className="product-hero-rise flex min-h-8 items-center justify-center"
          style={riseStyle(60 + index * 40)}
        >
          <TrustedByLogo
            src={logo.src}
            alt={logo.alt}
            width={logo.width}
            height={logo.height}
            mask={logo.mask}
            maskSrc={logo.maskSrc}
            inverseMask={logo.inverseMask}
            interactive={false}
            className={cn(
              'w-auto opacity-70 transition-opacity duration-200 hover:opacity-100',
              logo.size === 'lg' ? 'max-h-6 sm:max-h-7' : 'max-h-5 sm:max-h-6',
            )}
          />
        </li>
      ))}
    </ul>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Value props: open benefits with tone ticks
 * -----------------------------------------------------------------------------------------------*/

export function EnterpriseValueProps() {
  const t = useT()
  return (
    <ul className="grid gap-x-10 gap-y-10 sm:grid-cols-2">
      {enterpriseValueProps.map((item, index) => {
        const Icon = item.icon
        return (
          <li
            key={item.title}
            className="product-hero-rise relative border-t border-foreground/15 pt-5"
            style={riseStyle(120 + index * 90)}
          >
            <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
            <h3 className="flex items-center gap-2.5 text-[16px] font-medium text-foreground">
              {Icon ? <Icon className="size-[18px] shrink-0 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden /> : null}
              {t(item.title)}
            </h3>
            <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(item.description)}</p>
          </li>
        )
      })}
    </ul>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Consolidation: a stack of vendors into one platform
 * -----------------------------------------------------------------------------------------------*/

const VENDOR_ICONS: Record<(typeof enterpriseVendorSprawl)[number], LucideIcon> = {
  'Identity provider': Fingerprint,
  'Database service': Database,
  'File storage': Folder,
  'Function hosting': Zap,
  'Messaging provider': MessageSquare,
  'Web hosting': Globe,
  'Firewall and DDoS': Shield,
}

export function EnterpriseConsolidation() {
  const t = useT()
  return (
    <div className="grid min-w-0 items-center gap-12 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1.1fr)] lg:gap-10">
      <div>
        <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Without a platform')}</p>
        <ul className="mt-4 divide-y divide-foreground/10 border-y border-foreground/15">
          {enterpriseVendorSprawl.map((vendor, index) => {
            const Icon = VENDOR_ICONS[vendor]
            return (
              <li
                key={vendor}
                className="product-hero-rise flex items-center gap-3 py-3"
                style={riseStyle(80 + index * 70)}
              >
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-dashed border-foreground/25 text-muted-foreground">
                  <Icon className="size-4" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="min-w-0 flex-1 truncate text-[13px] text-foreground/75">{t(vendor)}</span>
                <span className="shrink-0 font-mono text-[10px] text-muted-foreground">{t('Separate contract')}</span>
              </li>
            )
          })}
        </ul>
      </div>

      <span
        className="hidden size-11 items-center justify-center rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] shadow-[0_0_24px_rgb(var(--tone-rgb)/0.45)] lg:flex dark:bg-card"
        aria-hidden
      >
        <ArrowRight className="size-4 rtl:-scale-x-100" />
      </span>

      <div className="relative isolate">
        <span
          className="pointer-events-none absolute -inset-10 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.18),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
          <AppwriteMark className="size-4" />
          {t('Appwrite Enterprise')}
        </p>
        <ul className="mt-5 grid grid-cols-5 gap-x-2 gap-y-5">
          {PLATFORM_PRODUCTS.map((product, index) => {
            const Icon = PRODUCT_NAV_REGISTRY[product.id].icon
            return (
              <li
                key={product.id}
                className="product-hero-rise flex flex-col items-center gap-2 text-center"
                style={riseStyle(420 + index * 55)}
              >
                <span className="flex size-11 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] shadow-[0_10px_26px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
                  <Icon className="size-5" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="text-[11px] font-medium text-foreground">{t(product.name)}</span>
              </li>
            )
          })}
        </ul>
        <ul className="mt-7 flex flex-wrap gap-x-6 gap-y-2 border-t border-foreground/15 pt-4 text-[12px] font-medium text-foreground">
          {enterpriseConsolidationOutcomes.map((outcome) => (
            <li key={outcome} className="flex items-center gap-2">
              <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
              {t(outcome)}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Capabilities, grouped
 * -----------------------------------------------------------------------------------------------*/

const GROUP_ICONS: Record<(typeof enterpriseCapabilityGroups)[number]['title'], LucideIcon> = {
  Reliability: Gauge,
  Governance: ShieldCheck,
  Operations: ScrollText,
  'Compliance and pricing': Receipt,
}

export function EnterpriseCapabilityGroups() {
  const t = useT()
  return (
    <div className="grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-4">
      {enterpriseCapabilityGroups.map((group, groupIndex) => {
        const GroupIcon = GROUP_ICONS[group.title]
        return (
          <div key={group.title} className="product-hero-rise relative" style={riseStyle(100 + groupIndex * 110)}>
            <div className="relative flex items-center gap-2.5 border-t border-foreground/15 pt-5">
              <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
              <GroupIcon className="size-[18px] text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
              <h3 className="text-[16px] font-medium text-foreground">{t(group.title)}</h3>
            </div>
            <ul className="mt-5 space-y-5">
              {group.items.map((title) => {
                const capability = enterprisePlanCapabilities.find((item) => item.title === title)
                return (
                  <li key={title}>
                    <p className="text-[14px] font-medium text-foreground/90">{t(title)}</p>
                    {capability ? (
                      <p className="mt-1 text-[13px] leading-6 text-muted-foreground">{t(capability.description)}</p>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          </div>
        )
      })}
    </div>
  )
}

/* -------------------------------------------------------------------------------------------------
 * Deployment: Cloud or self-hosted, one platform
 * -----------------------------------------------------------------------------------------------*/

export function EnterpriseDeploymentModels() {
  const t = useT()
  const [cloud, selfHosted] = enterpriseDeploymentOptions
  const columns = [
    { option: cloud, highlights: enterpriseDeploymentHighlights.cloud },
    { option: selfHosted, highlights: enterpriseDeploymentHighlights.selfHosted },
  ]

  return (
    <div>
      <div className="relative grid gap-12 md:grid-cols-2 md:gap-0">
        <span className="pointer-events-none absolute inset-y-0 start-1/2 hidden w-px bg-foreground/12 md:block" aria-hidden />
        <span
          className="absolute start-1/2 top-1/2 z-[1] hidden -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-background px-3 py-1 text-[11px] font-medium text-[var(--tone-ink)] shadow-[0_0_24px_rgb(var(--tone-rgb)/0.35)] md:block rtl:translate-x-1/2 dark:bg-card"
        >
          {t('Same platform')}
        </span>
        {columns.map(({ option, highlights }, index) => {
          if (!option) return null
          const Icon = option.icon
          return (
            <div
              key={option.title}
              className={cn('product-hero-rise relative isolate', index === 0 ? 'md:pe-14' : 'md:ps-14')}
              style={riseStyle(100 + index * 160)}
            >
              <span
                className="pointer-events-none absolute -inset-8 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.12),transparent_70%)]"
                aria-hidden
              />
              <span className="flex size-12 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] shadow-[0_12px_30px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
                {Icon ? <Icon className="size-5" strokeWidth={1.75} aria-hidden /> : null}
              </span>
              <h3 className="mt-5 font-aeonik-pro text-[24px] leading-tight tracking-tight text-foreground">{t(option.title)}</h3>
              <p className="mt-3 text-[14px] leading-7 text-muted-foreground">{t(option.description)}</p>
              <ul className="mt-6 space-y-2.5">
                {highlights.map((line) => (
                  <li key={line} className="flex items-center gap-2.5 text-[13px] text-foreground/85">
                    <span className="size-1.5 shrink-0 rounded-full bg-[var(--tone-ink)]" aria-hidden />
                    {t(line)}
                  </li>
                ))}
              </ul>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function EnterpriseSharedBenefits({ title }: { title: string }) {
  const t = useT()
  return (
    <div>
      <p className="text-center font-mono text-[11px] uppercase tracking-[0.2em] text-muted-foreground">{t(title)}</p>
      <ul className="mt-8 grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
        {enterpriseDeploymentSharedBenefits.map((benefit, index) => {
          const Icon = benefit.icon
          return (
            <li
              key={benefit.title}
              className="product-hero-rise relative border-t border-foreground/15 pt-5"
              style={riseStyle(120 + index * 90)}
            >
              <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
              <p className="flex items-center gap-2.5 text-[15px] font-medium text-foreground">
                {Icon ? <Icon className="size-[18px] text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden /> : null}
                {t(benefit.title)}
              </p>
              <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(benefit.description)}</p>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
