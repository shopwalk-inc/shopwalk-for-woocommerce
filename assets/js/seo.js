/* global jQuery, ShopwalkSeo */
(function ($) {
	'use strict';

	if (typeof ShopwalkSeo === 'undefined') {
		return;
	}

	var $box = $('.shopwalk-seo-box');
	if (!$box.length) {
		return;
	}

	var productId = $box.data('product-id');
	var lastGenerated = null;

	function setStatus(msg, isError) {
		var $s = $box.find('.shopwalk-seo-status');
		$s.text(msg || '').toggleClass('error', !!isError);
	}

	function renderLen(field, str, target) {
		var len = (str || '').length;
		var $el = $box.find('.shopwalk-seo-len[data-target-for="' + field + '"]');
		$el.text(len + ' / ' + target).toggleClass('over', len > target);
	}

	function renderPreview(data) {
		lastGenerated = data || {};
		var $preview = $box.find('.shopwalk-seo-preview');
		$preview.show();

		var title = data.meta_title || '';
		var desc = data.meta_description || '';
		$preview.find('[data-field="meta_title"]').val(title);
		$preview.find('[data-field="meta_description"]').val(desc);
		renderLen('meta_title', title, 60);
		renderLen('meta_description', desc, 155);

		var $altList = $preview.find('.shopwalk-seo-alt-list').empty();
		if (data.image_alts && typeof data.image_alts === 'object') {
			Object.keys(data.image_alts).forEach(function (key) {
				var alt = data.image_alts[key];
				var $li = $('<li/>');
				// key may be id or URL; if URL, show thumbnail
				if (/^https?:\/\//.test(key)) {
					$li.append($('<img/>').attr('src', key));
				}
				$li.append($('<input type="text" />').val(alt).attr('data-alt-key', key));
				$altList.append($li);
			});
		}

		var $check = $preview.find('.shopwalk-seo-checklist-list').empty();
		if (Array.isArray(data.seo_checklist)) {
			data.seo_checklist.forEach(function (item) {
				var $li = $('<li/>').addClass('status-' + (item.status || 'ok')).text(item.item || '');
				if (item.fix) {
					$li.append($('<div/>').css({ color: '#666', fontSize: '11px' }).text(item.fix));
				}
				$check.append($li);
			});
		}
	}

	$box.on('click', '#shopwalk-seo-generate', function () {
		setStatus(ShopwalkSeo.i18n.generating, false);
		$.ajax({
			url: ShopwalkSeo.ajax_url,
			method: 'POST',
			data: {
				action: 'shopwalk_seo_generate',
				nonce: ShopwalkSeo.nonce,
				product_id: productId,
				focus_keyphrase: $('#shopwalk-seo-focus').val() || '',
				fields: ['meta_title', 'meta_description', 'image_alt', 'seo_checklist']
			}
		}).done(function (resp) {
			if (resp && resp.success) {
				renderPreview(resp.data);
				setStatus('', false);
			} else {
				setStatus((resp && resp.data && resp.data.message) || 'Error', true);
			}
		}).fail(function () {
			setStatus('Network error', true);
		});
	});

	$box.on('input', '[data-field="meta_title"]', function () { renderLen('meta_title', $(this).val(), 60); });
	$box.on('input', '[data-field="meta_description"]', function () { renderLen('meta_description', $(this).val(), 155); });

	$box.on('click', '#shopwalk-seo-reject', function () {
		lastGenerated = null;
		$box.find('.shopwalk-seo-preview').hide();
		setStatus('', false);
	});

	$box.on('click', '#shopwalk-seo-apply', function () {
		if (!lastGenerated) {
			setStatus('Nothing to apply', true);
			return;
		}

		// Merge any in-place edits back in.
		var edited = $.extend({}, lastGenerated);
		edited.meta_title = $box.find('[data-field="meta_title"]').val();
		edited.meta_description = $box.find('[data-field="meta_description"]').val();
		var alts = {};
		$box.find('.shopwalk-seo-alt-list input').each(function () {
			alts[$(this).data('alt-key')] = $(this).val();
		});
		if (Object.keys(alts).length) {
			edited.image_alts = alts;
		}

		$.ajax({
			url: ShopwalkSeo.ajax_url,
			method: 'POST',
			data: {
				action: 'shopwalk_seo_apply',
				nonce: ShopwalkSeo.nonce,
				product_id: productId,
				generated: JSON.stringify(edited),
				apply_meta_title: $('#shopwalk-seo-apply-title').is(':checked') ? 1 : 0,
				apply_meta_description: $('#shopwalk-seo-apply-desc').is(':checked') ? 1 : 0,
				apply_focus_keyphrase: $('#shopwalk-seo-apply-focus').is(':checked') ? 1 : 0,
				apply_image_alt: $('#shopwalk-seo-apply-alt').is(':checked') ? 1 : 0,
				overwrite_alt: 0
			}
		}).done(function (resp) {
			if (resp && resp.success) {
				setStatus('Applied.', false);
			} else {
				setStatus((resp && resp.data && resp.data.message) || 'Error', true);
			}
		}).fail(function () {
			setStatus('Network error', true);
		});
	});

})(jQuery);
