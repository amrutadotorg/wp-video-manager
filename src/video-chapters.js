import $ from 'jquery';
import './video-chapters.css';

import { extractYouTubeId, sortChapters, isValidChapterTime, timeToSeconds, secondsToTimeStr, isValidChapterTitle, MAX_CHAPTER_TITLE_LENGTH, getDuplicateTitleIndices } from './validation.js';
import { searchVideoAPI, saveChaptersAPI } from './api.js';
import { createChapterRow, showMessage, clearAllErrors, highlightInvalidChapters, highlightDuplicateTitles } from './ui.js';

let ytPlayer = null;
window.originalChaptersJSON = '[]';

const initializeApp = () => {
  const app = $('#app');
  app.html(`
    <div class="vcm-wrap">
      <h1 class="wp-heading-inline">Video Chapters Manager</h1>
      <div class="vcm-search-row">
        <input type="text" id="youtube-id" class="regular-text" placeholder="Enter YouTube ID or URL">
        <button class="button button-primary" id="search-video">Search</button>
      </div>
      <div id="video-info" class="vcm-video-info"></div>
      <div id="chapters-container" class="vcm-chapters-container"></div>
      <div class="vcm-actions" style="display: none; align-items: center;">
        <button class="button" id="add-chapter">Add Chapter</button>
        <button class="button button-primary vcm-save-chapters" id="save-chapters">Save Chapters</button>
        <span style="margin-left: 15px; color: #646970; font-size: 13px;">
          💡 <strong>Tip:</strong> Pause the video at the desired moment, then click <em>Add Chapter</em> — the start time will be set automatically.
        </span>
      </div>
    </div>
  `);

  $('#youtube-id').focus();
  initializeKeyboardNavigation();

  $('#youtube-id').on('keypress', function(e) {
    if (e.which === 13) {
      e.preventDefault();
      $('#search-video').click();
    }
  });

  $('#search-video').on('click', searchVideo);

  $('#add-chapter')
    .off('click')
    .on('click', () => {
      const $lastRow = $('.vcm-chapter-row:last');
      if ($lastRow.length) {
        const lastTitle = $lastRow.find('.chapter-title').val().trim();
        const lastTime = $lastRow.find('.chapter-time').val().trim();
        if (!lastTitle || !lastTime) {
          showMessage('Please fill in the current chapter before adding a new one.', 'error');
          if (!lastTime) $lastRow.find('.chapter-time').addClass('vcm-error');
          if (!lastTitle) $lastRow.find('.vcm-title-widget').addClass('vcm-error');
          return;
        }

        const currentTime = (ytPlayer && ytPlayer.getCurrentTime)
          ? secondsToTimeStr(Math.floor(ytPlayer.getCurrentTime()))
          : lastTime;
        const existingChapters = [];
        $('.vcm-chapter-row').each(function () {
          const t = $(this).find('.chapter-time').val().trim();
          const ti = $(this).find('.chapter-title').val().trim();
          if (t && ti) existingChapters.push({ startChapter: t, title: ti });
        });
        const validation = isValidChapterTime(currentTime, existingChapters);
        if (!validation.valid) {
          showMessage(
            `Cannot add chapter at ${currentTime} — must be at least 10 seconds from the chapter at ${validation.conflictWith} (${validation.difference}s apart). Move the player to a later moment and try again.`,
            'error'
          );
          return;
        }
      }
      const currentTime = (ytPlayer && ytPlayer.getCurrentTime)
        ? secondsToTimeStr(Math.floor(ytPlayer.getCurrentTime()))
        : '0:00';
      const newChapterRow = createChapterRow({ startChapter: currentTime });
      $('#chapters-container').append(newChapterRow);
      setFirstChapterLock();
      updateChapterCounter();
      newChapterRow.find('.vcm-title-search').focus();
    });

  $('#chapters-container').on('input change', '.chapter-time, .chapter-title, .vcm-title-search', function() {
    updateChapterCounter();
    highlightInvalidChapters();
    highlightDuplicateTitles();
  });

  $('#save-chapters').on('click', saveChapters);

  $(document).on('click', '.vcm-start-time-link', function() {
    const timeStr = $(this).closest('.vcm-field').find('.chapter-time').val().trim();
    const seconds = timeToSeconds(timeStr);
    if (ytPlayer && ytPlayer.seekTo) {
      ytPlayer.seekTo(seconds, true);
      if (ytPlayer.playVideo) ytPlayer.playVideo();
    }
  });

  $(document).on('keydown', '.vcm-start-time-link', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      $(this).trigger('click');
    }
  });
};

const saveChapters = async () => {
  clearAllErrors();

  const videoId = $('#video-info').data('video-id');
  const youtubeId = $('#video-info').data('youtube-id');

  if (!videoId || !youtubeId) {
    showMessage('Please search for a video first.', 'error');
    return;
  }

  const chapters = [];
  let hasErrors = false;
  const timeErrors = [];
  const titleErrors = [];

  $('.vcm-chapter-row').each(function() {
    const $row = $(this);
    const startTime = $row.find('.chapter-time').val().trim();
    const title = $row.find('.chapter-title').val().trim();

    if (!startTime || !title) {
      hasErrors = true;
      if (!startTime) $row.find('.chapter-time').addClass('vcm-error');
      if (!title) $row.find('.vcm-title-widget').addClass('vcm-error');
      return;
    }

    if (!isValidChapterTitle(title)) {
      hasErrors = true;
      $row.find('.vcm-title-widget').addClass('vcm-error');
      titleErrors.push(`Chapter "${title.substring(0, 30)}${title.length > 30 ? '…' : ''}" exceeds ${MAX_CHAPTER_TITLE_LENGTH} characters (${title.length}).`);
      return;
    }

    const validation = isValidChapterTime(startTime, chapters);
    if (!validation.valid) {
      hasErrors = true;
      $row.find('.chapter-time').addClass('vcm-error');
      timeErrors.push(`Chapter at ${startTime} is too close to chapter at ${validation.conflictWith} (${validation.difference} seconds apart)`);
      return;
    }

    chapters.push({ startChapter: startTime, title });
  });

  if (hasErrors) {
    let errorMessage = 'Please correct the following errors:\n';
    if (timeErrors.length > 0) {
      errorMessage += '\n' + timeErrors.join('\n');
    }
    if (titleErrors.length > 0) {
      errorMessage += '\n' + titleErrors.join('\n');
    }
    if (!timeErrors.length && !titleErrors.length) {
      errorMessage = 'Please fill in all chapter fields.';
    }
    showMessage(errorMessage, 'error');
    return;
  }

  const sortedChapters = sortChapters(chapters);

  if (sortedChapters.length > 0) {
    if (sortedChapters.length < 3) {
      showMessage('You must include at least three separate chapters.', 'error');
      return;
    }
    
    if (timeToSeconds(sortedChapters[0].startChapter) !== 0) {
      showMessage('The very first timestamp on your list must be exactly 0:00.', 'error');
      return;
    }

    const dupes = getDuplicateTitleIndices(sortedChapters);
    if (dupes.size > 0) {
      const dupeList = [...dupes].map(i => `Chapter at ${sortedChapters[i].startChapter} — title "${sortedChapters[i].title}" is same as previous chapter`).join('\n');
      showMessage(
        `Consecutive chapters cannot share the same title. Either merge them or rename one:\n\n${dupeList}`,
        'error'
      );
      return;
    }
  }

  const $saveButton = $('#save-chapters');
  const originalText = $saveButton.text();

  try {
    $saveButton.prop('disabled', true).text('Saving\u2026');

    const response = await saveChaptersAPI(videoId, youtubeId, sortedChapters);

    if (response.success) {
      const syncFailed = response.data?.sync_status === 'failed';
      showMessage(
        response.data?.message || `Successfully saved ${sortedChapters.length} chapters!`,
        syncFailed ? 'error' : 'success'
      );

      const $container = $('#chapters-container');
      $container.empty();
      sortedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });
      window.originalChaptersJSON = JSON.stringify(sortedChapters);
      setFirstChapterLock();
      highlightInvalidChapters();
      highlightDuplicateTitles();
    } else {
      throw new Error(response.data?.message || 'Unknown error occurred');
    }
  } catch (error) {
    const errorMessage = error.responseJSON?.data?.message || error.message || 'Failed to save chapters.';
    showMessage(errorMessage, 'error');
  } finally {
    $saveButton.text(originalText);
    updateChapterCounter();
  }
};

const initYTPlayer = () => {
  if (ytPlayer) return;
  ytPlayer = new window.YT.Player('vcm-youtube-player', {
    events: {},
  });
};

const searchVideo = async () => {
  clearAllErrors();
  $('.vcm-actions').hide();
  ytPlayer = null;
  
  const input = $('#youtube-id').val().trim();
  const youtubeId = extractYouTubeId(input);

  if (!youtubeId) {
    showMessage('Invalid YouTube ID or URL.', 'error');
    return;
  }

  try {
    $('#search-video').prop('disabled', true).text('Searching\u2026');
    const response = await searchVideoAPI(youtubeId);

    if (response?.success) {
      const { id, title, ytid, chapters } = response.data;
		let parsedChapters = Array.isArray(chapters) ? chapters : [];

      parsedChapters = sortChapters(parsedChapters);
      
      if (parsedChapters.length > 0 && timeToSeconds(parsedChapters[0].startChapter) !== 0) {
        parsedChapters.unshift({ startChapter: '0:00', title: '' });
      }

      const $info = $('#video-info')
        .data('video-id', id)
        .data('youtube-id', ytid);

      const $notice = $('<div class="notice notice-info"></div>');
      $notice.append($('<p></p>').html('<strong>Title:</strong> ').append($('<span></span>').text(title)));

      const $videoWrap = $('<div class="vcm-video-wrap"></div>');
      const $iframe = $('<iframe></iframe>')
        .attr('id', 'vcm-youtube-player')
        .attr('src', 'https://www.youtube.com/embed/' + ytid + '?enablejsapi=1&rel=0&modestbranding=1')
        .attr('title', title)
        .attr('frameborder', '0')
        .attr('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share')
        .attr('referrerpolicy', 'strict-origin-when-cross-origin')
        .attr('allowfullscreen', true);
      $videoWrap.append($iframe);
      $notice.append($videoWrap);

      if (window.YT && window.YT.Player) {
        initYTPlayer();
      } else {
        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
        window.onYouTubeIframeAPIReady = initYTPlayer;
      }

      $info.append($notice);

      const $container = $('#chapters-container');
      $container.empty();
      parsedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });

      if (parsedChapters.length === 0) {
        $container.append(createChapterRow());
      }
      
      const currentChapters = [];
      $('.vcm-chapter-row').each(function() {
        const t = $(this).find('.chapter-time').val().trim();
        const ti = $(this).find('.chapter-title').val().trim();
        currentChapters.push({ startChapter: t, title: ti });
      });
      window.originalChaptersJSON = JSON.stringify(currentChapters);

      setFirstChapterLock();
      updateChapterCounter();
      highlightInvalidChapters();
      highlightDuplicateTitles();
      $('.vcm-actions').show();
    } else {
      throw new Error(response?.data || 'Video not found.');
    }
  } catch (error) {
    showMessage(error.message, 'error');
  } finally {
    $('#search-video').prop('disabled', false).text('Search');
  }
};

const setFirstChapterLock = () => {
  const $rows = $('.vcm-chapter-row');
  $rows.each(function (i) {
    const $time = $(this).find('.chapter-time');
    const $field = $time.closest('.vcm-field');
    $field.find('.vcm-locked-hint').remove();

    if (i === 0) {
      $time.val('0:00').prop('disabled', true).addClass('vcm-locked')
        .attr('title', 'First chapter must always start at 0:00');
      $field.append('<span class="vcm-locked-hint">First chapter is always 0:00</span>');
    } else {
      $time.prop('disabled', false).removeClass('vcm-locked')
        .removeAttr('title');
    }
  });
};

const updateChapterCounter = () => {
  const count = $('.vcm-chapter-row').length;
  const minRequired = 3;
  const $counter = $('#chapter-counter');

  if ($counter.length === 0) {
    $('.vcm-actions').prepend('<span id="chapter-counter" style="color: #646970; font-size: 13px;"></span>');
  }

  const $c = $('#chapter-counter');

  const currentChapters = [];
  $('.vcm-chapter-row').each(function() {
    const t = $(this).find('.chapter-time').val().trim();
    const ti = $(this).find('.chapter-title').val().trim();
    currentChapters.push({ startChapter: t, title: ti });
  });
  const currentJSON = JSON.stringify(currentChapters);
  const isChanged = currentJSON !== window.originalChaptersJSON;

  if (count < minRequired) {
    $c.css('color', '#d63638').text(`Chapters: ${count} / ${minRequired} minimum`);
    $('#save-chapters').prop('disabled', true);
  } else if (!isChanged && window.originalChaptersJSON !== '[]') {
    $c.css('color', '#646970').text(`Chapters: ${count}`);
    $('#save-chapters').prop('disabled', true);
  } else {
    $c.css('color', '#646970').text(`Chapters: ${count}`);
    $('#save-chapters').prop('disabled', false);
  }
};

const initializeKeyboardNavigation = () => {
  $(document).on('keydown', '.chapter-time, .vcm-title-search', function (e) {
    if (e.key === 'Enter') {
      // For .vcm-title-search, only navigate if autocomplete is not open
      // (Enter on autocomplete-open is handled by attachTitleWidget)
      if ($(this).hasClass('vcm-title-search')) {
        const acWidget = $(this).autocomplete('widget');
        if (acWidget && acWidget.is(':visible')) return;
        // If typed value will be committed (length>=1), let that handler fire
        if ($(this).val().trim().length >= 1) return;
      }
      e.preventDefault();
      const currentRow = $(this).closest('.vcm-chapter-row');
      if (currentRow.is(':last-child')) {
        $('#add-chapter').click();
        setTimeout(() => {
          $('.vcm-chapter-row:last-child .chapter-time').focus().select();
        }, 100);
      } else {
        currentRow.next().find('.chapter-time').focus().select();
      }
    }
  });
};

(function ($) {
  $(document).ready(() => {
    initializeApp();

    const params = new URLSearchParams(window.location.search);
    const preloadId = params.get('video_id') || params.get('youtube_id');
    if (preloadId) {
      $('#youtube-id').val(preloadId);
      $('#search-video').click();
    }

    $(document).on('click', '.vcm-remove-btn', function () {
      $(this).closest('.vcm-chapter-row').remove();
      setFirstChapterLock();
      updateChapterCounter();
      highlightInvalidChapters();
      highlightDuplicateTitles();
    });
  });
})(jQuery);
