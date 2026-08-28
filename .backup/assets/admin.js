jQuery(function($) {
    'use strict';

    var queueState = {
        page: 1,
        limit: 20,
        status: 'pending',
        search: ''
    };

    function t(key, fallback) {
        return (wpnc_ajax.i18n && wpnc_ajax.i18n[key]) || fallback || key;
    }

    function messageFromResponse(response, fallback) {
        if (response && response.data) {
            if (typeof response.data === 'string') {
                return response.data;
            }
            if (response.data.message) {
                return response.data.message;
            }
        }
        return fallback;
    }

    function post(action, data) {
        return $.post(wpnc_ajax.ajax_url, $.extend({
            action: action,
            nonce: wpnc_ajax.nonce
        }, data || {}));
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

    function appendText($parent, tag, text, className) {
        return $('<' + tag + '>')
            .addClass(className || '')
            .text(text || '')
            .appendTo($parent);
    }

    function loadQueue() {
        var $app = $('#wpnc-moderation-app');
        if (!$app.length) {
            return;
        }

        $app.empty().append($('<p>').text(t('loading', 'Loading...')));

        post('wpnc_get_queue', queueState).done(function(response) {
            if (!response.success) {
                $app.empty().append($('<p>').text(t('error_loading', 'Error loading queue.')));
                return;
            }
            renderQueue(response.data);
        }).fail(function() {
            $app.empty().append($('<p>').text(t('error_loading', 'Error loading queue.')));
        });
    }

    function renderQueue(data) {
        var $app = $('#wpnc-moderation-app');
        var items = data.items || [];
        $app.empty();

        var $toolbar = $('<div>').addClass('wpnc-queue-toolbar').appendTo($app);
        $('<input>')
            .attr({ type: 'search', placeholder: t('search', 'Search...') })
            .val(queueState.search)
            .on('search change keyup', debounce(function() {
                queueState.search = $(this).val();
                queueState.page = 1;
                loadQueue();
            }, 350))
            .appendTo($toolbar);

        $('<select>')
            .append($('<option>').val('pending').text('Pending'))
            .append($('<option>').val('error').text('Error'))
            .append($('<option>').val('approved').text('Approved'))
            .append($('<option>').val('rejected').text('Rejected'))
            .val(queueState.status)
            .on('change', function() {
                queueState.status = $(this).val();
                queueState.page = 1;
                loadQueue();
            })
            .appendTo($toolbar);

        var $bulk = $('<div>').addClass('wpnc-bulk-actions').appendTo($app);
        $('<label>')
            .append($('<input>').attr({ type: 'checkbox', id: 'wpnc-select-all' }))
            .append(document.createTextNode(' ' + t('select_all', 'Select All')))
            .appendTo($bulk);
        $('<button>').addClass('button button-primary').attr('id', 'wpnc-bulk-approve').text(t('approve_selected', 'Approve Selected')).appendTo($bulk);
        $('<button>').addClass('button').attr('id', 'wpnc-bulk-reject').text(t('reject_selected', 'Reject Selected')).appendTo($bulk);

        if (!items.length) {
            $('<p>').text(t('no_pending', 'No pending news in the queue.')).appendTo($app);
            renderPagination($app, data);
            bindQueueEvents();
            return;
        }

        var $grid = $('<div>').addClass('wpnc-grid').appendTo($app);
        items.forEach(function(item) {
            renderCard($grid, item);
        });

        renderEditModal($app);
        renderPagination($app, data);
        bindQueueEvents();
    }

    function renderCard($grid, item) {
        var $card = $('<div>').addClass('wpnc-card').attr('id', 'wpnc-item-' + item.id).appendTo($grid);
        $('<div>').addClass('wpnc-card-header')
            .append($('<input>').attr({ type: 'checkbox', class: 'wpnc-item-checkbox' }).val(item.id))
            .appendTo($card);

        if (item.image_url) {
            $('<img>').attr({ src: item.image_url, alt: '' }).appendTo($card);
        } else {
            $('<div>').addClass('wpnc-no-img').text(t('no_image', 'No Image')).appendTo($card);
        }

        var $content = $('<div>').addClass('wpnc-card-content').appendTo($card);
        appendText($content, 'h4', item.title);
        appendText($content, 'p', item.source_name, 'wpnc-source');
        appendText($content, 'p', item.pub_date, 'wpnc-date');

        if (item.tags) {
            appendText($content, 'p', t('tags', 'Tags') + ': ' + item.tags, 'wpnc-tags');
        }
        if (item.error_message) {
            appendText($content, 'p', item.error_message, 'wpnc-error-message');
        }

        var $actions = $('<div>').addClass('wpnc-actions').appendTo($content);
        $('<button>').addClass('button button-primary wpnc-approve').data('id', item.id).text(t('approve', 'Approve')).appendTo($actions);
        $('<button>')
            .addClass('button wpnc-edit')
            .data('item', item)
            .text(t('edit', 'Edit'))
            .appendTo($actions);
        $('<button>').addClass('button wpnc-reject button-link-delete').data('id', item.id).text(t('reject', 'Reject')).appendTo($actions);
    }

    function renderEditModal($app) {
        var $modal = $('<div>').attr('id', 'wpnc-edit-modal').addClass('wpnc-modal').hide();
        var $content = $('<div>').addClass('wpnc-modal-content').appendTo($modal);

        appendText($content, 'h2', t('edit_item', 'Edit News Item'));
        $('<input>').attr({ type: 'hidden', id: 'wpnc-edit-id' }).appendTo($content);
        $('<p>').append($('<input>').attr({ type: 'text', id: 'wpnc-edit-title' }).addClass('large-text')).appendTo($content);
        $('<p>').append($('<textarea>').attr({ id: 'wpnc-edit-desc', rows: 8 }).addClass('large-text')).appendTo($content);
        $('<p>').append($('<input>').attr({ type: 'text', id: 'wpnc-edit-tags', placeholder: t('tags', 'Tags') }).addClass('large-text')).appendTo($content);
        $('<p>')
            .append($('<button>').addClass('button button-primary').attr('id', 'wpnc-save-edit').text(t('save', 'Save')))
            .append(' ')
            .append($('<button>').addClass('button').attr('id', 'wpnc-close-modal').text(t('cancel', 'Cancel')))
            .appendTo($content);

        $modal.appendTo($app);
    }

    function renderPagination($app, data) {
        var totalPages = parseInt(data.total_pages || 1, 10);
        var page = parseInt(data.page || 1, 10);
        var $pager = $('<div>').addClass('wpnc-pagination').appendTo($app);

        $('<button>')
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
            .addClass('button')
            .prop('disabled', page >= totalPages)
            .text(t('next', 'Next'))
            .on('click', function() {
                queueState.page = page + 1;
                loadQueue();
            })
            .appendTo($pager);
    }

    function bindQueueEvents() {
        $('#wpnc-select-all').off('change').on('change', function() {
            $('.wpnc-item-checkbox').prop('checked', $(this).prop('checked'));
        });

        $('.wpnc-approve').off('click').on('click', function() {
            actionOne($(this), 'wpnc_approve_item', t('error_approve', 'Error approving item.'));
        });

        $('.wpnc-reject').off('click').on('click', function() {
            if (!window.confirm(t('confirm_reject', 'Reject selected item(s)?'))) {
                return;
            }
            actionOne($(this), 'wpnc_reject_item', t('error_reject', 'Error rejecting item.'));
        });

        $('.wpnc-edit').off('click').on('click', function() {
            var item = $(this).data('item');
            $('#wpnc-edit-id').val(item.id);
            $('#wpnc-edit-title').val(item.title || '');
            $('#wpnc-edit-desc').val(item.description || '');
            $('#wpnc-edit-tags').val(item.tags || '');
            $('#wpnc-edit-modal').show();
        });

        $('#wpnc-close-modal').off('click').on('click', function() {
            $('#wpnc-edit-modal').hide();
        });

        $('#wpnc-save-edit').off('click').on('click', function() {
            var $button = $(this);
            setBusy($button, true);
            post('wpnc_edit_item', {
                id: $('#wpnc-edit-id').val(),
                title: $('#wpnc-edit-title').val(),
                description: $('#wpnc-edit-desc').val(),
                tags: $('#wpnc-edit-tags').val()
            }).done(function(response) {
                if (response.success) {
                    $('#wpnc-edit-modal').hide();
                    loadQueue();
                } else {
                    window.alert(messageFromResponse(response, t('error_save', 'Error saving changes.')));
                }
            }).always(function() {
                setBusy($button, false);
            });
        });

        $('#wpnc-bulk-approve').off('click').on('click', function() {
            actionBulk($(this), 'wpnc_bulk_approve', t('error_approve', 'Error approving item.'));
        });

        $('#wpnc-bulk-reject').off('click').on('click', function() {
            if (!window.confirm(t('confirm_reject', 'Reject selected item(s)?'))) {
                return;
            }
            actionBulk($(this), 'wpnc_bulk_reject', t('error_reject', 'Error rejecting item.'));
        });
    }

    function actionOne($button, action, fallback) {
        var id = $button.data('id');
        var $card = $('#wpnc-item-' + id);
        setBusy($button, true);
        $card.css('opacity', '0.5');
        post(action, { id: id }).done(function(response) {
            if (response.success) {
                $card.fadeOut(function() {
                    $(this).remove();
                });
            } else {
                window.alert(messageFromResponse(response, fallback));
                $card.css('opacity', '1');
            }
        }).fail(function() {
            window.alert(fallback);
            $card.css('opacity', '1');
        }).always(function() {
            setBusy($button, false);
        });
    }

    function actionBulk($button, action, fallback) {
        var ids = $('.wpnc-item-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            return;
        }

        setBusy($button, true);
        post(action, { ids: ids }).done(function(response) {
            if (response.success) {
                loadQueue();
            } else {
                window.alert(messageFromResponse(response, fallback));
            }
        }).fail(function() {
            window.alert(fallback);
        }).always(function() {
            setBusy($button, false);
        });
    }

    function loadStats() {
        var $stats = $('#wpnc-stats-summary');
        if (!$stats.length) {
            return;
        }

        post('wpnc_get_stats').done(function(response) {
            if (!response.success) {
                return;
            }
            $stats.empty();
            var data = response.data || {};
            ['pending', 'approved', 'rejected', 'error'].forEach(function(key) {
                $('<div>').addClass('wpnc-stat')
                    .append($('<strong>').text(data[key] || 0))
                    .append($('<span>').text(key))
                    .appendTo($stats);
            });
        });
    }

    function loadLogs() {
        var $logs = $('#wpnc-logs-app');
        if (!$logs.length) {
            return;
        }

        $logs.empty().append($('<p>').text(t('loading', 'Loading...')));
        post('wpnc_get_logs', { limit: 50 }).done(function(response) {
            $logs.empty();
            if (!response.success || !response.data.logs || !response.data.logs.length) {
                $('<p>').text('No logs yet.').appendTo($logs);
                return;
            }

            var $table = $('<table>').addClass('widefat striped wpnc-logs-table').appendTo($logs);
            $('<thead>').append($('<tr>')
                .append($('<th>').text('Time'))
                .append($('<th>').text('Level'))
                .append($('<th>').text('Source'))
                .append($('<th>').text('Message'))
            ).appendTo($table);
            var $body = $('<tbody>').appendTo($table);
            response.data.logs.forEach(function(log) {
                $('<tr>')
                    .append($('<td>').text(log.created_at || ''))
                    .append($('<td>').text(log.level || ''))
                    .append($('<td>').text(log.source || ''))
                    .append($('<td>').text(log.message || ''))
                    .appendTo($body);
            });
        });
    }

    function bindFetchTool() {
        $('#wpnc-run-fetch').off('click').on('click', function() {
            var $button = $(this);
            var $status = $('#wpnc-fetch-status');
            setBusy($button, true);
            $status.text(t('loading', 'Loading...'));
            post('wpnc_run_fetch').done(function(response) {
                if (!response.success) {
                    $status.text(messageFromResponse(response, 'Fetch failed.'));
                    return;
                }
                var data = response.data || {};
                $status.text(t('fetch_done', 'Fetch completed.') + ' Fetched: ' + (data.fetched || 0) + ', queued: ' + (data.queued || 0) + ', published: ' + (data.published || 0) + ', errors: ' + (data.errors || 0));
                loadStats();
                loadLogs();
            }).fail(function() {
                $status.text('Fetch failed.');
            }).always(function() {
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

    loadQueue();
    loadStats();
    loadLogs();
    bindFetchTool();
});
