(function () {
	'use strict';

	var COOKIE_NAME = 'googtrans';

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : null;
	}

	function setCookie(name, value, days) {
		var date = new Date();
		date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
		document.cookie = name + '=' + value + '; expires=' + date.toUTCString() + '; path=/';
	}

	function clearCookie(name) {
		document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
	}

	function isEnglishActive() {
		var value = getCookie(COOKIE_NAME);
		return !!value && value.indexOf('/en') !== -1;
	}

	function loadGoogleTranslateScript() {
		if (document.getElementById('hithean-google-translate-script')) {
			return;
		}

		window.googleTranslateElementInit = function () {
			new google.translate.TranslateElement({
				pageLanguage: 'vi',
				includedLanguages: 'en',
				autoDisplay: false,
				layout: google.translate.TranslateElement.InlineLayout.SIMPLE
			}, 'google_translate_element');
		};

		var script = document.createElement('script');
		script.id = 'hithean-google-translate-script';
		script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
		document.body.appendChild(script);
	}

	function updateButton(button) {
		var active = isEnglishActive();
		button.textContent = active
			? (button.getAttribute('data-label-vi') || 'Vi')
			: (button.getAttribute('data-label-en') || 'Eng');
		button.setAttribute('aria-pressed', active ? 'true' : 'false');
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (isEnglishActive()) {
			loadGoogleTranslateScript();
		}

		var buttons = document.querySelectorAll('.hithean-lang-switch');
		buttons.forEach(function (button) {
			updateButton(button);
			button.addEventListener('click', function () {
				if (isEnglishActive()) {
					clearCookie(COOKIE_NAME);
				} else {
					setCookie(COOKIE_NAME, '/vi/en', 1);
				}
				window.location.reload();
			});
		});
	});
})();
