/**
 * Admin JavaScript for GA4 Server-Side Tagging
 *
 * @since      1.0.0
 */

(function( $ ) {
    'use strict';

    $(document).ready(function() {
        // Server-side tagging is always enabled - Cloudflare Worker URL is always visible

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

        // A/B Testing functionality
        initializeABTesting();
        
        // Click Tracking functionality
        initializeClickTracking();
    });

    // A/B Testing Functions
    function initializeABTesting() {
        // Toggle A/B testing configuration
        $('#ga4_ab_tests_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#ab_testing_config').show();
            } else {
                $('#ab_testing_config').hide();
            }
        }).trigger('change');

        // Add new A/B test
        $('#add_ab_test').on('click', function() {
            addABTest();
        });

        // Remove A/B test
        $(document).on('click', '.remove-ab-test', function() {
            $(this).closest('.ab-test-item').remove();
            updateABTestsConfig();
        });

        // Update hidden field when form inputs change
        $(document).on('input change', 'input[name^="ab_test_"]', function() {
            updateABTestsConfig();
        });

        // Initialize with existing tests
        updateABTestsConfig();

        // Handle form submission - make sure we update config before submit
        $('form').on('submit', function(e) {
            updateABTestsConfig();
            updateClickTracksConfig();
            // Small delay to ensure the field is updated
            return true;
        });
    }

    function addABTest() {
        var index = $('.ab-test-item').length;
        var template = `
            <div class="ab-test-item" data-index="${index}">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Test Name</label>
                        </th>
                        <td>
                            <input type="text" name="ab_test_name[]" value="" 
                                   placeholder="e.g., Button Color Test" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Variant A CSS Class</label>
                        </th>
                        <td>
                            <input type="text" name="ab_test_class_a[]" value="" 
                                   placeholder="e.g., .button-red" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Variant B CSS Class</label>
                        </th>
                        <td>
                            <input type="text" name="ab_test_class_b[]" value="" 
                                   placeholder="e.g., .button-blue" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Enabled</label>
                        </th>
                        <td>
                            <input type="checkbox" name="ab_test_enabled[]" value="1" checked />
                            <span>Test is active</span>
                        </td>
                    </tr>
                </table>
                <button type="button" class="button remove-ab-test">Remove Test</button>
                <hr>
            </div>
        `;
        
        $('#ab_tests_container').append(template);
        updateABTestsConfig();
    }

    function updateABTestsConfig() {
        var tests = [];
        
        $('.ab-test-item').each(function() {
            var $item = $(this);
            var name = $item.find('input[name="ab_test_name[]"]').val();
            var classA = $item.find('input[name="ab_test_class_a[]"]').val();
            var classB = $item.find('input[name="ab_test_class_b[]"]').val();
            var enabled = $item.find('input[name="ab_test_enabled[]"]').is(':checked');
            
            if (name && classA && classB) {
                tests.push({
                    name: name,
                    class_a: classA,
                    class_b: classB,
                    enabled: enabled
                });
            }
        });
        
        var configJson = JSON.stringify(tests);
        $('#ga4_ab_tests_config').val(configJson);
        
    }

    // Click Tracking Functions
    function initializeClickTracking() {
        // Toggle Click tracking configuration
        $('#ga4_click_tracks_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#click_tracking_config').show();
            } else {
                $('#click_tracking_config').hide();
            }
        }).trigger('change');

        // Add new Click track
        $('#add_click_track').on('click', function() {
            addClickTrack();
        });

        // Remove Click track
        $(document).on('click', '.remove-click-track', function() {
            $(this).closest('.click-track-item').remove();
            updateClickTracksConfig();
        });

        // Update hidden field when form inputs change
        $(document).on('input change', 'input[name^="click_track_"]', function() {
            updateClickTracksConfig();
        });

        // Initialize with existing tracks
        updateClickTracksConfig();
    }

    function addClickTrack() {
        var index = $('.click-track-item').length;
        var template = `
            <div class="click-track-item" data-index="${index}">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Event Name</label>
                        </th>
                        <td>
                            <input type="text" name="click_track_name[]" value="" 
                                   placeholder="e.g., download_pdf, cta_click" class="regular-text" />
                            <p class="description">This becomes the GA4 event name (automatically sanitized)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>CSS Selector</label>
                        </th>
                        <td>
                            <input type="text" name="click_track_selector[]" value="" 
                                   placeholder="e.g., .download-btn, #cta-button, .track-click" class="regular-text" />
                            <p class="description">CSS selector for elements to track (class, ID, or any valid CSS selector)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Enabled</label>
                        </th>
                        <td>
                            <input type="checkbox" name="click_track_enabled[]" value="1" checked />
                            <span>Track is active</span>
                        </td>
                    </tr>
                </table>
                <button type="button" class="button remove-click-track">Remove Track</button>
                <hr>
            </div>
        `;
        
        $('#click_tracks_container').append(template);
        updateClickTracksConfig();
    }

    function updateClickTracksConfig() {
        var tracks = [];
        
        $('.click-track-item').each(function() {
            var $item = $(this);
            var name = $item.find('input[name="click_track_name[]"]').val();
            var selector = $item.find('input[name="click_track_selector[]"]').val();
            var enabled = $item.find('input[name="click_track_enabled[]"]').is(':checked');
            
            if (name && selector) {
                tracks.push({
                    name: name,
                    selector: selector,
                    enabled: enabled
                });
            }
        });
        
        var configJson = JSON.stringify(tracks);
        $('#ga4_click_tracks_config').val(configJson);
        
        console.log('Updated click tracks config:', configJson);
    }


    // Encryption Key generation
    $(document).on('click', '#generate_encryption_key', function(e) {
        e.preventDefault();
        
        console.log('Generate Encryption Key button clicked'); // Debug log
        
        var $button = $(this);
        var $input = $('#ga4_jwt_encryption_key');
        
        // Check if input field exists
        if ($input.length === 0) {
            console.error('Encryption key input field not found!');
            alert('Error: Could not find encryption key input field. Please refresh the page and try again.');
            return;
        }
        
        // Disable button to prevent multiple clicks
        $button.prop('disabled', true).text('Generating...');
        
        // Remove any existing notice
        $('#encryption-key-generated-notice').remove();
        
        // Try AJAX approach first (saves to database)
        if (typeof ga4AdminAjax !== 'undefined') {
            console.log('Using AJAX approach for encryption key'); // Debug log
            
            $.ajax({
                url: ga4AdminAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ga4_generate_encryption_key',
                    nonce: ga4AdminAjax.nonce
                },
                success: function(response) {
                    console.log('AJAX response:', response); // Debug log
                    
                    if (response.success) {
                        $input.val(response.data.encryption_key);
                        showEncryptionSuccessNotice($button, 'New encryption key generated and saved! Update your Cloudflare Worker with this key.');
                    } else {
                        console.error('AJAX error:', response.data.message);
                        // Fallback to client-side generation
                        generateClientSideEncryptionKey($input, $button);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX request failed:', error);
                    // Fallback to client-side generation
                    generateClientSideEncryptionKey($input, $button);
                },
                complete: function() {
                    $button.prop('disabled', false).text('Generate New Encryption Key');
                }
            });
        } else {
            // Fallback to client-side generation
            generateClientSideEncryptionKey($input, $button);
            $button.prop('disabled', false).text('Generate New Encryption Key');
        }
    });
    
    // Client-side encryption key generation function
    function generateClientSideEncryptionKey($input, $button) {
        try {
            console.log('Using client-side generation for encryption key'); // Debug log
            
            // Generate a random 64-character hex encryption key (256-bit)
            var chars = '0123456789abcdef';
            var encryptionKey = '';
            for (var i = 0; i < 64; i++) {
                encryptionKey += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            console.log('Generated Encryption Key (client-side):', encryptionKey); // Debug log
            
            $input.val(encryptionKey);
            showEncryptionSuccessNotice($button, 'New encryption key generated! Remember to save settings and update your Cloudflare Worker.');
            
        } catch (error) {
            console.error('Error generating encryption key:', error);
            showEncryptionErrorNotice($button, 'Error generating encryption key: ' + error.message);
        }
    }
    
    // Show encryption success notice
    function showEncryptionSuccessNotice($button, message) {
        var notice = '<div id="encryption-key-generated-notice" class="notice notice-success inline" style="margin-left: 10px; padding: 5px 10px; display: inline-block;">' +
            '<p style="margin: 0;"><strong>✓ ' + message + '</strong></p>' +
            '</div>';
        
        $button.after(notice);
        
        // Remove the notice after 8 seconds
        setTimeout(function() {
            $('#encryption-key-generated-notice').fadeOut(500, function() {
                $(this).remove();
            });
        }, 8000);
    }
    
    // Show encryption error notice
    function showEncryptionErrorNotice($button, message) {
        var notice = '<div id="encryption-key-generated-notice" class="notice notice-error inline" style="margin-left: 10px; padding: 5px 10px; display: inline-block;">' +
            '<p style="margin: 0;"><strong>✗ ' + message + '</strong></p>' +
            '</div>';
        
        $button.after(notice);
        
        // Remove the notice after 10 seconds
        setTimeout(function() {
            $('#encryption-key-generated-notice').fadeOut(500, function() {
                $(this).remove();
            });
        }, 10000);
    }

})( jQuery ); 