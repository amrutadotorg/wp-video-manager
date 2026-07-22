import { describe, expect, it } from 'vitest';
import {
  extractYouTubeId,
  getTimeDifferenceInSeconds,
  isValidChapterTime,
  isValidTimeFormat,
  parseYouTubeTimeUrl,
  secondsToTimeStr,
  sortChapters,
  timeToSeconds,
} from '../src/validation.js';

describe('time conversion', () => {
  it.each([
    ['0:00', 0],
    ['1:05', 65],
    ['1:02:03', 3723],
  ])('converts %s to seconds', (input, expected) => {
    expect(timeToSeconds(input)).toBe(expected);
  });

  it.each([
    [0, '0:00'],
    [65, '1:05'],
    [3723, '1:02:03'],
  ])('formats %i seconds as %s', (input, expected) => {
    expect(secondsToTimeStr(input)).toBe(expected);
  });

  it('calculates an absolute difference between two chapter times', () => {
    expect(getTimeDifferenceInSeconds('1:30', '0:45')).toBe(45);
  });
});

describe('chapter time validation', () => {
  it.each(['0:00', '59:59', '1:02:03'])('accepts valid time format %s', (time) => {
    expect(isValidTimeFormat(time)).toBe(true);
  });

  it.each(['0', '1:2', '1:60', '1:02:60', 'abc'])('rejects invalid time format %s', (time) => {
    expect(isValidTimeFormat(time)).toBe(false);
  });

  it('rejects a time less than ten seconds from an existing chapter', () => {
    expect(isValidChapterTime('1:05', [{ startChapter: '1:00' }])).toEqual({
      valid: false,
      conflictWith: '1:00',
      difference: 5,
    });
  });

  it('accepts a time exactly ten seconds from an existing chapter', () => {
    expect(isValidChapterTime('1:10', [{ startChapter: '1:00' }])).toEqual({ valid: true });
  });
});

describe('chapter sorting', () => {
  it('sorts chapters by their start time', () => {
    const chapters = [
      { startChapter: '1:00', title: 'Second' },
      { startChapter: '0:00', title: 'First' },
      { startChapter: '1:00:00', title: 'Third' },
    ];

    expect(sortChapters(chapters).map(({ title }) => title)).toEqual(['First', 'Second', 'Third']);
  });
});

describe('YouTube input parsing', () => {
  it.each([
    ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
  ])('extracts the video ID from %s', (input, expected) => {
    expect(extractYouTubeId(input)).toBe(expected);
  });

  it.each(['https://example.com/watch?v=dQw4w9WgXcQ', '', 'not a YouTube URL'])(
    'rejects unsupported input %s',
    (input) => {
      expect(extractYouTubeId(input)).toBeNull();
    }
  );

  it.each([
    ['https://www.youtube.com/watch?v=abc&t=90', '1:30'],
    ['https://youtu.be/abc?time_continue=1h2m3s', '1:02:03'],
    ['https://youtu.be/abc?t=0', '0:00'],
  ])('parses a YouTube timestamp from %s', (input, expected) => {
    expect(parseYouTubeTimeUrl(input)).toBe(expected);
  });

  it.each(['https://youtu.be/abc', 'https://youtu.be/abc?t=banana', 'https://example.com/?t=90'])(
    'returns null when no valid YouTube timestamp is present: %s',
    (input) => {
      expect(parseYouTubeTimeUrl(input)).toBeNull();
    }
  );
});
