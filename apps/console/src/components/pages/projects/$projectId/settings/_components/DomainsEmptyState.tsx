import { Globe, Lock, Network, Plus, ShieldCheck } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Plus,
    title: 'Add your domain',
    description:
      'Use a subdomain you own, like api.example.com, as the endpoint for your project.',
  },
  {
    icon: Network,
    title: 'Update your DNS',
    description:
      'Add the CNAME record we show you at your DNS provider, then verify the domain.',
  },
  {
    icon: ShieldCheck,
    title: 'Get a certificate',
    description:
      'Appwrite issues a TLS certificate once the domain verifies, so traffic is served over HTTPS.',
  },
]

/** Decorative browser bar resolving a custom domain through a CNAME record. */
function DomainsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[380px] pb-6">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
          <div className="flex gap-1">
            <span className="h-2 w-2 rounded-full bg-muted-foreground/25" />
            <span className="h-2 w-2 rounded-full bg-muted-foreground/25" />
            <span className="h-2 w-2 rounded-full bg-muted-foreground/25" />
          </div>
          <div
            dir="ltr"
            className="flex min-w-0 flex-1 items-center gap-1.5 rounded-md border border-border bg-background px-2 py-1 font-mono text-[10px]"
          >
            <Lock className="h-2.5 w-2.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <span className="truncate text-muted-foreground">
              https://<span className="text-foreground">api.example.com</span>
              /v1
            </span>
          </div>
        </div>
        <div className="space-y-2 px-3 py-3">
          <span className="block h-1.5 w-40 rounded-full bg-muted-foreground/20" />
          <span className="block h-1.5 w-28 rounded-full bg-muted-foreground/15" />
          <span className="block h-1.5 w-32 rounded-full bg-muted-foreground/15" />
        </div>
      </div>

      <div className="absolute -start-28 top-16 w-52 overflow-hidden rounded-lg border border-border bg-popover text-start shadow-lg">
        <div className="flex items-center gap-1.5 border-b border-border px-2.5 py-1.5">
          <Globe className="h-3 w-3 text-muted-foreground" />
          <span className="h-1.5 w-12 rounded-full bg-muted-foreground/25" />
        </div>
        <div
          dir="ltr"
          className="grid grid-cols-[auto_auto_1fr] items-center gap-x-2.5 px-2.5 py-2 font-mono text-[10px]"
        >
          <span className="rounded bg-muted px-1 text-muted-foreground">
            CNAME
          </span>
          <span className="text-foreground">api</span>
          <span className="h-1.5 rounded-full bg-[color-mix(in_oklch,var(--brand-cta)_50%,transparent)]" />
        </div>
      </div>

      <div className="absolute -end-20 bottom-0 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <ShieldCheck className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          TLS
        </span>
        <span className="h-1.5 w-10 rounded-full bg-muted-foreground/25" />
      </div>
    </ProductEmptyStateVisual>
  )
}

export function DomainsEmptyState({
  onAdd,
  addDisabled = false,
  addDisabledTooltip,
}: {
  onAdd: () => void
  addDisabled?: boolean
  addDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<DomainsVisual />}
        icon={Globe}
        title={t('Serve your API on your own domain')}
        description={t(
          'Point a domain you own at this project so your apps call your brand instead of a shared endpoint. Cookies stay first party, which keeps sessions working in every browser.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onAdd}
              disabled={addDisabled}
              disabledTooltip={addDisabledTooltip}
            >
              {t('Add domain')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/products/network/custom-domains" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
