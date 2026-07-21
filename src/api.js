import $ from 'jquery';

const API = {
  ajaxurl: videoChapters?.ajaxurl || '',
  nonce: videoChapters?.nonce || '',
};

export const searchVideoAPI = (youtubeId) => {
  return $.post(API.ajaxurl, {
    action: 'search_video',
    nonce: API.nonce,
    youtube_id: youtubeId,
  });
};

export const saveChaptersAPI = (videoId, youtubeId, chapters) => {
  return $.ajax({
    url: API.ajaxurl,
    type: 'POST',
    data: {
      action: 'save_chapters',
      nonce: API.nonce,
      video_id: videoId,
      youtube_id: youtubeId,
      chapters: chapters,
    },
    dataType: 'json',
  });
};

export const getChapterTitlesAPI = (term) => {
  return $.ajax({
    url: API.ajaxurl,
    type: 'POST',
    data: {
      action: 'get_chapter_titles',
      term: term,
      nonce: API.nonce,
    },
  });
};
