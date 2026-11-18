import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { EmailDomainsCard } from './_components/EmailDomainsCard'

export function SingleSignOn() {
  return (
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
              'auto join',
              'company email',
              'members',
              'dns',
              'txt',
              'sso',
            ],
          },
          node: <EmailDomainsCard />,
        },
      ]}
    />
  )
}
