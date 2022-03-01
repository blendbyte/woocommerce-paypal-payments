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
use WooCommerce\PayPalCommerce\WcGateway\FundingSource\FundingSourceRenderer;
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
	private $isAdmin = false;
	private $sessionHandler;
	private $fundingSource = null;

	private $settingsRenderer;
	private $funding_source_renderer;
	private $orderProcessor;
	private $authorizedOrdersProcessor;
	private $settings;
	private $refundProcessor;
	private $onboardingState;
	private $transactionUrlProvider;
	private $subscriptionHelper;
	private $environment;
	private $paymentTokenRepository;
	private $logger;
	private $paymentsEndpoint;
	private $orderEndpoint;

	public function setUp(): void {
		parent::setUp();

		expect('is_admin')->andReturnUsing(function () {
			return $this->isAdmin;
		});

		$this->settingsRenderer = Mockery::mock(SettingsRenderer::class);
		$this->orderProcessor = Mockery::mock(OrderProcessor::class);
		$this->authorizedOrdersProcessor = Mockery::mock(AuthorizedPaymentsProcessor::class);
		$this->settings = Mockery::mock(Settings::class);
		$this->sessionHandler = Mockery::mock(SessionHandler::class);
		$this->refundProcessor = Mockery::mock(RefundProcessor::class);
		$this->onboardingState = Mockery::mock(State::class);
		$this->transactionUrlProvider = Mockery::mock(TransactionUrlProvider::class);
		$this->subscriptionHelper = Mockery::mock(SubscriptionHelper::class);
		$this->environment = Mockery::mock(Environment::class);
		$this->paymentTokenRepository = Mockery::mock(PaymentTokenRepository::class);
		$this->logger = Mockery::mock(LoggerInterface::class);
		$this->paymentsEndpoint = Mockery::mock(PaymentsEndpoint::class);
		$this->orderEndpoint = Mockery::mock(OrderEndpoint::class);
		$this->funding_source_renderer = new FundingSourceRenderer($this->settings);

		$this->onboardingState->shouldReceive('current_state')->andReturn(State::STATE_ONBOARDED);

		$this->sessionHandler
			->shouldReceive('funding_source')
			->andReturnUsing(function () {
				return $this->fundingSource;
			});

		$this->settings->shouldReceive('has')->andReturnFalse();

		$this->logger->shouldReceive('info');
	}

	private function createGateway()
	{
		return new PayPalGateway(
			$this->settingsRenderer,
			$this->funding_source_renderer,
			$this->orderProcessor,
			$this->authorizedOrdersProcessor,
			$this->settings,
			$this->sessionHandler,
			$this->refundProcessor,
			$this->onboardingState,
			$this->transactionUrlProvider,
			$this->subscriptionHelper,
			PayPalGateway::ID,
			$this->environment,
			$this->paymentTokenRepository,
			$this->logger,
			$this->paymentsEndpoint,
			$this->orderEndpoint
		);
	}

	public function testProcessPaymentSuccess() {
        $orderId = 1;
        $wcOrder = Mockery::mock(\WC_Order::class);
		$wcOrder->shouldReceive('get_customer_id')->andReturn(1);
		$wcOrder->shouldReceive('get_meta')->andReturn('');
		$this->orderProcessor
            ->expects('process')
            ->andReturnUsing(
                function(\WC_Order $order) use ($wcOrder) : bool {
                    return $order === $wcOrder;
                }
            );
		$this->sessionHandler
	        ->shouldReceive('destroy_session_data');
		$this->subscriptionHelper
            ->shouldReceive('has_subscription')
            ->with($orderId)
            ->andReturn(true)
			->andReturn(false);
		$this->subscriptionHelper
            ->shouldReceive('is_subscription_change_payment')
            ->andReturn(true);

        $testee = $this->createGateway();

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
        $orderId = 1;

	    $testee = $this->createGateway();

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
        $orderId = 1;
        $wcOrder = Mockery::mock(\WC_Order::class);
        $lastError = 'some-error';
		$this->orderProcessor
            ->expects('process')
            ->andReturnFalse();
		$this->orderProcessor
            ->expects('last_error')
			->twice()
            ->andReturn($lastError);
		$this->subscriptionHelper->shouldReceive('has_subscription')->with($orderId)->andReturn(true);
		$this->subscriptionHelper->shouldReceive('is_subscription_change_payment')->andReturn(true);
        $wcOrder->shouldReceive('update_status')->andReturn(true);

        $testee = $this->createGateway();

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
		$this->isAdmin = true;

		$this->onboardingState = Mockery::mock(State::class);
		$this->onboardingState
		    ->expects('current_state')
		    ->andReturn($currentState);

    	$testee = $this->createGateway();

    	$this->assertSame($needSetup, $testee->needs_setup());
    }

    /**
     * @dataProvider dataForFundingSource
     */
    public function testFundingSource($fundingSource, $title, $description)
    {
		$this->fundingSource = $fundingSource;

    	$testee = $this->createGateway();

		self::assertEquals($title, $testee->title);
		self::assertEquals($description, $testee->description);
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
		    [State::STATE_ONBOARDED, false]
	    ];
    }

    public function dataForFundingSource(): array
    {
    	return [
    		[null, 'PayPal', 'Pay via PayPal.'],
    		['venmo', 'Venmo', 'Pay via Venmo.'],
    		['qwerty', 'PayPal', 'Pay via PayPal.'],
	    ];
    }
}
