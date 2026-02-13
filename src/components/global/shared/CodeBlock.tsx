'use client'

/// <reference path="../../../prismjs-components.d.ts" />

/**
 * Reusable code block with syntax highlighting for all supported SDK/languages.
 * Uses prism-react-renderer; extra languages (dart, swift, kotlin) are loaded on mount.
 * Uses built-in Prism themes for reliable highlighting; background matches page (--background).
 */

import { useEffect, useRef, useState } from 'react'
import { Copy, Check } from 'lucide-react'
import { Highlight, Prism, themes } from 'prism-react-renderer'
import { useTheme } from 'next-themes'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'

// Expose Prism so prismjs language components can register themselves
if (typeof globalThis !== 'undefined') {
  ;(globalThis as unknown as { Prism: typeof Prism }).Prism = Prism
}

/** Languages we support for syntax highlighting (Prism language ids) */
export type CodeBlockLanguage =
  | 'javascript'
  | 'typescript'
  | 'dart'
  | 'swift'
  | 'kotlin'
  | 'bash'
  | 'php'
  | 'python'
  | 'ruby'
  | 'go'
  | 'csharp'
  | 'markup'
  | 'plaintext'

const EXTRA_LANGUAGES: CodeBlockLanguage[] = [
  'dart',
  'swift',
  'kotlin',
  'php',
  'python',
  'ruby',
  'go',
  'csharp',
  'markup',
]

const PRISM_LOADERS: Record<string, () => Promise<unknown>> = {
  dart: () => import('prismjs/components/prism-dart'),
  swift: () => import('prismjs/components/prism-swift'),
  kotlin: () => import('prismjs/components/prism-kotlin'),
  markup: () => import('prismjs/components/prism-markup'),
  'markup-templating': () =>
    loadLanguage('markup').then(() =>
      import('prismjs/components/prism-markup-templating'),
    ),
  php: () =>
    loadLanguage('markup-templating').then(() =>
      import('prismjs/components/prism-php'),
    ),
  python: () => import('prismjs/components/prism-python'),
  ruby: () => import('prismjs/components/prism-ruby'),
  go: () => import('prismjs/components/prism-go'),
  csharp: () => import('prismjs/components/prism-csharp'),
}

const loadedLanguages = new Set<string>()

function loadLanguage(lang: string): Promise<void> {
  if (loadedLanguages.has(lang)) return Promise.resolve()
  const loader = PRISM_LOADERS[lang]
  if (!loader) return Promise.resolve()
  return loader().then(() => {
    loadedLanguages.add(lang)
  })
}

export interface CodeBlockProps {
  code: string
  language: CodeBlockLanguage
  /** Show copy button (default true) */
  showCopy?: boolean
  /** Render copy button inside the code frame (top-right) instead of above */
  copyInside?: boolean
  /** Fixed height for the code frame (e.g. "280px"); content scrolls when longer */
  fixedHeight?: string
  className?: string
  /** Optional label above the block (e.g. "Code") */
  label?: string
}

export function CodeBlock({
  code,
  language,
  showCopy = true,
  copyInside = false,
  fixedHeight,
  className,
  label,
}: CodeBlockProps) {
  const [copied, setCopied] = useState(false)
  const preRef = useRef<HTMLPreElement>(null)
  const needsExtra = EXTRA_LANGUAGES.includes(language)
  const [extrasReady, setExtrasReady] = useState(!needsExtra)
  const { resolvedTheme } = useTheme()

  const handleWheel = (e: React.WheelEvent<HTMLPreElement>) => {
    const pre = preRef.current
    if (!pre || e.deltaY === 0) return
    const hasVerticalScroll = pre.scrollHeight > pre.clientHeight
    if (hasVerticalScroll) return
    const scrollParent = findScrollParent(pre)
    if (scrollParent && scrollParent instanceof Element) {
      scrollParent.scrollTop += e.deltaY
      e.preventDefault()
    }
  }

  function findScrollParent(el: HTMLElement): HTMLElement | null {
    let parent = el.parentElement
    while (parent) {
      const { overflowY } = getComputedStyle(parent)
      if (/(auto|scroll|overlay)/.test(overflowY) && parent.scrollHeight > parent.clientHeight) {
        return parent
      }
      parent = parent.parentElement
    }
    return null
  }

  useEffect(() => {
    if (!needsExtra) return
    loadLanguage(language).then(() => setExtrasReady(true))
  }, [language, needsExtra])

  const handleCopy = () => {
    navigator.clipboard.writeText(code)
    setCopied(true)
    toast.success('Copied to clipboard')
    setTimeout(() => setCopied(false), 2000)
  }

  const effectiveLanguage = extrasReady ? language : 'plaintext'
  const prismTheme = resolvedTheme === 'dark' ? themes.vsDark : themes.vsLight

  const copyButton = showCopy ? (
    <Button
      variant="ghost"
      size="sm"
      className="h-7 gap-1 text-[12px] text-muted-foreground hover:text-foreground"
      onClick={handleCopy}
    >
      {copied ? (
        <Check className="h-3.5 w-3.5" />
      ) : (
        <Copy className="h-3.5 w-3.5" />
      )}
      Copy
    </Button>
  ) : null

  return (
    <div className={cn('space-y-1.5', className)}>
      {!copyInside && (label || showCopy) && (
        <div
          className={cn(
            'flex items-center',
            label ? 'justify-between' : 'justify-end',
          )}
        >
          {label && (
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {label}
            </span>
          )}
          {copyButton}
        </div>
      )}
      <div
        className={cn(
          'relative rounded-xl border border-border overflow-hidden bg-background flex flex-col',
          fixedHeight && 'min-h-0',
        )}
        style={fixedHeight ? { height: fixedHeight } : undefined}
      >
        {copyInside && copyButton && (
          <div className="absolute right-2 top-2 z-10 shrink-0">{copyButton}</div>
        )}
        <Highlight
          theme={prismTheme}
          code={code}
          language={effectiveLanguage}
        >
          {({ className: preClassName, style, tokens, getLineProps, getTokenProps }) => (
            <pre
              ref={preRef}
              onWheel={handleWheel}
              className={cn(
                'rounded-none overflow-x-auto overflow-y-auto p-4 text-[12px] font-mono !bg-background',
                fixedHeight && 'min-h-0 flex-1',
                copyInside && showCopy && 'pt-10',
                preClassName,
              )}
              style={{
                ...style,
                margin: 0,
                backgroundColor: 'var(--background)',
              }}
            >
              <code className="text-left block">
                {tokens.map((line, i) => (
                  <div key={i} {...getLineProps({ line })}>
                    {line.map((token, key) => (
                      <span key={key} {...getTokenProps({ token })} />
                    ))}
                  </div>
                ))}
              </code>
            </pre>
          )}
        </Highlight>
      </div>
    </div>
  )
}
