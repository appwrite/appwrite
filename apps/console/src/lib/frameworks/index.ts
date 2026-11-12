/**
 * Framework-related configuration: adapter copy (SSR/Static), icons, and helpers.
 *
 * Single place for all framework config used by sites build settings,
 * FrameworkIcon, and any other consumers.
 */

export {
  FRAMEWORK_CONFIGS,
  normalizeFrameworkKey,
  getAdapterCopy,
  getAdapterDescriptionSegments,
  getFrameworkConfig,
} from './config'

export type {
  AdapterOptionCopy,
  FrameworkAdapterCopy,
  FrameworkConfig,
} from './config'

export { FRAMEWORK_ICON_MAP, getFrameworkIconFile } from './icons'

export {
  resolveFrameworkAdapter,
  getFrameworkAdapterBuildFields,
  getFrameworkAdapterDefaults,
  getFrameworkCreateDefaults,
  frameworkHasSsrAdapter,
  frameworkHasStaticAdapter,
} from './adapter-defaults'

export type { FrameworkAdapterBuildFields } from './adapter-defaults'
