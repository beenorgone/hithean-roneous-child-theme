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

	/** Google gắn class này vào <html> khi trang đang ở trạng thái đã dịch. */
	function isPageTranslated() {
		var classes = document.documentElement.classList;
		return classes.contains('translated-ltr') || classes.contains('translated-rtl');
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

	/**
	 * Có 2 bản toggle trên trang (nav cho desktop, fixed cho mobile — xem
	 * language-switcher.php và language-switcher.css); đồng bộ cả 2 mỗi lần
	 * đổi ngôn ngữ dù chỉ 1 bản đang hiện.
	 */
	function updateAllGroups() {
		document.querySelectorAll('.hithean-lang-switch').forEach(updateGroup);
	}

	function switchTo(targetLang) {
		var toEnglish = targetLang === 'en';

		if (toEnglish === isEnglishActive()) {
			return;
		}

		if (toEnglish) {
			setCookie(COOKIE_NAME, '/vi/en', 1);
		} else {
			clearCookie(COOKIE_NAME);
		}
		updateAllGroups();

		loadGoogleTranslateScript(function (combo) {
			combo.value = toEnglish ? 'en' : '';
			combo.dispatchEvent(new Event('change', { bubbles: true }));

			if (!toEnglish) {
				// Widget không có API chính thức để phục hồi bản gốc; sự kiện
				// change giả lập không phải lúc nào cũng được nó xử lý. Script
				// đã load sẵn (không cần chờ mạng) nên 2s là đủ — nếu <html>
				// vẫn còn đánh dấu đã dịch, reload là cách duy nhất chắc chắn
				// đúng để quay lại bản gốc.
				window.setTimeout(function () {
					if (isPageTranslated()) {
						window.location.reload();
					}
				}, 2000);
			}
		});
	}

	/**
	 * Nếu trang không có bản toggle nào render trong nav (header layout nào
	 * đó của theme cha không gọi hook `hithean_top_bar_after` — vd trang chủ
	 * có thể dùng 1 layout header khác 2 layout đã biết), hiện bản fixed góc
	 * trên-phải luôn — kể cả trên desktop — thay vì để trang không có toggle.
	 */
	function ensureToggleVisible() {
		if (document.querySelector('.hithean-lang-switch--inline')) {
			return;
		}
		var fixedGroup = document.querySelector('.hithean-lang-switch--mobile-fixed');
		if (fixedGroup) {
			fixedGroup.classList.add('hithean-lang-switch--force-visible');
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (isEnglishActive()) {
			loadGoogleTranslateScript(function (combo) {
				combo.value = 'en';
				combo.dispatchEvent(new Event('change', { bubbles: true }));
			});
		}

		ensureToggleVisible();
		updateAllGroups();
		document.querySelectorAll('.hithean-lang-switch__option').forEach(function (option) {
			option.addEventListener('click', function () {
				switchTo(option.getAttribute('data-lang'));
			});
		});
	});
})();
