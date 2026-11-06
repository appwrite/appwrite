'use client'

import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { resolveFenceCodeLanguage } from '@/lib/code-language'
import { useTabsContext } from './tabs-context'

type FenceProps = {
  content: string
  language?: string
}

export function Fence({ content, language }: FenceProps) {
  const tabs = useTabsContext()
  const resolvedLanguage = resolveFenceCodeLanguage(language ?? 'plaintext')

  if (tabs) {
    return (
      <div className="not-prose my-2 w-full">
        <ConnectCodeExample code={content} language={resolvedLanguage} />
      </div>
    )
  }

  return (
    <div className="not-prose my-4 w-full">
      <ConnectCodeExample code={content} language={resolvedLanguage} />
    </div>
  )
}
