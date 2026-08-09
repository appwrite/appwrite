'use client'

/// <reference path="../../../prismjs-components.d.ts" />

/**
 * Reusable code block with syntax highlighting for all Appwrite SDK and runtime languages.
 * Supports: JavaScript, TypeScript, Node/Deno/Bun, Python, PHP, Ruby, Dart, Swift, Kotlin, Java,
 * Go, C#/.NET, C++, Rust, JSON, YAML, TOML, Bash, PowerShell, HCL (Terraform), GraphQL, HTTP,
 * Markdown, Diff, markup, plaintext, and .env (dotenv).
 * Uses prism-react-renderer with the Snapwrite-inspired palette from
 * `@/lib/code-syntax-theme`; editor surface is transparent so the page background shows through.
 */

import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import { Copy, Check, Maximize2 } from 'lucide-react'
import { Highlight, Prism } from 'prism-react-renderer'
import { useTheme } from 'next-themes'
import {
  buildCodeBlockPrismTheme,
  CODE_BLOCK_PRISM_SURFACE_CLASS,
  resolvePrismPreSurfaceStyle,
  stripPrismTokenBackground,
} from '@/lib/code-block-prism-theme'
import {
  isHtmlDarkChrome,
  isResolvedThemeDarkChrome,
} from '@/lib/html-theme'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

// Expose Prism so prismjs language components can register themselves
if (typeof globalThis !== 'undefined') {
  ;(globalThis as unknown as { Prism: typeof Prism }).Prism = Prism
}

// Register .env / dotenv syntax (KEY=value, # comments, quoted values)
if (typeof Prism !== 'undefined' && !Prism.languages.env) {
  Prism.languages.env = {
    comment: /#.*/,
    'attr-name': /^[A-Za-z_][A-Za-z0-9_]*/m,
    operator: /=/,
    string: [
      { pattern: /"(?:[^"\\]|\\.)*"/, greedy: true },
      { pattern: /'(?:[^'\\]|\\.)*'/, greedy: true },
    ],
  }
}

/**
 * Languages we support for syntax highlighting.
 * Includes Prism language ids and Appwrite SDK/runtime identifiers (node, deno, bun, dotnet, java).
 */
export type CodeBlockLanguage =
  | 'javascript'
  | 'typescript'
  | 'json'
  | 'dart'
  | 'swift'
  | 'kotlin'
  | 'java'
  | 'bash'
  | 'powershell'
  | 'php'
  | 'python'
  | 'ruby'
  | 'go'
  | 'csharp'
  | 'markup'
  | 'plaintext'
  | 'env'
  | 'hcl'
  | 'rust'
  | 'graphql'
  | 'http'
  | 'groovy'
  | 'docker'
  | 'css'
  | 'yaml'
  | 'toml'
  | 'cpp'
  | 'markdown'
  | 'diff'
  // Appwrite runtime keys (map to Prism languages below)
  | 'node'
  | 'deno'
  | 'bun'
  | 'dotnet'

/** Map Appwrite runtime keys to Prism language ids for highlighting */
const RUNTIME_TO_PRISM: Record<string, string> = {
  node: 'javascript',
  deno: 'javascript',
  bun: 'javascript',
  dotnet: 'csharp',
}

function getPrismLanguage(lang: CodeBlockLanguage): string {
  return RUNTIME_TO_PRISM[lang] ?? lang
}

/** Markdown fences often end with a trailing newline that Prism renders as a blank line. */
export function normalizeCodeBlockContent(code: string): string {
  return code.endsWith('\n') ? code.slice(0, -1) : code
}

export function getCodeLanguageLabel(lang: CodeBlockLanguage): string {
  const labels: Partial<Record<CodeBlockLanguage, string>> = {
    javascript: 'JavaScript',
    typescript: 'TypeScript',
    json: 'JSON',
    dart: 'Dart',
    swift: 'Swift',
    kotlin: 'Kotlin',
    java: 'Java',
    bash: 'Bash',
    powershell: 'PowerShell',
    php: 'PHP',
    python: 'Python',
    ruby: 'Ruby',
    go: 'Go',
    csharp: 'C#',
    markup: 'Markup',
    plaintext: 'Plain text',
    env: '.env',
    hcl: 'Terraform',
    rust: 'Rust',
    graphql: 'GraphQL',
    http: 'HTTP',
    groovy: 'Groovy',
    docker: 'Dockerfile',
    css: 'CSS',
    yaml: 'YAML',
    toml: 'TOML',
    cpp: 'C++',
    markdown: 'Markdown',
    diff: 'Diff',
    node: 'Node.js',
    deno: 'Deno',
    bun: 'Bun',
    dotnet: '.NET',
  }

  return labels[lang] ?? lang
}

const EXTRA_LANGUAGES: string[] = [
  'json',
  'dart',
  'swift',
  'kotlin',
  'java',
  'bash',
  'powershell',
  'php',
  'python',
  'ruby',
  'go',
  'csharp',
  'markup',
  'hcl',
  'rust',
  'graphql',
  'http',
  'groovy',
  'docker',
  'css',
  'yaml',
  'toml',
  'cpp',
  'markdown',
  'diff',
]

const PRISM_LOADERS: Record<string, () => Promise<unknown>> = {
  json: () => import('prismjs/components/prism-json'),
  dart: () => import('prismjs/components/prism-dart'),
  swift: () => import('prismjs/components/prism-swift'),
  kotlin: () => import('prismjs/components/prism-kotlin'),
  java: () => import('prismjs/components/prism-java'),
  bash: () => import('prismjs/components/prism-bash'),
  powershell: () => import('prismjs/components/prism-powershell'),
  markup: () => import('prismjs/components/prism-markup'),
  'markup-templating': () =>
    loadLanguage('markup').then(
      () => import('prismjs/components/prism-markup-templating'),
    ),
  php: () =>
    loadLanguage('markup-templating').then(
      () => import('prismjs/components/prism-php'),
    ),
  python: () => import('prismjs/components/prism-python'),
  ruby: () => import('prismjs/components/prism-ruby'),
  go: () => import('prismjs/components/prism-go'),
  csharp: () => import('prismjs/components/prism-csharp'),
  hcl: () => import('prismjs/components/prism-hcl'),
  rust: () => import('prismjs/components/prism-rust'),
  graphql: () => import('prismjs/components/prism-graphql'),
  http: () => import('prismjs/components/prism-http'),
  groovy: () => import('prismjs/components/prism-groovy'),
  docker: () => import('prismjs/components/prism-docker'),
  css: () => import('prismjs/components/prism-css'),
  yaml: () => import('prismjs/components/prism-yaml'),
  toml: () => import('prismjs/components/prism-toml'),
  c: () => import('prismjs/components/prism-c'),
  cpp: () =>
    loadLanguage('c').then(() => import('prismjs/components/prism-cpp')),
  markdown: () =>
    loadLanguage('markup').then(
      () => import('prismjs/components/prism-markdown'),
    ),
  diff: () => import('prismjs/components/prism-diff'),
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

/** Docs and API reference pages use JSON heavily; preload to avoid plaintext flash. */
if (typeof window !== 'undefined') {
  void loadLanguage('json')
}

export type CodeBlockVariant = 'default' | 'headless'

export type CodeBlockSurface = 'default' | 'muted' | 'transparent'

function resolveCodeBlockSurface(
  surface?: CodeBlockSurface,
  transparentBackground?: boolean,
): CodeBlockSurface {
  if (transparentBackground) return 'transparent'
  return surface ?? 'default'
}

const SURFACE_FRAME_CLASS: Record<CodeBlockSurface, string> = {
  default: 'bg-transparent',
  muted: 'bg-transparent',
  transparent: 'bg-transparent',
}

const SURFACE_PRE_CLASS: Record<CodeBlockSurface, string> = {
  default: CODE_BLOCK_PRISM_SURFACE_CLASS,
  muted: CODE_BLOCK_PRISM_SURFACE_CLASS,
  transparent: CODE_BLOCK_PRISM_SURFACE_CLASS,
}

export interface CodeBlockProps {
  code: string
  language: CodeBlockLanguage
  /**
   * `default`: framed block with border and rounded corners.
   * `headless`: no outer border; full width - use flush inside a parent card.
   */
  variant?: CodeBlockVariant
  /** Show copy button (default true) */
  showCopy?: boolean
  /** Render copy button inside the code frame (top-right) instead of above */
  copyInside?: boolean
  /**
   * Fixed height for the code frame (e.g. "280px"); when set, content scrolls
   * vertically inside the block. Omit to size the block to the code (no inner
   * vertical scroll; wide lines still scroll horizontally).
   */
  fixedHeight?: string
  className?: string
  /** Optional label above the block (e.g. "Code") */
  label?: string
  /**
   * Editor surface inside the frame.
   * `muted` matches parent cards (`bg-card/50`) in docs tabs/multicode.
   */
  surface?: CodeBlockSurface
  /** @deprecated Prefer `surface="transparent"` */
  transparentBackground?: boolean
  /** Show fullscreen button (default false) */
  showFullscreen?: boolean
  /** Wrap long lines instead of horizontal scrolling */
  wrapLines?: boolean
}

export function CodeBlock({
  code,
  language,
  variant = 'default',
  showCopy = true,
  copyInside = false,
  fixedHeight,
  className,
  label,
  surface,
  transparentBackground = false,
  showFullscreen = false,
  wrapLines = false,
}: CodeBlockProps) {
  const t = useT()
  const resolvedSurface = resolveCodeBlockSurface(surface, transparentBackground)
  const [copied, setCopied] = useState(false)
  const [isFullscreenOpen, setIsFullscreenOpen] = useState(false)
  const preRef = useRef<HTMLPreElement>(null)
  const prismLanguage = getPrismLanguage(language)
  const needsExtra = EXTRA_LANGUAGES.includes(prismLanguage)
  // Prefer Prism's live registry (built-ins like json ship with prism-react-renderer)
  // so we don't fall back to plaintext while a redundant dynamic import is in flight.
  const languageIsRegistered =
    !needsExtra ||
    loadedLanguages.has(prismLanguage) ||
    Boolean(Prism.languages[prismLanguage])
  // Bump when async Prism grammars finish loading so Highlight re-tokenizes.
  const [, setLoadGeneration] = useState(0)
  const { resolvedTheme } = useTheme()
  const isDarkChrome =
    resolvedTheme !== undefined
      ? isResolvedThemeDarkChrome(resolvedTheme)
      : isHtmlDarkChrome()

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
      if (
        /(auto|scroll|overlay)/.test(overflowY) &&
        parent.scrollHeight > parent.clientHeight
      ) {
        return parent
      }
      parent = parent.parentElement
    }
    return null
  }

  useEffect(() => {
    if (languageIsRegistered) return
    let cancelled = false
    loadLanguage(prismLanguage).then(() => {
      if (!cancelled) setLoadGeneration((n) => n + 1)
    })
    return () => {
      cancelled = true
    }
  }, [prismLanguage, languageIsRegistered])

  const handleCopy = () => {
    navigator.clipboard.writeText(displayCode)
    setCopied(true)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopied(false), 2000)
  }

  const effectiveLanguage = languageIsRegistered ? prismLanguage : 'plaintext'
  const displayCode = useMemo(() => normalizeCodeBlockContent(code), [code])
  const prismTheme = useMemo(
    () => buildCodeBlockPrismTheme(resolvedTheme),
    [isDarkChrome],
  )

  const isHeadless = variant === 'headless'
  const isNestedSurface = resolvedSurface === 'muted'

  const preOverflowClasses = wrapLines
    ? 'overflow-x-hidden whitespace-pre-wrap break-words [overflow-wrap:anywhere]'
    : 'overflow-x-auto'

  const lineWrapClasses = wrapLines ? 'min-w-0 w-full break-all' : undefined

  const renderCopyButton = () => {
    if (!showCopy) return null
    return (
      <Button
        variant="ghost"
        size="sm"
        className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
        onClick={handleCopy}
        aria-label={t('Copy code')}
      >
        {copied ? (
          <Check className="h-3.5 w-3.5" />
        ) : (
          <Copy className="h-3.5 w-3.5" />
        )}
      </Button>
    )
  }

  const renderFullscreenButton = () => {
    if (!showFullscreen) return null
    return (
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
        onClick={() => setIsFullscreenOpen(true)}
      >
        <Maximize2 className="h-3.5 w-3.5" />
      </Button>
    )
  }

  return (
    <div
      dir="ltr"
      data-code-example
      className={cn(
        FORCE_LTR_CLASS,
        'w-full',
        isHeadless ? 'space-y-0' : 'space-y-1.5',
        className,
      )}
    >
      {!copyInside && (label || showCopy || showFullscreen) && (
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
          <div className="flex items-center gap-1">
            {renderCopyButton()}
            {renderFullscreenButton()}
          </div>
        </div>
      )}
      <div
        className={cn(
          'relative flex w-full flex-col overflow-hidden',
          isHeadless
            ? 'rounded-none border-0 shadow-none'
            : isNestedSurface
              ? 'rounded-none border-0 shadow-none'
              : 'rounded-xl border border-border',
          SURFACE_FRAME_CLASS[resolvedSurface],
          fixedHeight && 'min-h-0',
        )}
        style={fixedHeight ? { height: fixedHeight } : undefined}
      >
        {copyInside && (showCopy || showFullscreen) && (
          <div
            className={cn(
              'flex h-10 shrink-0 items-center justify-between px-3',
              isHeadless
                ? 'border-0 bg-muted/20'
                : 'border-b border-border',
            )}
          >
            <span className="text-[11px] font-medium text-muted-foreground">
              {getCodeLanguageLabel(language)}
            </span>
            <div className="flex items-center gap-1">
              {renderCopyButton()}
              {renderFullscreenButton()}
            </div>
          </div>
        )}
        <Highlight
          key={effectiveLanguage}
          theme={prismTheme}
          code={displayCode}
          language={effectiveLanguage}
        >
          {({
            className: preClassName,
            style,
            tokens,
            getLineProps,
            getTokenProps,
          }) => (
            <pre
              ref={preRef}
              onWheel={handleWheel}
              className={cn(
                'rounded-none text-[12px] font-mono',
                isNestedSurface ? 'px-0 py-2' : 'p-4',
                preOverflowClasses,
                fixedHeight && 'min-h-0 flex-1 overflow-y-auto',
                preClassName,
                SURFACE_PRE_CLASS[resolvedSurface],
              )}
              style={resolvePrismPreSurfaceStyle(style as CSSProperties)}
            >
              <code className="block bg-transparent text-start">
                {tokens.map((line, i) => {
                  const lineProps = getLineProps({
                    line,
                    className: cn(lineWrapClasses, 'bg-transparent'),
                  })
                  lineProps.style = stripPrismTokenBackground(
                    lineProps.style as CSSProperties,
                  )
                  return (
                    <div key={i} {...lineProps}>
                      {line.map((token, key) => {
                        const tokenProps = getTokenProps({ token })
                        if (tokenProps.style) {
                          tokenProps.style = stripPrismTokenBackground(
                            tokenProps.style as CSSProperties,
                          )
                        }
                        return <span key={key} {...tokenProps} />
                      })}
                    </div>
                  )
                })}
              </code>
            </pre>
          )}
        </Highlight>
      </div>
      {showFullscreen && isFullscreenOpen && (
        <WizardLayout
          title={`${getCodeLanguageLabel(language)} ${t('example')}`}
          fullscreen
          useSidebar={false}
          constrainWidth={false}
          constrainFooterWidth={false}
          contentPadding={false}
          onClose={() => setIsFullscreenOpen(false)}
          contentClassName="-mx-6"
          headerActions={renderCopyButton()}
        >
          <div dir="ltr" data-code-example className={FORCE_LTR_CLASS}>
            <Highlight
              key={effectiveLanguage}
              theme={prismTheme}
              code={displayCode}
              language={effectiveLanguage}
            >
              {({
                className: preClassName,
                style,
                tokens,
                getLineProps,
                getTokenProps,
              }) => (
                <pre
                  className={cn(
                    'p-6 text-[12px] font-mono',
                    preOverflowClasses,
                    preClassName,
                    CODE_BLOCK_PRISM_SURFACE_CLASS,
                  )}
                  style={resolvePrismPreSurfaceStyle(style as CSSProperties)}
                >
                  <code className="block bg-transparent text-start">
                    {tokens.map((line, i) => {
                      const lineProps = getLineProps({
                        line,
                        className: cn(lineWrapClasses, 'bg-transparent'),
                      })
                      lineProps.style = stripPrismTokenBackground(
                        lineProps.style as CSSProperties,
                      )
                      return (
                        <div key={i} {...lineProps}>
                          {line.map((token, key) => {
                            const tokenProps = getTokenProps({ token })
                            if (tokenProps.style) {
                              tokenProps.style = stripPrismTokenBackground(
                                tokenProps.style as CSSProperties,
                              )
                            }
                            return <span key={key} {...tokenProps} />
                          })}
                        </div>
                      )
                    })}
                  </code>
                </pre>
              )}
            </Highlight>
          </div>
        </WizardLayout>
      )}
    </div>
  )
}
