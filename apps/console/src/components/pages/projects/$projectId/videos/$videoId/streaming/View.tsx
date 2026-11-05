import { useMemo, useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import type { Models, VideoOutput } from '@appwrite.io/console'
import { CodeXml, Info } from 'lucide-react'
import { VideoPage, VideoSectionCard } from '../../_components/VideoPage'
import { VideoTermHint } from '../../_components/VideoTermHint'
import { VideoFormatLabel } from '../../_components/VideoOutputBadge'
import { VideoUrlRow } from '../../_components/VideoUrlRow'
import { NothingToStreamNotice } from '../../_components/NothingToStreamNotice'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useProjectVideo,
  useVideoRenditions,
  useVideoSubtitles,
} from '@/lib/react-query/hooks'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import {
  getVideoMasterManifestUrl,
  getVideoRenditionPlaylistUrl,
  getVideoSubtitleManifestUrl,
  VIDEO_MASTER_MANIFESTS,
} from '@/lib/videos/urls'
import { useT } from '@/lib/i18n/translate'

const MANIFEST_TERMS: Record<string, VideoGlossaryTerm> = {
  hls: 'hls',
  dash: 'dash',
  'cmaf-hls': 'cmaf',
  'cmaf-dash': 'cmaf',
}

type SubtitleOutput = 'hls' | 'dash' | 'cmaf'

export function View() {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { data: video } = useProjectVideo(projectId, videoId)
  const { data: renditionsData } = useVideoRenditions(projectId, videoId)
  const { data: subtitlesData } = useVideoSubtitles(projectId, videoId)
  const renditions = useMemo(
    () => renditionsData?.renditions ?? [],
    [renditionsData],
  )
  const subtitles = subtitlesData?.subtitles ?? []

  const readyByOutput = useMemo(() => {
    const counts: Record<string, number> = { hls: 0, dash: 0, cmaf: 0 }
    for (const rendition of renditions) {
      if (rendition.status === 'ready' && rendition.output in counts) {
        counts[rendition.output] += 1
      }
    }
    return counts
  }, [renditions])
  const anyReady = Object.values(readyByOutput).some((count) => count > 0)

  if (!video) return null

  return (
    <VideoPage
      title={t('Streaming')}
      term="manifest"
      actions={
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-1.5 text-[13px]"
          asChild
        >
          <Link
            to="/projects/$projectId/videos/$videoId/install"
            params={{ projectId, videoId }}
          >
            <CodeXml className="h-3.5 w-3.5" />
            {t('Install a player')}
          </Link>
        </Button>
      }
    >
      {!anyReady ? <NothingToStreamNotice /> : null}

      <VideoSectionCard
        title={t('Master manifests')}
        term="manifest"
        description={t(
          'Use HLS for Apple devices and most web players, DASH for Android and Shaka Player. CMAF serves both from one set of files.',
        )}
        bodyClassName="py-1"
      >
        <div className="divide-y divide-border">
          {VIDEO_MASTER_MANIFESTS.map((manifest) => {
            const count = readyByOutput[manifest.output]
            return (
              <VideoUrlRow
                key={manifest.kind}
                label={manifest.label}
                formatKey={manifest.kind}
                term={MANIFEST_TERMS[manifest.kind]}
                meta={count > 0 ? `${count}` : undefined}
                url={getVideoMasterManifestUrl(
                  projectId,
                  videoId,
                  manifest.kind,
                )}
                unavailableReason={
                  count > 0
                    ? null
                    : t('No ready renditions for this output yet')
                }
              />
            )
          })}
        </div>
      </VideoSectionCard>

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

      <SubtitleUrlsCard
        projectId={projectId}
        videoId={videoId}
        subtitles={subtitles}
      />

      <AdvancedUrlsCard
        projectId={projectId}
        videoId={videoId}
        renditions={renditions.filter((r) => r.status === 'ready')}
        subtitles={subtitles.filter((s) => s.status === 'ready')}
      />
    </VideoPage>
  )
}

function SubtitleUrlsCard({
  projectId,
  videoId,
  subtitles,
}: {
  projectId: string
  videoId: string
  subtitles: Models.VideoSubtitle[]
}) {
  const t = useT()
  const [output, setOutput] = useState<SubtitleOutput>('hls')
  return (
    <VideoSectionCard
      title={t('Subtitle tracks')}
      description={t(
        'Master manifests already include these. Use the direct URLs to load a track yourself. For DASH the URL returns the WebVTT file.',
      )}
      actions={
        subtitles.length > 0 ? (
          <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={output}
            onValueChange={(value) => {
              if (value) setOutput(value as SubtitleOutput)
            }}
          >
            {(['hls', 'dash', 'cmaf'] as const).map((id) => (
              <ToggleGroupItem
                key={id}
                value={id}
                className="h-8 px-3 text-[12px]"
              >
                <VideoFormatLabel format={id}>
                  {id.toUpperCase()}
                </VideoFormatLabel>
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        ) : null
      }
      bodyClassName="py-1"
    >
      {subtitles.length === 0 ? (
        <p className="py-3 text-[13px] text-muted-foreground">
          {t('No subtitle tracks. Add them from the Subtitles page.')}
        </p>
      ) : (
        <div className="divide-y divide-border">
          {subtitles.map((subtitle) => (
            <VideoUrlRow
              key={subtitle.$id}
              label={subtitle.name}
              meta={subtitle.code}
              url={getVideoSubtitleManifestUrl(
                projectId,
                videoId,
                subtitle.$id,
                output,
              )}
              unavailableReason={
                subtitle.status === 'ready'
                  ? null
                  : t('Track is still being packaged')
              }
            />
          ))}
        </div>
      )}
    </VideoSectionCard>
  )
}

/** Per-rendition and per-segment URLs players normally discover from the master manifest. */
function AdvancedUrlsCard({
  projectId,
  videoId,
  renditions,
  subtitles,
}: {
  projectId: string
  videoId: string
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
}) {
  const t = useT()
  const [renditionId, setRenditionId] = useState('')
  const [streamId, setStreamId] = useState('0')
  const [segmentId, setSegmentId] = useState('')
  const [subtitleId, setSubtitleId] = useState('')
  const [subtitleSegmentId, setSubtitleSegmentId] = useState('')

  const rendition =
    renditions.find((r) => r.$id === renditionId) ?? renditions[0] ?? null
  const subtitle =
    subtitles.find((s) => s.$id === subtitleId) ?? subtitles[0] ?? null
  const videos = sdk.forProject(projectId).videos

  const playlistUrl = rendition
    ? getVideoRenditionPlaylistUrl(
        projectId,
        videoId,
        rendition.$id,
        rendition.output,
        Math.max(0, Number(streamId) || 0),
      )
    : null
  const segmentUrl =
    rendition && segmentId.trim()
      ? String(
          videos.getSegment({
            videoId,
            output: rendition.output as VideoOutput,
            renditionId: rendition.$id,
            segmentId: segmentId.trim(),
          }),
        )
      : ''
  const subtitleSegmentUrl =
    subtitle && subtitleSegmentId.trim()
      ? String(
          videos.getSubtitleSegment({
            videoId,
            output: 'hls' as VideoOutput,
            subtitleId: subtitle.$id,
            segmentId: subtitleSegmentId.trim(),
          }),
        )
      : ''

  return (
    <VideoSectionCard
      title={t('Individual streams and segments')}
      term="mediaPlaylist"
      description={t(
        'Advanced. Players reach these from the master manifest. Use them to debug one quality level or fetch a single segment.',
      )}
    >
      {renditions.length === 0 ? (
        <p className="text-[13px] text-muted-foreground">
          {t('Available once a rendition is ready.')}
        </p>
      ) : (
        <div className="space-y-5">
          <div className="grid gap-3 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label className="text-[12px]">{t('Rendition')}</Label>
              <Select
                value={rendition?.$id ?? ''}
                onValueChange={setRenditionId}
              >
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {renditions.map((r) => (
                    <SelectItem
                      key={r.$id}
                      value={r.$id}
                      className="text-[13px]"
                    >
                      <VideoFormatLabel format={r.output}>
                        {r.output.toUpperCase()} · {r.name}
                      </VideoFormatLabel>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label
                htmlFor="stream-index"
                className="flex items-center gap-1 text-[12px]"
              >
                {t('Stream index')}
                <VideoTermHint term="streamIndex" />
              </Label>
              <Input
                id="stream-index"
                type="number"
                min={0}
                value={streamId}
                onChange={(event) => setStreamId(event.target.value)}
                className="h-9 font-mono text-[13px]"
              />
            </div>
            <div className="space-y-1.5">
              <Label
                htmlFor="segment-id"
                className="flex items-center gap-1 text-[12px]"
              >
                {t('Segment ID')}
                <VideoTermHint term="segment" />
              </Label>
              <Input
                id="segment-id"
                value={segmentId}
                onChange={(event) => setSegmentId(event.target.value)}
                placeholder={t('From the media playlist')}
                className="h-9 font-mono text-[13px]"
              />
            </div>
          </div>
          <div className="divide-y divide-border">
            <VideoUrlRow
              label={t('Media playlist')}
              term="mediaPlaylist"
              url={playlistUrl ?? ''}
              unavailableReason={
                playlistUrl
                  ? null
                  : t(
                      'DASH has no per-stream playlist. Segments are listed in the MPD.',
                    )
              }
            />
            <VideoUrlRow
              label={t('Segment')}
              term="segment"
              url={segmentUrl}
              unavailableReason={segmentUrl ? null : t('Enter a segment ID')}
            />
          </div>

          {subtitles.length > 0 ? (
            <>
              <div className="border-t border-border" />
              <div className="grid gap-3 sm:grid-cols-3">
                <div className="space-y-1.5">
                  <Label className="text-[12px]">{t('Subtitle track')}</Label>
                  <Select
                    value={subtitle?.$id ?? ''}
                    onValueChange={setSubtitleId}
                  >
                    <SelectTrigger className="h-9 text-[13px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {subtitles.map((s) => (
                        <SelectItem
                          key={s.$id}
                          value={s.$id}
                          className="text-[13px]"
                        >
                          {s.name} ({s.code})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label
                    htmlFor="subtitle-segment-id"
                    className="flex items-center gap-1 text-[12px]"
                  >
                    {t('Segment ID')}
                    <VideoTermHint term="segment" />
                  </Label>
                  <Input
                    id="subtitle-segment-id"
                    value={subtitleSegmentId}
                    onChange={(event) =>
                      setSubtitleSegmentId(event.target.value)
                    }
                    placeholder={t('From the subtitle playlist')}
                    className="h-9 font-mono text-[13px]"
                  />
                </div>
              </div>
              <div className="divide-y divide-border">
                <VideoUrlRow
                  label={t('Subtitle segment')}
                  term="segment"
                  url={subtitleSegmentUrl}
                  unavailableReason={
                    subtitleSegmentUrl ? null : t('Enter a segment ID')
                  }
                />
              </div>
            </>
          ) : null}
        </div>
      )}
    </VideoSectionCard>
  )
}
