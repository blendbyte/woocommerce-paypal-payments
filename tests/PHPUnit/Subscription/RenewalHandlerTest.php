<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Subscription;

use Dhii\Container\Dictionary;
use Exception;
use Psr\Log\LoggerInterface;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Capture;
use WooCommerce\PayPalCommerce\ApiClient\Entity\CaptureStatus;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Payer;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Payments;
use WooCommerce\PayPalCommerce\ApiClient\Entity\PaymentToken;
use WooCommerce\PayPalCommerce\ApiClient\Entity\PurchaseUnit;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PayerFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PurchaseUnitFactory;
use WooCommerce\PayPalCommerce\Onboarding\Environment;
use WooCommerce\PayPalCommerce\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use WooCommerce\PayPalCommerce\Vaulting\PaymentTokenRepository;
use WooCommerce\PayPalCommerce\WcGateway\Gateway\PayPalGateway;

class RenewalHandlerTest extends TestCase
{
	use MockeryPHPUnitIntegration;

	private $logger;
	private $repository;
	private $orderEndpoint;
	private $purchaseUnitFactory;
	private $payerFactory;
	private $environment;
	private $sut;

	public function setUp(): void
	{
		parent::setUp();

		$this->logger = Mockery::mock(LoggerInterface::class);
		$this->repository = Mockery::mock(PaymentTokenRepository::class);
		$this->orderEndpoint = Mockery::mock(OrderEndpoint::class);
		$this->purchaseUnitFactory = Mockery::mock(PurchaseUnitFactory::class);
		$this->payerFactory = Mockery::mock(PayerFactory::class);
		$this->environment = new Environment(new Dictionary([]));

		$this->logger->shouldReceive('error')->andReturnUsing(function ($msg) {
			throw new Exception($msg);
		});
		$this->logger->shouldReceive('info');

		$this->sut = new RenewalHandler(
			$this->logger,
			$this->repository,
			$this->orderEndpoint,
			$this->purchaseUnitFactory,
			$this->payerFactory,
			$this->environment
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRenewProcessOrder()
	{
		$transactionId = 'ABC123';
		$wcOrder = Mockery::mock(\WC_Order::class);
		$customer = Mockery::mock('overload:WC_Customer');
		$token = Mockery::mock(PaymentToken::class);
		$payer = Mockery::mock(Payer::class);
		$order = Mockery::mock(Order::class);

		$capture = Mockery::mock(Capture::class);
		$capture->expects('id')
			->andReturn($transactionId);
		$capture->expects('status')
			->andReturn(new CaptureStatus(CaptureStatus::COMPLETED));

		$payments = Mockery::mock(Payments::class);
		$payments->shouldReceive('captures')
			->andReturn([$capture]);

		$purchaseUnit = Mockery::mock(PurchaseUnit::class);
		$purchaseUnit->shouldReceive('payments')
			->andReturn($payments);

		$order
			->shouldReceive('id')
			->andReturn('101');
		$order->shouldReceive('intent')
			->andReturn('CAPTURE');
		$order->shouldReceive('status->is')
			->andReturn(true);
		$order
			->shouldReceive('purchase_units')
			->andReturn([$purchaseUnit]);

		$wcOrder
			->shouldReceive('get_id')
			->andReturn(1);
		$wcOrder
			->shouldReceive('get_customer_id')
			->andReturn(2);
		$wcOrder
			->expects('update_meta_data')
			->with(PayPalGateway::ORDER_ID_META_KEY, '101');
		$wcOrder
			->expects('update_meta_data')
			->with(PayPalGateway::INTENT_META_KEY, 'CAPTURE');
		$wcOrder
			->expects('update_meta_data')
			->with(PayPalGateway::ORDER_PAYMENT_MODE_META_KEY, 'live');
		$wcOrder
			->expects('payment_complete');
		$wcOrder
			->expects('set_transaction_id');

		$this->repository->shouldReceive('all_for_user_id')
			->andReturn([$token]);

		$customer->shouldReceive('get_id')
			->andReturn(1);

		$this->purchaseUnitFactory->shouldReceive('from_wc_order')
			->andReturn($purchaseUnit);
		$this->payerFactory->shouldReceive('from_customer')
			->andReturn($payer);

		$this->orderEndpoint->shouldReceive('create')
			->with([$purchaseUnit], $payer, $token)
			->andReturn($order);

		$wcOrder->shouldReceive('update_status');

		$this->sut->renew($wcOrder);
	}
}
