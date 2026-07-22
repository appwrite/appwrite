import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { parseOpenApiSpec } from '@/lib/api-explorer/parse-spec'
import {
  getServiceLabel,
  isConsoleOnlyDatabaseApiService,
} from '@/lib/api-explorer/services'
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
import { resolveResponseModels } from '@/lib/docs/references/schema-utils'
import { buildInlineResponseModel } from '@/lib/docs/references/parse-model'
import type {
  ApiReferenceMethod,
  ApiReferenceResponse,
  ApiReferenceServiceData,
} from '@/lib/docs/references/types'
import { loadReferenceOpenApiSpec, loadReferenceConsoleSpec } from './load-spec'
import { getSpecsPackageRoot } from './specs-path'

function getServiceDescriptionFromSpec(
  spec: OpenApiSpec,
  serviceId: string,
): string {
  const tag = spec.tags?.find(
    (entry) => entry.name?.toLowerCase() === serviceId.toLowerCase(),
  )
  return tag?.description?.trim() ?? ''
}

const exampleContentCache = new Map<string, string | undefined>()

function stripMarkdownCodeFence(content: string): string {
  const trimmed = content.trim()
  const withNewlineBeforeClose = trimmed.match(
    /^```[^\n]*\n([\s\S]*?)\n```[ \t]*$/,
  )
  if (withNewlineBeforeClose) return withNewlineBeforeClose[1].trimEnd()

  const closingFenceOnLastLine = trimmed.match(
    /^```[^\n]*\n([\s\S]*?)```[ \t]*$/,
  )
  if (closingFenceOnLastLine) return closingFenceOnLastLine[1].trimEnd()

  if (trimmed.startsWith('```')) {
    const withoutOpen = trimmed.replace(/^```[^\n]*\n?/, '')
    const withoutClose = withoutOpen.replace(/\n?```[ \t]*$/, '')
    if (withoutClose !== trimmed) return withoutClose.trimEnd()
  }

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

async function loadMethodDemo(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  demoPath: string | undefined,
): Promise<string | undefined> {
  if (!demoPath) return undefined

  const relativePath = getExamplePath(version, platform, demoPath)
  const cached = exampleContentCache.get(relativePath)
  if (cached !== undefined) return cached

  const filePath = join(getSpecsPackageRoot(), relativePath)

  try {
    const content = stripMarkdownCodeFence(await readFile(filePath, 'utf-8'))
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

function collectMethodResponses(
  operation: OpenApiOperation | undefined,
  platformSpec: OpenApiSpec,
  consoleSpec: OpenApiSpec,
  version: ReferenceVersion,
): ApiReferenceResponse[] {
  if (!operation?.responses) return []

  return Object.entries(operation.responses)
    .map(([code, response]) => {
      const responseObj = response as {
        content?: Record<
          string,
          { schema?: { $ref?: string; oneOf?: Array<{ $ref?: string }> } }
        >
      }
      const content = responseObj.content?.['application/json']
      const modelRefs =
        Number(code) === 204
          ? []
          : resolveResponseModels(content?.schema, platformSpec)
      const models = modelRefs.map((model) =>
        buildInlineResponseModel(model.id, model.name, consoleSpec, version),
      )

      return {
        code: Number(code),
        contentType: responseObj.content
          ? Object.keys(responseObj.content)[0]
          : undefined,
        models,
      }
    })
    .sort((a, b) => a.code - b.code)
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

  const [spec, consoleSpec] = await Promise.all([
    loadReferenceOpenApiSpec(version, platform),
    loadReferenceConsoleSpec(version),
  ])
  const mode = getSpecMode(platform) as ApiSpecPlatform
  const useConsoleService = isConsoleOnlyDatabaseApiService(serviceId)
  const serviceSpec = useConsoleService ? consoleSpec : spec
  const serviceMode: ApiSpecPlatform = useConsoleService ? 'console' : mode
  const parsed = parseOpenApiSpec(serviceSpec, serviceMode)
  const service = parsed.services.find((item) => item.id === serviceId)

  if (!service) {
    return {
      id: serviceId,
      label: SERVICE_LABELS[serviceId as ReferenceService] ?? getServiceLabel(serviceId),
      description: getServiceDescriptionFromSpec(serviceSpec, serviceId),
      methods: [],
    }
  }

  const demos = await loadMethodDemos(version, platform, service.methods)

  const methods: ApiReferenceMethod[] = service.methods.map((method) => {
    const rawOperation = getRawOperationForMethod(serviceSpec, method)
    return {
      ...method,
      demo: demos.get(method.id),
      responses: collectMethodResponses(
        rawOperation,
        serviceSpec,
        consoleSpec,
        version,
      ),
    }
  })

  return {
    id: serviceId,
    label: SERVICE_LABELS[serviceId as ReferenceService] ?? service.label,
    description: service.description ?? '',
    methods,
  }
}
