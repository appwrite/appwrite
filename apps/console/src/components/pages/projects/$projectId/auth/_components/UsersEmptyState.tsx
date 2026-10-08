import type { ReactNode } from 'react'
import { Link, type LinkProps } from '@tanstack/react-router'
import {
  ChevronRight,
  Fingerprint,
  Globe,
  KeyRound,
  Link2,
  Lock,
  Mail,
  ShieldCheck,
  Smartphone,
  Users,
  type LucideIcon,
} from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateVisual,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const OAUTH_ICONS = [
  '/icons/google.svg',
  '/icons/github.svg',
  '/icons/apple.svg',
]

const FLOATING_CHIPS: {
  icon: LucideIcon
  label: string
  className: string
  live?: boolean
}[] = [
  {
    icon: ShieldCheck,
    label: 'MFA',
    className: '-start-24 top-10 -rotate-3',
    live: true,
  },
  { icon: Link2, label: 'Magic URL', className: '-end-28 top-20 rotate-2' },
  { icon: Smartphone, label: 'OTP', className: '-start-28 bottom-16 rotate-2' },
  { icon: Fingerprint, label: 'JWT', className: '-end-16 bottom-8 -rotate-2' },
]

function Field({
  icon: Icon,
  children,
}: {
  icon: LucideIcon
  children: ReactNode
}) {
  return (
    <span className="flex h-8 items-center gap-2 rounded-md border border-border bg-background px-2.5">
      <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      {children}
    </span>
  )
}

/** Decorative sign-in card surrounded by the auth methods it supports. */
function SignInVisual() {
  return (
    <ProductEmptyStateVisual className="w-64">
      <div className="rounded-xl border border-border bg-card p-5 shadow-xl">
        <div className="flex flex-col items-center">
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <KeyRound className="h-4 w-4" />
          </span>
          <span className="mt-3 h-2 w-24 rounded-full bg-muted-foreground/40" />
          <span className="mt-1.5 h-1.5 w-32 rounded-full bg-muted-foreground/15" />
        </div>
        <div className="mt-5 space-y-2">
          <Field icon={Mail}>
            <span className="h-1.5 w-24 rounded-full bg-muted-foreground/30" />
          </Field>
          <Field icon={Lock}>
            <span className="flex gap-1">
              {Array.from({ length: 8 }, (_, index) => (
                <span
                  key={index}
                  className="h-1.5 w-1.5 rounded-full bg-muted-foreground/50"
                />
              ))}
            </span>
          </Field>
          <span className="flex h-8 items-center justify-center rounded-md bg-[var(--brand-cta)]">
            <span className="h-1.5 w-12 rounded-full bg-[var(--brand-cta-foreground)]/70" />
          </span>
        </div>
        <div className="my-4 flex items-center gap-2">
          <span className="h-px flex-1 bg-border" />
          <span className="h-1 w-1 rounded-full bg-muted-foreground/40" />
          <span className="h-px flex-1 bg-border" />
        </div>
        <div className="grid grid-cols-3 gap-2">
          {OAUTH_ICONS.map((src) => (
            <span
              key={src}
              className="flex h-8 items-center justify-center rounded-md border border-border bg-background"
            >
              <img src={src} alt="" className="h-4 w-4" />
            </span>
          ))}
        </div>
      </div>

      {FLOATING_CHIPS.map(({ icon: Icon, label, className, live }) => (
        <span
          key={label}
          className={cn(
            'absolute flex items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-popover py-1 pe-2.5 ps-1.5 text-[11px] font-medium text-foreground shadow-md',
            className,
          )}
        >
          <span className="flex h-5 w-5 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <Icon className="h-3 w-3" />
          </span>
          {label}
          {live ? (
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
          ) : null}
        </span>
      ))}
    </ProductEmptyStateVisual>
  )
}

type SetupItem = {
  icon: LucideIcon
  title: string
  description: string
  link?: LinkProps
}

export function UsersEmptyState({
  projectId,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
  showSecuritySettings = false,
}: {
  projectId: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
  /** Links the setup items to auth settings, OAuth providers, and policies. */
  showSecuritySettings?: boolean
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl('/docs/products/auth', features.marketing)
  const params = { projectId }

  const items: SetupItem[] = [
    {
      icon: KeyRound,
      title: 'Choose sign-in methods',
      description:
        'Turn on email and password, magic URL, phone, or anonymous sign-in.',
      link: showSecuritySettings
        ? { to: '/projects/$projectId/auth/settings', params }
        : undefined,
    },
    {
      icon: Globe,
      title: 'Connect OAuth providers',
      description: 'Let people sign in with Google, GitHub, Apple, and more.',
      link: showSecuritySettings
        ? { to: '/projects/$projectId/auth/social-providers', params }
        : undefined,
    },
    {
      icon: ShieldCheck,
      title: 'Set security policies',
      description:
        'Control session length, password rules, and limits for your users.',
      link: showSecuritySettings
        ? { to: '/projects/$projectId/auth/policies/sessions', params }
        : undefined,
    },
  ]

  return (
    <div className="mx-auto w-full max-w-4xl py-10 sm:py-14">
      <ProductEmptyStateHero
        visual={<SignInVisual />}
        icon={Users}
        title={t('Create your first user')}
        description={t(
          'Sign users up from your app with the Appwrite SDKs, or create one here to test your auth flows.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create user')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />
      <ul className="mx-auto mt-12 max-w-xl divide-y divide-border overflow-hidden rounded-xl border border-border bg-card/50 sm:mt-14">
        {items.map(({ icon: Icon, title, description, link }) => {
          const content = (
            <>
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
                <Icon className="h-4 w-4" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-[14px] font-medium text-foreground">
                  {t(title)}
                </span>
                <span className="mt-0.5 block text-[13px] leading-relaxed text-muted-foreground">
                  {t(description)}
                </span>
              </span>
              {link ? (
                <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
              ) : null}
            </>
          )
          const rowClassName = 'flex items-center gap-4 px-5 py-4 text-start'
          return (
            <li key={title}>
              {link ? (
                <Link
                  {...link}
                  className={cn(
                    rowClassName,
                    'group transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none',
                  )}
                >
                  {content}
                </Link>
              ) : (
                <div className={rowClassName}>{content}</div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
