'use client'

import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { resolveFenceCodeLanguage } from '@/lib/code-language'
import { useMultiCodeContext } from './MultiCode'
import { useTabsContext } from './Tabs'

type FenceProps = {
  content: string
  language?: string
}

export function Fence({ content, language }: FenceProps) {
  const multiCode = useMultiCodeContext()
  const tabs = useTabsContext()
  const lang = language ?? 'plaintext'
  const resolvedLanguage = resolveFenceCodeLanguage(lang)

  if (multiCode) {
    multiCode.registerSnippet(lang, content)
    return null
  }

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
