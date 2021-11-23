<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\WcGateway\Gateway;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
use Psr\Log\NullLogger;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Capture;
use WooCommerce\PayPalCommerce\ApiClient\Entity\CaptureStatus;
use WooCommerce\PayPalCommerce\Onboarding\Environment;
use WooCommerce\PayPalCommerce\Onboarding\State;
use WooCommerce\PayPalCommerce\Session\SessionHandler;
use WooCommerce\PayPalCommerce\Subscription\Helper\SubscriptionHelper;
use WooCommerce\PayPalCommerce\TestCase;
use WooCommerce\PayPalCommerce\Vaulting\PaymentTokenRepository;
use WooCommerce\PayPalCommerce\WcGateway\Notice\AuthorizeOrderActionNotice;
use WooCommerce\PayPalCommerce\WcGateway\Processor\AuthorizedPaymentsProcessor;
use WooCommerce\PayPalCommerce\WcGateway\Processor\OrderProcessor;
use WooCommerce\PayPalCommerce\WcGateway\Processor\RefundProcessor;
use WooCommerce\PayPalCommerce\WcGateway\Settings\Settings;
use WooCommerce\PayPalCommerce\WcGateway\Settings\SettingsRenderer;
use Mockery;
use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;

class WcGatewayTest extends TestCase
{
	private $environment;

	public function setUp(): void {
		parent::setUp();

		$this->environment = Mockery::mock(Environment::class);
	}

	public function testProcessPaymentSuccess() {
	    expect('is_admin')->andReturn(false);

        $orderId = 1;
        $wcOrder = Mockery::mock(\WC_Order::class);
		$wcOrder->shouldReceive('get_customer_id')->andReturn(1);
		$wcOrder->shouldReceive('get_meta')->andReturn('');
        $settingsRenderer = Mockery::mock(SettingsRenderer::class);
        $orderProcessor = Mockery::mock(OrderProcessor::class);
        $orderProcessor
            ->expects('process')
            ->andReturnUsing(
                function(\WC_Order $order) use ($wcOrder) : bool {
                    return $order === $wcOrder;
                }
            );
        $authorizedPaymentsProcessor = Mockery::mock(AuthorizedPaymentsProcessor::class);
        $settings = Mockery::mock(Settings::class);
        $sessionHandler = Mockery::mock(SessionHandler::class);
        $sessionHandler
	        ->shouldReceive('destroy_session_data');
        $settings
            ->shouldReceive('has')->andReturnFalse();
        $refundProcessor = Mockery::mock(RefundProcessor::class);
        $transactionUrlProvider = Mockery::mock(TransactionUrlProvider::class);
        $state = Mockery::mock(State::class);
        $state
	        ->shouldReceive('current_state')->andReturn(State::STATE_ONBOARDED);
        $subscriptionHelper = Mockery::mock(SubscriptionHelper::class);
        $subscriptionHelper
            ->shouldReceive('has_subscription')
            ->with($orderId)
            ->andReturn(true)
			->andReturn(false);
        $subscriptionHelper
            ->shouldReceive('is_subscription_change_payment')
            ->andReturn(true);

        $paymentTokenRepository = Mockery::mock(PaymentTokenRepository::class);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $paymentsEndpoint = Mockery::mock(PaymentsEndpoint::class);
        $orderEndpoint = Mockery::mock(OrderEndpoint::class);

        $testee = new PayPalGateway(
            $settingsRenderer,
            $orderProcessor,
            $authorizedPaymentsProcessor,
            $settings,
            $sessionHandler,
	        $refundProcessor,
	        $state,
            $transactionUrlProvider,
            $subscriptionHelper,
			PayPalGateway::ID,
			$this->environment,
			$paymentTokenRepository,
			$logger,
			$paymentsEndpoint,
			$orderEndpoint
		);

        expect('wc_get_order')
            ->with($orderId)
            ->andReturn($wcOrder);


        when('wc_get_checkout_url')
		->justReturn('test');

		$woocommerce = Mockery::mock(\WooCommerce::class);
		$cart = Mockery::mock(\WC_Cart::class);
		when('WC')->justReturn($woocommerce);
		$woocommerce->cart = $cart;
		$cart->shouldReceive('empty_cart');

        $result = $testee->process_payment($orderId);

        $this->assertIsArray($result);

        $this->assertEquals('success', $result['result']);
        $this->assertEquals($result['redirect'], $wcOrder);
    }

    public function testProcessPaymentOrderNotFound() {
	    expect('is_admin')->andReturn(false);

        $orderId = 1;
        $settingsRenderer = Mockery::mock(SettingsRenderer::class);
        $orderProcessor = Mockery::mock(OrderProcessor::class);
        $authorizedPaymentsProcessor = Mockery::mock(AuthorizedPaymentsProcessor::class);
        $settings = Mockery::mock(Settings::class);
        $settings
            ->shouldReceive('has')->andReturnFalse();
        $sessionHandler = Mockery::mock(SessionHandler::class);
	    $refundProcessor = Mockery::mock(RefundProcessor::class);
	    $state = Mockery::mock(State::class);
        $transactionUrlProvider = Mockery::mock(TransactionUrlProvider::class);
	    $state
		    ->shouldReceive('current_state')->andReturn(State::STATE_ONBOARDED);
        $subscriptionHelper = Mockery::mock(SubscriptionHelper::class);

		$paymentTokenRepository = Mockery::mock(PaymentTokenRepository::class);
		$logger = Mockery::mock(LoggerInterface::class);
		$paymentsEndpoint = Mockery::mock(PaymentsEndpoint::class);
		$orderEndpoint = Mockery::mock(OrderEndpoint::class);

	    $testee = new PayPalGateway(
            $settingsRenderer,
            $orderProcessor,
            $authorizedPaymentsProcessor,
            $settings,
            $sessionHandler,
	        $refundProcessor,
	        $state,
            $transactionUrlProvider,
            $subscriptionHelper,
			PayPalGateway::ID,
			$this->environment,
			$paymentTokenRepository,
			$logger,
			$paymentsEndpoint,
			$orderEndpoint
        );

        expect('wc_get_order')
            ->with($orderId)
            ->andReturn(false);

        $redirectUrl = 'http://example.com/checkout';

        when('wc_get_checkout_url')
			->justReturn($redirectUrl);

        expect('wc_add_notice')
			->with('Couldn\'t find order to process','error');

        $this->assertEquals(
        	[
        		'result' => 'failure',
				'redirect' => $redirectUrl
			],
			$testee->process_payment($orderId)
		);
    }


    public function testProcessPaymentFails() {
    	expect('is_admin')->andReturn(false);

        $orderId = 1;
        $wcOrder = Mockery::mock(\WC_Order::class);
        $lastError = 'some-error';
        $settingsRenderer = Mockery::mock(SettingsRenderer::class);
        $orderProcessor = Mockery::mock(OrderProcessor::class);
        $orderProcessor
            ->expects('process')
            ->andReturnFalse();
        $orderProcessor
            ->expects('last_error')
            ->andReturn($lastError);
        $authorizedPaymentsProcessor = Mockery::mock(AuthorizedPaymentsProcessor::class);
        $settings = Mockery::mock(Settings::class);
        $settings
            ->shouldReceive('has')->andReturnFalse();
        $sessionHandler = Mockery::mock(SessionHandler::class);
	    $refundProcessor = Mockery::mock(RefundProcessor::class);
	    $state = Mockery::mock(State::class);
        $transactionUrlProvider = Mockery::mock(TransactionUrlProvider::class);
	    $state
		    ->shouldReceive('current_state')->andReturn(State::STATE_ONBOARDED);
        $subscriptionHelper = Mockery::mock(SubscriptionHelper::class);
        $subscriptionHelper->shouldReceive('has_subscription')->with($orderId)->andReturn(true);
        $subscriptionHelper->shouldReceive('is_subscription_change_payment')->andReturn(true);
        $wcOrder->shouldReceive('update_status')->andReturn(true);

		$paymentTokenRepository = Mockery::mock(PaymentTokenRepository::class);
		$logger = Mockery::mock(LoggerInterface::class);
		$paymentsEndpoint = Mockery::mock(PaymentsEndpoint::class);
		$orderEndpoint = Mockery::mock(OrderEndpoint::class);

        $testee = new PayPalGateway(
            $settingsRenderer,
            $orderProcessor,
            $authorizedPaymentsProcessor,
            $settings,
            $sessionHandler,
	        $refundProcessor,
	        $state,
            $transactionUrlProvider,
            $subscriptionHelper,
			PayPalGateway::ID,
			$this->environment,
			$paymentTokenRepository,
			$logger,
			$paymentsEndpoint,
			$orderEndpoint
        );

        expect('wc_get_order')
            ->with($orderId)
            ->andReturn($wcOrder);
        expect('wc_add_notice')
            ->with($lastError, 'error');

		$redirectUrl = 'http://example.com/checkout';

		when('wc_get_checkout_url')
			->justReturn($redirectUrl);

        $result = $testee->process_payment($orderId);
        $this->assertEquals(
        	[
        		'result' => 'failure',
				'redirect' => $redirectUrl
			],
			$result
		);
    }

    /**
     * @dataProvider dataForTestNeedsSetup
     */
    public function testNeedsSetup($currentState, $needSetup)
    {
    	expect('is_admin')->andReturn(true);
    	$settingsRenderer = Mockery::mock(SettingsRenderer::class);
    	$orderProcessor = Mockery::mock(OrderProcessor::class);
    	$authorizedOrdersProcessor = Mockery::mock(AuthorizedPaymentsProcessor::class);
    	$config = Mockery::mock(ContainerInterface::class);
    	$config
		    ->shouldReceive('has')
		    ->andReturn(false);
    	$sessionHandler = Mockery::mock(SessionHandler::class);
    	$refundProcessor = Mockery::mock(RefundProcessor::class);
    	$onboardingState = Mockery::mock(State::class);
    	$onboardingState
		    ->expects('current_state')
		    ->andReturn($currentState);
    	$transactionUrlProvider = Mockery::mock(TransactionUrlProvider::class);
    	$subscriptionHelper = Mockery::mock(SubscriptionHelper::class);

		$paymentTokenRepository = Mockery::mock(PaymentTokenRepository::class);
		$logger = Mockery::mock(LoggerInterface::class);
		$paymentsEndpoint = Mockery::mock(PaymentsEndpoint::class);
		$orderEndpoint = Mockery::mock(OrderEndpoint::class);

    	$testee = new PayPalGateway(
    		$settingsRenderer,
		    $orderProcessor,
		    $authorizedOrdersProcessor,
		    $config,
		    $sessionHandler,
		    $refundProcessor,
		    $onboardingState,
		    $transactionUrlProvider,
		    $subscriptionHelper,
			PayPalGateway::ID,
			$this->environment,
			$paymentTokenRepository,
			$logger,
			$paymentsEndpoint,
			$orderEndpoint
	    );

    	$this->assertSame($needSetup, $testee->needs_setup());
    }

    public function dataForTestCaptureAuthorizedPaymentNoActionableFailures() : array
    {
        return [
            'inaccessible' => [
                AuthorizedPaymentsProcessor::INACCESSIBLE,
                AuthorizeOrderActionNotice::NO_INFO,
            ],
            'not_found' => [
                AuthorizedPaymentsProcessor::NOT_FOUND,
                AuthorizeOrderActionNotice::NOT_FOUND,
            ],
            'not_mapped' => [
                'some-other-failure',
                AuthorizeOrderActionNotice::FAILED,
            ],
        ];
    }

    public function dataForTestNeedsSetup(): array
    {
    	return [
    		[State::STATE_START, true],
		    [State::STATE_PROGRESSIVE, true],
		    [State::STATE_ONBOARDED, false]
	    ];
    }
}
