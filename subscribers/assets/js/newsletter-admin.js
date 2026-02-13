/* global rtsNL, tinymce */
(function ($) {
  'use strict';

  var cfg = (typeof rtsNL === 'object' && rtsNL) ? rtsNL : {};

  function escHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function nl2brSafe(value) {
    return escHtml(value).replace(/\n/g, '<br>');
  }

  function safeColor(value, fallback) {
    var color = String(value || '').trim();
    return /^#(?:[0-9a-fA-F]{3}){1,2}$/.test(color) ? color.toLowerCase() : fallback;
  }

  function uid() {
    return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  }

  function slugify(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 80);
  }

  function debounce(fn, wait) {
    var timeoutId = 0;
    var wrapped = function () {
      var args = arguments;
      var ctx = this;
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(function () {
        fn.apply(ctx, args);
      }, wait || 250);
    };
    wrapped.cancel = function () {
      if (timeoutId) {
        window.clearTimeout(timeoutId);
        timeoutId = 0;
      }
    };
    return wrapped;
  }

  function getEditor() {
    if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
      var editor = tinymce.get('content');
      if (editor && editor.initialized) {
        return editor;
      }
    }
    return null;
  }

  function setEditorContent(html) {
    var editor = getEditor();
    if (editor) {
      editor.setContent(String(html || ''));
      editor.save();
      return;
    }
    var textarea = document.getElementById('content');
    if (textarea) {
      textarea.value = String(html || '');
    }
  }

  function appendEditorContent(html) {
    var editor = getEditor();
    if (editor) {
      editor.execCommand('mceInsertContent', false, String(html || ''));
      editor.save();
      return;
    }
    var textarea = document.getElementById('content');
    if (textarea) {
      textarea.value += String(html || '');
    }
  }

  function getPostId() {
    var id = parseInt($('#post_ID').val(), 10) || parseInt(cfg.post_id, 10) || 0;
    return id > 0 ? id : 0;
  }

  function getSocialLinks() {
    var links = cfg.social_links || {};
    return {
      facebook: links.facebook || '',
      instagram: links.instagram || '',
      linkedin: links.linkedin || '',
      linktree: links.linktree || ''
    };
  }

  function renderSocialLinksInline(links) {
    var parts = [];
    if (links.facebook) parts.push('<a href="' + escHtml(links.facebook) + '">Facebook</a>');
    if (links.instagram) parts.push('<a href="' + escHtml(links.instagram) + '">Instagram</a>');
    if (links.linkedin) parts.push('<a href="' + escHtml(links.linkedin) + '">LinkedIn</a>');
    if (links.linktree) parts.push('<a href="' + escHtml(links.linktree) + '">Linktree</a>');
    return parts.join(' | ');
  }

  function joinSitePath(path) {
    var root = String(cfg.site_url || window.location.origin || '/');
    root = root.replace(/\/+$/, '');
    path = String(path || '').replace(/^\/+/, '');
    return root + '/' + path;
  }

  var blockDefaults = {
    header: {
      type: 'header',
      data: {
        title: 'Newsletter Update',
        subtitle: 'Thanks for being here.',
        background: '#1e293b'
      }
    },
    text: {
      type: 'text',
      data: {
        heading: 'Section Heading',
        body: 'Add your message here.'
      }
    },
    button: {
      type: 'button',
      data: {
        label: 'Read More Letters',
        url: joinSitePath('letters/'),
        background: '#1d4ed8'
      }
    },
    divider: {
      type: 'divider',
      data: {
        spacing: 18
      }
    },
    social: {
      type: 'social',
      data: {
        intro: 'Share and stay connected'
      }
    },
    footer: {
      type: 'footer',
      data: {
        text: 'You received this email because you subscribed. Manage your preferences from any email footer.'
      }
    }
  };

  function getStarterBlocks() {
    return [
      { id: uid(), type: 'header', data: { title: 'Dear strange,', subtitle: 'A short update from Reasons to Stay.', background: '#1e293b' } },
      { id: uid(), type: 'text', data: { heading: 'This Week', body: 'Share one clear update and why it matters.' } },
      { id: uid(), type: 'text', data: { heading: 'Featured Letter', body: 'Use the Insert Random Letter action below to pull a published letter block.' } },
      { id: uid(), type: 'button', data: { label: 'Read More Letters', url: joinSitePath('letters/'), background: '#1d4ed8' } },
      { id: uid(), type: 'footer', data: { text: 'Thank you for being here.' } }
    ];
  }

  function normalizeBlocks(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }

    var output = [];
    raw.forEach(function (block) {
      if (!block || typeof block !== 'object') {
        return;
      }

      var type = String(block.type || '').toLowerCase();
      if (!blockDefaults[type]) {
        return;
      }

      var id = String(block.id || uid()).replace(/[^a-zA-Z0-9_-]/g, '');
      if (!id) {
        id = uid();
      }

      var data = $.extend({}, blockDefaults[type].data, (block.data && typeof block.data === 'object') ? block.data : {});
      output.push({ id: id, type: type, data: data });
    });

    return output.slice(0, 80);
  }

  var builder = {
    blocks: [],
    templates: [],
    activeId: '',
    dirty: false,
    autosaveTimer: 0,
    autosaveInterval: 4500,

    init: function () {
      this.$root = $('#rts-nl-builder');
      if (!this.$root.length) {
        return;
      }

      this.$canvas = $('#rts-nl-canvas');
      this.$settings = $('#rts-nl-block-settings');
      this.$json = $('#rts-nl-builder-json');
      this.$status = $('#rts-nl-builder-status');
      this.$templateLibrary = $('#rts-nl-template-library');
      this.$preview = $('#rts-nl-preview');
      this.templates = Array.isArray(cfg.builder_templates) ? cfg.builder_templates : [];
      this.refreshPreviewDebounced = debounce(this.refreshPreview.bind(this), 650);

      var initial = [];
      try {
        var fromInput = this.$json.val();
        if (fromInput) {
          initial = JSON.parse(fromInput);
        } else if (Array.isArray(cfg.builder_blocks)) {
          initial = cfg.builder_blocks;
        }
      } catch (err) {
        initial = [];
      }

      this.blocks = normalizeBlocks(initial);
      this.activeId = this.blocks.length ? this.blocks[0].id : '';

      this.bindEvents();
      this.enableSortable();
      this.renderTemplates();
      this.render();
      this.syncHidden();
      this.refreshPreview();
    },

    bindEvents: function () {
      var self = this;

      this.$root.on('click', '[data-rts-block-type]', function (e) {
        e.preventDefault();
        self.addBlock($(this).attr('data-rts-block-type'));
      });

      $('#rts-nl-builder-starter').on('click', function (e) {
        e.preventDefault();
        if (self.blocks.length && !window.confirm('Replace current canvas with starter layout?')) {
          return;
        }
        self.blocks = getStarterBlocks();
        self.activeId = self.blocks[0].id;
        self.markDirty('Starter layout loaded.');
        self.render();
      });

      $('#rts-nl-builder-clear').on('click', function (e) {
        e.preventDefault();
        if (!window.confirm('Clear all blocks from canvas?')) {
          return;
        }
        self.blocks = [];
        self.activeId = '';
        self.markDirty('Canvas cleared.');
        self.render();
      });

      $('#rts-nl-builder-sync-editor').on('click', function (e) {
        e.preventDefault();
        self.pushToEditor();
      });

      $('#rts-nl-builder-preview').on('click', function (e) {
        e.preventDefault();
        self.refreshPreview();
      });

      $('#rts-nl-builder-save-api').on('click', function (e) {
        e.preventDefault();
        self.saveViaApi(true);
      });

      $('#rts-nl-save-template').on('click', function (e) {
        e.preventDefault();
        self.saveAsTemplate();
      });

      this.$templateLibrary.on('click', '[data-rts-template-apply]', function (e) {
        e.preventDefault();
        var slug = $(this).attr('data-rts-template-apply');
        self.applyTemplate(slug);
      });

      this.$canvas.on('click', '.rts-nl-canvas-item', function () {
        var id = $(this).attr('data-block-id');
        if (!id) {
          return;
        }
        self.activeId = id;
        self.render();
      });

      this.$canvas.on('click', '.rts-nl-canvas-action--duplicate', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).closest('.rts-nl-canvas-item').attr('data-block-id');
        self.duplicateBlock(id);
      });

      this.$canvas.on('click', '.rts-nl-canvas-action--remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).closest('.rts-nl-canvas-item').attr('data-block-id');
        self.removeBlock(id);
      });

      this.$settings.on('input change', '[data-rts-setting]', function () {
        var block = self.getActiveBlock();
        if (!block) {
          return;
        }
        var field = $(this).attr('data-rts-setting');
        if (!field) {
          return;
        }

        if ($(this).attr('type') === 'number') {
          block.data[field] = parseInt($(this).val(), 10) || 0;
        } else {
          block.data[field] = $(this).val();
        }

        self.markDirty();
        self.syncHidden();
        self.renderCanvas();
      });
    },

    enableSortable: function () {
      var self = this;
      if (!this.$canvas.length || !this.$canvas.sortable) {
        return;
      }

      this.$canvas.sortable({
        items: '> li.rts-nl-canvas-item',
        handle: '.rts-nl-canvas-handle',
        placeholder: 'rts-nl-canvas-placeholder',
        update: function () {
          var ordered = [];
          self.$canvas.find('> li.rts-nl-canvas-item').each(function () {
            var id = $(this).attr('data-block-id');
            var block = self.findBlockById(id);
            if (block) {
              ordered.push(block);
            }
          });
          self.blocks = ordered;
          self.markDirty('Block order updated.');
          self.syncHidden();
          self.renderSettings();
        }
      });
    },

    addBlock: function (type) {
      if (!blockDefaults[type]) {
        return;
      }
      var next = {
        id: uid(),
        type: type,
        data: $.extend({}, blockDefaults[type].data)
      };
      this.blocks.push(next);
      this.activeId = next.id;
      this.markDirty('Added ' + type + ' block.');
      this.render();
    },

    renderTemplates: function () {
      if (!this.$templateLibrary.length) {
        return;
      }

      if (!this.templates.length) {
        this.$templateLibrary.html('<div class="rts-nl-template-empty">No templates yet. Save your current layout as a reusable template.</div>');
        return;
      }

      var html = '';
      this.templates.forEach(function (tpl) {
        var slug = escHtml(tpl.slug || '');
        var name = escHtml(tpl.name || tpl.slug || 'Template');
        var count = Array.isArray(tpl.structure) ? tpl.structure.length : 0;
        html += '<div class="rts-nl-template-item">';
        html += '<div class="rts-nl-template-item__name">' + name + '</div>';
        html += '<div class="rts-nl-template-item__meta">' + count + ' blocks</div>';
        html += '<button type="button" class="button button-small" data-rts-template-apply="' + slug + '">Apply</button>';
        html += '</div>';
      });

      this.$templateLibrary.html(html);
    },

    applyTemplate: function (slug) {
      var chosen = null;
      this.templates.forEach(function (tpl) {
        if ((tpl.slug || '') === slug) {
          chosen = tpl;
        }
      });
      if (!chosen || !Array.isArray(chosen.structure) || !chosen.structure.length) {
        this.showStatus('Template is empty or unavailable.', 'warn');
        return;
      }

      if (this.blocks.length && !window.confirm('Replace current canvas with template "' + (chosen.name || slug) + '"?')) {
        return;
      }

      this.blocks = normalizeBlocks(chosen.structure).map(function (block) {
        block.id = uid();
        return block;
      });
      this.activeId = this.blocks.length ? this.blocks[0].id : '';
      this.markDirty('Template applied.');
      this.render();
      this.refreshPreview();
    },

    saveAsTemplate: function () {
      var self = this;
      if (!Array.isArray(this.blocks) || !this.blocks.length) {
        this.showStatus('Add blocks before saving a template.', 'warn');
        return;
      }

      var name = window.prompt('Template name', 'Newsletter Template');
      if (!name) {
        return;
      }
      name = String(name).trim();
      if (!name) {
        return;
      }

      var slug = slugify(name);
      if (!slug) {
        this.showStatus('Template name is not valid.', 'warn');
        return;
      }

      this.showStatus('Saving template...', 'warn');
      this.restRequest('templates', 'POST', {
        slug: slug,
        name: name,
        structure: this.blocks
      }).done(function (resp) {
        if (!resp || !resp.success) {
          self.showStatus('Template save failed.', 'warn');
          return;
        }

        var replaced = false;
        self.templates = self.templates.map(function (tpl) {
          if ((tpl.slug || '') === slug) {
            replaced = true;
            return { slug: slug, name: name, structure: JSON.parse(JSON.stringify(self.blocks)) };
          }
          return tpl;
        });
        if (!replaced) {
          self.templates.unshift({ slug: slug, name: name, structure: JSON.parse(JSON.stringify(self.blocks)) });
        }
        self.renderTemplates();
        self.showStatus('Template saved.', 'success');
      }).fail(function () {
        self.showStatus('Template save failed.', 'warn');
      });
    },

    duplicateBlock: function (id) {
      var original = this.findBlockById(id);
      if (!original) {
        return;
      }

      var clone = {
        id: uid(),
        type: original.type,
        data: $.extend({}, original.data)
      };

      var index = this.getBlockIndex(id);
      if (index < 0) {
        this.blocks.push(clone);
      } else {
        this.blocks.splice(index + 1, 0, clone);
      }

      this.activeId = clone.id;
      this.markDirty('Block duplicated.');
      this.render();
    },

    removeBlock: function (id) {
      var index = this.getBlockIndex(id);
      if (index < 0) {
        return;
      }

      this.blocks.splice(index, 1);
      if (this.activeId === id) {
        this.activeId = this.blocks.length ? this.blocks[Math.max(0, index - 1)].id : '';
      }
      this.markDirty('Block removed.');
      this.render();
    },

    getBlockIndex: function (id) {
      var found = -1;
      this.blocks.forEach(function (block, idx) {
        if (block.id === id) {
          found = idx;
        }
      });
      return found;
    },

    findBlockById: function (id) {
      var result = null;
      this.blocks.forEach(function (block) {
        if (block.id === id) {
          result = block;
        }
      });
      return result;
    },

    getActiveBlock: function () {
      return this.findBlockById(this.activeId);
    },

    blockLabel: function (type) {
      var labels = {
        header: 'Header',
        text: 'Text',
        button: 'Button',
        divider: 'Divider',
        social: 'Social',
        footer: 'Footer'
      };
      return labels[type] || type;
    },

    blockSummary: function (block) {
      if (block.type === 'header') {
        return block.data.title || 'Newsletter header';
      }
      if (block.type === 'text') {
        return block.data.heading || block.data.body || 'Text block';
      }
      if (block.type === 'button') {
        return (block.data.label || 'Button') + ' -> ' + (block.data.url || '#');
      }
      if (block.type === 'divider') {
        return 'Spacing: ' + (block.data.spacing || 18) + 'px';
      }
      if (block.type === 'social') {
        return block.data.intro || 'Social links row';
      }
      return block.data.text || 'Footer copy';
    },

    render: function () {
      this.renderCanvas();
      this.renderSettings();
      this.syncHidden();
    },

    renderCanvas: function () {
      var self = this;

      if (!this.blocks.length) {
        this.$canvas.html('<li class="rts-nl-canvas-empty">No blocks yet. Add components from the left panel.</li>');
        return;
      }

      var html = '';
      this.blocks.forEach(function (block) {
        var activeClass = block.id === self.activeId ? ' is-active' : '';
        html += '<li class="rts-nl-canvas-item' + activeClass + '" data-block-id="' + escHtml(block.id) + '">';
        html += '<div class="rts-nl-canvas-handle" aria-hidden="true">::</div>';
        html += '<div class="rts-nl-canvas-main">';
        html += '<div class="rts-nl-canvas-title">' + escHtml(self.blockLabel(block.type)) + '</div>';
        html += '<div class="rts-nl-canvas-sub">' + escHtml(self.blockSummary(block)) + '</div>';
        html += '</div>';
        html += '<div class="rts-nl-canvas-actions">';
        html += '<button type="button" class="button-link rts-nl-canvas-action rts-nl-canvas-action--duplicate">Duplicate</button>';
        html += '<button type="button" class="button-link-delete rts-nl-canvas-action rts-nl-canvas-action--remove">Remove</button>';
        html += '</div>';
        html += '</li>';
      });

      this.$canvas.html(html);
      if (this.$canvas.data('ui-sortable')) {
        this.$canvas.sortable('refresh');
      }
    },

    renderSettings: function () {
      var block = this.getActiveBlock();
      if (!block) {
        this.$settings.html('<div class="rts-nl-settings-empty">Select a block to edit its content and styles.</div>');
        return;
      }

      var html = '<div class="rts-nl-settings">';
      html += '<div class="rts-nl-settings__label">' + escHtml(this.blockLabel(block.type)) + '</div>';

      if (block.type === 'header') {
        html += '<label>Title<input type="text" class="regular-text" data-rts-setting="title" value="' + escHtml(block.data.title || '') + '"></label>';
        html += '<label>Subtitle<input type="text" class="regular-text" data-rts-setting="subtitle" value="' + escHtml(block.data.subtitle || '') + '"></label>';
        html += '<label>Background<input type="text" class="regular-text" data-rts-setting="background" value="' + escHtml(block.data.background || '#1e293b') + '" placeholder="#1e293b"></label>';
      } else if (block.type === 'text') {
        html += '<label>Heading<input type="text" class="regular-text" data-rts-setting="heading" value="' + escHtml(block.data.heading || '') + '"></label>';
        html += '<label>Body<textarea rows="6" class="large-text" data-rts-setting="body">' + escHtml(block.data.body || '') + '</textarea></label>';
      } else if (block.type === 'button') {
        html += '<label>Label<input type="text" class="regular-text" data-rts-setting="label" value="' + escHtml(block.data.label || '') + '"></label>';
        html += '<label>URL<input type="url" class="regular-text" data-rts-setting="url" value="' + escHtml(block.data.url || '') + '"></label>';
        html += '<label>Background<input type="text" class="regular-text" data-rts-setting="background" value="' + escHtml(block.data.background || '#1d4ed8') + '" placeholder="#1d4ed8"></label>';
      } else if (block.type === 'divider') {
        html += '<label>Spacing (px)<input type="number" class="small-text" min="8" max="48" step="1" data-rts-setting="spacing" value="' + escHtml(block.data.spacing || 18) + '"></label>';
      } else if (block.type === 'social') {
        html += '<label>Intro text<input type="text" class="regular-text" data-rts-setting="intro" value="' + escHtml(block.data.intro || '') + '"></label>';
      } else if (block.type === 'footer') {
        html += '<label>Footer text<textarea rows="5" class="large-text" data-rts-setting="text">' + escHtml(block.data.text || '') + '</textarea></label>';
      }

      html += '</div>';
      this.$settings.html(html);
    },

    toHtml: function () {
      var links = getSocialLinks();
      var html = '';

      this.blocks.forEach(function (block) {
        if (block.type === 'header') {
          html += '<section style="padding:24px 18px;background:' + escHtml(safeColor(block.data.background, '#1e293b')) + ';color:#ffffff;text-align:center;">';
          html += '<h2 style="margin:0 0 8px 0;color:#ffffff;">' + escHtml(block.data.title || 'Newsletter Update') + '</h2>';
          if (block.data.subtitle) {
            html += '<p style="margin:0;color:rgba(255,255,255,0.92);">' + escHtml(block.data.subtitle) + '</p>';
          }
          html += '</section>';
          return;
        }

        if (block.type === 'text') {
          html += '<section style="padding:18px 0;">';
          if (block.data.heading) {
            html += '<h3 style="margin:0 0 10px 0;">' + escHtml(block.data.heading) + '</h3>';
          }
          html += '<p style="margin:0;">' + nl2brSafe(block.data.body || '') + '</p>';
          html += '</section>';
          return;
        }

        if (block.type === 'button') {
          html += '<p style="margin:18px 0;text-align:center;">';
          html += '<a href="' + escHtml(block.data.url || '#') + '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:' + escHtml(safeColor(block.data.background, '#1d4ed8')) + ';color:#ffffff;text-decoration:none;font-weight:600;">' + escHtml(block.data.label || 'Read More') + '</a>';
          html += '</p>';
          return;
        }

        if (block.type === 'divider') {
          var spacing = parseInt(block.data.spacing, 10) || 18;
          spacing = Math.max(8, Math.min(48, spacing));
          html += '<hr style="border:none;border-top:1px solid #d1d5db;margin:' + spacing + 'px 0;">';
          return;
        }

        if (block.type === 'social') {
          var socialLinksHtml = renderSocialLinksInline(links);
          html += '<p style="margin:0 0 8px 0;text-align:center;font-size:13px;color:#475569;">' + escHtml(block.data.intro || 'Share and stay connected') + '</p>';
          html += '<p style="margin:0;text-align:center;">';
          html += socialLinksHtml || 'Social links available soon.';
          html += '</p>';
          return;
        }

        if (block.type === 'footer') {
          html += '<section style="padding:18px 0 4px;font-size:12px;color:#64748b;">' + nl2brSafe(block.data.text || '') + '</section>';
        }
      });

      return html;
    },

    pushToEditor: function () {
      setEditorContent(this.toHtml());
      this.dirty = false;
      this.showStatus('Builder content pushed to editor.', 'success');
      this.refreshPreview();
      this.saveViaApi(false);
    },

    syncHidden: function () {
      if (!this.$json.length) {
        return;
      }
      this.$json.val(JSON.stringify(this.blocks));
    },

    restRequest: function (path, method, data) {
      var root = String(cfg.rest_url || '').replace(/\/+$/, '');
      if (!root) {
        return $.Deferred().reject().promise();
      }

      return $.ajax({
        url: root + '/' + String(path || '').replace(/^\/+/, ''),
        method: method || 'GET',
        dataType: 'json',
        contentType: 'application/json',
        processData: false,
        data: JSON.stringify(data || {}),
        beforeSend: function (xhr) {
          if (cfg.rest_nonce) {
            xhr.setRequestHeader('X-WP-Nonce', cfg.rest_nonce);
          }
        }
      });
    },

    scheduleAutosave: function () {
      var self = this;
      if (this.autosaveTimer) {
        window.clearTimeout(this.autosaveTimer);
      }

      this.autosaveTimer = window.setTimeout(function () {
        self.saveViaApi(false);
      }, this.autosaveInterval);
    },

    saveViaApi: function (manual) {
      var self = this;
      var postId = getPostId();
      if (!postId) {
        if (manual) {
          self.showStatus('Save this newsletter once to enable API draft saves.', 'warn');
        }
        return;
      }

      var title = $('#title').val() || '';
      var content = (function () {
        var editor = getEditor();
        if (editor) {
          return editor.getContent();
        }
        return $('#content').val() || '';
      })();

      self.restRequest('save', 'POST', {
        post_id: postId,
        title: title,
        content: content,
        blocks: self.blocks
      }).done(function (resp) {
        if (!resp || !resp.success) {
          if (manual) {
            self.showStatus('Draft save failed.', 'warn');
          }
          return;
        }
        if (manual) {
          self.showStatus('Builder draft saved.', 'success');
        } else {
          self.showStatus('Autosaved.', 'success');
        }
      }).fail(function () {
        if (manual) {
          self.showStatus('Draft save failed.', 'warn');
        }
      });
    },

    refreshPreview: function () {
      var self = this;
      if (!self.$preview.length) {
        return;
      }
      self.$preview.html('<div class="rts-nl-preview__loading">Rendering preview...</div>');
      self.restRequest('preview', 'POST', {
        post_id: getPostId(),
        blocks: self.blocks
      }).done(function (resp) {
        var html = (resp && resp.html) ? String(resp.html) : self.toHtml();
        self.$preview.html('<div class="rts-nl-preview__inner">' + html + '</div>');
      }).fail(function () {
        self.$preview.html('<div class="rts-nl-preview__inner">' + self.toHtml() + '</div>');
      });
    },

    markDirty: function (message) {
      this.dirty = true;
      if (message) {
        this.showStatus(message, 'warn');
      }
      this.scheduleAutosave();
      if (this.refreshPreviewDebounced && typeof this.refreshPreviewDebounced === 'function') {
        this.refreshPreviewDebounced();
      }
    },

    showStatus: function (message, type) {
      if (!this.$status.length) {
        return;
      }
      this.$status.removeClass('is-success is-warn').addClass(type === 'success' ? 'is-success' : 'is-warn').text(message || '');
    },

    hasUnsyncedChanges: function () {
      return !!this.dirty;
    },

    cleanup: function () {
      if (this.autosaveTimer) {
        window.clearTimeout(this.autosaveTimer);
        this.autosaveTimer = 0;
      }
      if (this.refreshPreviewDebounced && typeof this.refreshPreviewDebounced.cancel === 'function') {
        this.refreshPreviewDebounced.cancel();
      }
    }
  };

  function ajaxPost(data, callback) {
    $.post(cfg.ajax_url || window.ajaxurl, data, callback);
  }

  function insertRandomLetter($btn) {
    var original = $btn.text();
    $btn.prop('disabled', true).text('Fetching...');

    ajaxPost({
      action: 'rts_insert_random_letter',
      nonce: cfg.nonce
    }, function (resp) {
      $btn.prop('disabled', false).text(original);
      if (resp && resp.success && resp.data && resp.data.content) {
        appendEditorContent(resp.data.content);
      } else {
        window.alert((resp && resp.data && resp.data.message) ? resp.data.message : 'No letters available.');
      }
    });
  }

  function appendStarterHtml() {
    var html = '' +
      '<section style="padding:20px 0;">' +
      '<h2 style="margin:0 0 10px;">Dear strange,</h2>' +
      '<p style="margin:0 0 12px;">Here is this week\'s newsletter update. You can include your intro here, followed by featured letters and updates.</p>' +
      '</section>' +
      '<section style="padding:10px 0;">' +
      '<h3 style="margin:0 0 8px;">Featured Letter</h3>' +
      '<p style="margin:0 0 10px;">Add your selected letter block below.</p>' +
      '</section>' +
      '<section style="padding:12px 0;border-top:1px solid #e5e7eb;margin-top:14px;">' +
      '<p style="margin:0;">Thank you for being here.</p>' +
      '</section>';
    appendEditorContent(html);
  }

  function appendCtaHtml() {
    var root = escHtml(joinSitePath(''));
    var html = '<p style="margin:16px 0;text-align:center;">' +
      '<a href="' + root + 'letters/" style="display:inline-block;padding:10px 18px;border-radius:8px;background:#1d4ed8;color:#ffffff;text-decoration:none;">Read More Letters</a>' +
      '</p>';
    appendEditorContent(html);
  }

  function appendSocialHtml() {
    var links = getSocialLinks();
    var socialLinksHtml = renderSocialLinksInline(links);
    var html = '<p style="text-align:center;">' +
      (socialLinksHtml || 'Social links available soon.') +
      '</p>';
    appendEditorContent(html);
  }

  function maybeSyncBuilderBeforeSend() {
    if (builder.$root && builder.$root.length && builder.hasUnsyncedChanges()) {
      if (window.confirm('Builder changes are not pushed to the editor yet. Push now before sending?')) {
        builder.pushToEditor();
      }
    }
  }

  var progressPollState = Object.create(null);

  function scheduleProgressPoll(key, postId, delayMs) {
    var state = progressPollState[key] || {};
    if (state.timerId) {
      window.clearTimeout(state.timerId);
    }
    state.timerId = window.setTimeout(function () {
      startProgressPoll(postId);
    }, delayMs);
    progressPollState[key] = state;
  }

  function stopAllProgressPolls() {
    Object.keys(progressPollState).forEach(function (key) {
      var state = progressPollState[key];
      if (state && state.timerId) {
        window.clearTimeout(state.timerId);
      }
      delete progressPollState[key];
    });
  }

  function startProgressPoll(postId) {
    var key = String(postId || '');
    if (!key) {
      return;
    }
    var state = progressPollState[key] || {
      delayMs: 2500,
      stagnantPolls: 0,
      lastPercent: -1
    };

    ajaxPost({
      action: 'rts_newsletter_progress',
      nonce: cfg.nonce,
      post_id: postId
    }, function (resp) {
      if (!resp || !resp.success) {
        state.delayMs = Math.min(15000, Math.round(state.delayMs * 1.5));
        progressPollState[key] = state;
        scheduleProgressPoll(key, postId, state.delayMs);
        return;
      }

      var data = resp.data || {};
      var pct = data.percent || 0;
      var html = '<div style="margin-bottom:10px;">' +
        '<div style="height:8px;background:#020617;border:1px solid #334155;border-radius:999px;overflow:hidden;">' +
        '<div style="height:100%;width:' + pct + '%;background:#FCA311;transition:width 0.3s;"></div>' +
        '</div></div>' +
        '<span style="font-size:12px;color:#94a3b8;">' + pct + '% complete (' + (data.queued || 0) + '/' + (data.total || 0) + ')</span>';

      $('#rts-send-progress').html(html);

      if (pct < 100) {
        if (pct > state.lastPercent) {
          state.delayMs = 2500;
          state.stagnantPolls = 0;
        } else {
          state.stagnantPolls += 1;
          state.delayMs = Math.min(15000, Math.round(state.delayMs * 1.4));
        }
        state.lastPercent = pct;
        progressPollState[key] = state;
        scheduleProgressPoll(key, postId, state.delayMs);
      } else {
        if (state.timerId) {
          window.clearTimeout(state.timerId);
        }
        delete progressPollState[key];
        $('#rts-send-all-btn').prop('disabled', false);
        $('#rts-send-progress').html('<div class="rts-nl-note rts-nl-note--success">All emails delivered.</div>');
      }
    });
  }

  $(function () {
    builder.init();
    window.addEventListener('beforeunload', function (e) {
      if (builder.$root && builder.$root.length && builder.hasUnsyncedChanges()) {
        var msg = 'You have newsletter builder changes that are not pushed to the editor yet.';
        if (e && typeof e.preventDefault === 'function') {
          e.preventDefault();
        }
        if (e) {
          e.returnValue = msg;
        }
        return msg;
      }
      return undefined;
    });

    window.addEventListener('pagehide', function () {
      builder.cleanup();
      stopAllProgressPolls();
    });

    $(document).on('click', '#rts-insert-letter, #rts-insert-letter-main', function (e) {
      e.preventDefault();
      insertRandomLetter($(this));
    });

    $(document).on('click', '#rts-insert-starter-layout', function (e) {
      e.preventDefault();
      appendStarterHtml();
    });

    $(document).on('click', '#rts-insert-cta-block', function (e) {
      e.preventDefault();
      appendCtaHtml();
    });

    $(document).on('click', '#rts-insert-socials, #rts-insert-socials-main', function (e) {
      e.preventDefault();
      appendSocialHtml();
    });

    $(document).on('click', '#rts-test-send-btn', function (e) {
      e.preventDefault();
      maybeSyncBuilderBeforeSend();

      var email = $('#rts-test-email').val() || cfg.admin_email;
      var postId = $('#post_ID').val();
      var $btn = $(this);
      var $msg = $('#rts-test-result');

      $btn.prop('disabled', true).text('Sending...');
      $msg.html('');

      ajaxPost({
        action: 'rts_newsletter_test_send',
        nonce: cfg.nonce,
        post_id: postId,
        email: email
      }, function (resp) {
        $btn.prop('disabled', false).text('Send Test');
        if (resp && resp.success) {
          $msg.html('<div class="rts-nl-note rts-nl-note--success">Test sent to ' + escHtml(email) + '</div>');
        } else {
          var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Send failed.';
          $msg.html('<div class="rts-nl-note rts-nl-note--danger">' + escHtml(message) + '</div>');
        }
      });
    });

    $(document).on('click', '#rts-send-all-btn', function (e) {
      e.preventDefault();
      maybeSyncBuilderBeforeSend();

      if (!window.confirm('Send this newsletter to ALL active subscribers?')) {
        return;
      }

      var postId = $('#post_ID').val();
      var $btn = $(this);
      var $progress = $('#rts-send-progress');

      $btn.prop('disabled', true);
      $progress.html('<div class="rts-nl-note rts-nl-note--warn">Queuing emails...</div>');

      ajaxPost({
        action: 'rts_newsletter_send_all',
        nonce: cfg.nonce,
        post_id: postId
      }, function (resp) {
        if (resp && resp.success) {
          $progress.html('<div class="rts-nl-note rts-nl-note--success">Queued ' + (resp.data.total || 0) + ' emails for delivery.</div>');
          startProgressPoll(postId);
          return;
        }

        $btn.prop('disabled', false);
        var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Queue failed.';
        $progress.html('<div class="rts-nl-note rts-nl-note--danger">' + escHtml(message) + '</div>');
      });
    });
  });
})(jQuery);
