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

    function renderEmpty($el, message, hint) {
        var $box = $('<div>').addClass('wpnc-state wpnc-state-empty');
        $('<p>').addClass('wpnc-state-title').text(message).appendTo($box);
        if (hint) {
            $('<p>').addClass('wpnc-state-hint').attr('dir', 'auto').text(hint).appendTo($box);
        }
        $el.empty().append($box);
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
    function flash(message, type) {
        var $host = $('.wpnc-tab-content').first();
        if (!$host.length) {
            return;
        }

        $host.find('.wpnc-flash').remove();
        var $note = $('<div>')
            .addClass('wpnc-flash wpnc-flash-' + (type || 'ok'))
            .attr({ role: 'status', dir: 'auto' })
            .text(message)
            .prependTo($host);

        if (type !== 'error') {
            window.setTimeout(function() {
                $note.fadeOut(400, function() { $(this).remove(); });
            }, 4000);
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

    function emptyHintFor(status) {
        if (queueState.search) {
            return t('empty_search_hint', 'No item matches this search. Clear the search box to see the whole queue.');
        }
        if (status === 'pending') {
            return t('empty_pending_hint', 'Add RSS sources under Settings, then run Fetch Now from Logs & Tools.');
        }
        return t('empty_status_hint', 'Nothing has reached this status yet.');
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
            renderEmptyQueue($app, emptyHintFor(queueState.status));
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

    function renderEmptyQueue($app, hint) {
        var $slot = $('<div>').appendTo($app);
        renderEmpty($slot, t('no_pending', 'No pending news in the queue.'), hint);
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

    function renderEditModal($app) {
        var $modal = $('<div>')
            .attr({ id: 'wpnc-edit-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'wpnc-edit-heading' })
            .addClass('wpnc-modal')
            .hide();
        var $content = $('<div>').addClass('wpnc-modal-content').appendTo($modal);

        $('<h2>').attr('id', 'wpnc-edit-heading').text(t('edit_item', 'Edit News Item')).appendTo($content);
        $('<input>').attr({ type: 'hidden', id: 'wpnc-edit-id' }).appendTo($content);

        labelledField($content, 'wpnc-edit-title', t('field_title', 'Title'),
            $('<input>').attr({ type: 'text', dir: 'auto' }).addClass('large-text'));
        labelledField($content, 'wpnc-edit-desc', t('field_description', 'Description'),
            $('<textarea>').attr({ rows: 8, dir: 'auto' }).addClass('large-text'));
        labelledField($content, 'wpnc-edit-tags', t('field_tags', 'Tags (comma separated)'),
            $('<input>').attr({ type: 'text', dir: 'auto' }).addClass('large-text'));

        $('<p>').addClass('wpnc-modal-actions')
            .append($('<button>').attr('type', 'button').addClass('button button-primary').attr('id', 'wpnc-save-edit').text(t('save', 'Save')))
            .append(' ')
            .append($('<button>').attr('type', 'button').addClass('button').attr('id', 'wpnc-close-modal').text(t('cancel', 'Cancel')))
            .appendTo($content);

        $('<div>').attr('id', 'wpnc-edit-error').addClass('wpnc-inline-error').attr('dir', 'auto').hide().appendTo($content);

        $modal.appendTo($app);
    }

    function openModal(item) {
        $('#wpnc-edit-id').val(item.id);
        $('#wpnc-edit-title').val(item.title || '');
        $('#wpnc-edit-desc').val(item.description || '');
        $('#wpnc-edit-tags').val(item.tags || '');
        $('#wpnc-edit-error').hide().empty();
        $('#wpnc-edit-modal').show();
        $('#wpnc-edit-title').trigger('focus');
    }

    function closeModal() {
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
            description: $('#wpnc-edit-desc').val(),
            tags: $('#wpnc-edit-tags').val()
        })
            .done(function(data) {
                closeModal();
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

        var peak = 0;
        series.forEach(function(d) { peak = Math.max(peak, d.total); });

        if (!peak) {
            $('<p>').addClass('wpnc-state-hint').text(
                t('dash_no_activity', 'No items were collected in this period.')
            ).appendTo($panel);
            return;
        }

        var W = 720, H = 200, padB = 26, padT = 10;
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
            chart.appendChild(svg('text', {
                x: 2, y: y - 3, class: 'wpnc-chart-axis'
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
                .text(src.approved + ' / ' + src.total)
                .attr('title', t('dash_approved_of_total', 'approved of total'))
                .appendTo($row);
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
        if (event.key === 'Escape' && $('#wpnc-edit-modal').is(':visible')) {
            closeModal();
        }
    });

    $('#wpnc-log-level').on('change', loadLogs);

    loadQueue();
    loadStats();
    loadLogs();
    loadDashboard();
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
