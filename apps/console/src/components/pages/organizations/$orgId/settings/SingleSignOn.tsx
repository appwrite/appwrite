import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  organizationQueryOptions,
  useOrganizationEmailDomains,
  useOrganizationSSOProviders,
} from '@/lib/react-query/hooks'
import { EmailDomainsCard } from './_components/EmailDomainsCard'
import { RequireSSOCard } from './_components/RequireSSOCard'
import { SSOPlanNotice, useSSOPlanGate } from './_components/SSOCardHelpers'
import { SSOProvidersCard } from './_components/SSOProvidersCard'

export function SingleSignOn() {
  const { orgId = '' } = useParams({ strict: false })
  // Single sign-on is set up on Pro and above. On another plan the page offers
  // the upgrade; what the organization kept from Pro stays listed, to delete.
  const gate = useSSOPlanGate(orgId)
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

  const cards = (
    <SettingsCardsList
      cards={[
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
      ]}
    />
  )

  if (!gate.locked) return cards
  if (nothingSetUp) {
    return (
      <SSOPlanNotice orgId={orgId} planName={gate.planName} variant="upgrade" />
    )
  }
  return (
    <div className="space-y-6">
      <SSOPlanNotice orgId={orgId} planName={gate.planName} variant="paused" />
      {cards}
    </div>
  )
}
