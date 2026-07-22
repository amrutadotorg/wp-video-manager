import $ from 'jquery';
import './video-chapters.css';

import { extractYouTubeId, sortChapters, isValidChapterTime, timeToSeconds } from './validation.js';
import { searchVideoAPI, saveChaptersAPI } from './api.js';
import { createChapterRow, showMessage, clearAllErrors } from './ui.js';

const initializeApp = () => {
  const app = $('#app');
  app.html(`
    <div class="vcm-wrap">
      <h1 class="wp-heading-inline">Video Chapters Manager</h1>
      <div class="vcm-search-row">
        <input type="text" id="youtube-id" class="regular-text" placeholder="Enter YouTube ID or URL">
        <button class="button button-primary" id="search-video">Search</button>
      </div>
      <div id="video-info"></div>
      <div id="chapters-container"></div>
      <div class="vcm-actions" style="display: none; align-items: center;">
        <button class="button" id="add-chapter">Add Chapter</button>
        <button class="button button-primary" id="save-chapters">Save Chapters</button>
        <span style="margin-left: 15px; color: #646970; font-size: 13px;">
          💡 <strong>Tip:</strong> You can paste a YouTube URL with a timestamp (e.g., <code>?t=123</code>) directly into any <em>Start Time</em> field. See <a href="https://youtu.be/wrBzUdLz-Zw?t=41" target="_blank" rel="noopener">tutorial</a> for more details.
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
      }
      const newChapterRow = createChapterRow();
      $('#chapters-container').append(newChapterRow);
      setFirstChapterLock();
      updateChapterCounter();
      newChapterRow.find('.chapter-time').focus().select();
    });

  $('#save-chapters').on('click', saveChapters);
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
    } else {
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
  }

  const $saveButton = $('#save-chapters');
  const originalText = $saveButton.text();

  try {
    $saveButton.prop('disabled', true).text('Saving\u2026');

    const response = await saveChaptersAPI(videoId, youtubeId, sortedChapters);

    if (response.success) {
      showMessage(`Successfully saved ${sortedChapters.length} chapters!`, 'success');

      const $container = $('#chapters-container');
      $container.empty();
      sortedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });
      setFirstChapterLock();
      updateChapterCounter();
    } else {
      throw new Error(response.data?.message || 'Unknown error occurred');
    }
  } catch (error) {
    const errorMessage = error.responseJSON?.data?.message || error.message || 'Failed to save chapters.';
    showMessage(errorMessage, 'error');
  } finally {
    $saveButton.prop('disabled', false).text(originalText);
  }
};

const searchVideo = async () => {
  clearAllErrors();
  $('.vcm-actions').hide();
  
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
      let parsedChapters = JSON.parse(chapters) || [];

      parsedChapters = sortChapters(parsedChapters);

      const $info = $('#video-info')
        .data('video-id', id)
        .data('youtube-id', ytid);

      const $notice = $('<div class="notice notice-info"></div>');
      $notice.append($('<p></p>').html('<strong>Title:</strong> ').append($('<span></span>').text(title)));

      const $videoWrap = $('<div class="vcm-video-wrap"></div>');
      const $iframe = $('<iframe></iframe>')
        .attr('src', 'https://www.youtube.com/embed/' + ytid + '?rel=0&modestbranding=1')
        .attr('title', title)
        .attr('frameborder', '0')
        .attr('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share')
        .attr('referrerpolicy', 'strict-origin-when-cross-origin')
        .attr('allowfullscreen', true);
      $videoWrap.append($iframe);
      $notice.append($videoWrap);

      $info.append($notice);

      const $container = $('#chapters-container');
      $container.empty();
      parsedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });

      if (parsedChapters.length === 0) {
        $container.append(createChapterRow());
      }

      setFirstChapterLock();
      updateChapterCounter();
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
  if (count < minRequired) {
    $c.css('color', '#d63638').text(`Chapters: ${count} / ${minRequired} minimum`);
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
    });
  });
})(jQuery);
