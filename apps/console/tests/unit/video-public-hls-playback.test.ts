import { describe, expect, test } from 'bun:test'
import {
  parseHlsMasterRenditions,
  publicHlsPlayback,
} from '@/lib/videos/public-hls-playback'

const MASTER = `#EXTM3U
#EXT-X-VERSION:4
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="group_audio",NAME="Audio 0",AUTOSELECT=YES,URI="/v1/videos/v1/outputs/hls/renditions/r360/streams/0/playlist.m3u8?project=p"


#EXT-X-STREAM-INF:BANDWIDTH=769296,RESOLUTION=640x360,NAME="640X360@896",CODECS="avc1.64001f,mp4a.40.2",AUDIO="group_audio"
/v1/videos/v1/outputs/hls/renditions/r360/streams/1/playlist.m3u8?project=p
#EXT-X-STREAM-INF:BANDWIDTH=2139816,RESOLUTION=1280x720,NAME="1280X720@2928",CODECS="avc1.640020,mp4a.40.2",AUDIO="group_audio"
/v1/videos/v1/outputs/hls/renditions/r720/streams/1/playlist.m3u8?project=p
`

describe('parseHlsMasterRenditions', () => {
  test('lists each rendition with its resolution, largest first', () => {
    expect(parseHlsMasterRenditions(MASTER)).toEqual([
      { $id: 'r720', width: 1280, height: 720 },
      { $id: 'r360', width: 640, height: 360 },
    ])
  })

  test('ignores audio-only entries and an empty playlist', () => {
    expect(parseHlsMasterRenditions('#EXTM3U\n#EXT-X-VERSION:4\n')).toEqual([])
  })

  test('keeps a stream without a resolution at zero size', () => {
    const manifest = `#EXTM3U
#EXT-X-STREAM-INF:BANDWIDTH=96000,CODECS="mp4a.40.2"
/v1/videos/v1/outputs/hls/renditions/audio/streams/0/playlist.m3u8`
    expect(parseHlsMasterRenditions(manifest)).toEqual([
      { $id: 'audio', width: 0, height: 0 },
    ])
  })
})

describe('publicHlsPlayback', () => {
  test('builds a playable video from the largest rendition', () => {
    const playback = publicHlsPlayback('v1', MASTER)
    expect(playback?.video.$id).toBe('v1')
    expect(playback?.video.width).toBe(1280)
    expect(playback?.video.height).toBe(720)
    expect(
      playback?.renditions.map((r) => [r.$id, r.output, r.status]),
    ).toEqual([
      ['r720', 'hls', 'ready'],
      ['r360', 'hls', 'ready'],
    ])
  })

  test('returns null when no rendition is ready', () => {
    expect(publicHlsPlayback('v1', '#EXTM3U\n')).toBeNull()
  })
})
