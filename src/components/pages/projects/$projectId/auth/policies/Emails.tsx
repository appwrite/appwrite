import { useAuthSecuritySnapshot } from '../Security'
import {
  DenyFreeEmailCard,
  DenyAliasedEmailCard,
  DenyDisposableEmailCard,
} from './EmailPoliciesCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'

type EmailsProps = {
  projectId: string
}

export function EmailsPolicies({ projectId }: EmailsProps) {
  const security = useAuthSecuritySnapshot(projectId)

  const cards: SettingsCardItem[] = [
    {
      id: 'free-emails',
      search: {
        title: 'Free emails',
        keywords: ['gmail', 'yahoo', 'signup', 'block'],
      },
      node: (
        <DenyFreeEmailCard
          projectId={projectId}
          currentEnabled={security.authDenyFreeEmail ?? false}
        />
      ),
    },
    {
      id: 'aliased-emails',
      search: {
        title: 'Aliased emails',
        keywords: ['plus', 'alias', 'signup'],
      },
      node: (
        <DenyAliasedEmailCard
          projectId={projectId}
          currentEnabled={security.authDenyAliasedEmail ?? false}
        />
      ),
    },
    {
      id: 'disposable-emails',
      search: {
        title: 'Disposable emails',
        keywords: ['mailinator', 'temp', 'signup'],
      },
      node: (
        <DenyDisposableEmailCard
          projectId={projectId}
          currentEnabled={security.authDenyDisposableEmail ?? false}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} emptyMessage="No matching policies" />
}
