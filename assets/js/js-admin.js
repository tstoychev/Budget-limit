/**
 * Membership Discount Budget - Admin Scripts
 */
(function($) {
    'use strict';
    
    // Close modal when clicking outside
    $(window).on('click', function(e) {
        if ($(e.target).is('#edit-budget-modal')) {
            $('#edit-budget-modal').hide();
        }
    });
    
    // Press Escape to close modal
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) { // ESC key
            $('#edit-budget-modal').hide();
        }
    });
    
    // Reports page chart initialization
    if ($('#mdb-budget-chart').length) {
        var ctx = document.getElementById('mdb-budget-chart').getContext('2d');
        
        // Get data from the data attribute
        var chartData = $('#mdb-budget-chart').data('chart');
        
        if (chartData) {
            var myChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [
                        {
                            label: 'Budget Used',
                            data: chartData.used,
                            backgroundColor: 'rgba(0, 115, 170, 0.6)',
                            borderColor: 'rgba(0, 115, 170, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Budget Remaining',
                            data: chartData.remaining,
                            backgroundColor: 'rgba(170, 170, 170, 0.6)',
                            borderColor: 'rgba(170, 170, 170, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: false
                        },
                        x: {
                            stacked: false
                        }
                    }
                }
            });
        }
    }
    
})(jQuery);