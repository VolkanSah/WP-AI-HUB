/**
 * WP AI Hub — Frontend Client
 * Vanilla JS, no jQuery, no frameworks.
 * Handles all chat instances (widget + shortcode) on the page.
 */
(function () {
    'use strict';

    const AJAX = aihub.ajax_url;
    const i18n = aihub.i18n;

    // ── Helpers ───────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Minimal Markdown → safe HTML
     * Handles: **bold**, *italic*, `code`, ```blocks```, headings, lists, links
     * Output is sanitised — only safe tags emitted.
     */
    function renderMarkdown(text) {
        var s = escHtml(text);
        // Code blocks first (multi-line)
        s = s.replace(/```[\s\S]*?```/g, function(m) {
            return '<pre><code>' + m.slice(3, -3).replace(/^[^\n]*\n?/, '') + '</code></pre>';
        });
        // Inline code
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Bold
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Italic
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
        // Headings
        s = s.replace(/^### (.+)$/gm, '<h4>$1</h4>');
        s = s.replace(/^## (.+)$/gm,  '<h3>$1</h3>');
        s = s.replace(/^# (.+)$/gm,   '<h2>$1</h2>');
        // Unordered list items
        s = s.replace(/^\s*[-*] (.+)$/gm, '<li>$1</li>');
        s = s.replace(/(<li>.*<\/li>)/s,  '<ul>$1</ul>');
        // Line breaks
        s = s.replace(/\n{2,}/g, '</p><p>');
        s = s.replace(/\n/g,     '<br>');
        return '<p>' + s + '</p>';
    }

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce',  aihub.nonce);
        for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) {
                fd.append(k, data[k]);
            }
        }
        return fetch(AJAX, { method: 'POST', body: fd }).then(function(r) {
            return r.json();
        });
    }

    // ── Chat Instance ─────────────────────────────────────────────────────────

    function AiHubChat(root) {
        this.root     = root;
        this.messages = root.querySelector('[id$="-messages"]');
        this.input    = root.querySelector('[id$="-input"]');
        this.sendBtn  = root.querySelector('[id$="-send"]');
        this.provider = root.dataset.provider || '';
        this.model    = root.dataset.model    || '';
        this.busy     = false;

        this._bind();
    }

    AiHubChat.prototype._bind = function () {
        var self = this;

        this.sendBtn.addEventListener('click', function () { self._send(); });

        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                self._send();
            }
        });
    };

    AiHubChat.prototype._send = function () {
        if (this.busy) return;

        var text = this.input.value.trim();
        if (!text) return;

        this._append('user', text);
        this.input.value = '';
        this._setLoading(true);

        var self = this;
        post('aihub_chat', {
            message:  text,
            provider: this.provider,
            model:    this.model,
        }).then(function (d) {
            self._setLoading(false);
            if (d.success) {
                self._append('assistant', d.data.response);
            } else {
                self._append('error', d.data?.message || i18n.error);
            }
        }).catch(function () {
            self._setLoading(false);
            self._append('error', i18n.error);
        });
    };

    AiHubChat.prototype._append = function (role, content) {
        var msg  = document.createElement('div');
        msg.className = 'aihub-msg aihub-msg--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'aihub-msg__bubble';

        if (role === 'user') {
            // User input: plain text only — never html()
            bubble.textContent = content;
        } else if (role === 'assistant') {
            // Assistant: render markdown (already sanitised via escHtml inside renderMarkdown)
            bubble.innerHTML = renderMarkdown(content);
        } else {
            // Error: plain text
            bubble.textContent = content;
        }

        msg.appendChild(bubble);
        this.messages.appendChild(msg);
        this.messages.scrollTop = this.messages.scrollHeight;
    };

    AiHubChat.prototype._setLoading = function (state) {
        this.busy = state;
        this.sendBtn.disabled    = state;
        this.sendBtn.textContent = state ? '…' : '';
        if (!state) {
            // restore icon
            this.sendBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
        }
    };

    // ── Widget Toggle ─────────────────────────────────────────────────────────

    function initWidget() {
        var toggle  = document.getElementById('aihub-widget-toggle');
        var widget  = document.getElementById('aihub-widget');
        if (!toggle || !widget) return;

        toggle.addEventListener('click', function () {
            widget.classList.toggle('aihub-hidden');
            toggle.classList.toggle('aihub-widget-toggle--open');
        });

        widget.querySelector('.aihub-chat__close')?.addEventListener('click', function () {
            widget.classList.add('aihub-hidden');
            toggle.classList.remove('aihub-widget-toggle--open');
        });
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        // Init all chat instances on the page
        document.querySelectorAll('.aihub-chat').forEach(function (el) {
            new AiHubChat(el);
        });
        initWidget();
    });

})();
