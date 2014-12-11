/*jslint browser: true */
/*global jQuery: false */

// adds zooming & brightness/contrast change capabilities to <img>

(function ($) {

    /*
    function css_filter_matrix(matrix) {
        var xml = "<svg xmlns='http://www.w3.org/2000/svg'><filter id='contrast'><feColorMatrix type='matrix' values='" + ' '.join(matrix) + "'/></filter></svg>";
        return 'url("data:image/svg+xml;utf8,' + xml + '#name")';
    }

    function css_filter_contrast(percent) {
        return css_filter_matrix([
            percent/100, 0, 0, 0, percent/200,
            0, percent/100, 0, 0, percent/200,
            0, 0, percent/100, 0, percent/200,
            0, 0, 0, 1, 0
        ]);
    }

    function css_filter_grayscale(percent) {
        return css_filter_matrix([
            percent/300, percent/300, percent/300, 0, 0,
            percent/300, percent/300, percent/300, 0, 0,
            percent/300, percent/300, percent/300, 0, 0,
            0, 0, 0, 1, 0,
        ]);
    }
    */

    function css_filter_feFunc(slope, intercept) {
        var fe = "<feFuncX type='linear' slope='" + slope + "' intercept='" + intercept + "'/>";
        var fes = fe.replace('X', 'R') + fe.replace('X', 'G') + fe.replace('X', 'B');
        var xml = "<svg xmlns='http://www.w3.org/2000/svg' ><filter id='feFunc'><feComponentTransfer>" + fes + "</feComponentTransfer></filter></svg>";
        return 'url("data:image/svg+xml;utf8,' + xml + '#feFunc")';
    }

    $.fn.cxr = function (command, options) {

        var defaults = {
            $overlay: $('<div />'),
            fx: 1000, fy: 1000, // mouse sensitivity brightness/contrast
            scrollf: 0.0001, // zoom factor / wheel
            bind_keys: true, // whether to bind keypress()
            window_min: 0.02, // when calling window_{narrow,widen,left,right}
            window_step: 0.05, // when calling window_{narrow,widen}
            position_step: 0.025, // when calling window_{left,right}
        };

        function Cxr($image, options) {
            this.$image = $image;
            this.options = options;
            this.FF = navigator.userAgent.toLowerCase().indexOf('firefox') > -1;

            this.image_width = $image.data('width');
            this.image_height = $image.data('height');
            this.$image.css('margin', '450px'); // for scrolling zoomed out images
            $(document).scrollTop(300);
            $(document).scrollLeft(300);

            this.reset();

            this.attach();
        }

        Cxr.prototype.reset = function() {
            this.zoom = 0.8; // that's 80% relative to parent
            this.c = 0.5; // center position of the value window
            this.w = 1.0; // width of the value window
            this.dragging = false;
            this.w0 = this.c0 = this.mx = this.my = null;
            this.angle = 0;
            this.update();
        };

        Cxr.prototype.get_state = function() {
            return {
                zoom: this.zoom,
                c: this.c,
                w: this.w,
                angle: this.angle,
            };
        };

        Cxr.prototype.set_state = function(state) {
            this.zoom = state.zoom;
            this.c = state.c;
            this.w = state.w;
            this.angle = state.angle;
        };

        Cxr.prototype.update = function() {
            this.update_window();
            this.update_zoom();
            this.update_angle();
        };

        Cxr.prototype.reset_window = function() {
            this.c=0.5;
            this.w=1.0;
            this.update_window();
        };

        Cxr.prototype.window_widen = function() {
            this.w = Math.min(1, this.w + this.options.window_step);
            this.c = Math.max(this.w/2, Math.min(1-this.w/2, this.c));
            this.update_window();
        };

        Cxr.prototype.window_narrow = function() {
            this.w = Math.max(this.options.window_min, this.w - this.options.window_step);
            this.update_window();
        };

        Cxr.prototype.window_right = function() {
            this.c = Math.min(1-this.options.window_min/2, this.c + this.options.position_step);
            this.w = Math.max(0, Math.min(2*(1-this.c), 2*this.c, this.w));
            this.update_window();
        };

        Cxr.prototype.window_left = function() {
            this.c = Math.max(this.options.window_min/2, this.c - this.options.position_step);
            this.w = Math.max(0, Math.min(2*(1-this.c), 2*this.c, this.w));
            this.update_window();
        };

        Cxr.prototype.zoom_in = function() {
            this.zoom += 0.1;
            this.update_zoom();
        };

        Cxr.prototype.zoom_out = function() {
            this.zoom -= 0.1;
            this.update_zoom();
        };

        Cxr.prototype.attach = function() {
            this.$image.mousedown($.proxy(this.mousedown, this));
            $(document).mouseup($.proxy(this.mouseup, this));
            this.$image.mousemove($.proxy(this.mousemove, this));
            this.$image.bind('mousewheel DOMMouseScroll', $.proxy(this.wheeled, this));
            if (this.options.bind_keys) {
                $(document).keypress($.proxy(this.keypress, this));
            }
        };

        Cxr.prototype.update_window = function(e) {

            var f, slope, intersect, brightness, contrast;

            if (e) {
                console.log('w0=' + this.w0);
                var c = this.c0 + (e.pageY - this.my) / this.options.fy; // up : decrease center (make image brighter)
                var w = this.w0 - (e.pageX - this.mx) / this.options.fx; // right : narrow window (increase contrast)
                console.log('w=' + w);

                this.w = Math.max(0, Math.min(2*(1-this.c), 2*this.c, w));
                this.c = Math.max(this.w/2, Math.min(1-this.w/2, c));
                console.log('this.w=' + this.w);
            }

            // show value window graphically
            this.options.$overlay.find('.bar .window').css({
                width: Math.floor(100*this.w) + '%',
                left: Math.floor(100*(this.c-this.w/2)) + '%'
            });

            // set filters accordingly
            slope = 1 / this.w;
            intersect = 0.5 - this.c * slope;
            f = css_filter_feFunc(slope, intersect);
            this.$image.css('filter', f);

            contrast = Math.round(100 * slope);
            if (this.c < 0.5) {
                brightness = Math.round(50 / this.c);
            } else {
                brightness = Math.round((1-this.c) * 200);
            }
            f = 'brightness(' + brightness + '%) contrast(' + contrast + '%)';
            this.$image.css('-webkit-filter', f);

            //console.log('slope=' + slope + ' intersect=' + intersect + ' -- contrast=' + contrast + '% brightness=' + brightness + '%');
        };

        Cxr.prototype.update_zoom = function() {
            var l;

            // this.zoom is fraction of parent width
            this.$image.css({width: (100 * this.zoom) + '%'});

            l = Math.round(100 * this.zoom) + '%';
            // displayed zoom is based on image_width if possible
            if (this.image_width) {
                l = Math.round(100 * this.$image.width() / this.image_width) + '%';
            }
            this.options.$overlay.find('.zoom').text(l);
        };

        Cxr.prototype.mousedown = function (e) {
            //console.log(e);
            this.dragging=true;
            this.w0 = this.w;
            this.c0 = this.c;
            this.mx = e.pageX;
            this.my = e.pageY;
            this.update_window(e);
            return false;
        };

        Cxr.prototype.mouseup = function() {
            this.dragging=false;
        };

        Cxr.prototype.mousemove = function(e) {
            if (!this.dragging) { return true; }
            this.update_window(e);
            return false;
        };

        Cxr.prototype.wheeled = function(e) {
            var d = e.originalEvent.wheelDelta || e.originalEvent.detail * -100;

            if (e.shiftKey) {

                if ((d < 0 && this.$image.width() < $(window).width()/2) ||
                    (d > 0 && this.$image.width() > this.image_width)) {
                    return false;
                }

                this.zoom *= (1 + this.options.scrollf * d);
                this.update_zoom();

                return false;
            }
            //?windows horizontal scroll using ALT
        };

        Cxr.prototype.keypress = function(e) {
            var x = String.fromCharCode(e.which);
            if (x === 'R' || x === 'L') {
                this.turn(x === 'R' ? 90 : -90);
            }
            if (x === 'Q' || x === 'W') {
                this['zoom_' + (x === 'Q' ? 'out' : 'in')]();
            }
        };

        Cxr.prototype.turn = function(dangle) {
            this.angle += dangle;
            this.update_angle();
        };

        Cxr.prototype.update_angle = function() {
            this.$image.css({
                '-webkit-transform': 'rotate(' + this.angle + 'deg)',
                '-ms-transform': 'rotate(' + this.angle + 'deg)',
                'transform': 'rotate(' + this.angle + 'deg)',
            });
            this.options.$overlay.find('.angle').text(this.angle % 360);
        };

        Cxr.prototype.turn_right = function() { this.turn(90); };
        Cxr.prototype.turn_left = function() { this.turn(-90); };


        return this.each(function () {

            if (typeof command === 'object') {
                options = command;
                command = 'init';
            }
            options = $.extend(defaults, options);

            var $this = $(this);
            var cxr = $this.data('cxr');
            if (!cxr) {
                cxr = new Cxr($this, options);
                $this.data('cxr', cxr);
            }

            if (command !== undefined && command !== 'init') {
                cxr[command](options);
            }

        });
    };
}(jQuery)); 

