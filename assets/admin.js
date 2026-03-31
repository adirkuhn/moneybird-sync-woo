/* global mbsfwAdmin, jQuery */
( function ( $ ) {
	'use strict';
	console.log('MBSFW Admin JS Loaded');

	// ── View logs ────────────────────────────────────────────────────────────
	$( document ).on( 'click', '.mbsfw-view-logs', function () {
		var taskId = $( this ).data( 'task-id' );
		var $panel = $( '#mbsfw-logs-panel' );
		$( '#mbsfw-logs-content' ).html( '<p>' + mbsfwAdmin.i18n.syncing + '</p>' );
		$panel.show();
		if ( $panel.length ) {
			$( 'html, body' ).animate( { scrollTop: $panel.offset().top - 40 }, 300 );
		}

		$.post( mbsfwAdmin.ajaxurl, {
			action:  'mbsfw_get_logs',
			_ajax_nonce: mbsfwAdmin.nonce,
			task_id: taskId,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				$( '#mbsfw-logs-content' ).html( '<p class="error">' + response.data + '</p>' );
				return;
			}
			var logs = response.data;
			if ( ! logs.length ) {
				$( '#mbsfw-logs-content' ).html( '<p>No logs for this task.</p>' );
				return;
			}
			var html = '<table class="wp-list-table widefat fixed striped">' +
				'<thead><tr><th style="width:160px">Time (UTC)</th><th style="width:70px">Level</th><th>Message</th></tr></thead><tbody>';
			logs.forEach( function ( log ) {
				html += '<tr class="mbsfw-level-' + log.level + '">' +
					'<td>' + log.created_at + '</td>' +
					'<td>' + log.level + '</td>' +
					'<td>' + log.message + '</td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			$( '#mbsfw-logs-content' ).html( html );
		} ).fail( function () {
			$( '#mbsfw-logs-content' ).html( '<p class="error">Request failed.</p>' );
		} );
	} );

	// ── Close logs panel ─────────────────────────────────────────────────────
	$( document ).on( 'click', '#mbsfw-logs-close', function () {
		$( '#mbsfw-logs-panel' ).hide();
	} );

	// ── Retry task ───────────────────────────────────────────────────────────
	$( document ).on( 'click', '.mbsfw-retry-task', function () {
		var $btn   = $( this );
		var taskId = $btn.data( 'task-id' );
		$btn.prop( 'disabled', true ).text( 'Retrying…' );

		$.post( mbsfwAdmin.ajaxurl, {
			action:      'mbsfw_retry_task',
			_ajax_nonce: mbsfwAdmin.nonce,
			task_id:     taskId,
		} ).done( function ( response ) {
			if ( response.success ) {
				$btn.closest( 'tr' ).find( '.mbsfw-badge' ).text( 'pending' ).attr( 'class', 'mbsfw-badge mbsfw-badge--pending' );
				$btn.remove();
			} else {
				alert( response.data );
				$btn.prop( 'disabled', false ).text( 'Retry' );
			}
		} ).fail( function () {
			alert( 'Request failed.' );
			$btn.prop( 'disabled', false ).text( 'Retry' );
		} );
	} );

	// ── Delete task ──────────────────────────────────────────────────────────
	$( document ).on( 'click', '.mbsfw-delete-task', function () {
		if ( ! window.confirm( mbsfwAdmin.i18n.confirm_delete ) ) {
			return;
		}
		var $btn   = $( this );
		var taskId = $btn.data( 'task-id' );
		$btn.prop( 'disabled', true );

		$.post( mbsfwAdmin.ajaxurl, {
			action:      'mbsfw_delete_task',
			_ajax_nonce: mbsfwAdmin.nonce,
			task_id:     taskId,
		} ).done( function ( response ) {
			if ( response.success ) {
				$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
			} else {
				alert( response.data );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			alert( 'Request failed.' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// ── Manual sync (orders page) ─────────────────────────────────────────────
	$( document ).on( 'click', '.mbsfw-manual-sync', function () {
		var $btn    = $( this );
		var orderId = $btn.data( 'order-id' );
		$btn.prop( 'disabled', true ).text( mbsfwAdmin.i18n.syncing );

		$.post( mbsfwAdmin.ajaxurl, {
			action:      'mbsfw_manual_sync',
			_ajax_nonce: mbsfwAdmin.nonce,
			order_id:    orderId,
		} ).done( function ( response ) {
			if ( response.success ) {
				$btn.closest( 'tr' ).find( '.mbsfw-badge' ).text( 'pending' ).attr( 'class', 'mbsfw-badge mbsfw-badge--pending' );
				$btn.text( 'Queued ✓' );
			} else {
				alert( response.data );
				$btn.prop( 'disabled', false ).text( 'Sync Now' );
			}
		} ).fail( function () {
			alert( 'Request failed.' );
			$btn.prop( 'disabled', false ).text( 'Sync Now' );
		} );
	} );

	// ── View payload (errors page) ────────────────────────────────────────────
	$( document ).on( 'click', '.mbsfw-view-payload', function () {
		var raw = $( this ).data( 'payload' );
		try {
			var formatted = JSON.stringify( JSON.parse( raw ), null, 2 );
			window.alert( formatted );
		} catch ( e ) {
			window.alert( raw );
		}
	} );
	// ── Trigger worker (dashboard) ───────────────────────────────────────────
	$( document ).on( 'click', '#mbsfw-trigger-worker', function () {
		console.log('Process Queue Now button clicked');
		var $btn = $( this );
		var originalHtml = $btn.html();
		$btn.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update spin"></span> Processing…' );

		$.post( mbsfwAdmin.ajaxurl, {
			action:      'mbsfw_trigger_worker',
			_ajax_nonce: mbsfwAdmin.nonce,
		} ).done( function ( response ) {
			if ( response.success ) {
				$btn.text( 'Done ✓' );
				window.location.reload();
			} else {
				alert( response.data );
				$btn.prop( 'disabled', false ).html( originalHtml );
			}
		} ).fail( function () {
			alert( 'Request failed.' );
			$btn.prop( 'disabled', false ).html( originalHtml );
		} );
	} );
} )( jQuery );
