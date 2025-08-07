<?php
/**
 * Reusable search and filter template for GA4 Server-Side Tagging admin pages
 *
 * This template provides consistent search and filter functionality across
 * the Event Monitor and Queue Management pages.
 *
 * @since      3.0.0
 * @package    GA4_Server_Side_Tagging
 * @subpackage GA4_Server_Side_Tagging/admin/partials
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Template variables expected:
 * @var string $page_slug - The current page slug for form action
 * @var string $search - Current search term
 * @var string $filter_status - Current status filter
 * @var string $filter_event - Current event filter (optional, for event monitor)
 * @var int $limit - Current per-page limit
 * @var array $unique_events - Array of unique event names (optional, for event monitor)
 * @var array $status_options - Array of status options for the filter
 * @var string $search_placeholder - Placeholder text for search field
 * @var string $date_from - Start date filter (optional)
 * @var string $date_to - End date filter (optional)
 * @var string $hours_filter - Hours filter (optional)
 */

// Set defaults for optional variables
$filter_event = $filter_event ?? '';
$unique_events = $unique_events ?? array();
$search_placeholder = $search_placeholder ?? 'Search...';
$date_from = $date_from ?? '';
$date_to = $date_to ?? '';
$hours_filter = $hours_filter ?? '';
?>

<!-- Filters Section -->
<div class="ga4-admin-section">
    <h2><?php echo esc_html__('Filters & Search', 'ga4-server-side-tagging'); ?></h2>
    <form method="get" action="">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end; margin-bottom: 15px;">
            <!-- Search -->
            <div>
                <label for="search"><?php echo esc_html__('Search:', 'ga4-server-side-tagging'); ?></label><br>
                <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" 
                       placeholder="<?php echo esc_attr($search_placeholder); ?>" style="width: 250px;">
            </div>

            <!-- Status Filter -->
            <div>
                <label for="filter_status"><?php echo esc_html__('Status:', 'ga4-server-side-tagging'); ?></label><br>
                <select id="filter_status" name="filter_status">
                    <option value=""><?php echo esc_html__('All Statuses', 'ga4-server-side-tagging'); ?></option>
                    <?php foreach ($status_options as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filter_status, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Event Name Filter (only for event monitor) -->
            <?php if (!empty($unique_events)) : ?>
            <div>
                <label for="filter_event"><?php echo esc_html__('Event Type:', 'ga4-server-side-tagging'); ?></label><br>
                <select id="filter_event" name="filter_event">
                    <option value=""><?php echo esc_html__('All Events', 'ga4-server-side-tagging'); ?></option>
                    <?php foreach ($unique_events as $event_name) : ?>
                        <option value="<?php echo esc_attr($event_name); ?>" <?php selected($filter_event, $event_name); ?>>
                            <?php echo esc_html($event_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Date Range Filter -->
            <div>
                <label><?php echo esc_html__('Date Range:', 'ga4-server-side-tagging'); ?></label><br>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <input type="datetime-local" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="width: 160px;" title="<?php echo esc_attr__('Start date and time', 'ga4-server-side-tagging'); ?>">
                    <span style="color: #666;">to</span>
                    <input type="datetime-local" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="width: 160px;" title="<?php echo esc_attr__('End date and time', 'ga4-server-side-tagging'); ?>">
                </div>
            </div>

            <!-- Quick Hours Filter -->
            <div>
                <label for="hours_filter"><?php echo esc_html__('Quick Filter:', 'ga4-server-side-tagging'); ?></label><br>
                <select id="hours_filter" name="hours_filter">
                    <option value=""><?php echo esc_html__('All Time', 'ga4-server-side-tagging'); ?></option>
                    <option value="1" <?php selected($hours_filter, '1'); ?>><?php echo esc_html__('Last Hour', 'ga4-server-side-tagging'); ?></option>
                    <option value="6" <?php selected($hours_filter, '6'); ?>><?php echo esc_html__('Last 6 Hours', 'ga4-server-side-tagging'); ?></option>
                    <option value="24" <?php selected($hours_filter, '24'); ?>><?php echo esc_html__('Last 24 Hours', 'ga4-server-side-tagging'); ?></option>
                    <option value="168" <?php selected($hours_filter, '168'); ?>><?php echo esc_html__('Last 7 Days', 'ga4-server-side-tagging'); ?></option>
                    <option value="720" <?php selected($hours_filter, '720'); ?>><?php echo esc_html__('Last 30 Days', 'ga4-server-side-tagging'); ?></option>
                    <option value="last_2_fridays" <?php selected($hours_filter, 'last_2_fridays'); ?>><?php echo esc_html__('Last 2 Fridays', 'ga4-server-side-tagging'); ?></option>
                </select>
            </div>

            <!-- Per Page -->
            <div>
                <label for="limit"><?php echo esc_html__('Per Page:', 'ga4-server-side-tagging'); ?></label><br>
                <select id="limit" name="limit">
                    <option value="25" <?php selected($limit, 25); ?>>25</option>
                    <option value="50" <?php selected($limit, 50); ?>>50</option>
                    <option value="100" <?php selected($limit, 100); ?>>100</option>
                    <option value="200" <?php selected($limit, 200); ?>>200</option>
                </select>
            </div>

            <!-- Submit -->
            <div>
                <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Filter', 'ga4-server-side-tagging'); ?>">
                <a href="<?php echo admin_url('admin.php?page=' . $page_slug); ?>" class="button"><?php echo esc_html__('Clear', 'ga4-server-side-tagging'); ?></a>
            </div>
        </div>
    </form>
</div>

<!-- Results Info and Pagination -->
<div class="ga4-admin-section">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div>
            <strong><?php echo esc_html__('Results:', 'ga4-server-side-tagging'); ?></strong>
            <?php 
            // Calculate pagination info
            $total_pages = ceil($total_events / $limit);
            $current_page = floor($offset / $limit) + 1;
            
            echo sprintf(
                esc_html__('Showing %d-%d of %s events', 'ga4-server-side-tagging'),
                $offset + 1,
                min($offset + $limit, $total_events),
                number_format($total_events)
            ); ?>
            <?php 
            $has_filters = $filter_status || $search || (!empty($filter_event) && $filter_event !== '');
            if ($has_filters) : ?>
                <span style="color: #666;"><?php echo esc_html__('(filtered)', 'ga4-server-side-tagging'); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
        <div class="tablenav-pages">
            <?php
            $base_url = admin_url('admin.php?page=' . $page_slug);
            $query_args = array_filter(array(
                'filter_status' => $filter_status,
                'filter_event' => $filter_event,
                'search' => $search,
                'limit' => $limit,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'hours_filter' => $hours_filter
            ));
            
            if ($current_page > 1) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => 0)), $base_url)); ?>">
                    <?php echo esc_html__('« First', 'ga4-server-side-tagging'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => ($current_page - 2) * $limit)), $base_url)); ?>">
                    <?php echo esc_html__('‹ Previous', 'ga4-server-side-tagging'); ?>
                </a>
            <?php endif; ?>
            
            <span style="margin: 0 10px;"><?php echo sprintf(esc_html__('Page %d of %d', 'ga4-server-side-tagging'), $current_page, $total_pages); ?></span>
            
            <?php if ($current_page < $total_pages) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => $current_page * $limit)), $base_url)); ?>">
                    <?php echo esc_html__('Next ›', 'ga4-server-side-tagging'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => ($total_pages - 1) * $limit)), $base_url)); ?>">
                    <?php echo esc_html__('Last »', 'ga4-server-side-tagging'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Date filtering functionality
    var dateFromInput = $('#date_from');
    var dateToInput = $('#date_to');
    var hoursFilterSelect = $('#hours_filter');
    
    // Clear date inputs when hours filter is selected
    hoursFilterSelect.on('change', function() {
        if ($(this).val()) {
            dateFromInput.val('');
            dateToInput.val('');
        }
    });
    
    // Clear hours filter when date inputs are used
    dateFromInput.add(dateToInput).on('input change', function() {
        if (dateFromInput.val() || dateToInput.val()) {
            hoursFilterSelect.val('');
        }
    });
    
    // Auto-set end date when start date is selected
    dateFromInput.on('change', function() {
        var fromDate = $(this).val();
        if (fromDate && !dateToInput.val()) {
            // Set end date to 24 hours later
            var date = new Date(fromDate);
            date.setHours(date.getHours() + 24);
            dateToInput.val(date.toISOString().slice(0, 16));
        }
    });
    
    // Validate date range
    function validateDateRange() {
        var from = dateFromInput.val();
        var to = dateToInput.val();
        
        if (from && to && new Date(from) > new Date(to)) {
            dateToInput[0].setCustomValidity('End date must be after start date');
        } else {
            dateToInput[0].setCustomValidity('');
        }
    }
    
    dateFromInput.add(dateToInput).on('change', validateDateRange);
    
    // Quick preset buttons
    function addQuickPresets() {
        var presetContainer = $('<div style="margin-top: 5px; font-size: 12px;"></div>');
        var presets = [
            { label: 'Last 1h', hours: 1 },
            { label: 'Last 6h', hours: 6 },
            { label: 'Today', hours: 24 },
            { label: 'This Week', hours: 168 },
            { label: 'Last 2 Fridays', hours: 'last_2_fridays' }
        ];
        
        $.each(presets, function(index, preset) {
            var button = $('<button type="button" class="button button-small" style="margin-right: 5px; font-size: 10px; padding: 2px 6px;">' + preset.label + '</button>');
            button.on('click', function(e) {
                e.preventDefault();
                hoursFilterSelect.val(preset.hours);
                dateFromInput.val('');
                dateToInput.val('');
            });
            presetContainer.append(button);
        });
        
        // Add clear button
        var clearButton = $('<button type="button" class="button button-small" style="margin-left: 10px; font-size: 10px; padding: 2px 6px; color: #666;">Clear</button>');
        clearButton.on('click', function(e) {
            e.preventDefault();
            dateFromInput.val('');
            dateToInput.val('');
            hoursFilterSelect.val('');
        });
        presetContainer.append(clearButton);
        
        $('#hours_filter').parent().append(presetContainer);
    }
    
    addQuickPresets();
});
</script>