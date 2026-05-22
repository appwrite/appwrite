import type { Models } from '@appwrite.io/console'

export type FrameworkAdapterBuildFields = {
  installCommand: string
  buildCommand: string
  startCommand: string
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
    startCommand: adapter?.startCommand ?? '',
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
      startCommand: '',
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
