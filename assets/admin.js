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

        // Sources and Fetch Now live on screens a moderator is not shown.
        if (status === 'pending' && canAdmin()) {
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

        $('<button>').attr('type', 'button')
            .addClass('button-link wpnc-shortcuts-hint')
            .text(t('shortcuts_hint', 'Keyboard shortcuts (?)'))
            .on('click', function() {
                toggleShortcutHelp();
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
            renderBulkDestination($bulk);
            $('<button>').attr('type', 'button').addClass('button').attr('id', 'wpnc-bulk-reject').text(t('reject_selected', 'Reject Selected')).appendTo($bulk);
            if (canAdmin()) {
                $('<button>').attr('type', 'button').addClass('button button-link-delete').attr('id', 'wpnc-bulk-delete').text(t('delete_selected', 'Delete Selected')).appendTo($bulk);
            }
        } else {
            var $terminalBulk = $('<div>').addClass('wpnc-bulk-actions').appendTo($app);
            $('<label>')
                .append($('<input>').attr({ type: 'checkbox', id: 'wpnc-select-all' }))
                .append(document.createTextNode(' ' + t('select_all', 'Select All')))
                .appendTo($terminalBulk);
            if (canAdmin()) {
                $('<button>').attr('type', 'button').addClass('button button-link-delete').attr('id', 'wpnc-bulk-delete').text(t('delete_selected', 'Delete Selected')).appendTo($terminalBulk);
            }
        }

        if (!items.length) {
            renderEmptyQueue($app, emptyStateFor(queueState.status));
            renderPagination($app, data);
            bindQueueEvents();
            syncExportLink();
            return;
        }

        var $grid = $('<div>').addClass('wpnc-grid').appendTo($app);

        // One story from several sources is shown once, newest first, with
        // the other copies folded under it. Grouped only within this page: a
        // story split across pages still shows on each, which is no worse
        // than it was.
        var groups = {};
        var order = [];

        items.forEach(function(item) {
            var key = String(item.group_key || item.id);
            if (!groups[key]) {
                groups[key] = [];
                order.push(key);
            }
            groups[key].push(item);
        });

        order.forEach(function(key) {
            var members = groups[key];
            var lead = members[0];

            renderCard($grid, lead, terminal);

            members.slice(1).forEach(function(member) {
                renderCard($grid, member, terminal);
                $('#wpnc-item-' + member.id).addClass('wpnc-card-member').attr('data-group', key).hide();
            });

            if (members.length > 1 && !terminal) {
                renderGroupNote($('#wpnc-item-' + lead.id), key, members.slice(1));
            }
        });

        renderEditModal($app);
        renderPagination($app, data);
        bindQueueEvents();
        syncExportLink();
    }

    /**
     * "The same story from N other sources", on the card that stands for it.
     *
     * Rejecting the others is one click because that is what a moderator does
     * with them nearly every time - and the reason this exists: judging the
     * same event five times over was the work being wasted.
     */
    function renderGroupNote($card, key, others) {
        var sources = [];

        others.forEach(function(item) {
            if (item.source_name && sources.indexOf(item.source_name) === -1) {
                sources.push(item.source_name);
            }
        });

        var $note = $('<div>').addClass('wpnc-group-note').attr('dir', 'auto');

        $('<span>').addClass('wpnc-group-label')
            .text(t('group_also', 'Same story from') + ' ' + others.length + ' ' + t('group_more', 'more') +
                (sources.length ? ': ' + sources.join(t('list_sep', ', ')) : ''))
            .appendTo($note);

        var $toggle = $('<button>').attr({ type: 'button', 'aria-expanded': 'false' })
            .addClass('button-link wpnc-group-toggle')
            .text(t('group_show', 'Show them'))
            .appendTo($note);

        $toggle.on('click', function() {
            var $members = $('.wpnc-card-member[data-group="' + key + '"]');
            var opening = $toggle.attr('aria-expanded') !== 'true';

            $members.toggle(opening);
            $toggle.attr('aria-expanded', opening ? 'true' : 'false')
                .text(opening ? t('group_hide', 'Hide them') : t('group_show', 'Show them'));
        });

        $('<button>').attr('type', 'button')
            .addClass('button button-small wpnc-group-reject')
            .text(t('group_reject', 'Reject the others'))
            .on('click', function() {
                var $button = $(this);

                if (!window.confirm(t('group_confirm', 'Reject the other copies of this story? The one shown stays in the queue.'))) {
                    return;
                }

                setBusy($button, true);
                request('wpnc_bulk_reject', { ids: others.map(function(item) { return item.id; }) })
                    .done(function(data) {
                        flash((data && data.message) || t('done', 'Done.'), 'ok');
                        loadQueue();
                    })
                    .fail(function(error) {
                        flash(error.message, 'error');
                        setBusy($button, false);
                    });
            })
            .appendTo($note);

        $note.insertBefore($card.find('.wpnc-actions'));
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

        var scheduled = item.publish_options && item.publish_options.publish_at_display;
        if (scheduled) {
            $('<p>').addClass('wpnc-scheduled').attr('dir', 'auto')
                .text(t('scheduled_for', 'Scheduled for') + ' ' + scheduled)
                .appendTo($content);
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

            $('<button>').attr('type', 'button').addClass('button wpnc-history-open')
                .data('id', item.id).text(t('history', 'History')).appendTo($actions);

            if (canAdmin()) {
                $('<button>').attr('type', 'button').addClass('button button-link-delete wpnc-delete')
                    .data('id', item.id).text(t('delete', 'Delete')).appendTo($actions);
            }
            return;
        }

        renderSendButtons($actions, item.id);
        $('<button>').attr('type', 'button').addClass('button wpnc-edit').data('item', item).text(t('edit', 'Edit')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-reject').data('id', item.id).text(t('reject', 'Reject')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-history-open').data('id', item.id).text(t('history', 'History')).appendTo($actions);

        // Permanent deletion is left to administrators; rejecting is the
        // moderator's way of saying no.
        if (canAdmin()) {
            $('<button>').attr('type', 'button').addClass('button button-link-delete wpnc-delete').data('id', item.id).text(t('delete', 'Delete')).appendTo($actions);
        }
    }

    /**
     * Destination picker for the bulk bar.
     *
     * Only drawn when there is a choice to make; with the site alone, Approve
     * Selected already says everything.
     */
    function renderBulkDestination($bulk) {
        var channels = readyChannels();

        if (channels.length < 2) {
            return;
        }

        var $wrap = $('<span>').addClass('wpnc-bulk-destination').appendTo($bulk);
        $('<label>').attr('for', 'wpnc-bulk-channels').text(t('destination', 'Destination')).appendTo($wrap);

        var $select = $('<select>').attr('id', 'wpnc-bulk-channels').appendTo($wrap);

        $.each(channels, function(index, channel) {
            $('<option>').attr('value', channel.slug).text(channel.label).appendTo($select);
        });

        $('<option>').attr('value', 'all').text(t('send_all', 'All')).appendTo($select);

        $select.on('change', function() {
            $('#wpnc-bulk-approve').data('channels', $(this).val());
        }).trigger('change');
    }

    /* Whether this user administers the site. A moderator is not shown the
       controls the server would refuse them. */
    function canAdmin() {
        return !!wpnc_ajax.can_admin;
    }

    /**
     * What happened to an item, and who did it.
     */
    function showHistory(id) {
        var $dialog = $('#wpnc-history');

        if (!$dialog.length) {
            $dialog = $('<div>')
                .attr({ id: 'wpnc-history', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'wpnc-history-title' })
                .addClass('wpnc-history')
                .hide();

            var $box = $('<div>').addClass('wpnc-history-box').appendTo($dialog);

            $('<div>').addClass('wpnc-history-head')
                .append($('<h3>').attr('id', 'wpnc-history-title').text(t('history_title', 'History')))
                .append($('<button>').attr({ type: 'button', 'aria-label': t('dismiss', 'Dismiss') })
                    .addClass('wpnc-history-close').text('×'))
                .appendTo($box);

            $('<ol>').addClass('wpnc-history-list').appendTo($box);

            $dialog.on('click', function(event) {
                if (event.target === this || $(event.target).is('.wpnc-history-close')) {
                    $dialog.hide();
                }
            });

            var $host = $('.wpnc-wrap').first();
            $dialog.appendTo($host.length ? $host : 'body');
        }

        var $list = $dialog.find('.wpnc-history-list').empty();
        $('<li>').addClass('wpnc-history-empty').text(t('loading', 'Loading...')).appendTo($list);
        $dialog.show();
        $dialog.find('.wpnc-history-close').trigger('focus');

        request('wpnc_item_history', { id: id })
            .done(function(data) {
                var entries = (data && data.entries) || [];
                $list.empty();

                if (!entries.length) {
                    $('<li>').addClass('wpnc-history-empty')
                        .text(t('history_empty', 'Nothing has been recorded for this item yet.'))
                        .appendTo($list);
                    return;
                }

                entries.forEach(function(entry) {
                    $('<li>').attr('dir', 'auto')
                        .append($('<span>').addClass('wpnc-history-text').text(entry.text || ''))
                        .append($('<span>').addClass('wpnc-history-meta')
                            .text((entry.when || '') + (entry.user ? '  ·  ' + entry.user : '')))
                        .appendTo($list);
                });
            })
            .fail(function(error) {
                $list.empty().append($('<li>').addClass('wpnc-history-empty').attr('dir', 'auto').text(error.message));
            });
    }

    /**
     * Channels an editor may send to right now.
     *
     * A destination is offered only when it has credentials AND a passing
     * test. Offering an untested one would put a button on screen whose
     * failure the editor only discovers after approving the item, by which
     * point the queue row is gone.
     */
    function readyChannels() {
        var status = wpnc_ajax.channels || {};
        var out = [];

        $.each(status, function(slug, info) {
            if (info && info.ready) {
                out.push({ slug: slug, label: info.label });
            }
        });

        return out;
    }

    /**
     * "Send to" controls for one queue row.
     *
     * With only the site available this is a single Approve button, exactly
     * as before - no reason to make a one-item menu out of it.
     */
    function renderSendButtons($actions, id) {
        var channels = readyChannels();

        if (channels.length < 2) {
            $('<button>').attr('type', 'button')
                .addClass('button button-primary wpnc-approve')
                .data({ id: id, channels: 'site' })
                .text(t('approve', 'Approve'))
                .appendTo($actions);
            return;
        }

        var $group = $('<span>').addClass('wpnc-send-group').appendTo($actions);
        $('<span>').addClass('wpnc-send-label').text(t('send_to', 'Send to')).appendTo($group);

        $.each(channels, function(index, channel) {
            $('<button>').attr('type', 'button')
                .addClass('button wpnc-approve wpnc-send-one')
                .data({ id: id, channels: channel.slug })
                .text(channel.label)
                .appendTo($group);
        });

        $('<button>').attr('type', 'button')
            .addClass('button button-primary wpnc-approve wpnc-send-all')
            .data({ id: id, channels: 'all' })
            .text(t('send_all', 'All'))
            .appendTo($group);
    }

    /* ==========================================================
       Edit modal
       ========================================================== */

    function publishConfig() {
        return (wpnc_ajax.publish) || { defaults: {}, post_types: {}, statuses: {}, authors: {}, categories: {} };
    }

    /**
     * A select whose blank option means "whatever the settings say".
     *
     * Naming the inherited value in that option matters: without it the field
     * reads as empty rather than as inheriting, and the only way to find out
     * what would actually happen is to go and read the settings.
     */
    function overrideSelect(id, choices, current, inherited) {
        var $select = $('<select>').attr('id', id).addClass('wpnc-override');
        var label = choices[inherited];

        $('<option>').val('').text(
            label ? t('inherit_named', 'Default:') + ' ' + label : t('inherit', 'Use the default')
        ).appendTo($select);

        Object.keys(choices).forEach(function(key) {
            $('<option>').val(key).text(choices[key]).appendTo($select);
        });

        $select.val(current ? String(current) : '');

        return $select;
    }

    /* ==========================================================
       Featured image

       A property of the post rather than a paragraph of the story: it goes
       to set_post_thumbnail() and is kept out of the article text. There
       was no way to see, change or remove it in the editor before, so a
       wrong or missing picture only showed up after publishing.
       ========================================================== */

    var featuredFrame = null;
    var featuredTimer = null;

    function renderFeatured($parent) {
        var $field = $('<div>').addClass('wpnc-field wpnc-featured').appendTo($parent);
        var $head = $('<div>').addClass('wpnc-field-head').appendTo($field);
        $('<label>').attr('for', 'wpnc-edit-image').text(t('featured_image', 'Featured image')).appendTo($head);

        var $tools = $('<span>').addClass('wpnc-field-tools').appendTo($head);
        $('<button>').attr({ type: 'button', id: 'wpnc-image-library' })
            .addClass('button button-small')
            .text(t('image_library', 'Choose from library'))
            .appendTo($tools);
        $('<button>').attr({ type: 'button', id: 'wpnc-image-detect' })
            .addClass('button button-small')
            .text(t('image_detect', 'Find in source'))
            .appendTo($tools);
        $('<button>').attr({ type: 'button', id: 'wpnc-image-remove' })
            .addClass('button button-small')
            .text(t('image_remove', 'Remove'))
            .appendTo($tools);

        var $body = $('<div>').addClass('wpnc-featured-body').appendTo($field);
        $('<div>').attr({ id: 'wpnc-featured-thumb', 'aria-hidden': 'true' }).addClass('wpnc-featured-thumb').appendTo($body);

        var $meta = $('<div>').addClass('wpnc-featured-meta').appendTo($body);
        $('<input>')
            .attr({ type: 'url', id: 'wpnc-edit-image', dir: 'ltr', placeholder: 'https://', autocomplete: 'off', spellcheck: 'false' })
            .addClass('large-text')
            .appendTo($meta);
        $('<p>').attr({ id: 'wpnc-featured-note', dir: 'auto', 'aria-live': 'polite' }).addClass('wpnc-featured-note').appendTo($meta);
    }

    function featuredUrl() {
        return $.trim($('#wpnc-edit-image').val() || '');
    }

    function featuredNote(message, type) {
        $('#wpnc-featured-note')
            .attr('class', 'wpnc-featured-note' + (type ? ' is-' + type : ''))
            .text(message || '');
    }

    /* Draws the thumbnail from the field as it stands. The browser loading
       the picture is only a hint - the server downloads it with a request
       of its own - so a failure here warns rather than blocks. */
    function showFeatured() {
        var url = featuredUrl();
        var $thumb = $('#wpnc-featured-thumb').empty().removeClass('is-empty is-broken');

        $('#wpnc-image-remove').prop('disabled', !url);

        if (!url) {
            $thumb.addClass('is-empty').text(t('no_image', 'No Image'));
            featuredNote(publishConfig().default_image
                ? t('featured_default', 'None set for this item, so the default image from Settings will be used.')
                : t('featured_none', 'No featured image. Paste an address, choose one from the library, or find one in the source.'));
            return;
        }

        featuredNote(t('featured_hint', 'Kept apart from the article. It becomes the featured image of the post and is not repeated inside the text.'));

        $('<img>')
            .attr({ src: url, alt: '' })
            .on('error', function() {
                // A slow earlier address must not overwrite a newer one.
                if (featuredUrl() !== url) {
                    return;
                }
                $thumb.addClass('is-broken').empty().text(t('featured_broken_short', 'Preview unavailable'));
                featuredNote(t('featured_broken', 'Your browser could not load this address as an image. The server may still manage it, but check the address.'), 'error');
            })
            .appendTo($thumb);
    }

    function setFeatured(url) {
        $('#wpnc-edit-image').val(url || '');
        showFeatured();
        schedulePreview();
    }

    /* Shown above the title, apart from the body, the way a theme shows a
       featured image - so the preview cannot suggest it is part of the text. */
    function renderPreviewFeatured(url) {
        var $paper = $('#wpnc-preview-body').closest('.wpnc-preview-paper');
        $paper.children('.wpnc-preview-featured').remove();

        if (!url) {
            return;
        }

        $('<figure>').addClass('wpnc-preview-featured')
            .append($('<img>').attr({ src: url, alt: '' }))
            .append($('<figcaption>').text(t('featured_image', 'Featured image')))
            .prependTo($paper);
    }

    function chooseFeatured() {
        if (!(window.wp && wp.media)) {
            featuredNote(t('media_unavailable', 'The media library is not available on this page.'), 'error');
            return;
        }

        if (!featuredFrame) {
            featuredFrame = wp.media({
                title: t('image_library_title', 'Choose the featured image'),
                button: { text: t('image_library_button', 'Use this image') },
                library: { type: 'image' },
                multiple: false
            });

            featuredFrame.on('select', function() {
                var picked = featuredFrame.state().get('selection').first();
                if (picked && picked.get('url')) {
                    setFeatured(picked.get('url'));
                }
            });
        }

        featuredFrame.open();
    }

    function detectFeatured() {
        var $button = $('#wpnc-image-detect');

        setBusy($button, true);
        featuredNote(t('image_detecting', 'Looking for an image in the source...'), 'busy');

        request('wpnc_detect_image', { id: $('#wpnc-edit-id').val() })
            .done(function(data) {
                setFeatured(data.image_url);
                featuredNote(data.message, 'ok');
            })
            .fail(function(error) {
                featuredNote(error.message, 'error');
            })
            .always(function() {
                setBusy($button, false);
            });
    }

    function renderAdvanced($parent) {
        var config = publishConfig();

        var $box = $('<details>').addClass('wpnc-advanced').appendTo($parent);
        $('<summary>').text(t('advanced', 'Advanced')).appendTo($box);

        $('<p>').addClass('description').attr('dir', 'auto')
            .text(t('advanced_hint', 'These start from Settings. Change one here and it applies to this item only.'))
            .appendTo($box);

        var $grid = $('<div>').addClass('wpnc-advanced-grid').appendTo($box);

        labelledField($grid, 'wpnc-edit-post-type', t('field_post_type', 'Post type'),
            overrideSelect('wpnc-edit-post-type', config.post_types, '', config.defaults.post_type));
        labelledField($grid, 'wpnc-edit-post-status', t('field_post_status', 'Status'),
            overrideSelect('wpnc-edit-post-status', config.statuses, '', config.defaults.post_status));
        labelledField($grid, 'wpnc-edit-post-author', t('field_post_author', 'Author'),
            overrideSelect('wpnc-edit-post-author', config.authors, '', config.defaults.post_author));
        labelledField($grid, 'wpnc-edit-category', t('field_category', 'Category'),
            overrideSelect('wpnc-edit-category', config.categories, '', config.defaults.category_id));

        // Typed in the site's own time; the server converts it to UTC.
        var $when = labelledField($grid, 'wpnc-edit-publish-at', t('field_publish_at', 'Publish at'),
            $('<input>').attr({ type: 'datetime-local' }).addClass('wpnc-override'));
        $('<span>').addClass('wpnc-field-hint').attr('dir', 'auto')
            .text(t('publish_at_hint', 'Leave empty to publish on approval. Telegram and Bale wait until the post is live.'))
            .insertAfter($when);
    }

    /* The same limit the server holds the description to. */
    var SEO_DESCRIPTION_LIMIT = 155;

    function updateSeoCount() {
        var length = ($('#wpnc-edit-seo-description').val() || '').length;

        $('#wpnc-seo-count')
            .text(length + ' / ' + SEO_DESCRIPTION_LIMIT)
            .toggleClass('is-over', length > SEO_DESCRIPTION_LIMIT);
    }

    function publishOptions() {
        return {
            post_type: $('#wpnc-edit-post-type').val() || '',
            post_status: $('#wpnc-edit-post-status').val() || '',
            post_author: $('#wpnc-edit-post-author').val() || '',
            category_id: $('#wpnc-edit-category').val() || '',
            // Always sent, even empty: an emptied field is how an editor takes
            // a scheduled item back to "publish on approval".
            publish_at_local: $('#wpnc-edit-publish-at').val() || '',
            caption: $('#wpnc-edit-caption').val() || '',
            seo_description: $('#wpnc-edit-seo-description').val() || '',
            seo_keyword: $('#wpnc-edit-seo-keyword').val() || ''
        };
    }

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
            $('#wpnc-edit-tags').val() || '',
            publishOptions(),
            featuredUrl()
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

    /* ==========================================================
       Preview

       Drawn in the browser on the next frame, then confirmed by the server.
       It used to wait for a round trip through admin-ajax - which boots all
       of WordPress - behind a 700ms debounce, and typing in the article
       itself never asked for one at all, so the pane kept showing the text
       as it was when the editor opened. The server's copy still arrives and
       replaces the local one: that is the publisher's exact output, and the
       local one is a close copy of it built from the same template and the
       same allowlist.
       ========================================================== */

    var previewSeq = 0;
    var previewFrame = null;
    var previewContext = {};
    var lastServerSnapshot = '';
    var lastLocalHtml = '';
    var shownFeatured = null;

    // Kept out of the article entirely: none of it is text anyone wrote.
    var PREVIEW_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea'];

    // Allowed on top of the article's own list, because the template in
    // Settings is written by an administrator and may use them.
    var PREVIEW_EXTRA = ['div', 'span', 'section', 'small'];

    function previewConfig() {
        return wpnc_ajax.preview || { template: '{content}', source_label: '', allowed: {} };
    }

    function previewEscape(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function previewSafeUrl(url) {
        url = $.trim(String(url || ''));
        return (/^(https?:)?\/\//i.test(url) || /^[\/#?]/.test(url) || /^mailto:/i.test(url)) ? url : '';
    }

    /* A document that is never attached to the page: nothing parsed into it
       runs, and no image in it is fetched. */
    function inertRoot(html) {
        var doc = document.implementation.createHTMLDocument('');
        var root = doc.createElement('div');
        root.innerHTML = String(html || '');
        return root;
    }

    function previewClean(node, allowed) {
        Array.prototype.slice.call(node.childNodes).forEach(function(child) {
            if (child.nodeType === 8) {
                node.removeChild(child);
                return;
            }

            if (child.nodeType !== 1) {
                return;
            }

            var tag = child.nodeName.toLowerCase();

            if (PREVIEW_DROP.indexOf(tag) !== -1) {
                node.removeChild(child);
                return;
            }

            previewClean(child, allowed);

            var permitted = allowed[tag] || (PREVIEW_EXTRA.indexOf(tag) !== -1 ? [] : null);

            if (!permitted) {
                // Unwrapped rather than deleted, the way wp_kses keeps the text
                // of a tag it strips.
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                node.removeChild(child);
                return;
            }

            Array.prototype.slice.call(child.attributes).forEach(function(attribute) {
                var name = attribute.name.toLowerCase();
                var keep = permitted.indexOf(name) !== -1 || name === 'class' || name === 'dir';

                if (keep && (name === 'href' || name === 'src' || name === 'cite')) {
                    keep = previewSafeUrl(attribute.value) !== '';
                }

                if (!keep) {
                    child.removeAttribute(attribute.name);
                }
            });
        });
    }

    function previewSanitize(html) {
        var root = inertRoot(html);
        previewClean(root, previewConfig().allowed || {});
        return root.innerHTML;
    }

    function previewDecode(value) {
        try {
            return decodeURIComponent(value);
        } catch (e) {
            return value;
        }
    }

    /* Mirrors WPNC_Image_Picker::identity(): one picture served at several
       sizes, or under www and without it, is still one picture. */
    function imageIdentity(url) {
        var parsed;

        try {
            parsed = new URL(String(url || ''), previewContext.main_link || window.location.href);
        } catch (e) {
            return '';
        }

        if (!/^https?:$/.test(parsed.protocol) || !parsed.hostname) {
            return '';
        }

        var path = previewDecode(parsed.pathname)
            .replace(/-\d{2,5}x\d{2,5}(\.[A-Za-z0-9]{2,5})$/, '$1')
            .replace(/-scaled(\.[A-Za-z0-9]{2,5})$/, '$1');

        return parsed.hostname.toLowerCase().replace(/^www\./, '') + path.toLowerCase();
    }

    function holdsOnly(parent, child) {
        return Array.prototype.every.call(parent.childNodes, function(node) {
            if (node === child || node.nodeType === 8) {
                return true;
            }
            if (node.nodeType === 3) {
                return $.trim(node.nodeValue.replace(/\u00a0/g, ' ')) === '';
            }
            return node.nodeType === 1 && ['figcaption', 'source', 'br'].indexOf(node.nodeName.toLowerCase()) !== -1;
        });
    }

    /* Mirrors WPNC_Image_Picker::strip_duplicate(): the featured image is shown
       above the title, so the same picture inside the text is dropped. */
    function stripFeaturedFromBody(root, featured) {
        var target = imageIdentity(featured);

        if (!target) {
            return;
        }

        Array.prototype.slice.call(root.getElementsByTagName('img')).forEach(function(img) {
            if (imageIdentity(img.getAttribute('src')) !== target) {
                return;
            }

            var node = img;
            while (node.parentNode && node.parentNode !== root &&
                ['a', 'figure', 'p', 'picture', 'span', 'div'].indexOf(node.parentNode.nodeName.toLowerCase()) !== -1 &&
                holdsOnly(node.parentNode, node)) {
                node = node.parentNode;
            }

            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        });
    }

    /* Mirrors WPNC_Template::tidy(). */
    function tidyHtml(html) {
        return $.trim(String(html)
            .replace(/<(p|figure|figcaption)[^>]*>\s*(?:&nbsp;|\u00a0|\s)*<\/\1>/gi, '')
            .replace(/<a[^>]*href=(["'])\s*\1[^>]*>([\s\S]*?)<\/a>/gi, '$2')
            .replace(/(?:[ \t]*\r?\n){3,}/g, '\n\n'));
    }

    /* Mirrors WPNC_Template::render(), including the order the placeholders
       are replaced in, which decides what happens when one value contains
       another placeholder. */
    function renderTemplate(template, values) {
        var out = $.trim(String(template || '')) ? String(template) : '{content}';

        Object.keys(values).forEach(function(key) {
            out = out.split('{' + key + '}').join(values[key]);
        });

        return tidyHtml(out);
    }

    function previewText(html) {
        return $.trim((inertRoot(html).textContent || '').replace(/\s+/g, ' '));
    }

    function previewExcerpt(html) {
        var words = previewText(html).split(' ').filter(Boolean);
        return words.slice(0, 55).join(' ') + (words.length > 55 ? '…' : '');
    }

    function previewCounts(html) {
        var text = previewText(html);
        var words = text ? text.split(' ').length : 0;
        return { words: words, minutes: words ? Math.max(1, Math.ceil(words / 200)) : 0 };
    }

    function showPreviewFeatured(url) {
        if (url === shownFeatured) {
            return;
        }
        shownFeatured = url;
        renderPreviewFeatured(url);
    }

    function renderLocalPreview() {
        var $pane = $('#wpnc-preview-body');
        if (!$pane.length || !$('#wpnc-edit-modal').is(':visible')) {
            return;
        }

        var config = previewConfig();
        var title = $('#wpnc-edit-title').val() || '';
        var featured = featuredUrl();
        var link = previewSafeUrl(previewContext.main_link);

        var body = inertRoot(editorGet());
        previewClean(body, config.allowed || {});
        stripFeaturedFromBody(body, featured);
        var content = body.innerHTML;

        var html = renderTemplate(config.template, {
            content: content,
            title: previewEscape(title),
            excerpt: previewEscape(previewExcerpt(content)),
            source_name: previewEscape(previewContext.source_name),
            source_url: previewEscape(link),
            source_label: previewEscape(config.source_label),
            source_link: '<a href="' + previewEscape(link) + '" target="_blank" rel="nofollow noopener">' +
                previewEscape(previewContext.source_name || link) + '</a>',
            date: previewEscape(previewContext.date),
            image: previewSafeUrl(featured)
                ? '<figure class="wpnc-source-image"><img src="' + previewEscape(featured) + '" alt="' + previewEscape(title) + '" /></figure>'
                : '',
            tags: previewEscape($('#wpnc-edit-tags').val() || '')
        });

        showPreviewFeatured(featured);
        $('#wpnc-preview-title').text(title);

        // Sanitised again as a whole, because the template comes from Settings
        // and nothing else holds it to an allowlist. Skipped when nothing
        // changed, so moving the cursor does not reload the pictures.
        html = previewSanitize(html);
        if (html !== lastLocalHtml) {
            lastLocalHtml = html;
            $pane.html(html);
        }

        updateCounts(previewCounts(content));
    }

    function confirmPreview() {
        var $pane = $('#wpnc-preview-body');
        if (!$pane.length || !$('#wpnc-edit-modal').is(':visible')) {
            return;
        }

        var payload = {
            id: $('#wpnc-edit-id').val(),
            title: $('#wpnc-edit-title').val(),
            content: editorGet(),
            tags: $('#wpnc-edit-tags').val(),
            image_url: featuredUrl()
        };
        var snapshot = JSON.stringify(payload);

        if (snapshot === lastServerSnapshot) {
            return;
        }

        var seq = ++previewSeq;

        request('wpnc_preview_item', payload)
            .done(function(data) {
                // An answer about an older version of the text must not
                // replace what has been typed since it was asked.
                if (seq !== previewSeq) {
                    return;
                }

                lastServerSnapshot = snapshot;
                lastLocalHtml = '';
                $('#wpnc-preview-sync').text('');
                showPreviewFeatured(data.featured || '');
                $('#wpnc-preview-title').text(data.title || '');
                // Rendered through the publisher's own template and already
                // passed through wp_kses there.
                $pane.html(data.html || '');
                updateCounts(data.stats);
            })
            .fail(function() {
                if (seq !== previewSeq) {
                    return;
                }
                // The local copy stays; only the confirmation is missing.
                $('#wpnc-preview-sync').text(t('preview_unconfirmed', 'Could not confirm with the server'));
            });
    }

    /* Everything that changes the article comes through here: drawn on the
       next frame, confirmed once typing pauses. */
    function refreshPreview() {
        if (previewFrame) {
            window.cancelAnimationFrame(previewFrame);
        }

        previewFrame = window.requestAnimationFrame(function() {
            previewFrame = null;
            renderLocalPreview();
        });

        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(confirmPreview, 600);
    }

    function schedulePreview() {
        refreshPreview();
    }

    /**
     * Headline suggestions: pick one, then apply it to the Title field.
     *
     * Nothing is written until Apply is pressed - a suggestion replacing the
     * headline the moment it arrived would be a change nobody asked for.
     */
    function renderTitleSuggestions(items) {
        var $box = $('#wpnc-title-suggestions').empty().show();

        $('<div>').addClass('wpnc-suggest-head')
            .append($('<span>').addClass('wpnc-suggest-label').text(t('suggested_titles', 'Suggested headlines')))
            .append($('<button>').attr({ type: 'button', id: 'wpnc-suggest-dismiss' })
                .addClass('wpnc-suggest-dismiss')
                .attr('aria-label', t('dismiss', 'Dismiss'))
                .text('×'))
            .appendTo($box);

        var $list = $('<div>').addClass('wpnc-suggest-list').attr('role', 'radiogroup').appendTo($box);

        $.each(items, function(index, title) {
            var id = 'wpnc-title-option-' + index;
            var $row = $('<label>').addClass('wpnc-suggest-option').attr('for', id).appendTo($list);

            $('<input>').attr({
                type: 'radio',
                name: 'wpnc-title-option',
                id: id,
                value: title
            }).prop('checked', 0 === index).appendTo($row);

            $('<span>').attr('dir', 'auto').text(title).appendTo($row);
        });

        $('<div>').addClass('wpnc-suggest-actions')
            .append($('<button>').attr({ type: 'button', id: 'wpnc-apply-title' })
                .addClass('button button-small button-primary')
                .text(t('apply_to_title', 'Apply to title')))
            .appendTo($box);
    }

    /**
     * Tag suggestions, shown beside the field they belong to.
     */
    function renderTagSuggestions(items) {
        var $box = $('#wpnc-tag-suggestions').empty().show();

        $('<div>').addClass('wpnc-suggest-head')
            .append($('<span>').addClass('wpnc-suggest-label').text(t('suggested_tags', 'Suggested tags')))
            .append($('<button>').attr({ type: 'button', id: 'wpnc-tagsuggest-dismiss' })
                .addClass('wpnc-suggest-dismiss')
                .attr('aria-label', t('dismiss', 'Dismiss'))
                .text('×'))
            .appendTo($box);

        var $list = $('<div>').addClass('wpnc-suggest-chips').appendTo($box);

        $.each(items, function(index, tag) {
            var $chip = $('<label>').addClass('wpnc-suggest-chip').appendTo($list);
            $('<input>').attr({ type: 'checkbox', value: tag }).prop('checked', true).appendTo($chip);
            $('<span>').attr('dir', 'auto').text(tag).appendTo($chip);
        });

        $('<div>').addClass('wpnc-suggest-actions')
            .append($('<button>').attr({ type: 'button', id: 'wpnc-apply-tags' })
                .addClass('button button-small button-primary')
                .text(t('apply_to_tags', 'Add to tags')))
            .appendTo($box);
    }

    /**
     * Merge the ticked suggestions into the tags field.
     *
     * Added rather than replaced, and de-duplicated case-insensitively, so
     * applying twice does not double every tag.
     */
    function applyTagSuggestions() {
        var $field = $('#wpnc-edit-tags');
        var existing = ($field.val() || '').split(',');
        var seen = {};
        var out = [];

        function push(value) {
            value = $.trim(value);
            if (!value) {
                return;
            }
            var key = value.toLowerCase();
            if (seen[key]) {
                return;
            }
            seen[key] = true;
            out.push(value);
        }

        $.each(existing, function(index, value) { push(value); });
        $('#wpnc-tag-suggestions input:checked').each(function() { push($(this).val()); });

        $field.val(out.join(', '));
        $('#wpnc-tag-suggestions').hide();
        schedulePreview();
    }

    function renderAiStrip($parent) {
        var $strip = $('<div>').addClass('wpnc-ai').appendTo($parent);

        $('<div>').addClass('wpnc-ai-head')
            .append($('<span>').addClass('wpnc-ai-badge').text(t('ai_badge', 'AI')))
            .append($('<span>').addClass('wpnc-ai-title').text(t('ai_title', 'Assistant')))
            .appendTo($strip);

        if (!wpnc_ajax.ai_enabled) {
            $('<p>').addClass('wpnc-ai-off').attr('dir', 'auto')
                .text(t('ai_disabled', 'The assistant needs an API key for the chosen AI provider. An administrator adds it under Settings.'))
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

        // Headline suggestions land here, under the field they would replace,
        // rather than in the article body.
        $('<div>').attr('id', 'wpnc-title-suggestions').addClass('wpnc-suggest').hide().appendTo($content);

        var $split = $('<div>').addClass('wpnc-split').appendTo($content);
        var $left = $('<div>').addClass('wpnc-split-edit').appendTo($split);
        var $right = $('<div>').addClass('wpnc-split-preview').appendTo($split);

        $('<div>').addClass('wpnc-preview-head')
            .append($('<span>').addClass('wpnc-preview-label').text(t('preview', 'Preview')))
            .append($('<span>').attr('id', 'wpnc-preview-sync').addClass('wpnc-preview-sync'))
            .append($('<span>').attr('id', 'wpnc-editor-counts').addClass('wpnc-preview-counts'))
            .appendTo($right);

        var $paper = $('<div>').addClass('wpnc-preview-paper').appendTo($right);
        $('<h3>').attr({ id: 'wpnc-preview-title', dir: 'auto' }).addClass('wpnc-preview-title').appendTo($paper);
        $('<div>').attr({ id: 'wpnc-preview-body', dir: 'auto' }).addClass('wpnc-preview-body').appendTo($paper);

        renderFeatured($left);

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

        $('<div>').attr('id', 'wpnc-tag-suggestions').addClass('wpnc-suggest').hide().appendTo($left);

        // What Telegram and Bale show under the picture. Written here - by hand
        // or by the assistant - so it is read before it goes out.
        var $caption = labelledField($left, 'wpnc-edit-caption', t('field_caption', 'Channel caption'),
            $('<textarea>').attr({ rows: 3, dir: 'auto' }).addClass('large-text'));
        $('<span>').addClass('wpnc-field-hint').attr('dir', 'auto')
            .text(t('caption_hint', 'Used for Telegram and Bale. Leave empty to use the opening of the article.'))
            .insertAfter($caption);

        // What a search result shows under the headline. The counter is there
        // because the limit is the whole point of the field.
        var $seoDescription = labelledField($left, 'wpnc-edit-seo-description', t('field_seo_description', 'Meta description'),
            $('<textarea>').attr({ rows: 2, dir: 'auto' }).addClass('large-text'));
        $('<span>').attr('id', 'wpnc-seo-count').addClass('wpnc-seo-count').insertAfter($seoDescription);
        $seoDescription.on('input', updateSeoCount);

        var $seoKeyword = labelledField($left, 'wpnc-edit-seo-keyword', t('field_seo_keyword', 'Focus keyword'),
            $('<input>').attr({ type: 'text', dir: 'auto' }).addClass('large-text'));
        $('<span>').addClass('wpnc-field-hint').attr('dir', 'auto')
            .text(t('seo_hint', 'For search engines. Leave the description empty to use the opening of the article. Yoast or Rank Math receive both when installed.'))
            .insertAfter($seoKeyword);

        renderAdvanced($left);

        var $actions = $('<p>').addClass('wpnc-modal-actions')
            .append($('<button>').attr('type', 'button').addClass('button button-primary').attr('id', 'wpnc-save-edit').text(t('save', 'Save')));

        // Sending from inside the editor always saves first. Approving the
        // stored copy while unsaved edits sat on screen would publish the
        // version the editor had just finished replacing.
        var ready = readyChannels();

        if (ready.length) {
            $actions.append($('<button>').attr('type', 'button').addClass('button wpnc-save-send').attr('id', 'wpnc-save-send').text(t('save_and_send', 'Save and send')));

            if (ready.length > 1) {
                var $target = $('<select>').attr('id', 'wpnc-send-target').addClass('wpnc-send-target');

                $.each(ready, function(index, channel) {
                    $('<option>').attr('value', channel.slug).text(channel.label).appendTo($target);
                });

                $('<option>').attr('value', 'all').text(t('send_all', 'All')).appendTo($target);
                $actions.append($target);
            }
        }

        $actions
            .append($('<button>').attr('type', 'button').addClass('button').attr('id', 'wpnc-close-modal').text(t('cancel', 'Cancel')))
            .appendTo($content);

        $('<div>').attr('id', 'wpnc-edit-error').addClass('wpnc-inline-error').attr('dir', 'auto').hide().appendTo($content);

        $modal.appendTo($app);
    }

    function openModal(item) {
        editorHistory = [];

        // What the template needs that the editor does not show, and a clean
        // slate so nothing from the previous item can land in this one.
        previewContext = {
            main_link: item.main_link || '',
            source_name: item.source_name || '',
            date: item.pub_date_display || ''
        };
        previewSeq++;
        lastServerSnapshot = '';
        lastLocalHtml = '';
        shownFeatured = null;
        $('#wpnc-preview-sync').text('');

        $('#wpnc-edit-id').val(item.id);
        $('#wpnc-edit-title').val(item.title || '');
        $('#wpnc-edit-tags').val(item.tags || '');
        $('#wpnc-edit-image').val(item.image_url || '');
        showFeatured();

        var overrides = item.publish_options || {};
        $('#wpnc-edit-post-type').val(overrides.post_type || '');
        $('#wpnc-edit-post-status').val(overrides.post_status || '');
        $('#wpnc-edit-post-author').val(overrides.post_author ? String(overrides.post_author) : '');
        $('#wpnc-edit-publish-at').val(overrides.publish_at_local || '');
        $('#wpnc-edit-caption').val(overrides.caption || '');
        $('#wpnc-edit-seo-description').val(overrides.seo_description || '');
        $('#wpnc-edit-seo-keyword').val(overrides.seo_keyword || '');
        updateSeoCount();

        // Seeded from the row's own column, not just from the overrides: a
        // category can arrive from the source's mapping at fetch time, and
        // showing this blank would have quietly cleared it on the next save.
        $('#wpnc-edit-category').val(item.category_id ? String(item.category_id) : '');
        $('#wpnc-edit-error').hide().empty();
        $('#wpnc-editor-undo').prop('disabled', true);
        // Suggestions belong to the item that produced them.
        $('#wpnc-title-suggestions, #wpnc-tag-suggestions').hide().empty();
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
                    directionality: (wpnc_ajax.lang === 'fa') ? 'rtl' : 'ltr',
                    setup: function(editor) {
                        // The article body never asked for a preview before,
                        // so typing in it changed nothing on the right.
                        editor.on('input keyup change undo redo SetContent ExecCommand', refreshPreview);

                        // Baseline once TinyMCE has normalised the markup, so
                        // opening and closing is never counted as an edit.
                        editor.on('init', function() {
                            editorBaseline = editorSnapshot();
                            refreshPreview();
                        });
                    }
                },
                quicktags: true,
                mediaButtons: true
            });
        } else {
            $('#' + EDITOR_ID).val(item.description || '');
        }

        $('#wpnc-edit-title').trigger('focus');

        // Drawn at once from the text as stored, before TinyMCE has even
        // loaded: an empty pane for the first moments reads as broken.
        refreshPreview();

        // Fallback baseline when the rich editor is unavailable or slow to
        // start; the init handler above replaces it once TinyMCE is ready.
        window.setTimeout(function() {
            if (editorBaseline === null) {
                editorBaseline = editorSnapshot();
            }
        }, 250);

        $('#wpnc-edit-title, #wpnc-edit-tags').off('input.wpncpreview').on('input.wpncpreview', schedulePreview);

        // The plain-text tab of the editor is a textarea, not TinyMCE.
        $('#' + EDITOR_ID).off('input.wpncpreview').on('input.wpncpreview', refreshPreview);

        // Debounced so typing an address does not request a picture per key.
        $('#wpnc-edit-image').off('input.wpncpreview').on('input.wpncpreview', function() {
            window.clearTimeout(featuredTimer);
            featuredTimer = window.setTimeout(showFeatured, 400);
            schedulePreview();
        });
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
        if (previewFrame) {
            window.cancelAnimationFrame(previewFrame);
            previewFrame = null;
        }
        // Any answer still on its way belongs to an editor that is closing.
        previewSeq++;
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

    /* ==========================================================
       Keyboard moderation

       J and K move between cards and the other keys act on the one in focus.
       Approving by key sends to the site only: a post can be undone from the
       queue, a message in a channel cannot, so the messengers stay a click.
       ========================================================== */

    var queueFocus = -1;

    function queueCards() {
        // Folded copies of a story are not stops on the way down the queue.
        return $('#wpnc-moderation-app .wpnc-card:visible');
    }

    function focusCard(index) {
        var $cards = queueCards();

        if (!$cards.length) {
            queueFocus = -1;
            return;
        }

        queueFocus = Math.max(0, Math.min(index, $cards.length - 1));
        $cards.removeClass('is-focused').removeAttr('aria-current');

        var $card = $cards.eq(queueFocus).addClass('is-focused').attr('aria-current', 'true');
        var element = $card.get(0);

        if (element && element.scrollIntoView) {
            element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function focusedCard() {
        return queueFocus >= 0 ? queueCards().eq(queueFocus) : $();
    }

    function typingInField(target) {
        var tag = ((target && target.nodeName) || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || !!(target && target.isContentEditable);
    }

    function toggleShortcutHelp(show) {
        var $help = $('#wpnc-shortcuts');

        if (!$help.length) {
            if (show === false) {
                return;
            }

            $help = $('<div>')
                .attr({ id: 'wpnc-shortcuts', role: 'dialog', 'aria-label': t('shortcuts_title', 'Keyboard shortcuts') })
                .addClass('wpnc-shortcuts')
                .hide();

            $('<h3>').text(t('shortcuts_title', 'Keyboard shortcuts')).appendTo($help);

            var $list = $('<dl>').appendTo($help);
            [
                ['J / K', t('shortcut_move', 'Next / previous item')],
                ['E', t('shortcut_edit', 'Edit')],
                ['A', t('shortcut_approve', 'Approve to the site')],
                ['R', t('shortcut_reject', 'Reject')],
                ['X', t('shortcut_select', 'Select for bulk actions')],
                ['/', t('shortcut_search', 'Search')],
                ['?', t('shortcut_help', 'Show or hide this list')]
            ].forEach(function(row) {
                $('<dt>').append($('<kbd>').text(row[0])).appendTo($list);
                $('<dd>').text(row[1]).appendTo($list);
            });

            $('<p>').addClass('description').attr('dir', 'auto')
                .text(t('shortcut_note', 'A sends to the site only. A message in Telegram or Bale cannot be taken back, so those stay a click.'))
                .appendTo($help);

            // Inside the panel wrapper, so it inherits the panel's direction
            // and design tokens.
            var $host = $('.wpnc-wrap').first();
            $help.appendTo($host.length ? $host : 'body').on('click', function() {
                toggleShortcutHelp(false);
            });
        }

        $help.toggle(typeof show === 'boolean' ? show : !$help.is(':visible'));
    }

    function handleQueueKey(event) {
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        // While the history is open, keys belong to it: only Escape does
        // anything, and it closes the dialog rather than moving the queue.
        if ($('#wpnc-history').is(':visible')) {
            if (event.key === 'Escape') {
                $('#wpnc-history').hide();
            }
            return;
        }

        if ($('#wpnc-edit-modal').is(':visible') || !$('#wpnc-moderation-app').is(':visible')) {
            return;
        }

        if (typingInField(event.target)) {
            return;
        }

        var key = event.key || '';

        if (key === '?') {
            event.preventDefault();
            toggleShortcutHelp();
            return;
        }

        if (key === 'Escape') {
            toggleShortcutHelp(false);
            return;
        }

        if (key === '/') {
            event.preventDefault();
            $('#wpnc-queue-search').trigger('focus');
            return;
        }

        var lower = key.toLowerCase();

        if (lower === 'j') {
            event.preventDefault();
            focusCard(queueFocus + 1);
            return;
        }

        if (lower === 'k') {
            event.preventDefault();
            focusCard(queueFocus < 0 ? 0 : queueFocus - 1);
            return;
        }

        var $card = focusedCard();

        if (!$card.length) {
            return;
        }

        // Enter on a focused button belongs to that button.
        if (key === 'Enter' && /^(button|a)$/i.test((event.target && event.target.nodeName) || '')) {
            return;
        }

        if (lower === 'e' || key === 'Enter') {
            var $edit = $card.find('.wpnc-edit');
            if ($edit.length) {
                event.preventDefault();
                $edit.trigger('click');
            }
            return;
        }

        if (lower === 'a') {
            var $site = $card.find('.wpnc-approve').filter(function() {
                return $(this).data('channels') === 'site';
            }).first();

            if ($site.length) {
                event.preventDefault();
                $site.trigger('click');
            }
            return;
        }

        if (lower === 'r') {
            // Through the button, so it asks for the same confirmation.
            var $reject = $card.find('.wpnc-reject');
            if ($reject.length) {
                event.preventDefault();
                $reject.trigger('click');
            }
            return;
        }

        if (lower === 'x') {
            var $box = $card.find('.wpnc-item-checkbox');
            if ($box.length) {
                event.preventDefault();
                $box.prop('checked', !$box.prop('checked')).trigger('change');
            }
        }
    }

    function bindQueueEvents() {
        // A re-rendered queue keeps the position the keyboard had reached.
        if (queueFocus >= 0) {
            focusCard(queueFocus);
        }

        $('.wpnc-history-open').off('click').on('click', function() {
            showHistory($(this).data('id'));
        });

        $('#wpnc-select-all').off('change').on('change', function() {
            $('.wpnc-item-checkbox').prop('checked', $(this).prop('checked'));
        });

        $('.wpnc-approve').off('click').on('click', function() {
            actionOne($(this), 'wpnc_approve_item', { channels: $(this).data('channels') || 'site' });
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

        $('#wpnc-save-edit').off('click').on('click', function() {
            saveEdit($(this));
        });
        $('#wpnc-save-send').off('click').on('click', saveAndSend);
        $('#wpnc-load-full-text').off('click').on('click', loadFullText);
        $('#wpnc-image-library').off('click').on('click', chooseFeatured);
        $('#wpnc-image-detect').off('click').on('click', detectFeatured);
        $('#wpnc-image-remove').off('click').on('click', function() {
            setFeatured('');
        });
        $('#wpnc-editor-undo').off('click').on('click', editorUndo);
        $('.wpnc-ai-run').off('click').on('click', function() {
            runAi($(this), $(this).data('action'));
        });

        $(document).off('click.wpncsuggest').on('click.wpncsuggest', '#wpnc-apply-title', function() {
            var chosen = $('input[name="wpnc-title-option"]:checked').val();

            if (chosen) {
                $('#wpnc-edit-title').val(chosen);
                schedulePreview();
            }

            $('#wpnc-title-suggestions').hide();
        });

        $(document).on('click.wpncsuggest', '#wpnc-apply-tags', applyTagSuggestions);

        $(document).on('click.wpncsuggest', '#wpnc-suggest-dismiss', function() {
            $('#wpnc-title-suggestions').hide();
        });

        $(document).on('click.wpncsuggest', '#wpnc-tagsuggest-dismiss', function() {
            $('#wpnc-tag-suggestions').hide();
        });

        $('#wpnc-bulk-approve').off('click').on('click', function() {
            actionBulk($(this), 'wpnc_bulk_approve', { channels: $(this).data('channels') || 'site' });
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
                // Headlines and tags are suggestions to choose from. Writing
                // them into the body is what this used to do, and it threw
                // the article away to make room for a list of titles.
                if (data.kind === 'titles') {
                    renderTitleSuggestions(data.suggestions || []);
                    editorStatus(data.message, 'ok');
                    return;
                }

                if (data.kind === 'tags') {
                    renderTagSuggestions(data.suggestions || []);
                    editorStatus(data.message, 'ok');
                    return;
                }

                // Into its own field, never the article.
                if (data.kind === 'caption') {
                    $('#wpnc-edit-caption').val(data.caption || '').trigger('focus');
                    editorStatus(data.message, 'ok');
                    return;
                }

                if (data.kind === 'seo') {
                    $('#wpnc-edit-seo-description').val(data.description || '').trigger('focus');
                    $('#wpnc-edit-seo-keyword').val(data.keyword || '');
                    updateSeoCount();
                    editorStatus(data.message, 'ok');
                    return;
                }

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

    /**
     * Save the open item.
     *
     * @param {jQuery}   $button The control that was pressed.
     * @param {Function} [then]  Called with the item id once the save has
     *                           been confirmed by the server. When given, the
     *                           modal stays open and the queue is not
     *                           reloaded - that is the caller's to do.
     */
    function saveEdit($button, then) {
        var $error = $('#wpnc-edit-error');
        var title = $.trim($('#wpnc-edit-title').val());
        var id = $('#wpnc-edit-id').val();

        $error.hide().empty();

        if (!title) {
            $error.text(t('title_required', 'Title is required.')).show();
            $('#wpnc-edit-title').trigger('focus');
            return;
        }

        setBusy($button, true);
        request('wpnc_edit_item', {
            id: id,
            title: title,
            description: editorGet(),
            tags: $('#wpnc-edit-tags').val(),
            image_url: featuredUrl(),
            publish_options: publishOptions()
        })
            .done(function(data) {
                editorBaseline = null;

                if (then) {
                    then(id);
                    return;
                }

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

    /**
     * Save, then send - in that order, and only if the save was confirmed.
     *
     * Sending a version that failed to save is the one outcome worth ruling
     * out here, so the send never runs on its own.
     */
    function saveAndSend() {
        var $button = $(this);
        var $error = $('#wpnc-edit-error');
        var $target = $('#wpnc-send-target');
        var channels = $target.length ? $target.val() : 'site';

        saveEdit($button, function(id) {
            setBusy($button, true);

            request('wpnc_approve_item', { id: id, channels: channels })
                .done(function(data) {
                    closeModal(true);
                    flash((data && data.message) || t('approved', 'Approved.'), 'ok');
                    loadQueue();
                    loadDashboard();
                })
                .fail(function(error) {
                    // The edit is safely stored, so say what happened and
                    // leave the modal open rather than discarding anything.
                    $error.text(error.message).show();
                })
                .always(function() {
                    setBusy($button, false);
                });
        });
    }

    function actionOne($button, action, extra) {
        var id = $button.data('id');
        var $card = $('#wpnc-item-' + id);
        var payload = $.extend({ id: id }, extra || {});

        setBusy($button, true);
        $card.addClass('wpnc-card-busy');

        request(action, payload)
            .done(function(data) {
                flash((data && data.message) || t('done', 'Done.'), 'ok');
                $card.fadeOut(function() {
                    $(this).remove();
                    if (!$('.wpnc-card').length) {
                        loadQueue();
                        return;
                    }
                    // The next card slides into the place of the one just
                    // handled, so keyboard focus lands on it without a key.
                    if (queueFocus >= 0) {
                        focusCard(queueFocus);
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

    function actionBulk($button, action, extra) {
        var ids = $('.wpnc-item-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            flash(t('select_something', 'Select at least one item first.'), 'warn');
            return;
        }

        setBusy($button, true);
        request(action, $.extend({ ids: ids }, extra || {}))
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

    /**
     * Connection check: run the probes and show what each address did.
     *
     * The panel already tells an editor that something is cutting requests
     * short. This is the evidence for it, gathered from the server rather
     * than inferred from one failure.
     */
    function bindDiagnostics() {
        var $button = $('#wpnc-diagnose');
        var $panel = $('#wpnc-diagnose-result');

        if (!$button.length || $button.data('wpncBound')) {
            return;
        }

        $button.data('wpncBound', true);

        $button.on('click', function() {
            setBusy($button, true);
            $panel.prop('hidden', false).empty().append(
                $('<p>').addClass('wpnc-diagnose-running').attr('dir', 'auto')
                    .text(t('diagnose_running', 'Testing outbound requests. This can take up to a minute.'))
            );

            request('wpnc_diagnose_network')
                .done(function(data) {
                    renderDiagnostics($panel, data);
                })
                .fail(function(error) {
                    $panel.empty().append(
                        $('<p>').addClass('wpnc-status-error').attr('dir', 'auto').text(error.message)
                    );
                })
                .always(function() {
                    setBusy($button, false);
                });
        });

        bindEndpointProbe();
    }

    /**
     * Which AI addresses answer from this server.
     *
     * "Choose another provider" is not advice anybody can act on without
     * this: the addresses that work depend on where the server sits.
     */
    function bindEndpointProbe() {
        var $button = $('#wpnc-probe-endpoints');
        var $panel = $('#wpnc-probe-result');

        if (!$button.length || $button.data('wpncBound')) {
            return;
        }

        $button.data('wpncBound', true);

        $button.on('click', function() {
            setBusy($button, true);
            $panel.prop('hidden', false).empty().append(
                $('<p>').addClass('wpnc-diagnose-running').attr('dir', 'auto')
                    .text(t('probe_running', 'Asking each address whether it answers. This can take a minute.'))
            );

            request('wpnc_probe_endpoints', { url: $.trim($('#wpnc-probe-url').val() || '') })
                .done(function(data) {
                    renderDiagnostics($panel, data);
                })
                .fail(function(error) {
                    $panel.empty().append(
                        $('<p>').addClass('wpnc-status-error').attr('dir', 'auto').text(error.message)
                    );
                })
                .always(function() {
                    setBusy($button, false);
                });
        });
    }

    function renderDiagnostics($panel, data) {
        $panel.empty();

        if (!data) {
            return;
        }

        var verdict = data.verdict || {};

        $('<p>')
            .addClass('wpnc-diagnose-verdict is-' + (verdict.code || 'unknown'))
            .attr('dir', 'auto')
            .text(verdict.message || '')
            .appendTo($panel);

        var $list = $('<ul>').addClass('wpnc-diagnose-list').appendTo($panel);

        $.each(data.probes || [], function(index, probe) {
            var detail = probe.ok
                ? t('diagnose_answered', 'answered') + ' (HTTP ' + probe.status + ')'
                : (probe.error || t('diagnose_failed', 'no answer'));

            $('<li>')
                .addClass(probe.ok ? 'is-ok' : 'is-error')
                .attr('dir', 'auto')
                .append($('<span>').addClass('wpnc-diagnose-label').text(probe.label))
                .append($('<span>').addClass('wpnc-diagnose-detail').text(detail))
                .append($('<span>').addClass('wpnc-diagnose-elapsed').text(probe.elapsed + 's'))
                .appendTo($list);
        });

        if (!data.ai_timeout) {
            return;
        }

        $('<p>')
            .addClass('wpnc-diagnose-meta')
            .attr('dir', 'auto')
            .text(
                t('diagnose_allowed', 'Allowed per request') + ': ' + data.asked + 's' +
                '  ·  ' + t('diagnose_ai_timeout', 'Assistant timeout') + ': ' + data.ai_timeout + 's' +
                '  ·  ' + t('diagnose_php_limit', 'PHP time limit') + ': ' + (data.php_limit || t('diagnose_none', 'none'))
            )
            .appendTo($panel);
    }

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

        bindDiagnostics();

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
       Delivery destinations

       The test runs against the stored credentials, so a passing result is
       what unlocks the destination's button on the queue.
       ========================================================== */

    function bindChannelSettings() {
        var $blocks = $('.wpnc-channel');
        if (!$blocks.length) {
            return;
        }

        // A real message to the administrator's chat: the only proof that
        // alerts will actually be seen.
        $('#wpnc-alert-test').off('click').on('click', function() {
            var $button = $(this);
            var $result = $('#wpnc-alert-result');

            setBusy($button, true);
            $result.removeClass('wpnc-channel-ok wpnc-channel-bad').text(t('processing', 'Processing...'));

            request('wpnc_test_alert')
                .done(function(data) {
                    $result.addClass('wpnc-channel-ok').text((data && data.message) || t('done', 'Done.'));
                })
                .fail(function(error) {
                    $result.addClass('wpnc-channel-bad').text(error.message);
                })
                .always(function() {
                    setBusy($button, false);
                });
        });

        $('.wpnc-channel-test').on('click', function() {
            var $button = $(this);
            var $block = $button.closest('.wpnc-channel');
            var $result = $block.find('.wpnc-channel-result');
            var $state = $block.find('.wpnc-channel-state');

            setBusy($button, true);
            $result.removeClass('wpnc-channel-ok wpnc-channel-bad').text(t('processing', 'Processing...'));

            request('wpnc_test_channel', { channel: $button.data('channel') })
                .done(function(data) {
                    $result.addClass('wpnc-channel-ok').text((data && data.message) || t('done', 'Done.'));
                    $state.removeClass('is-empty is-untested').addClass('is-ready')
                        .text(t('channel_ready', 'Tested and ready'));
                })
                .fail(function(error) {
                    $result.addClass('wpnc-channel-bad').text(error.message);
                    $state.removeClass('is-ready').addClass('is-untested')
                        .text(t('channel_untested', 'Not tested yet'));
                })
                .always(function() {
                    setBusy($button, false);
                });
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

        $table.on('click', '.wpnc-policy-edit', function() {
            var $button = $(this);
            var $row = $button.closest('tr').next('.wpnc-policy-row');
            var opening = $row.prop('hidden');

            $row.prop('hidden', !opening);
            $button.attr('aria-expanded', opening ? 'true' : 'false');
        });

        $table.on('click', '.wpnc-policy-save', function() {
            var $button = $(this);
            var $form = $button.closest('.wpnc-policy-form');
            var $result = $form.find('.wpnc-policy-result');
            var mode = $form.find('.wpnc-policy-mode').val();

            // The one choice here that takes a person out of the loop.
            if (mode === 'publish' && !window.confirm(t('confirm_policy_publish', 'Items from this source will be published without anyone reading them first. Continue?'))) {
                return;
            }

            var channels = $form.find('.wpnc-policy-channels input:checked').map(function() {
                return $(this).val();
            }).get();

            setBusy($button, true);
            request('wpnc_save_source_policy', {
                source_id: $form.data('source-id'),
                mode: mode,
                rewrite: $form.find('.wpnc-policy-rewrite').val(),
                channels: channels
            })
                .done(function(data) {
                    $result.removeClass('wpnc-health-bad').addClass('wpnc-health-ok').text(data.message);
                    $form.closest('tr').prev('tr').find('.wpnc-policy-summary').text(data.summary || '');
                })
                .fail(function(error) {
                    $result.removeClass('wpnc-health-ok').addClass('wpnc-health-bad').text(error.message);
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

    $(document).off('keydown.wpncqueue').on('keydown.wpncqueue', handleQueueKey);

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
    bindChannelSettings();
    bindSourceHealth();
});
