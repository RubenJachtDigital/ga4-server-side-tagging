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

})( jQuery ); 