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
import { useTheme } from 'next-themes'
import { cn } from '@/lib/utils'
import {
  isHtmlDarkChrome,
  isResolvedThemeDarkChrome,
} from '@/lib/html-theme'

/** Editor dark background (--editor-bg in .dark); use #09090b so Monaco --vscode-editor-background matches */
const APP_DARK_BG_HEX = '#09090b'
const APP_DARK_THEME_ID = 'app-dark'
const APP_LIGHT_THEME_ID = 'app-light'

function rgbCssToHex(rgb: string): string | null {
  const m = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
  if (!m) return null
  return (
    '#' +
    [Number(m[1]), Number(m[2]), Number(m[3])]
      .map((x) => x.toString(16).padStart(2, '0'))
      .join('')
  )
}

/** Resolve a theme color variable to hex (browser computes oklch etc. to rgb). */
function cssVarToHex(
  varName: string,
  kind: 'background' | 'foreground',
  fallback: string,
): string {
  if (typeof document === 'undefined') return fallback
  const el = document.createElement('div')
  el.style.cssText =
    kind === 'background'
      ? `position:absolute;left:-9999px;top:0;width:1px;height:1px;background:var(${varName})`
      : `position:absolute;left:-9999px;color:var(${varName})`
  document.documentElement.appendChild(el)
  const raw =
    kind === 'background'
      ? getComputedStyle(el).backgroundColor
      : getComputedStyle(el).color
  document.documentElement.removeChild(el)
  if (!raw || raw === 'transparent') return fallback
  const hex = rgbCssToHex(raw)
  return hex && /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : fallback
}

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
  /** Called when the Monaco editor instance is ready (e.g. for undo/redo toolbar). */
  onEditorMount?: (instance: editor.IStandaloneCodeEditor) => void
  /**
   * Stable Monaco model URI path (see @monaco-editor/react `path`). Keeps one model per
   * document so undo/redo isn’t reset when the wrapper swaps models.
   */
  modelPath?: string
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
      onEditorMount,
      modelPath,
    },
    ref,
  ) => {
    const { resolvedTheme } = useTheme()
    const isDarkChrome =
      resolvedTheme !== undefined
        ? isResolvedThemeDarkChrome(resolvedTheme)
        : isHtmlDarkChrome()
    /** Remount when theme changes so beforeMount re-reads CSS vars; include resolved theme name. */
    const monacoMountKey =
      resolvedTheme ?? (isDarkChrome ? 'dark-chrome' : 'light-chrome')

    const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
    const valueRef = useRef(value)

    useEffect(() => {
      valueRef.current = value
    }, [value])

    const handleBeforeMount = useCallback(
      (monaco: typeof import('monaco-editor')) => {
        const bgLight = cssVarToHex('--background', 'background', '#ffffff')
        const fgLight = cssVarToHex('--foreground', 'foreground', '#18181b')
        const mutedLight = cssVarToHex(
          '--muted-foreground',
          'foreground',
          '#71717a',
        )
        monaco.editor.defineTheme(APP_LIGHT_THEME_ID, {
          base: 'vs',
          inherit: true,
          rules: [],
          colors: {
            'editor.background': bgLight,
            'editorGutter.background': bgLight,
            'editor.foreground': fgLight,
            'editor.lineHighlightBackground': bgLight,
            'editorLineNumber.foreground': mutedLight,
            'editorCursor.foreground': fgLight,
            'editor.selectionBackground': '#e5e7eb',
            'editorWidget.background': bgLight,
            'editorSuggestWidget.background': bgLight,
            'minimap.background': bgLight,
            'minimapGutter.background': bgLight,
          },
        })

        const bgDark = getAppBackgroundHex()
        monaco.editor.defineTheme(APP_DARK_THEME_ID, {
          base: 'vs-dark',
          inherit: true,
          rules: [],
          colors: {
            'editor.background': bgDark,
            'editorGutter.background': bgDark,
            'editor.lineHighlightBackground': bgDark,
            'editorWidget.background': bgDark,
            'editorSuggestWidget.background': bgDark,
            'minimap.background': bgDark,
            'minimapGutter.background': bgDark,
          },
        })
      },
      [],
    )

    const handleEditorMount: OnMount = useCallback(
      (editorInstance) => {
        editorRef.current = editorInstance
        onEditorMount?.(editorInstance)
      },
      [onEditorMount],
    )

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
          key={`monaco-${monacoMountKey}`}
          height={typeof height === 'number' ? `${height}px` : height}
          defaultLanguage={language}
          path={modelPath}
          value={value}
          onChange={handleChange}
          beforeMount={handleBeforeMount}
          onMount={handleEditorMount}
          theme={isDarkChrome ? APP_DARK_THEME_ID : APP_LIGHT_THEME_ID}
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
