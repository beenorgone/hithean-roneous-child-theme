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

	/**
	 * Đổi ngôn ngữ ngay trên DOM hiện tại, không điều hướng trang.
	 * langCode rỗng ('') = quay về ngôn ngữ gốc (trick chuẩn của widget).
	 */
	function triggerCombo(langCode) {
		waitForCombo(function (combo) {
			combo.value = langCode;
			combo.dispatchEvent(new Event('change'));
		});
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

	function updateButton(button) {
		var active = isEnglishActive();
		button.textContent = active
			? (button.getAttribute('data-label-vi') || 'Vi')
			: (button.getAttribute('data-label-en') || 'Eng');
		button.setAttribute('aria-pressed', active ? 'true' : 'false');
	}

	function switchLanguage(button) {
		var toEnglish = !isEnglishActive();

		if (toEnglish) {
			setCookie(COOKIE_NAME, '/vi/en', 1);
		} else {
			clearCookie(COOKIE_NAME);
		}
		updateButton(button);

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

		var buttons = document.querySelectorAll('.hithean-lang-switch');
		buttons.forEach(function (button) {
			updateButton(button);
			button.addEventListener('click', function () {
				switchLanguage(button);
			});
		});
	});
})();
