import { Check, FolderCheck, Package, ShieldCheck, Store } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Package,
    title: 'Create your app',
    description:
      'Add a name, logo, and redirect URIs so users recognize your app on the consent screen.',
  },
  {
    icon: ShieldCheck,
    title: 'Request scoped access',
    description:
      'Users pick the projects and organizations your app can reach, and can revoke access at any time.',
  },
  {
    icon: Store,
    title: 'Publish to the marketplace',
    description:
      'List the app so teams across Appwrite can discover it and connect it to their projects.',
  },
]

const PROJECTS = [
  { name: 'my-app', granted: true },
  { name: 'staging', granted: true },
  { name: 'analytics', granted: false },
] as const

const SCOPES = ['projects.read', 'functions.write'] as const

/** Decorative project picker shown when a user connects your app. */
function ConnectVisual() {
  const t = useT()
  return (
    <ProductEmptyStateVisual className="w-64 pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
          <FolderCheck className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-20 rounded-full bg-muted-foreground/25" />
        </div>
        <ul className="space-y-2 px-3 py-3">
          {PROJECTS.map((project) => (
            <li key={project.name} className="flex items-center gap-2">
              <span
                className={cn(
                  'flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border',
                  project.granted
                    ? 'border-transparent bg-[var(--brand-cta)] text-white'
                    : 'border-muted-foreground/40',
                )}
              >
                {project.granted ? <Check className="h-2.5 w-2.5" /> : null}
              </span>
              <span
                dir="ltr"
                className={cn(
                  'font-mono text-[10px]',
                  project.granted ? 'text-foreground' : 'text-muted-foreground',
                )}
              >
                {project.name}
              </span>
            </li>
          ))}
        </ul>
        <div
          dir="ltr"
          className="flex flex-wrap gap-1 border-t border-border px-3 py-2.5"
        >
          {SCOPES.map((scope) => (
            <span
              key={scope}
              className="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[9px] text-muted-foreground"
            >
              {scope}
            </span>
          ))}
        </div>
      </div>

      <div className="absolute -start-40 top-8 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-2 shadow-lg">
        <img src="/icons/appwrite.svg" alt="" className="h-3.5 w-3.5" />
        <span className="text-[11px] font-medium text-foreground">
          {t('Sign in with Appwrite')}
        </span>
      </div>

      <div className="absolute -end-40 bottom-6 w-44 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-[11px] font-semibold text-muted-foreground">
            A
          </span>
          <div className="min-w-0 flex-1 space-y-1.5">
            <span className="block h-1.5 w-16 rounded-full bg-foreground/40" />
            <span className="block h-1.5 w-24 rounded-full bg-muted-foreground/20" />
          </div>
        </div>
        <span className="mt-2.5 inline-flex items-center gap-1 rounded-full border border-border px-1.5 py-0.5 text-[9px] text-muted-foreground">
          <Store className="h-2.5 w-2.5" />
          {t('Marketplace')}
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function AppsEmptyState({ onCreate }: { onCreate: () => void }) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<ConnectVisual />}
        icon={Package}
        title={t('Build on top of Appwrite')}
        description={t(
          'Create OAuth2 apps that let Appwrite users connect their projects with consent-based, scoped access instead of pasted API keys. Publish them to the marketplace for every organization to find.',
        )}
        actions={
          <>
            <span
              className="contents"
              {...analyticsAttrs('create-marketplace-app')}
            >
              <ProductEmptyStateCreateButton onClick={onCreate}>
                {t('Create app')}
              </ProductEmptyStateCreateButton>
            </span>
            <ProductEmptyStateDocsButton path="/docs/partners/apps" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
