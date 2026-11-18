/**
 * انتخاب‌گر تاریخ جلالی (خورشیدی) سبک و بدون وابستگی
 * روی هر ورودی متنی با ویژگی data-jalali-picker فعال می‌شود و مقدار میلادی معادل
 * را (به‌صورت YYYY-MM-DD) در ورودی مخفیِ مشخص‌شده توسط data-hidden-target قرار می‌دهد.
 */
(function () {
    'use strict';

    var PERSIAN_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEK_DAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var EN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toPersianDigits(str) {
        str = String(str);
        for (var i = 0; i < 10; i++) str = str.split(EN_DIGITS[i]).join(FA_DIGITS[i]);
        return str;
    }

    function div(a, b) { return Math.floor(a / b); }

    function gregorianToJalali(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + g_d_m[gm - 1];
        var jy = -1595 + (33 * div(days, 12053));
        days %= 12053;
        jy += 4 * div(days, 1461);
        days %= 1461;
        if (days > 365) {
            jy += div(days - 1, 365);
            days = (days - 1) % 365;
        }
        var jm, jd;
        if (days < 186) {
            jm = 1 + div(days, 31);
            jd = 1 + (days % 31);
        } else {
            jm = 7 + div(days - 186, 30);
            jd = 1 + ((days - 186) % 30);
        }
        return [jy, jm, jd];
    }

    function jalaliToGregorian(jy, jm, jd) {
        jy += 1595;
        var days = -355668 + (365 * jy) + (div(jy, 33) * 8) + div(((jy % 33) + 3), 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        var gy = 400 * div(days, 146097);
        days %= 146097;
        if (days > 36524) {
            days--;
            gy += 100 * div(days, 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * div(days, 1461);
        days %= 1461;
        if (days > 365) {
            gy += div(days - 1, 365);
            days = (days - 1) % 365;
        }
        var gd = days + 1;
        var sal_a = [0, 31, (((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 1;
        for (var i = 1; i <= 12; i++) {
            var v = sal_a[i];
            if (gd <= v) { gm = i; break; }
            gd -= v;
        }
        return [gy, gm, gd];
    }

    function isLeapJalaliYear(jy) {
        var g = jalaliToGregorian(jy, 12, 30);
        var j2 = gregorianToJalali(g[0], g[1], g[2]);
        return j2[0] === jy && j2[1] === 12 && j2[2] === 30;
    }

    function jalaliMonthLength(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return isLeapJalaliYear(jy) ? 30 : 29;
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function todayJalali() {
        var now = new Date();
        return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }

    function closeAllPopups() {
        var list = document.querySelectorAll('.jdp-popup');
        for (var i = 0; i < list.length; i++) list[i].parentNode.removeChild(list[i]);
    }

    function bindPicker(input) {
        var hiddenId = input.getAttribute('data-hidden-target');
        var hidden = hiddenId ? document.getElementById(hiddenId) : null;
        var clearable = input.getAttribute('data-clearable') === '1';
        var wrap = input.closest('.jdp-wrap') || input.parentElement;
        var view = null;
        var outsideHandler = null;

        function hiddenJalali() {
            if (hidden && hidden.value) {
                var parts = hidden.value.split('-').map(Number);
                if (parts.length === 3 && parts[0]) return gregorianToJalali(parts[0], parts[1], parts[2]);
            }
            return null;
        }

        function setValue(jy, jm, jd) {
            var g = jalaliToGregorian(jy, jm, jd);
            if (hidden) {
                hidden.value = g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
            input.value = toPersianDigits(jy + '/' + pad2(jm) + '/' + pad2(jd));
            teardown();
        }

        function clearValue() {
            if (hidden) {
                hidden.value = '';
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
            input.value = '';
            teardown();
        }

        function teardown() {
            closeAllPopups();
            if (outsideHandler) {
                document.removeEventListener('click', outsideHandler);
                outsideHandler = null;
            }
        }

        function render() {
            closeAllPopups();
            var sel = hiddenJalali();
            if (!view) view = sel ? { jy: sel[0], jm: sel[1] } : (function () { var t = todayJalali(); return { jy: t[0], jm: t[1] }; })();

            var popup = document.createElement('div');
            popup.className = 'jdp-popup';

            var header = document.createElement('div');
            header.className = 'jdp-header';

            function navBtn(label, handler) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'jdp-nav';
                b.textContent = label;
                b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); handler(); render(); });
                return b;
            }

            header.appendChild(navBtn('«', function () { view.jy--; }));
            header.appendChild(navBtn('‹', function () { view.jm--; if (view.jm < 1) { view.jm = 12; view.jy--; } }));

            var title = document.createElement('span');
            title.className = 'jdp-title';
            title.textContent = PERSIAN_MONTHS[view.jm - 1] + ' ' + toPersianDigits(view.jy);
            header.appendChild(title);

            header.appendChild(navBtn('›', function () { view.jm++; if (view.jm > 12) { view.jm = 1; view.jy++; } }));
            header.appendChild(navBtn('»', function () { view.jy++; }));

            popup.appendChild(header);

            var weekRow = document.createElement('div');
            weekRow.className = 'jdp-week';
            WEEK_DAYS.forEach(function (w) {
                var c = document.createElement('span');
                c.textContent = w;
                weekRow.appendChild(c);
            });
            popup.appendChild(weekRow);

            var grid = document.createElement('div');
            grid.className = 'jdp-grid';

            var firstG = jalaliToGregorian(view.jy, view.jm, 1);
            var jsWeekday = new Date(firstG[0], firstG[1] - 1, firstG[2]).getDay();
            var startOffset = (jsWeekday + 1) % 7;

            for (var i = 0; i < startOffset; i++) {
                var empty = document.createElement('span');
                empty.className = 'jdp-day jdp-empty';
                grid.appendChild(empty);
            }

            var monthLen = jalaliMonthLength(view.jy, view.jm);
            var todayJ = todayJalali();

            var _loop = function (day) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'jdp-day';
                if (sel && sel[0] === view.jy && sel[1] === view.jm && sel[2] === day) cell.classList.add('jdp-selected');
                if (todayJ[0] === view.jy && todayJ[1] === view.jm && todayJ[2] === day) cell.classList.add('jdp-today');
                cell.textContent = toPersianDigits(day);
                cell.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    setValue(view.jy, view.jm, day);
                });
                grid.appendChild(cell);
            };
            for (var d = 1; d <= monthLen; d++) _loop(d);

            popup.appendChild(grid);

            var footer = document.createElement('div');
            footer.className = 'jdp-footer';

            var todayBtn = document.createElement('button');
            todayBtn.type = 'button';
            todayBtn.className = 'jdp-btn';
            todayBtn.textContent = 'امروز';
            todayBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var t = todayJalali();
                setValue(t[0], t[1], t[2]);
            });
            footer.appendChild(todayBtn);

            if (clearable) {
                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'jdp-btn jdp-btn-clear';
                clearBtn.textContent = 'پاک کردن';
                clearBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearValue();
                });
                footer.appendChild(clearBtn);
            }

            popup.appendChild(footer);
            popup.addEventListener('click', function (e) { e.stopPropagation(); });
            wrap.appendChild(popup);

            outsideHandler = function (e) {
                if (!popup.contains(e.target) && e.target !== input) teardown();
            };
            setTimeout(function () { document.addEventListener('click', outsideHandler); }, 0);
        }

        input.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            view = null;
            render();
        });
    }

    function init() {
        var inputs = document.querySelectorAll('[data-jalali-picker]');
        for (var i = 0; i < inputs.length; i++) bindPicker(inputs[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.JalaliDatePicker = {
        gregorianToJalali: gregorianToJalali,
        jalaliToGregorian: jalaliToGregorian,
        bind: bindPicker,
        bindAll: function (container) {
            var scope = container || document;
            var inputs = scope.querySelectorAll('[data-jalali-picker]');
            for (var i = 0; i < inputs.length; i++) {
                if (!inputs[i].getAttribute('data-jdp-bound')) {
                    bindPicker(inputs[i]);
                    inputs[i].setAttribute('data-jdp-bound', '1');
                }
            }
        }
    };
})();
