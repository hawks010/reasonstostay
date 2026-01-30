/**
 * Reasons to Stay - Main JavaScript
 * Handles letter viewer, onboarding, and form submission
 * v2.2.1 - Enterprise Edition (Integrated, Compatible, No-Fatal)
 *
 * Notes:
 * - Avoids invalid URL() usage with relative REST bases
 * - Sanitizes letter HTML safely (allows basic markup, strips dangerous nodes/attrs)
 * - Health/perf/error endpoints are optional; failures are silent
 */

const RTSLetterSystem = {
  // State
  preferences: {
    feelings: [],
    readingTime: 'long',
    tone: 'any',
    skipOnboarding: false
  },

  // Feature flags (Auto-managed)
  features: {
    prefetch: true,
    toastNotifications: true,
    analytics: true,
    healthChecks: true,
    remoteErrorReporting: false,
    remotePerfReporting: false
  },

  experiments: {},

  domElements: {
    loading: null,
    display: null,
    content: null,
    signature: null,
    helpfulBtn: null,
    unhelpBtn: null,
    ratePrompt: null,
    rateUpBtn: null,
    rateDownBtn: null,
    rateSkipBtn: null
  },

  currentLetter: null,
  prefetchedLetter: null,
  prefetchInFlight: false,
  loadingFirstLetter: false,
  viewedLetterIds: [],
  sessionStartTime: null,
  debounceTimer: null,
  lastActivityTime: null,
  isOnline: navigator.onLine,

  // Rating prompt flow
  pendingNextAfterRate: false,

  requestQueue: [],
  isProcessingQueue: false,
  activeRequests: new Set(),
  letterCache: {},
  CACHE_TTL: 5 * 60 * 1000,
  cacheVersion: 1,
  _lastCacheKey: null,
  _cacheKeyVersion: 0,
  swRegistered: false,
  swMessageHandler: null,

  healthChecks: {
    lastSuccessfulFetch: null,
    consecutiveFailures: 0,
    isHealthy: true
  },

  circuitBreaker: {
    state: 'CLOSED',
    failureCount: 0,
    failureThreshold: 5,
    resetTimeout: 30000,
    lastFailureTime: null,

    canExecute() {
      if (this.state === 'OPEN') {
        if (Date.now() - this.lastFailureTime > this.resetTimeout) {
          this.state = 'HALF_OPEN';
          return true;
        }
        return false;
      }
      return true;
    },

    onSuccess() {
      this.failureCount = 0;
      this.state = 'CLOSED';
      this.lastFailureTime = null;
    },

    onFailure() {
      this.failureCount++;
      this.lastFailureTime = Date.now();
      if (this.failureCount >= this.failureThreshold) {
        this.state = 'OPEN';
        try { console.warn('[RTS] Circuit breaker OPEN - preventing requests'); } catch(e){}
      }
    }
  },

  performance: {
    startTimes: {},
    metrics: [],
    MAX_METRICS: 100,

    logMetric(label, duration, success = true) {
      const metric = {
        label,
        duration,
        success,
        timestamp: Date.now(),
        memory: (performance && performance.memory) ? {
          used: performance.memory.usedJSHeapSize,
          total: performance.memory.totalJSHeapSize
        } : null
      };

      this.metrics.push(metric);
      if (this.metrics.length > this.MAX_METRICS) this.metrics.shift();

      if (duration > 2000 && Math.random() < 0.1) {
        this.reportSlowOperation(metric);
      }
    },

    reportSlowOperation(metric) {
      // Optional endpoint: /performance
      if (!RTSLetterSystem.features.remotePerfReporting) return;
      if (!navigator.sendBeacon) return;

      const endpoint = RTSLetterSystem.getRestBase() + 'performance';
      try { navigator.sendBeacon(endpoint, JSON.stringify(metric)); } catch(e) {}
    }
  },

  rateLimit: {
    requests: [],
    MAX_REQUESTS_PER_MINUTE: 30,

    canMakeRequest() {
      const now = Date.now();
      const oneMinuteAgo = now - 60000;
      this.requests = this.requests.filter(time => time > oneMinuteAgo);
      if (this.requests.length >= this.MAX_REQUESTS_PER_MINUTE) return false;
      this.requests.push(now);
      return true;
    }
  },

  errorLog: [],
  MAX_ERROR_LOG: 50,
  errorCounts: {},

  MAX_VIEWED_IDS: 100,
  eventListeners: [],

  init() {
    try {
      this.autoConfigure();
      this.setupExperiments();
      this.ensureStyles();
      this.addResourceHints();

      this.sessionStartTime = Date.now();
      this.lastActivityTime = Date.now();

      this.cacheDomElements();
      this.loadState();
      this.checkOnboarding();
      this.bindEvents();

      // SW is optional and must never break the viewer
      // DISABLED: Service worker not present (causes 404)
      // this.registerServiceWorker();
      this.setupMemoryWatchdog();

      window.addEventListener('beforeunload', () => this.cleanup());

      if (window.history && window.history.pushState) {
        try {
          const self = this;
          const originalPushState = history.pushState;
          history.pushState = function () {
            originalPushState.apply(this, arguments);
            self.cleanup();
          };
        } catch (e) {
          this.logError('historyOverride', e);
        }
      }
    } catch (error) {
      try { console.error('[RTS] initialization failed:', error); } catch(e){}
      const fallbackMsg = document.createElement('div');
      fallbackMsg.innerHTML = '<p style="color:#666;padding:20px;text-align:center;">Unable to load letters system. Please refresh the page.</p>';
      const viewer = document.querySelector('.rts-letter-viewer') || document.body;
      viewer.prepend(fallbackMsg);
    }
  },

  autoConfigure() {
    const isLocalhost =
      window.location.hostname === 'localhost' ||
      window.location.hostname === '127.0.0.1';

    window.RTS_CONFIG = window.RTS_CONFIG || {};

    // IMPORTANT: many hosts/WAF/security plugins block /wp-json/* for anonymous users.
    // Default to using admin-ajax unless the site explicitly opts into REST.
    if (typeof window.RTS_CONFIG.restEnabled === 'undefined') {
      window.RTS_CONFIG.restEnabled = false;
    }

    if (!window.RTS_CONFIG.timeoutMs) {
      window.RTS_CONFIG.timeoutMs = isLocalhost ? 30000 : 9000;
    }

    // Prefer WP-localized rest base if present.
    // If not, use a safe relative default.
    if (!window.RTS_CONFIG.restBase) {
      window.RTS_CONFIG.restBase = '/wp-json/rts/v1/';
    }

    // Feature detection
    this.features.prefetch = ('fetch' in window) && ('AbortController' in window);
    this.features.toastNotifications = ('Promise' in window);
    this.features.analytics = true;

    // Optional endpoints are disabled by default unless explicitly enabled
    this.features.remoteErrorReporting = !!window.RTS_CONFIG.remoteErrorReporting;
    this.features.remotePerfReporting = !!window.RTS_CONFIG.remotePerfReporting;
  },

  addResourceHints() {
    try {
      const restBase = this.getRestBase();
      // Ensure URL() works even if restBase is relative
      const origin = new URL(restBase, window.location.origin).origin;

      const preconnect = document.createElement('link');
      preconnect.rel = 'preconnect';
      preconnect.href = origin;
      document.head.appendChild(preconnect);

      // Don't prefetch the next-letter endpoint here.
      // The endpoint is POST-only in some installs; link-prefetch uses GET.
      // Prefetching also tends to get rewritten by optimisers, producing noisy errors.
    } catch (e) {
      // No-op
    }
  },

  setupExperiments() {
    const expCookie = document.cookie.match(/rts_experiments=([^;]+)/);
    if (expCookie) {
      try {
        this.experiments = JSON.parse(decodeURIComponent(expCookie[1]));
      } catch (e) {
        this.experiments = {};
      }
    } else {
      this.experiments = {
        prefetchV2: Math.random() < 0.5,
        enhancedCache: Math.random() < 0.3,
        newOnboarding: false
      };
      document.cookie = `rts_experiments=${encodeURIComponent(JSON.stringify(this.experiments))}; max-age=604800; path=/`;
    }
  },

  ensureStyles() {
    // Only run if body is available
    if (!document.body) {
      document.addEventListener('DOMContentLoaded', () => this.ensureStyles(), { once: true });
      return;
    }

    const testId = 'rts-style-test-' + Date.now();
    const styleTest = document.createElement('div');
    styleTest.id = testId;
    styleTest.className = 'rts-visibility-test';
    styleTest.style.cssText = 'position:absolute;left:-9999px;';
    document.body.appendChild(styleTest);

    const cleanup = () => {
      const element = document.getElementById(testId);
      if (element && document.body.contains(element)) document.try { element.remove(); } catch(e) {}
    };

    setTimeout(() => {
      const element = document.getElementById(testId);
      if (!element) {
        cleanup();
        return;
      }
      const computedStyle = window.getComputedStyle(element);
      if (computedStyle.position !== 'absolute') this.injectCriticalStyles();
      cleanup();
    }, 100);
  },

  injectCriticalStyles() {
    const style = document.createElement('style');
    style.textContent = `
      .rts-letter-viewer{max-width:800px;margin:0 auto;padding:20px}
      .rts-loading,.rts-error{text-align:center;padding:40px}
      .rts-btn-next,.rts-btn-helpful,.rts-btn-unhelpful{padding:10px 20px;margin:5px;cursor:pointer}
      .rts-hidden{display:none!important}
    `;
    document.head.appendChild(style);
  },

  registerServiceWorker() {
    // Optional: only if file exists and SW is supported
    if (!('serviceWorker' in navigator)) return;
    if (this.swRegistered) return;

    // Do not hard-fail if sw file is missing
    navigator.serviceWorker.register('/sw-rts.js')
      .then(registration => {
        this.swRegistered = true;
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          if (!newWorker) return;
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              this.showHelpfulToast('New version available. Refresh to update.');
            }
          });
        });
      })
      .catch(() => { /* silent */ });

    if (!this.swMessageHandler) {
      this.swMessageHandler = (event) => {
        if (event.data && event.data.type === 'CACHE_UPDATED') this.invalidateCache();
      };
      try {
        navigator.serviceWorker.addEventListener('message', this.swMessageHandler);
      } catch(e){}
    }
  },

  setupMemoryWatchdog() {
    if (!(performance && performance.memory)) return;
    const checkMemory = () => {
      try {
        const { usedJSHeapSize, totalJSHeapSize } = performance.memory;
        if (totalJSHeapSize > 0) {
          const usagePercent = (usedJSHeapSize / totalJSHeapSize) * 100;
          if (usagePercent > 80) this.cleanupMemory();
        }
      } catch(e){}
    };
    setInterval(checkMemory, 30000);
  },

  cleanupMemory() {
    this.letterCache = {};
    if (this.errorLog.length > 10) this.errorLog = this.errorLog.slice(-10);
    this.performance.metrics = [];
    this.prefetchedLetter = null;
    if (window.gc) { try { window.gc(); } catch(e){} }
  },

  cacheDomElements() {
    this.domElements.loading = document.querySelector('.rts-loading');
    this.domElements.display = document.querySelector('.rts-letter-display');
    this.domElements.content = document.querySelector('.rts-letter-content');
    this.domElements.signature = document.querySelector('.rts-letter-signature');
    this.domElements.helpfulBtn = document.querySelector('.rts-btn-helpful');
    this.domElements.unhelpBtn = document.querySelector('.rts-btn-unhelpful');
    this.domElements.ratePrompt = document.querySelector('.rts-rate-prompt');
    this.domElements.rateUpBtn = document.querySelector('.rts-rate-up');
    this.domElements.rateDownBtn = document.querySelector('.rts-rate-down');
    this.domElements.rateSkipBtn = document.querySelector('.rts-rate-skip');
  },

  getRestBase() {
    const cfg = (typeof window.RTS_CONFIG !== 'undefined' && window.RTS_CONFIG) ? window.RTS_CONFIG : {};
    const base = (cfg.restBase || '/wp-json/rts/v1/');
    // Ensure single trailing slash
    return base.replace(/\/+$/, '') + '/';
  },

  getHeaders() {
    const cfg = (typeof window.RTS_CONFIG !== 'undefined' && window.RTS_CONFIG) ? window.RTS_CONFIG : {};
    const headers = {
      'Content-Type': 'application/json',
      'X-WP-Nonce': cfg.nonce || ''
    };
    return headers;
  },

  /**
   * AJAX fallback wrapper (admin-ajax.php)
   * Uses URL-encoded payload to avoid CORS / JSON parsing layers.
   */
  async ajaxPost(action, payload) {
    const cfg = (typeof window.RTS_CONFIG !== 'undefined' && window.RTS_CONFIG) ? window.RTS_CONFIG : {};
    const url = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
    const params = new URLSearchParams();
    params.set('action', action);
    params.set('payload', JSON.stringify(payload || {}));
    if (cfg.nonce) {
      params.set('nonce', cfg.nonce);
    }

    const controller = ('AbortController' in window) ? new AbortController() : null;
    const timeoutMs = (cfg && cfg.timeoutMs) ? cfg.timeoutMs : 10000;
    const timeoutId = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;

    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        credentials: 'same-origin',
        body: params.toString(),
        signal: controller ? controller.signal : undefined
      });
      const data = await resp.json().catch(() => ({}));
      return data;
    } finally {
      if (timeoutId) clearTimeout(timeoutId);
    }
  },

  async queueRequest(requestFn, priority = 'normal', requestId = null, timeout = 30000) {
    const reqId = requestId || (Date.now() + Math.random().toString(36).slice(2));

    const duplicate = this.requestQueue.find(item => item.requestId === reqId);
    if (duplicate) {
      return new Promise((resolve, reject) => {
        duplicate.resolveCallbacks = duplicate.resolveCallbacks || [];
        duplicate.rejectCallbacks = duplicate.rejectCallbacks || [];
        duplicate.resolveCallbacks.push(resolve);
        duplicate.rejectCallbacks.push(reject);
      });
    }

    return new Promise((resolve, reject) => {
      let timedOut = false;
      const timeoutId = setTimeout(() => {
        timedOut = true;
        reject(new Error('Request timeout'));
      }, timeout);

      const queueItem = {
        async requestFn() {
          if (timedOut) throw new Error('Request cancelled - timeout');
          clearTimeout(timeoutId);
          return await requestFn();
        },
        resolveCallbacks: [resolve],
        rejectCallbacks: [reject],
        priority,
        requestId: reqId
      };

      queueItem.resolve = (result) => {
        if (!timedOut) {
          clearTimeout(timeoutId);
          queueItem.resolveCallbacks.forEach(cb => cb(result));
        }
      };

      queueItem.reject = (error) => {
        if (!timedOut) {
          clearTimeout(timeoutId);
          queueItem.rejectCallbacks.forEach(cb => cb(error));
        }
      };

      this.requestQueue.push(queueItem);
      this.processQueue();
    });
  },

  async processQueue() {
    if (this.isProcessingQueue || this.requestQueue.length === 0) return;
    this.isProcessingQueue = true;

    try {
      this.requestQueue.sort((a, b) => {
        const order = { high: 0, normal: 1, low: 2 };
        return order[a.priority] - order[b.priority];
      });

      const item = this.requestQueue.shift();
      const { requestFn, resolve, reject, requestId } = item;
      if (requestId) this.activeRequests.add(requestId);

      try {
        const result = await requestFn();
        resolve(result);
      } catch (error) {
        reject(error);
      } finally {
        if (requestId) this.activeRequests.delete(requestId);
      }
    } finally {
      this.isProcessingQueue = false;
      if (this.requestQueue.length > 0) setTimeout(() => this.processQueue(), 0);
    }
  },

  async robustFetch(url, options = {}, maxRetries = 2, baseDelay = 700) {
    if (!this.rateLimit.canMakeRequest()) throw new Error('Rate limit exceeded. Please wait a moment.');
    if (!this.circuitBreaker.canExecute()) throw new Error('Service temporarily unavailable');

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutMs = (window.RTS_CONFIG && window.RTS_CONFIG.timeoutMs) || 10000;
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

        const response = await fetch(url, { ...options, signal: controller.signal });
        clearTimeout(timeoutId);

        if (response.ok) {
          this.circuitBreaker.onSuccess();
          return response;
        }

        if (response.status >= 400 && response.status < 500) {
          this.circuitBreaker.onSuccess();
          return response;
        }

        throw new Error(`HTTP ${response.status}`);
      } catch (error) {
        this.circuitBreaker.onFailure();
        if (attempt === maxRetries) throw error;
        const delay = baseDelay * Math.pow(2, attempt) + Math.random() * 300;
        await new Promise(res => setTimeout(res, delay));
      }
    }

    throw new Error('Failed to fetch');
  },

  // Allows basic markup, strips scripts/iframes/styles and unsafe attributes/URLs.
  sanitizeHtml(html) {
    if (!html) return '';
    const template = document.createElement('template');
    template.innerHTML = String(html);

    const dangerous = template.content.querySelectorAll('script, iframe, object, embed, style, link, meta');
    dangerous.forEach(n => n.remove());

    const all = template.content.querySelectorAll('*');
    all.forEach(el => {
      [...el.attributes].forEach(attr => {
        const name = attr.name.toLowerCase();
        const val = (attr.value || '').toLowerCase();

        if (name.startsWith('on')) el.removeAttribute(attr.name);
        if (name === 'style') el.removeAttribute(attr.name);
        if ((name === 'href' || name === 'src') && val.includes('javascript:')) el.removeAttribute(attr.name);
      });
    });

    return template.innerHTML;
  },

  startTimer(label) {
    this.performance.startTimes[label] = { start: (performance ? performance.now() : Date.now()) };
  },

  endTimer(label, success = true) {
    const timer = this.performance.startTimes[label];
    if (!timer) return;
    const now = (performance ? performance.now() : Date.now());
    const duration = now - timer.start;
    this.performance.logMetric(label, duration, success);
    delete this.performance.startTimes[label];
  },

  async healthCheck() {
    if (!this.features.healthChecks) return false;
    try {
      // Optional endpoint. If missing, we silently disable future checks.
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 2500);

      const response = await fetch(this.getRestBase() + 'health', {
        method: 'GET',
        headers: this.getHeaders(),
        credentials: 'same-origin',
        signal: controller.signal
      });

      clearTimeout(timeoutId);

      if (response.ok) {
        this.healthChecks.lastSuccessfulFetch = Date.now();
        this.healthChecks.consecutiveFailures = 0;
        this.healthChecks.isHealthy = true;
        return true;
      }

      if (response.status === 404) this.features.healthChecks = false;
    } catch (error) {
      this.healthChecks.consecutiveFailures++;
      if (this.healthChecks.consecutiveFailures > 3) {
        this.healthChecks.isHealthy = false;
        this.showDegradedMode();
      }
    }
    return false;
  },

  showDegradedMode() {
    const viewer = document.querySelector('.rts-letter-viewer');
    if (!viewer || document.querySelector('.rts-degraded-warning')) return;

    const warning = document.createElement('div');
    warning.className = 'rts-degraded-warning';
    warning.innerHTML = `
      <div style="background:#fff3cd;color:#856404;padding:10px;margin-bottom:15px;border-radius:4px;text-align:center;">
        <p style="margin:0;">⚠️ Connection issues. Trying again in the background.</p>
        <button type="button" data-rts-retry style="margin-top:5px;cursor:pointer;">Retry</button>
      </div>
    `;
    viewer.prepend(warning);

    const btn = warning.querySelector('[data-rts-retry]');
    if (btn) btn.addEventListener('click', () => location.reload());
  },

  logError(context, error, severity = 'warn') {
    const entry = {
      timestamp: new Date().toISOString(),
      context,
      error: (error && error.message) ? error.message : String(error),
      severity,
      userAgent: navigator.userAgent,
      url: window.location.href
    };

    this.errorLog.push(entry);
    if (this.errorLog.length > this.MAX_ERROR_LOG) this.errorLog.shift();

    this.maybeReportError(entry);

    // Keep console quiet in prod
    if (window.location.hostname === 'localhost') {
      try { console[severity](`[RTS] ${context}:`, error); } catch(e){}
    }
  },

  maybeReportError(entry) {
    if (!this.features.remoteErrorReporting) return;
    if (Math.random() > 0.01) return;
    if (!navigator.sendBeacon) return;

    const endpoint = this.getRestBase() + 'error';
    try { navigator.sendBeacon(endpoint, JSON.stringify(entry)); } catch(e) {}
  },

  trackError(feature) {
    this.errorCounts[feature] = (this.errorCounts[feature] || 0) + 1;
    if (this.errorCounts[feature] >= 3) this.features[feature] = false;
  },

  trackSuccess(feature) {
    if (this.errorCounts[feature]) this.errorCounts[feature] = 0;
    this.features[feature] = true;
  },

  getInactivityTime() {
    return Date.now() - (this.lastActivityTime || this.sessionStartTime);
  },

  loadState() {
    const saved = sessionStorage.getItem('rts_state');
    if (!saved) {
      this.preferences = this.getDefaultPreferences();
      this.viewedLetterIds = [];
      return;
    }

    try {
      const state = JSON.parse(saved);
      this.preferences = this.validatePreferences(state.preferences) || this.getDefaultPreferences();
      this.viewedLetterIds = Array.isArray(state.viewed) ? state.viewed : [];
    } catch (error) {
      this.resetState();
    }
  },

  getDefaultPreferences() {
    return { feelings: [], readingTime: 'long', tone: 'any', skipOnboarding: false };
  },

  validatePreferences(prefs) {
    if (!prefs || typeof prefs !== 'object') return null;
    const defaults = this.getDefaultPreferences();
    const valid = { ...defaults, ...prefs };
    if (!Array.isArray(valid.feelings)) valid.feelings = [];
    if (!['short','medium','long','any'].includes(valid.readingTime)) valid.readingTime = defaults.readingTime;
    if (typeof valid.tone !== 'string') valid.tone = defaults.tone;
    if (typeof valid.skipOnboarding !== 'boolean') valid.skipOnboarding = defaults.skipOnboarding;

    // Cache bust when preferences change
    const oldHash = JSON.stringify(this.preferences);
    const newHash = JSON.stringify(valid);
    if (oldHash !== newHash) this.invalidateCache();

    return valid;
  },

  invalidateCache() {
    this.letterCache = {};
    this.cacheVersion++;
  },

  getCacheKey() {
    if (!this._lastCacheKey || this._cacheKeyVersion !== this.cacheVersion) {
      this._lastCacheKey = JSON.stringify({
        preferences: this.preferences,
        viewed: this.viewedLetterIds,
        _v: this.cacheVersion
      });
      this._cacheKeyVersion = this.cacheVersion;
    }
    return this._lastCacheKey;
  },

  resetState() {
    this.preferences = this.getDefaultPreferences();
    this.viewedLetterIds = [];
    this.saveState();
  },

  saveState() {
    if (this.viewedLetterIds.length > this.MAX_VIEWED_IDS) {
      this.viewedLetterIds = this.viewedLetterIds.slice(-this.MAX_VIEWED_IDS);
    }
    const state = { preferences: this.preferences, viewed: this.viewedLetterIds, _v: 2 };
    try {
      sessionStorage.setItem('rts_state', JSON.stringify(state));
    } catch (error) {
      // If quota exceeded, keep it smaller
      this.viewedLetterIds = this.viewedLetterIds.slice(-50);
      state.viewed = this.viewedLetterIds;
      try { sessionStorage.setItem('rts_state', JSON.stringify(state)); } catch(e){}
    }
  },

  checkOnboarding() {
    const onboardingEl = document.querySelector('.rts-onboarding-overlay');
    const viewerEl = document.querySelector('.rts-letter-viewer');
    if (!viewerEl) return;

    if (onboardingEl) {
      const hasOnboarded = sessionStorage.getItem('rts_onboarded');
      if (!hasOnboarded && !this.preferences.skipOnboarding) {
        onboardingEl.style.display = 'flex';
        return;
      }
    }
    this.loadFirstLetter();
  },

  async loadFirstLetter() {
    if (this.loadingFirstLetter) return;
    this.loadingFirstLetter = true;
    try { await this.getNextLetter(); }
    finally { this.loadingFirstLetter = false; }
  },

  async getNextLetter() {
    return this.queueRequest(async () => {
      this.startTimer('getNextLetter');

      if (!this.isOnline) {
        this.showError('You are offline. Please check your connection.');
        this.endTimer('getNextLetter', false);
        return;
      }

      const cacheKey = this.getCacheKey();
      const cached = this.letterCache[cacheKey];
      if (cached && Date.now() - cached.timestamp < this.CACHE_TTL) {
        this.currentLetter = cached.letter;
        this.renderLetter(cached.letter);
        this.endTimer('getNextLetter');
        return;
      }

      if (this.prefetchedLetter && this.prefetchedLetter.id) {
        const letter = this.prefetchedLetter;
        this.prefetchedLetter = null;

        this.currentLetter = letter;
        this.viewedLetterIds.push(letter.id);
        this.saveState();

        this.renderLetter(letter);
        this.trackView(letter.id);

        if (this.features.prefetch) this.prefetchNextLetter();
        this.endTimer('getNextLetter');
        return;
      }

      const loadingEl = this.domElements.loading || document.querySelector('.rts-loading');
      const displayEl = this.domElements.display || document.querySelector('.rts-letter-display');

      if (loadingEl) loadingEl.style.display = 'block';
      if (displayEl) displayEl.style.display = 'none';

      try {
	        const payload = {
	          preferences: this.preferences,
	          viewed: this.viewedLetterIds,
	          timestamp: Date.now()
	        };

	        let data = {};
	        // Prefer admin-ajax by default unless REST is explicitly enabled.
	        if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
	          const response = await this.robustFetch(this.getRestBase() + 'letter/next', {
	            method: 'POST',
	            headers: this.getHeaders(),
	            credentials: 'same-origin',
	            body: JSON.stringify(payload)
	          });

	          // If REST is blocked (common with some security layers), fall back to admin-ajax.
	          if (response && (response.status === 403 || response.status === 404)) {
	            data = await this.ajaxPost('rts_get_next_letter', payload);
	          } else {
	            try { data = await response.json(); } catch (e) { data = {}; }
	          }
	        } else {
	          data = await this.ajaxPost('rts_get_next_letter', payload);
	        }

        if (data && data.success && data.letter) {
          this.currentLetter = data.letter;
          this.viewedLetterIds.push(data.letter.id);
          this.saveState();

          this.letterCache[cacheKey] = { letter: data.letter, timestamp: Date.now() };

          this.renderLetter(data.letter);
          this.trackView(data.letter.id);
          this.trackSuccess('nextLetter');

          if (this.features.prefetch) this.prefetchNextLetter();
        } else if (data && data.message) {
          this.showError(data.message);
        } else {
          this.showError('No letter available right now. Please refresh the page in a moment.');
        }
      } catch (error) {
        this.logError('getNextLetter', error, 'error');
        this.trackError('nextLetter');
        this.showError('Unable to load letter. Please check your connection and refresh the page.');
      } finally {
        if (loadingEl) loadingEl.style.display = 'none';
        if (displayEl) displayEl.style.display = 'block';
        this.endTimer('getNextLetter');
      }
    }, 'high');
  },

  async prefetchNextLetter() {
    if (!this.features.prefetch) return;
    if (this.getInactivityTime() > 30000) return;

    if (navigator.connection) {
      const conn = navigator.connection;
      if (conn.saveData || conn.effectiveType === 'slow-2g' || conn.effectiveType === '2g') return;
    }

    if (this.prefetchInFlight) return;
    if (this.prefetchedLetter && this.prefetchedLetter.id) return;

    try {
      this.prefetchInFlight = true;

      const response = await this.robustFetch(this.getRestBase() + 'letter/next', {
        method: 'POST',
        headers: this.getHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
          preferences: this.preferences,
          viewed: this.viewedLetterIds,
          timestamp: Date.now()
        })
      });

	      let data = await response.json().catch(() => ({}));
	      if ((response.status === 403 || response.status === 404) && (!data || !data.success)) {
	        data = await this.ajaxPost('rts_get_next_letter', {
	          preferences: this.preferences,
	          viewed: this.viewedLetterIds,
	          timestamp: Date.now()
	        });
	      }
      if (data && data.success && data.letter && data.letter.id) {
        if (!this.viewedLetterIds.includes(data.letter.id)) this.prefetchedLetter = data.letter;
        this.trackSuccess('prefetch');
      }
    } catch (e) {
      this.trackError('prefetch');
    } finally {
      this.prefetchInFlight = false;
    }
  },

  renderLetter(letter) {
    const loadingEl = this.domElements.loading || document.querySelector('.rts-loading');
    const displayEl = this.domElements.display || document.querySelector('.rts-letter-display');
    const contentEl = this.domElements.content || document.querySelector('.rts-letter-content');
    const signatureEl = this.domElements.signature || document.querySelector('.rts-letter-signature');

    if (!contentEl || !displayEl) return;

    // Reset per-letter UI state
    try { letter._rtsRated = false; } catch (e) {}
    this.pendingNextAfterRate = false;
    this.hideRatePrompt();

    contentEl.innerHTML = this.sanitizeHtml(letter.content);

    if (signatureEl && letter.author) {
      signatureEl.innerHTML = `<p><em>— ${this.sanitizeHtml(letter.author)}</em></p>`;
    }

    if (loadingEl) loadingEl.style.display = 'none';
    displayEl.style.display = 'block';

    displayEl.classList.remove('rts-fade-in');
    setTimeout(() => displayEl.classList.add('rts-fade-in'), 10);

    const helpfulBtn = this.domElements.helpfulBtn || document.querySelector('.rts-btn-helpful');
    const unhelpBtn = this.domElements.unhelpBtn || document.querySelector('.rts-btn-unhelpful');

    if (helpfulBtn) { helpfulBtn.classList.remove('rts-helped'); helpfulBtn.disabled = false; }
    if (unhelpBtn) { unhelpBtn.classList.remove('rts-helped'); unhelpBtn.disabled = false; }

    const fbForm = document.querySelector('.rts-feedback-form');
    if (fbForm) {
      const idField = fbForm.querySelector('input[name="letter_id"]');
      if (idField) idField.value = letter.id;
      const keepId = letter.id;
      fbForm.reset();
      if (idField) idField.value = keepId;
    }

    this.closeFeedbackModal();
  },

  async trackView(letterId) {
    if (!this.features.analytics) return;
    try {
      if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
        await this.robustFetch(this.getRestBase() + 'track/view', {
          method: 'POST',
          headers: this.getHeaders(),
          credentials: 'same-origin',
          body: JSON.stringify({ letter_id: letterId })
        });
      } else {
        await this.ajaxPost('rts_track_view', { letter_id: letterId });
      }
    } catch (error) {
      this.logError('trackView', error);
    }
  },

  async trackHelpful() {
    if (!this.currentLetter) return;

    // mark as rated for "Next" flow
    try { this.currentLetter._rtsRated = true; } catch (e) {}

    const helpfulBtn = this.domElements.helpfulBtn || document.querySelector('.rts-btn-helpful');
    if (helpfulBtn) { helpfulBtn.disabled = true; helpfulBtn.classList.add('rts-helped'); }

    try {
      if (this.features.analytics) {
        if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
          await this.robustFetch(this.getRestBase() + 'track/helpful', {
            method: 'POST',
            headers: this.getHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ letter_id: this.currentLetter.id })
          });
        } else {
          await this.ajaxPost('rts_track_helpful', { letter_id: this.currentLetter.id });
        }
      }
      this.showHelpfulToast('Thanks. That helps.');
    } catch (error) {
      this.logError('trackHelpful', error);
      this.showHelpfulToast('Thanks. That helps.');
    }

    // If the user clicked "Next" and we paused for rating, continue now
    if (this.pendingNextAfterRate) {
      this.pendingNextAfterRate = false;
      this.hideRatePrompt();
      this.getNextLetter();
    }
  },

  async trackUnhelpful() {
    if (!this.currentLetter) return;

    // mark as rated for "Next" flow
    try { this.currentLetter._rtsRated = true; } catch (e) {}

    const btn = this.domElements.unhelpBtn || document.querySelector('.rts-btn-unhelpful');
    if (btn) { btn.classList.add('rts-helped'); btn.disabled = true; }

    try {
      if (this.features.analytics) {
        if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
          await this.robustFetch(this.getRestBase() + 'track/rate', {
            method: 'POST',
            headers: this.getHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ letter_id: this.currentLetter.id, value: 'down' })
          });
        } else {
          await this.ajaxPost('rts_track_rate', { letter_id: this.currentLetter.id, value: 'down' });
        }
      }
      this.showHelpfulToast('Thanks, that helps us improve the mix.');
    } catch (error) {
      this.logError('trackUnhelpful', error);
    }

    if (this.pendingNextAfterRate) {
      this.pendingNextAfterRate = false;
      this.hideRatePrompt();
      this.getNextLetter();
    }
  },

  async trackShare(platform) {
    if (!this.currentLetter || !this.features.analytics) return;
    try {
      if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
        await this.robustFetch(this.getRestBase() + 'track/share', {
          method: 'POST',
          headers: this.getHeaders(),
          credentials: 'same-origin',
          body: JSON.stringify({ letter_id: this.currentLetter.id, platform })
        });
      } else {
        await this.ajaxPost('rts_track_share', { letter_id: this.currentLetter.id, platform });
      }
    } catch (error) {
      this.logError('trackShare', error);
    }
  },

  showHelpfulToast(message) {
    if (!this.features.toastNotifications) return;
    let toast = document.querySelector('.rts-helpful-toast');

    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'rts-helpful-toast';
      document.body.appendChild(toast);
    }

    toast.textContent = message || 'Thanks.';

    toast.style.display = 'block';
    setTimeout(() => toast.classList.add('rts-toast-show'), 10);

    setTimeout(() => {
      toast.classList.remove('rts-toast-show');
      setTimeout(() => { toast.style.display = 'none'; }, 300);
    }, 3000);
  },

  openFeedbackModal(defaultRating = 'neutral', forceTriggered = false) {
    const modal = document.getElementById('rts-feedback-modal');
    if (!modal) return;

    const form = modal.querySelector('.rts-feedback-form');
    if (form) {
      const rating = form.querySelector('select[name="rating"]');
      if (rating && ['neutral', 'up', 'down'].includes(defaultRating)) rating.value = defaultRating;

      const idField = form.querySelector('input[name="letter_id"]');
      if (idField && this.currentLetter && this.currentLetter.id) idField.value = this.currentLetter.id;

      const trig = form.querySelector('input[name="triggered"]');
      if (trig) trig.checked = !!forceTriggered;
    }

    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('rts-modal-open');

    setTimeout(() => {
      const focusTarget = modal.querySelector('#rts-feedback-rating') || modal.querySelector('button, input, select, textarea');
      if (focusTarget) focusTarget.focus();
    }, 20);
  },

  closeFeedbackModal() {
    const modal = document.getElementById('rts-feedback-modal');
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('rts-modal-open');
  },

  // ==============================
  // Rating prompt (shown on Next)
  // ==============================
  showRatePrompt() {
    const prompt = this.domElements.ratePrompt || document.querySelector('.rts-rate-prompt');
    if (!prompt) return false;
    // If we've already rated this letter, don't prompt again
    if (this.currentLetter && this.currentLetter._rtsRated) return false;

    prompt.hidden = false;
    prompt.setAttribute('aria-hidden', 'false');
    prompt.classList.add('is-open');

    // Focus the first button for keyboard users
    const focusTarget = (this.domElements.rateUpBtn || prompt.querySelector('.rts-rate-up')) || prompt.querySelector('button');
    if (focusTarget) setTimeout(() => focusTarget.focus(), 10);
    return true;
  },

  hideRatePrompt() {
    const prompt = this.domElements.ratePrompt || document.querySelector('.rts-rate-prompt');
    if (!prompt) return;
    prompt.classList.remove('is-open');
    prompt.setAttribute('aria-hidden', 'true');
    prompt.hidden = true;
  },

  async submitFeedback(formEl) {
    if (!formEl) return;

    const formData = new FormData(formEl);
    const payload = {};
    formData.forEach((v, k) => { payload[k] = v; });

    if (!payload.letter_id && this.currentLetter && this.currentLetter.id) payload.letter_id = this.currentLetter.id;
    payload.triggered = (function(){var t=formEl.querySelector('input[name="triggered"]'); return t && t.checked;})() ? '1' : '0';

    const submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      let data = null;

      if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
        const response = await this.robustFetch(this.getRestBase() + 'feedback/submit', {
          method: 'POST',
          headers: this.getHeaders(),
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        });
        data = await response.json().catch(() => ({}));
        if (!response.ok) {
          data = data || {};
          data.success = false;
        }
      } else {
        data = await this.ajaxPost('rts_submit_feedback', payload);
      }

      if (!data || !data.success) {
        const msg = (data && data.message) ? data.message : 'Could not send feedback. Please try again.';
        this.showHelpfulToast(msg);
        if (submitBtn) submitBtn.disabled = false;
        return;
      }

      this.showHelpfulToast('Thanks. Your feedback has been received.');
      this.closeFeedbackModal();

      const id = payload.letter_id || '';
      formEl.reset();
      const idField = formEl.querySelector('input[name="letter_id"]');
      if (idField) idField.value = id;
    } catch (err) {
      this.logError('submitFeedback', err);
      this.showHelpfulToast('Network issue. Please try again.');
      if (submitBtn) submitBtn.disabled = false;
    }
  },

  showError(message) {
    const loadingEl = document.querySelector('.rts-loading');
    if (!loadingEl) return;

    const safe = this.sanitizeHtml(message);
    let html = `<p style="color:#d32f2f;">${safe}</p>`;

    html += `
      <div style="margin-top:10px;font-size:0.9em;color:#666;">
        <p style="margin-bottom:5px;">Try:</p>
        <ul style="text-align:left;padding-left:20px;margin-top:0;">
          <li>Refreshing the page</li>
          <li>Checking your internet connection</li>
          <li>Clearing browser cache</li>
        </ul>
      </div>
    `;

    loadingEl.innerHTML = html;
  },

  completeOnboarding() {
    const feelingCheckboxes = document.querySelectorAll('input[name="feelings[]"]:checked');
    this.preferences.feelings = Array.from(feelingCheckboxes).map(cb => cb.value);

    const readingTimeRadio = document.querySelector('input[name="readingTime"]:checked');
    if (readingTimeRadio) this.preferences.readingTime = readingTimeRadio.value;

    const toneRadio = document.querySelector('input[name="tone"]:checked');
    if (toneRadio) this.preferences.tone = toneRadio.value;

    this.saveState();
    sessionStorage.setItem('rts_onboarded', 'true');

    const onboardingEl = document.querySelector('.rts-onboarding-overlay');
    if (onboardingEl) onboardingEl.style.display = 'none';

    this.loadFirstLetter();
  },

  skipOnboarding() {
    this.preferences.skipOnboarding = true;
    sessionStorage.setItem('rts_onboarded', 'true');

    const onboardingEl = document.querySelector('.rts-onboarding-overlay');
    if (onboardingEl) onboardingEl.style.display = 'none';

    this.loadFirstLetter();
  },

  nextOnboardingStep(currentStep) {
    const steps = document.querySelectorAll('.rts-onboarding-step');
    steps.forEach(step => (step.style.display = 'none'));

    const nextStep = currentStep + 1;
    const nextStepEl = document.querySelector(`.rts-onboarding-step[data-step="${nextStep}"]`);
    if (nextStepEl) nextStepEl.style.display = 'block';
  },

  async submitLetter(formData) {
    const submitBtn = document.querySelector('.rts-btn-submit');
    const btnText = (submitBtn ? submitBtn.querySelector('.rts-btn-text') : null);
    const btnSpinner = (submitBtn ? submitBtn.querySelector('.rts-btn-spinner') : null);
    const responseEl = document.querySelector('.rts-form-response');

    const letterText = formData.get('letter_text');
    if (!letterText || letterText.length < 50) {
      if (responseEl) responseEl.innerHTML = '<div class="rts-error">Letter must be at least 50 characters.</div>';
      return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (btnText) btnText.style.display = 'none';
    if (btnSpinner) btnSpinner.style.display = 'inline';
    if (responseEl) responseEl.innerHTML = '';

    try {
      const response = await this.robustFetch(this.getRestBase() + 'letter/submit', {
        method: 'POST',
        headers: this.getHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
          author_name: formData.get('author_name'),
          letter_text: letterText,
          author_email: formData.get('author_email'),
          website: formData.get('website')
        })
      });

      const data = await response.json().catch(() => ({}));

      if (response.ok && data && data.success) {
        const formWrap = document.querySelector('.rts-submit-form-wrapper form');
        const success = document.querySelector('.rts-submit-success');
        if (formWrap) formWrap.style.display = 'none';
        if (success) success.style.display = 'block';
      } else {
        if (responseEl) responseEl.innerHTML = `<div class="rts-error">${this.sanitizeHtml(data.message || 'Failed to submit. Please try again.')}</div>`;
        if (submitBtn) submitBtn.disabled = false;
        if (btnText) btnText.style.display = 'inline';
        if (btnSpinner) btnSpinner.style.display = 'none';
      }
    } catch (error) {
      this.logError('submitLetter', error);
      if (responseEl) responseEl.innerHTML = '<div class="rts-error">Failed to submit. Please check your connection and try again.</div>';

      if (submitBtn) submitBtn.disabled = false;
      if (btnText) btnText.style.display = 'inline';
      if (btnSpinner) btnSpinner.style.display = 'none';
    }
  },

  cleanup() {
    this.eventListeners.forEach(({ element, type, handler }) => {
      try { element.removeEventListener(type, handler); } catch(e){}
    });
    this.eventListeners = [];
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
  },

  bindEvents() {
    ['click', 'scroll', 'keydown', 'mousemove'].forEach(event => {
      const handler = () => { this.lastActivityTime = Date.now(); };
      document.addEventListener(event, handler, { passive: true });
      this.eventListeners.push({ element: document, type: event, handler });
    });

    const onlineHandler = () => {
      this.isOnline = true;
      this.showHelpfulToast('Connection restored');
      this.healthCheck();
    };
    window.addEventListener('online', onlineHandler);
    this.eventListeners.push({ element: window, type: 'online', handler: onlineHandler });

    const offlineHandler = () => {
      this.isOnline = false;
      this.showHelpfulToast('You are offline. Some features may be limited.');
    };
    window.addEventListener('offline', offlineHandler);
    this.eventListeners.push({ element: window, type: 'offline', handler: offlineHandler });

    const clickHandler = (e) => {
      // Next: prompt for a quick thumbs up/down, then load the next letter.
      if (e.target.closest('.rts-btn-next')) {
        e.preventDefault();
        if (this.currentLetter && !this.currentLetter._rtsRated && this.domElements.ratePrompt) {
          this.pendingNextAfterRate = true;
          this.showRatePrompt();
          return;
        }
        this.getNextLetter();
        return;
      }

      // Rating prompt buttons
      if (e.target.closest('.rts-rate-up')) { e.preventDefault(); this.trackHelpful(); return; }
      if (e.target.closest('.rts-rate-down')) { e.preventDefault(); this.trackUnhelpful(); return; }
      if (e.target.closest('.rts-rate-skip')) {
        e.preventDefault();
        this.hideRatePrompt();
        if (this.pendingNextAfterRate) { this.pendingNextAfterRate = false; this.getNextLetter(); }
        return;
      }

      // Backwards compatible (older templates)
      if (e.target.closest('.rts-btn-helpful')) { e.preventDefault(); this.trackHelpful(); return; }
      if (e.target.closest('.rts-btn-unhelpful')) { e.preventDefault(); this.trackUnhelpful(); return; }

      const shareBtn = e.target.closest('.rts-share-btn');
      if (shareBtn) {
        const platform = shareBtn.dataset.platform;
        if (platform) this.trackShare(platform);
        return;
      }

      if (e.target.closest('.rts-btn-skip')) { e.preventDefault(); this.skipOnboarding(); return; }

      const nextStepBtn = e.target.closest('.rts-btn-next-step');
      if (nextStepBtn) {
        e.preventDefault();
        const stepEl = nextStepBtn.closest('.rts-onboarding-step');
        const currentStep = parseInt((stepEl && stepEl.dataset ? stepEl.dataset.step : undefined) || '0', 10);
        if (currentStep) this.nextOnboardingStep(currentStep);
        return;
      }

      if (e.target.closest('.rts-btn-complete')) { e.preventDefault(); this.completeOnboarding(); return; }

      const openBtn = e.target.closest('.rts-feedback-open');
      if (openBtn) {
        e.preventDefault();
        let defaultRating = 'neutral';
        if (document.querySelector('.rts-btn-helpful.rts-helped')) defaultRating = 'up';
        if (document.querySelector('.rts-btn-unhelpful.rts-helped')) defaultRating = 'down';
        this.openFeedbackModal(defaultRating);
        return;
      }

      const triggerBtn = e.target.closest('.rts-trigger-open');
      if (triggerBtn) { e.preventDefault(); this.openFeedbackModal('down', true); return; }

      if (e.target.closest('[data-rts-close]')) { e.preventDefault(); this.closeFeedbackModal(); return; }
    };

    document.addEventListener('click', clickHandler);
    this.eventListeners.push({ element: document, type: 'click', handler: clickHandler });

    const keydownHandler = (e) => {
      if (e.key !== 'Escape') return;
      const modal = document.getElementById('rts-feedback-modal');
      if (modal && modal.getAttribute('aria-hidden') === 'false') this.closeFeedbackModal();
    };
    document.addEventListener('keydown', keydownHandler);
    this.eventListeners.push({ element: document, type: 'keydown', handler: keydownHandler });

    const submitHandler = (e) => {
      const form = e.target.closest('.rts-feedback-form');
      if (!form) return;
      e.preventDefault();
      this.submitFeedback(form);
    };
    document.addEventListener('submit', submitHandler);
    this.eventListeners.push({ element: document, type: 'submit', handler: submitHandler });

    const submitForm = document.getElementById('rts-submit-form');
    if (submitForm) {
      const textarea = submitForm.querySelector('#rts-letter-text');
      const charCount = submitForm.querySelector('.rts-char-count');

      if (textarea && charCount) {
        const inputHandler = () => {
          clearTimeout(this.debounceTimer);
          this.debounceTimer = setTimeout(() => {
            const count = textarea.value.length;
            charCount.textContent = `${count} characters ${count < 50 ? '(minimum 50)' : ''}`;
            charCount.style.color = count >= 50 ? '#2e7d32' : '#666';
          }, 100);
        };
        textarea.addEventListener('input', inputHandler);
        this.eventListeners.push({ element: textarea, type: 'input', handler: inputHandler });
      }

      const formSubmitHandler = (e) => {
        e.preventDefault();
        const fd = new FormData(submitForm);
        this.submitLetter(fd);
      };
      submitForm.addEventListener('submit', formSubmitHandler);
      this.eventListeners.push({ element: submitForm, type: 'submit', handler: formSubmitHandler });
    }
  }
};

// Auto-initialize
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => RTSLetterSystem.init());
} else {
  RTSLetterSystem.init();
}
