import { Check, Globe, Link2, ListTree, Server } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Server,
    title: 'Bring or buy a domain',
    description:
      'Add a domain you already own, transfer it in, or buy a new one without leaving the console.',
  },
  {
    icon: ListTree,
    title: 'Manage DNS in one place',
    description:
      'Point your nameservers to Appwrite, then add records or apply presets for email and verification.',
  },
  {
    icon: Link2,
    title: 'Connect it everywhere',
    description:
      'Use the domain and its subdomains for sites, functions, and project APIs across the organization.',
  },
]

const RECORDS = [
  { type: 'A', name: '@', target: 'Sites' },
  { type: 'CNAME', name: 'api', target: 'API' },
  { type: 'CNAME', name: 'hooks', target: 'Functions' },
  { type: 'MX', name: '@', target: 'mail' },
] as const

/** Decorative organization domain with its DNS zone. */
function DomainsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[360px] pb-6">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
          <Globe className="h-3.5 w-3.5 text-muted-foreground" />
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            example.com
          </span>
          <span className="ms-auto flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <Check className="h-2.5 w-2.5" />
          </span>
        </div>
        <ul dir="ltr" className="divide-y divide-border">
          {RECORDS.map((record) => (
            <li
              key={`${record.type}-${record.name}-${record.target}`}
              className="grid grid-cols-[3.25rem_3rem_1fr] items-center gap-2 px-3 py-2 font-mono text-[10px]"
            >
              <span className="w-fit rounded bg-muted px-1 text-muted-foreground">
                {record.type}
              </span>
              <span className="text-foreground">{record.name}</span>
              <span
                className={cn(
                  'h-1.5 rounded-full',
                  record.target === 'mail'
                    ? 'w-16 bg-muted-foreground/20'
                    : 'w-24 bg-[color-mix(in_oklch,var(--brand-cta)_45%,transparent)]',
                )}
              />
            </li>
          ))}
        </ul>
      </div>

      <div className="absolute -start-48 top-14 w-44 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <Server className="h-3 w-3 text-muted-foreground" />
          <span className="h-1.5 w-14 rounded-full bg-muted-foreground/25" />
        </div>
        <div dir="ltr" className="mt-2 space-y-1 font-mono text-[10px]">
          <p className="text-foreground">ns1.appwrite.zone</p>
          <p className="text-foreground">ns2.appwrite.zone</p>
        </div>
      </div>

      <div className="absolute -end-24 -bottom-1 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span className="h-1.5 w-1.5 rounded-full bg-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          api.example.com
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

function SecondaryAction({
  label,
  onClick,
  disabledTooltip,
}: {
  label: string
  onClick: () => void
  disabledTooltip?: string
}) {
  const button = (
    <Button
      variant="outline"
      className="h-9 text-[13px]"
      onClick={onClick}
      disabled={!!disabledTooltip}
    >
      {label}
    </Button>
  )
  if (!disabledTooltip) return button
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0}>{button}</span>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-[12px]">
        {disabledTooltip}
      </TooltipContent>
    </Tooltip>
  )
}

export function DomainsEmptyState({
  onAdd,
  onBuy,
  onTransfer,
  disabledTooltip,
}: {
  onAdd: () => void
  /** Omitted where buying and transferring are unavailable. */
  onBuy?: () => void
  onTransfer?: () => void
  /** Set when the plan's domain limit blocks every action. */
  disabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<DomainsVisual />}
        icon={Globe}
        title={t('Your domains, managed by Appwrite')}
        description={t(
          'Register, transfer, and manage domains for your whole organization. Configure DNS once and connect it to your sites, functions, and APIs.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onAdd}
              disabled={!!disabledTooltip}
              disabledTooltip={disabledTooltip}
            >
              {t('Add domain')}
            </ProductEmptyStateCreateButton>
            {onBuy ? (
              <SecondaryAction
                label={t('Buy domain')}
                onClick={onBuy}
                disabledTooltip={disabledTooltip}
              />
            ) : null}
            {onTransfer ? (
              <SecondaryAction
                label={t('Transfer in')}
                onClick={onTransfer}
                disabledTooltip={disabledTooltip}
              />
            ) : null}
            <ProductEmptyStateDocsButton path="/docs/products/domains" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
