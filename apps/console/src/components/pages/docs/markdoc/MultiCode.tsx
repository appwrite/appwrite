'use client'

import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import {
  resolveFenceCodeLabel,
  resolveFenceCodeLanguage,
} from '@/lib/code-language'
import {
  Children,
  isValidElement,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

type FenceProps = {
  content?: string
  language?: string
}

export function MultiCode({ children }: { children: ReactNode }) {
  // Read snippets from the fences during render so the server HTML has the code.
  const snippets = useMemo(() => {
    const map = new Map<string, string>()
    Children.forEach(children, (child) => {
      if (!isValidElement<FenceProps>(child)) return
      const { content, language } = child.props
      if (typeof content === 'string') map.set(language ?? 'plaintext', content)
    })
    return map
  }, [children])
  const [selected, setSelected] = useState<string | null>(null)

  const languages = Array.from(snippets.keys())
  const activeLanguage =
    selected && snippets.has(selected) ? selected : (languages[0] ?? null)
  const activeContent = activeLanguage
    ? (snippets.get(activeLanguage) ?? '')
    : ''

  return (
    <div className="not-prose my-6 w-full">
      {activeLanguage && activeContent ? (
        <ConnectCodeExample
          code={activeContent}
          language={resolveFenceCodeLanguage(activeLanguage)}
          tabs={languages.map((lang) => ({
            id: lang,
            label: resolveFenceCodeLabel(lang),
          }))}
          activeTabId={activeLanguage}
          onTabChange={setSelected}
          selectorVariant="dropdown"
        />
      ) : null}
    </div>
  )
}
