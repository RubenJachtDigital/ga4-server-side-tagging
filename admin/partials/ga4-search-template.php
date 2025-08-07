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
    <form method="get" action="" id="ga4-filter-form">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        
        <!-- Hidden date filter fields -->
        <input type="hidden" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>">
        <input type="hidden" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>">
        <input type="hidden" id="hours_filter" name="hours_filter" value="<?php echo esc_attr($hours_filter); ?>">
        
        <div class="ga4-filter-container">
            <!-- Main Filter Row -->
            <div class="ga4-filter-main-row">
                <!-- Search -->
                <div class="ga4-filter-item ga4-filter-search">
                    <label for="search"><?php echo esc_html__('Search:', 'ga4-server-side-tagging'); ?></label>
                    <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php echo esc_attr($search_placeholder); ?>">
                </div>

                <!-- Status Filter -->
                <div class="ga4-filter-item">
                    <label for="filter_status"><?php echo esc_html__('Status:', 'ga4-server-side-tagging'); ?></label>
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
                <div class="ga4-filter-item">
                    <label for="filter_event"><?php echo esc_html__('Event Type:', 'ga4-server-side-tagging'); ?></label>
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

                <!-- Date Filter Button -->
                <div class="ga4-filter-item">
                    <label><?php echo esc_html__('Date Filter:', 'ga4-server-side-tagging'); ?></label>
                    <button type="button" class="button ga4-date-filter-btn" id="ga4-date-filter-btn">
                        📅 <?php echo esc_html__('Date Range', 'ga4-server-side-tagging'); ?>
                        <span class="ga4-date-status" id="ga4-date-status">
                            <?php 
                            if ($date_from || $date_to) {
                                echo '<span class="ga4-filter-active">✓</span>';
                            } elseif ($hours_filter) {
                                $quick_labels = array(
                                    '1' => 'Last Hour',
                                    '6' => 'Last 6h',
                                    '24' => 'Last 24h',
                                    '168' => 'Last 7d',
                                    '720' => 'Last 30d',
                                    'last_2_fridays' => 'Last 2 Fridays'
                                );
                                $label = $quick_labels[$hours_filter] ?? 'Custom';
                                echo '<span class="ga4-filter-active">(' . esc_html($label) . ')</span>';
                            }
                            ?>
                        </span>
                    </button>
                </div>

                <!-- Per Page -->
                <div class="ga4-filter-item">
                    <label for="limit"><?php echo esc_html__('Per Page:', 'ga4-server-side-tagging'); ?></label>
                    <select id="limit" name="limit">
                        <option value="25" <?php selected($limit, 25); ?>>25</option>
                        <option value="50" <?php selected($limit, 50); ?>>50</option>
                        <option value="100" <?php selected($limit, 100); ?>>100</option>
                        <option value="200" <?php selected($limit, 200); ?>>200</option>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="ga4-filter-item ga4-filter-actions">
                    <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Filter', 'ga4-server-side-tagging'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=' . $page_slug); ?>" class="button"><?php echo esc_html__('Clear', 'ga4-server-side-tagging'); ?></a>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Date Filter Modal -->
<div id="ga4-date-filter-modal" class="ga4-date-modal" style="display: none;">
    <div class="ga4-date-modal-content">
        <div class="ga4-date-modal-header">
            <h3><?php echo esc_html__('📅 Date & Time Filtering', 'ga4-server-side-tagging'); ?></h3>
            <span class="ga4-date-modal-close">&times;</span>
        </div>
        <div class="ga4-date-modal-body">
            <!-- Quick Date Filters -->
            <div class="ga4-date-section">
                <h4><?php echo esc_html__('Quick Filters', 'ga4-server-side-tagging'); ?></h4>
                <div class="ga4-quick-filters">
                    <button type="button" class="button ga4-quick-filter" data-hours="1"><?php echo esc_html__('Last Hour', 'ga4-server-side-tagging'); ?></button>
                    <button type="button" class="button ga4-quick-filter" data-hours="6"><?php echo esc_html__('Last 6 Hours', 'ga4-server-side-tagging'); ?></button>
                    <button type="button" class="button ga4-quick-filter" data-hours="24"><?php echo esc_html__('Today', 'ga4-server-side-tagging'); ?></button>
                    <button type="button" class="button ga4-quick-filter" data-hours="168"><?php echo esc_html__('Last 7 Days', 'ga4-server-side-tagging'); ?></button>
                    <button type="button" class="button ga4-quick-filter" data-hours="720"><?php echo esc_html__('Last 30 Days', 'ga4-server-side-tagging'); ?></button>
                    <button type="button" class="button ga4-quick-filter" data-hours="last_2_fridays"><?php echo esc_html__('Last 2 Fridays', 'ga4-server-side-tagging'); ?></button>
                </div>
            </div>
            
            <div class="ga4-date-divider">
                <span><?php echo esc_html__('OR', 'ga4-server-side-tagging'); ?></span>
            </div>
            
            <!-- Custom Date Range -->
            <div class="ga4-date-section">
                <h4><?php echo esc_html__('Custom Date Range', 'ga4-server-side-tagging'); ?></h4>
                <div class="ga4-date-range">
                    <div class="ga4-date-input-group">
                        <label for="modal_date_from"><?php echo esc_html__('From:', 'ga4-server-side-tagging'); ?></label>
                        <input type="datetime-local" id="modal_date_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    <div class="ga4-date-input-group">
                        <label for="modal_date_to"><?php echo esc_html__('To:', 'ga4-server-side-tagging'); ?></label>
                        <input type="datetime-local" id="modal_date_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                </div>
                <div class="ga4-date-presets">
                    <small>
                        <button type="button" class="button-link ga4-preset-btn" data-preset="today"><?php echo esc_html__('Today', 'ga4-server-side-tagging'); ?></button> |
                        <button type="button" class="button-link ga4-preset-btn" data-preset="yesterday"><?php echo esc_html__('Yesterday', 'ga4-server-side-tagging'); ?></button> |
                        <button type="button" class="button-link ga4-preset-btn" data-preset="this_week"><?php echo esc_html__('This Week', 'ga4-server-side-tagging'); ?></button> |
                        <button type="button" class="button-link ga4-preset-btn" data-preset="last_week"><?php echo esc_html__('Last Week', 'ga4-server-side-tagging'); ?></button>
                    </small>
                </div>
            </div>
        </div>
        <div class="ga4-date-modal-footer">
            <button type="button" class="button button-primary ga4-apply-date-filter"><?php echo esc_html__('Apply Filter', 'ga4-server-side-tagging'); ?></button>
            <button type="button" class="button ga4-clear-date-filter"><?php echo esc_html__('Clear Dates', 'ga4-server-side-tagging'); ?></button>
            <button type="button" class="button ga4-cancel-date-filter"><?php echo esc_html__('Cancel', 'ga4-server-side-tagging'); ?></button>
        </div>
    </div>
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
            $has_filters = $filter_status || $search || (!empty($filter_event) && $filter_event !== '') || $date_from || $date_to || $hours_filter;
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

<style>
/* GA4 Filter Styles */
.ga4-filter-container {
    margin-bottom: 15px;
}

.ga4-filter-main-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: end;
}

.ga4-filter-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ga4-filter-item label {
    font-weight: 600;
    font-size: 13px;
    color: #23282d;
    margin: 0;
}

.ga4-filter-search {
    min-width: 250px;
    flex: 1;
}

.ga4-filter-search input {
    width: 100%;
}

.ga4-filter-actions {
    flex-direction: row !important;
    gap: 8px !important;
    align-items: center;
}

.ga4-date-filter-btn {
    position: relative;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}

.ga4-date-status .ga4-filter-active {
    color: #0073aa;
    font-weight: 600;
    margin-left: 5px;
}

/* Date Filter Modal Styles */
.ga4-date-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ga4-date-modal-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    animation: ga4DateModalSlideIn 0.2s ease-out;
}

@keyframes ga4DateModalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.ga4-date-modal-header {
    padding: 20px 25px 15px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 8px 8px 0 0;
}

.ga4-date-modal-header h3 {
    margin: 0;
    color: #23282d;
    font-size: 18px;
}

.ga4-date-modal-close {
    font-size: 24px;
    font-weight: bold;
    color: #666;
    cursor: pointer;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
}

.ga4-date-modal-close:hover {
    background: #e5e5e5;
    color: #333;
}

.ga4-date-modal-body {
    padding: 25px;
}

.ga4-date-section {
    margin-bottom: 25px;
}

.ga4-date-section:last-child {
    margin-bottom: 0;
}

.ga4-date-section h4 {
    margin: 0 0 15px 0;
    color: #23282d;
    font-size: 16px;
    border-bottom: 1px solid #e5e5e5;
    padding-bottom: 8px;
}

.ga4-quick-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.ga4-quick-filter {
    padding: 8px 16px;
    font-size: 14px;
    transition: all 0.2s;
    border-radius: 4px;
}

.ga4-quick-filter:hover {
    background: #0073aa;
    color: white;
    border-color: #0073aa;
}

.ga4-quick-filter.active {
    background: #0073aa;
    color: white;
    border-color: #0073aa;
    font-weight: 600;
}

.ga4-date-divider {
    text-align: center;
    margin: 25px 0;
    position: relative;
    color: #666;
}

.ga4-date-divider:before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
    background: #e5e5e5;
    z-index: 1;
}

.ga4-date-divider span {
    background: white;
    padding: 0 15px;
    position: relative;
    z-index: 2;
    font-weight: 600;
}

.ga4-date-range {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.ga4-date-input-group {
    flex: 1;
}

.ga4-date-input-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #23282d;
}

.ga4-date-input-group input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.ga4-date-presets {
    text-align: center;
}

.ga4-preset-btn {
    color: #0073aa;
    text-decoration: none;
    font-size: 13px;
    padding: 2px 4px;
    margin: 0 2px;
    cursor: pointer;
    border: none;
    background: none;
}

.ga4-preset-btn:hover {
    text-decoration: underline;
    color: #005a87;
}

.ga4-date-modal-footer {
    padding: 20px 25px;
    border-top: 1px solid #e5e5e5;
    background: #f8f9fa;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    border-radius: 0 0 8px 8px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .ga4-filter-main-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .ga4-filter-item {
        width: 100%;
    }
    
    .ga4-filter-actions {
        flex-direction: row !important;
        justify-content: center;
        margin-top: 15px;
    }
    
    .ga4-date-range {
        flex-direction: column;
        gap: 15px;
    }
    
    .ga4-date-modal-content {
        width: 95%;
        margin: 20px;
    }
    
    .ga4-quick-filters {
        justify-content: center;
    }
    
    .ga4-date-modal-footer {
        flex-wrap: wrap;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .ga4-quick-filters {
        flex-direction: column;
    }
    
    .ga4-quick-filter {
        width: 100%;
        text-align: center;
    }
}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var dateFromInput = $('#date_from');
    var dateToInput = $('#date_to');
    var hoursFilterInput = $('#hours_filter');
    var modal = $('#ga4-date-filter-modal');
    var modalDateFrom = $('#modal_date_from');
    var modalDateTo = $('#modal_date_to');
    var dateStatus = $('#ga4-date-status');
    
    // Update date status display
    function updateDateStatus() {
        var from = dateFromInput.val();
        var to = dateToInput.val();
        var hours = hoursFilterInput.val();
        var html = '';
        
        if (from || to) {
            html = '<span class="ga4-filter-active">✓ Custom Range</span>';
        } else if (hours) {
            var labels = {
                '1': 'Last Hour',
                '6': 'Last 6h',
                '24': 'Last 24h',
                '168': 'Last 7d',
                '720': 'Last 30d',
                'last_2_fridays': 'Last 2 Fridays'
            };
            var label = labels[hours] || 'Custom';
            html = '<span class="ga4-filter-active">(' + label + ')</span>';
        }
        
        dateStatus.html(html);
        
        // Update active state of quick filter buttons
        $('.ga4-quick-filter').removeClass('active');
        if (hours) {
            $('.ga4-quick-filter[data-hours="' + hours + '"]').addClass('active');
        }
    }
    
    // Open date filter modal
    $('#ga4-date-filter-btn').on('click', function(e) {
        e.preventDefault();
        
        // Sync modal inputs with current values
        modalDateFrom.val(dateFromInput.val());
        modalDateTo.val(dateToInput.val());
        
        updateDateStatus();
        modal.show();
    });
    
    // Close modal
    $('.ga4-date-modal-close, .ga4-cancel-date-filter').on('click', function() {
        modal.hide();
    });
    
    // Close modal when clicking outside
    modal.on('click', function(e) {
        if (e.target.id === 'ga4-date-filter-modal') {
            modal.hide();
        }
    });
    
    // Quick filter buttons
    $('.ga4-quick-filter').on('click', function() {
        var hours = $(this).data('hours');
        
        // Clear date inputs and set hours filter
        modalDateFrom.val('');
        modalDateTo.val('');
        hoursFilterInput.val(hours);
        
        updateDateStatus();
        
        // Auto-apply quick filters
        $('#ga4-filter-form').submit();
        modal.hide();
    });
    
    // Date preset buttons
    $('.ga4-preset-btn').on('click', function() {
        var preset = $(this).data('preset');
        var now = new Date();
        var from, to;
        
        switch (preset) {
            case 'today':
                from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                to = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
                break;
            case 'yesterday':
                from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
                to = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1, 23, 59, 59);
                break;
            case 'this_week':
                var dayOfWeek = now.getDay();
                var diff = now.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1); // Monday as first day
                from = new Date(now.setDate(diff));
                from.setHours(0, 0, 0, 0);
                to = new Date();
                break;
            case 'last_week':
                var lastWeek = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                var dayOfWeek = lastWeek.getDay();
                var diff = lastWeek.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
                from = new Date(lastWeek.setDate(diff));
                from.setHours(0, 0, 0, 0);
                to = new Date(from.getTime() + 6 * 24 * 60 * 60 * 1000);
                to.setHours(23, 59, 59);
                break;
        }
        
        if (from && to) {
            modalDateFrom.val(from.toISOString().slice(0, 16));
            modalDateTo.val(to.toISOString().slice(0, 16));
            
            // Clear hours filter when using custom dates
            hoursFilterInput.val('');
        }
    });
    
    // Apply date filter
    $('.ga4-apply-date-filter').on('click', function() {
        // Update hidden form fields
        dateFromInput.val(modalDateFrom.val());
        dateToInput.val(modalDateTo.val());
        
        // Clear hours filter if custom dates are set
        if (modalDateFrom.val() || modalDateTo.val()) {
            hoursFilterInput.val('');
        }
        
        updateDateStatus();
        modal.hide();
        
        // Submit form
        $('#ga4-filter-form').submit();
    });
    
    // Clear date filter
    $('.ga4-clear-date-filter').on('click', function() {
        modalDateFrom.val('');
        modalDateTo.val('');
        dateFromInput.val('');
        dateToInput.val('');
        hoursFilterInput.val('');
        
        updateDateStatus();
        modal.hide();
        
        // Submit form to clear filters
        $('#ga4-filter-form').submit();
    });
    
    // Auto-set end date when start date is selected in modal
    modalDateFrom.on('change', function() {
        var fromDate = $(this).val();
        if (fromDate && !modalDateTo.val()) {
            var date = new Date(fromDate);
            date.setHours(date.getHours() + 24);
            modalDateTo.val(date.toISOString().slice(0, 16));
        }
        
        // Clear hours filter when using custom dates
        hoursFilterInput.val('');
        updateDateStatus();
    });
    
    modalDateTo.on('change', function() {
        // Clear hours filter when using custom dates
        hoursFilterInput.val('');
        updateDateStatus();
    });
    
    // Validate date range
    function validateDateRange() {
        var from = modalDateFrom.val();
        var to = modalDateTo.val();
        
        if (from && to && new Date(from) > new Date(to)) {
            modalDateTo[0].setCustomValidity('End date must be after start date');
        } else {
            modalDateTo[0].setCustomValidity('');
        }
    }
    
    modalDateFrom.add(modalDateTo).on('change', validateDateRange);
    
    // Initialize date status
    updateDateStatus();
});
</script>