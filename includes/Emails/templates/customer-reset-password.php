<?php
/**
 * LotzApp override of the Customer Reset Password email.
 *
 * Mirrors WooCommerce core templates/emails/customer-reset-password.php
 * (@version 9.8.0). Only the greeting / intro / post-username text are routed
 * through the LotzApp Advanced_Editor so they can be replaced per email; the
 * username, reset link, additional content and structure stay identical to
 * WooCommerce. If a fragment is not overridden the WooCommerce default is
 * emitted unchanged.
 *
 * @package WooCommerce\Templates\Emails
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'email_improvements' );

$lotzwoo_ae  = \Lotzwoo\Emails\Advanced_Editor::instance();
$lotzwoo_url = add_query_arg(
	array(
		'key'   => $reset_key,
		'id'    => $user_id,
		'login' => rawurlencode( $user_login ),
	),
	wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
);
$lotzwoo_extra = array(
	'{username}'           => $user_login,
	'{reset_password_url}' => esc_url( $lotzwoo_url ),
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php
$lotzwoo_greeting = '<p>' . sprintf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_login ) ) . '</p>';
$lotzwoo_intro    = '<p>' . sprintf( esc_html__( 'Someone has requested a new password for the following account on %s:', 'woocommerce' ), esc_html( $blogname ) ) . '</p>';

echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'reset_greeting', $lotzwoo_greeting, $lotzwoo_extra ) : $lotzwoo_greeting;
echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'reset_intro', $lotzwoo_intro, $lotzwoo_extra ) : $lotzwoo_intro;
?>
<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<div class="hr hr-bottom"></div>
	<?php $lotzwoo_after = '<p>' . esc_html__( 'If you didn’t make this request, just ignore this email. If you’d like to proceed, reset your password via the link below:', 'woocommerce' ) . '</p>'; ?>
<?php else : ?>
	<?php /* translators: %s: Customer username */ ?>
	<p><?php printf( esc_html__( 'Username: %s', 'woocommerce' ), esc_html( $user_login ) ); ?></p>
	<?php $lotzwoo_after = '<p>' . esc_html__( 'If you didn\'t make this request, just ignore this email. If you\'d like to proceed:', 'woocommerce' ) . '</p>'; ?>
<?php endif; ?>
<?php echo $lotzwoo_ae ? $lotzwoo_ae->fragment( $email, 'reset_after', $lotzwoo_after, $lotzwoo_extra ) : $lotzwoo_after; ?>
<p>
	<a class="link" href="<?php echo esc_url( $lotzwoo_url ); ?>">
		<?php
		if ( $email_improvements_enabled ) {
			esc_html_e( 'Reset your password', 'woocommerce' );
		} else {
			esc_html_e( 'Click here to reset your password', 'woocommerce' );
		}
		?>
	</a>
</p>
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
