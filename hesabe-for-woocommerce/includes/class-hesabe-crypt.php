<?php
/**
 * Hesabe AES encryption (PHP kit compatible).
 *
 * @see https://developer.hesabe.com/docs/integration-kits/php
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Hesabe_WC_Crypt
 */
class Hesabe_WC_Crypt {

	/**
	 * Encrypt JSON payload for checkout API.
	 *
	 * @param string $str   JSON string.
	 * @param string $key   Secret key.
	 * @param string $ivKey IV key.
	 * @return string
	 */
	public static function encrypt( $str, $key, $iv_key ) {
		$str       = self::pkcs5_pad( $str );
		$encrypted = openssl_encrypt( $str, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv_key );
		$encrypted = base64_decode( $encrypted, true );
		if ( false === $encrypted ) {
			return '';
		}
		$encrypted = unpack( 'C*', $encrypted );
		$encrypted = self::byte_array2_hex( $encrypted );
		return urlencode( $encrypted );
	}

	/**
	 * Decrypt API response payload.
	 *
	 * @param string $code  Encrypted payload (hex).
	 * @param string $key   Secret key.
	 * @param string $iv_key IV key.
	 * @return string|false
	 */
	public static function decrypt( $code, $key, $iv_key ) {
		if ( ! ( ctype_xdigit( $code ) && strlen( $code ) % 2 === 0 ) ) {
			return false;
		}
		$code = self::hex2_byte_array( trim( $code ) );
		$code = self::byte_array2_string( $code );
		$code = base64_encode( $code );
		$decrypted = openssl_decrypt( $code, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv_key );
		if ( false === $decrypted ) {
			return false;
		}
		return self::pkcs5_unpad( $decrypted );
	}

	/**
	 * @param string $text Plaintext.
	 * @return string
	 */
	private static function pkcs5_pad( $text ) {
		$block_size = 32;
		$pad        = $block_size - ( strlen( $text ) % $block_size );
		return $text . str_repeat( chr( $pad ), $pad );
	}

	/**
	 * @param string|false $text Plaintext.
	 * @return string|false
	 */
	private static function pkcs5_unpad( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return false;
		}
		$pad = ord( $text[ strlen( $text ) - 1 ] );
		if ( $pad > strlen( $text ) ) {
			return false;
		}
		if ( strspn( $text, chr( $pad ), strlen( $text ) - $pad ) !== $pad ) {
			return false;
		}
		return substr( $text, 0, -1 * $pad );
	}

	/**
	 * @param array<int, int> $byte_array Byte values.
	 * @return string
	 */
	private static function byte_array2_hex( $byte_array ) {
		$chars = array_map( 'chr', $byte_array );
		$bin   = implode( '', $chars );
		return bin2hex( $bin );
	}

	/**
	 * @param string $hex_string Hex string.
	 * @return array<int, int>
	 */
	private static function hex2_byte_array( $hex_string ) {
		$string = hex2bin( $hex_string );
		if ( false === $string ) {
			return array();
		}
		return unpack( 'C*', $string );
	}

	/**
	 * @param array<int, int> $byte_array Bytes.
	 * @return string
	 */
	private static function byte_array2_string( $byte_array ) {
		$chars = array_map( 'chr', $byte_array );
		return implode( '', $chars );
	}
}
