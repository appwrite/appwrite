import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { OrgKeysCard } from './_components/OrgApiKeys'
import { PartnersProgramCard } from './_components/PartnersProgramCard'

export function Partners() {
  return (
    <SettingsCardsList
      cards={[
        {
          id: 'partners-program',
          search: {
            title: 'Partners Program',
            keywords: [
              'partners',
              'program',
              'agency',
              'co-marketing',
              'training',
              'discounts',
              'badge',
              'apply',
            ],
          },
          node: <PartnersProgramCard />,
        },
        {
          id: 'org-keys',
          search: {
            title: 'Partners keys',
            keywords: [
              'partners keys',
              'org keys',
              'api',
              'scopes',
              'credentials',
              'project keys',
              'partners',
            ],
          },
          node: <OrgKeysCard />,
        },
      ]}
    />
  )
}
