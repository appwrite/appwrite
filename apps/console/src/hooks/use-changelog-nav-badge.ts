'use client'

import { useRouterState } from '@tanstack/react-router'
import { useEffect, useState } from 'react'
import {
  CHANGELOG_SEEN_COUNT_KEY,
  CHANGELOG_SEEN_UPDATED_EVENT,
  isChangelogNavBadgeVisible,
} from '@/lib/changelog/nav-badge'

export function useChangelogNavBadge() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const update = () => setVisible(isChangelogNavBadgeVisible(pathname))

    const onStorage = (event: StorageEvent) => {
      if (event.key === CHANGELOG_SEEN_COUNT_KEY || event.key === null) {
        update()
      }
    }

    update()

    window.addEventListener(CHANGELOG_SEEN_UPDATED_EVENT, update)
    window.addEventListener('storage', onStorage)

    return () => {
      window.removeEventListener(CHANGELOG_SEEN_UPDATED_EVENT, update)
      window.removeEventListener('storage', onStorage)
    }
  }, [pathname])

  return visible
}
