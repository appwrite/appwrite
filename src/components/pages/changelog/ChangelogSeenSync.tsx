'use client'

import { useEffect } from 'react'
import { markChangelogSeen } from '@/lib/changelog/nav-badge'

export function ChangelogSeenSync() {
  useEffect(() => {
    markChangelogSeen()
  }, [])

  return null
}
