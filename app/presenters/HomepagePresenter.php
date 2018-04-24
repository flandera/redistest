<?php

namespace App\Presenters;


class HomepagePresenter extends BasePresenter
{
	public function renderDefault()
	{
		$this->template->anyVariable = 'any value';
	}

	public function storeGame()
	{
		if($this->isAjax()){

		}else{
			return $this->redirect('403', 'Homapage:Default');
		}
	}
}
