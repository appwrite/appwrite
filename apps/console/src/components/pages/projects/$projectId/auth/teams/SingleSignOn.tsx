import { EmailDomainsCard } from '@/components/global/shared/sso/EmailDomainsCard'
import { RequireSSOCard } from '@/components/global/shared/sso/RequireSSOCard'
import { SAMLProvidersCard } from '@/components/global/shared/sso/SAMLProvidersCard'
import { useSSOPageState } from '@/components/global/shared/sso/SSOCardHelpers'
import { SSOProvidersCard } from '@/components/global/shared/sso/SSOProvidersCard'
import { SSOAddonCard } from '@/components/global/shared/sso-addon/SSOAddonCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useActiveEnvironmentId } from '@/lib/active-environment'
import { useT } from '@/lib/i18n/translate'
import type { SSOTarget } from '@/lib/sso-target'
import { AppSignInCard } from './_components/AppSignInCard'

/**
 * Single sign-on for a team of the project: the app's users on the team's
 * verified domains sign in to the app through its identity providers and join
 * the team with their roles. It needs the project's Single sign-on add-on,
 * whose card leads the page; without it and with nothing set up, the page is
 * that card and what the team gets. What was set up before stays listed, to
 * delete, as on an organization's Single sign-on page.
 */
export function TeamSingleSignOn({
  projectId,
  teamId,
}: {
  projectId: string
  teamId: string
}) {
  const t = useT()
  const target: SSOTarget = { scope: 'project', projectId, teamId }
  const { features } = useConsoleProfile()
  const { showSetup, paused } = useSSOPageState(target)
  // An environment has the add-on of its project
  const environmentId = useActiveEnvironmentId(projectId)

  return (
    <div className="w-full min-w-0 space-y-6">
      {features.billing ? (
        <SSOAddonCard
          target={{
            scope: 'project',
            projectId,
            inEnvironment: !!environmentId,
          }}
          paused={paused}
        />
      ) : null}
      {showSetup ? (
        <>
          <EmailDomainsCard target={target} />
          <SSOProvidersCard target={target} />
          <SAMLProvidersCard />
          <RequireSSOCard target={target} />
          <AppSignInCard projectId={projectId} teamId={teamId} />
        </>
      ) : (
        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('What this team gets')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                "Your app's users sign in through their company's identity provider, and the team decides who joins it and with which roles.",
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <ul className="list-disc space-y-1 ps-4 text-[13px] text-muted-foreground">
              <li>{t('Verify the email domains your users sign in with.')}</li>
              <li>
                {t(
                  'Connect OpenID Connect identity providers, like Okta, Microsoft Entra ID, Google Workspace or Keycloak.',
                )}
              </li>
              <li>
                {t(
                  'Users on a verified domain sign in to your app through them and join this team with the roles you choose.',
                )}
              </li>
              <li>
                {t(
                  "Require SSO, so members on those domains get the team's roles only after signing in through an identity provider.",
                )}
              </li>
              <li>
                {t(
                  'The add-on is for the whole project: every team in it, in each of its environments, can use it.',
                )}
              </li>
            </ul>
          </div>
        </div>
      )}
    </div>
  )
}
