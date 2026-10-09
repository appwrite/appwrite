'use client'

import { useEffect } from 'react'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { resolveFenceCodeLanguage } from '@/lib/code-language'
import { useDocsCodeFill } from '../project-context/useDocsCodeFill'
import { useMultiCodeContext } from './MultiCode'
import { useTabsContext } from './tabs-context'

type FenceProps = {
  content: string
  language?: string
}

export function Fence({ content, language }: FenceProps) {
  const multiCode = useMultiCodeContext()
  const tabs = useTabsContext()
  const lang = language ?? 'plaintext'
  const resolvedLanguage = resolveFenceCodeLanguage(lang)
  const registerSnippet = multiCode?.registerSnippet
  const { code, actions } = useDocsCodeFill(content)

  // Register after commit. Depending on the whole context would re-register
  // conflicting snippets on every update when a language appears twice.
  useEffect(() => {
    registerSnippet?.(lang, content)
  }, [registerSnippet, lang, content])

  if (multiCode) {
    return null
  }

  if (tabs) {
    return (
      <div className="not-prose my-2 w-full">
        <ConnectCodeExample
          code={code}
          language={resolvedLanguage}
          actions={actions}
        />
      </div>
    )
  }

  return (
    <div className="not-prose my-4 w-full">
      <ConnectCodeExample
        code={code}
        language={resolvedLanguage}
        actions={actions}
      />
    </div>
  )
}
