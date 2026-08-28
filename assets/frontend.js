jQuery(function($) {
    'use strict';

    var i18n = (window.wpnc_frontend_ajax && wpnc_frontend_ajax.i18n) || {};

    function message($button, key, fallback) {
        var $wrapper = $button.closest('.wpnc-load-more-wrapper');
        $wrapper.find('.wpnc-load-more-message').remove();
        $('<p>')
            .addClass('wpnc-load-more-message')
            .attr({ role: 'status', dir: 'auto' })
            .text(i18n[key] || fallback)
            .appendTo($wrapper);
    }

    $('.wpnc-news-container').on('click', '.wpnc-load-more-btn', function() {
        var $button = $(this);
        var $container = $button.closest('.wpnc-news-container');
        var $list = $container.find('.wpnc-news-list').first();
        var page = parseInt($button.attr('data-page'), 10) || 1;
        var limit = parseInt($button.attr('data-limit'), 10) || 10;
        var category = $button.attr('data-category') || '';
        var maxPages = parseInt($button.attr('data-max-pages'), 10) || 1;
        var nextPage = page + 1;

        $button.closest('.wpnc-load-more-wrapper').find('.wpnc-load-more-message').remove();
        $button.text(i18n.loading || 'Loading...').prop('disabled', true);

        $.ajax({
            url: wpnc_frontend_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wpnc_load_more_news',
                nonce: wpnc_frontend_ajax.nonce,
                page: nextPage,
                limit: limit,
                category: category
            }
        }).done(function(response) {
            if (response && response.success && response.data && response.data.html) {
                $list.append(response.data.html);
                $button.attr('data-page', nextPage);

                if (nextPage >= maxPages) {
                    $button.closest('.wpnc-load-more-wrapper').remove();
                } else {
                    $button.text(i18n.load_more || 'Load More News').prop('disabled', false);
                }
                return;
            }

            // "Nothing left" and "something broke" used to look identical to
            // the reader; only the first should retire the button.
            var code = response && response.data && response.data.code;
            if (code === 'wpnc_no_more_posts') {
                $button.closest('.wpnc-load-more-wrapper').remove();
                $('<p>')
                    .addClass('wpnc-load-more-message')
                    .attr({ role: 'status', dir: 'auto' })
                    .text(i18n.no_more || 'No more news')
                    .insertAfter($list);
                return;
            }

            $button.text(i18n.load_more || 'Load More News').prop('disabled', false);
            message($button, 'error', 'Could not load more news. Please try again.');
        }).fail(function() {
            $button.text(i18n.load_more || 'Load More News').prop('disabled', false);
            message($button, 'error', 'Could not load more news. Please try again.');
        });
    });
});
