
/*jslint browser: true */
/*globals $: false, php_self:false, html_alert:false */


var sms_url = 'https://smsc5.routotelecom.com/SMSsend';
var mass_delay_ms = 200;
var sms_dry_run = false;


function sms_send(params) {

    var d = $.Deferred();

    if (!params.number || !params.message) {
        return d.reject('cannot send sms with empty number/message').promise();
    }

    // only send messages if log ok
    $
    .post(php_self, $.extend({'sendsms': 1}, params))
    .done(function(data) {
        if (data.status === 'success') {
            d.resolve();
        } else {
            d.reject(data.status);
        }
    })
    .fail(function() {
        d.reject('could not connect to server');
    });

    return d.promise();
}


function sms_bind_single($div) {

    function single_update_controls() {
        var number = $div.find('.number option:selected').data('number')
          , message = $div.find('.message').val()
          , $controls = $div.find('.controls')
          , $content;

      if (/\S/.exec(message) === null) {
          $content = $('<span></span>').text('(will not send empty message)');
      } else {
          $content = $('<a href="#" class="btn btn-primary"></a>').text('send');
          $content.click(function() {
              $content.replaceWith($('<span>sending...</span>'));
              sms_send({
                  number: number,
                  message: message
              }).done(function() {
                  html_alert('sent message to ' + number, 'success');
                  $content.replaceWith(
                      $('<a href="#" class="btn"></a>').text('send again'));
              }).fail(function(why) {
                  html_alert('could not send message : ' + why, 'error');
                  $content.replaceWith(
                      $('<a href="#" class="btn"></a>').text('send again'));
              });
          });
      }
      $controls.empty().append($content);
    }

    $div.find('.message').keyup(function() {
        $div.find('.template option').removeAttr('selected');
        $div.find('.template option:not([data-content])').attr('selected');
        single_update_controls();
    });
    $div.find('.number').change(function() {
        single_update_controls();
    });
    $div.find('.template').change(function() {
        var $template = $(this).find(':selected');
        var content = $template.data('content');
        if (content) {
            $div.find('.message').val(content);
            single_update_controls();
        }
    }).change();
    single_update_controls();
}


function sms_bind_mass($table) {
    var delay = 0, n = 0;
    $table.find('.send-selected').click(function() {
        $table.find('tr:gt(0)').each(function() {

            var $this = $(this);
            var checked = $this.find('input[type="checkbox"]').prop('checked');

            if (!checked) {
                return;
            }

            var number = $this.find('.number option:selected').data('number');
            var message = $this.find('.message').text();
            var patient_id = $this.find('.patient_id').text();
            var title = $this.find('.title').text();

            function do_send() {
                var status = $this.find('.send-status').text('sending...');
                sms_send({
                    number: number,
                    message: message,
                    id_title: patient_id + '=' + title
                }).done(function() {
                    status.text('done.').addClass('text-success');
                }).fail(function(why) {
                    status.text('error : ' + why).addClass('text-error');
                });
            }
            window.setTimeout(do_send, delay);
            delay += mass_delay_ms;
            n++;
        });

        console.log('sending ' + n + ' messages...');

        return false;
    });
}


function sms_button_post($buttons) {
    $buttons.click(function() {
        var href = $(this).attr('href');
        var script = href.substr(0, href.indexOf('?'));
        var params = href.substr(href.indexOf('?') + 1).split('&');

        var i, name, value;
        var $form = $('<form method=POST></form>').attr('action', script);
        for(i = 0; i < params.length; i++) {
            name = params[i].substr(0, params[i].indexOf('='));
            value = decodeURIComponent(params[i].substr(params[i].indexOf('=') + 1));
            $('<input type=hidden>').attr(
                'name', name).attr('value', value).appendTo(
                $form);
        }
        $form.appendTo($('body')).submit();
        return false;
    });
}

$(function() {
    sms_bind_single($('.sms-single'));
    sms_bind_mass($('.sms-mass'));
    sms_button_post($('.sms-send'));
});

