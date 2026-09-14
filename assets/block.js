/**
 * News bulletin block.
 *
 * Plain ES5 with no build step, so the plugin ships as it is written. The
 * editor shows the list the site will actually render - the server draws it
 * through the same code as the shortcode - rather than a mock-up that could
 * drift from it.
 */
(function (blocks, element, components, blockEditor, ServerSideRender) {
    'use strict';

    if (!blocks || !element || !components || !blockEditor || !ServerSideRender) {
        return;
    }

    var el = element.createElement;
    var config = window.wpnc_block || {};
    var labels = config.labels || {};

    function label(key, fallback) {
        return labels[key] || fallback;
    }

    blocks.registerBlockType('boz-news/bulletin', {
        title: label('title', 'News bulletin'),
        description: label('description', 'The latest news this site published, with pictures.'),
        icon: 'megaphone',
        category: 'widgets',
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            limit: { type: 'number', 'default': 10 },
            category: { type: 'string', 'default': '' },
            layout: { type: 'string', 'default': 'list' },
            image: { type: 'boolean', 'default': true },
            excerpt: { type: 'number', 'default': 30 },
            source: { type: 'boolean', 'default': true }
        },

        edit: function (props) {
            var attributes = props.attributes;
            var set = props.setAttributes;

            return el(element.Fragment, null,
                el(blockEditor.InspectorControls, null,
                    el(components.PanelBody, { title: label('settings', 'Bulletin settings'), initialOpen: true },
                        el(components.RangeControl, {
                            label: label('limit', 'Number of items'),
                            min: 1,
                            max: 50,
                            value: attributes.limit,
                            onChange: function (value) { set({ limit: value || 1 }); }
                        }),
                        el(components.SelectControl, {
                            label: label('layout', 'Layout'),
                            value: attributes.layout,
                            options: [
                                { value: 'list', label: label('list', 'List') },
                                { value: 'grid', label: label('grid', 'Grid') }
                            ],
                            onChange: function (value) { set({ layout: value }); }
                        }),
                        el(components.SelectControl, {
                            label: label('category', 'Category'),
                            value: attributes.category,
                            options: config.categories || [{ value: '', label: '' }],
                            onChange: function (value) { set({ category: value }); }
                        }),
                        el(components.ToggleControl, {
                            label: label('image', 'Show pictures'),
                            checked: !!attributes.image,
                            onChange: function (value) { set({ image: value }); }
                        }),
                        el(components.ToggleControl, {
                            label: label('source', 'Show the source'),
                            checked: !!attributes.source,
                            onChange: function (value) { set({ source: value }); }
                        }),
                        el(components.RangeControl, {
                            label: label('excerpt', 'Summary length (words)'),
                            min: 0,
                            max: 100,
                            value: attributes.excerpt,
                            onChange: function (value) { set({ excerpt: value || 0 }); }
                        })
                    )
                ),
                el(ServerSideRender, { block: 'boz-news/bulletin', attributes: attributes })
            );
        },

        // Rendered on the server every time, so nothing is saved into the post.
        save: function () {
            return null;
        }
    });
}(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.components,
    window.wp && window.wp.blockEditor,
    window.wp && window.wp.serverSideRender
));
