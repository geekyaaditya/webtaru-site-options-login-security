(function ($) {
	'use strict';

	$(function () {
		/* ── Media frame (per-click instance) ── */
		$(document).on('click', '.wtols-upload-logo', function (event) {
			event.preventDefault();

			var $btn      = $(this);
			var targetId  = $btn.data('target');
			var previewId = $btn.data('preview');

			var frame = wp.media({
				title: wtolsAdmin.chooseLogo,
				button: { text: wtolsAdmin.useLogo },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var preview    = attachment.sizes && attachment.sizes.medium
					? attachment.sizes.medium.url
					: attachment.url;

				$('#' + targetId).val(attachment.id);
				$('#' + previewId).html('<img class="wtols-logo-preview-image" src="' + preview + '" alt="" />');
			});

			frame.open();
		});

		$(document).on('click', '.wtols-remove-logo', function (event) {
			event.preventDefault();

			$('#' + $(this).data('target')).val('');
			$('#' + $(this).data('preview')).text(wtolsAdmin.noLogo);
		});

		/* ── Tabbed admin UI ── */
		var $tabs    = $('.wtols-tab');
		var $panels  = $('.wtols-tab-panel');

		function activateTab(hash) {
			hash = hash || $tabs.first().data('tab');
			$tabs.removeClass('wtols-tab--active');
			$panels.removeClass('wtols-tab-panel--active');
			$tabs.filter('[data-tab="' + hash + '"]').addClass('wtols-tab--active');
			$panels.filter('[data-panel="' + hash + '"]').addClass('wtols-tab-panel--active');
		}

		$tabs.on('click', function (e) {
			e.preventDefault();
			var tab = $(this).data('tab');
			activateTab(tab);
			$('#wtols_active_tab').val(tab);
			if (history.replaceState) {
				history.replaceState(null, '', '#' + tab);
			}
		});

		if ($tabs.length) {
			var activeTab = $('#wtols_active_tab').val();
			var hash = window.location.hash.replace('#', '') || activeTab;
			activateTab(hash || undefined);
		}

		/* ── Accordion UI for Section Rows ── */
		function initAccordions() {
			$('.wtols-tab-panel').each(function() {
				var $panel = $(this);
				var $sections = $panel.find('.wtols-section-row');
				
				// Hide all rows under each section by default, except the first one
				$sections.each(function(index) {
					var $headingRow = $(this);
					var $contentRows = $headingRow.nextUntil('.wtols-section-row, p.submit');
					
					if (index === 0) {
						$headingRow.removeClass('wtols-section-closed');
						$contentRows.show();
					} else {
						$headingRow.addClass('wtols-section-closed');
						$contentRows.hide();
					}
				});
			});
		}

		initAccordions();

		// Ensure accordions are initialized properly when switching tabs
		$tabs.on('click', function() {
			setTimeout(initAccordions, 50);
		});

		$(document).on('click', '.wtols-section-row h2', function() {
			var $headingRow = $(this).closest('.wtols-section-row');
			var $contentRows = $headingRow.nextUntil('.wtols-section-row, p.submit');
			var $panel = $headingRow.closest('.wtols-tab-panel');
			
			// If already open, just close it
			if (!$headingRow.hasClass('wtols-section-closed')) {
				$headingRow.addClass('wtols-section-closed');
				$contentRows.hide();
				return;
			}
			
			// Otherwise, close all other sections in this panel
			$panel.find('.wtols-section-row').not($headingRow).each(function() {
				$(this).addClass('wtols-section-closed');
				$(this).nextUntil('.wtols-section-row, p.submit').hide();
			});

			// And open this one
			$headingRow.removeClass('wtols-section-closed');
			$contentRows.fadeIn(200);
		});

		/* ── Custom social link rows ── */
		$(document).on('click', '.wtols-add-custom-social', function (e) {
			e.preventDefault();
			var idx = $('.wtols-custom-social-row').length;
			var html = '<div class="wtols-custom-social-row wtols-social-row">'
				+ '<input type="text" name="wtols[custom_social_links][' + idx + '][label]" value="" placeholder="' + wtolsAdmin.labelPlaceholder + '" />'
				+ '<input type="text" name="wtols[custom_social_links][' + idx + '][url]" value="" placeholder="' + wtolsAdmin.urlPlaceholder + '" />'
				+ '<input type="text" name="wtols[custom_social_links][' + idx + '][icon]" value="" placeholder="fab fa-globe" />'
				+ '<button type="button" class="button wtols-remove-custom-social">&times;</button>'
				+ '</div>';
			$('.wtols-custom-social-list').append(html);
		});

		$(document).on('click', '.wtols-remove-custom-social', function (e) {
			e.preventDefault();
			$(this).closest('.wtols-custom-social-row').remove();
			/* Re-index names */
			$('.wtols-custom-social-row').each(function (i) {
				$(this).find('input').each(function () {
					var name = $(this).attr('name');
					if (name) {
						$(this).attr('name', name.replace(/\[custom_social_links\]\[\d+\]/, '[custom_social_links][' + i + ']'));
					}
				});
			});
		});

		/* ── Shortcode click-to-copy ── */
		$(document).on('click', '.wtols-shortcode-list code', function () {
			var $code = $(this);
			var text  = $code.text();

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function () {
					showCopied($code);
				});
			} else {
				/* Fallback */
				var $ta = $('<textarea>').val(text).appendTo('body').select();
				document.execCommand('copy');
				$ta.remove();
				showCopied($code);
			}
		});

		function showCopied($el) {
			if ($el.find('.wtols-copied-badge').length) {
				return;
			}
			var $badge = $('<span class="wtols-copied-badge">Copied!</span>');
			$el.append($badge);
			setTimeout(function () {
				$badge.fadeOut(300, function () { $badge.remove(); });
			}, 1200);
		}

		/* ── Business hours — toggle time inputs ── */
		$(document).on('change', '.wtols-hours-enabled', function () {
			var $row   = $(this).closest('.wtols-hours-day-row');
			var active = $(this).is(':checked');
			$row.find('.wtols-hours-time').prop('disabled', !active).toggleClass('wtols-hours-disabled', !active);
		});

		/* Init: disable time inputs for unchecked days */
		$('.wtols-hours-enabled').each(function () {
			if (!$(this).is(':checked')) {
				$(this).closest('.wtols-hours-day-row').find('.wtols-hours-time').prop('disabled', true).addClass('wtols-hours-disabled');
			}
		});

		/* ── Login limiter — AJAX unlock ── */
		$(document).on('click', '.wtols-unlock-ip', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var ip   = $btn.data('ip');
			$btn.prop('disabled', true).text(wtolsAdmin.unlocking || 'Unlocking...');

			$.post(ajaxurl, {
				action:   'wtols_unlock_ip',
				ip:       ip,
				_wpnonce: wtolsAdmin.unlockNonce || ''
			}, function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
				} else {
					$btn.prop('disabled', false).text('Unlock');
				}
			});
		});

		if ($.fn.wpColorPicker) {
			$('.wtols-color-picker').wpColorPicker();
		}

		/* ── CAPTCHA Toggle ── */
		function toggleCaptchaFields() {
			var type = $('#wtols-captcha-type').val();
			if (type === 'none') {
				$('.wtols-captcha-row').hide();
			} else {
				$('.wtols-captcha-row').show();
			}
		}

		$(document).on('change', '#wtols-captcha-type', toggleCaptchaFields);
		toggleCaptchaFields();

		/* ── Auto-hide Admin Notices ── */
		setTimeout(function () {
			$('.wtols-wrap .notice.is-dismissible').fadeOut(500, function () {
				$(this).remove();
			});
		}, 4000);

		/* ── Code Editor Enhancements ── */
		function updateLineNumbers($textarea) {
			var $wrapper = $textarea.closest('.wtols-editor-wrapper');
			var $numbers = $wrapper.find('.wtols-line-numbers');
			var lines = $textarea.val().split('\n').length;
			var html = '';
			for (var i = 1; i <= lines; i++) {
				html += '<span>' + i + '</span>';
			}
			$numbers.html(html);
		}

		$('.wtols-code-textarea').on('input focus', function () {
			updateLineNumbers($(this));
		});

		$('.wtols-code-textarea').on('scroll', function () {
			var $numbers = $(this).closest('.wtols-editor-wrapper').find('.wtols-line-numbers');
			$numbers.scrollTop($(this).scrollTop());
		});

		$('.wtols-code-textarea').on('keydown', function (e) {
			if (e.key === 'Tab') {
				e.preventDefault();
				var start = this.selectionStart;
				var end = this.selectionEnd;
				var value = $(this).val();
				$(this).val(value.substring(0, start) + "\t" + value.substring(end));
				this.selectionStart = this.selectionEnd = start + 1;
			}
		});

		$('.wtols-code-textarea').each(function () {
			updateLineNumbers($(this));
		});

		/* ── Form Validation (CSS/PHP) ── */
		$('.wtols-admin-form').on('submit', function (e) {
			var css = $('#wtols-custom-css').val();
			if (css) {
				// Basic brace balance check
				var openBraces = (css.match(/{/g) || []).length;
				var closeBraces = (css.match(/}/g) || []).length;
				if (openBraces !== closeBraces) {
					if (!confirm('Warning: Your Custom CSS has unbalanced braces { }. This might break your site layout. Continue anyway?')) {
						e.preventDefault();
						return;
					}
				}
			}

			var php = $('#wtols-php-snippets').val();
			if (php) {
				if (php.toLowerCase().includes('<?php')) {
					alert('Error: Please remove the "<?php" tag from your snippets. It is handled automatically by the plugin.');
					e.preventDefault();
					return;
				}
			}
		});

		/* ── Rating Widget Image URL Toggle ── */
		function toggleRatingImageField() {
			var type = $('#wtols-rating-widget-icon-type').val();
			if (type === 'image') {
				$('.wtols-rating-image-url-row').show();
			} else {
				$('.wtols-rating-image-url-row').hide();
			}
		}
		$(document).on('change', '#wtols-rating-widget-icon-type', toggleRatingImageField);
		toggleRatingImageField();
	});
})(jQuery);
