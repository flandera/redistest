<?php

namespace App\Presenters;


use Kdyby\Redis\Exception;
use Kdyby\Redis\RedisClient;
use Tracy\Debugger;
use Nette\Http\IResponse;

class GamePresenter extends BasePresenter
{
	/**
	 * @var array
	 */
	private $allowedParameters = ['game_id', 'score', 'user_id'];

	/**
	 * @var \Kdyby\Redis\RedisClient
	 */
	private $redis;

	public function __construct(RedisClient $client)
	{
		parent::__construct();
		$this->redis = $client;
	}

	public function renderDefault()
	{

	}

	public function actionStoreGame()
	{
		$values = $this->request->getParameters();
		unset($values['action']);
		$validRequest = $this->validateValues($values);
		if($validRequest === FALSE){
			return $this->sendJson(['status'=>IResponse::S400_BAD_REQUEST, 'message'=>'Invalid parameters']);
		}else{
			try {
				$this->saveGame($values);
			} catch (\Exception $e) {
				return $this->sendJson(['status' => IResponse::S500_INTERNAL_SERVER_ERROR, 'message' => 'Error in saving game']);
			}
			return $this->sendJson(['status' => IResponse::S200_OK, 'message' => 'Game saved']);
		}
	}

	/**
	 * @throws \Nette\Application\AbortException
	 * @return \Nette\Application\Responses\JsonResponse
	 */
	public function actionGetTopGamers()
	{
		try {
			$gamers = $this->redis->zRevRange('gamers', 0, 9);
		} catch (\Exception $e) {
			return $this->sendJson(['status' => IResponse::S500_INTERNAL_SERVER_ERROR, 'message' => 'Error in loading data']);
		}
		return $this->sendJson(['status'=>IResponse::S200_OK, 'message'=>$gamers]);
	}

	/**
	 * @param array $values
	 * @return bool
	 */
	protected function validateValues($values)
	{
		$validRequest = TRUE;
		foreach ($values as $key => $value){
			if(!is_numeric($value) || !in_array($key, $this->allowedParameters)){
				$validRequest = FALSE;
			}
			$insertedParams[] = $key;
		}
		asort($insertedParams);
		$insertedParams = array_values($insertedParams);
		if($insertedParams !== $this->allowedParameters){
			$validRequest = FALSE;
		}
		return $validRequest;
	}

	/**
	 * @param array $values
	 */
	protected function saveGame($values)
	{
		$this->redis->zAdd('gamers', $values['score'], $values['user_id']);
		$this->redis->persist('gamers');
		$this->redis->save();
	}

	/**
	 * @return \Kdyby\Redis\RedisClient
	 */
	public function getRedis()
	{
		return $this->redis;
	}
}
