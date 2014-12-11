
/*globals $: false, php_self:false, html_alert:false, window: false,
  saveAs: false, Blob: false */

$(function() {

    var slurp = function() { return true; };
    var d = new Date();
    var add0 = function (x) { return x < 10 ? '0' + x : x; };
    var today = d.getFullYear() + add0(d.getMonth() + 1) + add0(d.getDay());

    function make_csv(values) {
        return $.map(values, function(x) {
            return x.replace(/\n/g, ' ').replace(/,/g, ';');
        }).join(',');
    }

    $('.overview-table').each(function() {
        var $table = $(this);
        $table.find('.topleft').html(
            $('<a href="#" class=btn><i class=icon-download-alt></i> .csv</a>').click(function() {

                var forms = $table.find('tr:first th').map(function() {
                    return this.textContent;
                });
                var data = [];
                $table.find('td[data-list]').each(function(i, td) {
                    $.each($(td).data('list').split(','), function(j, list) {
                        data.push(make_csv([
                            forms[$(td).index() -1],
                            $(td).parent().find('th').text().trim(),
                            list
                        ]));
                        slurp(i, j);
                    });
                });
                data.splice(0, 0, make_csv(['form', 'id', 'comment']));

                saveAs(
                        new Blob([data.join('\n')], {type: "text/csv;charset=utf-8"}),
                        'overview_' + $table.data('name') + '_' + today + '.csv'
                    );
                return false;
            }));
    });
});

