import { describe, expect, test } from 'bun:test'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { Models } from '@appwrite.io/console'
import { QueryClient } from '@tanstack/react-query'
import {
  createMemoryHistory,
  createRootRouteWithContext,
  createRoute,
  createRouter,
  redirect,
  type AnyRoute,
} from '@tanstack/react-router'
import ts from 'typescript'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'

type LoaderOptions = Pick<
  AnyRoute['options'],
  | 'loader'
  | 'preload'
  | 'ssr'
  | 'staleTime'
  | 'preloadStaleTime'
  | 'shouldReload'
>

// Exercise the shipped loaders and preload settings without importing the auth
// forms, SDK, or app router. Only their account-service dependencies are stubbed.
function readLoaderOptions(
  routeFile: string,
  dependencies: Record<string, unknown>,
): LoaderOptions {
  const filename = resolve(import.meta.dir, '../../src/routes', routeFile)
  const source = ts.createSourceFile(
    filename,
    readFileSync(filename, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  let options: ts.ObjectLiteralExpression | undefined
  function visit(node: ts.Node) {
    if (
      ts.isVariableDeclaration(node) &&
      ts.isIdentifier(node.name) &&
      node.name.text === 'Route' &&
      node.initializer &&
      ts.isCallExpression(node.initializer)
    ) {
      const argument = node.initializer.arguments[0]
      if (argument && ts.isObjectLiteralExpression(argument)) options = argument
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  if (!options) throw new Error(`Missing route options in ${routeFile}`)

  const keys = new Set([
    'loader',
    'preload',
    'ssr',
    'staleTime',
    'preloadStaleTime',
    'shouldReload',
  ])
  const properties = options.properties.filter(
    (property) =>
      property.name &&
      ts.isIdentifier(property.name) &&
      keys.has(property.name.text),
  )
  const selected = ts.factory.createObjectLiteralExpression(properties)
  const expression = ts
    .createPrinter()
    .printNode(ts.EmitHint.Expression, selected, source)
  const { outputText } = ts.transpileModule(`const options = ${expression}`, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
  })
  return new Function(
    ...Object.keys(dependencies),
    `${outputText}\nreturn options`,
  )(...Object.values(dependencies)) as LoaderOptions
}

function createAuthRouter(account?: Pick<Models.User, 'emailVerification'>) {
  const accountChecks: string[] = []
  const queryClient = new QueryClient()
  const dependencies = {
    window: {},
    redirect,
    CONSOLE_ENTRY_PATH,
    ensureConsoleAccountOnAuthRoute: async () => {
      accountChecks.push('auth')
    },
    ensureConsoleAccountQueryData: async () => {
      accountChecks.push('account')
      return account
    },
    requiresConsoleEmailVerification: (user: typeof account) =>
      !user?.emailVerification,
    resolvePostAuthRedirect: (target?: string) => target,
    toRedirectNavigateOptions: (to: string) => ({ to }),
  }
  const root = createRootRouteWithContext<{ queryClient: QueryClient }>()()
  const home = createRoute({ getParentRoute: () => root, path: 'home' })
  const console = createRoute({
    getParentRoute: () => root,
    path: CONSOLE_ENTRY_PATH,
  })
  const verification = createRoute({
    getParentRoute: () => root,
    path: 'verify-email',
  })
  const auth = createRoute({
    getParentRoute: () => root,
    id: 'auth',
    ...readLoaderOptions('_auth.tsx', dependencies),
  })
  const children = (['sign-in', 'sign-up'] as const).map((path) =>
    createRoute({
      getParentRoute: () => auth,
      path,
      validateSearch: (search) => search as { redirect?: string },
      ...readLoaderOptions(`_auth/${path}.tsx`, dependencies),
    }),
  )
  const router = createRouter({
    routeTree: root.addChildren([
      home,
      console,
      verification,
      auth.addChildren(children),
    ]),
    history: createMemoryHistory({ initialEntries: ['/home'] }),
    context: { queryClient },
    origin: 'http://localhost',
    defaultPreload: 'intent',
    isServer: false,
  })
  return { router, accountChecks }
}

describe.each(['/sign-in', '/sign-up'] as const)('%s intent preload', (to) => {
  test('keeps guest auth untouched, then checks it on actual navigation', async () => {
    const { router, accountChecks } = createAuthRouter()
    await router.load()
    await router.preloadRoute({ to })
    expect(accountChecks).toEqual([])
    expect(router.state.location.pathname).toBe('/home')

    await router.navigate({ to })
    expect(accountChecks).toEqual(['auth', 'account'])
    expect(router.state.location.pathname).toBe(to)
  })

  test('checks and redirects a signed-in account after a prior preload', async () => {
    const { router, accountChecks } = createAuthRouter({
      emailVerification: true,
    })
    await router.load()
    await router.preloadRoute({ to })
    expect(accountChecks).toEqual([])

    await router.navigate({ to })
    expect(accountChecks).toEqual(['auth', 'account'])
    expect(router.state.location.pathname).toBe(CONSOLE_ENTRY_PATH)
  })

  test('preserves email verification and the return destination after a preload', async () => {
    const { router, accountChecks } = createAuthRouter({
      emailVerification: false,
    })
    await router.load()
    await router.preloadRoute({ to, search: { redirect: '/' } })
    expect(accountChecks).toEqual([])

    await router.navigate({ to, search: { redirect: '/' } })
    expect(accountChecks).toEqual(['auth', 'account'])
    expect(router.state.location.pathname).toBe('/verify-email')
    expect(router.state.location.search).toEqual({ redirect: '/' })
  })
})
