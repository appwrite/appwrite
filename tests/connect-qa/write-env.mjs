#!/usr/bin/env node

/**
 * Writes the framework-appropriate Appwrite env/config file into a scaffolded QA app.
 * Usage: node write-env.mjs <sdkId> <frameworkId> <usingId> <appDir>
 *
 * Non-web SDKs (react-native, flutter, android, apple) ignore framework/using
 * in getEnvExample; pass the sdk id for both so the call site stays readable.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const [sdk, framework, using, appDir] = process.argv.slice(2)
if (!sdk || !framework || !using || !appDir) {
  throw new Error(
    'Usage: node write-env.mjs <sdkId> <frameworkId> <usingId> <appDir>',
  )
}
const endpoint = process.env.CODE_SNIPPET_QA_ENDPOINT
const projectId = process.env.CODE_SNIPPET_QA_PROJECT_ID
if (!endpoint || !projectId) {
  throw new Error('CODE_SNIPPET_QA_ENDPOINT and CODE_SNIPPET_QA_PROJECT_ID must be set')
}

const here = dirname(fileURLToPath(import.meta.url))
const snippetsTs = resolve(
  here,
  '../../src/components/pages/projects/$projectId/shared/connect-snippets.ts',
)
const source = readFileSync(snippetsTs, 'utf8')

// Extract the getEnvExample function body by brace matching. All braces in
// the function (including those inside its template literals) are balanced.
const start = source.indexOf('function getEnvExample')
if (start === -1) {
  throw new Error(`getEnvExample not found in ${snippetsTs}`)
}
let depth = 0
let end = -1
for (let i = source.indexOf('{', start); i < source.length; i++) {
  if (source[i] === '{') depth++
  else if (source[i] === '}' && --depth === 0) {
    end = i + 1
    break
  }
}
if (end === -1) {
  throw new Error('Could not find the end of getEnvExample')
}

// The function's only TypeScript syntax is in its signature; strip it so the
// body can run as plain JS. Fail loudly if a future edit adds annotations
// this helper does not know how to strip.
const fn = source
  .slice(start, end)
  .replaceAll(": 'client' | 'server'", '')
  .replaceAll(': string', '')
if (/[a-zA-Z)]:\s*[a-zA-Z'|]/.test(fn.slice(0, fn.indexOf(')') + 10))) {
  throw new Error('Unstripped type annotation in getEnvExample - update write-env.mjs')
}
const getEnvExample = new Function(`${fn}; return getEnvExample`)()

/**
 * Where each client SDK expects its config, mirroring the `envLabel` the
 * Connect dialog shows for that SDK in connect-snippets.ts SAMPLE_OVERRIDES.
 * None of these platforms can read a process environment at runtime, so the
 * filename is part of the contract the snippet documents.
 */
const CONFIG_FILES = {
  'react-native': '.env', // loaded by Expo CLI, inlined by Metro
  flutter: 'env.json', // flutter run --dart-define-from-file=env.json
  android: 'gradle.properties', // read by build.gradle.kts into BuildConfig
  apple: 'Appwrite.xcconfig', // surfaced to the app via Info.plist
}

const content = getEnvExample(sdk, 'client', framework, using, endpoint, projectId)
const target =
  sdk in CONFIG_FILES
    ? join(appDir, CONFIG_FILES[sdk])
    : sdk !== 'web'
      ? join(appDir, '.env')
      : framework === 'angular'
        ? join(appDir, 'src/environments/environment.ts')
        : framework === 'next'
          ? join(appDir, '.env.local')
          : join(appDir, '.env')

mkdirSync(dirname(target), { recursive: true })
writeFileSync(target, `${content}\n`)
console.log(`Wrote ${target}:\n${content}`)
