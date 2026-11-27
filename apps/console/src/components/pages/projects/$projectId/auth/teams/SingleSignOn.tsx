import { EmailDomainsCard } from '@/components/global/shared/sso/EmailDomainsCard'
import { RequireSSOCard } from '@/components/global/shared/sso/RequireSSOCard'
import { SAMLProvidersCard } from '@/components/global/shared/sso/SAMLProvidersCard'
import { useSSOPageState } from '@/components/global/shared/sso/SSOCardHelpers'
import { SSOProvidersCard } from '@/components/global/shared/sso/SSOProvidersCard'
import { SSOAddonCard } from '@/components/global/shared/sso-addon/SSOAddonCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useActiveEnvironmentId } from '@/lib/active-environment'
import type { SSOTarget } from '@/lib/sso-target'
import { AppSignInCard } from './_components/AppSignInCard'

/**
 * Single sign-on for a team of the project: the app's users on the team's
 * verified domains sign in to the app through its identity providers and join
 * the team with their roles. It needs the project's Single sign-on add-on,
 * whose card leads the page; without it and with nothing set up, the page is
 * that card's offer: what it does, its price and the way to enable it. What
 * was set up before stays listed, to delete, as on an organization's Single
 * sign-on page.
 */
export function TeamSingleSignOn({
  projectId,
  teamId,
}: {
  projectId: string
  teamId: string
}) {
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
      ) : null}
    </div>
  )
}
