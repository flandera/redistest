<?php
/**
 * Created by PhpStorm.
 * User: FlanderaT
 * Date: 25.04.2018
 * Time: 10:23
 */

use App\Presenters\GamePresenter;
use Faker\Factory as FakerFactory;
use Nette\Application\IPresenterFactory;
use PHPUnit\Framework\TestCase;

class GameTest extends Testcase
{

	/**
	 * @var \Faker\Generator
	 */
	private $faker;

	/**
	 * @var array
	 */
	private $games = [];

	/**
	 * @var \Nette\Application\IPresenter
	 */
	private $gamePresenter;

	/**
	 * @var \Kdyby\Redis\RedisClient $redis
	 */
	private $redis;

	/**
	 * @var \Nette\DI\Container
	 */
	private $container;

	public function __construct()
	{
		parent::__construct();
		$this->container = require __DIR__ . '/bootstrap.php';
		$this->faker = FakerFactory::create('cs_CZ');
		/**@var \Nette\Application\IPresenterFactory $presenterFactory */
		$presenterFactory = $this->container->getByType(IPresenterFactory::class);
		$this->gamePresenter = $presenterFactory->createPresenter('Game');
		$this->redis = $this->gamePresenter->getRedis();
	}

	public function setUp()
	{
		$games = [];
		for($i = 1; $i <= 20; $i ++){
			$score = $this->faker->numberBetween(1, 500000);
			$user_id = $this->faker->numberBetween(1, 100000);
			$game_id = $this->faker->numberBetween(1, 500000);
			$this->games[$score] = ['score' => $score, 'game_id' => $game_id, 'user_id' => $user_id];
 		}
		$this->gamePresenter->autoCanonicalize = FALSE;
		foreach ($this->games as $key => $game){
			$request = new \Nette\Application\Request('Game','POST', ['action' => 'storegame', 'user_id' => $game['user_id'], 'game_id' => $game['game_id'], 'score' => $game['score']]);
			$this->gamePresenter->run($request);
		}
	}

	public function tearDown()
	{
		$this->redis->del('gamers');
	}

	public function testActionStoreGame()
	{
		foreach ($this->games as $key => $game){
			$request = new \Nette\Application\Request('Game','POST', ['action' => 'storegame', 'user_id' => $game['user_id'], 'game_id' => $game['game_id'], 'score' => $game['score']]);
			/**@var  \Nette\Application\Responses\JsonResponse $response*/
			$response = $this->gamePresenter->run($request);
			$this->assertSame(200, $response->getPayload()['status']);
		}
	}

	public function testActionGetTopGamers()
	{
		krsort($this->games);
		$this->games = array_values($this->games);
		$topGamers = [];
		for ($i = 0; $i < 10; $i++){
			$topGamers[$i] = (string)$this->games[$i]['user_id'];
		}
		$request = new \Nette\Application\Request('Game','POST', ['action' => 'gettopgamers']);
		/**@var  \Nette\Application\Responses\JsonResponse $response*/
		$response = $this->gamePresenter->run($request);
		$this->assertSame($topGamers, $response->getPayload()['message']);
	}


}
