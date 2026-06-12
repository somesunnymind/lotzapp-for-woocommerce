<?php
/**
 * LotzApp override of the Customer New Account email.
 *
 * Mirrors WooCommerce core templates/emails/customer-new-account.php
 * (@version 10.0.0). The greeting + welcome block (one fragment) and the
 * after-username explanation block are routed through the LotzApp
 * Advanced_Editor so they can be replaced per email; the username display,
 * the optional set-password link, the My-Account link, the additional
 * content area and the overall structure stay identical to WooCommerce.
 * If a fragment is not overridden the WC default is emitted unchanged.
 *
 * @package WooCommerce\Templates\Emails
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'email_improvements' );

$lotzwoo_ae    = \Lotzwoo\Emails\Advanced_Editor::instance();
$lotzwoo_extra = array(
	'{username}' => $user_login,
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php
// Fragment 1 (greeting + welcome).
// In email-improvements mode the WC default is two separate <p> tags
// ("Hi %s," and "Thanks for creating an account on %s. Here's a copy of
// your user details."); we present them as a single editable block so the
// admin doesn't have to maintain two near-identical fragments.
if ( $email_improvements_enabled ) {
	$lotzwoo_greeting_default  = '<p>' . sprintf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_login ) ) . '</p>';
	$lotzwoo_greeting_default .= '<p>' . sprintf( esc_html__( 'Thanks for creating an account on %s. Here&rsquo;s a copy of your user details.', 'woocommerce' ), esc_html( $blogname ) ) . '</p>';
} else {
	$lotzwoo_greeting_default = '<p>' . sprintf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_login ) ) . '</p>';
}
echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'account_greeting', $lotzwoo_greeting_default, $lotzwoo_extra ) : $lotzwoo_greeting_default; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php esc_html_e( 'Set your new password.', 'woocommerce' ); ?></a></p>
	<?php endif; ?>
	<div class="hr hr-bottom"></div>
	<?php
	// Fragment 2 (after-username explanation).
	$lotzwoo_after_default = '<p>' . esc_html__( 'You can access your account area to view orders, change your password, and more via the link below:', 'woocommerce' ) . '</p>';
	echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'account_after', $lotzwoo_after_default, $lotzwoo_extra ) : $lotzwoo_after_default; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<p><a href="<?php echo esc_attr( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a></p>
<?php else : ?>
	<?php
	// Non-improvements mode: WC bakes username + account access into one
	// paragraph. We treat that single paragraph as the after fragment so
	// the admin still has two-field semantics, just collapsed.
	$lotzwoo_after_default = '<p>' . sprintf(
		/* translators: %1$s: Username, %2$s: My account link */
		esc_html__( 'Your username is %1$s. You can access your account area to view orders, change your password, and more at: %2$s', 'woocommerce' ),
		'<strong>' . esc_html( $user_login ) . '</strong>',
		make_clickable( esc_url( wc_get_page_permalink( 'myaccount' ) ) )
	) . '</p>';
	echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'account_after', $lotzwoo_after_default, $lotzwoo_extra ) : $lotzwoo_after_default; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php esc_html_e( 'Click here to set your new password.', 'woocommerce' ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
