/**
 * Diyara Admin JS
 * Handles Tabs, Toggles, and UI interactions.
 */
jQuery(document).ready(function ($) {
    'use strict';

    // --- 1. Settings Page Main Tabs ---
    const $mainTabs = $('.diyara-settings-tab');
    const $mainPanels = $('.diyara-settings-panel');

    $mainTabs.on('click', function (e) {
        e.preventDefault();
        const targetId = $(this).attr('href'); // e.g. #diyara-tab-general

        // Toggle Active Class on Tab
        $mainTabs.removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Toggle Active Panel
        $mainPanels.hide().removeClass('is-active');
        $(targetId).fadeIn(200).addClass('is-active');
    });

    // --- 2. AI Provider Sub-Tabs (Vertical) ---
    const $providerBtns = $('.diyara-ai-provider-tab');
    const $providerPanels = $('.diyara-ai-provider-panel');

    if ($providerBtns.length) {
        // Initialize: Show first active or default to first
        if (!$('.diyara-ai-provider-tab.active').length) {
            $providerBtns.first().addClass('active');
            // Assuming the panel ID logic matches the data-provider attribute
            // You might need to add ID to panels in PHP if not present
        }
        
        $providerBtns.on('click', function (e) {
            e.preventDefault();
            if ($(this).attr('disabled')) return;

            const provider = $(this).data('provider');

            // Update Buttons
            $providerBtns.removeClass('active');
            $(this).addClass('active');

            // Update Panels (Logic depends on your PHP structure)
            // This assumes panels have a specific class or ID based on provider
            // For now, let's rely on the PHP structure you have:
            // "do_settings_sections" renders them. You might need to wrap them in divs with data-provider attrs.
            // Since your PHP code does not strictly wrap them in identifiable divs yet, 
            // ensure your PHP 'add_settings_section' callbacks wrap content in <div class="diyara-ai-provider-panel" data-provider="gemini">...
            
            // Fallback if PHP structure isn't perfect yet:
            $('.diyara-ai-provider-panel').hide();
            $('.diyara-ai-provider-panel[data-provider="' + provider + '"]').fadeIn(200);
        });
    }

    // --- 3. Logs Page: Message Toggle ---
    $(document).on('click', '.diyara-log-msg-toggle', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $cell = $btn.closest('.diyara-log-msg-cell');
        const $fullLog = $cell.find('.diyara-log-msg-full');
        
        const showLabel = $btn.data('show-label') || 'Details';
        const hideLabel = $btn.data('hide-label') || 'Hide';

        $fullLog.slideToggle(200, function() {
            // Update button text after toggle complete
            if ($fullLog.is(':visible')) {
                $btn.text(hideLabel);
            } else {
                $btn.text(showLabel);
            }
        });
    });

});