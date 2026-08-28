jQuery(function($) {
    'use strict';

    $('.wpnc-news-container').on('click', '.wpnc-load-more-btn', function() {
        var $button = $(this);
        var $container = $button.closest('.wpnc-news-container');
        var $list = $container.find('.wpnc-news-list').first();
        var page = parseInt($button.attr('data-page'), 10) || 1;
        var limit = parseInt($button.attr('data-limit'), 10) || 10;
        var category = $button.attr('data-category') || '';
        var maxPages = parseInt($button.attr('data-max-pages'), 10) || 1;
        var nextPage = page + 1;

        $button.text(wpnc_frontend_ajax.i18n.loading).prop('disabled', true);

        $.post(wpnc_frontend_ajax.ajax_url, {
            action: 'wpnc_load_more_news',
            nonce: wpnc_frontend_ajax.nonce,
            page: nextPage,
            limit: limit,
            category: category
        }).done(function(response) {
            if (response.success && response.data.html) {
                $list.append(response.data.html);
                $button.attr('data-page', nextPage);

                if (nextPage >= maxPages) {
                    $button.closest('.wpnc-load-more-wrapper').remove();
                } else {
                    $button.text(wpnc_frontend_ajax.i18n.load_more).prop('disabled', false);
                }
            } else {
                $button.text(wpnc_frontend_ajax.i18n.no_more).prop('disabled', true);
            }
        }).fail(function() {
            $button.text(wpnc_frontend_ajax.i18n.load_more).prop('disabled', false);
        });
    });
});
