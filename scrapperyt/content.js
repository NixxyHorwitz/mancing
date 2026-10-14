// content.js - MancingCuan YouTube Video Scraper Content Script

let knownVideoIds = new Set();
let isScrapingActive = true;
let throttleTimeout = null;

function log(msg, type = "info") {
  const styles = {
    info: "color: #f59e0b; font-weight: bold; background: #0f172a; padding: 2px 6px; border-radius: 4px;",
    success: "color: #10b981; font-weight: bold; background: #0f172a; padding: 2px 6px; border-radius: 4px;",
    warning: "color: #ef4444; font-weight: bold; background: #0f172a; padding: 2px 6px; border-radius: 4px;"
  };
  console.log(`%c[MancingCuan Scrapper] ${msg}`, styles[type] || styles.info);
}

function parseDurationText(str) {
  if (!str) return null;
  str = str.trim();
  const parts = str.split(':').map(p => parseInt(p.trim(), 10));
  if (parts.some(isNaN)) return null;
  if (parts.length === 2) {
    return parts[0] * 60 + parts[1];
  } else if (parts.length === 3) {
    return parts[0] * 3600 + parts[1] * 60 + parts[2];
  } else if (parts.length === 1) {
    return parts[0];
  }
  return null;
}

function initializeScraper() {
  log("Ekstensi MancingCuan YT Scrapper aktif.", "info");
  
  chrome.storage.local.get(["scrapedVideos", "isScrapingActive"], (result) => {
    if (result.scrapedVideos) {
      result.scrapedVideos.forEach(v => {
        const vid = v.youtube_id || v.youtubeId;
        if (vid) knownVideoIds.add(vid);
      });
    }
    if (result.isScrapingActive !== undefined) {
      isScrapingActive = result.isScrapingActive;
    }
    
    log(`Tersinkronisasi ${knownVideoIds.size} video dalam cache. Status aktif: ${isScrapingActive}`, "success");
    
    if (isScrapingActive) {
      setTimeout(scrapeVideos, 1200);
    }
  });
}

chrome.storage.onChanged.addListener((changes, areaName) => {
  if (areaName === 'local') {
    if (changes.isScrapingActive !== undefined) {
      isScrapingActive = changes.isScrapingActive.newValue;
      log(`Status scraping: ${isScrapingActive ? 'Aktif' : 'Dijeda'}`, "warning");
      if (isScrapingActive) scrapeVideos();
    }
    if (changes.scrapedVideos) {
      const newVal = changes.scrapedVideos.newValue || [];
      if (newVal.length === 0) {
        knownVideoIds.clear();
        log("Cache video lokal dibersihkan.", "warning");
      } else {
        newVal.forEach(v => {
          const vid = v.youtube_id || v.youtubeId;
          if (vid) knownVideoIds.add(vid);
        });
      }
    }
  }
});

function scrapeVideos() {
  if (!isScrapingActive) return;

  const anchors = document.querySelectorAll('a[href*="/watch?v="]');
  const foundVideos = [];

  anchors.forEach(anchor => {
    const href = anchor.getAttribute('href');
    if (!href) return;

    const match = href.match(/[?&]v=([^&#]+)/);
    if (!match) return;
    const videoId = match[1];

    if (knownVideoIds.has(videoId)) return;

    let title = "";

    // 1. Title attribute on anchor
    if (anchor.hasAttribute('title') && anchor.getAttribute('title').trim()) {
      title = anchor.getAttribute('title').trim();
    }

    // 2. Standard YouTube title elements
    if (!title) {
      const titleEl = anchor.querySelector('#video-title, #video-title-link, yt-formatted-string');
      if (titleEl && titleEl.textContent.trim()) {
        title = titleEl.textContent.trim();
      }
    }

    // 3. Anchor text
    if (!title && (anchor.id === 'video-title-link' || anchor.id === 'video-title')) {
      title = anchor.textContent.trim();
    }

    // 4. Closest layout container search
    const container = anchor.closest('ytd-rich-grid-media, ytd-rich-item-renderer, ytd-video-renderer, ytd-compact-video-renderer, ytd-grid-video-renderer, ytm-media-item, ytm-compact-video-renderer') || anchor.parentElement;
    if (!title && container) {
      const titleEl = container.querySelector('#video-title, #video-title-link, .media-item-title, h3, .title-text');
      if (titleEl && titleEl.textContent.trim()) {
        title = titleEl.textContent.trim();
      }
    }

    // 5. Parent text fallback
    if (!title && anchor.parentElement) {
      const titleEl = anchor.parentElement.querySelector('#video-title, h3, .title, .title-text');
      if (titleEl && titleEl.textContent.trim()) {
        title = titleEl.textContent.trim();
      }
    }

    if (!title) {
      title = anchor.textContent.trim();
    }

    if (title) {
      title = title.replace(/\s+/g, ' ').trim();
    }

    // Attempt duration extraction
    let durationSeconds = null;
    let durationText = "";
    if (container) {
      const timeEl = container.querySelector('ytd-thumbnail-overlay-time-status-renderer span, badge-shape .badge-shape-wiz__text, #time-status span, .ytd-thumbnail-overlay-time-status-renderer, span.ytd-thumbnail-overlay-time-status-renderer');
      if (timeEl && timeEl.textContent.trim()) {
        durationText = timeEl.textContent.trim();
        durationSeconds = parseDurationText(durationText);
      }
    }

    if (videoId && title && title.length > 2 && isNaN(title) && title !== videoId) {
      const item = {
        youtube_id: videoId,
        title: title,
        url: `https://www.youtube.com/watch?v=${videoId}`,
        thumbnail: `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`,
        watch_duration: durationSeconds || 30
      };

      foundVideos.push(item);
      knownVideoIds.add(videoId);
      log(`+ Scraped: "${title}" [${videoId}] (${durationText || '30s'})`, "success");
    }
  });

  if (foundVideos.length > 0) {
    saveNewVideos(foundVideos);
  }
}

function saveNewVideos(newVideos) {
  chrome.storage.local.get(["scrapedVideos"], (result) => {
    let currentList = result.scrapedVideos || [];
    
    const currentIds = new Set(currentList.map(v => v.youtube_id || v.youtubeId));
    const uniqueNew = newVideos.filter(v => !currentIds.has(v.youtube_id));
    
    if (uniqueNew.length > 0) {
      const updatedList = [...currentList, ...uniqueNew];
      chrome.storage.local.set({ scrapedVideos: updatedList }, () => {
        log(`Tersimpan ${uniqueNew.length} video baru. Total: ${updatedList.length}`, "success");
        
        chrome.runtime.sendMessage({
          action: "updateVideoCount",
          count: updatedList.length,
          addedCount: uniqueNew.length
        }, () => {
          if (chrome.runtime.lastError) {}
        });
      });
    }
  });
}

function throttledScrape() {
  if (throttleTimeout) return;
  throttleTimeout = setTimeout(() => {
    throttleTimeout = null;
    if (isScrapingActive) {
      scrapeVideos();
    }
  }, 1000);
}

window.addEventListener('scroll', throttledScrape, { passive: true });

const observer = new MutationObserver((mutations) => {
  let shouldScrape = false;
  for (let m of mutations) {
    if (m.addedNodes && m.addedNodes.length > 0) {
      shouldScrape = true;
      break;
    }
  }
  if (shouldScrape) throttledScrape();
});

observer.observe(document.body, { childList: true, subtree: true });

initializeScraper();
