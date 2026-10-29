/**
 * Container-query breakpoints for backups layout (policies + archives).
 *
 * `BACKUPS_VIEW_CONTAINER` must wrap the grid — container queries cannot style
 * the container element itself, so column classes live on descendants.
 *
 * Two-column layout follows the host width (workspace pane, sidebar layout),
 * not the viewport, so policies and backups stack in narrow containers.
 */

export const BACKUPS_VIEW_CONTAINER = '@container/backups w-full min-w-0'

export const backupsViewGridClass =
  'grid gap-6 @[1024px]/backups:grid-cols-3 @[1024px]/backups:items-stretch'

export const backupsViewPoliciesColumnClass =
  'flex flex-col @[1024px]/backups:col-span-1'

export const backupsViewArchivesColumnClass =
  'flex flex-col @[1024px]/backups:col-span-2'
