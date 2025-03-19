/**
 * Admin JavaScript for GA4 Server-Side Tagging
 *
 * @since      1.0.0
 */

(function( $ ) {
    'use strict';

    $(document).ready(function() {
        // Toggle server-side tagging options
        $('#ga4_use_server_side').on('change', function() {
            if ($(this).is(':checked')) {
                $('#ga4_cloudflare_worker_url').closest('tr').show();
            } else {
                $('#ga4_cloudflare_worker_url').closest('tr').hide();
            }
        }).trigger('change');

        // Toggle debug mode options
        $('#ga4_server_side_tagging_debug_mode').on('change', function() {
            if ($(this).is(':checked')) {
                // Show a warning about debug mode
                if (!$('#debug-mode-warning').length) {
                    $(this).closest('td').append(
                        '<div id="debug-mode-warning" class="notice notice-warning inline" style="margin-top: 10px; padding: 8px;">' +
                        '<p>Debug mode will log all events and may affect performance. Use only for troubleshooting.</p>' +
                        '</div>'
                    );
                }
            } else {
                $('#debug-mode-warning').remove();
            }
        }).trigger('change');

        // Toggle e-commerce tracking options
        $('#ga4_ecommerce_tracking').on('change', function() {
            // Future e-commerce specific options can be toggled here
        }).trigger('change');

        // Password visibility toggle
        $('<button type="button" class="button button-secondary" id="toggle-api-secret">Show</button>')
            .insertAfter('#ga4_api_secret')
            .css('margin-left', '10px')
            .on('click', function(e) {
                e.preventDefault();
                var $input = $('#ga4_api_secret');
                var type = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
                $(this).text(type === 'password' ? 'Show' : 'Hide');
            });

        // Test connection button
        $('#ga4_test_connection').on('click', function() {
            // Validation before test
            var measurementId = $('#ga4_measurement_id').val();
            var apiSecret = $('#ga4_api_secret').val();
            
            if (!measurementId || !apiSecret) {
                alert('Please enter both Measurement ID and API Secret before testing the connection.');
                return false;
            }
            
            return true;
        });
    });

})( jQuery ); 