import type { AlternativeContent } from '@/lib/alternatives/types'

export const muxAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Streaming',
      rows: [
        {
          label: 'Output formats',
          appwrite: { value: 'HLS, DASH, and CMAF', note: 'Every output has its own master manifest' },
          competitor: { value: 'HLS', note: 'Plus MP4 renditions, no DASH' },
        },
        {
          label: 'Encoding ladder',
          appwrite: { value: 'Profiles you control', note: 'Resolution, bitrates, and codec per profile' },
          competitor: { value: 'partial', note: 'Pick a quality level and max resolution' },
        },
        { label: 'Audio-only streams', appwrite: true, competitor: true },
        {
          label: 'Source file',
          appwrite: { value: 'Stays in your Storage bucket', note: 'Untouched, under your permissions' },
          competitor: { value: 'Ingested by Mux', note: 'Up to 12 hours per asset' },
        },
      ],
    },
    {
      title: 'Playback',
      rows: [
        {
          label: 'Built-in player',
          appwrite: { value: true, note: 'Embed with an iframe from the Console' },
          competitor: { value: true, note: 'Mux Player, open source' },
        },
        {
          label: 'Third-party players',
          appwrite: { value: true, note: 'hls.js, Shaka, Video.js, AVPlayer, ExoPlayer' },
          competitor: { value: true, note: 'Any HLS player' },
        },
        { label: 'Timeline hover thumbnails', appwrite: true, competitor: true },
        {
          label: 'Playback quality insights',
          appwrite: { value: 'Inspector', note: 'Live experience score per session' },
          competitor: { value: 'Mux Data', note: 'Included with Mux Video' },
        },
      ],
    },
    {
      title: 'Subtitles',
      rows: [
        {
          label: 'Upload WebVTT and SubRip',
          appwrite: { value: true, note: 'From any file in Storage' },
          competitor: true,
        },
        {
          label: 'Extract tracks embedded in the source',
          appwrite: { value: true, note: 'Automatic on the first encoding job' },
          competitor: { value: 'partial', note: 'Documented for live captions' },
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Auth, database, and functions in the same project',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'File storage for any file type',
          appwrite: { value: true, note: 'Image transformations included' },
          competitor: false,
        },
        {
          label: 'Hosting, messaging, and realtime',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Open source',
          appwrite: true,
          competitor: { value: 'partial', note: 'Player and uploader only' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Mux might still fit',
    description:
      'Mux is a mature, dedicated video API. If video is the whole product, its depth may be worth a separate vendor.',
    points: [
      'You need live streaming with RTMP or SRT ingest, simulcast, and low-latency modes.',
      'You need DRM with Widevine, FairPlay, and PlayReady licenses.',
      'You want captions generated automatically from speech, or AI chapters and summaries.',
      'You already run Mux Data across many players and want the same quality analytics everywhere.',
    ],
  },
  related: [
    {
      kind: 'product',
      title: 'Appwrite Videos',
      description: 'Adaptive HLS, DASH, and CMAF streams with subtitles.',
      href: '/products/videos',
    },
    {
      kind: 'product',
      title: 'Appwrite Storage',
      description: 'Upload, transform, and deliver files on CDN.',
      href: '/products/storage',
    },
    {
      kind: 'docs',
      title: 'Storage permissions',
      description: 'Grant access per user, team, or role.',
      href: '/docs/products/storage/permissions',
    },
    {
      kind: 'product',
      title: 'Appwrite Functions',
      description: 'Run code on every upload in 13+ runtimes.',
      href: '/products/functions',
    },
    {
      kind: 'product',
      title: 'Appwrite Auth',
      description: 'Email, OAuth, SMS, MFA, teams, and sessions.',
      href: '/products/auth',
    },
    {
      kind: 'blog',
      title: 'Appwrite vs Cloudinary: Storage and image handling compared',
      description: 'Fit, not quality: when each one makes sense.',
      href: '/blog/post/appwrite-vs-cloudinary',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite a good Mux alternative?',
      answer:
        'Yes, for on-demand video in apps. Appwrite Videos turns files in Storage into adaptive HLS, DASH, and CMAF streams with an encoding ladder, subtitles, scrubbing thumbnails, and a built-in player, in the same project as your auth, databases, and functions. Mux is a separate, proprietary video API that delivers HLS.',
      links: [{ label: 'Appwrite Videos', href: '/products/videos' }],
    },
    {
      question: 'What is the best open-source alternative to Mux?',
      answer:
        'Appwrite is an open-source backend that includes video streaming. Appwrite Videos encodes and packages streams from your Storage files, next to auth, databases, functions, realtime, messaging, and hosting. Mux open sources its player and uploader, but encoding, storage, and delivery run only on Mux.',
    },
    {
      question: 'Does Appwrite support DASH as well as HLS?',
      answer:
        'Yes. Package each rendition as HLS with MPEG-TS segments, DASH with fMP4 segments, or CMAF, which encodes fMP4 once and exposes both an HLS and a DASH master manifest. Mux delivers HLS and MP4 renditions.',
    },
    {
      question: 'Can I control the encoding ladder?',
      answer:
        'Yes. Every project starts with a default ladder of encoding profiles. Add your own profile with a resolution, video and audio bitrate, and codec, and each video gets at most one rendition per profile and output.',
    },
    {
      question: 'Which players work with Appwrite Videos?',
      answer:
        'Embed the built-in Appwrite player with an iframe, or use your own. The same master manifest works with hls.js, Shaka Player, and Video.js on the web, AVPlayer on Apple platforms, ExoPlayer on Android, and the usual Flutter and React Native players.',
    },
    {
      question: 'How do subtitles work?',
      answer:
        'Upload a WebVTT or SubRip file to Storage and attach it to a video with a name and an ISO 639-2 language code. Subtitle tracks embedded in the source are extracted automatically during the first encoding job.',
    },
    {
      question: 'Does Appwrite Videos support live streaming?',
      answer:
        'Not today. Appwrite Videos streams on-demand video and audio from files in Storage. If you need live ingest, simulcast, or low-latency live, Mux is a strong fit for that part of your product.',
    },
    {
      question: 'Do I need another service for users and data with Mux?',
      answer:
        'Usually, yes. Mux handles video and audio only, so sign-in, permissions, databases, and the rest of your backend live elsewhere and you sync them with webhooks. With Appwrite, videos sit in the same project as your users, files, and data.',
    },
  ],
  sources: [
    { label: 'Mux pricing', href: 'https://www.mux.com/pricing' },
    { label: 'Mux pricing guide', href: 'https://www.mux.com/docs/pricing/overview' },
    { label: 'Mux playback', href: 'https://www.mux.com/docs/guides/play-your-videos' },
    { label: 'Mux on HLS and DASH', href: 'https://www.mux.com/blog/what-is-a-video-playback-engine' },
    { label: 'Mux assets API', href: 'https://www.mux.com/docs/api-reference/video/assets' },
    { label: 'Mux subtitles', href: 'https://www.mux.com/docs/guides/add-subtitles-to-your-videos' },
    { label: 'Mux live captions', href: 'https://www.mux.com/docs/guides/add-live-captions' },
    { label: 'Mux DRM', href: 'https://www.mux.com/docs/guides/protect-videos-with-drm' },
    { label: 'Mux timeline previews', href: 'https://www.mux.com/docs/guides/create-timeline-hover-previews' },
    { label: 'Mux open source', href: 'https://www.mux.com/oss' },
  ],
}
