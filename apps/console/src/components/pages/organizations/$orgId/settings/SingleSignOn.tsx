import { useParams } from '@tanstack/react-router'
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { useT } from '@/lib/i18n/translate'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { EmailDomainsCard } from './_components/EmailDomainsCard'
import { RequireSSOCard } from './_components/RequireSSOCard'
import { SSOProvidersCard } from './_components/SSOProvidersCard'

export function SingleSignOn() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const { plan } = useOrganizationPlan(orgId)
  // The same check that disables Invite: a plan whose seats add-on is not
  // supported takes no more members, so the server adds nobody who signs in
  // through single sign-on.
  const addsNoMembers = plan?.addons?.seats?.supported === false

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
              'email domains',
              'domain',
              'company email',
              'members',
              'dns',
              'txt',
              'sso',
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
              'company login',
              'security',
            ],
          },
          node: <RequireSSOCard />,
        },
      ]}
    />
  )

  if (!addsNoMembers) return cards
  return (
    <div className="space-y-6">
      <WarningAlert>
        {t(
          "This plan can't add members, so people who sign in with single sign-on don't join this organization.",
        )}{' '}
        <UpgradePlanLink orgId={orgId} />
      </WarningAlert>
      {cards}
    </div>
  )
}
