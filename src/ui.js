import $ from 'jquery';
import { timeToSeconds, secondsToTimeStr, isValidTimeFormat, isValidChapterTime, parseYouTubeTimeUrl } from './validation.js';
import { getChapterTitlesAPI } from './api.js';

export const clearAllErrors = () => {
  $('#alert-message').remove();
  $('.chapter-time, .chapter-title').removeClass('vcm-error');
};

export const showMessage = (message, type = 'info') => {
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

export const initializeTimeInput = (input) => {
  $(input).on('blur', function() {
    let currentTime = $(this).val().trim();

    if (!currentTime) return;
    
    // Check if the user pasted a YouTube link with a timestamp
    const parsedUrlTime = parseYouTubeTimeUrl(currentTime);
    if (parsedUrlTime) {
      currentTime = parsedUrlTime;
      $(this).val(currentTime);
    }

    if (!isValidTimeFormat(currentTime)) {
      $(this).addClass('vcm-error');
      showMessage('Invalid time format. Please use MM:SS or HH:MM:SS', 'error');
      return;
    }

    const otherChapters = [];
    $('.vcm-chapter-row').not($(this).closest('.vcm-chapter-row')).each(function() {
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
        `Chapters must be at least 10 seconds apart. This time is ${Math.floor(validation.difference)} seconds from the chapter at ${validation.conflictWith}`,
        'error'
      );
    } else {
      $(this).removeClass('vcm-error');
    }

    const seconds = timeToSeconds(currentTime);
    $(this).val(secondsToTimeStr(seconds));
  });
};

export const createChapterRow = (chapter = {}) => {
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
      const term = request.term.trim();

      getChapterTitlesAPI(term).then(
        (data) => {
          const existing = Array.isArray(data) ? data.slice(0, 10) : [];
          const lowerTerm = term.toLowerCase();
          const exactMatch = existing.some(
            (t) => t.trim().toLowerCase() === lowerTerm
          );

          const items = existing.map((t) => ({ label: t, value: t, isNew: false }));

          if (!exactMatch && term.length >= 3) {
            items.push({
              label: `+ Add new title: "${term}"`,
              value: term,
              isNew: true,
            });
          }

          response(items);
        },
        () => response([])
      );
    },
    minLength: 3,
    delay: 300,
  });

  titleField.autocomplete('instance')._renderItem = function(ul, item) {
    return $('<li>')
      .toggleClass('vcm-suggestion-new', !!item.isNew)
      .toggleClass('vcm-suggestion-existing', !item.isNew)
      .append($('<div>').text(item.label))
      .appendTo(ul);
  };

  return row;
};
