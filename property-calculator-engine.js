/* ملکینو — موتور محاسبه قیمت ملک (جدا از UI) */
(function (root) {
    'use strict';

    var DEFAULT_RATES = {
        ESTEHKLAK_RATE: 0.015,
        VAGHFI_DISCOUNT: 0.20,
        NO_PARKING_DISCOUNT_DEFAULT: 0.09,
        NO_PARKING_DISCOUNT_MIN: 0.08,
        NO_PARKING_DISCOUNT_MAX: 0.10,
        NO_ELEVATOR_RATE: 0.025,
        YARD_RATIO: 1 / 3,
        FULL_RENT_DIVISOR: 8,
        RENT_PER_100M: 3000000,
        RENT_BASE: 100000000
    };

    var DEFAULT_EXTRAS = [
        { id: 'storage', label: 'انباری', enabled: true, mode: 'area_ratio', ratio: 0.5 }
    ];

    function rates() {
        var extra = root.MELKINO_CALC_RATES || {};
        var out = {};
        Object.keys(DEFAULT_RATES).forEach(function (k) {
            var v = extra[k];
            out[k] = (v != null && isFinite(Number(v))) ? Number(v) : DEFAULT_RATES[k];
        });
        if (out.NO_PARKING_DISCOUNT_MIN > out.NO_PARKING_DISCOUNT_MAX) {
            var tmp = out.NO_PARKING_DISCOUNT_MIN;
            out.NO_PARKING_DISCOUNT_MIN = out.NO_PARKING_DISCOUNT_MAX;
            out.NO_PARKING_DISCOUNT_MAX = tmp;
        }
        if (Array.isArray(extra.EXTRAS)) {
            out.EXTRAS = extra.EXTRAS;
        } else {
            out.EXTRAS = DEFAULT_EXTRAS;
        }
        return out;
    }

    function parseNumber(value) {
        if (value == null) return NaN;
        var s = String(value)
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
            .replace(/[٬،,\\s]/g, '')
            .replace(/[^\d.\-]/g, '');
        if (s === '' || s === '-' || s === '.') return NaN;
        var n = Number(s);
        return isFinite(n) ? n : NaN;
    }

    function toFaDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) {
            return '۰۱۲۳۴۵۶۷۸۹'[d];
        });
    }

    function formatGrouped(n, digits) {
        if (n == null || !isFinite(n)) return '';
        var d = digits == null ? 0 : digits;
        var neg = n < 0;
        var abs = Math.abs(n);
        var s;
        if (d > 0) {
            s = abs.toFixed(d);
            s = s.replace(/\.?0+$/, '');
        } else {
            s = String(Math.round(abs));
        }
        var parts = s.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
        s = parts.join('.');
        return (neg ? '−' : '') + toFaDigits(s);
    }

    function formatToman(n, digits) {
        if (n == null || !isFinite(n) || n < 0) return '—';
        return formatGrouped(n, digits) + ' تومان';
    }

    function formatPct(rate, digits) {
        if (!isFinite(rate)) return '—';
        var p = rate * 100;
        var d = digits == null ? 4 : digits;
        var s = p.toFixed(d).replace(/\.?0+$/, '');
        return toFaDigits(s) + '٪';
    }

    var ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    var TEENS = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    var TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    var HUNDREDS = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    var SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون'];

    function threeToWords(num) {
        num = Math.floor(num);
        if (num <= 0) return '';
        var h = Math.floor(num / 100);
        var t = num % 100;
        var parts = [];
        if (h) parts.push(HUNDREDS[h]);
        if (t) {
            if (t < 10) parts.push(ONES[t]);
            else if (t < 20) parts.push(TEENS[t - 10]);
            else {
                var ten = Math.floor(t / 10);
                var one = t % 10;
                parts.push(TENS[ten] + (one ? ' و ' + ONES[one] : ''));
            }
        }
        return parts.join(' و ');
    }

    function numberToWords(n) {
        n = Math.round(Math.abs(Number(n)));
        if (!isFinite(n)) return '';
        if (n === 0) return 'صفر';
        var parts = [];
        var scale = 0;
        while (n > 0 && scale < SCALES.length) {
            var chunk = n % 1000;
            if (chunk) {
                var w = threeToWords(chunk);
                if (SCALES[scale]) w += ' ' + SCALES[scale];
                parts.unshift(w);
            }
            n = Math.floor(n / 1000);
            scale += 1;
        }
        return parts.join(' و ');
    }

    function tomanToWords(n) {
        n = Math.round(Number(n));
        if (!isFinite(n) || n < 0) return '';
        if (n === 0) return 'صفر تومان';
        return numberToWords(n) + ' تومان';
    }

    function finiteOrZero(n) {
        return (isFinite(n) && n > 0) ? n : 0;
    }

    function enabledExtras(R) {
        var list = Array.isArray(R.EXTRAS) ? R.EXTRAS : [];
        return list.filter(function (ex) {
            return ex && ex.enabled !== false && String(ex.label || '').trim() !== '';
        });
    }

    function calculate(input) {
        var R = rates();
        input = input || {};
        var errors = [];

        var newMeter = parseNumber(input.newMeterPrice);
        var area = parseNumber(input.area);
        var age = parseNumber(input.age);
        var floorRaw = String(input.floor == null ? '' : input.floor).trim();
        var floor = parseNumber(input.floor);
        var totalFloors = parseNumber(input.totalFloors);
        var yardArea = parseNumber(input.yardArea);
        var extraRent = parseNumber(input.extraRentMonthly);
        var isWaqf = !!input.isWaqf;
        var hasParking = !!input.hasParking;
        var hasElevator = !!input.hasElevator;
        var hasYard = !!input.hasYard;
        var wantRent = !!input.wantRent;
        var extraInput = input.extras && typeof input.extras === 'object' ? input.extras : {};

        var parkRate = parseNumber(input.noParkingDiscount);
        if (!isFinite(parkRate)) parkRate = R.NO_PARKING_DISCOUNT_DEFAULT;
        if (parkRate < R.NO_PARKING_DISCOUNT_MIN) parkRate = R.NO_PARKING_DISCOUNT_MIN;
        if (parkRate > R.NO_PARKING_DISCOUNT_MAX) parkRate = R.NO_PARKING_DISCOUNT_MAX;

        if (!(area > 0)) errors.push('متراژ باید بیشتر از صفر باشد.');
        if (!(newMeter > 0)) errors.push('قیمت هر متر نوساز باید بیشتر از صفر باشد.');
        if (floorRaw === '' || !isFinite(floor)) errors.push('طبقه را وارد کنید.');
        else if (floor < 0) errors.push('طبقه نمی‌تواند منفی باشد.');
        if (!isFinite(age)) errors.push('سن بنا را وارد کنید.');
        else if (age < 0) errors.push('سن بنا نمی‌تواند منفی باشد.');
        if (isFinite(age) && age * R.ESTEHKLAK_RATE >= 1) {
            errors.push('سن بنا برای این فرمول بیش از حد است.');
        }
        if (isFinite(totalFloors) && totalFloors < 0) errors.push('تعداد طبقات نمی‌تواند منفی باشد.');
        if (newMeter > 1e12 || area > 1e6) errors.push('اعداد واردشده بیش از حد بزرگ هستند.');
        if (hasYard) {
            if (!isFinite(yardArea)) errors.push('متراژ حیاط اختصاصی الزامی است.');
            else if (yardArea < 0) errors.push('متراژ حیاط نمی‌تواند منفی باشد.');
            else if (!(yardArea > 0)) errors.push('متراژ حیاط اختصاصی باید بیشتر از صفر باشد.');
        } else if (isFinite(yardArea) && yardArea < 0) {
            errors.push('متراژ حیاط نمی‌تواند منفی باشد.');
        }

        var extrasCfg = enabledExtras(R);
        extrasCfg.forEach(function (ex) {
            var id = String(ex.id || '');
            var user = extraInput[id] || extraInput[ex.label] || {};
            var has = !!(user.has || user.enabled);
            if (!has) return;
            if ((ex.mode || 'area_ratio') === 'area_ratio') {
                var a = parseNumber(user.area);
                if (!(a > 0)) errors.push('متراژ «' + ex.label + '» را وارد کنید.');
            }
        });

        if (errors.length) {
            return { ok: false, errors: errors, result: null };
        }

        var depRate = age * R.ESTEHKLAK_RATE;
        var meterPrice = newMeter * (1 - depRate);
        if (!isFinite(meterPrice) || meterPrice < 0) {
            return { ok: false, errors: ['قیمت هر متر قابل محاسبه نیست.'], result: null };
        }

        var baseTotal = area * meterPrice;
        var price = baseTotal;

        var waqfCut = 0;
        if (isWaqf) {
            waqfCut = price * R.VAGHFI_DISCOUNT;
            price = price * (1 - R.VAGHFI_DISCOUNT);
        }

        var parkingCut = 0;
        var parkingApplied = 0;
        if (!hasParking) {
            parkingApplied = parkRate;
            parkingCut = price * parkingApplied;
            price = price * (1 - parkingApplied);
        }

        var elevPct = 0;
        var elevCut = 0;
        if (!hasElevator) {
            if (floor > 1) {
                elevPct = (floor - 1) * R.NO_ELEVATOR_RATE;
                elevCut = price * elevPct;
                price = price * (1 - elevPct);
            }
        }

        var yardPerMeter = 0;
        var yardValue = 0;
        if (hasYard) {
            yardPerMeter = meterPrice * R.YARD_RATIO;
            yardValue = yardArea * yardPerMeter;
            price = price + yardValue;
        }

        var extrasOut = [];
        extrasCfg.forEach(function (ex) {
            var id = String(ex.id || '');
            var user = extraInput[id] || extraInput[ex.label] || {};
            var has = !!(user.has || user.enabled);
            var ratio = Number(ex.ratio);
            if (!isFinite(ratio) || ratio < 0) ratio = 0;
            var item = {
                id: id,
                label: ex.label,
                mode: ex.mode || 'area_ratio',
                applied: false,
                has: has,
                ratio: ratio,
                area: 0,
                value: 0
            };
            if (!has) {
                extrasOut.push(item);
                return;
            }
            if (item.mode === 'percent_add') {
                item.value = price * ratio;
                price = price + item.value;
                item.applied = true;
            } else if (item.mode === 'percent_cut') {
                item.value = price * ratio;
                price = price * (1 - ratio);
                item.applied = true;
            } else {
                var a2 = parseNumber(user.area);
                item.area = a2;
                item.value = a2 * meterPrice * ratio;
                price = price + item.value;
                item.applied = true;
            }
            extrasOut.push(item);
        });

        if (!isFinite(price) || price < 0) {
            return { ok: false, errors: ['قیمت نهایی قابل محاسبه نیست.'], result: null };
        }

        var divisor = R.FULL_RENT_DIVISOR > 0 ? R.FULL_RENT_DIVISOR : 8;
        var rentBase = R.RENT_BASE > 0 ? R.RENT_BASE : 100000000;
        var rentPer = R.RENT_PER_100M;
        var fullRent = price / divisor;
        var monthlyFromFull = (fullRent / rentBase) * rentPer;
        var rentToDeposit = null;
        if (wantRent && isFinite(extraRent) && extraRent > 0 && rentPer > 0) {
            rentToDeposit = (extraRent / rentPer) * rentBase;
        }

        function money(n) {
            return isFinite(n) ? Math.round(n) : 0;
        }

        return {
            ok: true,
            errors: [],
            result: {
                newMeterPrice: money(newMeter),
                age: age,
                floor: floor,
                totalFloors: isFinite(totalFloors) ? totalFloors : null,
                area: area,
                depRate: depRate,
                meterPrice: money(meterPrice),
                meterPriceWords: tomanToWords(money(meterPrice)),
                baseTotal: money(baseTotal),
                isWaqf: isWaqf,
                waqfCut: money(waqfCut),
                hasParking: hasParking,
                parkingRate: parkingApplied,
                parkingCut: money(parkingCut),
                hasElevator: hasElevator,
                elevPct: elevPct,
                elevCut: money(elevCut),
                hasYard: hasYard,
                yardArea: hasYard ? yardArea : 0,
                yardPerMeter: money(yardPerMeter),
                yardValue: money(yardValue),
                extras: extrasOut.map(function (x) {
                    return {
                        id: x.id,
                        label: x.label,
                        mode: x.mode,
                        applied: x.applied,
                        has: x.has,
                        ratio: x.ratio,
                        area: x.area,
                        value: money(x.value)
                    };
                }),
                finalPrice: money(price),
                wantRent: wantRent,
                fullRent: money(fullRent),
                monthlyFromFull: money(monthlyFromFull),
                extraRentMonthly: (isFinite(extraRent) && extraRent > 0) ? money(extraRent) : null,
                rentToDeposit: rentToDeposit != null ? money(rentToDeposit) : null,
                rates: R
            }
        };
    }

    function rentFromDeposit(deposit) {
        var R = rates();
        var n = parseNumber(deposit);
        if (!(n > 0) || !isFinite(n)) return null;
        return (n / R.RENT_BASE) * R.RENT_PER_100M;
    }

    function depositFromRent(rent) {
        var R = rates();
        var n = parseNumber(rent);
        if (!(n > 0) || !isFinite(n)) return null;
        return (n / R.RENT_PER_100M) * R.RENT_BASE;
    }

    root.MelkinoPropertyCalc = {
        rates: rates,
        parseNumber: parseNumber,
        toFaDigits: toFaDigits,
        formatGrouped: formatGrouped,
        formatToman: formatToman,
        formatPct: formatPct,
        tomanToWords: tomanToWords,
        numberToWords: numberToWords,
        calculate: calculate,
        rentFromDeposit: rentFromDeposit,
        depositFromRent: depositFromRent,
        finiteOrZero: finiteOrZero
    };
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
