import $ from 'jquery';
import { timeToSeconds, secondsToTimeStr, isValidTimeFormat, isValidChapterTime, parseYouTubeTimeUrl, isValidChapterTitle, MAX_CHAPTER_TITLE_LENGTH } from './validation.js';
import { getChapterTitlesAPI } from './api.js';

export const clearAllErrors = () => {
  $('#alert-message').remove();
  $('.chapter-time, .chapter-title').removeClass('vcm-error');
  $('.vcm-title-widget').removeClass('vcm-error');
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
  
  $('html, body').animate({
    scrollTop: alert.offset().top - 50
  }, 300);

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

/**
 * Attach chip-style title widget to a row.
 * The hidden .chapter-title input holds the committed value.
 * The visible .vcm-title-search input is the autocomplete field.
 */
const attachTitleWidget = (row, initialTitle) => {
  const widget    = row.find('.vcm-title-widget');
  const hidden    = row.find('.chapter-title');      // real value storage
  const chipWrap  = row.find('.vcm-title-chip-wrap');
  const searchWrap = row.find('.vcm-title-search-wrap');
  const searchInput = row.find('.vcm-title-search');
  const hint      = row.find('.vcm-title-hint');

  /** Show a chip for the committed title and hide the search input */
  const commitTitle = (value) => {
    if (!value.trim()) return;
    if (!isValidChapterTitle(value.trim())) {
      widget.addClass('vcm-error');
      showMessage(
        `Chapter title exceeds ${MAX_CHAPTER_TITLE_LENGTH} characters (${value.trim().length}). Vimeo will reject it.`,
        'error'
      );
      return;
    }
    hidden.val(value.trim()).trigger('change');
    widget.removeClass('vcm-error');

    // Build chip
    chipWrap.empty();
    const chip = $('<span class="vcm-title-chip"></span>');
    const label = $('<span class="vcm-title-chip-label"></span>').text(value.trim());
    const removeBtn = $('<button type="button" class="vcm-title-chip-remove" aria-label="Remove title">&times;</button>');
    chip.append(label).append(removeBtn);
    // Append chip to wrap — DOM presence makes it visible (no .show() needed)
    chipWrap.append(chip);

    // Hide search area via inline style (not CSS class, to avoid specificity fights)
    searchWrap.css('display', 'none');
    hint.css('display', 'none');

    // Remove chip → restore search
    removeBtn.on('click', () => {
      hidden.val('').trigger('change');
      chipWrap.empty(); // emptying the wrap hides it visually (no children = no size)
      searchInput.val('').trigger('input');
      searchWrap.css('display', '');
      hint.css('display', '');
      searchInput.focus();
    });
  };

  // If we have an initial title (loaded from DB) show the chip immediately
  if (initialTitle) {
    commitTitle(initialTitle);
  } else {
    // No initial title: chipWrap is empty (invisible), searchWrap visible
    searchWrap.css('display', '');
    hint.css('display', '');
  }

  // Autocomplete on the search input
  searchInput.autocomplete({
    source: function(request, response) {
      searchInput.addClass('vcm-ac-loading');
      const term = request.term.trim();
      getChapterTitlesAPI(term).then(
        (data) => {
          searchInput.removeClass('vcm-ac-loading');
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
        () => {
          searchInput.removeClass('vcm-ac-loading');
          response([]);
        }
      );
    },
    minLength: 3,
    delay: 300,
    select: function(event, ui) {
      event.preventDefault();
      commitTitle(ui.item.value);
    },
  });

  searchInput.autocomplete('instance')._renderItem = function(ul, item) {
    return $('<li>')
      .toggleClass('vcm-suggestion-new', !!item.isNew)
      .toggleClass('vcm-suggestion-existing', !item.isNew)
      .append($('<div>').text(item.label))
      .appendTo(ul);
  };

  // Allow committing a manually typed title with Enter
  searchInput.on('keydown', function(e) {
    if (e.key === 'Enter' && $(this).val().trim().length >= 1) {
      // Only commit if autocomplete menu is NOT open
      if (!searchInput.autocomplete('widget').is(':visible')) {
        e.preventDefault();
        e.stopPropagation();
        commitTitle($(this).val().trim());
      }
    }
  });

  // Update hint visibility on typing
  searchInput.on('input', function() {
    const len = $(this).val().length;
    if (len > 0) {
      hint.text(`${len}/${MAX_CHAPTER_TITLE_LENGTH}`);
      hint.toggleClass('vcm-error', len > MAX_CHAPTER_TITLE_LENGTH);
      hint.show();
    } else {
      hint.html('Type 3+ characters to see title suggestions.');
      hint.removeClass('vcm-error');
      hint.toggle(!hidden.val());
    }
  });
};

export const createChapterRow = (chapter = {}) => {
  const timeStr = chapter.startChapter || '0:00';
  const title   = chapter.title || '';

  const row = $(`
    <div class="vcm-chapter-row">
      <div class="vcm-row-fields">
        <div class="vcm-field">
          <label>Start Time</label>
          <input type="text" class="regular-text chapter-time" placeholder="0:00">
        </div>
        <div class="vcm-field vcm-field-title">
          <label>Title</label>
          <input type="hidden" class="chapter-title">
          <div class="vcm-title-widget">
            <div class="vcm-title-chip-wrap"></div>
            <div class="vcm-title-search-wrap">
              <input type="text" class="regular-text vcm-title-search" placeholder="Search or type a title…" autocomplete="off">
            </div>
          </div>
          <span class="description vcm-title-hint">Type 3+ characters to see title suggestions.</span>
        </div>
        <div class="vcm-row-actions">
          <button type="button" class="button vcm-remove-btn" aria-label="Remove chapter" title="Remove chapter"><span class="dashicons dashicons-trash"></span></button>
        </div>
      </div>
    </div>
  `);

  row.find('.chapter-time').val(timeStr);
  initializeTimeInput(row.find('.chapter-time'));

  attachTitleWidget(row, title);

  return row;
};
