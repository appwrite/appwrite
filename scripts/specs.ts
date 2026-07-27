/**
 * Generates per-database API spec markdown files in specs/ from @appwrite.io/console SDK types and OpenAPI specs.
 *
 * Run: bun run specs
 */
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const OUTPUT_DIR = join(ROOT, 'specs')

const CONSOLE_PKG = dirname(
  createRequire(import.meta.url).resolve('@appwrite.io/console/package.json'),
)
const SPECS_PKG = dirname(
  createRequire(import.meta.url).resolve('@appwrite.io/specs/package.json'),
)

type ServiceConfig = {
  id: string
  title: string
  sdkAccessor: string
  basePath: string
  className: string
  dtsFile: string
  openApiTag?: string
  /**
   * Where the service is mounted on the console SDK wrapper.
   * - project: sdk.forProject(projectId).…
   * - console: sdk.forConsole.…
   * - both: available on console and project clients
   */
  sdkScope?: 'project' | 'console' | 'both'
}

const SERVICES: ServiceConfig[] = [
  {
    id: 'apps',
    title: 'Apps',
    sdkAccessor: 'apps',
    basePath: '/v1/apps',
    className: 'Apps',
    dtsFile: 'apps.d.ts',
    sdkScope: 'both',
  },
  {
    id: 'postgresql',
    title: 'PostgreSQL',
    sdkAccessor: 'postgresql',
    basePath: '/v1/postgresql',
    className: 'Postgresql',
    dtsFile: 'postgresql.d.ts',
  },
  {
    id: 'mysql',
    title: 'MySQL',
    sdkAccessor: 'mysql',
    basePath: '/v1/mysql',
    className: 'Mysql',
    dtsFile: 'mysql.d.ts',
  },
  {
    id: 'tablesdb',
    title: 'TablesDB',
    sdkAccessor: 'tablesDB',
    basePath: '/v1/tablesdb',
    className: 'TablesDB',
    dtsFile: 'tables-db.d.ts',
    openApiTag: 'tablesDB',
  },
  {
    id: 'documentsdb',
    title: 'DocumentsDB',
    sdkAccessor: 'documentsDB',
    basePath: '/v1/documentsdb',
    className: 'DocumentsDB',
    dtsFile: 'documents-db.d.ts',
    openApiTag: 'documentsDB',
  },
  {
    id: 'vectorsdb',
    title: 'VectorsDB',
    sdkAccessor: 'vectorsDB',
    basePath: '/v1/vectorsdb',
    className: 'VectorsDB',
    dtsFile: 'vectors-db.d.ts',
    openApiTag: 'vectorsDB',
  },
]

type SdkMethod = {
  name: string
  description: string
  parameters: { name: string; type: string; required: boolean; description: string }[]
  returnType: string
}

type HttpEndpoint = {
  method: string
  path: string
  sdkMethod: string
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

function parseSdkMethods(dtsContent: string, className: string): Map<string, SdkMethod> {
  const methods = new Map<string, SdkMethod>()
  const classStart = dtsContent.indexOf(`export declare class ${className}`)
  if (classStart === -1) return methods

  const classBody = dtsContent.slice(classStart)
  const blocks = classBody.split(/\n\s*\/\*\*/).slice(1)

  for (const block of blocks) {
    const end = block.indexOf('*/')
    if (end === -1) continue
    const jsdoc = block.slice(0, end)
    const after = block.slice(end + 2)

    if (jsdoc.includes('@deprecated')) continue

    const nameMatch =
      after.match(/\n\s*(\w+)\(params(?:\?)?:/) ??
      after.match(/\n\s*(\w+)\(\):/)
    if (!nameMatch) continue
    const name = nameMatch[1]

    const description = jsdoc
      .split('\n')
      .map((line) => line.replace(/^\s*\*\s?/, '').trim())
      .filter((line) => line && !line.startsWith('@'))
      .join(' ')
      .trim()

    const parameters: SdkMethod['parameters'] = []
    for (const match of jsdoc.matchAll(/@param\s+\{([^}]+)\}\s+params\.(\w+)\s+-\s+(.+)/g)) {
      parameters.push({
        name: match[2],
        type: match[1].trim(),
        required: true,
        description: match[3].trim(),
      })
    }
    // Optional params use same pattern; detect optional from signature
    const sigMatch = after.match(
      new RegExp(`${name}\\(params\\??:\\s*\\{([^}]+(?:\\{[^}]*\\}[^}]*)*)\\}`),
    )
    const sigBody = sigMatch?.[1] ?? ''
    for (const param of parameters) {
      const optional = new RegExp(`${param.name}\\?:`).test(sigBody)
      if (optional) param.required = false
    }

    const returnsMatch = jsdoc.match(/@returns\s+\{((?:[^{}]|\{[^{}]*\})*)\}/)
    const returnType = returnsMatch?.[1]?.trim() ?? 'unknown'

    methods.set(name, { name, description, parameters, returnType })
  }

  return methods
}

function parseSdkBundlePaths(
  bundleContent: string,
  className: string,
): Map<string, HttpEndpoint[]> {
  const endpoints = new Map<string, HttpEndpoint[]>()
  const classStart = bundleContent.indexOf(`class ${className}`)
  if (classStart === -1) return endpoints

  const nextClass = bundleContent.slice(classStart + 1).search(/\nclass \w+/)
  const classBody =
    nextClass === -1
      ? bundleContent.slice(classStart)
      : bundleContent.slice(classStart, classStart + 1 + nextClass)

  const methodBlocks = classBody.split(/\n    (?:async )?(\w+)\(/).slice(1)
  for (let i = 0; i < methodBlocks.length; i += 2) {
    const methodName = methodBlocks[i]
    if (methodName === 'constructor') continue
    const body = methodBlocks[i + 1] ?? ''
    const pathMatch = body.match(/const apiPath = '([^']+)'/)
    if (!pathMatch) continue

    const httpMatch = body.match(/\.call\('(\w+)'/)
    const httpMethod = (httpMatch?.[1] ?? 'get').toUpperCase()

    const entry: HttpEndpoint = {
      method: httpMethod,
      path: `/v1${pathMatch[1]}`,
      sdkMethod: methodName,
    }

    const existing = endpoints.get(methodName) ?? []
    existing.push(entry)
    endpoints.set(methodName, existing)
  }

  return endpoints
}

type OpenApiSpec = {
  paths: Record<
    string,
    Record<string, { operationId?: string; summary?: string; tags?: string[]; 'x-appwrite'?: { method?: string } }>
  >
}

function parseOpenApiEndpoints(spec: OpenApiSpec, tag: string): Map<string, HttpEndpoint[]> {
  const endpoints = new Map<string, HttpEndpoint[]>()

  for (const [path, methods] of Object.entries(spec.paths ?? {})) {
    for (const [method, operation] of Object.entries(methods)) {
      if (!operation.tags?.includes(tag)) continue
      const sdkMethod = operation['x-appwrite']?.method
      if (!sdkMethod) continue

      const entry: HttpEndpoint = {
        method: method.toUpperCase(),
        path: `/v1${path}`,
        sdkMethod,
      }

      const existing = endpoints.get(sdkMethod) ?? []
      existing.push(entry)
      endpoints.set(sdkMethod, existing)
    }
  }

  return endpoints
}

function groupEndpointsByResource(endpoints: HttpEndpoint[]): Map<string, HttpEndpoint[]> {
  const groups = new Map<string, HttpEndpoint[]>()

  for (const endpoint of endpoints) {
    const parts = endpoint.path.split('/').filter(Boolean)
    // /v1/service/... -> use from index 2 onward
    const resourceParts = parts.slice(2)
    const staticPart = resourceParts.find((p) => !p.startsWith('{'))
    const key =
      resourceParts.length === 0
        ? 'root'
        : (staticPart ?? resourceParts[resourceParts.length - 1].replace(/[{}]/g, ''))

    const existing = groups.get(key) ?? []
    existing.push(endpoint)
    groups.set(key, existing)
  }

  return groups
}

function resourceTitle(key: string, service: ServiceConfig): string {
  if (key === 'root') return service.title
  return key
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function sdkCallPrefixes(service: ServiceConfig): string[] {
  const scope = service.sdkScope ?? 'project'
  if (scope === 'console') return [`sdk.forConsole.${service.sdkAccessor}`]
  if (scope === 'both') {
    return [
      `sdk.forConsole.${service.sdkAccessor}`,
      `sdk.forProject(projectId).${service.sdkAccessor}`,
    ]
  }
  return [`sdk.forProject(projectId).${service.sdkAccessor}`]
}

function renderSdkAccessorLine(service: ServiceConfig): string {
  const scope = service.sdkScope ?? 'project'
  if (scope === 'console') {
    return `SDK accessor: \`sdk.forConsole.${service.sdkAccessor}\``
  }
  if (scope === 'both') {
    return `SDK accessors: \`sdk.forConsole.${service.sdkAccessor}\` (organization / console) and \`sdk.forProject(projectId).${service.sdkAccessor}\` (project OAuth2 server apps)`
  }
  return `SDK accessor: \`sdk.forProject(projectId).${service.sdkAccessor}\``
}

function renderAuthNote(service: ServiceConfig): string {
  const scope = service.sdkScope ?? 'project'
  if (scope === 'console') {
    return 'All paths are relative to the console API endpoint (`{endpoint}/v1/...`). Authenticated console requests require a session cookie or `X-Appwrite-Key`.'
  }
  if (scope === 'both') {
    return 'All paths are relative to the API endpoint (`{endpoint}/v1/...`). Console-scoped calls use the console client session; project-scoped calls require `X-Appwrite-Project` and a session or API key.'
  }
  return 'All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.'
}

function renderMethodSection(
  service: ServiceConfig,
  method: SdkMethod,
  endpoints: HttpEndpoint[],
): string {
  const primary = endpoints[0]
  const anchor = `${service.id}-${slugify(method.name)}`
  const lines: string[] = []
  const prefixes = sdkCallPrefixes(service)

  lines.push(`<a id="${anchor}"></a>`)
  lines.push('')
  lines.push(`#### \`${method.name}\``)
  lines.push('')
  if (method.description) {
    lines.push(method.description)
    lines.push('')
  }

  if (primary) {
    lines.push(`- **HTTP:** \`${primary.method}\``)
    lines.push(`- **Path:** \`${primary.path}\``)
  }
  lines.push(`- **Returns:** \`${method.returnType}\``)
  lines.push('')

  lines.push('**Parameters**')
  lines.push('')
  if (method.parameters.length === 0) {
    lines.push('_No request parameters._')
  } else {
    lines.push('| Parameter | Type | Required | Description |')
    lines.push('| --- | --- | --- | --- |')
    for (const param of method.parameters) {
      lines.push(
        `| \`${param.name}\` | \`${param.type}\` | ${param.required ? 'Yes' : 'No'} | ${param.description} |`,
      )
    }
  }
  lines.push('')

  lines.push('**SDK signature**')
  lines.push('')
  lines.push('```typescript')
  for (let i = 0; i < prefixes.length; i++) {
    if (i > 0) lines.push('')
    if (method.parameters.length === 0) {
      lines.push(`${prefixes[i]}.${method.name}()`)
    } else {
      const fields = method.parameters
        .map((p) => `  ${p.name}${p.required ? '' : '?'}: ${p.type}`)
        .join(';\n')
      lines.push(`${prefixes[i]}.${method.name}({`)
      lines.push(fields + (fields ? ';' : ''))
      lines.push('})')
    }
  }
  lines.push('```')
  lines.push('')

  return lines.join('\n')
}

function renderServiceFile(
  service: ServiceConfig,
  methods: Map<string, SdkMethod>,
  httpByMethod: Map<string, HttpEndpoint[]>,
  consoleVersion: string,
): string {
  const lines: string[] = []
  const anchor = `${service.id}service`

  lines.push(`# ${service.title} API specifications`)
  lines.push('')
  lines.push(
    `Reference extracted from \`@appwrite.io/console\` v${consoleVersion} and \`@appwrite.io/specs\` (latest console OpenAPI).`,
  )
  lines.push('')
  lines.push(renderAuthNote(service))
  lines.push('')
  lines.push(
    'Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.',
  )
  lines.push('')
  lines.push(`<a id="${anchor}"></a>`)
  lines.push('')
  lines.push(renderSdkAccessorLine(service))
  lines.push('')
  lines.push(`Base path prefix: \`${service.basePath}\``)
  lines.push('')

  const allEndpoints: HttpEndpoint[] = []
  for (const method of methods.keys()) {
    const eps = httpByMethod.get(method) ?? []
    allEndpoints.push(...eps)
  }

  lines.push('| SDK method | HTTP | Path | Returns |')
  lines.push('| --- | --- | --- | --- |')
  for (const [name, method] of [...methods.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
    const eps = httpByMethod.get(name) ?? []
    if (eps.length === 0) {
      lines.push(`| [\`${name}\`](#${service.id}-${slugify(name)}) | - | - | \`${method.returnType}\` |`)
      continue
    }
    for (const ep of eps) {
      lines.push(
        `| [\`${name}\`](#${service.id}-${slugify(name)}) | ${ep.method} | \`${ep.path}\` | \`${method.returnType}\` |`,
      )
    }
  }
  lines.push('')
  lines.push('## Method details')
  lines.push('')

  const grouped = groupEndpointsByResource(allEndpoints)
  const renderedMethods = new Set<string>()

  for (const [resourceKey, resourceEndpoints] of grouped) {
    const uniqueMethods = [...new Set(resourceEndpoints.map((e) => e.sdkMethod))]
    const resourceAnchor = `${service.id}-${slugify(resourceKey)}-resource`
    lines.push(`<a id="${resourceAnchor}"></a>`)
    lines.push('')
    lines.push(`### ${resourceTitle(resourceKey, service)}`)
    lines.push('')
    const samplePath = resourceEndpoints[0]?.path ?? service.basePath
    lines.push(`REST resource: \`${samplePath.split('/').slice(0, 4).join('/')}${resourceKey === 'root' ? '' : '/…'}\``)
    lines.push('')

    for (const methodName of uniqueMethods.sort()) {
      const method = methods.get(methodName)
      if (!method) continue
      renderedMethods.add(methodName)
      lines.push(
        renderMethodSection(
          service,
          method,
          httpByMethod.get(methodName) ?? [],
        ),
      )
    }
  }

  for (const [name, method] of methods) {
    if (renderedMethods.has(name)) continue
    lines.push(renderMethodSection(service, method, httpByMethod.get(name) ?? []))
  }

  return lines.join('\n')
}

async function main() {
  const consoleVersion = JSON.parse(
    await readFile(join(CONSOLE_PKG, 'package.json'), 'utf8'),
  ).version

  const openApi = JSON.parse(
    await readFile(
      join(SPECS_PKG, 'specs/latest/open-api3-latest-console.json'),
      'utf8',
    ),
  ) as OpenApiSpec

  const bundle = await readFile(join(CONSOLE_PKG, 'dist/esm/sdk.js'), 'utf8')

  await mkdir(OUTPUT_DIR, { recursive: true })

  for (const service of SERVICES) {
    const dts = await readFile(
      join(CONSOLE_PKG, 'types/services', service.dtsFile),
      'utf8',
    )
    const methods = parseSdkMethods(dts, service.className)
    const httpByMethod = service.openApiTag
      ? parseOpenApiEndpoints(openApi, service.openApiTag)
      : parseSdkBundlePaths(bundle, service.className)

    const outputPath = join(OUTPUT_DIR, `${service.id}.md`)
    await writeFile(
      outputPath,
      renderServiceFile(service, methods, httpByMethod, consoleVersion),
    )
    console.log(`Wrote ${outputPath}`)
  }
}

await main()
