/**
 * Predefined filter columns for the Videos list.
 * Attributes match the server's `Queries\Videos::ALLOWED_ATTRIBUTES`.
 */

import type { FilterColumn } from '../types'

export const videosFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'format', title: 'Format', type: 'string' },
  { id: 'videoCodec', title: 'Video codec', type: 'string' },
  { id: 'audioCodec', title: 'Audio codec', type: 'string' },
  { id: 'videoBitRate', title: 'Video bitrate (bps)', type: 'integer' },
  { id: 'audioBitRate', title: 'Audio bitrate (bps)', type: 'integer' },
  { id: 'duration', title: 'Duration (ms)', type: 'integer' },
  { id: 'width', title: 'Width', type: 'integer' },
  { id: 'height', title: 'Height', type: 'integer' },
  { id: 'size', title: 'Size (bytes)', type: 'integer' },
  { id: 'bucketId', title: 'Bucket ID', type: 'string' },
  { id: 'fileId', title: 'File ID', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]
