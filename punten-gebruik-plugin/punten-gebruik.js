jQuery(document).ready(function($) {
    $('#punten-gebruik-name').select2({
        placeholder: 'Selecteer een naam',
        allowClear: true
    });

    var POINTS_RATE = 0.05;
    var formatEuros = (amount) => new Intl.NumberFormat("be-NL", { style: "currency", currency: "EUR" }).format(amount);
    $('#punten-gebruik-name').on('change', function () {
        $('#punten-gebruik-points').hide();
        $('#punten-gebruik-amount-description').hide();
        $('#punten-gebruik-submit').hide();
        $('#punten-gebruik-records').hide();
        var niss = $(this).val();
        if (niss) {
            $('#loading-spinner').show();
            $.post(punten_gebruik_ajax.ajax_url, {
                action: 'get_available_points',
                niss: niss
            }, function(response) {
                $('#loading-spinner').hide();
                if (response.available !== undefined) {
                    var euros = (response.available * POINTS_RATE);
                    $('#available-points').text(response.available + ' (' + formatEuros(euros)+')');
                    $('#punten-gebruik-amount-input')
                        .attr('max', response.available)
                        .val('')
                        .trigger('input');
                    $('#punten-gebruik-amount-description').data('available', response.available);
                    $('#punten-gebruik-points').show();
                    $('#punten-gebruik-amount-description').css('display', 'flex');
                    $('#punten-gebruik-submit').show();

                    // Display records
                    var tbody = $('#records-table tbody');
                    tbody.empty();
                    if (response.records && response.records.length > 0) {
                        $.each(response.records, function(index, record) {
                            var row = '<tr><td>' + record.datum + '</td><td>' + record.titel + '</td><td>' + record.punten + '</td><td>' + formatEuros(record.bedrag) + '</td></tr>';
                            tbody.append(row);
                        });
                        $('#punten-gebruik-records').show();
                    } else {
                        $('#punten-gebruik-records').hide();
                    }
                }
            });
        } else {
            $('#loading-spinner').hide();
        }
    });

    $('#punten-gebruik-amount-input').on('input', function() {
        var amount = parseFloat($(this).val()) || 0;
        var euros = (amount * POINTS_RATE);
        $('#amount-euros').text(' (' + formatEuros(euros) + ')');
    });

    $('#max-points-btn').on('click', function() {
        var available = $('#punten-gebruik-amount-description').data('available') || 0;
        $('#punten-gebruik-amount-input').val(available).trigger('input');
    });

    if ($('#punten-gebruik-name').val()) {
        $('#punten-gebruik-name').trigger('change');
    }
});