import { GitBranch, GitCommitHorizontal, Tag } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type {
  DistributionBuild,
  DistributionBuildReferenceType,
} from '@/lib/appwrite/distribution'
import { buildSource, shortCommit } from '@/lib/distribution/builds'
import { cn } from '@/lib/utils'

const SOURCE_ICONS: Record<DistributionBuildReferenceType, LucideIcon> = {
  branch: GitBranch,
  tag: Tag,
  commit: GitCommitHorizontal,
}

export function BuildSource({
  build,
  linked = false,
  className,
}: {
  build: DistributionBuild
  linked?: boolean
  className?: string
}) {
  const source = buildSource(build)
  if (!source) {
    return <span className="text-[12px] text-muted-foreground">-</span>
  }
  const Icon = SOURCE_ICONS[source.type]
  const commit =
    source.type !== 'commit' && build.providerCommitHash
      ? shortCommit(build.providerCommitHash)
      : ''
  const name =
    linked && source.url ? (
      <a
        href={source.url}
        target="_blank"
        rel="noopener noreferrer"
        className="truncate hover:underline"
      >
        {source.name}
      </a>
    ) : (
      <span className="truncate">{source.name}</span>
    )
  const hash =
    commit && linked && build.providerCommitUrl ? (
      <a
        href={build.providerCommitUrl}
        target="_blank"
        rel="noopener noreferrer"
        className="shrink-0 text-muted-foreground hover:underline"
      >
        {commit}
      </a>
    ) : commit ? (
      <span className="shrink-0 text-muted-foreground">{commit}</span>
    ) : null
  return (
    <span
      dir="ltr"
      className={cn(
        'flex min-w-0 items-center gap-1.5 font-mono text-[12px] text-foreground',
        className,
      )}
    >
      <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      {name}
      {hash}
    </span>
  )
}
