import { useAuthSecuritySnapshot } from '../Security'
import {
  DenyFreeEmailCard,
  DenyAliasedEmailCard,
  DenyDisposableEmailCard,
  DenyCorporateEmailCard,
} from './EmailPoliciesCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useT } from '@/lib/i18n/translate'

type EmailsProps = {
  projectId: string
}

export function EmailsPolicies({ projectId }: EmailsProps) {
  const t = useT()
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
    {
      id: 'corporate-emails',
      search: {
        title: 'Corporate emails',
        keywords: ['business', 'work', 'organization', 'domain', 'signup'],
      },
      node: (
        <DenyCorporateEmailCard
          projectId={projectId}
          currentEnabled={security.authDenyCorporateEmail ?? false}
        />
      ),
    },
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
