import type { Models } from '@appwrite.io/console'

export type FrameworkAdapterBuildFields = {
  installCommand: string
  buildCommand: string
  outputDirectory: string
  fallbackFile: string
}

/**
 * Resolve the adapter config for a framework (matches legacy console build settings).
 */
export function resolveFrameworkAdapter(
  framework: Models.Framework | undefined,
  adapterKey?: string | null,
): Models.FrameworkAdapter | undefined {
  if (!framework?.adapters?.length) return undefined
  if (adapterKey) {
    const match = framework.adapters.find((a) => a.key === adapterKey)
    if (match) return match
  }
  return framework.adapters[0]
}

/** Build command fields from an adapter (empty string when unset). */
export function getFrameworkAdapterBuildFields(
  adapter: Models.FrameworkAdapter | undefined,
): FrameworkAdapterBuildFields {
  return {
    installCommand: adapter?.installCommand ?? '',
    buildCommand: adapter?.buildCommand ?? '',
    outputDirectory: adapter?.outputDirectory ?? '',
    fallbackFile: adapter?.fallbackFile ?? '',
  }
}

/**
 * Defaults for a framework + adapter pair (site settings placeholders).
 */
export function getFrameworkAdapterDefaults(
  framework: Models.Framework | undefined,
  adapterKey?: string | null,
): FrameworkAdapterBuildFields {
  return getFrameworkAdapterBuildFields(
    resolveFrameworkAdapter(framework, adapterKey),
  )
}

/** Whether the framework supports SSR (has an `ssr` adapter). */
export function frameworkHasSsrAdapter(
  framework: Models.Framework | undefined,
): boolean {
  return framework?.adapters?.some((a) => a.key === 'ssr') ?? false
}

/** Whether the framework supports static hosting (has a `static` adapter). */
export function frameworkHasStaticAdapter(
  framework: Models.Framework | undefined,
): boolean {
  return framework?.adapters?.some((a) => a.key === 'static') ?? false
}

/**
 * Defaults when creating a site (prefer static adapter when available).
 */
export function getFrameworkCreateDefaults(
  framework: Models.Framework | undefined,
): FrameworkAdapterBuildFields & {
  adapter: string
  buildRuntime: string
} {
  if (!framework?.adapters?.length) {
    return {
      installCommand: 'npm install',
      buildCommand: 'npm run build',
      outputDirectory: '.output',
      fallbackFile: '',
      adapter: 'static',
      buildRuntime: 'node-22',
    }
  }
  const adapter =
    framework.adapters.find((a) => a.key === 'static') ?? framework.adapters[0]
  const fields = getFrameworkAdapterBuildFields(adapter)
  return {
    ...fields,
    installCommand: fields.installCommand || 'npm install',
    buildCommand: fields.buildCommand || 'npm run build',
    outputDirectory: fields.outputDirectory || '.output',
    adapter: adapter.key,
    buildRuntime: framework.buildRuntime || 'node-22',
  }
}
