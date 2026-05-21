import { useAuthSecuritySnapshot, PrivacyCard } from '../Security'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'

type MembershipsProps = {
  projectId: string
}

export function MembershipsPolicies({ projectId }: MembershipsProps) {
  const security = useAuthSecuritySnapshot(projectId)
  const membershipsPrivacy = security.membershipsPrivacy ?? {
    userName: true,
    userEmail: true,
    mfa: true,
    userId: true,
    userPhone: true,
  }

  const cards: SettingsCardItem[] = [
    {
      id: 'privacy',
      search: {
        title: 'Privacy',
        keywords: ['team', 'mfa', 'hidden', 'name', 'email'],
      },
      node: (
        <PrivacyCard
          projectId={projectId}
          currentPrivacy={membershipsPrivacy}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} emptyMessage="No matching policies" />
}
