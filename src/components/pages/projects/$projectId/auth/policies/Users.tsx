import { useAuthSecuritySnapshot, UsersLimitCard } from '../Security'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'

type UsersProps = {
  projectId: string
}

export function UsersPolicies({ projectId }: UsersProps) {
  const security = useAuthSecuritySnapshot(projectId)

  const cards: SettingsCardItem[] = [
    {
      id: 'users-limit',
      search: {
        title: 'Users limit',
        keywords: ['signup', 'maximum', 'registration', 'unlimited'],
      },
      node: (
        <UsersLimitCard
          projectId={projectId}
          currentLimit={security.authLimit ?? 0}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} emptyMessage="No matching policies" />
}
