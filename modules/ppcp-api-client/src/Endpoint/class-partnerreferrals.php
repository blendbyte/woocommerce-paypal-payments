<?php
/**
 * The partner referrals endpoint.
 *
 * @package Inpsyde\PayPalCommerce\ApiClient\Endpoint
 */

declare(strict_types=1);

namespace Inpsyde\PayPalCommerce\ApiClient\Endpoint;

use Inpsyde\PayPalCommerce\ApiClient\Authentication\Bearer;
use Inpsyde\PayPalCommerce\ApiClient\Exception\PayPalApiException;
use Inpsyde\PayPalCommerce\ApiClient\Exception\RuntimeException;
use Inpsyde\PayPalCommerce\ApiClient\Repository\PartnerReferralsData;
use Psr\Log\LoggerInterface;

/**
 * Class PartnerReferrals
 */
class PartnerReferrals {

	use RequestTrait;

	/**
	 * The host.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * The bearer.
	 *
	 * @var Bearer
	 */
	private $bearer;

	/**
	 * The PartnerReferralsData.
	 *
	 * @var PartnerReferralsData
	 */
	private $data;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * PartnerReferrals constructor.
	 *
	 * @param string               $host The host.
	 * @param Bearer               $bearer The bearer.
	 * @param PartnerReferralsData $data The partner referrals data.
	 * @param LoggerInterface      $logger The logger.
	 */
	public function __construct(
		string $host,
		Bearer $bearer,
		PartnerReferralsData $data,
		LoggerInterface $logger
	) {

		$this->host   = $host;
		$this->bearer = $bearer;
		$this->data   = $data;
		$this->logger = $logger;
	}

	/**
	 * Fetch the signup link.
	 *
	 * @return string
	 * @throws RuntimeException If the request fails.
	 */
	public function signup_link(): string {
		$data     = $this->data->data();
		$bearer   = $this->bearer->bearer();
		$args     = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $bearer->token(),
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			),
			'body'    => wp_json_encode( $data ),
		);
		$url      = trailingslashit( $this->host ) . 'v2/customer/partner-referrals';
		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new RuntimeException(
				__( 'Could not create referral.', 'paypal-for-woocommerce' )
			);
			$this->logger->log(
				'warning',
				$error->getMessage(),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}
		$json        = json_decode( $response['body'] );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 201 !== $status_code ) {
			$error = new PayPalApiException(
				$json,
				$status_code
			);
			$this->logger->log(
				'warning',
				$error->getMessage(),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		foreach ( $json->links as $link ) {
			if ( 'action_url' === $link->rel ) {
				return (string) $link->href;
			}
		}

		$error = new RuntimeException(
			__( 'Action URL not found.', 'paypal-for-woocommerce' )
		);
		$this->logger->log(
			'warning',
			$error->getMessage(),
			array(
				'args'     => $args,
				'response' => $response,
			)
		);
		throw $error;
	}
}