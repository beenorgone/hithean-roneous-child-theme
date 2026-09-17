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

	/**
	 * .goog-te-combo chỉ xuất hiện sau khi TranslateElement dựng xong UI ẩn
	 * của nó, việc này chạy bất đồng bộ ngay cả sau khi script đã "load" —
	 * nên phải chờ thay vì thao tác ngay khi element.js chạy xong.
	 */
	function waitForCombo(callback, attemptsLeft) {
		attemptsLeft = attemptsLeft === undefined ? 40 : attemptsLeft;
		var combo = document.querySelector('.goog-te-combo');
		if (combo) {
			callback(combo);
			return;
		}
		if (attemptsLeft <= 0) {
			return;
		}
		window.setTimeout(function () {
			waitForCombo(callback, attemptsLeft - 1);
		}, 150);
	}

	function loadGoogleTranslateScript(onReady) {
		if (document.getElementById('hithean-google-translate-script')) {
			onReady && waitForCombo(onReady);
			return;
		}

		window.googleTranslateElementInit = function () {
			new google.translate.TranslateElement({
				pageLanguage: 'vi',
				includedLanguages: 'en',
				autoDisplay: false,
				layout: google.translate.TranslateElement.InlineLayout.SIMPLE
			}, 'google_translate_element');
			onReady && waitForCombo(onReady);
		};

		var script = document.createElement('script');
		script.id = 'hithean-google-translate-script';
		script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
		document.body.appendChild(script);
	}

	function updateGroup(group) {
		var active = isEnglishActive() ? 'en' : 'vi';
		group.querySelectorAll('.hithean-lang-switch__option').forEach(function (option) {
			option.setAttribute('aria-pressed', option.getAttribute('data-lang') === active ? 'true' : 'false');
		});
	}

	function switchTo(group, targetLang) {
		var toEnglish = targetLang === 'en';

		if (toEnglish === isEnglishActive()) {
			return;
		}

		if (toEnglish) {
			setCookie(COOKIE_NAME, '/vi/en', 1);
		} else {
			clearCookie(COOKIE_NAME);
		}
		updateGroup(group);

		loadGoogleTranslateScript(function (combo) {
			combo.value = toEnglish ? 'en' : '';
			combo.dispatchEvent(new Event('change'));
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (isEnglishActive()) {
			loadGoogleTranslateScript(function (combo) {
				combo.value = 'en';
				combo.dispatchEvent(new Event('change'));
			});
		}

		document.querySelectorAll('.hithean-lang-switch').forEach(function (group) {
			updateGroup(group);
			group.querySelectorAll('.hithean-lang-switch__option').forEach(function (option) {
				option.addEventListener('click', function () {
					switchTo(group, option.getAttribute('data-lang'));
				});
			});
		});
	});
})();
