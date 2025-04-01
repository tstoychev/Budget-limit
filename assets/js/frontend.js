/**
 * Frontend scripts for Membership Discount Budget plugin.
 */
(function($) {
    'use strict';

    // Initialize frontend scripts on document ready
    $(document).ready(function() {
        // Animate progress bars
        $('.mdb-budget-progress-bar').each(function() {
            var $this = $(this);
            var width = $this.attr('style').replace('width: ', '').replace('%;', '');
            
            $this.css('width', '0%');
            
            setTimeout(function() {
                $this.css('width', width + '%');
            }, 100);
        });
    });

})(jQuery);
