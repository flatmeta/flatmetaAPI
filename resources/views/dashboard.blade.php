@extends('layouts.app')
@section('title')
    Dashboard
@stop
@section('content')

<div class="row flex-between-center g-0">
  <div class="col-auto">
    
  </div>
  <div class="col-auto mb-3">
      <select id="DateFilter" class="form-select form-select-sm audience-select-menu" >
        <option value="" >Today</option>
        <option value="">Yesterday</option>
        <option value="">This Week</option>
        <option value="">Last Week</option>
        <option value="">This Month</option>
        <option value="">Last Month</option>
        <option value="">This Year</option>
        <option value="">Last Year</option>
      </select>
      <div id="" class="input-group input-group-sm mt-3 ">
        <input class="form-control datetimepicker" id="timepicker3" type="text" placeholder="y-m-d to y-m-d" data-options='{"mode":"range","dateFormat":"y-m-d","disableMobile":true,"defaultDate": ["<?php if(!empty($startdate)){ echo date_format($startdate,"y-m-d"); } ?>", "<?php if(!empty($enddate)){  echo date_format($enddate,"y-m-d");} ?>"]}' />
        <span class="input-group-text"><input type="submit" id="Search" value="Search" /></span>
      </div>
  </div>
</div>

          
          <div class="row g-3 mb-3">
            <div class="col-sm-6 col-md-4">
              <div class="card overflow-hidden" style="min-width: 12rem">
                <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-1.png);">
                </div>
                <div class="card-body position-relative">
                  <h6>Total Users</h6>
                  <div class="display-4 fs-4 mb-2 fw-normal font-sans-serif text-warning" data-countup='{"endValue":{{ $Users }},"decimalPlaces":0,"suffix":""}'>{{ $Users }}</div>
                  <a class="fw-semi-bold fs--1 text-nowrap" href="">See all<span class="fas fa-angle-right ms-1" data-fa-transform="down-1"></span></a>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-md-4">
              <div class="card overflow-hidden" style="min-width: 12rem">
                <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-2.png);">
                </div>
                <div class="card-body position-relative">
                  <h6>Purchased Tiles</h6>
                  <div class="display-4 fs-4 mb-2 fw-normal font-sans-serif text-info" data-countup='{"endValue":{{ $Tiles[0]->total }},"decimalPlaces":0,"suffix":""}'>{{ $Tiles[0]->total }}</div><a class="fw-semi-bold fs--1 text-nowrap" href="{{ route('Subscriptions') }}">All Purchased Tiles<span class="fas fa-angle-right ms-1" data-fa-transform="down-1"></span></a>
                </div>
              </div>
            </div>
          </div>
          
@endsection

@section('script')

<script src="{{asset('assets/js/flatpickr.js')}}"></script>

<script>

/* -------------------------------------------------------------------------- */

/*                                    Utils                                   */

/* -------------------------------------------------------------------------- */
var docReady = function docReady(fn) {
  // see if DOM is already available
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    setTimeout(fn, 1);
  }
};

var resize = function resize(fn) {
  return window.addEventListener('resize', fn);
};

var isIterableArray = function isIterableArray(array) {
  return Array.isArray(array) && !!array.length;
};

var camelize = function camelize(str) {
  var text = str.replace(/[-_\s.]+(.)?/g, function (_, c) {
    return c ? c.toUpperCase() : '';
  });
  return "".concat(text.substr(0, 1).toLowerCase()).concat(text.substr(1));
};

var getData = function getData(el, data) {
  try {
    return JSON.parse(el.dataset[camelize(data)]);
  } catch (e) {
    return el.dataset[camelize(data)];
  }
};
/* ----------------------------- Colors function ---------------------------- */


var hexToRgb = function hexToRgb(hexValue) {
  var hex;
  hexValue.indexOf('#') === 0 ? hex = hexValue.substring(1) : hex = hexValue; // Expand shorthand form (e.g. "03F") to full form (e.g. "0033FF")

  var shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
  var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex.replace(shorthandRegex, function (m, r, g, b) {
    return r + r + g + g + b + b;
  }));
  return result ? [parseInt(result[1], 16), parseInt(result[2], 16), parseInt(result[3], 16)] : null;
};

var rgbaColor = function rgbaColor() {
  var color = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : '#fff';
  var alpha = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 0.5;
  return "rgba(".concat(hexToRgb(color), ", ").concat(alpha, ")");
};
/* --------------------------------- Colors --------------------------------- */


var getColor = function getColor(name) {
  var dom = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : document.documentElement;
  return getComputedStyle(dom).getPropertyValue("--falcon-".concat(name)).trim();
};

var getColors = function getColors(dom) {
  return {
    primary: getColor('primary', dom),
    secondary: getColor('secondary', dom),
    success: getColor('success', dom),
    info: getColor('info', dom),
    warning: getColor('warning', dom),
    danger: getColor('danger', dom),
    light: getColor('light', dom),
    dark: getColor('dark', dom)
  };
};

var getSoftColors = function getSoftColors(dom) {
  return {
    primary: getColor('soft-primary', dom),
    secondary: getColor('soft-secondary', dom),
    success: getColor('soft-success', dom),
    info: getColor('soft-info', dom),
    warning: getColor('soft-warning', dom),
    danger: getColor('soft-danger', dom),
    light: getColor('soft-light', dom),
    dark: getColor('soft-dark', dom)
  };
};

var getGrays = function getGrays(dom) {
  return {
    white: getColor('white', dom),
    100: getColor('100', dom),
    200: getColor('200', dom),
    300: getColor('300', dom),
    400: getColor('400', dom),
    500: getColor('500', dom),
    600: getColor('600', dom),
    700: getColor('700', dom),
    800: getColor('800', dom),
    900: getColor('900', dom),
    1000: getColor('1000', dom),
    1100: getColor('1100', dom),
    black: getColor('black', dom)
  };
};

var hasClass = function hasClass(el, className) {
  !el && false;
  return el.classList.value.includes(className);
};

var addClass = function addClass(el, className) {
  el.classList.add(className);
};

var getOffset = function getOffset(el) {
  var rect = el.getBoundingClientRect();
  var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
  var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
  return {
    top: rect.top + scrollTop,
    left: rect.left + scrollLeft
  };
};

var isScrolledIntoView = function isScrolledIntoView(el) {
  var top = el.offsetTop;
  var left = el.offsetLeft;
  var width = el.offsetWidth;
  var height = el.offsetHeight;

  while (el.offsetParent) {
    // eslint-disable-next-line no-param-reassign
    el = el.offsetParent;
    top += el.offsetTop;
    left += el.offsetLeft;
  }

  return {
    all: top >= window.pageYOffset && left >= window.pageXOffset && top + height <= window.pageYOffset + window.innerHeight && left + width <= window.pageXOffset + window.innerWidth,
    partial: top < window.pageYOffset + window.innerHeight && left < window.pageXOffset + window.innerWidth && top + height > window.pageYOffset && left + width > window.pageXOffset
  };
};

var breakpoints = {
  xs: 0,
  sm: 576,
  md: 768,
  lg: 992,
  xl: 1200,
  xxl: 1540
};

var getBreakpoint = function getBreakpoint(el) {
  var classes = el && el.classList.value;
  var breakpoint;

  if (classes) {
    breakpoint = breakpoints[classes.split(' ').filter(function (cls) {
      return cls.includes('navbar-expand-');
    }).pop().split('-').pop()];
  }

  return breakpoint;
};
/* --------------------------------- Cookie --------------------------------- */


var setCookie = function setCookie(name, value, expire) {
  var expires = new Date();
  expires.setTime(expires.getTime() + expire);
  document.cookie = "".concat(name, "=").concat(value, ";expires=").concat(expires.toUTCString());
};

var getCookie = function getCookie(name) {
  var keyValue = document.cookie.match("(^|;) ?".concat(name, "=([^;]*)(;|$)"));
  return keyValue ? keyValue[2] : keyValue;
};

var settings = {
  tinymce: {
    theme: 'oxide'
  },
  chart: {
    borderColor: 'rgba(255, 255, 255, 0.8)'
  }
};
/* -------------------------- Chart Initialization -------------------------- */

var newChart = function newChart(chart, config) {
  var ctx = chart.getContext('2d');
  return new window.Chart(ctx, config);
};
/* ---------------------------------- Store --------------------------------- */


var getItemFromStore = function getItemFromStore(key, defaultValue) {
  var store = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : localStorage;

  try {
    return JSON.parse(store.getItem(key)) || defaultValue;
  } catch (_unused) {
    return store.getItem(key) || defaultValue;
  }
};

var setItemToStore = function setItemToStore(key, payload) {
  var store = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : localStorage;
  return store.setItem(key, payload);
};

var getStoreSpace = function getStoreSpace() {
  var store = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : localStorage;
  return parseFloat((escape(encodeURIComponent(JSON.stringify(store))).length / (1024 * 1024)).toFixed(2));
};
/* get Dates between */


var getDates = function getDates(startDate, endDate) {
  var interval = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 1000 * 60 * 60 * 24;
  var duration = endDate - startDate;
  var steps = duration / interval;
  return Array.from({
    length: steps + 1
  }, function (v, i) {
    return new Date(startDate.valueOf() + interval * i);
  });
};

var getPastDates = function getPastDates(duration) {
  var days;

  switch (duration) {
    case 'week':
      days = 7;
      break;

    case 'month':
      days = 30;
      break;

    case 'year':
      days = 365;
      break;

    default:
      days = duration;
  }

  var date = new Date();
  var endDate = date;
  var startDate = new Date(new Date().setDate(date.getDate() - (days - 1)));
  return getDates(startDate, endDate);
};
/* Get Random Number */


var getRandomNumber = function getRandomNumber(min, max) {
  return Math.floor(Math.random() * (max - min) + min);
};

var utils = {
  docReady: docReady,
  resize: resize,
  isIterableArray: isIterableArray,
  camelize: camelize,
  getData: getData,
  hasClass: hasClass,
  addClass: addClass,
  hexToRgb: hexToRgb,
  rgbaColor: rgbaColor,
  getColor: getColor,
  getColors: getColors,
  getSoftColors: getSoftColors,
  getGrays: getGrays,
  getOffset: getOffset,
  isScrolledIntoView: isScrolledIntoView,
  getBreakpoint: getBreakpoint,
  setCookie: setCookie,
  getCookie: getCookie,
  newChart: newChart,
  settings: settings,
  getItemFromStore: getItemFromStore,
  setItemToStore: setItemToStore,
  getStoreSpace: getStoreSpace,
  getDates: getDates,
  getPastDates: getPastDates,
  getRandomNumber: getRandomNumber
};

var chartJsDefaultTooltip = function chartJsDefaultTooltip() {
  return {
    backgroundColor: utils.getGrays()['100'],
    borderColor: utils.getGrays()['300'],
    borderWidth: 1,
    titleColor: utils.getGrays().black,
    callbacks: {
      labelTextColor: function labelTextColor() {
        return utils.getGrays().black;
      }
    }
  };
};


var chartLine = function chartLine() {
  var line = document.getElementById('Total_Screening');

  var getOptions = function getOptions() {
    return {
      type: 'bar',
      data: {
        labels: [<?php echo ""; ?>],
        datasets: [{
          type: 'line',
          label: 'Completed Screenings',
          borderColor: utils.getColor('primary'),
          borderWidth: 2,
          fill: false,
          data: [<?php echo ""; ?>],
          tension: 0.3
        }]
      },
      options: {
        plugins: {
          tooltip: chartJsDefaultTooltip()
        },
        scales: {
          x: {
            grid: {
              color: utils.rgbaColor(utils.getGrays().black, 0.1)
            }
          },
          y: {
            grid: {
              color: utils.rgbaColor(utils.getGrays().black, 0.1)
            }
          }
        }
      }
    };
  };

  chartJsInit(line, getOptions);
};

var Total_Exercises = function chartLine() {
  var line = document.getElementById('Total_Exercises');

  var getOptions = function getOptions() {
    return {
      type: 'bar',
      data: {
        labels: [<?php echo ""; ?>],
        datasets: [{
          type: 'line',
          label: 'Completed Exercises',
          borderColor: utils.getColor('primary'),
          borderWidth: 2,
          fill: false,
          data: [<?php echo ""; ?>],
          tension: 0.3
        }]
      },
      options: {
        plugins: {
          tooltip: chartJsDefaultTooltip()
        },
        scales: {
          x: {
            grid: {
              color: utils.rgbaColor(utils.getGrays().black, 0.1)
            }
          },
          y: {
            grid: {
              color: utils.rgbaColor(utils.getGrays().black, 0.1)
            }
          }
        }
      }
    };
  };

  chartJsInit(line, getOptions);
};

docReady(chartLine);
docReady(Total_Exercises);

$(document).ready(function () {
      $('#DateFilter').on('change',function() {
          if(this.value != "https://cms.mymotion.ai/Dashboard/custom" || this.value != "http://cms.mymotion.ai/Dashboard/custom"){
            window.location.href=this.value
          }else{
            $('#customBox').show();
          }
        });

        $('#Search').on('click',function() {
          var selecteddate = $('#timepicker3').val().replace(/\//g, '-');
          if(selecteddate == ""){
            $('#timepicker3').focus();
          }else{
            window.location.href="https://cms.mymotion.ai/Dashboard/custom/"+selecteddate
          }

        });

    });


</script>

@stop