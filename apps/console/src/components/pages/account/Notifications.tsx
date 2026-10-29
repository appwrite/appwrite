import { useMemo } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { BrowserAlertsSection } from './BrowserAlertsSection'

export function AccountNotifications() {
  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'browser-alerts',
        search: {
          title: 'Browser alerts',
          keywords: [
            'alert',
            'browser',
            'notification',
            'desktop',
            'build',
            'deployment',
            'sites',
            'functions',
          ],
        },
        node: <BrowserAlertsSection />,
      },
    ],
    [],
  )

  return <SettingsCardsList cards={cards} />
}
