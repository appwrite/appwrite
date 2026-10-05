import type { ProductPageContent } from '@/lib/products/types'

export const videosProductContent: ProductPageContent = {
  id: 'videos',
  metaDescription:
    'Stream video and audio with Appwrite Videos. Turn files in Storage into adaptive HLS, DASH, and CMAF streams with encoding profiles, subtitles, and scrubbing thumbnails.',
  hero: {
    title: 'Adaptive video streaming from your Storage files',
    description:
      'Turn video and audio files into HLS, DASH, and CMAF streams with an encoding ladder, subtitles, and scrubbing thumbnails. First frame under 100 ms, by design. One API, in the same project as your Auth, Databases, and Storage.',
    stats: [
      { value: '< 100 ms', label: 'To first frame' },
      { value: '3 outputs', label: 'HLS, DASH, and CMAF' },
      { value: 'Adaptive', label: 'Default bitrate ladder' },
      { value: 'Any player', label: 'Web, mobile, and native' },
      { value: 'Inspector', label: 'Live experience score' },
    ],
  },
  faq: [
    {
      question: 'Which files can I turn into a video?',
      answer:
        'Any video or audio file in an Appwrite Storage bucket. Create a video from the bucket and file IDs, and the source file stays in Storage untouched. Audio-only sources stream the same way but have no timeline thumbnails.',
    },
    {
      question: 'What is the difference between a profile and a rendition?',
      answer:
        'A profile is a reusable encoding target with a resolution, video and audio bitrate, and codec. A rendition is one encode of a video against a profile, packaged for one output. Each video has at most one rendition per profile and output. Updating a profile does not re-encode existing renditions.',
    },
    {
      question: 'Which streaming formats are supported?',
      answer:
        'HLS, DASH, and CMAF. HLS uses MPEG-TS segments, DASH uses fMP4 segments, and CMAF encodes fMP4 once and exposes both an HLS and a DASH master manifest. Players request the master manifest and follow it to renditions, segments, and subtitles.',
    },
    {
      question: 'How do subtitles work?',
      answer:
        'Upload a WebVTT or SubRip file to Storage and attach it to a video with a name and an ISO 639-2 language code. Uploads are converted to WebVTT and segmented for streaming. Subtitle tracks embedded in the source are extracted automatically during the first encoding job, and you can retag their language at any time.',
    },
    {
      question: 'What happens when I delete a video?',
      answer:
        'Deleting a video removes its renditions, subtitles, previews, and transcoded segments. Deleting a profile keeps the renditions that were already encoded with it.',
    },
    {
      question: 'How do you reach a first frame under 100 ms?',
      answer:
        'The pipeline is designed for that startup budget. Players load a small master manifest, pick a rendition from the ladder that the connection can fetch immediately, and request a short first segment instead of the source file. Use the inspector to watch time to first frame on a real session.',
    },
    {
      question: 'Who can manage videos in my organization?',
      answer:
        'Videos follows your organization roles. The videos.read and videos.write scopes control who can view and manage videos, profiles, renditions, and subtitles.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
  ],
  cta: {
    title: 'Start streaming with Videos',
    description:
      'Upload a file to Storage, create a video, and get an adaptive stream URL for any HLS or DASH player.',
  },
}
