/**
 * Reasons to Stay - Main JavaScript
 * Handles letter viewer, onboarding, and form submission
 * v2.3.1 - Enterprise Edition (Rocket Loader & Duplicate Safe)
 *
 * Notes:
 * - Fixes "Identifier has already been declared" by using window assignment
 * - Handles Rocket Loader double-execution gracefully
 * - Forces UI updates for closing modals
 */

(function() {
    // PREVENT DOUBLE LOADING (Rocket Loader Safe)
    if (window.RTSLetterSystem && window.RTSLetterSystem.loaded) {
        // System already loaded, skip duplicate execution
        return;
    }

    // Main System Definition attached directly to window
    window.RTSLetterSystem = {
      loaded: true, // Flag to prevent re-init

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
      pendingRequests: new Map(),
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

        BUDGETS: {
          render: 100,
          network: 2000,
          total: 3000
        },

        checkBudget(label, duration) {
          const budget = (this.BUDGETS && this.BUDGETS[label]) ? this.BUDGETS[label] : 1000;
          if (duration > budget) {
            this.logMetric('BUDGET_EXCEEDED_' + label, duration, false);
            if (label === 'render' && duration > 500) {
              try { document.documentElement.classList.add('rts-no-animations'); } catch(e){}
            }
          }
        },

        BUDGETS: {
          render: 100,
          network: 2000,
          total: 3000
        },

        checkBudget(label, duration) {
          const budget = (this.BUDGETS && this.BUDGETS[label]) ? this.BUDGETS[label] : 1000;
          if (duration > budget) {
            this.logMetric('BUDGET_EXCEEDED_' + label, duration, false);
            if (label === 'render' && duration > 500) {
              try { document.documentElement.classList.add('rts-no-animations'); } catch(e){}
            }
          }
        },

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
          const root = (window && window.RTSLetterSystem) ? window.RTSLetterSystem : {};
          const features = (root && root.features) ? root.features : {};
          if (!features.remotePerfReporting) return;
          if (!navigator.sendBeacon) return;

          const restBase = (root && typeof root.getRestBase === 'function') ? root.getRestBase() : '';
          if (!restBase) return;
          const endpoint = restBase + 'performance';
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
        if (window.location.hostname === 'localhost') {
        }

        try {
          this.autoConfigure();
          this.setupExperiments();
          this.ensureStyles();
          this.setupPerformanceTracking();
          this.addResourceHints();

          this.sessionStartTime = Date.now();
          this.lastActivityTime = Date.now();

          this.cacheDomElements();
          this.loadState();
          this.checkOnboarding();
          this.bindEvents();

          // SW is optional
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

        if (typeof window.RTS_CONFIG.restEnabled === 'undefined') {
          window.RTS_CONFIG.restEnabled = false;
        }

        if (!window.RTS_CONFIG.timeoutMs) {
          window.RTS_CONFIG.timeoutMs = isLocalhost ? 30000 : 9000;
        }

        if (!window.RTS_CONFIG.restBase) {
          window.RTS_CONFIG.restBase = '/wp-json/rts/v1/';
        }

        this.features.prefetch = ('fetch' in window) && ('AbortController' in window);
        this.features.toastNotifications = ('Promise' in window);
        this.features.analytics = true;
        this.features.remoteErrorReporting = !!window.RTS_CONFIG.remoteErrorReporting;
        this.features.remotePerfReporting = !!window.RTS_CONFIG.remotePerfReporting;
      },

      addResourceHints() {
        try {
          const restBase = this.getRestBase();
          const origin = new URL(restBase, window.location.origin).origin;
          const preconnect = document.createElement('link');
          preconnect.rel = 'preconnect';
          preconnect.href = origin;
          document.head.appendChild(preconnect);
        } catch (e) { }
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
          if (element && document.body.contains(element)) {
            try { element.remove(); } catch(e) {}
          }
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

          /* Performance optimizations */
          .rts-letter-body{
            contain: content;
            will-change: opacity;
          }
          .rts-letter-display{
            backface-visibility: hidden;
            transform: translateZ(0);
          }
          .rts-fade-in{
            opacity:0;
            animation: rtsFadeIn .3s ease forwards;
          }
          @keyframes rtsFadeIn{to{opacity:1}}
        `;
        document.head.appendChild(style);
      },


      setupPerformanceTracking() {
        if (!('PerformanceObserver' in window)) return;
        try {
          // Largest Contentful Paint
          const lcpObserver = new PerformanceObserver((entryList) => {
            const entries = entryList.getEntries();
            const last = entries && entries.length ? entries[entries.length - 1] : null;
            if (last && this.performance && typeof this.performance.logMetric === 'function') {
              this.performance.logMetric('LCP', last.startTime, true);
            }
          });
          lcpObserver.observe({ entryTypes: ['largest-contentful-paint'] });

          // First Input Delay (where available)
          const fidObserver = new PerformanceObserver((entryList) => {
            for (const entry of entryList.getEntries()) {
              if (this.performance && typeof this.performance.logMetric === 'function') {
                this.performance.logMetric('FID', entry.duration || 0, true);
              }
            }
          });
          fidObserver.observe({ entryTypes: ['first-input'] });
        } catch (e) {}
      },


      registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        if (this.swRegistered) return;

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
          .catch(() => { });

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

      optimizeMemory() {
        const cacheKeys = Object.keys(this.letterCache || {});
        if (cacheKeys.length > 20) {
          const sorted = cacheKeys.sort((a, b) => (this.letterCache[b]?.timestamp || 0) - (this.letterCache[a]?.timestamp || 0));
          for (let i = 10; i < sorted.length; i++) {
            try { delete this.letterCache[sorted[i]]; } catch (e) {}
          }
        }

        if (this.performance && Array.isArray(this.performance.metrics) && this.performance.metrics.length > 50) {
          this.performance.metrics = this.performance.metrics.slice(-50);
        }

        if (Array.isArray(this.errorLog) && this.errorLog.length > 20) {
          this.errorLog = this.errorLog.slice(-20);
        }
      },

      
cleanupMemory() {
        this.optimizeMemory();
        this.prefetchedLetter = null;
        if (window.gc) { try { window.gc(); } catch(e){} }
      },

      
cacheDomElements() {
        // Use single query for frequently accessed elements
        const container = document.querySelector('.rts-letter-viewer');

        if (container) {
          this.domElements.loading = container.querySelector('.rts-loading');
          this.domElements.display = container.querySelector('.rts-letter-display');
          this.domElements.content = container.querySelector('.rts-letter-body');
          this.domElements.helpfulBtn = container.querySelector('.rts-btn-helpful');
          this.domElements.unhelpBtn = container.querySelector('.rts-btn-unhelpful');
          this.domElements.ratePrompt = container.querySelector('.rts-rate-prompt');
          this.domElements.rateUpBtn = container.querySelector('.rts-rate-up');
          this.domElements.rateDownBtn = container.querySelector('.rts-rate-down');
          this.domElements.rateSkipBtn = container.querySelector('.rts-rate-skip');
        }

        // Fallback to document-wide search only if needed
        if (!this.domElements.display) {
          this.domElements.display = document.querySelector('.rts-letter-display');
        }
        if (!this.domElements.content) {
          this.domElements.content = document.querySelector('.rts-letter-body');
        }

        // One-time fallback checks (avoid recurring timeouts)
        if (!this.domElements.ratePrompt) {
          this.domElements.ratePrompt = document.querySelector('.rts-rate-prompt');
        }
        if (!this.domElements.helpfulBtn) {
          this.domElements.helpfulBtn = document.querySelector('.rts-btn-helpful');
        }
        if (!this.domElements.unhelpBtn) {
          this.domElements.unhelpBtn = document.querySelector('.rts-btn-unhelpful');
        }
      },

      getRestBase() {
        const cfg = (typeof window.RTS_CONFIG !== 'undefined' && window.RTS_CONFIG) ? window.RTS_CONFIG : {};
        const base = (cfg.restBase || '/wp-json/rts/v1/');
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

      
      async parseJson(resp) {
        const ct = (resp.headers && resp.headers.get) ? (resp.headers.get('content-type') || '') : '';
        // If it's JSON, parse normally (WP REST typically returns application/json; charset=UTF-8)
        if (ct.toLowerCase().includes('application/json')) {
          return await resp.json();
        }

        // Some hosts/WAFs return HTML for 403/500 or a login page. Capture a small snippet for debugging.
        const text = await resp.text().catch(() => '');
        const snippet = (text || '').toString().slice(0, 400).replace(/\s+/g, ' ').trim();
        const err = new Error(`Non-JSON response (HTTP ${resp.status})`);
        err.http_status = resp.status;
        err.body_snippet = snippet;
        throw err;
      },

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
          const data = await this.parseJson(resp).catch(() => ({}));
          return data;
        } finally {
          if (timeoutId) clearTimeout(timeoutId);
        }
      },






      async queueRequest(requestFn, priority = 'normal', requestId = null, timeout = 30000) {
        const reqId = requestId || (Date.now() + Math.random().toString(36).slice(2));

        if (this.pendingRequests && this.pendingRequests.has(reqId)) {
          return this.pendingRequests.get(reqId);
        }

        const duplicate = this.requestQueue.find(item => item.requestId === reqId);
        if (duplicate) {
          return new Promise((resolve, reject) => {
            duplicate.resolveCallbacks = duplicate.resolveCallbacks || [];
            duplicate.rejectCallbacks = duplicate.rejectCallbacks || [];
            duplicate.resolveCallbacks.push(resolve);
            duplicate.rejectCallbacks.push(reject);
          });
        }

        const promise = new Promise((resolve, reject) => {
          let timedOut = false;

          const timeoutId = setTimeout(() => {
            timedOut = true;
            try { this.pendingRequests && this.pendingRequests.delete(reqId); } catch(e){}
            reject(new Error('Request timeout'));
          }, timeout);

          const queueItem = {
            priority,
            requestId: reqId,
            resolveCallbacks: [resolve],
            rejectCallbacks: [reject],
            requestFn: async () => {
              if (timedOut) throw new Error('Request cancelled - timeout');
              return await requestFn();
            }
          };

          queueItem.resolve = (result) => {
            if (timedOut) return;
            clearTimeout(timeoutId);
            try { this.pendingRequests && this.pendingRequests.delete(reqId); } catch(e){}
            (queueItem.resolveCallbacks || []).forEach(cb => { try { cb(result); } catch(e){} });
          };

          queueItem.reject = (error) => {
            if (timedOut) return;
            clearTimeout(timeoutId);
            try { this.pendingRequests && this.pendingRequests.delete(reqId); } catch(e){}
            (queueItem.rejectCallbacks || []).forEach(cb => { try { cb(error); } catch(e){} });
          };

          this.requestQueue.push(queueItem);
          this.processQueue();
        });

        try { this.pendingRequests && this.pendingRequests.set(reqId, promise); } catch(e){}
        return promise;
      },

      
async processQueue() {
        if (this.isProcessingQueue || this.requestQueue.length === 0) return;
        this.isProcessingQueue = true;

        try {
          // Sort only when needed
          if (this.requestQueue.length > 1) {
            this.requestQueue.sort((a, b) => {
              const order = { high: 0, normal: 1, low: 2 };
              return (order[a.priority] ?? 1) - (order[b.priority] ?? 1);
            });
          }

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
          // Microtask continuation (faster than setTimeout(0))
          Promise.resolve().then(() => {
            if (this.requestQueue.length > 0) this.processQueue();
          });
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


      sanitizeHtml(html) {
        if (!html) return '';

        // Fast path for plain text (no tags)
        const str = String(html);
        if (!/<[a-z][\s\S]*>/i.test(str)) return str;

        const template = document.createElement('template');
        template.innerHTML = str;

        // Remove dangerous nodes in one sweep
        const dangerous = template.content.querySelectorAll('script, iframe, object, embed, style, link, meta');
        if (dangerous.length) {
          for (let i = dangerous.length - 1; i >= 0; i--) {
            dangerous[i].remove();
          }
        }

        // Strip dangerous attributes
        const all = template.content.querySelectorAll('*');
        if (all.length) {
          for (const el of all) {
            const attrs = el.attributes;
            for (let i = attrs.length - 1; i >= 0; i--) {
              const attr = attrs[i];
              const name = attr.name.toLowerCase();
              const val  = String(attr.value || '').toLowerCase();

              if (name.startsWith('on') || name === 'style') {
                el.removeAttribute(attr.name);
              } else if ((name === 'href' || name === 'src') && val.includes('javascript:')) {
                el.removeAttribute(attr.name);
              }
            }
          }
        }

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
        const lastViewed = this.viewedLetterIds.length
          ? this.viewedLetterIds[this.viewedLetterIds.length - 1]
          : 0;

        const viewedLen = this.viewedLetterIds.length;

        // Stable string for hashing (avoid JSON.stringify on whole prefs object)
        const feelingsStr = (this.preferences && Array.isArray(this.preferences.feelings))
          ? this.preferences.feelings.join(',')
          : '';
        const readingTime = (this.preferences && this.preferences.readingTime) ? this.preferences.readingTime : 'long';
        const tone = (this.preferences && this.preferences.tone) ? this.preferences.tone : 'any';
        const skip = (this.preferences && this.preferences.skipOnboarding) ? '1' : '0';

        // Simple 32-bit hash (fast)
        const prefStr = `${feelingsStr}|${readingTime}|${tone}|${skip}`;
        let hash = 0;
        for (let i = 0; i < prefStr.length; i++) {
          hash = ((hash << 5) - hash) + prefStr.charCodeAt(i);
          hash |= 0; // force 32-bit
        }

        if (
          !this._lastCacheKey ||
          this._cacheKeyVersion !== this.cacheVersion ||
          this._cacheKeyLastViewed !== lastViewed ||
          this._cacheKeyViewedLen !== viewedLen ||
          this._cacheKeyPrefHash !== hash
        ) {
          this._lastCacheKey = `${hash}:${readingTime}:${tone}:${lastViewed}:${viewedLen}:v${this.cacheVersion}`;
          this._cacheKeyVersion = this.cacheVersion;
          this._cacheKeyLastViewed = lastViewed;
          this._cacheKeyViewedLen = viewedLen;
          this._cacheKeyPrefHash = hash;
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
          this.viewedLetterIds = this.viewedLetterIds.slice(-50);
          state.viewed = this.viewedLetterIds;
          try { sessionStorage.setItem('rts_state', JSON.stringify(state)); } catch(e){}
        }
      },

      checkOnboarding() {
        const onboardingEl = document.querySelector('.rts-onboarding-overlay');
        const viewerEl = document.querySelector('.rts-letter-viewer');
        if (!viewerEl) return;

        const hasOnboarded = sessionStorage.getItem('rts_onboarded');

        if (onboardingEl) {
          if (!hasOnboarded && !this.preferences.skipOnboarding) {
            onboardingEl.style.display = 'flex';
            return; 
          } else {
            onboardingEl.style.display = 'none'; 
          }
        }
        
        this.loadFirstLetter();
      },

      async loadFirstLetter() {
        if (this.loadingFirstLetter) return;
        this.loadingFirstLetter = true;
        try { await this.getNextLetter(false); }
        finally { this.loadingFirstLetter = false; }
      },

      // forceFresh=true skips any cached letter for the current state and always requests a new one.
      async getNextLetter(forceFresh = false) {
        const onboardingEl = document.querySelector('.rts-onboarding-overlay');
        if (onboardingEl && sessionStorage.getItem('rts_onboarded')) {
            onboardingEl.style.display = 'none';
        }

        return this.queueRequest(async () => {
          this.startTimer('getNextLetter');

          if (!this.isOnline) {
            this.showError('You are offline. Please check your connection.');
            this.endTimer('getNextLetter', false);
            return;
          }

          const cacheKey = this.getCacheKey();

          // If user explicitly asked for another letter, never serve from cache.
          if (forceFresh && this.letterCache && this.letterCache[cacheKey]) {
            try { delete this.letterCache[cacheKey]; } catch (e) {}
          }

          const cached = this.letterCache[cacheKey];
          if (!forceFresh && cached && Date.now() - cached.timestamp < this.CACHE_TTL) {
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

          let loadedOk = false;
          try {
                const payload = {
                  preferences: this.preferences,
                  viewed: this.viewedLetterIds,
                  timestamp: Date.now()
                };

                let data = {};
                if (window.RTS_CONFIG && window.RTS_CONFIG.restEnabled) {
                  const response = await this.robustFetch(this.getRestBase() + 'letter/next', {
                    method: 'POST',
                    headers: this.getHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                  });

                  if (response && response.ok) {
                      try {
                          data = await this.parseJson(response);
                      } catch (e) {
                          data = { success: false, message: 'Invalid response format' };
                      }
                  } else if (response && (response.status === 403 || response.status === 404)) {
                      data = await this.ajaxPost('rts_get_next_letter', payload);
                  } else {
                      data = { success: false, message: `HTTP ${response.status}` };
                  }
                } else {
                  data = await this.ajaxPost('rts_get_next_letter', payload);
                }

            // Normalize response shapes from REST and admin-ajax.
            // Supported:
            // 1) { success: true, letter: {...} }
            // 2) wp_send_json_success: { success: true, data: { letter: {...} } }
            // 3) REST: { ok: true, letter: {...} }
            const normalized = {
              ok: (data && (data.success === true || data.ok === true)) ? true : false,
              letter: (data && data.letter) ? data.letter : (data && data.data && data.data.letter) ? data.data.letter : null,
              message: (data && data.message) ? data.message : (data && data.data && data.data.message) ? data.data.message : null
            };

            if (normalized.ok && normalized.letter) {
              this.currentLetter = normalized.letter;
              this.viewedLetterIds.push(normalized.letter.id);
              this.saveState();

              this.letterCache[cacheKey] = { letter: normalized.letter, timestamp: Date.now() };

              this.renderLetter(normalized.letter);
              this.trackView(normalized.letter.id);
              this.trackSuccess('nextLetter');

              loadedOk = true;

              if (this.features.prefetch) this.prefetchNextLetter();
            } else if (normalized.message) {
              this.showError(normalized.message);
            } else {
              this.showError('No letter available right now. Please refresh the page in a moment.');
            }
          } catch (error) {
            this.logError('getNextLetter', error, 'error');
            this.trackError('nextLetter');
            this.showError('Unable to load letter. Please check your connection and refresh the page.');
          } finally {
            // Only reveal the letter display when we actually have a letter.
            // Otherwise keep the loading panel visible so errors are readable.
            if (loadedOk) {
              if (loadingEl) loadingEl.style.display = 'none';
              if (displayEl) displayEl.style.display = 'block';
            } else {
              if (loadingEl) loadingEl.style.display = 'block';
              if (displayEl) displayEl.style.display = 'none';
            }
            this.endTimer('getNextLetter');
          }
        }, 'high');
      },
      getConnectionInfo() {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return {
          effectiveType: (conn && conn.effectiveType) ? conn.effectiveType : '4g',
          saveData: !!(conn && conn.saveData),
          rtt: (conn && typeof conn.rtt === 'number') ? conn.rtt : 100,
          downlink: (conn && typeof conn.downlink === 'number') ? conn.downlink : 10
        };
      },

      async prefetchNextLetter() {
        if (!this.features.prefetch) return;

        const connection = this.getConnectionInfo();

        // Don't prefetch if user is idle or connection is constrained
        if (this.getInactivityTime() > 30000) return;
        if (connection.saveData || connection.effectiveType === 'slow-2g') return;

        // Adaptive throttling based on connection quality
        if (connection.effectiveType === '2g' && Math.random() < 0.7) return;
        if (connection.effectiveType === '3g' && Math.random() < 0.3) return;

        if (this.prefetchInFlight) return;
        if (this.prefetchedLetter && this.prefetchedLetter.id) return;

        try {
          this.prefetchInFlight = true;
          const prefetchLetter = await this.queueRequest(() => this.fetchNextLetter(), 'low', 'prefetch', 15000);
          if (prefetchLetter && prefetchLetter.id && (!this.viewedLetterIds || !this.viewedLetterIds.includes(prefetchLetter.id))) {
            this.prefetchedLetter = prefetchLetter;
            this.trackSuccess('prefetch');
          }
        } catch (e) {
          this.trackError('prefetch');
        } finally {
          this.prefetchInFlight = false;
        }
      },




      progressiveRender(contentEl, html) {
        // Fast path for small payloads
        if (typeof html === 'string' && html.length < 1000) {
          contentEl.innerHTML = html;
          return;
        }

        const fragment = document.createDocumentFragment();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;

        const children = tempDiv.children;
        const total = children.length;

        // Clear once
        contentEl.textContent = '';

        if (total > 10) {
          // First batch
          for (let i = 0; i < Math.min(5, total); i++) {
            fragment.appendChild(children[i].cloneNode(true));
          }
          contentEl.appendChild(fragment);

          const renderRemaining = () => {
            const remaining = document.createDocumentFragment();
            for (let i = 5; i < total; i++) {
              remaining.appendChild(children[i].cloneNode(true));
            }
            contentEl.appendChild(remaining);
            tempDiv.innerHTML = '';
          };

          if (window.requestIdleCallback) {
            window.requestIdleCallback(renderRemaining, { timeout: 100 });
          } else {
            setTimeout(renderRemaining, 0);
          }
        } else {
          while (tempDiv.firstChild) fragment.appendChild(tempDiv.firstChild);
          contentEl.appendChild(fragment);
          tempDiv.innerHTML = '';
        }
      },


      renderLetter(letter) {
        const renderStart = (window.performance && performance.now) ? performance.now() : Date.now();
        const loadingEl = this.domElements.loading || document.querySelector('.rts-loading');
        const displayEl = this.domElements.display || document.querySelector('.rts-letter-display');
        const contentEl = this.domElements.content || document.querySelector('.rts-letter-body');
        const signatureEl = null;

        if (!contentEl || !displayEl) {
            console.error('RTS: Required DOM elements not found for rendering');
            return;
        }

        try { letter._rtsRated = false; } catch (e) {}
        this.pendingNextAfterRate = false;
        this.hideRatePrompt();

        // Use requestAnimationFrame for smoother rendering and reduced layout thrashing
        requestAnimationFrame(() => {
            // Batch visibility changes
            if (loadingEl) loadingEl.style.display = 'none';
            displayEl.style.display = 'block';
            
            // Use progressive rendering for large letters
            const sanitized = this.sanitizeHtml(letter.content);
            this.progressiveRender(contentEl, sanitized);
            this.setupLazyLoading(contentEl);

            // Animation with proper timing for smoother transitions
            displayEl.classList.remove('rts-fade-in');
            setTimeout(() => {
                displayEl.classList.add('rts-fade-in');
            }, 16); // ~1 frame delay (16ms at 60fps)
            
            // Update button states
            const helpfulBtn = this.domElements.helpfulBtn || document.querySelector('.rts-btn-helpful');
            const unhelpBtn = this.domElements.unhelpBtn || document.querySelector('.rts-btn-unhelpful');
            
            if (helpfulBtn) {
                helpfulBtn.classList.remove('rts-helped');
                helpfulBtn.disabled = false;
            }
            if (unhelpBtn) {
                unhelpBtn.classList.remove('rts-helped');
                unhelpBtn.disabled = false;
            }
            
            // Batch feedback form updates
            const fbForm = document.querySelector('.rts-feedback-form');
            if (fbForm) {
                const idField = fbForm.querySelector('input[name="letter_id"]');
                if (idField) idField.value = letter.id;
                const keepId = letter.id;
                fbForm.reset();
                if (idField) idField.value = keepId;
            }
            
            this.closeFeedbackModal();
        
            const renderEnd = (window.performance && performance.now) ? performance.now() : Date.now();
            try {
              if (this.performance && typeof this.performance.checkBudget === 'function') {
                this.performance.checkBudget('render', renderEnd - renderStart);
              }
            } catch(e) {}
});
      },

      setupLazyLoading(scopeEl) {
        try {
          const root = scopeEl || document;
          const imgs = root.querySelectorAll ? root.querySelectorAll('img[data-src], img[data-rts-src]') : [];
          if (!imgs || !imgs.length) return;

          // Simple fallback: eager swap if IntersectionObserver not available
          if (!('IntersectionObserver' in window)) {
            imgs.forEach(img => {
              const src = img.getAttribute('data-src') || img.getAttribute('data-rts-src');
              if (src) {
                img.setAttribute('src', src);
                img.removeAttribute('data-src');
                img.removeAttribute('data-rts-src');
              }
            });
            return;
          }

          const io = new IntersectionObserver(entries => {
            entries.forEach(entry => {
              if (!entry.isIntersecting) return;
              const img = entry.target;
              const src = img.getAttribute('data-src') || img.getAttribute('data-rts-src');
              if (src) {
                img.setAttribute('src', src);
                img.removeAttribute('data-src');
                img.removeAttribute('data-rts-src');
              }
              io.unobserve(img);
            });
          }, { rootMargin: '200px 0px' });

          imgs.forEach(img => io.observe(img));
        } catch(e) {}
      },

      async trackView(letterId) {
        if (!this.features.analytics || !letterId) return;
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

        if (this.pendingNextAfterRate) {
          this.pendingNextAfterRate = false;
          this.hideRatePrompt();
          this.getNextLetter(true);
        }
      },

      async trackUnhelpful() {
        if (!this.currentLetter) return;

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
          this.getNextLetter(true);
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

      /**
       * Open platform share intents.
       * Keep synchronous (no awaits) to reduce popup blocking.
       */
      openShare(platform) {
        const url = window.location.href;
        const title = document.title || 'Reasons to Stay';

        const encodedUrl = encodeURIComponent(url);
        const encodedTitle = encodeURIComponent(title);
        const shareText = encodeURIComponent(`${title} ${url}`);

        const openPopup = (shareUrl) => {
          if (!shareUrl) return;
          // noopener for safety
          window.open(shareUrl, '_blank', 'noopener,noreferrer');
        };

        switch ((platform || '').toLowerCase()) {
          case 'facebook':
            openPopup(`https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`);
            break;
          case 'x':
          case 'twitter':
            openPopup(`https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`);
            break;
          case 'whatsapp':
            openPopup(`https://wa.me/?text=${shareText}`);
            break;
          case 'reddit':
            openPopup(`https://www.reddit.com/submit?url=${encodedUrl}&title=${encodedTitle}`);
            break;
          case 'threads':
            // Threads doesn't have a universally consistent web share intent,
            // but the /intent/post endpoint is supported for many clients.
            openPopup(`https://www.threads.net/intent/post?text=${shareText}`);
            break;
          case 'email':
            // Use location change (not popup)
            window.location.href = `mailto:?subject=${encodedTitle}&body=${shareText}`;
            break;
          case 'copy':
            // Copy link with fallback
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(url).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch(e) {}
                document.body.removeChild(ta);
              });
            } else {
              const ta = document.createElement('textarea');
              ta.value = url;
              ta.setAttribute('readonly', '');
              ta.style.position = 'fixed';
              ta.style.left = '-9999px';
              document.body.appendChild(ta);
              ta.select();
              try { document.execCommand('copy'); } catch(e) {}
              document.body.removeChild(ta);
            }
            break;
          default:
            break;
        }
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
        modal.style.display = 'block'; 
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
        modal.style.display = 'none';
        document.body.classList.remove('rts-modal-open');
      },

      showRatePrompt() {
        const prompt = this.domElements.ratePrompt || document.querySelector('.rts-rate-prompt');
        
        if (!prompt) {
            if (this.currentLetter && !this.currentLetter._rtsRated) {
                 console.warn('[RTS] Rate prompt element not found');
            }
            return false;
        }

        if (this.currentLetter && this.currentLetter._rtsRated) return false;

        prompt.hidden = false;
        prompt.setAttribute('aria-hidden', 'false');
        prompt.classList.add('is-open');

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
            data = await this.parseJson(response).catch(() => ({}));
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
          // Treat feedback submission as a completed rating so the Next button can proceed
          try {
            if (this.currentLetter) {
              this.currentLetter._rtsRated = true;
            }
          } catch (e) {}

          // If the rating prompt was open, close it now (prevents 'stuck' flow)
          this.hideRatePrompt();

          // If user clicked Next and was waiting on a rating, proceed immediately
          if (this.pendingNextAfterRate) {
            this.pendingNextAfterRate = false;
            this.getNextLetter(true);
          }

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
        if (!loadingEl) {
            console.error('RTS: Loading element not found for error display');
            return;
        }

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
        
        // Scope selection to the active onboarding modal
        const onboardingModal = document.querySelector('.rts-onboarding-modal');
        if (!onboardingModal) {
            console.error('RTS: Onboarding modal not found');
            return;
        }
        
        // Collect feelings (checkboxes)
        const feelingCheckboxes = onboardingModal.querySelectorAll('input[name="feelings[]"]:checked');
        this.preferences.feelings = Array.from(feelingCheckboxes).map(cb => cb.value);
        
        // Collect reading time (radio)
        const readingTimeRadio = onboardingModal.querySelector('input[name="readingTime"]:checked');
        if (readingTimeRadio) this.preferences.readingTime = readingTimeRadio.value;
        
        // Collect tone (radio)
        const toneRadio = onboardingModal.querySelector('input[name="tone"]:checked');
        if (toneRadio) this.preferences.tone = toneRadio.value;
        
        // Ensure skipOnboarding is explicitly FALSE when completing normally
        this.preferences.skipOnboarding = false;

        
        // Save to sessionStorage
        this.saveState();
        sessionStorage.setItem('rts_onboarded', 'true');
        
        // Force Hide ALL onboarding overlays (Specificity War)
        const overlays = document.querySelectorAll('.rts-onboarding-overlay');

        // If focus is inside the overlay, move it out before hiding (WCAG + avoids console warning)
        try {
          const active = document.activeElement;
          if (active && overlays.length) {
            const inside = Array.from(overlays).some(o => o.contains(active));
            if (inside) {
              const target = document.querySelector('.rts-letter-viewer, .rts-letter-container, main, body');
              if (target && typeof target.focus === 'function') {
                target.setAttribute('tabindex', '-1');
                target.focus({ preventScroll: true });
                setTimeout(() => target.removeAttribute('tabindex'), 250);
              } else {
                active.blur?.();
              }
            }
          }
        } catch (e) {
          // ignore
        }

        overlays.forEach(el => {
          el.classList.add('rts-hidden-force');
          el.setAttribute('aria-hidden', 'true');
          el.setAttribute('inert', '');
          // Use setProperty with priority so we beat display:flex!important
          el.style.setProperty('display', 'none', 'important');
          el.style.setProperty('visibility', 'hidden', 'important');
          el.style.setProperty('pointer-events', 'none', 'important');
        });
        
        // Load first personalized letter
        this.loadFirstLetter();
      },

      skipOnboarding() {
        
        this.preferences.skipOnboarding = true;
        sessionStorage.setItem('rts_onboarded', 'true');
        
        // Force Hide ALL onboarding overlays
        const overlays = document.querySelectorAll('.rts-onboarding-overlay');

        // If focus is inside the overlay, move it out before hiding
        try {
          const active = document.activeElement;
          if (active && overlays.length) {
            const inside = Array.from(overlays).some(o => o.contains(active));
            if (inside) {
              const target = document.querySelector('.rts-letter-viewer, .rts-letter-container, main, body');
              if (target && typeof target.focus === 'function') {
                target.setAttribute('tabindex', '-1');
                target.focus({ preventScroll: true });
                setTimeout(() => target.removeAttribute('tabindex'), 250);
              } else {
                active.blur?.();
              }
            }
          }
        } catch (e) {
          // ignore
        }

        overlays.forEach(el => {
          el.classList.add('rts-hidden-force');
          el.setAttribute('aria-hidden', 'true');
          el.setAttribute('inert', '');
          el.style.setProperty('display', 'none', 'important');
          el.style.setProperty('visibility', 'hidden', 'important');
          el.style.setProperty('pointer-events', 'none', 'important');
        });
        
        this.loadFirstLetter();
      },

      nextOnboardingStep(currentStep) {
        // No-op here, logic moved to event handler to support scoping
      },

      

async submitLetter(formData) {
  const submitBtn = document.querySelector('.rts-btn-submit');
  const btnText = (submitBtn ? submitBtn.querySelector('.rts-btn-text') : null);
  const btnSpinner = (submitBtn ? submitBtn.querySelector('.rts-btn-spinner') : null);
  const responseEl = document.querySelector('.rts-form-response');

  const letterText = formData.get('letter_text');
  if (!letterText || letterText.length < 50) {
    if (responseEl) {
        responseEl.innerHTML = '<div class="rts-error">Letter must be at least 50 characters.</div>';
        responseEl.style.display = 'block';
    }
    return;
  }

  // Consent checkbox (WCAG-friendly error)
  const consent = document.querySelector('#rts-consent');
  if (consent && !consent.checked) {
    if (responseEl) {
      responseEl.innerHTML = '<div class="rts-error">Please confirm you understand your letter will be reviewed before publishing.</div>';
      responseEl.style.display = 'block';
    }
    if (consent && consent.focus) consent.focus();
    return;
  }

  if (submitBtn) submitBtn.disabled = true;
  if (btnText) btnText.style.display = 'none';
  if (btnSpinner) btnSpinner.style.display = 'inline';
  if (responseEl) responseEl.innerHTML = '';

  // Ensure timestamp exists (used for lightweight bot detection)
  const tsField = document.querySelector('#rts-timestamp');
  if (tsField && !tsField.value) {
    tsField.value = String(Date.now());
  }

  // Build payload including required security token (rts_token)
  const tokenField = document.querySelector('#rts-token');
  const payload = {
    author_name: formData.get('author_name'),
    letter_text: letterText,
    author_email: formData.get('author_email'),
    website: formData.get('website') || '',
    company: formData.get('company') || '',
    confirm_email: formData.get('confirm_email') || '',
    rts_timestamp: formData.get('rts_timestamp') || (tsField ? tsField.value : ''),
    rts_token: formData.get('rts_token') || (tokenField ? tokenField.value : '')
  };

  const showError = (msg) => {
    if (responseEl) {
      responseEl.innerHTML = `<div class="rts-error">${this.sanitizeHtml(msg || 'Something went wrong. Please try again.')}</div>`;
      responseEl.style.display = 'block';
    }
    if (submitBtn) submitBtn.disabled = false;
    if (btnText) btnText.style.display = 'inline';
    if (btnSpinner) btnSpinner.style.display = 'none';
  };

  try {
    // Try REST first
    let response = await this.robustFetch(this.getRestBase() + 'letter/submit', {
      method: 'POST',
      headers: this.getHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });

    // If blocked by WAF/security (common: 403 on /wp-json), fall back to admin-ajax.
    if (!response.ok && (response.status === 403 || response.status === 404 || response.status === 405)) {
      const ajax = await this.ajaxPost('rts_submit_letter', payload);
      if (ajax && ajax.success) {
        const formWrap = document.querySelector('.rts-submit-form-wrapper form');
        const success = document.querySelector('.rts-submit-success');
        if (formWrap) formWrap.style.display = 'none';
        if (success) success.style.display = 'block';
        return;
      }
      showError((ajax && ajax.message) ? ajax.message : 'Something went wrong. Please try again.');
      return;
    }

    const data = await this.parseJson(response).catch(() => ({}));

    if (response.ok && data && data.success) {
      const formWrap = document.querySelector('.rts-submit-form-wrapper form');
      const success = document.querySelector('.rts-submit-success');
      if (formWrap) formWrap.style.display = 'none';
      if (success) success.style.display = 'block';
    } else {
      // REST returned an error; try ajax fallback once if it's a non-JSON/WAF-y response.
      if (!data || typeof data !== 'object' || Object.keys(data).length === 0) {
        const ajax = await this.ajaxPost('rts_submit_letter', payload);
        if (ajax && ajax.success) {
          const formWrap = document.querySelector('.rts-submit-form-wrapper form');
          const success = document.querySelector('.rts-submit-success');
          if (formWrap) formWrap.style.display = 'none';
          if (success) success.style.display = 'block';
          return;
        }
        showError((ajax && ajax.message) ? ajax.message : 'Something went wrong. Please try again.');
        return;
      }
      showError(data.message || 'Something went wrong. Please try again.');
    }
  } catch (error) {
    // Network error: try ajax fallback as last resort
    try {
      const ajax = await this.ajaxPost('rts_submit_letter', payload);
      if (ajax && ajax.success) {
        const formWrap = document.querySelector('.rts-submit-form-wrapper form');
        const success = document.querySelector('.rts-submit-success');
        if (formWrap) formWrap.style.display = 'none';
        if (success) success.style.display = 'block';
        return;
      }
      showError((ajax && ajax.message) ? ajax.message : 'Failed to submit. Please try again.');
    } catch (e2) {
      this.logError('submitLetter', error);
      showError('Failed to submit. Please check your connection and try again.');
    }
  }
},

cleanup() {
        if (!Array.isArray(this.eventListeners)) {
            this.eventListeners = [];
            return;
        }
        this.eventListeners.forEach(({ element, type, handler }) => {
          try { element.removeEventListener(type, handler); } catch(e){}
        });
        this.eventListeners = [];
        if (this.debounceTimer) clearTimeout(this.debounceTimer);
      },

      debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
          const later = () => {
            clearTimeout(timeout);
            func.apply(this, args);
          };
          clearTimeout(timeout);
          timeout = setTimeout(later, wait);
        };
      },

      

bindEvents() {
        // Debounced activity tracker - reduces CPU usage
        const debouncedActivity = this.debounce(() => {
          this.lastActivityTime = Date.now();
        }, 250);

        // Activity events (passive where safe)
        ['scroll', 'mousemove'].forEach(evt => {
          document.addEventListener(evt, debouncedActivity, { passive: true });
          this.eventListeners.push({ element: document, type: evt, handler: debouncedActivity });
        });

        // keydown can't be passive (we may preventDefault later)
        document.addEventListener('keydown', debouncedActivity);
        this.eventListeners.push({ element: document, type: 'keydown', handler: debouncedActivity });

        // Mobile touch activity (passive)
        if ('ontouchstart' in window) {
          document.addEventListener('touchstart', debouncedActivity, { passive: true });
          this.eventListeners.push({ element: document, type: 'touchstart', handler: debouncedActivity });
        }

        // Keep existing online/offline handlers
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

        // Single delegated click handler
        const clickHandler = (e) => {
          // 1. Next Letter Button
          if (e.target.closest('.rts-btn-next')) {
            e.preventDefault();
            e.stopPropagation();

            const ratePrompt = this.domElements.ratePrompt || document.querySelector('.rts-rate-prompt');

            if (this.currentLetter && !this.currentLetter._rtsRated && ratePrompt) {
              this.pendingNextAfterRate = true;
              this.showRatePrompt();
              return;
            }
            this.getNextLetter(true);
            return;
          }

          // 2. Rating prompt buttons
          if (e.target.closest('.rts-rate-up')) { e.preventDefault(); e.stopPropagation(); this.trackHelpful(); return; }
          if (e.target.closest('.rts-rate-down')) { e.preventDefault(); e.stopPropagation(); this.trackUnhelpful(); return; }
          if (e.target.closest('.rts-rate-skip')) {
            e.preventDefault();
            e.stopPropagation();
            this.hideRatePrompt();
            if (this.pendingNextAfterRate) { this.pendingNextAfterRate = false; this.getNextLetter(true); }
            return;
          }

          // 3. Backwards compatible helpful buttons
          if (e.target.closest('.rts-btn-helpful')) { e.preventDefault(); e.stopPropagation(); this.trackHelpful(); return; }
          if (e.target.closest('.rts-btn-unhelpful')) { e.preventDefault(); e.stopPropagation(); this.trackUnhelpful(); return; }

          // 4. Share buttons
          const shareBtn = e.target.closest('.rts-share-btn');
          if (shareBtn) {
            e.preventDefault();
            e.stopPropagation();
            const platform = shareBtn.dataset.platform;
            if (platform) {
              this.trackShare(platform);
              this.openShare(platform);
            }
            return;
          }

          // 5. Onboarding: Exit/Skip
          if (e.target.closest('.rts-skip-tag') || e.target.closest('.rts-btn-skip')) {
            e.preventDefault();
            e.stopPropagation();
            this.skipOnboarding();
            return;
          }

          // 6. Onboarding: Next Step (Scoped)
          const nextStepBtn = e.target.closest('.rts-btn-next-step');
          if (nextStepBtn) {
            e.preventDefault();
            e.stopPropagation();

            const modalEl = nextStepBtn.closest('.rts-onboarding-modal');
            const stepEl = nextStepBtn.closest('.rts-onboarding-step');

            if (stepEl && stepEl.dataset && stepEl.dataset.step && modalEl) {
              const currentStep = parseInt(stepEl.dataset.step, 10);
              const nextStep = currentStep + 1;

              const stepsInModal = modalEl.querySelectorAll('.rts-onboarding-step');
              stepsInModal.forEach(s => s.style.display = 'none');

              const nextEl = modalEl.querySelector(`.rts-onboarding-step[data-step="${nextStep}"]`);
              if (nextEl) nextEl.style.display = 'block';
            }
            return;
          }

          // 7. Onboarding: Complete
          if (e.target.closest('.rts-btn-complete')) {
            e.preventDefault();
            e.stopPropagation();
            this.completeOnboarding();
            return;
          }

          // 8. Feedback Modal Triggers
          const openBtn = e.target.closest('.rts-feedback-open');
          if (openBtn) {
            e.preventDefault();
            e.stopPropagation();
            let defaultRating = 'neutral';
            if (document.querySelector('.rts-btn-helpful.rts-helped')) defaultRating = 'up';
            if (document.querySelector('.rts-btn-unhelpful.rts-helped')) defaultRating = 'down';
            this.openFeedbackModal(defaultRating);
            return;
          }

          const triggerBtn = e.target.closest('.rts-trigger-open');
          if (triggerBtn) { e.preventDefault(); e.stopPropagation(); this.openFeedbackModal('down', true); return; }

          if (e.target.closest('[data-rts-close]')) { e.preventDefault(); e.stopPropagation(); this.closeFeedbackModal(); return; }
        };

        document.addEventListener('click', clickHandler, { passive: true });
        this.eventListeners.push({ element: document, type: 'click', handler: clickHandler });

        // Keydown handler (Escape handling)
        const keydownHandler = (e) => {
          if (e.key !== 'Escape') return;
          const modal = document.getElementById('rts-feedback-modal');
          if (modal && modal.getAttribute('aria-hidden') === 'false') this.closeFeedbackModal();

          const onboarding = document.querySelector('.rts-onboarding-overlay');
          if (onboarding) {
            const isHidden = onboarding.classList.contains('rts-hidden-force') || onboarding.getAttribute('aria-hidden') === 'true';
            const display = window.getComputedStyle(onboarding).display;
            if (!isHidden && display !== 'none') {
              e.preventDefault();
              e.stopPropagation();
              this.skipOnboarding();
              return;
            }
          }
        };
        document.addEventListener('keydown', keydownHandler);
        this.eventListeners.push({ element: document, type: 'keydown', handler: keydownHandler });

        // Feedback submit handler (delegated)
        const submitHandler = (e) => {
          const form = e.target.closest('.rts-feedback-form');
          if (!form) return;
          e.preventDefault();
          this.submitFeedback(form);
        };
        document.addEventListener('submit', submitHandler);
        this.eventListeners.push({ element: document, type: 'submit', handler: submitHandler });

        // Submit form handlers (as-is, but only bound once)
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

    // Auto-initialize (Robust Check)
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
          if (typeof window.RTSLetterSystem !== 'undefined' && window.RTSLetterSystem.init) {
              window.RTSLetterSystem.init();
          }
      });
    } else {
      if (typeof window.RTSLetterSystem !== 'undefined' && window.RTSLetterSystem.init) {
          window.RTSLetterSystem.init();
      }
    }
})();