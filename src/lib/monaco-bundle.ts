import { loader } from '@monaco-editor/react'

/**
 * `@monaco-editor/react` downloads Monaco from a CDN by default, so every editor
 * in the console is blank without internet access. Point it at the copy that is
 * already a dependency, and give it bundled workers.
 */
let configuring: Promise<void> | null = null

export function configureBundledMonaco(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve()
  configuring ??= (async () => {
    const [monaco, editorWorker, htmlWorker] = await Promise.all([
      import('monaco-editor'),
      import('monaco-editor/esm/vs/editor/editor.worker?worker'),
      import('monaco-editor/esm/vs/language/html/html.worker?worker'),
    ])
    window.MonacoEnvironment = {
      getWorker(_workerId: string, label: string) {
        return label === 'html'
          ? new htmlWorker.default()
          : new editorWorker.default()
      },
    }
    loader.config({ monaco })
  })()
  return configuring
}
