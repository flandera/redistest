<?php

namespace App\Presenters;


use Tracy\Debugger;

class GamePresenter extends BasePresenter
{
	public function renderDefault()
	{
		Debugger::barDump('games');
	}

	public function actionStoreGame()
	{
		Debugger::barDump('store games');
	}
}
