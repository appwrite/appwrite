import { Clock, Key, ListChecks, Server } from 'lucide-react'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
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
    icon: ListChecks,
    title: 'Pick the scopes',
    description:
      'Grant only what your server needs, from reading users to writing rows. You can change scopes later.',
  },
  {
    icon: Clock,
    title: 'Set an expiration',
    description:
      'Keys can live forever or expire on a date, so short-lived scripts never leave access behind.',
  },
  {
    icon: Server,
    title: 'Use it on your server',
    description:
      'Pass the secret to a server SDK or the CLI. Keep it out of browsers and mobile apps.',
  },
]

const SCOPES = [
  'users.read',
  'rows.write',
  'files.read',
  'functions.write',
  'buckets.read',
]

const LANGUAGES = ['node', 'python', 'go', 'php', 'dart', 'kotlin']

/** Decorative API key card with scopes, and the server SDK call that uses it. */
function KeyVisual() {
  return (
    <ProductEmptyStateVisual className="w-[380px] pb-6">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2.5">
          <span className="flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
            <Key className="h-3.5 w-3.5" />
          </span>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            backend-server
          </span>
          <span className="ms-auto h-1.5 w-1.5 rounded-full bg-emerald-500" />
        </div>
        <div className="space-y-3 px-3 py-3">
          <div
            dir="ltr"
            className="flex items-center rounded-md border border-border bg-background px-2 py-1.5 font-mono text-[10px]"
          >
            <span className="text-foreground">standard_8f3a</span>
            <span className="ms-1 tracking-widest text-muted-foreground">
              ••••••••••••••••
            </span>
          </div>
          <div dir="ltr" className="flex flex-wrap gap-1">
            {SCOPES.map((scope) => (
              <span
                key={scope}
                className="rounded border border-border bg-muted/50 px-1.5 py-0.5 font-mono text-[9px] text-muted-foreground"
              >
                {scope}
              </span>
            ))}
          </div>
          <div className="flex items-center gap-1.5 text-muted-foreground">
            <Clock className="h-3 w-3" />
            <span className="h-1.5 w-20 rounded-full bg-muted-foreground/20" />
          </div>
        </div>
      </div>

      <div
        dir="ltr"
        className="absolute -end-44 top-20 w-56 rounded-lg border border-border bg-popover p-2.5 text-start font-mono text-[10px] leading-relaxed shadow-lg"
      >
        <div className="text-muted-foreground">
          <span className="text-[var(--brand-cta)]">const</span> client ={' '}
          <span className="text-foreground">new Client()</span>
        </div>
        <div className="ps-3 text-muted-foreground">
          .setProject(<span className="text-emerald-600 dark:text-emerald-400">&apos;my-app&apos;</span>)
        </div>
        <div className="ps-3 text-muted-foreground">
          .setKey(<span className="text-emerald-600 dark:text-emerald-400">process.env.KEY</span>)
        </div>
      </div>

      <div className="absolute -start-20 bottom-0 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        {LANGUAGES.map((language) => (
          <LanguageIcon
            key={language}
            language={language}
            size="sm"
            className="h-4 w-4"
          />
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

export function ApiKeysEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-8 sm:py-12">
      <ProductEmptyStateHero
        visual={<KeyVisual />}
        icon={Key}
        title={t('Create your first API key')}
        description={t(
          'API keys let your servers, scripts, and CI talk to Appwrite with exactly the access you grant, without a user session.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create API key')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/partners/project/api-keys" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
