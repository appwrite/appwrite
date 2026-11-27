import type { ReactNode } from 'react'
import {
  Building2,
  KeyRound,
  LockKeyhole,
  ShieldCheck,
  UserPlus,
  type LucideIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { SSOAddonVisual } from './SSOAddonVisual'
import { SSO_ADDON_CARD_ID } from './use-sso-addon'

type Scope = 'organization' | 'project'

type Benefit = { icon: LucideIcon; title: string; description: string }

/** What single sign-on does, for whoever would enable it. */
const CONTENT: Record<
  Scope,
  { title: string; description: string; benefits: readonly Benefit[] }
> = {
  organization: {
    title: 'Sign in to Appwrite with your company login',
    description:
      'People on your verified domains sign in to the Console through your identity provider, and join this organization with the roles you choose.',
    benefits: [
      {
        icon: UserPlus,
        title: 'Teammates join on their first sign-in',
        description:
          'No invites to send. Anyone on your domains gets in with the roles you set.',
      },
      {
        icon: ShieldCheck,
        title: 'Make it the only way in',
        description:
          'Require SSO, so people on your domains reach the organization only through your identity provider. Owners always keep access.',
      },
      {
        icon: KeyRound,
        title: 'Bring your identity provider',
        description:
          'Okta, Microsoft Entra ID, Google Workspace, Keycloak, or anything that speaks OpenID Connect.',
      },
    ],
  },
  project: {
    title: 'Let your customers sign in with their company login',
    description:
      "Users on a team's verified domains sign in to your app through that team's identity provider, and join the team with its roles.",
    benefits: [
      {
        icon: Building2,
        title: 'An identity provider for each customer',
        description:
          'Give every customer a team with its own domains and its own identity provider.',
      },
      {
        icon: UserPlus,
        title: 'Users join on their first sign-in',
        description:
          'No invites to send. They land in the team with the roles you chose.',
      },
      {
        icon: ShieldCheck,
        title: 'Require SSO, team by team',
        description:
          "Members on a team's domains get its roles only after signing in through its identity provider.",
      },
    ],
  },
}

type SSOAddonHeroProps = {
  scope: Scope
  /** The add-on's state, next to its name: pending, unavailable, starting later. */
  badge: ReactNode
  /** What it costs each month, for who can enable it now. */
  price?: string | null
  /** What enabling it takes: the plan that sells it, a payment to check. */
  note: ReactNode
  /** The way on: enable, upgrade, refresh. Nothing for who cannot change it. */
  action: ReactNode
}

/**
 * The Single sign-on add-on while it is not on: what it does, drawn and said,
 * with its price and the way to enable it. Shown wherever the feature would be
 * (an organization's Single sign-on settings, a team's Single sign-on tab, a
 * project's settings), so the offer is made where it lands.
 */
export function SSOAddonHero({
  scope,
  badge,
  price,
  note,
  action,
}: SSOAddonHeroProps) {
  const t = useT()
  const { title, description, benefits } = CONTENT[scope]

  return (
    <div
      data-card-id={SSO_ADDON_CARD_ID}
      className="@container overflow-hidden rounded-xl border border-border bg-card/50"
    >
      <div className="grid @3xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <SSOAddonVisual
          scope={scope}
          className="border-b border-border px-4 py-2 @3xl:order-2 @3xl:border-s @3xl:border-b-0 @3xl:px-6"
        />
        <div className="flex flex-col p-6 @3xl:p-8">
          <div className="flex flex-wrap items-center gap-2 text-[12px] font-medium text-muted-foreground">
            <LockKeyhole className="h-3.5 w-3.5" aria-hidden />
            {t('Single sign-on')}
            <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
              {t('Add-on')}
            </Badge>
            {badge}
          </div>
          <h3 className="mt-3 text-[20px] leading-snug font-semibold tracking-tight text-foreground">
            {t(title)}
          </h3>
          <p className="mt-2 max-w-xl text-[13px] text-muted-foreground">
            {t(description)}
          </p>
          <ul className="mt-6 space-y-4">
            {benefits.map(
              ({ icon: Icon, title: name, description: detail }) => (
                <li key={name} className="flex items-start gap-3">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon className="h-4 w-4" aria-hidden />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      {t(name)}
                    </p>
                    <p className="mt-0.5 max-w-md text-[12px] text-muted-foreground">
                      {t(detail)}
                    </p>
                  </div>
                </li>
              ),
            )}
          </ul>
          {scope === 'project' ? (
            <p className="mt-5 text-[12px] text-muted-foreground">
              {t(
                'One add-on covers every team in this project, in each of its environments.',
              )}
            </p>
          ) : null}
          {action || note || price ? (
            <div
              data-sso-addon-actions
              className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-3 pt-7"
            >
              {action}
              {note || price ? (
                <div className="min-w-0 flex-1 basis-56">
                  {price ? (
                    <p className="text-[13px] text-muted-foreground">
                      <span className="text-[15px] font-semibold text-foreground">
                        {price}
                      </span>{' '}
                      {t('per month')}
                    </p>
                  ) : null}
                  {note ? (
                    <p className="text-[12px] text-muted-foreground">{note}</p>
                  ) : null}
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
