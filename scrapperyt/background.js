// background.js - Service Worker for MancingCuan YT Scrapper
chrome.runtime.onInstalled.addListener(() => {
  console.log("MancingCuan YT Scrapper extension installed successfully!");
  
  chrome.storage.local.get(["scrapedVideos", "isScrapingActive", "scraperSettings"], (result) => {
    if (!result.scrapedVideos) {
      chrome.storage.local.set({ scrapedVideos: [] });
    }
    if (result.isScrapingActive === undefined) {
      chrome.storage.local.set({ isScrapingActive: true });
    }
    if (!result.scraperSettings) {
      chrome.storage.local.set({
        scraperSettings: {
          includeReward: true,
          rewardMin: 50,
          rewardMax: 200,
          includeDuration: true,
          durationMin: 15,
          durationMax: 60
        }
      });
    }
  });
});
