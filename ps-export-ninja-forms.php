<?php
/**
 * Plugin Name: PS Export Ninja Forms
 * Description: Export Ninja Forms submissions as CSV with configurable separator.
 * Version:     1.0.0
 * Author:      Per Soderlind
 * License:     GPL-2.0-or-later
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Ninja Forms is active.
 */
function ps_enf_ninja_forms_is_active(): bool {
	return class_exists( 'Ninja_Forms' );
}

/**
 * Register admin menu page under Tools.
 */
add_action( 'admin_menu', 'ps_enf_admin_menu' );

function ps_enf_admin_menu(): void {
	add_management_page(
		__( 'Export Ninja Forms', 'ps-export-ninja-forms' ),
		__( 'Export Ninja Forms', 'ps-export-ninja-forms' ),
		'manage_options',
		'ps-export-ninja-forms',
		'ps_enf_render_admin_page'
	);
}

/**
 * Handle CSV export on admin_init (before any output).
 */
add_action( 'admin_init', 'ps_enf_handle_export' );

function ps_enf_handle_export(): void {
	if ( ! isset( $_POST['ps_enf_action'] ) || 'export' !== $_POST['ps_enf_action'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export submissions.', 'ps-export-ninja-forms' ) );
	}

	if ( ! isset( $_POST['ps_enf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ps_enf_nonce'] ) ), 'ps_enf_export' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'ps-export-ninja-forms' ) );
	}

	if ( ! ps_enf_ninja_forms_is_active() ) {
		wp_die( esc_html__( 'Ninja Forms plugin is not active.', 'ps-export-ninja-forms' ) );
	}

	$form_id   = absint( $_POST['ps_enf_form_id'] ?? 0 );
	$separator = sanitize_text_field( wp_unslash( $_POST['ps_enf_separator'] ?? ',' ) );

	if ( ! $form_id ) {
		wp_die( esc_html__( 'Please select a form.', 'ps-export-ninja-forms' ) );
	}

	if ( strlen( $separator ) !== 1 ) {
		$separator = ',';
	}

	ps_enf_stream_csv( $form_id, $separator );
	exit;
}

/**
 * Generate and stream CSV for a given form.
 */
function ps_enf_stream_csv( int $form_id, string $separator ): void {
	$fields = Ninja_Forms()->form( $form_id )->get_fields();

	$hidden_field_types = apply_filters( 'nf_sub_hidden_field_types', array() );

	$skip_types = array(
		'submit',
		'html',
		'hr',
		'recaptcha',
		'spam',
		'unknown',
		'note',
		'confirm',
		'password',
		'passwordconfirm',
		'creditcard',
		'creditcardcvc',
		'creditcardexpiration',
		'creditcardfullname',
		'creditcardnumber',
		'creditcardzip',
		'hcaptcha',
		'turnstile',
	);

	$merged_skip = array_unique( array_merge( $hidden_field_types, $skip_types ) );

	$export_fields = array();
	foreach ( $fields as $field ) {
		$type = $field->get_setting( 'type' );
		if ( in_array( $type, $merged_skip, true ) ) {
			continue;
		}
		$export_fields[] = $field;
	}

	usort(
		$export_fields,
		function ( $a, $b ) {
			$order_a = (int) $a->get_setting( 'order' );
			$order_b = (int) $b->get_setting( 'order' );
			return $order_a <=> $order_b;
		}
	);

	$headers   = array(
		__( 'Submission ID', 'ps-export-ninja-forms' ),
		__( 'Date', 'ps-export-ninja-forms' ),
		__( 'Seq #', 'ps-export-ninja-forms' ),
	);
	$field_ids = array();

	foreach ( $export_fields as $field ) {
		$label = $field->get_setting( 'admin_label' );
		if ( empty( $label ) ) {
			$label = $field->get_setting( 'label' );
		}
		$headers[]   = $label;
		$field_ids[] = $field->get_id();
	}

	$subs = Ninja_Forms()->form( $form_id )->get_subs();

	$form       = Ninja_Forms()->form( $form_id )->get();
	$form_title = '';
	if ( $form ) {
		$form_title = sanitize_file_name( $form->get_setting( 'title' ) );
	}
	if ( empty( $form_title ) ) {
		$form_title = 'form-' . $form_id;
	}

	$filename = 'ninja-forms-export-' . $form_title . '-' . gmdate( 'Y-m-d' ) . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$output = fopen( 'php://output', 'w' );

	// UTF-8 BOM for Excel compatibility.
	fprintf( $output, "\xEF\xBB\xBF" );

	fputcsv( $output, $headers, $separator );

	if ( ! empty( $subs ) ) {
		foreach ( $subs as $sub ) {
			$row = array(
				$sub->get_id(),
				$sub->get_sub_date( 'Y-m-d H:i:s' ),
				$sub->get_seq_num(),
			);

			foreach ( $field_ids as $fid ) {
				$value = $sub->get_field_value( $fid );
				$value = maybe_unserialize( $value );

				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				$row[] = $value;
			}

			fputcsv( $output, $row, $separator );
		}
	}

	fclose( $output );
}

/**
 * Render the admin page.
 */
function ps_enf_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ps-export-ninja-forms' ) );
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Export Ninja Forms Submissions', 'ps-export-ninja-forms' ) . '</h1>';

	if ( ! ps_enf_ninja_forms_is_active() ) {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Ninja Forms plugin is not active. Please activate it to use this export tool.', 'ps-export-ninja-forms' );
		echo '</p></div></div>';
		return;
	}

	$forms = Ninja_Forms()->form()->get_forms();

	if ( empty( $forms ) ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'No Ninja Forms found. Please create a form first.', 'ps-export-ninja-forms' );
		echo '</p></div></div>';
		return;
	}

	echo '<form method="post">';
	wp_nonce_field( 'ps_enf_export', 'ps_enf_nonce' );
	echo '<input type="hidden" name="ps_enf_action" value="export">';

	echo '<table class="form-table" role="presentation">';

	// Form selector.
	echo '<tr>';
	echo '<th scope="row"><label for="ps_enf_form_id">' . esc_html__( 'Select Form', 'ps-export-ninja-forms' ) . '</label></th>';
	echo '<td><select name="ps_enf_form_id" id="ps_enf_form_id" required>';
	echo '<option value="">' . esc_html__( '-- Select a form --', 'ps-export-ninja-forms' ) . '</option>';

	foreach ( $forms as $form ) {
		printf(
			'<option value="%d">%s</option>',
			esc_attr( $form->get_id() ),
			esc_html( $form->get_setting( 'title' ) )
		);
	}

	echo '</select></td>';
	echo '</tr>';

	// Separator input.
	echo '<tr>';
	echo '<th scope="row"><label for="ps_enf_separator">' . esc_html__( 'CSV Separator', 'ps-export-ninja-forms' ) . '</label></th>';
	echo '<td>';
	echo '<input type="text" name="ps_enf_separator" id="ps_enf_separator" value="," maxlength="1" size="3" class="small-text">';
	echo '<p class="description">' . esc_html__( 'Single character used to separate values. Default is comma (,). Use semicolon (;) for some European locales.', 'ps-export-ninja-forms' ) . '</p>';
	echo '</td>';
	echo '</tr>';

	echo '</table>';

	submit_button( __( 'Export CSV', 'ps-export-ninja-forms' ) );

	echo '</form>';
	echo '</div>';
}
