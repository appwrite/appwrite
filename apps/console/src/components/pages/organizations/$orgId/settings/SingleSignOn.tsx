import { useParams } from '@tanstack/react-router'
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { EmailDomainsCard } from '@/components/global/shared/sso/EmailDomainsCard'
import { RequireSSOCard } from '@/components/global/shared/sso/RequireSSOCard'
import { SAMLProvidersCard } from '@/components/global/shared/sso/SAMLProvidersCard'
import { useSSOPageState } from '@/components/global/shared/sso/SSOCardHelpers'
import { SSOProvidersCard } from '@/components/global/shared/sso/SSOProvidersCard'
import { SSOAddonCard } from '@/components/global/shared/sso-addon/SSOAddonCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { SSOTarget } from '@/lib/sso-target'

export function SingleSignOn() {
  const { orgId = '' } = useParams({ strict: false })
  const target: SSOTarget = { scope: 'organization', organizationId: orgId }
  // Single sign-on for the organization needs its Single sign-on add-on. The
  // add-on's card leads the page; without the add-on active, what the
  // organization kept from when it was stays listed below, to delete.
  const { showSetup, paused } = useSSOPageState(target)
  const { features } = useConsoleProfile()

  return (
    <SettingsCardsList
      cards={[
        {
          id: 'sso-addon',
          search: {
            title: 'Single sign-on',
            keywords: ['sso', 'single sign-on', 'add-on', 'addon', 'billing'],
          },
          node: (
            <SSOAddonCard
              target={{ scope: 'organization', organizationId: orgId }}
              paused={paused}
            />
          ),
        },
        {
          id: 'sso-domains',
          search: {
            title: 'Domains',
            keywords: [
              'sso',
              'single sign-on',
              'domains',
              'domain',
              'members',
              'dns',
              'txt',
            ],
          },
          node: <EmailDomainsCard target={target} />,
        },
        {
          id: 'sso-providers',
          search: {
            title: 'Identity providers',
            keywords: [
              'sso',
              'single sign-on',
              'oidc',
              'openid connect',
              'okta',
              'identity provider',
              'login',
            ],
          },
          node: <SSOProvidersCard target={target} />,
        },
        {
          id: 'sso-saml',
          search: {
            title: 'SAML identity providers',
            keywords: [
              'sso',
              'single sign-on',
              'saml',
              'identity provider',
              'okta',
              'entra',
              'coming soon',
            ],
          },
          node: <SAMLProvidersCard />,
        },
        {
          id: 'sso-required',
          search: {
            title: 'Require SSO',
            keywords: [
              'sso',
              'single sign-on',
              'require',
              'enforce',
              'identity provider',
              'security',
            ],
          },
          node: <RequireSSOCard target={target} />,
        },
      ].filter((card) =>
        card.id === 'sso-addon' ? features.billing : showSetup,
      )}
    />
  )
}
