import $ from 'jquery';
import 'bootstrap';
import 'jquery-ui-dist/jquery-ui.js'; // If jQuery UI must be used, keep this.
import './video-chapters.css';

const API = {
  ajaxurl: videoChapters?.ajaxurl || '',
  nonce: videoChapters?.nonce || '',
};

// Convert time string (HH:MM:SS or MM:SS) to seconds
const timeToSeconds = (timeStr) => {
  const parts = timeStr.split(':').map(Number);
  if (parts.length === 3) {
    return parts[0] * 3600 + parts[1] * 60 + parts[2];
  } else if (parts.length === 2) {
    return parts[0] * 60 + parts[1];
  }
  return 0;
};

// Convert seconds to time string (HH:MM:SS or MM:SS)
const secondsToTimeStr = (seconds) => {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;

  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }
  return `${minutes}:${String(secs).padStart(2, '0')}`;
};

// Sort chapters by time
const sortChapters = (chapters) => {
  return chapters.sort((a, b) => {
    const timeA = timeToSeconds(a.startChapter);
    const timeB = timeToSeconds(b.startChapter);
    return timeA - timeB;
  });
};

// Validate time string format
const isValidTimeFormat = (timeStr) =>
  /^\d{1,2}:\d{2}(:\d{2})?$/.test(timeStr) &&
  timeStr
    .split(':')
    .reverse()
    .every((part, index) => (index === 0 || index === 1 ? parseInt(part) < 60 : true));

// Clear all form errors and alerts
const clearAllErrors = () => {
  $('#alert-message').remove();
  $('.form-control').removeClass('is-invalid');
};

// Extract YouTube ID from input
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

// Create a single chapter row
const createChapterRow = (chapter = {}) => {
  const timeStr = chapter.startChapter || '0:00';
  const title = chapter.title || '';

  const row = $(`
    <div class="chapter-row mb-3">
      <div class="row">
        <div class="col-md-4">
          <div class="form-group">
            <label>Start Time</label>
            <input type="text" class="form-control chapter-time" value="${timeStr}" placeholder="0:00">
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-group">
            <label>Title</label>
            <input type="text" class="form-control chapter-title" value="${title}" placeholder="Chapter Title">
            <button type="button" class="btn btn-danger remove-chapter">×</button>
          </div>
        </div>
      </div>
    </div>
  `);

  // Initialize time input validation
  initializeTimeInput(row.find('.chapter-time'));

  // Attach autocomplete to the title field
  const titleField = row.find('.chapter-title');
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
    open: function() {
      $(this).autocomplete('widget').css({
        'max-height': '200px',
        'overflow-y': 'auto',
        'overflow-x': 'hidden',
        'z-index': 1000
      });
    }
  });

  return row;
};



// Initialize the app
const initializeApp = () => {
  const app = $('#app');
  app.html(`
    <div class="container">
      <h2>Video Chapters Manager</h2>
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="input-group">
            <input type="text" id="youtube-id" class="form-control" placeholder="Enter YouTube (ID or link)">
            <button class="btn btn-primary" id="search-video">Search</button>
          </div>
        </div>
      </div>
      <div id="video-info" class="my-4"></div>
      <div class="row">
        <div class="col-md-12">
          <div id="chapters-container"></div>
          <button class="btn btn-secondary mt-3" id="add-chapter">Add Chapter</button>
          <button class="btn btn-success mt-3" id="save-chapters">Save Chapters</button>
        </div>
      </div>
    </div>
  `);
$('#youtube-id').focus();
  // Initialize keyboard navigation
  initializeKeyboardNavigation();

$('#youtube-id').on('keypress', function(e) {
    if (e.which === 13) {
      e.preventDefault();
      $('#search-video').click();
    }
  });
  $('#search-video').on('click', searchVideo);
$('#add-chapter')
  .off('click') // Remove any previously attached listeners
  .on('click', () => {
    const newChapterRow = createChapterRow();
    $('#chapters-container').append(newChapterRow);

    const timeField = newChapterRow.find('.chapter-time');
    initializeTimeInput(timeField);

    timeField.focus();
  });


  // Save chapters functionality
  $('#save-chapters').on('click', saveChapters); // Bind save button to saveChapters function
};

const getTimeDifferenceInSeconds = (time1, time2) => {
  return Math.abs(timeToSeconds(time1) - timeToSeconds(time2));
};

// Validate new chapter time against existing chapters
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
// Modified save chapters function with time validation
const saveChapters = async () => {
  clearAllErrors();

  const videoId = $('#video-info').data('video-id');
  const youtubeId = $('#video-info').data('youtube-id');

  if (!videoId || !youtubeId) {
    showMessage('Please search for a video first.', 'danger');
    return;
  }

  const chapters = [];
  let hasErrors = false;
  let timeErrors = [];

  // Collect and validate chapters
  $('.chapter-row').each(function(_index) {
    const $row = $(this);
    const startTime = $row.find('.chapter-time').val().trim();
    const title = $row.find('.chapter-title').val().trim();

    if (!startTime || !title) {
      hasErrors = true;
      if (!startTime) $row.find('.chapter-time').addClass('is-invalid');
      if (!title) $row.find('.chapter-title').addClass('is-invalid');
      return;
    }

    // Check time conflicts with existing chapters
    const validation = isValidChapterTime(startTime, chapters);
    if (!validation.valid) {
      hasErrors = true;
      $row.find('.chapter-time').addClass('is-invalid');
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
    showMessage(errorMessage, 'danger');
    return;
  }

  // Sort chapters by time
  const sortedChapters = sortChapters(chapters);

  const $saveButton = $('#save-chapters');
  const originalText = $saveButton.text();

  try {
    $saveButton.prop('disabled', true)
              .html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

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
      
      // Update display with sorted chapters
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
    showMessage(errorMessage, 'danger');
  } finally {
    $saveButton.prop('disabled', false).text(originalText);
  }
};

// Modify the search video function to display sorted chapters
const searchVideo = async () => {
  clearAllErrors();
  const input = $('#youtube-id').val().trim();
  const youtubeId = extractYouTubeId(input);

  if (!youtubeId) {
    showMessage('Invalid YouTube ID or URL.', 'danger');
    return;
  }

  try {
    $('#search-video').prop('disabled', true).text('Searching...');
    const response = await $.post(API.ajaxurl, {
      action: 'search_video',
      nonce: API.nonce,
      youtube_id: youtubeId,
    });

    if (response?.success) {
      const { id, title, ytid, chapters } = response.data;
      let parsedChapters = JSON.parse(chapters) || [];
      
      // Sort chapters by time when displaying
      parsedChapters = sortChapters(parsedChapters);

      $('#video-info')
        .data('video-id', id)
        .data('youtube-id', ytid)
        .html(`
          <div class="alert alert-info">
            <strong>Title:</strong> ${title}<br>
            <strong>URL:</strong> <a href="https://youtube.com/watch?v=${ytid}" target="_blank">View</a>
          </div>
        `);

      const $container = $('#chapters-container');
      $container.empty();
      parsedChapters.forEach(chapter => {
        $container.append(createChapterRow(chapter));
      });
    } else {
      throw new Error(response?.data || 'Video not found.');
    }
  } catch (error) {
    showMessage(error.message, 'danger');
  } finally {
    $('#search-video').prop('disabled', false).text('Search');
  }
};




const initializeTimeInput = (input) => {
  const $input = $(input);
  
  $input.on('blur', function() {
    const $row = $(this).closest('.chapter-row');
    const currentTime = $(this).val().trim();
    
    if (!currentTime) return;

    if (!isValidTimeFormat(currentTime)) {
      $(this).addClass('is-invalid');
      showMessage('Invalid time format. Please use MM:SS or HH:MM:SS', 'danger');
      return;
    }

    // Collect all other chapter times
    const otherChapters = [];
    $('.chapter-row').not($row).each(function() {
      const time = $(this).find('.chapter-time').val().trim();
      const title = $(this).find('.chapter-title').val().trim();
      if (time && title) {
        otherChapters.push({ startChapter: time, title });
      }
    });

    // Validate time difference
    const validation = isValidChapterTime(currentTime, otherChapters);
    if (!validation.valid) {
      $(this).addClass('is-invalid');
      showMessage(
        `Chapters must be at least 60 seconds apart. This time is ${Math.floor(validation.difference)} seconds from the chapter at ${validation.conflictWith}`, 
        'danger'
      );
    } else {
      $(this).removeClass('is-invalid');
    }

    // Format to consistent time string
    const seconds = timeToSeconds(currentTime);
    $(this).val(secondsToTimeStr(seconds));
  });
};

const initializeKeyboardNavigation = () => {
  $(document).on('keydown', '.chapter-time, .chapter-title', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const currentRow = $(this).closest('.chapter-row');
      const isLastRow = currentRow.is(':last-child');
      
      if (isLastRow) {
        $('#add-chapter').click();
        setTimeout(() => {
          $('.chapter-row:last-child .chapter-time').focus();
        }, 100);
      } else {
        currentRow.next().find('.chapter-time').focus();
      }
    }
  });
};
// Show message alerts
const showMessage = (message, type = 'info') => {
  $('#alert-message').remove();
  const alert = $(`
    <div id="alert-message" class="alert alert-${type} alert-dismissible fade show" role="alert">
      ${message}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>`
  ).hide();

  $('#app').prepend(alert);
  alert.fadeIn(300);

  // Auto-dismiss success messages
  if (type === 'success') {
    setTimeout(() => {
      alert.fadeOut(300, function() {
        $(this).remove();
      });
    }, 3000);
  }
};

(function ($) {
  $(document).ready(() => {
    initializeApp();
    
    $(document).on('click', '.remove-chapter', function () {
      $(this).closest('.chapter-row').remove();
    });
  });
})(jQuery);