import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  organizationQueryOptions,
  useOrganizationEmailDomains,
  useOrganizationSSOProviders,
} from '@/lib/react-query/hooks'
import { EmailDomainsCard } from './_components/EmailDomainsCard'
import { EnableBaaDialog } from './_components/EnableBaaDialog'
import { RequireSSOCard } from './_components/RequireSSOCard'
import { SAMLProvidersCard } from './_components/SAMLProvidersCard'
import { SSOAddonNotice, useSSOAddonGate } from './_components/SSOCardHelpers'
import { SSOProvidersCard } from './_components/SSOProvidersCard'

export function SingleSignOn() {
  const { orgId = '' } = useParams({ strict: false })
  // Single sign-on is part of the HIPAA BAA add-on. Without it active the page
  // offers the add-on, or the upgrade on a plan without it; what the
  // organization kept from when it was active stays listed, to delete.
  const gate = useSSOAddonGate(orgId)
  const { features } = useConsoleProfile()
  const [enablingAddon, setEnablingAddon] = useState(false)
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
      ]}
    />
  )

  // Unknown (not read yet, or no billing) is not locked: the server decides.
  const notice =
    gate.status && gate.status !== 'active' ? (
      <SSOAddonNotice
        orgId={orgId}
        status={gate.status}
        planName={gate.planName}
        paused={!nothingSetUp}
        onEnable={() => setEnablingAddon(true)}
      />
    ) : null

  return (
    <>
      {!notice ? (
        cards
      ) : nothingSetUp ? (
        notice
      ) : (
        <div className="space-y-6">
          {notice}
          {cards}
        </div>
      )}
      {/* Here rather than in the notice, which is gone once the add-on is on */}
      {features.billing ? (
        <EnableBaaDialog
          open={enablingAddon}
          onOpenChange={setEnablingAddon}
          organizationId={orgId}
        />
      ) : null}
    </>
  )
}
