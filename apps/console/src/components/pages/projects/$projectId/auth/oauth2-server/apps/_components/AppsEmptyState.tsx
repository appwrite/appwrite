import { ArrowLeftRight, Check, KeyRound, LogIn, Repeat } from 'lucide-react'
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
    icon: KeyRound,
    title: 'Register the client',
    description:
      'Name the app, add its redirect URIs, and create a secret for server-side clients.',
  },
  {
    icon: LogIn,
    title: 'Send users to authorize',
    description:
      'Users sign in with this project and approve the scopes the client asks for on a branded consent screen.',
  },
  {
    icon: Repeat,
    title: 'Exchange the code',
    description:
      'The client trades the authorization code for tokens and reads the signed-in user from the userinfo endpoint.',
  },
]

const SCOPES = ['openid', 'email', 'profile'] as const

/** Decorative consent screen with the client's redirect URI. */
function ConsentVisual() {
  return (
    <ProductEmptyStateVisual className="w-64 pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center justify-center gap-2 border-b border-border bg-muted/40 px-3 py-3">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-border bg-background text-[11px] font-semibold text-muted-foreground">
            A
          </span>
          <ArrowLeftRight className="h-3 w-3 text-muted-foreground/60" />
          <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-border bg-background">
            <img src="/icons/appwrite.svg" alt="" className="h-3.5 w-3.5" />
          </span>
        </div>
        <div className="px-3 py-3">
          <span className="mx-auto block h-1.5 w-32 rounded-full bg-foreground/40" />
          <span className="mx-auto mt-1.5 block h-1.5 w-20 rounded-full bg-muted-foreground/20" />
          <ul dir="ltr" className="mt-3 space-y-1.5">
            {SCOPES.map((scope) => (
              <li
                key={scope}
                className="flex items-center gap-2 font-mono text-[10px] text-foreground"
              >
                <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                {scope}
              </li>
            ))}
          </ul>
          <div className="mt-3 grid grid-cols-2 gap-1.5">
            <span className="h-6 rounded-md border border-border" />
            <span className="h-6 rounded-md bg-[var(--brand-cta)]" />
          </div>
        </div>
      </div>

      <div className="absolute -end-36 top-6 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          client_id
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          app_7f2c91
        </span>
      </div>

      <div className="absolute -start-40 bottom-10 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span className="h-1.5 w-1.5 rounded-full bg-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          acme.app/callback?code=…
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
        visual={<ConsentVisual />}
        icon={KeyRound}
        title={t('Let other apps sign in with your project')}
        description={t(
          'Register OAuth2 clients so other products can let users connect with this project. To let your users connect their Appwrite account with your application, create an app under your organization instead.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton onClick={onCreate}>
              {t('Create app')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/products/auth/oauth-server" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
