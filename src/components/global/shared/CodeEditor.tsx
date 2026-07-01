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
import {
  applyMonacoAppTheme,
  defineMonacoAppTheme,
  monacoAppThemeId,
} from '@/lib/monaco-app-theme'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'

export type CodeEditorLanguage =
  | 'javascript'
  | 'typescript'
  | 'python'
  | 'json'
  | 'dart'
  | 'kotlin'
  | 'swift'
  | 'php'
  | 'ruby'
  | 'csharp'
  | 'go'
  | 'java'
  | 'sql'
  | 'shell'
  | 'yaml'
  | 'rust'
  | 'graphql'
  | 'css'
  | 'html'
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
  onEditorMount?: (
    instance: editor.IStandaloneCodeEditor,
    monaco: typeof import('monaco-editor'),
  ) => void
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
    const monacoRef = useRef<typeof import('monaco-editor') | null>(null)
    const valueRef = useRef(value)

    useEffect(() => {
      valueRef.current = value
    }, [value])

    const handleBeforeMount = useCallback(
      (monaco: typeof import('monaco-editor')) => {
        monacoRef.current = monaco
        defineMonacoAppTheme(monaco, resolvedTheme, isDarkChrome)
      },
      [isDarkChrome, resolvedTheme],
    )

    const handleEditorMount: OnMount = useCallback(
      (editorInstance, monaco) => {
        editorRef.current = editorInstance
        monacoRef.current = monaco
        applyMonacoAppTheme(monaco, resolvedTheme, isDarkChrome)
        onEditorMount?.(editorInstance, monaco)
      },
      [isDarkChrome, onEditorMount, resolvedTheme],
    )

    useEffect(() => {
      const monaco = monacoRef.current
      if (!monaco) return
      applyMonacoAppTheme(monaco, resolvedTheme, isDarkChrome)
      editorRef.current?.updateOptions({
        theme: monacoAppThemeId(resolvedTheme, isDarkChrome),
      })
    }, [isDarkChrome, resolvedTheme])

    useEffect(() => {
      return () => {
        editorRef.current = null
      }
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
        dir="ltr"
        data-code-example
        className={cn(
          FORCE_LTR_CLASS,
          'overflow-hidden rounded-lg border border-border bg-background',
          className,
        )}
      >
        <Editor
          key={`monaco-${monacoMountKey}`}
          height={typeof height === 'number' ? `${height}px` : height}
          defaultLanguage={language}
          language={language}
          path={modelPath}
          value={value}
          keepCurrentModel={Boolean(modelPath)}
          onChange={handleChange}
          beforeMount={handleBeforeMount}
          onMount={handleEditorMount}
          theme={monacoAppThemeId(resolvedTheme, isDarkChrome)}
          loading={null}
          options={{
            readOnly,
            minimap: { enabled: minimap },
            lineNumbers,
            renderLineHighlight: 'none',
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
