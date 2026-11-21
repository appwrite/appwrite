/**
 * Predefined filter columns for the code reviews list.
 *
 * Filterable attributes match `reviews.list`: repositoryId, providerPullRequestId,
 * providerPullRequestAuthor, providerCommitHash, trigger, status, verdict and
 * tier (plus $id and $createdAt). Labels match the badges in the reviews table.
 */

import type { FilterColumn } from '../types'

const REVIEW_STATUS_ELEMENTS = [
  { value: 'queued', label: 'Queued' },
  { value: 'processing', label: 'Reviewing' },
  { value: 'completed', label: 'Completed' },
  { value: 'skipped', label: 'Skipped' },
  { value: 'failed', label: 'Failed' },
]

const REVIEW_VERDICT_ELEMENTS = [
  { value: 'approve', label: 'Approved' },
  { value: 'request_changes', label: 'Changes requested' },
  { value: 'comment', label: 'Commented' },
]

const REVIEW_TIER_ELEMENTS = ['S', 'A', 'B', 'C', 'D', 'F'].map((tier) => ({
  value: tier,
  label: tier,
}))

const REVIEW_TRIGGER_ELEMENTS = [
  { value: 'opened', label: 'Pull request opened' },
  { value: 'synchronize', label: 'New commits' },
  { value: 'ready_for_review', label: 'Ready for review' },
  { value: 'retry', label: 'Retry' },
  { value: 'mention', label: 'Requested in a comment' },
]

/**
 * Columns for the reviews list. A review stores its repository by ID, so the
 * repository filter offers the repositories the project reviews by name.
 */
export function reviewsFilterColumns(
  repositories: Array<{ value: string; label: string }> = [],
): FilterColumn[] {
  return [
    ...(repositories.length > 0
      ? [
          {
            id: 'repositoryId',
            title: 'Repository',
            type: 'enum',
            format: 'enum',
            elements: repositories,
            optional: false,
          } satisfies FilterColumn,
        ]
      : []),
    {
      id: 'status',
      title: 'Status',
      type: 'enum',
      format: 'enum',
      elements: REVIEW_STATUS_ELEMENTS,
      optional: false,
    },
    {
      id: 'verdict',
      title: 'Verdict',
      type: 'enum',
      format: 'enum',
      elements: REVIEW_VERDICT_ELEMENTS,
      optional: false,
    },
    {
      id: 'tier',
      title: 'Confidence',
      type: 'enum',
      format: 'enum',
      elements: REVIEW_TIER_ELEMENTS,
      optional: false,
    },
    {
      id: 'trigger',
      title: 'Trigger',
      type: 'enum',
      format: 'enum',
      elements: REVIEW_TRIGGER_ELEMENTS,
      optional: false,
    },
    { id: 'providerPullRequestAuthor', title: 'Author', type: 'string' },
    { id: 'providerPullRequestId', title: 'Pull request', type: 'string' },
    { id: 'providerCommitHash', title: 'Commit', type: 'string' },
    { id: '$id', title: '$id', type: 'string' },
    { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  ]
}
