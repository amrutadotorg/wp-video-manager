import $ from 'jquery';
import './video-chapters.css';

const API = {
  ajaxurl: videoChapters?.ajaxurl || '',
  nonce: videoChapters?.nonce || '',
};

const timeToSeconds = (timeStr) => {
  const parts = timeStr.split(':').map(Number);
  if (parts.length === 3) {
    return parts[0] * 3600 + parts[1] * 60 + parts[2];
  } else if (parts.length === 2) {
    return parts[0] * 60 + parts[1];
  }
  return 0;
};

const secondsToTimeStr = (seconds) => {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;

  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }
  return `${minutes}:${String(secs).padStart(2, '0')}`;
};

const sortChapters = (chapters) => {
  return chapters.sort((a, b) => {
    const timeA = timeToSeconds(a.startChapter);
    const timeB = timeToSeconds(b.startChapter);
    return timeA - timeB;
  });
};

const isValidTimeFormat = (timeStr) =>
  /^\d{1,2}:\d{2}(:\d{2})?$/.test(timeStr) &&
  timeStr
    .split(':')
    .reverse()
    .every((part, index) => (index === 0 || index === 1 ? parseInt(part) < 60 : true));

const clearAllErrors = () => {
  $('#alert-message').remove();
  $('.chapter-time, .chapter-title').removeClass('vcm-error');
};

const extractYouTubeId = (input) => {
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

const createChapterRow = (chapter = {}) => {
  const timeStr = chapter.startChapter || '0:00';
  const title = chapter.title || '';

  const row = $(`
    <div class="vcm-chapter-row">
      <div class="vcm-row-fields">
        <div class="vcm-field">
          <label for="">Start Time</label>
          <input type="text" class="regular-text chapter-time" placeholder="0:00">
        </div>
        <div class="vcm-field vcm-field-title">
          <label for="">Title</label>
          <input type="text" class="regular-text chapter-title" placeholder="Chapter Title">
        </div>
        <button type="button" class="button vcm-remove-btn" aria-label="Remove chapter">&times;</button>
      </div>
    </div>
  `);

  row.find('.chapter-time').val(timeStr);
  initializeTimeInput(row.find('.chapter-time'));

  const titleField = row.find('.chapter-title').val(title);
  titleField.autocomplete({
    source: function(request, response) {
      $.ajax({
        url: videoChapters.ajaxurl,
        type: 'POST',
        data: {
          action: 'get_chapter_titles',
          term: request.term,
          nonce: videoChapters.nonce,
        },
        success: function(data) {
          if (Array.isArray(data)) {
            response(data.slice(0, 10));
          } else {
            response([]);
          }
        },
        error: function() {
          response([]);
        }
      });
    },
    minLength: 1,
    delay: 300,
  });

  return row;
};

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

const getTimeDifferenceInSeconds = (time1, time2) => {
  return Math.abs(timeToSeconds(time1) - timeToSeconds(time2));
};

const isValidChapterTime = (newTime, existingChapters) => {
  for (const chapter of existingChapters) {
    const timeDifference = getTimeDifferenceInSeconds(newTime, chapter.startChapter);

    if (timeDifference < 60) {
      return {
        valid: false,
        conflictWith: chapter.startChapter,
        difference: timeDifference
      };
    }
  }

  return { valid: true };
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

    const response = await $.ajax({
      url: API.ajaxurl,
      type: 'POST',
      data: {
        action: 'save_chapters',
        nonce: API.nonce,
        video_id: videoId,
        youtube_id: youtubeId,
        chapters: sortedChapters
      },
      dataType: 'json'
    });

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
    const response = await $.post(API.ajaxurl, {
      action: 'search_video',
      nonce: API.nonce,
      youtube_id: youtubeId,
    });

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

const initializeTimeInput = (input) => {
  $(input).on('blur', function() {
    const $row = $(this).closest('.vcm-chapter-row');
    const currentTime = $(this).val().trim();

    if (!currentTime) return;

    if (!isValidTimeFormat(currentTime)) {
      $(this).addClass('vcm-error');
      showMessage('Invalid time format. Please use MM:SS or HH:MM:SS', 'error');
      return;
    }

    const otherChapters = [];
    $('.vcm-chapter-row').not($row).each(function() {
      const time = $(this).find('.chapter-time').val().trim();
      const title = $(this).find('.chapter-title').val().trim();
      if (time && title) {
        otherChapters.push({ startChapter: time, title });
      }
    });

    const validation = isValidChapterTime(currentTime, otherChapters);
    if (!validation.valid) {
      $(this).addClass('vcm-error');
      showMessage(
        `Chapters must be at least 60 seconds apart. This time is ${Math.floor(validation.difference)} seconds from the chapter at ${validation.conflictWith}`,
        'error'
      );
    } else {
      $(this).removeClass('vcm-error');
    }

    const seconds = timeToSeconds(currentTime);
    $(this).val(secondsToTimeStr(seconds));
  });
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

const showMessage = (message, type = 'info') => {
  $('#alert-message').remove();
  const noticeClass = type === 'success' ? 'notice-success' : type === 'error' ? 'notice-error' : 'notice-info';
  const alert = $(`
    <div id="alert-message" class="notice ${noticeClass} is-dismissible">
      <p></p>
      <button type="button" class="notice-dismiss" aria-label="Dismiss">
        <span class="screen-reader-text">Dismiss this notice.</span>
      </button>
    </div>`
  ).hide();

  alert.find('p').text(message);

  $('#app').prepend(alert);
  alert.slideDown(200);

  alert.find('.notice-dismiss').on('click', function() {
    alert.slideUp(200, function() { $(this).remove(); });
  });

  if (type === 'success') {
    setTimeout(() => {
      alert.slideUp(200, function() { $(this).remove(); });
    }, 4000);
  }
};

(function ($) {
  $(document).ready(() => {
    initializeApp();

    $(document).on('click', '.vcm-remove-btn', function () {
      $(this).closest('.vcm-chapter-row').remove();
    });
  });
})(jQuery);
