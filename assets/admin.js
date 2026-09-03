jQuery(function($) {
    'use strict';

    var queueState = {
        page: 1,
        limit: 20,
        status: 'pending',
        search: ''
    };

    /* ==========================================================
       i18n
       ========================================================== */

    function t(key, fallback) {
        var lang = (wpnc_ajax && wpnc_ajax.lang) || 'en';
        if (lang === 'fa' && wpnc_ajax.i18n_fa && wpnc_ajax.i18n_fa[key]) {
            return wpnc_ajax.i18n_fa[key];
        }
        return (wpnc_ajax.i18n && wpnc_ajax.i18n[key]) || fallback || key;
    }

    /* ==========================================================
       Request layer

       Every call goes through request(). It rejects with the same
       {message, code, status} shape whether the failure was the network,
       the server, or an application-level success:false — so each caller
       has exactly one place to handle failure instead of four call sites
       silently having none.
       ========================================================== */

    function errorFromResponse(response) {
        var data = response && response.data;

        if (typeof data === 'string' && data) {
            return { message: data, code: 'wpnc_error', status: 200 };
        }
        if (data && data.message) {
            return {
                message: data.message,
                code: data.code || 'wpnc_error',
                status: 200,
                data: data
            };
        }
        return {
            message: t('error_server', 'The server rejected the request.'),
            code: 'wpnc_unknown',
            status: 200
        };
    }

    function errorFromTransport(jqXHR, textStatus) {
        var status = jqXHR ? jqXHR.status : 0;

        // Distinguishing these is the whole point: "check your connection"
        // and "your session expired" need different actions from the user.
        if (status === 0) {
            return {
                message: t('error_network', 'Could not reach the server. Check your connection and try again.'),
                code: 'wpnc_network',
                status: 0
            };
        }
        if (status === 403) {
            return {
                message: t('error_forbidden', 'Your session expired or you lack permission. Reload the page and sign in again.'),
                code: 'wpnc_forbidden',
                status: 403
            };
        }
        if (status >= 500) {
            return {
                message: t('error_server', 'The server returned an error. Check Logs & Tools for details.'),
                code: 'wpnc_server',
                status: status
            };
        }
        if (textStatus === 'timeout') {
            return {
                message: t('error_timeout', 'The request timed out. Try again.'),
                code: 'wpnc_timeout',
                status: status
            };
        }

        // A JSON parse failure usually means a PHP notice was printed
        // before the response body.
        if (textStatus === 'parsererror') {
            return {
                message: t('error_parse', 'The server sent an unreadable response. Check Logs & Tools.'),
                code: 'wpnc_parse',
                status: status
            };
        }

        return {
            message: t('error_server', 'The server returned an error.'),
            code: 'wpnc_http',
            status: status
        };
    }

    function request(action, data) {
        return $.ajax({
            url: wpnc_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: wpnc_ajax.nonce }, data || {})
        }).then(
            function(response) {
                if (response && response.success) {
                    return response.data;
                }
                return $.Deferred().reject(errorFromResponse(response)).promise();
            },
            function(jqXHR, textStatus) {
                return $.Deferred().reject(errorFromTransport(jqXHR, textStatus)).promise();
            }
        );
    }

    /* ==========================================================
       Shared state rendering: loading, empty, error
       ========================================================== */

    function renderLoading($el) {
        $el.empty().append(
            $('<p>').addClass('wpnc-state wpnc-state-loading')
                .append($('<span>').addClass('wpnc-spinner'))
                .append(document.createTextNode(' ' + t('loading', 'Loading...')))
        );
    }

    /* An empty state that only says "nothing here" leaves you to work out what
       to do about it. Where there is an obvious next step, it offers it. */
    function renderEmpty($el, message, hint, action) {
        var $box = $('<div>').addClass('wpnc-state wpnc-state-empty');
        $('<p>').addClass('wpnc-state-title').text(message).appendTo($box);
        if (hint) {
            $('<p>').addClass('wpnc-state-hint').attr('dir', 'auto').text(hint).appendTo($box);
        }
        if (action && action.href) {
            $('<a>')
                .addClass('button button-primary wpnc-state-action')
                .attr('href', action.href)
                .text(action.label)
                .appendTo($box);
        } else if (action) {
            $('<button>')
                .attr('type', 'button')
                .addClass('button button-primary wpnc-state-action')
                .text(action.label)
                .on('click', action.run)
                .appendTo($box);
        }
        $el.empty().append($box);
    }

    function panelUrl(tab) {
        return (wpnc_ajax.panel_url || '') + tab;
    }

    function renderError($el, error, onRetry) {
        var $box = $('<div>').addClass('wpnc-state wpnc-state-error');
        $('<p>').addClass('wpnc-state-title').attr('dir', 'auto').text(error.message).appendTo($box);

        if (typeof onRetry === 'function') {
            $('<button>')
                .attr('type', 'button')
                .addClass('button')
                .text(t('retry', 'Try again'))
                .on('click', onRetry)
                .appendTo($box);
        }

        $el.empty().append($box);
    }

    function setBusy($button, busy) {
        if (!$button || !$button.length) {
            return;
        }
        if (busy) {
            $button.data('original-text', $button.text());
            $button.text(t('processing', 'Processing...')).prop('disabled', true);
        } else {
            $button.text($button.data('original-text') || $button.text()).prop('disabled', false);
        }
    }

    /* Transient banner for actions that do not own a region of the page. */
    /* Toasts live in a fixed corner region rather than at the top of the tab.
       Prepending to the content meant a message could land above the fold and
       be scrolled past unseen - which for an error is the same as not showing
       it at all. */
    function toastHost() {
        var $host = $('#wpnc-toasts');
        if ($host.length) {
            return $host;
        }

        var $wrap = $('.wpnc-wrap').first();
        if (!$wrap.length) {
            return $();
        }

        return $('<div>')
            .attr({ id: 'wpnc-toasts', 'aria-live': 'polite' })
            .addClass('wpnc-toasts')
            .appendTo($wrap);
    }

    function flash(message, type) {
        var $host = toastHost();
        if (!$host.length) {
            return;
        }

        var $note = $('<div>')
            .addClass('wpnc-flash wpnc-flash-' + (type || 'ok'))
            .attr({ role: 'status', dir: 'auto' });

        $('<span>').addClass('wpnc-flash-text').text(message).appendTo($note);
        $('<button>')
            .attr({ type: 'button', 'aria-label': t('dismiss', 'Dismiss') })
            .addClass('wpnc-flash-close')
            .text('×')
            .on('click', function() { $note.remove(); })
            .appendTo($note);

        $host.append($note);

        // Errors wait to be read and dismissed; everything else is a receipt.
        if (type !== 'error') {
            window.setTimeout(function() {
                $note.fadeOut(400, function() { $(this).remove(); });
            }, 4500);
        }

        // Never let receipts stack past a screenful.
        var $all = $host.children('.wpnc-flash');
        if ($all.length > 4) {
            $all.slice(0, $all.length - 4).remove();
        }
    }

    /* ==========================================================
       Moderation queue
       ========================================================== */

    function loadQueue() {
        var $app = $('#wpnc-moderation-app');
        if (!$app.length) {
            return;
        }

        renderLoading($app);

        request('wpnc_get_queue', queueState)
            .done(function(data) {
                renderQueue(data);
            })
            .fail(function(error) {
                renderError($app, error, loadQueue);
            });
    }

    function statusLabel(key) {
        var labels = {
            pending: t('pending_opt', 'Pending'),
            error: t('error_opt', 'Error'),
            approved: t('approved_opt', 'Approved'),
            rejected: t('rejected_opt', 'Rejected')
        };
        return labels[key] || key;
    }

    function emptyStateFor(status) {
        if (queueState.search) {
            return {
                hint: t('empty_search_hint', 'No item matches this search. Clear the search box to see the whole queue.'),
                action: {
                    label: t('clear_search', 'Clear the search'),
                    run: function() {
                        queueState.search = '';
                        queueState.page = 1;
                        loadQueue();
                    }
                }
            };
        }

        if (status === 'pending') {
            return {
                hint: t('empty_pending_hint', 'Add RSS sources under Settings, then run Fetch Now from Logs & Tools.'),
                action: { label: t('go_to_tools', 'Fetch now'), href: panelUrl('logs') }
            };
        }

        return { hint: t('empty_status_hint', 'Nothing has reached this status yet.') };
    }

    function renderQueue(data) {
        var $app = $('#wpnc-moderation-app');
        var items = data.items || [];
        $app.empty();

        var $toolbar = $('<div>').addClass('wpnc-queue-toolbar').appendTo($app);

        $('<label>')
            .addClass('screen-reader-text')
            .attr('for', 'wpnc-queue-search')
            .text(t('search', 'Search...'))
            .appendTo($toolbar);
        $('<input>')
            .attr({ type: 'search', id: 'wpnc-queue-search', placeholder: t('search', 'Search...'), dir: 'auto' })
            .val(queueState.search)
            .on('search change keyup', debounce(function() {
                queueState.search = $(this).val();
                queueState.page = 1;
                loadQueue();
            }, 350))
            .appendTo($toolbar);

        $('<label>')
            .addClass('screen-reader-text')
            .attr('for', 'wpnc-queue-status')
            .text(t('filter_status', 'Filter by status'))
            .appendTo($toolbar);
        var $status = $('<select>').attr('id', 'wpnc-queue-status');
        ['pending', 'error', 'approved', 'rejected'].forEach(function(key) {
            $('<option>').val(key).text(statusLabel(key)).appendTo($status);
        });
        $status
            .val(queueState.status)
            .on('change', function() {
                queueState.status = $(this).val();
                queueState.page = 1;
                loadQueue();
            })
            .appendTo($toolbar);

        var terminal = queueState.status === 'approved' || queueState.status === 'rejected';

        if (!terminal) {
            var $bulk = $('<div>').addClass('wpnc-bulk-actions').appendTo($app);
            $('<label>')
                .append($('<input>').attr({ type: 'checkbox', id: 'wpnc-select-all' }))
                .append(document.createTextNode(' ' + t('select_all', 'Select All')))
                .appendTo($bulk);
            $('<button>').attr('type', 'button').addClass('button button-primary').attr('id', 'wpnc-bulk-approve').text(t('approve_selected', 'Approve Selected')).appendTo($bulk);
            $('<button>').attr('type', 'button').addClass('button').attr('id', 'wpnc-bulk-reject').text(t('reject_selected', 'Reject Selected')).appendTo($bulk);
            $('<button>').attr('type', 'button').addClass('button button-link-delete').attr('id', 'wpnc-bulk-delete').text(t('delete_selected', 'Delete Selected')).appendTo($bulk);
        } else {
            var $terminalBulk = $('<div>').addClass('wpnc-bulk-actions').appendTo($app);
            $('<label>')
                .append($('<input>').attr({ type: 'checkbox', id: 'wpnc-select-all' }))
                .append(document.createTextNode(' ' + t('select_all', 'Select All')))
                .appendTo($terminalBulk);
            $('<button>').attr('type', 'button').addClass('button button-link-delete').attr('id', 'wpnc-bulk-delete').text(t('delete_selected', 'Delete Selected')).appendTo($terminalBulk);
        }

        if (!items.length) {
            renderEmptyQueue($app, emptyStateFor(queueState.status));
            renderPagination($app, data);
            bindQueueEvents();
            syncExportLink();
            return;
        }

        var $grid = $('<div>').addClass('wpnc-grid').appendTo($app);
        items.forEach(function(item) {
            renderCard($grid, item, terminal);
        });

        renderEditModal($app);
        renderPagination($app, data);
        bindQueueEvents();
        syncExportLink();
    }

    /* The export is a normal link so the browser handles the download; keep
       its query in step with what the user is actually looking at. */
    function syncExportLink() {
        var $link = $('#wpnc-export-queue');
        if (!$link.length) {
            return;
        }

        var href = $link.attr('href')
            .replace(/([?&]status=)[^&]*/, '$1' + encodeURIComponent(queueState.status))
            .replace(/&search=[^&]*/, '');

        if (queueState.search) {
            href += '&search=' + encodeURIComponent(queueState.search);
        }

        $link.attr('href', href);
    }

    function renderEmptyQueue($app, state) {
        var $slot = $('<div>').appendTo($app);
        renderEmpty($slot, t('no_pending', 'No pending news in the queue.'), state.hint, state.action);
    }

    function renderCard($grid, item, terminal) {
        var $card = $('<div>').addClass('wpnc-card').attr('id', 'wpnc-item-' + item.id).appendTo($grid);

        $('<div>').addClass('wpnc-card-header')
            .append(
                $('<input>')
                    .attr({ type: 'checkbox', 'aria-label': t('select_item', 'Select this item') })
                    .addClass('wpnc-item-checkbox')
                    .val(item.id)
            )
            .appendTo($card);

        if (item.image_url) {
            $('<img>').attr({ src: item.image_url, alt: '', loading: 'lazy' }).appendTo($card);
        } else {
            $('<div>').addClass('wpnc-no-img').text(t('no_image', 'No Image')).appendTo($card);
        }

        var $content = $('<div>').addClass('wpnc-card-content').appendTo($card);

        // dir="auto" lets the browser detect RTL (Persian) vs LTR per content block.
        $('<h4>').attr('dir', 'auto').text(item.title || '').appendTo($content);
        $('<p>').addClass('wpnc-source').attr('dir', 'auto').text(item.source_name || '').appendTo($content);
        $('<p>').addClass('wpnc-date').text(item.pub_date_display || item.pub_date || '').appendTo($content);

        if (item.tags) {
            $('<p>').addClass('wpnc-tags').attr('dir', 'auto').text(t('tags', 'Tags') + ': ' + item.tags).appendTo($content);
        }
        if (item.error_message) {
            $('<p>').addClass('wpnc-error-message').attr('dir', 'auto').text(item.error_message).appendTo($content);
        }

        var $actions = $('<div>').addClass('wpnc-actions').appendTo($content);

        if (terminal) {
            $('<span>').addClass('wpnc-badge wpnc-badge-' + item.status).text(statusLabel(item.status)).appendTo($actions);

            if (item.post_id) {
                $('<a>')
                    .addClass('button')
                    .attr({ href: wpnc_ajax.post_edit_base + item.post_id, target: '_blank', rel: 'noopener' })
                    .text(t('view_post', 'View post'))
                    .appendTo($actions);
            }

            if (item.status === 'approved') {
                $('<button>').attr('type', 'button').addClass('button wpnc-unpublish')
                    .data('id', item.id).text(t('undo_approve', 'Undo approve')).appendTo($actions);
            }

            $('<button>').attr('type', 'button').addClass('button button-link-delete wpnc-delete')
                .data('id', item.id).text(t('delete', 'Delete')).appendTo($actions);
            return;
        }

        $('<button>').attr('type', 'button').addClass('button button-primary wpnc-approve').data('id', item.id).text(t('approve', 'Approve')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-edit').data('item', item).text(t('edit', 'Edit')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-reject').data('id', item.id).text(t('reject', 'Reject')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button button-link-delete wpnc-delete').data('id', item.id).text(t('delete', 'Delete')).appendTo($actions);
    }

    /* ==========================================================
       Edit modal
       ========================================================== */

    function labelledField($parent, id, labelText, $field) {
        var $wrap = $('<p>').addClass('wpnc-field').appendTo($parent);
        $('<label>').attr('for', id).text(labelText).appendTo($wrap);
        $field.attr('id', id).appendTo($wrap);
        return $field;
    }

    /* ==========================================================
       Edit modal: a workbench, not a text box

       The description is edited in WordPress's own TinyMCE via
       wp.editor.initialize(), so formatting, links, lists and images all
       survive - which only matters because full-text extraction now keeps
       them instead of flattening the article to plain paragraphs.
       ========================================================== */

    var EDITOR_ID = 'wpnc-edit-desc';
    var editorHistory = [];
    var editorBaseline = null;
    var previewTimer = null;

    /* Compared against the editor to decide whether dismissing would throw
       work away. Cheap, and it never reports a false positive the way a
       "dirty" flag set on every keystroke would. */
    function editorSnapshot() {
        return JSON.stringify([
            $('#wpnc-edit-title').val() || '',
            editorGet(),
            $('#wpnc-edit-tags').val() || ''
        ]);
    }

    function editorDirty() {
        return editorBaseline !== null && editorSnapshot() !== editorBaseline;
    }

    function editorAvailable() {
        return !!(window.wp && wp.editor && typeof wp.editor.initialize === 'function');
    }

    function editorGet() {
        if (editorAvailable() && window.tinymce) {
            var ed = tinymce.get(EDITOR_ID);
            if (ed && !ed.isHidden()) {
                return ed.getContent();
            }
        }
        return $('#' + EDITOR_ID).val() || '';
    }

    function editorSet(html) {
        if (editorAvailable() && window.tinymce) {
            var ed = tinymce.get(EDITOR_ID);
            if (ed && !ed.isHidden()) {
                ed.setContent(html || '');
                $('#' + EDITOR_ID).val(html || '');
                return;
            }
        }
        $('#' + EDITOR_ID).val(html || '');
    }

    /* Every AI action and full-text load is undoable, because an assistant
       that silently replaces an editor's work is not usable. */
    function editorPush() {
        editorHistory.push(editorGet());
        $('#wpnc-editor-undo').prop('disabled', false);
    }

    function editorUndo() {
        if (!editorHistory.length) {
            return;
        }
        editorSet(editorHistory.pop());
        $('#wpnc-editor-undo').prop('disabled', !editorHistory.length);
        refreshPreview();
    }

    function editorStatus(message, type) {
        var $box = $('#wpnc-editor-status');
        if (!message) {
            $box.empty().hide();
            return;
        }
        $box.attr('class', 'wpnc-editor-status is-' + (type || 'ok'))
            .attr('dir', 'auto')
            .text(message)
            .show();
    }

    function updateCounts(stats) {
        if (!stats) {
            return;
        }
        $('#wpnc-editor-counts').text(
            stats.words + ' ' + t('words', 'words') +
            (stats.minutes ? '  ·  ' + stats.minutes + ' ' + t('read_minutes', 'min read') : '')
        );
    }

    function refreshPreview() {
        var $pane = $('#wpnc-preview-body');
        if (!$pane.length || !$('#wpnc-edit-modal').is(':visible')) {
            return;
        }

        request('wpnc_preview_item', {
            id: $('#wpnc-edit-id').val(),
            title: $('#wpnc-edit-title').val(),
            content: editorGet(),
            tags: $('#wpnc-edit-tags').val()
        })
            .done(function(data) {
                $('#wpnc-preview-title').text(data.title || '');
                // Server-rendered through the same template the publisher
                // uses, and already passed through wp_kses there.
                $pane.html(data.html || '');
                updateCounts(data.stats);
            })
            .fail(function(error) {
                $pane.empty().append(
                    $('<p>').addClass('wpnc-preview-error').attr('dir', 'auto').text(error.message)
                );
            });
    }

    /* Debounced: the preview is a server round trip, so it follows typing
       rather than racing it. */
    function schedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 700);
    }

    function renderAiStrip($parent) {
        var $strip = $('<div>').addClass('wpnc-ai').appendTo($parent);

        $('<div>').addClass('wpnc-ai-head')
            .append($('<span>').addClass('wpnc-ai-badge').text(t('ai_badge', 'AI')))
            .append($('<span>').addClass('wpnc-ai-title').text(t('ai_title', 'Assistant')))
            .appendTo($strip);

        if (!wpnc_ajax.ai_enabled) {
            $('<p>').addClass('wpnc-ai-off').attr('dir', 'auto')
                .text(t('ai_disabled', 'Add an OpenAI API key under Settings to use the assistant.'))
                .appendTo($strip);
            return;
        }

        var $actions = $('<div>').addClass('wpnc-ai-actions').appendTo($strip);
        var actions = wpnc_ajax.ai_actions || {};
        Object.keys(actions).forEach(function(key) {
            $('<button>')
                .attr({ type: 'button', 'data-action': key })
                .addClass('button button-small wpnc-ai-run')
                .text(actions[key])
                .appendTo($actions);
        });

        var $custom = $('<div>').addClass('wpnc-ai-custom').appendTo($strip);
        $('<label>')
            .addClass('screen-reader-text')
            .attr('for', 'wpnc-ai-instruction')
            .text(t('ai_instruction_label', 'What should the assistant change?'))
            .appendTo($custom);
        $('<input>')
            .attr({
                type: 'text',
                id: 'wpnc-ai-instruction',
                dir: 'auto',
                placeholder: t('ai_placeholder', 'e.g. add a short intro paragraph explaining the background')
            })
            .appendTo($custom);
        $('<button>')
            .attr({ type: 'button', 'data-action': 'custom' })
            .addClass('button button-primary wpnc-ai-run')
            .text(t('ai_apply', 'Apply'))
            .appendTo($custom);
    }

    function renderEditModal($app) {
        var $modal = $('<div>')
            .attr({ id: 'wpnc-edit-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'wpnc-edit-heading' })
            .addClass('wpnc-modal')
            .hide();
        var $content = $('<div>').addClass('wpnc-modal-content is-wide').appendTo($modal);

        var $head = $('<div>').addClass('wpnc-modal-head').appendTo($content);
        $('<h2>').attr('id', 'wpnc-edit-heading').text(t('edit_item', 'Edit News Item')).appendTo($head);
        $('<a>').attr({ id: 'wpnc-edit-source', target: '_blank', rel: 'noopener noreferrer' })
            .addClass('wpnc-modal-source')
            .text(t('open_original', 'Open the original'))
            .appendTo($head);

        $('<input>').attr({ type: 'hidden', id: 'wpnc-edit-id' }).appendTo($content);

        labelledField($content, 'wpnc-edit-title', t('field_title', 'Title'),
            $('<input>').attr({ type: 'text', dir: 'auto' }).addClass('large-text'));


        var $split = $('<div>').addClass('wpnc-split').appendTo($content);
        var $left = $('<div>').addClass('wpnc-split-edit').appendTo($split);
        var $right = $('<div>').addClass('wpnc-split-preview').appendTo($split);

        $('<div>').addClass('wpnc-preview-head')
            .append($('<span>').addClass('wpnc-preview-label').text(t('preview', 'Preview')))
            .append($('<span>').attr('id', 'wpnc-editor-counts').addClass('wpnc-preview-counts'))
            .appendTo($right);

        var $paper = $('<div>').addClass('wpnc-preview-paper').appendTo($right);
        $('<h3>').attr({ id: 'wpnc-preview-title', dir: 'auto' }).addClass('wpnc-preview-title').appendTo($paper);
        $('<div>').attr({ id: 'wpnc-preview-body', dir: 'auto' }).addClass('wpnc-preview-body').appendTo($paper);

        var $descField = $('<div>').addClass('wpnc-field').appendTo($left);
        var $descHead = $('<div>').addClass('wpnc-field-head').appendTo($descField);
        $('<label>').attr('for', EDITOR_ID).text(t('field_description', 'Description')).appendTo($descHead);

        var $tools = $('<span>').addClass('wpnc-field-tools').appendTo($descHead);
        $('<button>').attr({ type: 'button', id: 'wpnc-load-full-text' })
            .addClass('button button-small')
            .text(t('load_full_text', 'Load full article'))
            .appendTo($tools);
        $('<button>').attr({ type: 'button', id: 'wpnc-editor-undo' })
            .addClass('button button-small')
            .prop('disabled', true)
            .text(t('undo', 'Undo'))
            .appendTo($tools);

        $('<textarea>').attr({ id: EDITOR_ID, rows: 16, dir: 'auto' }).addClass('large-text').appendTo($descField);
        $('<div>').attr('id', 'wpnc-editor-status').addClass('wpnc-editor-status').hide().appendTo($descField);

        renderAiStrip($descField);

        labelledField($left, 'wpnc-edit-tags', t('field_tags', 'Tags (comma separated)'),
            $('<input>').attr({ type: 'text', dir: 'auto' }).addClass('large-text'));

        $('<p>').addClass('wpnc-modal-actions')
            .append($('<button>').attr('type', 'button').addClass('button button-primary').attr('id', 'wpnc-save-edit').text(t('save', 'Save')))
            .append($('<button>').attr('type', 'button').addClass('button').attr('id', 'wpnc-close-modal').text(t('cancel', 'Cancel')))
            .appendTo($content);

        $('<div>').attr('id', 'wpnc-edit-error').addClass('wpnc-inline-error').attr('dir', 'auto').hide().appendTo($content);

        $modal.appendTo($app);
    }

    function openModal(item) {
        editorHistory = [];

        $('#wpnc-edit-id').val(item.id);
        $('#wpnc-edit-title').val(item.title || '');
        $('#wpnc-edit-tags').val(item.tags || '');
        $('#wpnc-edit-error').hide().empty();
        $('#wpnc-editor-undo').prop('disabled', true);
        editorStatus('');

        var $source = $('#wpnc-edit-source');
        if (item.main_link) {
            $source.attr('href', item.main_link).show();
        } else {
            $source.hide();
        }

        $('#wpnc-edit-modal').show();

        // TinyMCE has to be initialised while the textarea is visible, and
        // torn down on close or the next open gets a stale instance.
        if (editorAvailable()) {
            wp.editor.remove(EDITOR_ID);
            $('#' + EDITOR_ID).val(item.description || '');
            wp.editor.initialize(EDITOR_ID, {
                tinymce: {
                    wpautop: true,
                    toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,removeformat,undo,redo',
                    directionality: (wpnc_ajax.lang === 'fa') ? 'rtl' : 'ltr'
                },
                quicktags: true,
                mediaButtons: true
            });
        } else {
            $('#' + EDITOR_ID).val(item.description || '');
        }

        $('#wpnc-edit-title').trigger('focus');

        // Baseline after the editor is populated, so simply opening and
        // closing is never treated as a change.
        window.setTimeout(function() {
            editorBaseline = editorSnapshot();
            refreshPreview();
        }, 250);

        $('#wpnc-edit-title, #wpnc-edit-tags').off('input.wpncpreview').on('input.wpncpreview', schedulePreview);
    }

    /**
     * Dismiss the modal.
     *
     * An AI run can cost money and replace the whole article, and there were
     * three ways to throw that away without being asked: Cancel, the
     * backdrop, and Escape.
     */
    function closeModal(force) {
        if (!force && editorDirty()) {
            if (!window.confirm(t('confirm_discard', 'Discard the changes you made to this item?'))) {
                return;
            }
        }

        window.clearTimeout(previewTimer);
        if (editorAvailable()) {
            wp.editor.remove(EDITOR_ID);
        }
        editorHistory = [];
        editorBaseline = null;
        $('#wpnc-edit-modal').hide();
    }

    /* ==========================================================
       Pagination
       ========================================================== */

    function renderPagination($app, data) {
        var totalPages = parseInt(data.total_pages || 1, 10);
        var page = parseInt(data.page || 1, 10);

        if (totalPages <= 1 && !(data.total || 0)) {
            return;
        }

        var $pager = $('<div>').addClass('wpnc-pagination').appendTo($app);

        $('<button>')
            .attr('type', 'button')
            .addClass('button')
            .prop('disabled', page <= 1)
            .text(t('previous', 'Previous'))
            .on('click', function() {
                queueState.page = Math.max(1, page - 1);
                loadQueue();
            })
            .appendTo($pager);

        $('<span>').text(page + ' / ' + totalPages + ' (' + (data.total || 0) + ')').appendTo($pager);

        $('<button>')
            .attr('type', 'button')
            .addClass('button')
            .prop('disabled', page >= totalPages)
            .text(t('next', 'Next'))
            .on('click', function() {
                queueState.page = page + 1;
                loadQueue();
            })
            .appendTo($pager);
    }

    /* ==========================================================
       Queue actions
       ========================================================== */

    function bindQueueEvents() {
        $('#wpnc-select-all').off('change').on('change', function() {
            $('.wpnc-item-checkbox').prop('checked', $(this).prop('checked'));
        });

        $('.wpnc-approve').off('click').on('click', function() {
            actionOne($(this), 'wpnc_approve_item');
        });

        $('.wpnc-reject').off('click').on('click', function() {
            if (!window.confirm(t('confirm_reject', 'Reject selected item(s)?'))) {
                return;
            }
            actionOne($(this), 'wpnc_reject_item');
        });

        $('.wpnc-edit').off('click').on('click', function() {
            openModal($(this).data('item'));
        });

        $('#wpnc-close-modal').off('click').on('click', closeModal);

        $('#wpnc-edit-modal').off('click').on('click', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $('#wpnc-save-edit').off('click').on('click', saveEdit);
        $('#wpnc-load-full-text').off('click').on('click', loadFullText);
        $('#wpnc-editor-undo').off('click').on('click', editorUndo);
        $('.wpnc-ai-run').off('click').on('click', function() {
            runAi($(this), $(this).data('action'));
        });

        $('#wpnc-bulk-approve').off('click').on('click', function() {
            actionBulk($(this), 'wpnc_bulk_approve');
        });

        $('#wpnc-bulk-reject').off('click').on('click', function() {
            if (!window.confirm(t('confirm_reject', 'Reject selected item(s)?'))) {
                return;
            }
            actionBulk($(this), 'wpnc_bulk_reject');
        });

        $('.wpnc-delete').off('click').on('click', function() {
            if (!window.confirm(t('confirm_delete', 'Permanently delete this item from the queue? Any post it already published stays on the site.'))) {
                return;
            }
            actionOne($(this), 'wpnc_delete_item');
        });

        $('#wpnc-bulk-delete').off('click').on('click', function() {
            if (!window.confirm(t('confirm_delete_bulk', 'Permanently delete the selected items from the queue? This cannot be undone.'))) {
                return;
            }
            actionBulk($(this), 'wpnc_bulk_delete');
        });

        $('.wpnc-unpublish').off('click').on('click', function() {
            if (!window.confirm(t('confirm_unpublish', 'Move the published post to Trash and return this item to the queue?'))) {
                return;
            }
            actionOne($(this), 'wpnc_unpublish_item');
        });
    }

    function loadFullText() {
        var $button = $('#wpnc-load-full-text');

        setBusy($button, true);
        editorStatus(t('loading', 'Loading...'), 'busy');

        request('wpnc_fetch_full_text', { id: $('#wpnc-edit-id').val() })
            .done(function(data) {
                editorPush();
                editorSet(data.content);
                editorStatus(data.message, 'ok');
                refreshPreview();
            })
            .fail(function(error) {
                editorStatus(error.message, 'error');
            })
            .always(function() {
                setBusy($button, false);
            });
    }

    function runAi($button, action) {
        var instruction = $('#wpnc-ai-instruction').val() || '';

        if (action === 'custom' && !$.trim(instruction)) {
            editorStatus(t('ai_need_instruction', 'Tell the assistant what to change.'), 'error');
            $('#wpnc-ai-instruction').trigger('focus');
            return;
        }

        $('.wpnc-ai-run').prop('disabled', true);
        setBusy($button, true);
        editorStatus(t('ai_working', 'The assistant is working on it...'), 'busy');

        request('wpnc_ai_transform', {
            id: $('#wpnc-edit-id').val(),
            title: $('#wpnc-edit-title').val(),
            content: editorGet(),
            ai_action: action,
            instruction: instruction
        })
            .done(function(data) {
                editorPush();
                editorSet(data.content);
                editorStatus(data.message + ' ' + t('ai_undo_hint', 'Use Undo to go back.'), 'ok');
                refreshPreview();
            })
            .fail(function(error) {
                editorStatus(error.message, 'error');
            })
            .always(function() {
                $('.wpnc-ai-run').prop('disabled', false);
                setBusy($button, false);
            });
    }

    function saveEdit() {
        var $button = $(this);
        var $error = $('#wpnc-edit-error');
        var title = $.trim($('#wpnc-edit-title').val());

        $error.hide().empty();

        if (!title) {
            $error.text(t('title_required', 'Title is required.')).show();
            $('#wpnc-edit-title').trigger('focus');
            return;
        }

        setBusy($button, true);
        request('wpnc_edit_item', {
            id: $('#wpnc-edit-id').val(),
            title: title,
            description: editorGet(),
            tags: $('#wpnc-edit-tags').val()
        })
            .done(function(data) {
                editorBaseline = null;
                closeModal(true);
                flash((data && data.message) || t('saved', 'Saved.'), 'ok');
                loadQueue();
            })
            .fail(function(error) {
                // Previously this had no failure branch at all: a network
                // error just re-enabled the button and lost the edit.
                $error.text(error.message).show();
            })
            .always(function() {
                setBusy($button, false);
            });
    }

    function actionOne($button, action) {
        var id = $button.data('id');
        var $card = $('#wpnc-item-' + id);

        setBusy($button, true);
        $card.addClass('wpnc-card-busy');

        request(action, { id: id })
            .done(function(data) {
                flash((data && data.message) || t('done', 'Done.'), 'ok');
                $card.fadeOut(function() {
                    $(this).remove();
                    if (!$('.wpnc-card').length) {
                        loadQueue();
                    }
                });
            })
            .fail(function(error) {
                $card.removeClass('wpnc-card-busy');
                flash(error.message, 'error');

                // The row moved on without us; show its real state.
                if (error.code === 'wpnc_already_processed' || error.code === 'wpnc_not_found') {
                    loadQueue();
                }
            })
            .always(function() {
                setBusy($button, false);
            });
    }

    function actionBulk($button, action) {
        var ids = $('.wpnc-item-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            flash(t('select_something', 'Select at least one item first.'), 'warn');
            return;
        }

        setBusy($button, true);
        request(action, { ids: ids })
            .done(function(data) {
                var failed = (data && (data.failed || data.skipped)) || 0;
                flash((data && data.message) || t('done', 'Done.'), failed ? 'warn' : 'ok');
                loadQueue();
            })
            .fail(function(error) {
                flash(error.message, 'error');
            })
            .always(function() {
                setBusy($button, false);
            });
    }

    /* ==========================================================
       Stats
       ========================================================== */

    function loadStats() {
        var $stats = $('#wpnc-stats-summary');
        if (!$stats.length) {
            return;
        }

        renderLoading($stats);

        request('wpnc_get_stats')
            .done(function(data) {
                renderStats($stats, data || {});
            })
            .fail(function(error) {
                // This block used to stay silently blank forever on failure.
                renderError($stats, error, loadStats);
            });
    }

    function renderStats($stats, data) {
        var colorMap = {
            pending: 'wpnc-stat-warning',
            approved: 'wpnc-stat-success',
            rejected: '',
            error: 'wpnc-stat-error'
        };
        var total = 0;

        $stats.empty();
        ['pending', 'approved', 'rejected', 'error'].forEach(function(key) {
            var count = data[key] || 0;
            total += count;
            $('<div>').addClass('wpnc-stat ' + (colorMap[key] || ''))
                .append($('<strong>').text(count))
                .append($('<span>').text(statusLabel(key)))
                .appendTo($stats);
        });

        if (!total) {
            $('<p>').addClass('wpnc-state-hint').attr('dir', 'auto')
                .text(t('empty_stats_hint', 'The queue is empty. Add sources under Settings, then run Fetch Now.'))
                .appendTo($stats);
        }
    }

    /* ==========================================================
       Logs
       ========================================================== */

    function loadLogs() {
        var $logs = $('#wpnc-logs-app');
        if (!$logs.length) {
            return;
        }

        renderLoading($logs);

        request('wpnc_get_logs', { limit: 50, level: $('#wpnc-log-level').val() || '' })
            .done(function(data) {
                var logs = (data && data.logs) || [];
                if (!logs.length) {
                    // Failure and "nothing logged yet" used to share this
                    // branch, so an error read as an empty log.
                    renderEmpty($logs,
                        t('no_logs', 'No logs yet.'),
                        $('#wpnc-log-level').val()
                            ? t('empty_level_hint', 'Nothing was logged at this level. Choose All to see every entry.')
                            : t('empty_logs_hint', 'Run Fetch Now above and the result will appear here.'));
                    return;
                }
                renderLogs($logs, logs);
            })
            .fail(function(error) {
                renderError($logs, error, loadLogs);
            });
    }

    function renderLogs($logs, logs) {
        $logs.empty();

        var $scroll = $('<div>').addClass('wpnc-table-scroll').appendTo($logs);
        var $table = $('<table>').addClass('widefat striped wpnc-logs-table').appendTo($scroll);

        $('<thead>').append($('<tr>')
            .append($('<th>').text(t('col_time', 'Time')))
            .append($('<th>').text(t('col_level', 'Level')))
            .append($('<th>').text(t('col_source', 'Source')))
            .append($('<th>').text(t('col_message', 'Message')))
        ).appendTo($table);

        var $body = $('<tbody>').appendTo($table);
        logs.forEach(function(log) {
            var $row = $('<tr>').appendTo($body);
            $('<td>').text(log.created_at_display || log.created_at || '').appendTo($row);
            $('<td>').append(
                $('<span>').addClass('wpnc-log-level wpnc-log-' + (log.level || '')).text(log.level || '')
            ).appendTo($row);
            $('<td>').text(log.source || '').appendTo($row);
            $('<td>').attr('dir', 'auto').text(log.message || '').appendTo($row);
        });
    }

    /* ==========================================================
       Fetch tool
       ========================================================== */

    function bindFetchTool(opts) {
        opts = opts || {};

        var $btn = $(opts.button || '#wpnc-run-fetch');
        var $clear = $(opts.clear || '#wpnc-clear-lock');
        var $status = $(opts.status || '#wpnc-fetch-status');
        var $progress = $(opts.progress || '#wpnc-fetch-progress');
        var $fill = $progress.find('.wpnc-progress-fill');
        var $label = $progress.find('.wpnc-progress-text');
        var onDone = opts.onDone || function() {};

        if (!$btn.length) {
            return;
        }

        // The dashboard has no progress bar markup of its own; build one so
        // the same code path can report into it.
        if (!$progress.length && opts.status) {
            $progress = $('<div>')
                .addClass('wpnc-progress-wrap')
                .attr({ role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 })
                .hide()
                .append($('<div>').addClass('wpnc-progress-bar')
                    .append($('<div>').addClass('wpnc-progress-fill')))
                .append($('<span>').addClass('wpnc-progress-text'))
                .appendTo($status.parent());
            $fill = $progress.find('.wpnc-progress-fill');
            $label = $progress.find('.wpnc-progress-text');
        }

        function setProgress(pct, text) {
            $fill.css('width', pct + '%');
            $progress.attr('aria-valuenow', pct);
            $label.text(text || '');
        }

        function setStatus(msg, type) {
            $status.empty().append(
                $('<span>').addClass('wpnc-status-' + (type || 'ok')).attr('dir', 'auto').text(msg)
            );
        }

        function stop() {
            $progress.hide();
            setBusy($btn, false);
        }

        $clear.on('click', function() {
            var $self = $(this);
            if (!window.confirm(t('confirm_clear_lock', 'Clear the fetch lock? Only do this if a previous run is stuck.'))) {
                return;
            }
            setBusy($self, true);
            request('wpnc_clear_fetch_lock')
                .done(function(data) {
                    setStatus((data && data.message) || t('lock_cleared', 'Lock cleared.'), 'ok');
                })
                .fail(function(error) {
                    setStatus(error.message, 'error');
                })
                .always(function() {
                    setBusy($self, false);
                });
        });

        $btn.on('click', function() {
            setBusy($btn, true);
            $status.empty();
            $progress.show();
            setProgress(0, t('loading', 'Loading...'));

            request('wpnc_get_sources_list')
                .done(function(data) {
                    runSources(data.sources || []);
                })
                .fail(function(error) {
                    setStatus(error.message, error.code === 'wpnc_locked' ? 'warn' : 'error');
                    stop();
                });
        });

        function runSources(sources) {
            var total = sources.length;
            var done = 0;
            var accumulated = {
                sources_total: total,
                sources_ok: 0,
                fetched: 0,
                queued: 0,
                published: 0,
                skipped: 0,
                errors: 0,
                messages: []
            };

            if (!total) {
                setStatus(t('no_sources', 'No RSS sources configured.'), 'warn');
                stop();
                return;
            }

            function next() {
                if (done >= total) {
                    finalize(accumulated);
                    return;
                }

                var src = sources[done];
                setProgress(
                    Math.round((done / total) * 100),
                    t('fetching_source', 'Fetching source') + ' ' + (done + 1) + ' ' +
                        t('of', 'of') + ' ' + total + (src.key ? ' — ' + src.key : '')
                );

                request('wpnc_fetch_one_source', { source_index: src.index })
                    .done(function(data) {
                        accumulated.fetched += (data.fetched || 0);
                        accumulated.queued += (data.queued || 0);
                        accumulated.published += (data.published || 0);
                        accumulated.skipped += (data.skipped || 0);
                        accumulated.errors += (data.errors || 0);
                        if (!data.errors) {
                            accumulated.sources_ok++;
                        }
                        if (data.messages && data.messages.length) {
                            accumulated.messages = accumulated.messages.concat(data.messages);
                        }
                    })
                    .fail(function(error) {
                        accumulated.errors++;
                        accumulated.messages.push(
                            (src.key || src.url || ('#' + src.index)) + ' — ' + error.message
                        );
                    })
                    .always(function() {
                        done++;
                        next();
                    });
            }

            next();
        }

        function finalize(data) {
            setProgress(100, t('fetch_done', 'Fetch completed.'));

            // Always runs, so the server-side lock is released even when
            // every source failed.
            request('wpnc_fetch_finalize', { summary: JSON.stringify(data) })
                .fail(function(error) {
                    data.messages.push(error.message);
                })
                .always(function() {
                    window.setTimeout(function() {
                        stop();
                        showSummary(data);
                        loadStats();
                        loadLogs();
                        onDone(data);
                    }, 400);
                });
        }

        function showSummary(data) {
            var parts = [
                t('fetched', 'Fetched') + ': ' + data.fetched,
                t('queued_lc', 'queued') + ': ' + data.queued,
                t('published_lc', 'published') + ': ' + data.published,
                t('skipped_lc', 'skipped') + ': ' + data.skipped,
                t('errors_lc', 'errors') + ': ' + data.errors
            ];

            setStatus(t('fetch_done', 'Fetch completed.') + ' — ' + parts.join(', '),
                data.errors > 0 ? 'warn' : 'ok');

            if (data.messages && data.messages.length) {
                var $list = $('<ul>').addClass('wpnc-fetch-messages');
                data.messages.forEach(function(m) {
                    $('<li>').attr('dir', 'auto').text(m).appendTo($list);
                });
                $status.append($list);
            }
        }
    }

    /* ==========================================================
       Utilities and boot
       ========================================================== */

    /* ==========================================================
       Dashboard
       ========================================================== */

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function svg(tag, attrs) {
        var node = document.createElementNS(SVG_NS, tag);
        for (var k in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, k)) {
                node.setAttribute(k, attrs[k]);
            }
        }
        return node;
    }

    function renderDashboardSkeleton($app) {
        var $wrap = $('<div>').addClass('wpnc-skeleton').appendTo($app.empty());
        for (var i = 0; i < 4; i++) {
            $('<div>').addClass('wpnc-skeleton-block').appendTo($wrap);
        }
        $('<div>').addClass('wpnc-skeleton-block is-wide').appendTo($wrap);
    }

    function loadDashboard() {
        var $app = $('#wpnc-dash-app');
        if (!$app.length) {
            return;
        }

        renderDashboardSkeleton($app);

        request('wpnc_get_dashboard')
            .done(function(data) {
                renderDashboard($app, data);
            })
            .fail(function(error) {
                renderError($app, error, loadDashboard);
            });
    }

    function renderDashboard($app, data) {
        $app.empty();

        var totals = data.totals || {};
        $('#wpnc-dash-subtitle').text(
            t('dash_next_run', 'Next fetch') + ': ' + (data.next_run || '-') +
            (data.last_run && data.last_run.at
                ? '  ·  ' + t('dash_last_run', 'Last run') + ': ' + data.last_run.at
                : '')
        );

        renderCards($app, totals, data.health || {});

        if (!totals.total) {
            renderEmpty(
                $('<div>').appendTo($app),
                t('dash_empty', 'Nothing has been collected yet.'),
                t('empty_pending_hint', 'Add RSS sources under Settings, then run Fetch Now.')
            );
            return;
        }

        renderActivity($app, data.activity || []);
        renderOutcome($app, totals);
        renderSources($app, data.sources || []);
    }

    function card($row, opts) {
        var $c = $('<div>').addClass('wpnc-card-stat ' + (opts.tone || '')).appendTo($row);
        $('<span>').addClass('wpnc-card-stat-label').text(opts.label).appendTo($c);
        $('<strong>').addClass('wpnc-card-stat-value').text(opts.value).appendTo($c);
        if (opts.note) {
            $('<span>').addClass('wpnc-card-stat-note').text(opts.note).appendTo($c);
        }
        return $c;
    }

    function renderCards($app, totals, health) {
        var $row = $('<div>').addClass('wpnc-card-stats').appendTo($app);

        card($row, {
            label: t('pending_opt', 'Pending'),
            value: totals.pending || 0,
            tone: 'is-warning',
            note: t('dash_awaiting', 'awaiting your review')
        });
        card($row, {
            label: t('approved_opt', 'Approved'),
            value: totals.approved || 0,
            tone: 'is-success',
            note: t('dash_published_note', 'published to the site')
        });
        card($row, {
            label: t('error_opt', 'Error'),
            value: totals.errors || 0,
            tone: (totals.errors ? 'is-error' : ''),
            note: t('dash_errors_note', 'failed to publish')
        });

        var sourceNote = [];
        if (health.failing) { sourceNote.push(health.failing + ' ' + t('dash_failing', 'failing')); }
        if (health.paused) { sourceNote.push(health.paused + ' ' + t('dash_paused', 'paused')); }
        if (health.unsafe) { sourceNote.push(health.unsafe + ' ' + t('dash_unsafe', 'unsafe')); }

        card($row, {
            label: t('dash_sources', 'Sources'),
            value: (health.ok || 0) + ' / ' + (health.total || 0),
            tone: (health.failing || health.unsafe) ? 'is-warning' : '',
            note: sourceNote.length ? sourceNote.join(' · ') : t('dash_all_healthy', 'all healthy')
        });
    }

    /* Stacked columns: one per day, approved + rejected + errors + still
       pending, so the shape shows both volume and what happened to it. */
    function renderActivity($app, series) {
        var $panel = $('<div>').addClass('wpnc-panel').appendTo($app);
        $('<h3>').text(t('dash_activity', 'Last 14 days')).appendTo($panel);

        var busiest = 0;
        series.forEach(function(d) { busiest = Math.max(busiest, d.total); });

        // A floor keeps a single busy day from becoming one hairline bar in a
        // mostly empty box, and rounding up gives the axis a sane top.
        var peak = Math.max(busiest, 4);
        peak = Math.ceil(peak / 4) * 4;

        if (!busiest) {
            $('<p>').addClass('wpnc-state-hint').text(
                t('dash_no_activity', 'No items were collected in this period.')
            ).appendTo($panel);
            return;
        }

        var rtl = $('.wpnc-wrap').hasClass('wpnc-rtl');
        var W = 720, H = 170, padB = 24, padT = 10;
        var slot = W / series.length;
        var barW = Math.max(6, Math.min(34, slot * 0.62));

        var chart = svg('svg', {
            viewBox: '0 0 ' + W + ' ' + H,
            // Not 'none': that stretches the axis text along with the bars.
            preserveAspectRatio: 'xMidYMid meet',
            role: 'img',
            'aria-label': t('dash_activity', 'Last 14 days'),
            class: 'wpnc-chart'
        });

        // Gridlines give the columns a scale to be read against.
        [0, 0.5, 1].forEach(function(f) {
            var y = padT + (H - padT - padB) * (1 - f);
            chart.appendChild(svg('line', {
                x1: 0, x2: W, y1: y, y2: y, class: 'wpnc-chart-grid'
            }));
            // SVG coordinates do not follow the CSS writing direction, so
            // the value axis has to be placed explicitly.
            chart.appendChild(svg('text', {
                x: rtl ? W - 2 : 2,
                y: y - 4,
                'text-anchor': rtl ? 'end' : 'start',
                class: 'wpnc-chart-axis'
            })).textContent = String(Math.round(peak * f));
        });

        var plot = H - padT - padB;

        series.forEach(function(d, i) {
            var x = i * slot + (slot - barW) / 2;
            var y = H - padB;

            // Order matters: the settled outcomes sit at the bottom so the
            // still-pending part is what sticks up.
            var parts = [
                { n: d.approved, cls: 'is-approved' },
                { n: d.rejected, cls: 'is-rejected' },
                { n: d.errors, cls: 'is-error' },
                { n: Math.max(0, d.total - d.approved - d.rejected - d.errors), cls: 'is-pending' }
            ];

            var topDrawn = false;
            parts.forEach(function(part) {
                if (!part.n) { return; }
                var h = (part.n / peak) * plot;
                y -= h;

                var attrs = {
                    x: x, y: y, width: barW, height: h,
                    class: 'wpnc-bar ' + part.cls
                };

                // Only the topmost visible segment gets rounded corners, so a
                // stack still reads as one column.
                if (!topDrawn && h > 3) {
                    attrs.rx = Math.min(3, barW / 2);
                    topDrawn = true;
                }

                chart.appendChild(svg('rect', attrs));
            });

            var title = svg('title');
            title.textContent = d.label + ' — ' + d.total;
            chart.appendChild(svg('rect', {
                x: i * slot, y: padT, width: slot, height: plot,
                class: 'wpnc-bar-hit'
            })).appendChild(title);

            if (series.length <= 16 || i % 2 === 0) {
                var label = svg('text', {
                    x: i * slot + slot / 2, y: H - 8,
                    'text-anchor': 'middle', class: 'wpnc-chart-label'
                });
                label.textContent = d.label;
                chart.appendChild(label);
            }
        });

        $panel[0].appendChild(chart);

        var $legend = $('<div>').addClass('wpnc-legend').appendTo($panel);
        [
            ['is-approved', t('approved_opt', 'Approved')],
            ['is-pending', t('pending_opt', 'Pending')],
            ['is-rejected', t('rejected_opt', 'Rejected')],
            ['is-error', t('error_opt', 'Error')]
        ].forEach(function(pair) {
            $('<span>').addClass('wpnc-legend-item')
                .append($('<i>').addClass('wpnc-swatch ' + pair[0]))
                .append(document.createTextNode(' ' + pair[1]))
                .appendTo($legend);
        });
    }

    /* Proportion of everything collected that actually reached the site.
       A ring answers "what share" at a glance in a way four numbers cannot. */
    function renderOutcome($app, totals) {
        var settled = (totals.approved || 0) + (totals.rejected || 0) + (totals.errors || 0);
        if (!settled) {
            return;
        }

        var $panel = $('<div>').addClass('wpnc-panel').appendTo($app);
        $('<h3>').text(t('dash_outcome', 'What happens to what you collect')).appendTo($panel);

        var $wrap = $('<div>').addClass('wpnc-ring-wrap').appendTo($panel);

        var total = totals.total || 1;
        var pct = Math.round(((totals.approved || 0) / total) * 100);
        var R = 54;
        var C = 2 * Math.PI * R;

        var ring = svg('svg', {
            viewBox: '0 0 132 132',
            class: 'wpnc-ring',
            role: 'img',
            'aria-label': pct + '%'
        });
        ring.appendChild(svg('circle', { cx: 66, cy: 66, r: R, class: 'wpnc-ring-track' }));
        ring.appendChild(svg('circle', {
            cx: 66, cy: 66, r: R,
            class: 'wpnc-ring-value is-approved',
            'stroke-dasharray': C,
            'stroke-dashoffset': C * (1 - pct / 100)
        }));

        var label = svg('text', { x: 66, y: 68, class: 'wpnc-ring-label' });
        label.textContent = pct + '%';
        ring.appendChild(label);

        var sub = svg('text', { x: 66, y: 84, class: 'wpnc-ring-sub' });
        sub.textContent = t('approved_opt', 'Approved');
        ring.appendChild(sub);

        $wrap[0].appendChild(ring);

        var $legend = $('<div>').addClass('wpnc-ring-legend').appendTo($wrap);
        [
            [t('approved_opt', 'Approved'), totals.approved || 0],
            [t('pending_opt', 'Pending'), totals.pending || 0],
            [t('rejected_opt', 'Rejected'), totals.rejected || 0],
            [t('error_opt', 'Error'), totals.errors || 0]
        ].forEach(function(pair) {
            $('<div>')
                .append($('<span>').text(pair[0]))
                .append($('<b>').text(pair[1]))
                .appendTo($legend);
        });
    }

    function renderSources($app, sources) {
        var $panel = $('<div>').addClass('wpnc-panel').appendTo($app);
        $('<h3>').text(t('dash_by_source', 'By source')).appendTo($panel);

        if (!sources.length) {
            $('<p>').addClass('wpnc-state-hint').text(
                t('dash_no_sources_yet', 'No source has produced an item yet.')
            ).appendTo($panel);
            return;
        }

        var peak = 0;
        sources.forEach(function(s) { peak = Math.max(peak, s.total); });

        var $list = $('<div>').addClass('wpnc-bars').appendTo($panel);
        sources.forEach(function(src) {
            var $row = $('<div>').addClass('wpnc-bars-row').appendTo($list);
            $('<span>').addClass('wpnc-bars-name').attr('dir', 'auto').text(src.name).appendTo($row);

            var $track = $('<span>').addClass('wpnc-bars-track').appendTo($row);
            var pct = peak ? (src.total / peak) * 100 : 0;
            var approvedPct = src.total ? (src.approved / src.total) * 100 : 0;

            $('<span>').addClass('wpnc-bars-fill').css('width', pct + '%')
                .append($('<span>').addClass('wpnc-bars-fill-approved').css('width', approvedPct + '%'))
                .appendTo($track);

            $('<span>').addClass('wpnc-bars-value')
                .attr({ dir: 'ltr', title: t('dash_approved_of_total', 'approved of total') })
                .text(src.approved + ' / ' + src.total)
                .appendTo($row);
        });
    }

    /* ==========================================================
       AI provider settings

       New rows carry a placeholder id; the server swaps it for a stable one
       on save. A row left blank keeps whatever key is already stored under
       its id, which is what lets the field show a mask instead of a secret.
       ========================================================== */

    function bindProviderSettings() {
        var $select = $('#wpnc_ai_provider');
        if (!$select.length) {
            return;
        }

        function showActive() {
            var active = $select.val();
            $('.wpnc-provider').each(function() {
                var $block = $(this);
                $block.prop('hidden', $block.data('provider') !== active);
            });
        }

        $select.on('change', showActive);
        showActive();

        var added = 0;

        $('.wpnc-key-add').on('click', function() {
            var provider = $(this).data('provider');
            var $list = $('.wpnc-keys[data-provider="' + provider + '"]');

            added++;
            var $row = $('<div>').addClass('wpnc-key-row').appendTo($list);

            $('<input>')
                .attr({
                    type: 'password',
                    name: 'wpnc_ai_keys[' + provider + '][new' + added + ']',
                    autocomplete: 'off',
                    dir: 'ltr',
                    placeholder: t('key_placeholder', 'Paste a new key')
                })
                .addClass('regular-text')
                .appendTo($row);

            $('<button>')
                .attr('type', 'button')
                .addClass('button button-small wpnc-key-remove')
                .text(t('remove', 'Remove'))
                .appendTo($row);

            $row.find('input').trigger('focus');
        });

        // A removed row simply is not submitted, and the server treats an
        // absent id as deleted.
        $(document).on('click', '.wpnc-key-remove', function() {
            var $row = $(this).closest('.wpnc-key-row');
            var stored = $row.find('input').attr('placeholder');

            if (stored && !window.confirm(t('confirm_remove_key', 'Remove this key?'))) {
                return;
            }

            $row.remove();
        });
    }

    /* ==========================================================
       Source health actions
       ========================================================== */

    function bindSourceHealth() {
        var $table = $('.wpnc-health-table');
        if (!$table.length) {
            return;
        }

        function cell($button) {
            return $button.closest('.wpnc-health-actions');
        }

        function report($cell, message, type) {
            $cell.find('.wpnc-health-result')
                .removeClass('wpnc-health-ok wpnc-health-bad')
                .addClass(type === 'error' ? 'wpnc-health-bad' : 'wpnc-health-ok')
                .text(message);
        }

        $table.on('click', '.wpnc-test-source', function() {
            var $button = $(this);
            var $cell = cell($button);

            setBusy($button, true);
            request('wpnc_test_source', { source_index: $cell.data('index') })
                .done(function(data) {
                    var text = data.message;
                    if (data.titles && data.titles.length) {
                        text += ' — ' + data.titles[0];
                    }
                    report($cell, text, 'ok');
                })
                .fail(function(error) {
                    report($cell, error.message, 'error');
                })
                .always(function() {
                    setBusy($button, false);
                });
        });

        $table.on('click', '.wpnc-toggle-source', function() {
            var $button = $(this);
            var $cell = cell($button);

            setBusy($button, true);
            request('wpnc_toggle_source', { source_index: $cell.data('index') })
                .done(function(data) {
                    report($cell, data.message, 'ok');
                    $button.text(data.enabled ? t('pause_source', 'Pause') : t('resume_source', 'Resume'));
                    $cell.attr('data-enabled', data.enabled ? '1' : '0');
                })
                .fail(function(error) {
                    report($cell, error.message, 'error');
                })
                .always(function() {
                    setBusy($button, false);
                });
        });

        $table.on('click', '.wpnc-reset-health', function() {
            var $button = $(this);
            var $cell = cell($button);

            setBusy($button, true);
            request('wpnc_reset_source_health', { source_id: $cell.data('source-id') })
                .done(function(data) {
                    report($cell, data.message, 'ok');
                    $button.remove();
                })
                .fail(function(error) {
                    report($cell, error.message, 'error');
                })
                .always(function() {
                    setBusy($button, false);
                });
        });
    }

    function debounce(fn, wait) {
        var timeout;
        return function() {
            var context = this;
            var args = arguments;
            window.clearTimeout(timeout);
            timeout = window.setTimeout(function() {
                fn.apply(context, args);
            }, wait);
        };
    }

    $(document).on('keydown', function(event) {
        if (!$('#wpnc-edit-modal').is(':visible')) {
            return;
        }

        if (event.key === 'Escape') {
            closeModal();
            return;
        }

        // The modal has no form, so Enter does nothing on its own.
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            $('#wpnc-save-edit').trigger('click');
        }
    });

    // Leaving the page mid-edit deserves the browser's own warning.
    $(window).on('beforeunload', function() {
        if ($('#wpnc-edit-modal').is(':visible') && editorDirty()) {
            return t('confirm_discard', 'Discard the changes you made to this item?');
        }
    });

    $('#wpnc-log-level').on('change', loadLogs);

    loadQueue();
    loadStats();
    loadLogs();
    loadDashboard();
    bindProviderSettings();
    bindFetchTool();
    bindFetchTool({
        button: '#wpnc-dash-fetch',
        clear: '#wpnc-dash-clear-lock',
        status: '#wpnc-dash-status',
        progress: '#wpnc-dash-progress',
        onDone: loadDashboard
    });
    bindSourceHealth();
});
