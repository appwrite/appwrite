import { loader } from '@monaco-editor/react'

/**
 * `@monaco-editor/react` downloads Monaco from a CDN by default, so every editor
 * in the console is blank without internet access. Point it at the copy that is
 * already a dependency, with the bundled worker for each language service.
 */
let configuring: Promise<void> | null = null

export function configureBundledMonaco(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve()
  configuring ??= (async () => {
    const [monaco, editor, json, css, html, typescript] = await Promise.all([
      import('monaco-editor'),
      import('monaco-editor/esm/vs/editor/editor.worker?worker'),
      import('monaco-editor/esm/vs/language/json/json.worker?worker'),
      import('monaco-editor/esm/vs/language/css/css.worker?worker'),
      import('monaco-editor/esm/vs/language/html/html.worker?worker'),
      import('monaco-editor/esm/vs/language/typescript/ts.worker?worker'),
    ])
    window.MonacoEnvironment = {
      getWorker(_workerId: string, label: string) {
        switch (label) {
          case 'json':
            return new json.default()
          case 'css':
          case 'scss':
          case 'less':
            return new css.default()
          case 'html':
          case 'handlebars':
          case 'razor':
            return new html.default()
          case 'typescript':
          case 'javascript':
            return new typescript.default()
          default:
            return new editor.default()
        }
      },
    }
    loader.config({ monaco })
  })()
  return configuring
}
