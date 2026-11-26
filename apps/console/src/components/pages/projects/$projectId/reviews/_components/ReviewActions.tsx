import type { ReactNode } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  GitCommitHorizontal,
  Link2,
  Square,
} from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { RowActionsDropdown, RowContextMenu, type RowAction } from './RowMenus'
import type { Review } from '@/lib/appwrite/reviews'
import { fetchReview, useReviewInstallation } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  getKnownVcsProvider,
  getProviderReviewUrls,
  type ProviderReviewUrls,
} from '@/lib/vcs/providers'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'

/**
 * Where a review lives on its provider, read from the installation its
 * repository is connected through, with the provider's name for "Open on"
 * labels. Null until the installation is loaded, or where no link can be
 * built.
 */
export function useReviewUrls(
  projectId: string,
  review: Pick<
    Review,
    | 'repositoryId'
    | 'providerRepositoryOwner'
    | 'providerRepositoryName'
    | 'providerPullRequestId'
    | 'providerReviewId'
  > | null,
): { urls: ProviderReviewUrls; label: string } | null {
  const installation = useReviewInstallation(projectId, review?.repositoryId)
  const meta = getKnownVcsProvider(installation?.provider)
  const urls = getProviderReviewUrls({
    provider: installation?.provider,
    organizationUrl: (installation as { organizationUrl?: string } | null)
      ?.organizationUrl,
    organization: review?.providerRepositoryOwner,
    repositoryName: review?.providerRepositoryName,
    pullRequestId: review?.providerPullRequestId,
    reviewId: review?.providerReviewId,
  })
  return urls && meta ? { urls, label: meta.label } : null
}

/**
 * What a review's menus offer, top to bottom: the copy submenu, then links.
 * Reviews are written by the review service, so there is nothing to change or
 * delete here.
 */
function useReviewActions(projectId: string, review: Review) {
  const t = useT()
  const href = buildConsoleUrl(
    `/projects/${projectId}/reviews/${encodeURIComponent(review.$id)}`,
  )
  const links = useReviewUrls(projectId, review)

  const copy: RowAction[] = [
    {
      icon: Copy,
      label: t('Copy ID'),
      onSelect: () => void copyToClipboard('ID', review.$id),
    },
    {
      icon: Link2,
      label: t('Copy link'),
      onSelect: () => void copyToClipboard('Link', href),
    },
    ...(review.providerCommitHash
      ? [
          {
            icon: GitCommitHorizontal,
            label: t('Copy commit hash'),
            onSelect: () =>
              void copyToClipboard('Commit hash', review.providerCommitHash),
          },
        ]
      : []),
    {
      icon: FileJson,
      label: t('Copy as JSON'),
      onSelect: () =>
        void copyResourceAsJson(() => fetchReview(projectId, review.$id), {
          fallback: review,
        }),
    },
  ]

  const opens: RowAction[] = [
    ...(links
      ? [
          {
            icon: ExternalLink,
            label: t('Open on {provider}').replace('{provider}', links.label),
            onSelect: () => openInNewTab(links.urls.review),
          },
        ]
      : []),
    {
      icon: ExternalLink,
      label: t('Open in new tab'),
      onSelect: () => openInNewTab(href),
    },
    {
      icon: Square,
      label: t('Open in new window'),
      onSelect: () => openInNewWindow(href),
    },
  ]

  return { copy, links: opens }
}

/** Right-click menu of a review row. */
export function ReviewContextMenu({
  projectId,
  review,
  children,
}: {
  projectId: string
  review: Review
  children: ReactNode
}) {
  const actions = useReviewActions(projectId, review)
  return <RowContextMenu actions={actions}>{children}</RowContextMenu>
}

/** The ⋯ menu of a review, on its row and on its page; same items as the right-click menu. */
export function ReviewActionsMenu({
  projectId,
  review,
  trigger,
}: {
  projectId: string
  review: Review
  /** Replaces the default ⋯ row trigger. */
  trigger?: ReactNode
}) {
  const t = useT()
  const actions = useReviewActions(projectId, review)
  return (
    <RowActionsDropdown
      actions={actions}
      trigger={
        trigger ?? (
          <RowActionsMenuTrigger
            aria-label={`${t('Actions for')} ${review.providerRepositoryOwner}/${review.providerRepositoryName} #${review.providerPullRequestId}`}
          />
        )
      }
    />
  )
}
