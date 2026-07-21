import $ from 'jquery';
import './video-chapters.css';

import { extractYouTubeId, sortChapters, isValidChapterTime } from './validation.js';
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
      <div class="vcm-actions">
        <button class="button" id="add-chapter">Add Chapter</button>
        <button class="button button-primary" id="save-chapters">Save Chapters</button>
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
      const newChapterRow = createChapterRow();
      $('#chapters-container').append(newChapterRow);
      newChapterRow.find('.chapter-time').focus();
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
      if (!title) $row.find('.chapter-title').addClass('vcm-error');
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
      $notice.append($('<p></p>').html('<strong>URL:</strong> ').append(
        $('<a></a>').attr('href', 'https://youtube.com/watch?v=' + ytid).attr('target', '_blank').text('View on YouTube')
      ));
      $info.append($notice);

      const $container = $('#chapters-container');
      $container.empty();
      parsedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });
    } else {
      throw new Error(response?.data || 'Video not found.');
    }
  } catch (error) {
    showMessage(error.message, 'error');
  } finally {
    $('#search-video').prop('disabled', false).text('Search');
  }
};

const initializeKeyboardNavigation = () => {
  $(document).on('keydown', '.chapter-time, .chapter-title', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const currentRow = $(this).closest('.vcm-chapter-row');
      const isLastRow = currentRow.is(':last-child');

      if (isLastRow) {
        $('#add-chapter').click();
        setTimeout(() => {
          $('.vcm-chapter-row:last-child .chapter-time').focus();
        }, 100);
      } else {
        currentRow.next().find('.chapter-time').focus();
      }
    }
  });
};

(function ($) {
  $(document).ready(() => {
    initializeApp();

    $(document).on('click', '.vcm-remove-btn', function () {
      $(this).closest('.vcm-chapter-row').remove();
    });
  });
})(jQuery);
