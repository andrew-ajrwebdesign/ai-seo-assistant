jQuery(function ($) {
	const generateButton = $('#ai-seo-generate-button');
	const clearButton = $('#ai-seo-clear-button');
	const previewButton = $('#ai-seo-preview-content-button');
	const recommendationsButton = $('#ai-seo-recommendations-button');
	const status = $('#ai-seo-status');
	const titleField = $('#ai_seo_title');
	const descriptionField = $('#ai_seo_description');
	const titleCount = $('#ai-seo-title-count');
	const descriptionCount = $('#ai-seo-description-count');
	const titleStatus = $('#ai-seo-title-status');
	const descriptionStatus = $('#ai-seo-description-status');
	const extractedPreview = $('#ai-seo-extracted-content-preview');
	const recommendationsOutput = $('#ai-seo-recommendations-output');

	function updateCounts() {
		if (titleCount.length) {
			titleCount.text(titleField.val().length);
		}
		if (descriptionCount.length) {
			descriptionCount.text(descriptionField.val().length);
		}
	}

	function escapeHtml(text) {
		return $('<div>').text(text || '').html();
	}

	function setStatus(message, type) {
		status.removeClass('is-success is-error is-warning').text(message || '');
		if (type) {
			status.addClass('is-' + type);
		}
	}

	function getResponseData(response) {
		if (!response || typeof response !== 'object' || !response.data || typeof response.data !== 'object') {
			return {};
		}
		return response.data;
	}

	function getErrorMessage(response, fallback) {
		const data = getResponseData(response);
		return data.message || data.error || fallback || 'Something went wrong.';
	}

	function buildGeneratedStatus(data) {
		let details = 'Generated';
		if (data.source) {
			details += ' via ' + data.source;
		}
		if (data.model) {
			details += ' using ' + data.model;
		}
		if (data.generated_at) {
			details += ' at ' + data.generated_at;
		}
		if (data.saved) {
			details += ' and saved';
		}
		return details + '.';
	}

	function requestMetadata(postId, showPreviewOnly = false) {
		setStatus(showPreviewOnly ? 'Extracting content...' : 'Generating metadata...', 'warning');
		generateButton.prop('disabled', true);
		previewButton.prop('disabled', true);

		$.ajax({
			url: aiSeoAssistant.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 90000,
			data: {
				action: 'ai_seo_assistant_generate',
				nonce: aiSeoAssistant.nonce,
				post_id: postId,
			},
		})
			.done(function (response) {
				console.log('AI SEO Assistant response:', response);
				const data = getResponseData(response);
				if (!response || response.success !== true) {
					setStatus(getErrorMessage(response, 'Metadata generation failed.'), 'error');
					return;
				}
				if (data.error) {
					setStatus('API error: ' + data.error, 'error');
					return;
				}
				if (data.extracted_preview) {
					extractedPreview.text(data.extracted_preview).show();
				} else {
					extractedPreview.text('No extracted content was returned.').show();
				}
				if (showPreviewOnly) {
					setStatus('Extracted content preview loaded.', 'success');
					return;
				}
				if (!data.title && !data.description) {
					console.error('AI SEO Assistant returned success without metadata:', response);
					setStatus('Metadata generation returned no title or description. Check the AJAX response/PHP handler.', 'error');
					return;
				}
				titleField.val(data.title || '');
				descriptionField.val(data.description || '');
				titleStatus.text(data.title_status || '');
				descriptionStatus.text(data.description_status || '');
				updateCounts();
				if (data.warning) {
					setStatus(data.warning, 'warning');
				} else if (data.source === 'placeholder') {
					setStatus('Placeholder metadata generated. Review and update the page to save.', 'warning');
				} else {
					setStatus(buildGeneratedStatus(data) + ' Review the fields, then update the post to save.', 'success');
				}
			})
			.fail(function (xhr, textStatus) {
				console.error('AI SEO Assistant AJAX error:', xhr);
				let message = 'Request failed. Check the browser console.';
				if (textStatus === 'timeout') {
					message = 'The request timed out. Try again or use a shorter page/content extract.';
				} else if (xhr && xhr.responseJSON) {
					message = getErrorMessage(xhr.responseJSON, message);
				} else if (xhr && xhr.responseText) {
					message = xhr.responseText.replace(/<[^>]*>/g, '').trim();
					if (message.length > 220) {
						message = message.substring(0, 220) + '...';
					}
				}
				setStatus(message, 'error');
			})
			.always(function () {
				generateButton.prop('disabled', false);
				previewButton.prop('disabled', false);
			});
	}

	function renderBadge(statusText) {
		let badgeClass = 'ai-seo-assistant-badge';
		if (statusText === 'Looks good') {
			badgeClass += ' is-good';
		} else if (statusText === 'Missing') {
			badgeClass += ' is-missing';
		} else {
			badgeClass += ' is-warning';
		}
		return '<span class="' + badgeClass + '">' + escapeHtml(statusText) + '</span>';
	}

	function formatGeneratedDetails(data) {
		if (data.error) {
			return 'Never';
		}
		let details = '';
		if (data.generated_at) {
			details += escapeHtml(data.generated_at);
		}
		if (data.source || data.model) {
			details += '<div class="ai-seo-assistant-audit-small">';
			if (data.source) {
				details += escapeHtml(data.source);
			}
			if (data.model) {
				details += ' / ' + escapeHtml(data.model);
			}
			details += '</div>';
		}
		return details || 'Just now';
	}

	function updateAuditRow(row, data) {
		const titleCell = row.find('.ai-seo-audit-title-cell');
		const descriptionCell = row.find('.ai-seo-audit-description-cell');
		const lastGeneratedCell = row.find('.ai-seo-audit-last-generated-cell');
		titleCell.html(renderBadge(data.title_status || '') + '<div class="ai-seo-assistant-audit-meta-preview">' + escapeHtml(data.title || '') + '</div>');
		descriptionCell.html(renderBadge(data.description_status || '') + '<div class="ai-seo-assistant-audit-meta-preview">' + escapeHtml(data.description || '') + '</div>');
		lastGeneratedCell.html(formatGeneratedDetails(data));
	}

	function generateAndSaveFromAudit(button) {
		const postId = button.data('post-id');
		const row = button.closest('tr');
		const rowStatus = row.find('.ai-seo-audit-row-status');
		if (!postId) {
			rowStatus.removeClass('is-success is-error is-warning').addClass('is-error').text('Missing post ID.');
			return;
		}
		rowStatus.removeClass('is-success is-error is-warning').addClass('is-warning').text('Generating...');
		button.prop('disabled', true);
		$.ajax({
			url: aiSeoAssistant.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 90000,
			data: {
				action: 'ai_seo_assistant_generate_and_save',
				nonce: aiSeoAssistant.nonce,
				post_id: postId,
			},
		})
			.done(function (response) {
				console.log('AI SEO Assistant audit response:', response);
				const data = getResponseData(response);
				if (!response || response.success !== true) {
					rowStatus.removeClass('is-success is-warning').addClass('is-error').text(getErrorMessage(response, 'Something went wrong.'));
					return;
				}
				if (data.error) {
					rowStatus.removeClass('is-success is-warning').addClass('is-error').text(data.error);
					return;
				}
				updateAuditRow(row, data);
				rowStatus.removeClass('is-error is-warning').addClass('is-success').text(buildGeneratedStatus(data));
			})
			.fail(function (xhr, textStatus) {
				console.error('AI SEO Assistant audit AJAX error:', xhr);
				let message = 'Request failed. Check the browser console.';
				if (textStatus === 'timeout') {
					message = 'The request timed out. Try again or use a shorter page/content extract.';
				} else if (xhr && xhr.responseJSON) {
					message = getErrorMessage(xhr.responseJSON, message);
				}
				rowStatus.removeClass('is-success is-warning').addClass('is-error').text(message);
			})
			.always(function () {
				button.prop('disabled', false);
			});
	}

	function renderRecommendationList(items) {
		if (!Array.isArray(items) || !items.length) {
			return '<p class="ai-seo-assistant-recommendations-empty">No recommendations returned.</p>';
		}
		let html = '<ul>';
		items.forEach(function (item) {
			html += '<li>' + escapeHtml(item) + '</li>';
		});
		html += '</ul>';
		return html;
	}

	function renderContentInsertionSuggestions(items) {
		if (!Array.isArray(items) || !items.length) {
			return '<p class="ai-seo-assistant-recommendations-empty">No placement suggestions returned.</p>';
		}
		let html = '<div class="ai-seo-content-insertion-list">';
		items.forEach(function (item) {
			html += '<div class="ai-seo-content-insertion-item">';
			if (item.missing_term) {
				html += '<p><strong>Missing term/topic:</strong> ' + escapeHtml(item.missing_term) + '</p>';
			}
			if (item.recommended_location) {
				html += '<p><strong>Suggested placement:</strong> ' + escapeHtml(item.recommended_location) + '</p>';
			}
			if (item.suggested_copy) {
				html += '<p><strong>Suggested copy:</strong></p><blockquote>' + escapeHtml(item.suggested_copy) + '</blockquote>';
			}
			if (item.reason) {
				html += '<p><strong>Why:</strong> ' + escapeHtml(item.reason) + '</p>';
			}
			html += '</div>';
		});
		html += '</div>';
		return html;
	}

	function renderRecommendations(data) {
		let html = '';
		html += '<div class="ai-seo-assistant-recommendations-card"><h3>SEO Recommendations</h3>';
		if (data.summary) {
			html += '<div class="ai-seo-assistant-recommendations-section"><h4>Summary</h4><p>' + escapeHtml(data.summary) + '</p></div>';
		}
		html += '<div class="ai-seo-assistant-recommendations-grid">';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Priority Actions</h4>' + renderRecommendationList(data.priority_actions) + '</div>';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Content Gaps</h4>' + renderRecommendationList(data.content_gaps) + '</div>';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Suggested Sections</h4>' + renderRecommendationList(data.suggested_sections) + '</div>';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Local SEO Notes</h4>' + renderRecommendationList(data.local_seo_notes) + '</div>';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Internal Linking Suggestions</h4>' + renderRecommendationList(data.internal_linking_suggestions) + '</div>';
		html += '<div class="ai-seo-assistant-recommendations-section"><h4>Content Placement Suggestions</h4>' + renderContentInsertionSuggestions(data.content_insertion_suggestions) + '</div>';
		if (data.metadata_direction) {
			html += '<div class="ai-seo-assistant-recommendations-section"><h4>Metadata Direction</h4>';
			if (data.metadata_direction.title_angle) {
				html += '<p><strong>Title angle:</strong> ' + escapeHtml(data.metadata_direction.title_angle) + '</p>';
			}
			if (data.metadata_direction.description_angle) {
				html += '<p><strong>Description angle:</strong> ' + escapeHtml(data.metadata_direction.description_angle) + '</p>';
			}
			html += '</div>';
		}
		html += '</div></div>';
		recommendationsOutput.html(html).show();
	}

	function generateRecommendations(button) {
		const postId = button.data('post-id');
		if (!postId) {
			setStatus('Missing post ID.', 'error');
			return;
		}
		setStatus('Generating SEO recommendations...', 'warning');
		button.prop('disabled', true);
		recommendationsOutput.html('').hide();
		$.ajax({
			url: aiSeoAssistant.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 90000,
			data: {
				action: 'ai_seo_assistant_generate_recommendations',
				nonce: aiSeoAssistant.nonce,
				post_id: postId,
			},
		})
			.done(function (response) {
				console.log('AI SEO Assistant recommendations response:', response);
				if (!response || response.success !== true) {
					setStatus(getErrorMessage(response, 'Something went wrong.'), 'error');
					return;
				}
				renderRecommendations(getResponseData(response));
				setStatus('SEO recommendations generated.', 'success');
			})
			.fail(function (xhr, textStatus) {
				console.error('AI SEO Assistant recommendations AJAX error:', xhr);
				let message = 'Request failed. Check the browser console.';
				if (textStatus === 'timeout') {
					message = 'The request timed out. Try again or use a shorter page/content extract.';
				} else if (xhr && xhr.responseJSON) {
					message = getErrorMessage(xhr.responseJSON, message);
				}
				setStatus(message, 'error');
			})
			.always(function () {
				button.prop('disabled', false);
			});
	}

	function setFieldValueIfAllowed(selector, value, overwrite) {
		const field = $(selector);
		if (!field.length || value === undefined || value === null || value === '') {
			return false;
		}
		if (!overwrite && field.val()) {
			return false;
		}
		field.val(value).trigger('input').trigger('change');
		return true;
	}

	function renderFocusSuggestions(data) {
		let html = '<div class="ai-seo-focus-suggestion-card"><strong>SEO focus suggestions generated.</strong>';
		if (data.source === 'search_console' && data.top_query) {
			html += '<p><strong>Source:</strong> Search Console</p><p><strong>Top query:</strong> ' + escapeHtml(data.top_query) + '</p>';
			if (data.impressions) {
				html += '<p><strong>Impressions:</strong> ' + escapeHtml(String(data.impressions)) + '</p>';
			}
			if (data.position) {
				html += '<p><strong>Avg position:</strong> ' + escapeHtml(String(data.position)) + '</p>';
			}
		} else {
			html += '<p><strong>Source:</strong> Page content</p>';
		}
		html += '<ul>';
		if (data.service_focus) html += '<li><strong>Primary focus:</strong> ' + escapeHtml(data.service_focus) + '</li>';
		if (data.primary_location) html += '<li><strong>Primary location:</strong> ' + escapeHtml(data.primary_location) + '</li>';
		if (data.secondary_locations) html += '<li><strong>Secondary locations:</strong> ' + escapeHtml(data.secondary_locations) + '</li>';
		if (data.search_intent) html += '<li><strong>Search intent:</strong> ' + escapeHtml(data.search_intent) + '</li>';
		if (data.priority) html += '<li><strong>Priority:</strong> ' + escapeHtml(data.priority) + '</li>';
		html += '</ul>';
		if (data.page_notes) html += '<p><strong>Notes:</strong> ' + escapeHtml(data.page_notes) + '</p>';
		html += '</div>';
		$('#ai-seo-autofill-focus-output').html(html).show();
	}

	function autofillSeoFocus(button) {
		const postId = button.data('post-id');
		const overwrite = $('#ai-seo-autofill-overwrite').is(':checked');
		if (!postId) {
			setStatus('Missing post ID.', 'error');
			return;
		}
		setStatus('Generating SEO focus suggestions...', 'warning');
		button.prop('disabled', true);
		$.ajax({
			url: aiSeoAssistant.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 90000,
			data: {
				action: 'ai_seo_assistant_suggest_focus',
				nonce: aiSeoAssistant.nonce,
				post_id: postId,
			},
		})
			.done(function (response) {
				console.log('AI SEO Assistant focus suggestions response:', response);
				const data = getResponseData(response);
				if (!response || response.success !== true) {
					setStatus(getErrorMessage(response, 'Could not generate SEO focus suggestions.'), 'error');
					return;
				}
				let appliedCount = 0;
				if (setFieldValueIfAllowed('#ai_seo_service_focus', data.service_focus, overwrite)) appliedCount++;
				if (setFieldValueIfAllowed('#ai_seo_primary_location', data.primary_location, overwrite)) appliedCount++;
				if (setFieldValueIfAllowed('#ai_seo_secondary_locations', data.secondary_locations, overwrite)) appliedCount++;
				if (setFieldValueIfAllowed('#ai_seo_search_intent', data.search_intent, overwrite)) appliedCount++;
				if (setFieldValueIfAllowed('#ai_seo_priority', data.priority, overwrite)) appliedCount++;
				if (setFieldValueIfAllowed('#ai_seo_page_notes', data.page_notes, overwrite)) appliedCount++;
				renderFocusSuggestions(data);
				if (appliedCount > 0) {
					setStatus('SEO focus suggestions applied. Review the fields, then update the page to save.', 'success');
				} else {
					setStatus('Suggestions generated, but no fields were changed. Enable overwrite to replace existing values.', 'warning');
				}
			})
			.fail(function (xhr, textStatus) {
				console.error('AI SEO Assistant focus suggestion AJAX error:', xhr);
				let message = 'Request failed. Check the browser console.';
				if (textStatus === 'timeout') {
					message = 'The request timed out.';
				} else if (xhr && xhr.responseJSON) {
					message = getErrorMessage(xhr.responseJSON, message);
				}
				setStatus(message, 'error');
			})
			.always(function () {
				button.prop('disabled', false);
			});
	}

	titleField.on('input', updateCounts);
	descriptionField.on('input', updateCounts);
	clearButton.on('click', function () {
		titleField.val('');
		descriptionField.val('');
		titleStatus.text('Missing');
		descriptionStatus.text('Missing');
		setStatus('Fields cleared. Update the post to save.', 'warning');
		updateCounts();
	});
	generateButton.on('click', function () {
		const postId = generateButton.data('post-id');
		if (!postId) {
			setStatus('Missing post ID.', 'error');
			return;
		}
		requestMetadata(postId, false);
	});
	previewButton.on('click', function () {
		const postId = previewButton.data('post-id');
		if (!postId) {
			setStatus('Missing post ID.', 'error');
			return;
		}
		requestMetadata(postId, true);
	});
	recommendationsButton.on('click', function () {
		generateRecommendations($(this));
	});
	$(document).on('click', '.ai-seo-audit-generate-save', function () {
		generateAndSaveFromAudit($(this));
	});
	$(document).on('click', '#ai-seo-autofill-focus-button', function () {
		autofillSeoFocus($(this));
	});
	updateCounts();
});
