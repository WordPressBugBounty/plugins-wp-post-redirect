/* WP Post Redirect - metabox behaviour. Settings come from wpprAdmin (wp_localize_script). */
(function($){
    $(document).ready(function(){
        const $typeSelect = $('#wppr-redirect-type');
        if (!$typeSelect.length) return;

        const $externalWrp = $('#wppr-external-wrapper');
        const $internalWrp = $('#wppr-internal-wrapper');
        const $searchInput = $('#wppr-search-input');
        const $resultsBox = $('#wppr-search-results');
        const $internalId = $('#wppr-internal-id');
        const $selectedWrp = $('#wppr-selected-content');
        const $selectedTitle = $('#wppr-selected-title');

        $typeSelect.on('change', function(){
            if($(this).val() === 'external'){
                $externalWrp.show();
                $internalWrp.hide();
            } else {
                $externalWrp.hide();
                $internalWrp.show();
            }
        });

        // Warn about URLs the server will reject (mirrors normalize_external_url() in PHP)
        const $urlInput = $('#wppr-redirect-url');
        const $urlWarning = $('#wppr-url-warning');
        function checkUrl(){
            const url = $.trim($urlInput.val());
            const scheme = url.match(/^([a-z][a-z0-9+.-]*):/i);
            const invalid = (scheme && !/^https?$/i.test(scheme[1])) || /^https?:\/{0,2}$/i.test(url);
            $urlWarning.prop('hidden', !invalid);
        }
        $urlInput.on('input', checkUrl);
        checkUrl();

        let timer;
        $searchInput.on('input', function(){
            clearTimeout(timer);
            const q = $(this).val();
            if(q.length < 3) {
                $resultsBox.hide();
                return;
            }

            timer = setTimeout(function(){
                $.ajax({
                    url: wpprAdmin.ajaxUrl,
                    data: {
                        action: 'wppr_search_posts',
                        q: q,
                        exclude: wpprAdmin.postId,
                        nonce: wpprAdmin.nonce
                    },
                    success: function(res){
                        if(res.success && res.data.length > 0){
                            $resultsBox.empty().show();
                            res.data.forEach(function(item){
                                // Built with .attr()/.text(): titles are never parsed as HTML
                                $('<div class="wppr-search-item"></div>')
                                    .attr('data-id', item.id)
                                    .attr('data-title', item.title)
                                    .text(item.title)
                                    .appendTo($resultsBox);
                            });
                        }
                    }
                });
            }, 300);
        });

        $(document).on('click', '.wppr-search-item', function(){
            const id = $(this).data('id');
            const title = $(this).data('title');
            $internalId.val(id);
            $selectedTitle.text(title);
            $selectedWrp.show();
            $resultsBox.hide();
            $searchInput.val('');
        });

        $('#wppr-clear-internal').on('click', function(e){
            e.preventDefault();
            $internalId.val('');
            $selectedWrp.hide();
        });

        $(document).on('click', function(e){
            if(!$(e.target).closest('#wppr-internal-wrapper').length) $resultsBox.hide();
        });
    });
})(jQuery);
