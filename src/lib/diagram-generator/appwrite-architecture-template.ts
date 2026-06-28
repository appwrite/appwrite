import { DEFAULT_DIAGRAM_ICON } from '@/lib/diagram-generator/constants'
import {
  createDiagramEdge,
  createDiagramNode,
  snapDiagramValue,
} from '@/lib/diagram-generator/diagram-factory'
import type { DiagramDocument, DiagramEdge, DiagramNode } from '@/lib/diagram-generator/types'
import { DEFAULT_COVER_THEME_ID } from '@/lib/cover-generator/themes'
import { formatCoverLucideIconValue } from '@/lib/cover-generator/lucide-icon-utils'

const CANVAS_WIDTH = 1920
const CANVAS_HEIGHT = 2048

/** Main stack stays left of this x; API module labels live in the right column. */
const MODULES_COLUMN_X = 1664
const MODULE_LABEL_WIDTH = 128

type EdgePreset = 'request' | 'response' | 'event' | 'data' | 'stream' | 'link'

const EDGE_PRESETS: Record<
  EdgePreset,
  Pick<DiagramEdge, 'lineStyle' | 'arrow' | 'strokeTone'>
> = {
  request: { lineStyle: 'solid', arrow: 'forward', strokeTone: 'default' },
  response: { lineStyle: 'dashed', arrow: 'forward', strokeTone: 'muted' },
  event: { lineStyle: 'dashed', arrow: 'forward', strokeTone: 'muted' },
  data: { lineStyle: 'dotted', arrow: 'forward', strokeTone: 'purple' },
  stream: { lineStyle: 'solid', arrow: 'both', strokeTone: 'accent' },
  link: { lineStyle: 'dotted', arrow: 'none', strokeTone: 'muted' },
}

function edge(
  fromNode: DiagramNode,
  toNode: DiagramNode,
  preset: EdgePreset,
  overrides?: Partial<
    Pick<
      DiagramEdge,
      'label' | 'fromSide' | 'toSide' | 'lineStyle' | 'arrow' | 'strokeTone'
    >
  >,
): DiagramEdge {
  return createDiagramEdge(fromNode, toNode, {
    ...EDGE_PRESETS[preset],
    ...overrides,
  })
}

function labelNode(
  x: number,
  y: number,
  text: string,
  width = 128,
  height = 36,
): DiagramNode {
  return createDiagramNode('label', { x, y }, { label: text, width, height })
}

function sectionLabel(x: number, y: number, text: string, width: number): DiagramNode {
  return createDiagramNode('label', { x, y }, { label: text, width, height: 28 })
}

function serviceNode(
  x: number,
  y: number,
  text: string,
  options?: {
    subtitle?: string
    icon?: string | typeof DEFAULT_DIAGRAM_ICON
    width?: number
    height?: number
  },
): DiagramNode {
  let iconSrc: string | undefined
  if (options?.icon === DEFAULT_DIAGRAM_ICON) {
    iconSrc = DEFAULT_DIAGRAM_ICON
  } else if (options?.icon) {
    iconSrc = formatCoverLucideIconValue(options.icon)
  }

  return createDiagramNode('service', { x, y }, {
    label: text,
    subtitle: options?.subtitle,
    width: options?.width ?? 176,
    height: options?.height ?? 64,
    ...(iconSrc ? { iconSrc } : {}),
  })
}

function groupNode(
  x: number,
  y: number,
  text: string,
  width: number,
  height: number,
): DiagramNode {
  return createDiagramNode('group', { x, y }, { label: text, width, height })
}

function centerUnderX(node: DiagramNode, childWidth: number): number {
  return snapDiagramValue(node.x + (node.width - childWidth) / 2)
}

function workerColumn(
  anchor: DiagramNode,
  startY: number,
  labels: string[],
  width = 120,
  rowGap = 44,
): DiagramNode[] {
  const x = centerUnderX(anchor, width)
  return labels.map((label, index) =>
    labelNode(x, startY + index * rowGap, label, width, 36),
  )
}

function connectVerticalFan(
  fromNode: DiagramNode,
  targets: DiagramNode[],
  preset: EdgePreset,
): DiagramEdge[] {
  return targets.map((target) =>
    edge(fromNode, target, preset, { fromSide: 'bottom', toSide: 'top' }),
  )
}

function connectApiModules(
  api: DiagramNode,
  modules: DiagramNode[],
  preset: EdgePreset = 'link',
): DiagramEdge[] {
  return modules.map((module) =>
    edge(api, module, preset, { fromSide: 'right', toSide: 'left' }),
  )
}

export function createAppwriteArchitectureDiagram(): DiagramDocument {
  const clientY = 96
  const clientWidth = 120
  const clientGap = 20
  const clientCount = 5
  const clientRowWidth = clientCount * clientWidth + (clientCount - 1) * clientGap
  const clientStartX = snapDiagramValue((CANVAS_WIDTH - clientRowWidth) / 2)

  const clientsLabel = sectionLabel(clientStartX, 56, 'Clients', clientRowWidth)

  const web = labelNode(clientStartX, clientY, 'Web', clientWidth)
  const flutter = labelNode(
    clientStartX + (clientWidth + clientGap) * 1,
    clientY,
    'Flutter',
    clientWidth,
  )
  const ios = labelNode(
    clientStartX + (clientWidth + clientGap) * 2,
    clientY,
    'iOS',
    clientWidth,
  )
  const android = labelNode(
    clientStartX + (clientWidth + clientGap) * 3,
    clientY,
    'Android',
    clientWidth,
  )
  const servers = labelNode(
    clientStartX + (clientWidth + clientGap) * 4,
    clientY,
    'Servers',
    clientWidth,
  )

  const appwriteY = 192
  const appwrite = serviceNode(
    snapDiagramValue(CANVAS_WIDTH / 2 - 104),
    appwriteY,
    'Appwrite',
    { icon: DEFAULT_DIAGRAM_ICON, width: 208, height: 76 },
  )

  const entryY = 320
  const loadbalancer = serviceNode(
    snapDiagramValue(CANVAS_WIDTH / 2 - 88),
    entryY,
    'Loadbalancer',
    { icon: 'network', width: 176, height: 64 },
  )
  const sslGateway = serviceNode(
    snapDiagramValue(loadbalancer.x + loadbalancer.width + 64),
    entryY,
    'SSL Gateway',
    { icon: 'shield-check', width: 176, height: 64 },
  )

  const apiY = 448
  const apiWidth = 168
  const apiGap = 28
  const apiRowWidth = 4 * apiWidth + 3 * apiGap
  const apiStartX = snapDiagramValue((MODULES_COLUMN_X - 48 - apiRowWidth) / 2)

  const console = serviceNode(apiStartX, apiY, 'Console', {
    icon: 'layout-dashboard',
    width: apiWidth,
  })
  const graphql = serviceNode(
    apiStartX + (apiWidth + apiGap) * 1,
    apiY,
    'GraphQL API',
    { subtitle: 'Coming soon', icon: 'braces', width: apiWidth },
  )
  const restApi = serviceNode(
    apiStartX + (apiWidth + apiGap) * 2,
    apiY,
    'REST API',
    { icon: 'globe', width: apiWidth },
  )
  const realtimeApi = serviceNode(
    apiStartX + (apiWidth + apiGap) * 3,
    apiY,
    'Realtime API',
    { icon: 'radio', width: apiWidth },
  )

  const apiBottom = apiY + 64
  const securityY = apiBottom + 64
  const securityLayer = groupNode(
    snapDiagramValue(apiStartX - 32),
    securityY,
    'Security Layer',
    apiRowWidth + 64,
    80,
  )

  const coreY = securityY + 80 + 64
  const coreGap = 40
  const coreWidth = 176
  const coreRowWidth = 4 * coreWidth + 3 * coreGap
  const coreStartX = snapDiagramValue((MODULES_COLUMN_X - 48 - coreRowWidth) / 2)

  const executor = serviceNode(coreStartX, coreY, 'Executor', {
    subtitle: 'Open Runtimes',
    icon: 'cpu',
    width: coreWidth,
    height: 72,
  })
  const cache = serviceNode(
    coreStartX + (coreWidth + coreGap) * 1,
    coreY,
    'Cache',
    { subtitle: 'Redis', icon: 'database-backup', width: coreWidth, height: 72 },
  )
  const queue = serviceNode(
    coreStartX + (coreWidth + coreGap) * 2,
    coreY,
    'Queue',
    { subtitle: 'Redis', icon: 'list-ordered', width: coreWidth, height: 72 },
  )
  const antivirus = serviceNode(
    coreStartX + (coreWidth + coreGap) * 3,
    coreY,
    'AntiVirus',
    { subtitle: 'ClamAV', icon: 'shield', width: coreWidth, height: 72 },
  )

  const infraY = coreY + 72 + 72
  const dockerK8s = serviceNode(
    centerUnderX(executor, 168),
    infraY,
    'Docker / K8S',
    { icon: 'container', width: 168, height: 64 },
  )
  const database = serviceNode(
    centerUnderX(cache, 168),
    infraY,
    'Database',
    { icon: 'database', width: 168, height: 64 },
  )
  const queueInfra = serviceNode(
    centerUnderX(queue, 168),
    infraY,
    'Redis',
    { subtitle: 'Queue store', icon: 'database-backup', width: 168, height: 64 },
  )

  const modulesLabelY = apiY - 36
  const apiServicesLabel = sectionLabel(
    MODULES_COLUMN_X,
    modulesLabelY,
    'API modules',
    MODULE_LABEL_WIDTH,
  )

  const moduleRowHeight = 40
  const moduleGap = 10
  const modulesStartY = apiY

  const serviceFunctions = labelNode(
    MODULES_COLUMN_X,
    modulesStartY,
    'Functions',
    MODULE_LABEL_WIDTH,
  )
  const serviceUsers = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 1,
    'Users',
    MODULE_LABEL_WIDTH,
  )
  const serviceAccount = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 2,
    'Account',
    MODULE_LABEL_WIDTH,
  )
  const serviceTeams = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 3,
    'Teams',
    MODULE_LABEL_WIDTH,
  )
  const serviceDatabase = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 4,
    'Database',
    MODULE_LABEL_WIDTH,
  )
  const serviceStorage = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 5,
    'Storage',
    MODULE_LABEL_WIDTH,
  )
  const serviceLocalization = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 6,
    'Localization',
    MODULE_LABEL_WIDTH,
  )
  const serviceAvatars = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 7,
    'Avatars',
    MODULE_LABEL_WIDTH,
  )
  const serviceHealth = labelNode(
    MODULES_COLUMN_X,
    modulesStartY + (moduleRowHeight + moduleGap) * 8,
    'Health',
    MODULE_LABEL_WIDTH,
  )

  const restApiModules = [
    serviceFunctions,
    serviceUsers,
    serviceAccount,
    serviceTeams,
    serviceDatabase,
    serviceStorage,
    serviceLocalization,
    serviceAvatars,
    serviceHealth,
  ]

  const workerSectionY = infraY + 64 + 72
  const workersLabel = sectionLabel(
    snapDiagramValue(coreStartX),
    workerSectionY,
    'Background workers',
    coreRowWidth,
  )

  const workerStartY = workerSectionY + 48
  const workerRowGap = 44

  const executionWorkers = workerColumn(
    dockerK8s,
    workerStartY,
    ['Builds', 'Functions', 'Maintenance'],
    120,
    workerRowGap,
  )
  const [builds, workerFunctions, maintenance] = executionWorkers

  const queueWorkers = workerColumn(
    queueInfra,
    workerStartY,
    ['Scheduler', 'Webhooks', 'Deletes'],
    120,
    workerRowGap,
  )
  const [scheduler, webhooks, deletes] = queueWorkers

  const dataWorkers = workerColumn(
    database,
    workerStartY,
    ['Usage', 'Database', 'Audits'],
    120,
    workerRowGap,
  )
  const [usage, workerDatabase, audits] = dataWorkers

  const integrationWorkers = workerColumn(
    antivirus,
    workerStartY,
    ['Mails', 'Certs'],
    120,
    workerRowGap,
  )
  const [mails, certs] = integrationWorkers

  const integrationInfraY =
    workerStartY + (workerRowGap * 2) + 36 + 48
  const smtp = serviceNode(
    centerUnderX(mails, 136),
    integrationInfraY,
    'SMTP',
    { icon: 'mail', width: 136, height: 56 },
  )
  const letsencrypt = serviceNode(
    centerUnderX(certs, 136),
    integrationInfraY,
    'Letsencrypt',
    { icon: 'badge-check', width: 136, height: 56 },
  )

  const clientNodes = [web, flutter, ios, android, servers]
  const apiNodes = [console, graphql, restApi, realtimeApi]
  const coreNodes = [executor, cache, queue, antivirus]

  const edges: DiagramEdge[] = [
    ...clientNodes.map((client) =>
      edge(client, appwrite, 'request', { fromSide: 'bottom', toSide: 'top' }),
    ),

    edge(appwrite, loadbalancer, 'request', { fromSide: 'bottom', toSide: 'top' }),
    edge(sslGateway, loadbalancer, 'request', {
      fromSide: 'left',
      toSide: 'right',
      label: 'HTTPS',
    }),

    ...connectVerticalFan(loadbalancer, apiNodes, 'request'),

    ...apiNodes.map((api) =>
      edge(api, securityLayer, 'request', { fromSide: 'bottom', toSide: 'top' }),
    ),

    ...connectVerticalFan(securityLayer, coreNodes, 'request'),

    edge(executor, dockerK8s, 'data', { fromSide: 'bottom', toSide: 'top' }),
    edge(queue, queueInfra, 'data', { fromSide: 'bottom', toSide: 'top' }),

    edge(queue, scheduler, 'event', { fromSide: 'bottom', toSide: 'top' }),
    edge(builds, dockerK8s, 'event', { fromSide: 'top', toSide: 'bottom' }),
    edge(workerFunctions, executor, 'event', { fromSide: 'top', toSide: 'bottom' }),
    edge(maintenance, cache, 'request', { fromSide: 'top', toSide: 'bottom' }),

    edge(workerDatabase, database, 'data', { fromSide: 'top', toSide: 'bottom' }),
    edge(usage, database, 'data', { fromSide: 'top', toSide: 'bottom' }),
    edge(audits, database, 'data', { fromSide: 'top', toSide: 'bottom' }),

    edge(webhooks, queue, 'event', { fromSide: 'top', toSide: 'bottom' }),
    edge(deletes, queue, 'event', { fromSide: 'top', toSide: 'bottom' }),

    edge(mails, smtp, 'request', { fromSide: 'bottom', toSide: 'top' }),
    edge(certs, letsencrypt, 'request', { fromSide: 'bottom', toSide: 'top' }),

    ...connectApiModules(restApi, restApiModules, 'link'),
    ...connectApiModules(
      realtimeApi,
      [serviceFunctions, serviceDatabase, serviceStorage],
      'event',
    ),
  ]

  const nodes: DiagramNode[] = [
    clientsLabel,
    ...clientNodes,
    appwrite,
    loadbalancer,
    sslGateway,
    ...apiNodes,
    securityLayer,
    ...coreNodes,
    dockerK8s,
    database,
    queueInfra,
    apiServicesLabel,
    ...restApiModules,
    workersLabel,
    ...executionWorkers,
    ...queueWorkers,
    ...dataWorkers,
    ...integrationWorkers,
    smtp,
    letsencrypt,
  ]

  return {
    title: 'Appwrite architecture',
    theme: DEFAULT_COVER_THEME_ID,
    width: CANVAS_WIDTH,
    height: CANVAS_HEIGHT,
    format: 'png',
    nodes,
    edges,
  }
}
