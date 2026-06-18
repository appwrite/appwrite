import { parseOpenApiSpec } from '@/lib/api-explorer/parse-spec'
import { getServiceLabel } from '@/lib/api-explorer/services'
import type {
  ApiExplorerMethod,
  ApiSpecPlatform,
  OpenApiOperation,
  OpenApiSpec,
} from '@/lib/api-explorer/types'
import {
  getSpecMode,
  isReferencePlatform,
  isReferenceService,
  isReferenceVersion,
  resolveSpecVersionDirs,
  SERVICE_LABELS,
  type ReferencePlatform,
  type ReferenceService,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { resolveSchemaRef } from '@/lib/docs/references/schema-utils'
import type {
  ApiReferenceMethod,
  ApiReferenceResponse,
  ApiReferenceServiceData,
} from '@/lib/docs/references/types'
import { loadReferenceOpenApiSpec } from './load-spec'

function getServiceDescriptionFromSpec(
  spec: OpenApiSpec,
  serviceId: string,
): string {
  const tag = spec.tags?.find(
    (entry) => entry.name?.toLowerCase() === serviceId.toLowerCase(),
  )
  return tag?.description?.trim() ?? ''
}

const SPECS_EXAMPLES_PREFIX = '../../../node_modules/@appwrite.io/specs/'

const exampleModules = import.meta.glob<string>(
  '../../../node_modules/@appwrite.io/specs/examples/**/*.md',
  { query: '?raw', import: 'default' },
)

const exampleContentCache = new Map<string, string | undefined>()

function stripMarkdownCodeFence(content: string): string {
  const trimmed = content.trim()
  const fenced = trimmed.match(/^```[^\n]*\n([\s\S]*?)\n```[ \t]*$/)
  if (fenced) return fenced[1]
  return content
}

function getExamplePath(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  demo: string,
): string {
  const { examplesDir } = resolveSpecVersionDirs(version)
  const isAndroidJava =
    platform === 'client-android-java' || platform === 'server-java'
  const isAndroidKotlin =
    platform === 'client-android-kotlin' || platform === 'server-kotlin'
  const isAndroid = isAndroidJava || isAndroidKotlin
  const isAndroidServer =
    platform === 'server-java' || platform === 'server-kotlin'

  if (isAndroid) {
    const androidPlatform = isAndroidServer ? 'server-kotlin' : 'client-android'
    const lang = isAndroidJava ? 'java' : 'kotlin'
    return `examples/${examplesDir}/${androidPlatform}/${lang}/${demo}`
  }

  return `examples/${examplesDir}/${platform}/examples/${demo}`
}

function resolveExampleModuleKey(relativePath: string): string | undefined {
  const normalized = relativePath.replace(/\\/g, '/')
  const key = `${SPECS_EXAMPLES_PREFIX}${normalized}`
  return key in exampleModules ? key : undefined
}

async function loadMethodDemo(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  demoPath: string | undefined,
): Promise<string | undefined> {
  if (!demoPath) return undefined

  const relativePath = getExamplePath(version, platform, demoPath)
  const cached = exampleContentCache.get(relativePath)
  if (cached !== undefined) return cached

  const moduleKey = resolveExampleModuleKey(relativePath)
  if (!moduleKey) {
    exampleContentCache.set(relativePath, undefined)
    return undefined
  }

  try {
    const content = stripMarkdownCodeFence((await exampleModules[moduleKey]()) as string)
    exampleContentCache.set(relativePath, content)
    return content
  } catch {
    exampleContentCache.set(relativePath, undefined)
    return undefined
  }
}

async function loadMethodDemos(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  methods: ApiExplorerMethod[],
): Promise<Map<string, string>> {
  const demos = new Map<string, string>()
  await Promise.all(
    methods.map(async (method) => {
      const demo = await loadMethodDemo(
        version,
        platform,
        method.xAppwrite?.demo,
      )
      if (demo) demos.set(method.id, demo)
    }),
  )
  return demos
}

function getRawOperationForMethod(
  spec: OpenApiSpec,
  method: ApiExplorerMethod,
): OpenApiOperation | undefined {
  const pathItem = spec.paths?.[method.path]
  if (!pathItem) return undefined
  return pathItem[method.httpMethod as keyof typeof pathItem] as
    | OpenApiOperation
    | undefined
}

function resolveResponseModels(
  schema: ReturnType<typeof resolveSchemaRef>,
  spec: OpenApiSpec,
): Array<{ id: string; name: string }> {
  if (!schema) return []

  if (schema.oneOf?.length) {
    return schema.oneOf
      .filter((item) => item.$ref)
      .map((item) => {
        const id = item.$ref!.replace('#/components/schemas/', '')
        const resolved = spec.components?.schemas?.[id]
        return { id, name: resolved?.description ?? id }
      })
  }

  if (schema.$ref) {
    const id = schema.$ref.replace('#/components/schemas/', '')
    const resolved = spec.components?.schemas?.[id]
    return [{ id, name: resolved?.description ?? id }]
  }

  return []
}

function collectMethodResponses(
  operation: OpenApiOperation | undefined,
  spec: OpenApiSpec,
): ApiReferenceResponse[] {
  if (!operation?.responses) return []

  return Object.entries(operation.responses).map(([code, response]) => {
    const responseObj = response as {
      content?: Record<string, { schema?: { $ref?: string; oneOf?: Array<{ $ref?: string }> } }>
    }
    const content = responseObj.content?.['application/json']
    const schema = resolveSchemaRef(content?.schema, spec)
    const models =
      Number(code) === 204 ? [] : resolveResponseModels(schema, spec)

    return {
      code: Number(code),
      contentType: responseObj.content
        ? Object.keys(responseObj.content)[0]
        : undefined,
      models,
    }
  })
}

export async function loadApiReferenceService(
  version: string,
  platform: string,
  serviceId: string,
): Promise<ApiReferenceServiceData | null> {
  if (
    !isReferenceVersion(version) ||
    !isReferencePlatform(platform) ||
    !isReferenceService(serviceId)
  ) {
    return null
  }

  const spec = await loadReferenceOpenApiSpec(version, platform)
  const mode = getSpecMode(platform) as ApiSpecPlatform
  const parsed = parseOpenApiSpec(spec, mode)
  const service = parsed.services.find((item) => item.id === serviceId)

  if (!service) {
    return {
      id: serviceId,
      label: SERVICE_LABELS[serviceId as ReferenceService] ?? getServiceLabel(serviceId),
      description: getServiceDescriptionFromSpec(spec, serviceId),
      methods: [],
    }
  }

  const demos = await loadMethodDemos(version, platform, service.methods)

  const methods: ApiReferenceMethod[] = service.methods.map((method) => {
    const rawOperation = getRawOperationForMethod(spec, method)
    return {
      ...method,
      demo: demos.get(method.id),
      responses: collectMethodResponses(rawOperation, spec),
    }
  })

  return {
    id: serviceId,
    label: SERVICE_LABELS[serviceId as ReferenceService] ?? service.label,
    description: service.description ?? '',
    methods,
  }
}
