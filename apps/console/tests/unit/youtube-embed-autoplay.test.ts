import { describe, expect, test } from 'bun:test'
import { withAutoplay } from '@/components/global/shared/YoutubePlayerDialog'

describe('withAutoplay', () => {
  test('adds autoplay to YouTube embed URLs', () => {
    expect(withAutoplay('https://www.youtube-nocookie.com/embed/abc123')).toBe(
      'https://www.youtube-nocookie.com/embed/abc123?autoplay=1',
    )
    expect(withAutoplay('https://www.youtube.com/embed/abc123?start=30')).toBe(
      'https://www.youtube.com/embed/abc123?start=30&autoplay=1',
    )
  })

  test('refuses anything that is not an https YouTube URL', () => {
    expect(withAutoplay('javascript:alert(document.domain)')).toBeNull()
    expect(withAutoplay('data:text/html,<script>alert(1)</script>')).toBeNull()
    expect(withAutoplay('http://www.youtube.com/embed/abc123')).toBeNull()
    expect(
      withAutoplay('https://www.youtube.com.example.com/embed/abc123'),
    ).toBeNull()
    expect(withAutoplay('https://example.com/?u=www.youtube.com')).toBeNull()
    expect(withAutoplay('/embed/abc123')).toBeNull()
  })
})
