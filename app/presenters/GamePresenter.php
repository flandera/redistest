<?php

namespace App\Presenters;


use Kdyby\Redis\Exception;
use Kdyby\Redis\RedisClient;
use Tracy\Debugger;
use Nette\Http\IResponse;

class GamePresenter extends BasePresenter
{
	private $allowedParameters = ['game_id', 'score', 'user_id'];

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
			} catch (Exception $e) {
				return $this->sendJson(['status' => IResponse::S500_INTERNAL_SERVER_ERROR, 'message' => 'Error in saving game']);
			}
			return $this->sendJson(['status' => IResponse::S200_OK, 'message' => 'Game saved']);
		}
		Debugger::barDump($values);
	}

	public function actionGetTopGamers()
	{
		$gamers = $this->redis->zRange('gamers', 0, 9);
		return $this->sendJson(['status'=>IResponse::S200_OK, 'message'=>$gamers]);
	}

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

	protected function saveGame($values)
	{
		$this->redis->zAdd('gamers', $values['score'], $values['user_id']);
		$this->redis->persist('gamers');
		$this->redis->save();
	}
}
