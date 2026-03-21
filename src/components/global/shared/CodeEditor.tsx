'use client'

import {
  useRef,
  useCallback,
  useEffect,
  forwardRef,
  useImperativeHandle,
} from 'react'
import Editor, { type OnMount, type OnChange } from '@monaco-editor/react'
import type { editor } from 'monaco-editor'
import { cn } from '@/lib/utils'
import { isHtmlDarkChrome } from '@/lib/html-theme'

/** Editor dark background (--editor-bg in .dark); use #09090b so Monaco --vscode-editor-background matches */
const APP_DARK_BG_HEX = '#09090b'
const APP_DARK_THEME_ID = 'app-dark'

/** Read editor background hex: use --editor-bg from .dark, or compute from --background. */
function getAppBackgroundHex(): string {
  if (typeof document === 'undefined') return APP_DARK_BG_HEX
  const root = document.documentElement
  const editorBg = getComputedStyle(root).getPropertyValue('--editor-bg').trim()
  if (editorBg && editorBg.startsWith('#')) return editorBg
  // Fallback: compute from --background (div must inherit .dark to get dark --background)
  const el = document.createElement('div')
  el.className = 'dark'
  el.style.cssText =
    'position:absolute;width:0;height:0;background:var(--background)'
  document.body.appendChild(el)
  const rgb = getComputedStyle(el).backgroundColor
  document.body.removeChild(el)
  const m = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/)
  if (m)
    return (
      '#' +
      [Number(m[1]), Number(m[2]), Number(m[3])]
        .map((x) => x.toString(16).padStart(2, '0'))
        .join('')
    )
  return APP_DARK_BG_HEX
}

export type CodeEditorLanguage =
  | 'javascript'
  | 'typescript'
  | 'python'
  | 'json'
  | 'plaintext'

export interface CodeEditorRef {
  getValue: () => string
  getEditor: () => editor.IStandaloneCodeEditor | null
}

export interface CodeEditorProps {
  value: string
  onChange?: (value: string) => void
  language?: CodeEditorLanguage
  fileName?: string
  height?: string | number
  className?: string
  readOnly?: boolean
  minimap?: boolean
  lineNumbers?: 'on' | 'off'
  /** Entrypoint filename for deployment (e.g. "index.js"). Used when building the gzip package. */
  entrypoint?: string
}

function isDarkMode(): boolean {
  return isHtmlDarkChrome()
}

export const CodeEditor = forwardRef<CodeEditorRef, CodeEditorProps>(
  (
    {
      value,
      onChange,
      language = 'javascript',
      height = 400,
      className,
      readOnly = false,
      minimap = false,
      lineNumbers = 'on',
    },
    ref,
  ) => {
    const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
    const valueRef = useRef(value)

    useEffect(() => {
      valueRef.current = value
    }, [value])

    const handleBeforeMount = useCallback(
      (monaco: typeof import('monaco-editor')) => {
        const isDark = isHtmlDarkChrome()
        if (isDark) {
          const bg = getAppBackgroundHex()
          monaco.editor.defineTheme(APP_DARK_THEME_ID, {
            base: 'vs-dark',
            inherit: true,
            rules: [],
            colors: {
              'editor.background': bg,
              'editorGutter.background': bg,
              'editorWidget.background': bg,
              'editorSuggestWidget.background': bg,
              'minimap.background': bg,
              'minimapGutter.background': bg,
            },
          })
        }
      },
      [],
    )

    const handleEditorMount: OnMount = useCallback((editorInstance) => {
      editorRef.current = editorInstance
    }, [])

    const handleChange: OnChange = useCallback(
      (newValue) => {
        valueRef.current = newValue ?? ''
        onChange?.(newValue ?? '')
      },
      [onChange],
    )

    useImperativeHandle(
      ref,
      () => ({
        getValue: () => editorRef.current?.getValue() ?? valueRef.current,
        getEditor: () => editorRef.current,
      }),
      [],
    )

    return (
      <div
        className={cn(
          'overflow-hidden rounded-lg border border-border bg-background',
          className,
        )}
      >
        <Editor
          height={typeof height === 'number' ? `${height}px` : height}
          defaultLanguage={language}
          value={value}
          onChange={handleChange}
          beforeMount={handleBeforeMount}
          onMount={handleEditorMount}
          theme={isDarkMode() ? APP_DARK_THEME_ID : 'light'}
          loading={null}
          options={{
            readOnly,
            minimap: { enabled: minimap },
            lineNumbers,
            scrollBeyondLastLine: false,
            fontSize: 13,
            fontFamily:
              "source-code-pro, Menlo, Monaco, Consolas, 'Courier New', monospace",
            padding: { top: 12, bottom: 12 },
            tabSize: 2,
            wordWrap: 'on',
            automaticLayout: true,
          }}
        />
      </div>
    )
  },
)

CodeEditor.displayName = 'CodeEditor'
