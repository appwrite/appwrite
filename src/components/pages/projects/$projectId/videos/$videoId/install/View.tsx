import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { Check, Copy, ExternalLink, Info } from 'lucide-react'
import { toast } from 'sonner'
import { VideoPage } from '../../_components/VideoPage'
import { VideoFormatLabel } from '../../_components/VideoOutputBadge'
import { NothingToStreamNotice } from '../../_components/NothingToStreamNotice'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { PackageManagerIcon } from '@/components/global/shared/PackageManagerIcon'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useProjectVideo, useVideoRenditions } from '@/lib/react-query/hooks'
import {
  buildPlayerSetupPrompt,
  getPlayerCodeFiles,
  getPlayerInstallNote,
  getPlayerInstallSteps,
  PLAYER_PACKAGE_MANAGERS,
  PLAYER_PLATFORMS,
  PLAYERS,
  type PlayerFrameworkId,
  type PlayerId,
  type PlayerPackageManagerId,
  type PlayerPlatformId,
} from '@/lib/videos/player-snippets'
import {
  getVideoMasterManifestUrl,
  VIDEO_MASTER_MANIFESTS,
  type VideoMasterManifestKind,
} from '@/lib/videos/urls'
import { useT } from '@/lib/i18n/translate'

const SELECT_LABEL_CLASS =
  'mb-2 block text-[12px] font-medium uppercase tracking-wider text-muted-foreground'

export function View() {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { data: video } = useProjectVideo(projectId, videoId)
  const { data: renditionsData } = useVideoRenditions(projectId, videoId)

  const readyByOutput = useMemo(() => {
    const counts: Record<string, number> = { hls: 0, dash: 0, cmaf: 0 }
    for (const rendition of renditionsData?.renditions ?? []) {
      if (rendition.status === 'ready' && rendition.output in counts) {
        counts[rendition.output] += 1
      }
    }
    return counts
  }, [renditionsData])
  const anyReady = Object.values(readyByOutput).some((count) => count > 0)
  const isFormatReady = useCallback(
    (kind: VideoMasterManifestKind) => {
      const output = VIDEO_MASTER_MANIFESTS.find((m) => m.kind === kind)?.output
      return output ? readyByOutput[output] > 0 : false
    },
    [readyByOutput],
  )

  const [platformId, setPlatformId] = useState<PlayerPlatformId>('web')
  const platform =
    PLAYER_PLATFORMS.find((p) => p.id === platformId) ?? PLAYER_PLATFORMS[0]
  const [playerId, setPlayerId] = useState<PlayerId>(platform.players[0])
  const player = PLAYERS[playerId]
  const [frameworkId, setFrameworkId] = useState<PlayerFrameworkId>(
    player.frameworks[0]?.id ?? 'vanilla',
  )
  const [format, setFormat] = useState<VideoMasterManifestKind>('hls')
  const [packageManagerId, setPackageManagerId] =
    useState<PlayerPackageManagerId>('npm')
  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [copiedPrompt, setCopiedPrompt] = useState(false)

  useEffect(() => {
    if (!platform.players.includes(playerId)) setPlayerId(platform.players[0])
  }, [platform, playerId])

  useEffect(() => {
    if (!player.frameworks.some((fw) => fw.id === frameworkId)) {
      setFrameworkId(player.frameworks[0]?.id ?? 'vanilla')
    }
  }, [player, frameworkId])

  // Prefer a format this player supports that already has ready renditions.
  useEffect(() => {
    setFormat(
      (current) =>
        (player.formats.includes(current) && isFormatReady(current)
          ? current
          : player.formats.find(isFormatReady)) ??
        (player.formats.includes(current) ? current : player.formats[0]),
    )
  }, [player, isFormatReady])

  const url = getVideoMasterManifestUrl(projectId, videoId, format)
  const codeFiles = useMemo(
    () => getPlayerCodeFiles(playerId, frameworkId, url, format),
    [playerId, frameworkId, url, format],
  )
  const installSteps = useMemo(
    () => getPlayerInstallSteps(playerId, format, packageManagerId),
    [playerId, format, packageManagerId],
  )
  const installNote = getPlayerInstallNote(playerId)

  useEffect(() => {
    setSelectedFileIndex(0)
  }, [playerId, frameworkId])
  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]

  if (!video) return null

  const formatLabel =
    VIDEO_MASTER_MANIFESTS.find((m) => m.kind === format)?.label ?? format
  const frameworkLabel = player.frameworks.find(
    (fw) => fw.id === frameworkId,
  )?.label

  const handleCopyPrompt = async () => {
    try {
      await navigator.clipboard.writeText(
        buildPlayerSetupPrompt({
          videoName: video.name,
          platformLabel: platform.label,
          playerLabel: player.label,
          frameworkLabel,
          formatLabel,
          url,
          installNote,
          installSteps,
          codeFiles,
        }),
      )
      setCopiedPrompt(true)
      toast.success(t('Prompt copied to clipboard'))
      setTimeout(() => setCopiedPrompt(false), 2000)
    } catch {
      toast.error(t('Failed to copy prompt'))
    }
  }

  return (
    <VideoPage
      title={t('Install')}
      term="adaptive"
      actions={
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 gap-1.5 text-[13px]"
          onClick={() => void handleCopyPrompt()}
        >
          {copiedPrompt ? (
            <Check className="h-3.5 w-3.5" />
          ) : (
            <Copy className="h-3.5 w-3.5" />
          )}
          {t('Copy prompt')}
        </Button>
      }
    >
      {!anyReady ? <NothingToStreamNotice /> : null}

      <div>
        <div className="flex flex-wrap items-end gap-4 border-b border-border pb-4">
          <SelectField label={t('Platform')} className="min-w-[160px]">
            <Select
              value={platformId}
              onValueChange={(value) => setPlatformId(value as PlayerPlatformId)}
            >
              <SelectTrigger className="h-9 w-full text-[13px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PLAYER_PLATFORMS.map((option) => (
                  <SelectItem
                    key={option.id}
                    value={option.id}
                    className="text-[13px]"
                  >
                    <span className="flex items-center gap-1.5">
                      {option.id === 'flutter' ||
                      option.id === 'react-native' ? (
                        <FrameworkIcon framework={option.id} size="sm" />
                      ) : (
                        <PlatformIcon platform={option.id} size="sm" />
                      )}
                      {option.label}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </SelectField>

          <SelectField label={t('Player')} className="min-w-[160px]">
            <Select
              value={playerId}
              onValueChange={(value) => setPlayerId(value as PlayerId)}
              disabled={platform.players.length === 1}
            >
              <SelectTrigger className="h-9 w-full text-[13px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {platform.players.map((id) => (
                  <SelectItem key={id} value={id} className="text-[13px]">
                    {id === 'native' ? t(PLAYERS[id].label) : PLAYERS[id].label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </SelectField>

          {player.frameworks.length > 0 ? (
            <SelectField label={t('Framework')} className="min-w-[140px]">
              <Select
                value={frameworkId}
                onValueChange={(value) =>
                  setFrameworkId(value as PlayerFrameworkId)
                }
                disabled={player.frameworks.length === 1}
              >
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {player.frameworks.map((fw) => (
                    <SelectItem
                      key={fw.id}
                      value={fw.id}
                      className="text-[13px]"
                    >
                      <span className="flex items-center gap-1.5">
                        {fw.id === 'vanilla' || fw.id === 'react' ? (
                          <FrameworkIcon framework={fw.id} size="sm" />
                        ) : null}
                        {fw.label}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </SelectField>
          ) : null}

          <SelectField label={t('Format')} className="min-w-[160px]">
            <Select
              value={format}
              onValueChange={(value) =>
                setFormat(value as VideoMasterManifestKind)
              }
              disabled={player.formats.length === 1}
            >
              <SelectTrigger className="h-9 w-full text-[13px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {VIDEO_MASTER_MANIFESTS.filter((m) =>
                  player.formats.includes(m.kind),
                ).map((manifest) => (
                  <SelectItem
                    key={manifest.kind}
                    value={manifest.kind}
                    className="text-[13px]"
                  >
                    <span className="flex items-center gap-1.5">
                      <VideoFormatLabel format={manifest.output}>
                        {manifest.label}
                      </VideoFormatLabel>
                      {isFormatReady(manifest.kind) ? null : (
                        <span className="text-[12px] text-muted-foreground">
                          {t('Not ready')}
                        </span>
                      )}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </SelectField>

          {player.npmPackage ? (
            <SelectField label={t('Package manager')} className="min-w-[110px]">
              <Select
                value={packageManagerId}
                onValueChange={(value) =>
                  setPackageManagerId(value as PlayerPackageManagerId)
                }
              >
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PLAYER_PACKAGE_MANAGERS.map((pm) => (
                    <SelectItem
                      key={pm.id}
                      value={pm.id}
                      className="text-[13px]"
                    >
                      <span className="flex items-center gap-1.5">
                        <PackageManagerIcon packageManager={pm.id} size="sm" />
                        {pm.label}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </SelectField>
          ) : null}
        </div>

        <div className="grid gap-6 pt-4 lg:grid-cols-[0.9fr_1.4fr]">
          <div className="min-w-0 space-y-5">
            <div className="space-y-4">
              <h4 className="text-[13px] font-semibold text-foreground">
                {t('Install {player}').replace('{player}', player.label)}
              </h4>
              {installNote ? (
                <p className="text-[13px] text-muted-foreground">
                  {t(installNote)}
                </p>
              ) : null}
              {installSteps.map((step, index) => (
                <CodeBlock
                  key={`${playerId}-${index}`}
                  code={step.code}
                  language={step.language}
                  label={
                    installSteps.length > 1
                      ? `${index + 1}. ${t(step.label)}`
                      : t(step.label)
                  }
                  showCopy
                />
              ))}
            </div>

            <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 px-4 py-3 text-[13px] text-muted-foreground">
              <Info className="mt-0.5 h-4 w-4 shrink-0" />
              <p>
                {t(
                  'Manifest, segment, and subtitle requests use the read permissions of the source file in Storage. Grant read access to Any for public playback, or to signed-in users for private videos.',
                )}{' '}
                <Link
                  to="/projects/$projectId/storage/$bucketId"
                  params={{ projectId, bucketId: video.bucketId }}
                  className="text-foreground underline-offset-4 hover:underline"
                >
                  {t('Open bucket')}
                </Link>
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
              <a
                href={player.docsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="link-neutral inline-flex items-center gap-1.5 text-[13px]"
              >
                {t('{player} documentation').replace(
                  '{player}',
                  player.id === 'native' ? t(player.label) : player.label,
                )}
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
              <Link
                to="/projects/$projectId/videos/$videoId/streaming"
                params={{ projectId, videoId }}
                className="link-neutral text-[13px]"
              >
                {t('All streaming URLs')}
              </Link>
            </div>
          </div>

          <div className="min-w-0">
            {selectedFile ? (
              <ConnectCodeExample
                code={selectedFile.code}
                language={selectedFile.language}
                tabs={codeFiles.map((file, index) => ({
                  id: String(index),
                  label: file.label,
                }))}
                activeTabId={String(selectedFileIndex)}
                onTabChange={(id) => setSelectedFileIndex(Number(id))}
                selectorAriaLabel={t('Select file')}
              />
            ) : null}
          </div>
        </div>
      </div>
    </VideoPage>
  )
}

function SelectField({
  label,
  className,
  children,
}: {
  label: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={className}>
      <label className={SELECT_LABEL_CLASS}>{label}</label>
      {children}
    </div>
  )
}
