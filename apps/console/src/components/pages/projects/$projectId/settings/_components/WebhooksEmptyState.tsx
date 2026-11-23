import { Check, ListChecks, Send, ShieldCheck, Webhook } from 'lucide-react'
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
    icon: ListChecks,
    title: 'Pick the events',
    description:
      'Subscribe to the changes you care about, from new users to uploaded files and finished executions.',
  },
  {
    icon: Send,
    title: 'Point it at your endpoint',
    description:
      'Appwrite sends a POST request with the event payload to your URL as soon as the event happens.',
  },
  {
    icon: ShieldCheck,
    title: 'Verify the signature',
    description:
      'Check the X-Appwrite-Webhook-Signature header to confirm every request came from your project.',
  },
]

const EVENTS = [
  { name: 'users.*.create', active: true },
  { name: 'storage.files.create', active: false },
  { name: 'functions.executions.update', active: false },
] as const

/** Decorative event subscription delivering a payload to an endpoint. */
function WebhooksVisual() {
  return (
    <ProductEmptyStateVisual className="flex items-center pb-4">
      <div className="w-56 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Webhook className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-14 rounded-full bg-muted-foreground/25" />
        </div>
        <ul dir="ltr" className="space-y-2 px-3 py-3">
          {EVENTS.map((event) => (
            <li
              key={event.name}
              className={cn(
                'flex items-center gap-2 font-mono text-[10px]',
                event.active ? 'text-foreground' : 'text-muted-foreground',
              )}
            >
              <span
                className={cn(
                  'h-1.5 w-1.5 shrink-0 rounded-full',
                  event.active
                    ? 'bg-[var(--brand-cta)]'
                    : 'bg-muted-foreground/30',
                )}
              />
              <span className="truncate">{event.name}</span>
            </li>
          ))}
        </ul>
      </div>

      <div className="flex items-center px-1">
        <span className="h-px w-5 bg-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)]" />
        <span className="rounded-full border border-border bg-popover px-2 py-0.5 font-mono text-[9px] text-muted-foreground">
          POST
        </span>
        <span className="h-px w-5 bg-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)]" />
      </div>

      <div className="w-60 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div
          dir="ltr"
          className="truncate border-b border-border bg-muted/40 px-3 py-2 font-mono text-[10px] text-muted-foreground"
        >
          https://api.example.com/hooks
        </div>
        <pre
          dir="ltr"
          className="px-3 py-2.5 font-mono text-[10px] leading-relaxed text-muted-foreground"
        >
          {'{\n  '}
          <span className="text-foreground">"$id"</span>
          {': "6650a1c3",\n  '}
          <span className="text-foreground">"email"</span>
          {': "ada@example.com",\n  '}
          <span className="text-foreground">"status"</span>
          {': true\n}'}
        </pre>
      </div>

      <div className="absolute -bottom-3 end-6 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span className="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
          <Check className="h-2.5 w-2.5" />
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          200 OK
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          84ms
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function WebhooksEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<WebhooksVisual />}
        icon={Webhook}
        title={t('React to changes in your project')}
        description={t(
          'Webhooks notify your servers the moment something happens in your project, so you can sync data, send notifications, or kick off workflows.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create webhook')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/apis/webhooks" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
