import { useMemo } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  AccountIdSection,
  DeleteAccountSection,
  UpdateEmailSection,
  UpdateNameSection,
} from './Overview'

export function AccountGeneral() {
  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'account-id',
        search: {
          title: 'Account ID',
          keywords: ['id', 'api', 'sdk', 'copy'],
        },
        node: <AccountIdSection />,
      },
      {
        id: 'name',
        search: {
          title: 'Update name',
          keywords: ['name', 'display name', 'profile'],
        },
        node: <UpdateNameSection />,
      },
      {
        id: 'email',
        search: {
          title: 'Update email',
          keywords: ['email', 'address', 'verified'],
        },
        node: <UpdateEmailSection />,
      },
      {
        id: 'delete-account',
        search: {
          title: 'Delete account',
          keywords: ['delete', 'remove', 'destroy', 'danger'],
        },
        node: <DeleteAccountSection />,
      },
    ],
    [],
  )

  return <SettingsCardsList cards={cards} />
}
