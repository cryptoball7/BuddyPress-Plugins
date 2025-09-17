(function($){
    $(document).ready(function(){
        $(document).on('click', '.bre-reaction-btn', function(e){
            e.preventDefault();
            var btn = $(this);
            var wrapper = btn.closest('.bre-reactions');
            var activity_id = wrapper.data('activity-id');
            var reaction = btn.data('reaction');

            // Optimistic UI: toggle active class immediately
            var wasActive = btn.hasClass('bre-react-active');
            btn.toggleClass('bre-react-active');
            btn.attr('aria-pressed', !wasActive);

            // send ajax
            $.post(BRE_Reactions.ajax_url, {
                action: 'bre_toggle_reaction',
                nonce: BRE_Reactions.nonce,
                activity_id: activity_id,
                reaction: reaction
            }, function(response){
                if ( response && response.success ) {
                    // update counts for all buttons in this wrapper
                    $.each(BRE_Reactions.reactions, function(key, emoji){
                        var b = wrapper.find('.bre-reaction-btn[data-reaction="'+key+'"]');
                        var count = response.data.counts[key] || 0;
                        var isActive = response.data.counts[key + '_active'];
                        b.find('.bre-count').text(count);
                        if ( isActive ) b.addClass('bre-react-active').attr('aria-pressed', 'true');
                        else b.removeClass('bre-react-active').attr('aria-pressed', 'false');
                    });
                } else {
                    // rollback optimistic UI on error
                    btn.toggleClass('bre-react-active');
                    btn.attr('aria-pressed', wasActive);
                    if ( response && response.data && response.data.message ) {
                        alert(response.data.message);
                    } else {
                        alert('Error toggling reaction.');
                    }
                }
            }).fail(function(){
                // rollback
                btn.toggleClass('bre-react-active');
                btn.attr('aria-pressed', wasActive);
                alert('Error toggling reaction.');
            });
        });

        // Toggle details (for future UX improvement)
        $(document).on('click', '.bre-show-details', function(e){
            e.preventDefault();
            var btn = $(this);
            var wrapper = btn.closest('.bre-reactions');
            var activity_id = wrapper.data('activity-id');

            // Basic details: show a list of users per reaction using AJAX hook (not implemented server side yet)
            // For now toggle aria-expanded and simple title
            var expanded = btn.attr('aria-expanded') === 'true';
            btn.attr('aria-expanded', expanded ? 'false' : 'true');
            btn.text(expanded ? BRE_Reactions.labels.react : 'Hide');
        });
    });
})(jQuery);

