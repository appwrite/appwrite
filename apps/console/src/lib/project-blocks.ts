import { BlockMode, BlockResourceType, Status } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'

/** Generic user-facing copy. Never surface API `reason` values in the console. */
export const PROJECT_BLOCKED_CURTAIN = {
  title: 'Project blocked',
  message:
    'This project is temporarily unavailable. Access has been restricted, which may include a Terms of Service violation. For questions about this restriction or to request a review, contact support.',
} as const

export function isBlockActive(block: Models.Block): boolean {
  const expiredAt = block.expiredAt?.trim()
  if (!expiredAt) return true
  const expiresMs = new Date(expiredAt).getTime()
  if (Number.isNaN(expiresMs)) return true
  return expiresMs > Date.now()
}

/**
 * True when the project has an active full block at the project resource level.
 * Readonly blocks do not lock console access.
 *
 * Paused projects also carry a project-level block in `blocks`, but `status`
 * is the source of truth for that state — show the paused UI instead.
 */
export function hasActiveProjectBlock(
  project:
    | { blocks?: Models.Block[] | null; status?: string | null }
    | null
    | undefined,
  projectId: string,
): boolean {
  if (!project?.blocks?.length || !projectId) return false
  if (project.status === 'paused') return false
  return project.blocks.some((block) =>
    isActiveProjectFullBlock(block, projectId),
  )
}

function isActiveProjectFullBlock(
  block: Models.Block,
  projectId: string,
): boolean {
  if (block.resourceType !== BlockResourceType.Projects) return false
  if (block.mode !== BlockMode.Full) return false
  if (!isBlockActive(block)) return false

  const resourceId = block.resourceId?.trim()
  if (resourceId && resourceId !== projectId) return false
  return true
}

/**
 * Optimistic project cache shape after resume. Clears project-level full blocks
 * (including the pause block) so the UI does not flash blocked while refetching.
 */
export function applyProjectResumeToCache(
  project: Models.Project,
  projectId: string,
): Models.Project {
  return {
    ...project,
    status: Status.Active,
    blocks: (project.blocks ?? []).filter(
      (block) => !isActiveProjectFullBlock(block, projectId),
    ),
  }
}
