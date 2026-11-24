import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { SSOAddonCard } from '@/components/global/shared/sso-addon/SSOAddonCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  organizationQueryOptions,
  useOrganizationEmailDomains,
  useOrganizationSSOProviders,
} from '@/lib/react-query/hooks'
import { EmailDomainsCard } from './_components/EmailDomainsCard'
import { RequireSSOCard } from './_components/RequireSSOCard'
import { SAMLProvidersCard } from './_components/SAMLProvidersCard'
import { useSSOAddonGate } from './_components/SSOCardHelpers'
import { SSOProvidersCard } from './_components/SSOProvidersCard'

export function SingleSignOn() {
  const { orgId = '' } = useParams({ strict: false })
  // Single sign-on for the organization needs its Single sign-on add-on. The
  // add-on's card leads the page; without the add-on active, what the
  // organization kept from when it was stays listed below, to delete.
  const gate = useSSOAddonGate(orgId)
  const { features } = useConsoleProfile()
  const { domains, isSuccess: domainsLoaded } =
    useOrganizationEmailDomains(orgId)
  const { providers, isSuccess: providersLoaded } =
    useOrganizationSSOProviders(orgId)
  const { data: organization } = useQuery(organizationQueryOptions(orgId))
  const required =
    (organization as { ssoRequired?: boolean } | null | undefined)
      ?.ssoRequired === true
  const nothingSetUp =
    domainsLoaded &&
    providersLoaded &&
    domains.length === 0 &&
    providers.length === 0 &&
    !required
  // Unknown (not read yet, or no billing) is not locked: the server decides.
  const showSetup = !gate.locked || !nothingSetUp

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
              paused={gate.locked && !nothingSetUp}
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
          node: <EmailDomainsCard />,
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
          node: <SSOProvidersCard />,
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
          node: <RequireSSOCard />,
        },
      ].filter((card) =>
        card.id === 'sso-addon' ? features.billing : showSetup,
      )}
    />
  )
}
