/**
 * Admin scripts for Membership Discount Budget plugin.
 */
(function($) {
    'use strict';

    // Initialize admin scripts on document ready
    $(document).ready(function() {
        // Edit budget modal
        $('.mdb-edit-budget').on('click', function(e) {
            e.preventDefault();
            
            var userId = $(this).data('user');
            var currentBudget = $(this).data('budget');
            
            // Create modal if it doesn't exist
            if ($('#mdb-edit-budget-modal').length === 0) {
                var modalHtml = '<div id="mdb-edit-budget-modal" class="mdb-modal">' +
                    '<div class="mdb-modal-content">' +
                    '<span class="mdb-modal-close">&times;</span>' +
                    '<h2 class="mdb-modal-title">' + mdbL10n.edit_budget + '</h2>' +
                    '<form id="mdb-edit-budget-form" method="post">' +
                    '<div class="mdb-modal-form-row">' +
                    '<label for="mdb-budget-amount">' + mdbL10n.budget_amount + '</label>' +
                    '<input type="number" id="mdb-budget-amount" name="mdb_budget_amount" step="0.01" min="0" required>' +
                    '</div>' +
                    '<input type="hidden" name="mdb_user_id" id="mdb-user-id" value="">' +
                    '<input type="hidden" name="mdb_edit_budget" value="1">' +
                    wp.template('wp-nonce') +
                    '<div class="mdb-modal-actions">' +
                    '<button type="button" class="button mdb-modal-cancel">' + mdbL10n.cancel + '</button>' +
                    '<button type="submit" class="button button-primary">' + mdbL10n.save + '</button>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '</div>';
                
                $('body').append(modalHtml);
                
                // Close modal
                $(document).on('click', '.mdb-modal-close, .mdb-modal-cancel', function() {
                    $('#mdb-edit-budget-modal').hide();
                });
                
                // Close on outside click
                $(document).on('click', '.mdb-modal', function(e) {
                    if ($(e.target).hasClass('mdb-modal')) {
                        $(this).hide();
                    }
                });
            }
            
            // Update form values
            $('#mdb-user-id').val(userId);
            $('#mdb-budget-amount').val(currentBudget);
            
            // Update nonce
            $('#mdb-edit-budget-form input[name="_wpnonce"]').val(
                wp.ajax.settings.nonce || ''
            );
            $('#mdb-edit-budget-form input[name="_wpnonce"]').attr(
                'id', '_wpnonce-mdb-edit-budget-' + userId
            );
            $('#mdb-edit-budget-form input[name="_wpnonce"]').attr(
                'name', '_wpnonce'
            );
            
            // Show modal
            $('#mdb-edit-budget-modal').show();
        });
        
        // Reset budget action
        $('.mdb-reset-budget').on('click', function(e) {
            e.preventDefault();
            
            var userId = $(this).data('user');
            
            if (confirm(mdbL10n.confirm_reset)) {
                // Submit form to reset budget
                var form = $('<form method="post"></form>');
                form.append('<input type="hidden" name="bulk-reset" value="1">');
                form.append('<input type="hidden" name="budget[]" value="' + userId + '">');
                form.append(wp.template('wp-nonce'));
                $('body').append(form);
                form.submit();
            }
        });
    });

})(jQuery);
