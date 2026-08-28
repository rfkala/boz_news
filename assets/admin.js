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
        }

        if (!items.length) {
            renderEmptyQueue($app, emptyHintFor(queueState.status));
            renderPagination($app, data);
            bindQueueEvents();
            return;
        }

        var $grid = $('<div>').addClass('wpnc-grid').appendTo($app);
        items.forEach(function(item) {
            renderCard($grid, item, terminal);
        });

        renderEditModal($app);
        renderPagination($app, data);
        bindQueueEvents();
    }

    function renderEmptyQueue($app, hint) {
        var $slot = $('<div>').appendTo($app);
        renderEmpty($slot, t('no_pending', 'No pending news in the queue.'), hint);
    }

    function renderCard($grid, item, terminal) {
        var $card = $('<div>').addClass('wpnc-card').attr('id', 'wpnc-item-' + item.id).appendTo($grid);

        if (!terminal) {
            $('<div>').addClass('wpnc-card-header')
                .append(
                    $('<input>')
                        .attr({ type: 'checkbox', 'aria-label': t('select_item', 'Select this item') })
                        .addClass('wpnc-item-checkbox')
                        .val(item.id)
                )
                .appendTo($card);
        }

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
            return;
        }

        $('<button>').attr('type', 'button').addClass('button button-primary wpnc-approve').data('id', item.id).text(t('approve', 'Approve')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-edit').data('item', item).text(t('edit', 'Edit')).appendTo($actions);
        $('<button>').attr('type', 'button').addClass('button wpnc-reject button-link-delete').data('id', item.id).text(t('reject', 'Reject')).appendTo($actions);
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

        request('wpnc_get_logs', { limit: 50 })
            .done(function(data) {
                var logs = (data && data.logs) || [];
                if (!logs.length) {
                    // Failure and "nothing logged yet" used to share this
                    // branch, so an error read as an empty log.
                    renderEmpty($logs,
                        t('no_logs', 'No logs yet.'),
                        t('empty_logs_hint', 'Run Fetch Now above and the result will appear here.'));
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

    function bindFetchTool() {
        var $btn = $('#wpnc-run-fetch');
        var $clear = $('#wpnc-clear-lock');
        var $status = $('#wpnc-fetch-status');
        var $progress = $('#wpnc-fetch-progress');
        var $fill = $progress.find('.wpnc-progress-fill');
        var $label = $progress.find('.wpnc-progress-text');

        if (!$btn.length) {
            return;
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

    loadQueue();
    loadStats();
    loadLogs();
    bindFetchTool();
});
