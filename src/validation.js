export const timeToSeconds = (timeStr) => {
  const parts = timeStr.split(':').map(Number);
  if (parts.length === 3) {
    return parts[0] * 3600 + parts[1] * 60 + parts[2];
  } else if (parts.length === 2) {
    return parts[0] * 60 + parts[1];
  }
  return 0;
};

export const secondsToTimeStr = (seconds) => {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;

  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }
  return `${minutes}:${String(secs).padStart(2, '0')}`;
};

export const sortChapters = (chapters) => {
  return chapters.sort((a, b) => {
    const timeA = timeToSeconds(a.startChapter);
    const timeB = timeToSeconds(b.startChapter);
    return timeA - timeB;
  });
};

export const isValidTimeFormat = (timeStr) =>
  /^\d{1,2}:\d{2}(:\d{2})?$/.test(timeStr) &&
  timeStr
    .split(':')
    .reverse()
    .every((part, index) => (index === 0 || index === 1 ? parseInt(part) < 60 : true));

export const getTimeDifferenceInSeconds = (time1, time2) => {
  return Math.abs(timeToSeconds(time1) - timeToSeconds(time2));
};

export const isValidChapterTime = (newTime, existingChapters) => {
  for (const chapter of existingChapters) {
    const timeDifference = getTimeDifferenceInSeconds(newTime, chapter.startChapter);

    if (timeDifference < 10) {
      return {
        valid: false,
        conflictWith: chapter.startChapter,
        difference: timeDifference
      };
    }
  }

  return { valid: true };
};

export const MAX_CHAPTER_TITLE_LENGTH = 50;

export const isValidChapterTitle = (title) => title.length <= MAX_CHAPTER_TITLE_LENGTH;

export const extractYouTubeId = (input) => {
  const patterns = [
    /(?:https?:\/\/)?(?:www\.)?youtu(?:be\.com\/watch\?v=|\.be\/)([\w\-_]*)/,
    /^([\w\-_]*)$/,
  ];

  for (const pattern of patterns) {
    const match = input.match(pattern);
    if (match?.[1]) return match[1];
  }

  return null;
};

export const parseYouTubeTimeUrl = (inputStr) => {
  if (!inputStr.includes('youtu') || (!inputStr.includes('t=') && !inputStr.includes('time_continue='))) {
    return null;
  }
  
  const match = inputStr.match(/[?&](?:t|time_continue)=([^&\s]+)/);
  if (!match || !match[1]) return null;
  
  const t = match[1];
  let seconds = 0;
  
  if (/^\d+s?$/.test(t)) {
    seconds = parseInt(t, 10);
  } else {
    const hMatch = t.match(/(\d+)h/);
    const mMatch = t.match(/(\d+)m/);
    const sMatch = t.match(/(\d+)s/);
    
    if (hMatch) seconds += parseInt(hMatch[1], 10) * 3600;
    if (mMatch) seconds += parseInt(mMatch[1], 10) * 60;
    if (sMatch) seconds += parseInt(sMatch[1], 10);
  }
  
  if (seconds === 0 && !/^\d+/.test(t) && !t.includes('s') && !t.includes('m') && !t.includes('h')) {
      return null;
  }
  
  return secondsToTimeStr(seconds) || '0:00';
};
